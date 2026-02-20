<?php

/**
 * Abstract base class for search engine implementations.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use craft\helpers\App;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Provides shared functionality for all search engine implementations.
 *
 * Handles per-index configuration, environment-variable resolution, and
 * fallback batch operations that concrete engines can override with native
 * bulk APIs.
 *
 * @author cogapp
 * @since 1.0.0
 */
abstract class AbstractEngine implements EngineInterface
{
    protected const DATE_FORMAT_EPOCH_SECONDS = 'epoch_seconds';
    protected const DATE_FORMAT_ISO8601 = 'iso8601';
    protected const EMBEDDING_MIN_DIMENSIONS = 50;

    /** @var array<string, string|null> Memoized geo field names keyed by index handle. */
    private array $_geoFieldCache = [];

    /**
     * Per-index engine configuration (e.g. index prefix).
     *
     * @var array
     */
    protected array $config;

    /**
     * @param array $config Per-index engine configuration values.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Return the resolved index name.
     *
     * If an explicit `indexName` override is set in the per-index engine config,
     * it is used as-is (after env-var resolution). Otherwise the standard
     * `indexPrefix + handle` convention applies.
     *
     * @param Index $index The index model.
     * @return string The resolved index name.
     */
    protected function getIndexName(Index $index): string
    {
        $override = $this->config['indexName'] ?? '';
        $override = (string)(App::parseEnv($override) ?? '');

        if ($override !== '') {
            return $override;
        }

        $prefix = $this->config['indexPrefix'] ?? '';
        $prefix = (string)(App::parseEnv($prefix) ?? '');

        return $prefix . $index->handle;
    }

    /**
     * Return a parsed config value, resolving environment variables.
     *
     * @param string $key     The configuration key to look up.
     * @param string $default Fallback value if the key is not set.
     * @return string The resolved value.
     */
    protected function parseSetting(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return (string)(App::parseEnv($value) ?? '');
    }

    /**
     * Return a per-index config value with fallback to a global plugin setting.
     *
     * Checks the per-index engineConfig first; if empty, falls back to the
     * corresponding global plugin setting. Resolves environment variables.
     *
     * @param string $configKey   The key in the per-index engineConfig array.
     * @param string $globalValue The global setting value to fall back to.
     * @return string The resolved value.
     */
    protected function resolveConfigOrGlobal(string $configKey, string $globalValue): string
    {
        $override = $this->config[$configKey] ?? '';
        $resolved = App::parseEnv($override);

        if ($resolved !== '' && $resolved !== null) {
            return $resolved;
        }

        return (string)(App::parseEnv($globalValue) ?? '');
    }

    /**
     * Normalise all mapped date fields in a document to the requested format.
     *
     * This keeps resolver output flexible (timestamp, ISO string, DateTime) while
     * ensuring each engine receives a shape it can index/sort/filter reliably.
     *
     * @param Index $index
     * @param array $document
     * @param string $targetFormat One of self::DATE_FORMAT_*.
     * @return array
     */
    protected function normaliseDateFields(Index $index, array $document, string $targetFormat): array
    {
        $dateFieldNames = [];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            if ($mapping->indexFieldType === FieldMapping::TYPE_DATE) {
                $dateFieldNames[] = $mapping->indexFieldName;
            }
        }

        if (empty($dateFieldNames)) {
            return $document;
        }

        foreach ($dateFieldNames as $fieldName) {
            if (!array_key_exists($fieldName, $document)) {
                continue;
            }

            $normalised = $this->normaliseDateValue($document[$fieldName], $targetFormat);
            if ($normalised !== null) {
                $document[$fieldName] = $normalised;
            }
        }

