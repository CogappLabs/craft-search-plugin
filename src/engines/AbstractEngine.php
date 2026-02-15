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
     * Return the resolved index name, applying any configured prefix.
     *
     * @param Index $index The index model.
     * @return string The prefixed index name.
     */
    protected function getIndexName(Index $index): string
    {
        $prefix = $this->config['indexPrefix'] ?? '';
        $prefix = App::parseEnv($prefix);

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

        return App::parseEnv($value);
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

        if ($resolved !== '') {
            return $resolved;
        }

        return App::parseEnv($globalValue);
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

                if ((is_int($first) || is_float($first)) && count($value) >= 8) {
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
     * Looks for `facets` (array of field names to aggregate) and `filters`
     * (associative array of field => value or field => [values] constraints)
     * in the options array. The extracted keys are removed from the returned
     * remaining options.
     *
     * @param array $options The caller-provided search options.
     * @return array{string[], array, array} [$facets, $filters, $remainingOptions]
     */
    protected function extractFacetParams(array $options): array
    {
        $facets = (array)($options['facets'] ?? []);
        $filters = (array)($options['filters'] ?? []);

        $remaining = $options;
        unset($remaining['facets'], $remaining['filters']);

        return [$facets, $filters, $remaining];
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