        return $document;
    }

    /**
     * Convert a date value into a target serialisation format.
     *
     * @param mixed $value
     * @param string $targetFormat
     * @return int|string|null Null means "unable to parse, keep original value".
     */
    protected function normaliseDateValue(mixed $value, string $targetFormat): int|string|null
    {
        $seconds = $this->dateValueToEpochSeconds($value);
        if ($seconds === null) {
            return null;
        }

        return match ($targetFormat) {
            self::DATE_FORMAT_EPOCH_SECONDS => $seconds,
            self::DATE_FORMAT_ISO8601 => gmdate('c', $seconds),
            default => $seconds,
        };
    }

    /**
     * Convert common date payloads into epoch seconds.
     *
     * Accepts DateTime objects, numeric seconds/milliseconds, or parseable strings.
     *
     * @param mixed $value
     * @return int|null
     */
    private function dateValueToEpochSeconds(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $this->normaliseEpochNumber($value);
        }

        if (is_float($value) && is_finite($value)) {
            return $this->normaliseEpochNumber((int)round($value));
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return $this->normaliseEpochNumber((int)round((float)$trimmed));
        }

        try {
            $dt = new DateTimeImmutable($trimmed);
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * If an epoch looks like milliseconds, convert it to seconds.
     *
     * @param int $epoch
     * @return int
     */
    private function normaliseEpochNumber(int $epoch): int
    {
        // 10^10 ~= 2286-11-20 in seconds; larger magnitudes are likely milliseconds.
        if (abs($epoch) >= 10000000000) {
            return (int)round($epoch / 1000);
        }

        return $epoch;
    }

    /**
     * Default searchFacetValues: fetch all facet values and filter by substring match.
     *
     * Retrieves the full facet distribution from the index (empty query, perPage 0)
     * then filters values client-side using case-insensitive substring matching.
     * Engines with native facet search APIs should override this.
     *
     * @param Index    $index       The index to search.
     * @param string[] $facetFields The facet field names to search within.
     * @param string   $query       The query to match against facet values.
     * @param int      $maxPerField Maximum values to return per field.
     * @param array    $filters     Optional filters to narrow the facet value context.
     * @return array<string, array<array{value: string, count: int}>> Grouped by field name.
     */
    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5, array $filters = []): array
    {
        $searchOptions = ['facets' => $facetFields, 'perPage' => 0];
        if (!empty($filters)) {
            $searchOptions['filters'] = $filters;
        }
        $result = $this->search($index, '', $searchOptions);

        $queryLower = mb_strtolower($query);
        $grouped = [];
        foreach ($facetFields as $field) {
            $allValues = $result->facets[$field] ?? [];
            $values = [];
            if ($query === '') {
                $values = array_slice($allValues, 0, $maxPerField);
            } else {
                foreach ($allValues as $facetValue) {
                    if (mb_strpos(mb_strtolower((string)$facetValue['value']), $queryLower) !== false) {
                        $values[] = $facetValue;
                        if (count($values) >= $maxPerField) {
                            break;
                        }
                    }
                }
            }
            if (!empty($values)) {
                $grouped[$field] = $values;
            }
        }

        return $grouped;
    }

    /**
     * Default batch index: loops single indexDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? 0;
            $this->indexDocument($index, (int)$elementId, $document);
        }
    }

    /**
     * Default batch delete: loops single deleteDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            $this->deleteDocument($index, $elementId);
        }
    }

    /**
     * Default: atomic swap not supported.
     *
     * @return bool
     */
    public function supportsAtomicSwap(): bool
    {
        return false;
    }

    /**
     * Default swap handle: append `_swap` to the index handle.
     *
     * Direct-rename engines (Algolia, Meilisearch) use this default.
     * Alias-based engines override with alternating `_swap_a`/`_swap_b`.
     *
     * @param Index $index The production index.
     * @return string The swap index handle.
     */
    public function buildSwapHandle(Index $index): string
    {
        return $index->handle . '_swap';
    }

    /**
     * Default: throw if called on an engine that doesn't support atomic swap.
     *
     * @param Index $index
     * @param Index $swapIndex
     * @return void
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        throw new \RuntimeException(static::class . ' does not support atomic index swapping.');
    }

    /**
     * Default multiSearch: loops single search() calls.
     * Engine implementations should override with native multi-search APIs.
     *
     * @param array $queries Array of ['index' => Index, 'query' => string, 'options' => array]
     * @return SearchResult[] One result per query, in the same order.
     */
    public function multiSearch(array $queries): array
    {
        $results = [];
        foreach ($queries as $query) {
            $results[] = $this->search(
                $query['index'],
                $query['query'],
                $query['options'] ?? [],
            );
        }
        return $results;
    }

    /**
     * Default getDocument: searches for the document by ID.
     * Engine implementations should override with native document retrieval.
     *
     * @param Index  $index      The index to query.
     * @param string $documentId The document ID to retrieve.
     * @return array|null The document as an associative array, or null if not found.
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        try {
            $result = $this->search($index, $documentId, ['perPage' => 1]);
            foreach ($result->hits as $hit) {
                if (($hit['objectID'] ?? null) === $documentId) {
                    return $hit;
                }
            }
        } catch (\Throwable $e) {
            // Fallback failed
        }

        return null;
    }

    /**
     * Default relatedSearch: fetches the source document, extracts keywords from
     * text fields, and performs a filtered search to find similar documents.
     *
     * Engines with native MLT support (ES, OpenSearch) should override this with
     * their built-in "More Like This" query for better results.
     *
     * @inheritdoc
     */
    public function relatedSearch(Index $index, string $documentId, int $perPage = 5, array $fields = []): SearchResult
    {
        // Fetch the source document
        $doc = $this->getDocument($index, $documentId);
        if ($doc === null) {
            return SearchResult::empty();
        }

        // Determine which fields to use for similarity
        if (empty($fields)) {
            $fields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_TEXT) {
                    $fields[] = $mapping->indexFieldName;
                }
            }
        }

        // Extract text content from the source document
        $textParts = [];
        foreach ($fields as $field) {
            if (isset($doc[$field]) && is_string($doc[$field])) {
                $textParts[] = $doc[$field];
            }
        }

        if (empty($textParts)) {
            return SearchResult::empty();
        }

        // Extract significant keywords: split into words, remove short/common ones
        $text = implode(' ', $textParts);
        $text = strip_tags($text);
        $words = preg_split('/\W+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn(string $w) => mb_strlen($w) > 3);

        // Count frequencies and take top keywords
        $freq = array_count_values($words);
        arsort($freq);
        $keywords = array_slice(array_keys($freq), 0, 10);

        if (empty($keywords)) {
            return SearchResult::empty();
        }

        $queryString = implode(' ', $keywords);

        // Search with the extracted keywords, excluding the source document
        $result = $this->search($index, $queryString, [
            'perPage' => $perPage + 1, // Fetch one extra to exclude the source
        ]);

        // Filter out the source document from results
        $filteredHits = array_values(array_filter(
            $result->hits,
            fn(array $hit) => ($hit['objectID'] ?? '') !== $documentId,
        ));

        // Trim to requested count
        $filteredHits = array_slice($filteredHits, 0, $perPage);

        return new SearchResult(
            hits: $filteredHits,
            totalHits: count($filteredHits),
            page: 1,
            perPage: $perPage,
            totalPages: 1,
            processingTimeMs: $result->processingTimeMs,
        );
    }

    /**
     * Default getIndexSchema: returns empty array.
     * Engine implementations should override with native schema retrieval.
     *
     * @param Index $index The index to inspect.
     * @return array Engine-specific schema/settings array.
     */
    public function getIndexSchema(Index $index): array
    {
        return [];
    }

    /**
     * Default getSchemaFields: returns empty array.
     * Engine implementations should override with native schema field extraction.
     *
     * @param Index $index The index to inspect.
     * @return array<array{name: string, type: string}> Normalised field list.
     */
    public function getSchemaFields(Index $index): array
    {
        return [];
    }

    /**
     * Infer schema fields by sampling documents when privileged schema APIs are unavailable.
     *
     * Engines can override {@see sampleDocumentsForSchemaInference()} to return a
     * small set of representative documents fetched with low-privilege query APIs.
     *
     * @param Index $index
     * @return array<array{name: string, type: string}>
     */
    protected function inferSchemaFieldsFromSampleDocuments(Index $index): array
    {
        try {
            $documents = $this->sampleDocumentsForSchemaInference($index);
        } catch (\Throwable) {
            return [];
        }

        if (empty($documents)) {
            return [];
        }

        // Merge fields across sampled docs so nulls in one record can still
        // be typed from non-null values in another.
        $fieldValues = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            foreach ($document as $name => $value) {
                if (!is_string($name)) {
                    continue;
                }

                if (!array_key_exists($name, $fieldValues) || $fieldValues[$name] === null) {
                    $fieldValues[$name] = $value;
                }
            }
        }

        $fields = [];
        foreach ($fieldValues as $name => $value) {
            $fields[] = ['name' => $name, 'type' => $this->inferFieldType($name, $value)];
        }

        return $fields;
    }

    /**
     * Return a small list of raw documents for schema inference.
     *
     * @param Index $index
     * @return array<int, array<string, mixed>>
     */
    protected function sampleDocumentsForSchemaInference(Index $index): array
    {
        return [];
    }

    /**
     * Infer a plugin field type from a field name and sample value.
     *
     * Uses both value types and name-based heuristics (for timestamps/booleans).
     *
     * @param string $name
     * @param mixed $value
     * @return string
     */
    protected function inferFieldType(string $name, mixed $value): string
    {
        if (is_bool($value)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        $lower = strtolower($name);

        if (preg_match('/(_at|_date|_time|timestamp)$/', $lower)
            || preg_match('/^(created|updated|deleted|modified|date)_/', $lower)
        ) {
            return FieldMapping::TYPE_DATE;
        }

        if (preg_match('/^(is_|has_)/', $lower) || preg_match('/_(enabled|active|visible|archived)$/', $lower)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        if (is_int($value)) {
            return FieldMapping::TYPE_INTEGER;
        }
        if (is_float($value)) {
            return FieldMapping::TYPE_FLOAT;
        }

        if (is_array($value)) {
            if (array_is_list($value) && !empty($value)) {
                $first = $value[0];

                if (is_string($first)) {
                    return FieldMapping::TYPE_FACET;
                }

                if ((is_int($first) || is_float($first)) && count($value) > static::EMBEDDING_MIN_DIMENSIONS) {
                    return FieldMapping::TYPE_EMBEDDING;
                }
            }

            if (isset($value['lat'], $value['lng'])
                && (is_numeric($value['lat']) && is_numeric($value['lng']))
            ) {
                return FieldMapping::TYPE_GEO_POINT;
            }

            return FieldMapping::TYPE_OBJECT;
        }

        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s].*)?$/', $value)) {
                return FieldMapping::TYPE_DATE;
            }

            if (filter_var($value, FILTER_VALIDATE_URL)) {
                return FieldMapping::TYPE_KEYWORD;
            }

            return strlen($value) > 64 ? FieldMapping::TYPE_TEXT : FieldMapping::TYPE_KEYWORD;
        }

        return FieldMapping::TYPE_TEXT;
    }

    /**
     * Check whether a filter value represents a range filter (min/max).
     *
     * Range filters are distinguished from equality array filters by having
     * associative `min` and/or `max` keys rather than indexed string values.
     *
     * @param mixed $value The filter value to check.
     * @return bool True if the value is a range filter.
     */
    protected function isRangeFilter(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        // Must have at least one of min/max and no other keys
        $keys = array_keys($value);
        $allowed = ['min', 'max'];

        return !empty($keys)
            && empty(array_diff($keys, $allowed))
            && !empty(array_intersect($keys, $allowed));
    }

    /**
     * Extract a unified `stats` parameter from the search options.
     *
     * Stats request format: `['fieldName1', 'fieldName2']`.
     * The extracted key is removed from the returned remaining options.
     *
     * @param array $options The caller-provided search options.
     * @return array{string[], array} [$statsFields, $remainingOptions]
     */
    protected function extractStatsParams(array $options): array
    {
        $stats = $options['stats'] ?? [];
        $remaining = $options;
        unset($remaining['stats']);

        if (!is_array($stats)) {
            $stats = [];
        }

        return [$stats, $remaining];
    }

    /**
     * Normalise engine-specific stats data from a search response.
     *
     * Target format: `{ fieldName: { min: float, max: float } }`.
     * Subclasses should override this to extract stats from the raw response.
     * The base implementation returns an empty array.
     *
     * @param array $response The raw engine response.
     * @param string[] $statsFields The requested stats fields.
     * @return array<string, array{min: float, max: float}>
     */
    protected function normaliseRawStats(array $response, array $statsFields = []): array
    {
        return [];
    }

    /**
     * Extract a unified `histogram` parameter from the search options.
     *
     * Histogram request format:
     * - Shorthand: `['field' => interval]`
     * - Full config: `['field' => ['interval' => interval, 'min' => float, 'max' => float]]`
     *
     * Normalises shorthand to full config format. The extracted key is removed
     * from the returned remaining options.
     *
     * @param array $options The caller-provided search options.
     * @return array{array, array} [$histogramConfig, $remainingOptions]
     */
    protected function extractHistogramParams(array $options): array
    {
        $histogram = $options['histogram'] ?? [];
        $remaining = $options;
        unset($remaining['histogram']);

        if (!is_array($histogram)) {
            $histogram = [];
        }

        // Normalise shorthand: field => interval → field => ['interval' => interval]
        $normalised = [];
        foreach ($histogram as $field => $config) {
            if (!is_string($field)) {
                continue;
            }

            if (is_numeric($config)) {
                $normalised[$field] = ['interval' => (float)$config];
            } elseif (is_array($config) && isset($config['interval'])) {
                $normalised[$field] = $config;
            }
            // Skip invalid entries
        }

        return [$normalised, $remaining];
    }

    /**
     * Normalise engine-specific histogram data from a search response.
     *
     * Target format: `{ field: [{ key: float, count: int }, ...] }`.
     * Subclasses should override this to extract histograms from the raw response.
     * The base implementation returns an empty array.
     *
     * @param array $response The raw engine response.
     * @param array $histogramConfig The requested histogram configuration.
     * @return array<string, array<array{key: float|int, count: int}>>
     */
    protected function normaliseRawHistograms(array $response, array $histogramConfig = []): array
    {
        return [];
    }

    /**
     * Extract a unified `sort` parameter from the search options.
     *
     * Unified sort format: `['fieldName' => 'asc', 'otherField' => 'desc']`.
     * The extracted key is removed from the returned remaining options.
     * If the value is not in unified format (e.g. already engine-native),
     * it is still extracted so the engine can handle it.
     *
     * @param array $options The caller-provided search options.
     * @return array{array, array} [$sort, $remainingOptions]
     */
    protected function extractSortParams(array $options): array
    {
        $sort = $options['sort'] ?? [];
        $remaining = $options;
        unset($remaining['sort']);

        if (!is_array($sort)) {
            $sort = [];
        }

        return [$sort, $remaining];
    }

    /**
     * Check whether a sort value is in unified format (associative array of field => direction).
     *
     * Unified: `['title' => 'asc', 'price' => 'desc']`
     * Native (not unified): `[['price' => 'asc'], '_score']` (ES DSL), `['price:asc']` (Meilisearch)
     *
     * @param array $sort The sort value to check.
     * @return bool True if the sort is in unified format.
     */
    protected function isUnifiedSort(array $sort): bool
    {
        if (empty($sort)) {
            return false;
        }

        foreach ($sort as $key => $value) {
            if (!is_string($key) || !in_array($value, ['asc', 'desc'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract a unified `attributesToRetrieve` parameter from the search options.
     *
     * Allows callers to specify which document fields should be returned by the
     * search engine, reducing payload size for use cases like autocomplete.
     *
     * @param array $options The caller-provided search options.
     * @return array{string[]|null, array} [$attributes, $remainingOptions] — null means "return all".
     */
    protected function extractAttributesToRetrieve(array $options): array
    {
        $attributes = $options['attributesToRetrieve'] ?? null;
        $remaining = $options;
        unset($remaining['attributesToRetrieve']);

        if ($attributes !== null && !is_array($attributes)) {
            $attributes = null;
        }

        return [$attributes, $remaining];
    }

    /**
     * Extract a unified `highlight` parameter from the search options.
     *
     * Supported values:
     * - `true`   — highlight all text fields
     * - `array`  — highlight only the specified field names
     * - `null`   — no highlighting requested (default)
     *
     * @param array $options The caller-provided search options.
     * @return array{bool|string[]|null, array} [$highlight, $remainingOptions]
     */
    protected function extractHighlightParams(array $options): array
    {
        $highlight = $options['highlight'] ?? null;
        $remaining = $options;
        unset($remaining['highlight']);

        // Normalise: true means "all fields", array means specific fields
        if ($highlight === true || $highlight === false) {
            $highlight = $highlight ?: null;
        } elseif (!is_array($highlight)) {
            $highlight = null;
        }

        return [$highlight, $remaining];
    }

    /**
     * Extract a unified `suggest` parameter from the search options.
     *
     * When `true`, engines that support spelling suggestions will include
     * alternative query strings in the SearchResult `suggestions` array.
     *
     * @param array $options The caller-provided search options.
     * @return array{bool, array} [$suggest, $remainingOptions]
     */
    protected function extractSuggestParams(array $options): array
    {
        $suggest = (bool)($options['suggest'] ?? false);
        $remaining = $options;
        unset($remaining['suggest']);

        return [$suggest, $remaining];
    }

    /**
     * Extract embedding/vector search parameters from the search options.
     *
     * Strips `embedding`, `embeddingField`, `vectorSearch`, and `voyageModel`
     * from the options so they don't leak to engine-native parameters.
     *
     * @param array $options The caller-provided search options.
     * @return array{float[]|null, string|null, array} [$embedding, $embeddingField, $remainingOptions]
     */
    protected function extractEmbeddingParams(array $options): array
    {
        $embedding = $options['embedding'] ?? null;
        $embeddingField = $options['embeddingField'] ?? null;

        $remaining = $options;
        unset(
            $remaining['embedding'],
            $remaining['embeddingField'],
            $remaining['vectorSearch'],
            $remaining['voyageModel'],
        );

        if ($embedding !== null && !is_array($embedding)) {
            $embedding = null;
        }

        if ($embeddingField !== null && !is_string($embeddingField)) {
            $embeddingField = null;
        }

        return [$embedding, $embeddingField, $remaining];
    }

    /**
     * Extract unified geo search parameters from the search options.
     *
     * Geo filter format: `['lat' => float, 'lng' => float, 'radius' => string]`
     * Geo sort format: `['lat' => float, 'lng' => float]`
     *
     * The extracted keys are removed from the returned remaining options.
     *
     * @param array $options The caller-provided search options.
     * @return array{array|null, array|null, array} [$geoFilter, $geoSort, $remainingOptions]
     */
    protected function extractGeoParams(array $options): array
    {
        $geoFilter = $options['geoFilter'] ?? null;
        $geoSort = $options['geoSort'] ?? null;

        $remaining = $options;
        unset($remaining['geoFilter'], $remaining['geoSort']);

        // Validate geoFilter
        if ($geoFilter !== null) {
            if (!is_array($geoFilter)
                || !isset($geoFilter['lat'], $geoFilter['lng'], $geoFilter['radius'])
                || !is_numeric($geoFilter['lat'])
                || !is_numeric($geoFilter['lng'])
            ) {
                $geoFilter = null;
            }
        }

        // Validate geoSort
        if ($geoSort !== null) {
            if (!is_array($geoSort)
                || !isset($geoSort['lat'], $geoSort['lng'])
                || !is_numeric($geoSort['lat'])
                || !is_numeric($geoSort['lng'])
            ) {
                $geoSort = null;
            }
        }

        return [$geoFilter, $geoSort, $remaining];
    }

    /**
     * Parse a radius string into metres for engines that need numeric values.
     *
     * Supported formats: "50km", "5000m", "50" (defaults to km).
     *
     * @param string $radius The radius string.
     * @return int Radius in metres.
     */
    protected function parseRadiusToMetres(string $radius): int
    {
        $radius = trim($radius);
        if (preg_match('/^([\d.]+)\s*m$/i', $radius, $m)) {
            return (int)round((float)$m[1]);
        }
        if (preg_match('/^([\d.]+)\s*(km)?$/i', $radius, $m)) {
            return (int)round((float)$m[1] * 1000);
        }
        return (int)round((float)$radius * 1000);
    }

    /**
     * Detect the geo-point field name from the index field mappings.
     *
     * Returns the first field mapped as TYPE_GEO_POINT, or null if none found.
     *
     * @param Index $index The index to inspect.
     * @return string|null The geo-point field name, or null.
     */
    protected function detectGeoField(Index $index): ?string
    {
        $handle = $index->handle;
        if (array_key_exists($handle, $this->_geoFieldCache)) {
            return $this->_geoFieldCache[$handle];
        }

        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_GEO_POINT) {
                return $this->_geoFieldCache[$handle] = $mapping->indexFieldName;
            }
        }
        return $this->_geoFieldCache[$handle] = null;
    }

    /**
     * Extract unified geo grid aggregation parameters from the search options.
     *
     * Geo grid format: `['field' => string, 'precision' => int]`
     * Precision maps to zoom level (0-29 for geotile_grid).
     *
     * @param array $options The caller-provided search options.
     * @return array{array|null, array} [$geoGrid, $remainingOptions]
     */
    protected function extractGeoGridParams(array $options): array
    {
        $geoGrid = $options['geoGrid'] ?? null;
        $remaining = $options;
        unset($remaining['geoGrid']);

        if ($geoGrid !== null) {
            if (!is_array($geoGrid) || !isset($geoGrid['precision'])) {
                $geoGrid = null;
            } else {
                $geoGrid['precision'] = max(0, min(29, (int)$geoGrid['precision']));

                // Validate optional viewport bounds (ES geo_bounding_box format)
                if (isset($geoGrid['bounds'])) {
                    $b = $geoGrid['bounds'];
                    if (is_array($b)
                        && isset($b['top_left']['lat'], $b['top_left']['lon'], $b['bottom_right']['lat'], $b['bottom_right']['lon'])
                        && is_numeric($b['top_left']['lat']) && is_numeric($b['top_left']['lon'])
                        && is_numeric($b['bottom_right']['lat']) && is_numeric($b['bottom_right']['lon'])
                    ) {
                        $geoGrid['bounds'] = [
                            'top_left' => [
                                'lat' => (float)$b['top_left']['lat'],
                                'lon' => (float)$b['top_left']['lon'],
                            ],
                            'bottom_right' => [
                                'lat' => (float)$b['bottom_right']['lat'],
                                'lon' => (float)$b['bottom_right']['lon'],
                            ],
                        ];
                    } else {
                        unset($geoGrid['bounds']);
                    }
                }
            }
        }

        return [$geoGrid, $remaining];
    }

    /**
     * Convert a geotile key (zoom/x/y) to a lat/lng centroid.
     *
     * @param string $key Geotile key in "zoom/x/y" format.
     * @return array{lat: float, lng: float} The tile centroid.
     */
    protected function geotileToLatLng(string $key): array
    {
        $parts = explode('/', $key);
        if (count($parts) !== 3) {
            return ['lat' => 0.0, 'lng' => 0.0];
        }

        $zoom = (int)$parts[0];
        $x = (int)$parts[1];
        $y = (int)$parts[2];

        $n = 2 ** $zoom;
        // Use tile centre (+0.5)
        $lng = (($x + 0.5) / $n) * 360.0 - 180.0;
        $latRad = atan(sinh(M_PI * (1 - 2 * ($y + 0.5) / $n)));
        $lat = rad2deg($latRad);

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    /**
     * Compute a zero-based offset from a 1-based page number.
     *
     * @param int $page    The 1-based page number.
     * @param int $perPage Results per page.
     * @return int The zero-based offset.
     */
    protected function offsetFromPage(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    /**
     * Convert a unified sort array into engine-native sort parameters.
     *
     * Subclasses should override this to translate `['field' => 'asc']` into
     * whatever format the engine requires. Non-unified (engine-native) sort
     * values are returned as-is by default.
     *
     * @param array $sort The sort array (unified or engine-native).
     * @return mixed Engine-native sort representation, or empty array if no sort.
     */
    protected function buildNativeSortParams(array $sort): mixed
    {
        return $sort;
    }

    /**
     * Convert unified filter parameters into engine-native filter syntax.
     *
     * Input: `['field' => 'value']` or `['field' => ['val1', 'val2']]`.
     * Subclasses should override this to produce the engine's filter format.
     *
     * @param array $filters Unified filter map.
     * @param Index $index   The index (for field type introspection).
     * @return mixed Engine-native filter representation.
     */
    protected function buildNativeFilterParams(array $filters, Index $index): mixed
    {
        return $filters;
    }

    /**
     * Normalise a facet response in the common `{ field: { value: count } }` shape.
     *
     * Used by engines where the raw response is an associative map of field name
     * to `{ value => count }` pairs (Algolia `facets`, Meilisearch `facetDistribution`).
     *
     * @param array $facetMap Raw facet data: `['field' => ['value' => count, ...], ...]`.
     * @return array Normalised: `['field' => [['value' => 'x', 'count' => n], ...], ...]`.
     */
    protected function normaliseFacetMapResponse(array $facetMap): array
    {
        $normalised = [];
        foreach ($facetMap as $field => $valueCounts) {
            $normalised[$field] = $this->normaliseFacetCounts($valueCounts);
        }
        return $normalised;
    }

    /**
     * Normalise a single raw hit from the engine response into a flat document.
     *
     * Subclasses should override this to flatten engine-specific hit structure
     * (e.g. ES `_source`, Typesense `document`) and extract highlights/scores.
     * The base implementation returns the hit unchanged.
     *
     * @param array $hit A single raw hit from the engine response.
     * @return array Flattened document with `_highlights` key populated.
     */
    protected function normaliseRawHit(array $hit): array
    {
        return $hit;
    }

    /**
     * Normalise engine-specific facet/aggregation data from a search response.
     *
     * Subclasses should override this to extract and normalise facets from the
     * raw response. The base implementation returns an empty array.
     *
     * @param array $response The raw engine response (or a subsection of it).
     * @return array Normalised: `['field' => [['value' => 'x', 'count' => n], ...], ...]`.
     */
    protected function normaliseRawFacets(array $response): array
    {
        return [];
    }

    /**
     * Parse a successful schema response into normalised field definitions.
     *
     * Called by the default {@see getSchemaFields()} template method when the
     * schema API returns successfully (no `error` key). Subclasses should
     * override this to parse their engine's native schema format.
     *
     * @param array $schema The raw schema response from {@see getIndexSchema()}.
     * @return array<array{name: string, type: string}> Normalised field list.
     */
    protected function parseSchemaFields(array $schema): array
    {
        return [];
    }

    /**
     * Handle a schema introspection error during {@see getSchemaFields()}.
     *
     * Called when `getIndexSchema()` returns an array with an `error` key.
     * The default implementation returns an empty array. Subclasses can
     * override this to fall back to document sampling or other strategies.
     *
     * @param Index $index The index whose schema could not be retrieved.
     * @return array<array{name: string, type: string}> Fallback field list.
     */
    protected function handleSchemaError(Index $index): array
    {
        return [];
    }

    /**
     * Normalise engine-specific highlight data into the unified format.
     *
     * Target format: `{ fieldName: ['fragment1', 'fragment2'] }`.
     * Subclasses should override this to handle their engine's highlight shape.
     *
     * @param array $highlightData Raw highlight data from the engine.
     * @return array<string, string[]> Normalised highlights.
     */
    protected function normaliseHighlightData(array $highlightData): array
    {
        // Base implementation: assume { field: [fragments] } (ES format) or return as-is
        $normalised = [];
        foreach ($highlightData as $field => $value) {
            if (is_array($value) && !empty($value)) {
                // Already in { field: [fragments] } format (e.g. ES)
                $normalised[$field] = array_values(array_filter($value, 'is_string'));
            } elseif (is_string($value) && $value !== '') {
                $normalised[$field] = [$value];
            }
        }
        return $normalised;
    }

    /**
     * Extract unified facet/filter parameters from the search options.
     *
     * Looks for `facets` (array of field names to aggregate), `filters`
     * (associative array of field => value or field => [values] constraints),
     * and `maxValuesPerFacet` (int limit on aggregation bucket size) in the
     * options array. The extracted keys are removed from the returned
     * remaining options.
     *
     * @param array $options The caller-provided search options.
     * @return array{string[], array, int|null, array} [$facets, $filters, $maxValuesPerFacet, $remainingOptions]
     */
    protected function extractFacetParams(array $options): array
    {
        $facets = (array)($options['facets'] ?? []);
        $filters = (array)($options['filters'] ?? []);
        $maxValuesPerFacet = isset($options['maxValuesPerFacet']) ? (int)$options['maxValuesPerFacet'] : null;

        $remaining = $options;
        unset($remaining['facets'], $remaining['filters'], $remaining['maxValuesPerFacet']);

        return [$facets, $filters, $maxValuesPerFacet, $remaining];
    }

    /**
     * Normalise facet counts from a flat value→count map into the unified shape.
     *
     * Input:  `['News' => 12, 'Blog' => 5]`
     * Output: `[['value' => 'News', 'count' => 12], ['value' => 'Blog', 'count' => 5]]`
     *
     * @param array<string, int> $valueCounts A map of facet value to document count.
     * @return array<array{value: string, count: int}> Sorted by count descending.
     */
    protected function normaliseFacetCounts(array $valueCounts): array
    {
        $result = [];
        foreach ($valueCounts as $value => $count) {
            $result[] = ['value' => (string)$value, 'count' => (int)$count];
        }

        // Sort by count descending for consistent output
        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Normalise an array of engine-specific hit documents into a consistent shape.
     *
     * Every hit will contain at least `objectID` (string), `_score` (float|int|null),
     * and `_highlights` (array). All original engine-specific keys are preserved.
     *
     * @param array       $hits         Raw hits from the engine response.
     * @param string      $idKey        The key used by this engine for the document ID.
     * @param string      $scoreKey     The key used by this engine for the relevance score.
     * @param string|null $highlightKey The key used by this engine for highlight data.
     * @return array Normalised hits.
     */
    protected function normaliseHits(array $hits, string $idKey, string $scoreKey, ?string $highlightKey): array
    {
        return array_map(function(array $hit) use ($idKey, $scoreKey, $highlightKey): array {
            if (!isset($hit['objectID']) && isset($hit[$idKey])) {
                $hit['objectID'] = (string)$hit[$idKey];
            }

            if (!isset($hit['_score'])) {
                $hit['_score'] = $hit[$scoreKey] ?? null;
            }

            if (!isset($hit['_highlights'])) {
                $hit['_highlights'] = $highlightKey !== null ? ($hit[$highlightKey] ?? []) : [];
            }

            return $hit;
        }, $hits);
    }

    /**
     * Extract unified pagination parameters from the search options.
     *
     * Looks for `page` (1-based) and `perPage` in the options array, falling back
     * to defaults. The extracted keys are removed from the returned remaining options.
     *
     * @param array $options       The caller-provided search options.
     * @param int   $defaultPerPage Default results per page.
     * @return array{int, int, array} [$page, $perPage, $remainingOptions]
     */
    protected function extractPaginationParams(array $options, int $defaultPerPage = 20): array
    {
        $page = (int)($options['page'] ?? 1);
        $perPage = (int)($options['perPage'] ?? $defaultPerPage);

        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }

        $remaining = $options;
        unset($remaining['page'], $remaining['perPage']);

        return [$page, $perPage, $remaining];
    }

    /**
     * Sort weighted attributes by weight descending and return just the names.
     *
     * Expects each element to be `['name' => string, 'weight' => int]`.
     *
     * @param array $attributes Array of ['name' => ..., 'weight' => ...] items.
     * @return string[] Sorted field names.
     */
    protected function sortByWeight(array $attributes): array
    {
        usort($attributes, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return array_map(fn($attr) => $attr['name'], $attributes);
    }

    /**
     * Compute the total number of pages for the given totals.
     *
     * @param int $totalHits Total matching documents.
     * @param int $perPage   Results per page.
     * @return int Total pages (minimum 0).
     */
    protected function computeTotalPages(int $totalHits, int $perPage): int
    {
        if ($perPage <= 0) {
            return 0;
        }

        return (int)ceil($totalHits / $perPage);
    }
}
