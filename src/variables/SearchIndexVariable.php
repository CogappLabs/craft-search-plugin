<?php

/**
 * Search Index plugin for Craft CMS -- Twig variable class.
 */

namespace cogapp\searchindex\variables;

use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;
use Twig\Markup;

/**
 * Provides Search Index functionality to Twig templates via craft.searchIndex.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndexVariable
{
    /** @var array<string, \cogapp\searchindex\engines\EngineInterface> Cached engine instances keyed by type + config hash. */
    private array $_engineCache = [];

    /** @var array<string, array<string, string>> Cached role field maps keyed by index handle. */
    private array $_roleFieldCache = [];

    /**
     * Get all configured indexes.
     * Usage: {% set indexes = craft.searchIndex.indexes %}
     *
     * @return Index[]
     */
    public function getIndexes(): array
    {
        return SearchIndex::$plugin->getIndexes()->getAllIndexes();
    }

    /**
     * Get a single index by handle.
     * Usage: {% set index = craft.searchIndex.index('places') %}
     *
     * @param string $handle
     * @return Index|null
     */
    public function getIndex(string $handle): ?Index
    {
        return SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);
    }

    /**
     * Search an index by handle.
     * Usage: {% set results = craft.searchIndex.search('places', 'london', { perPage: 20, fields: ['title','summary'] }) %}
     *
     * @param string $handle  The index handle to search.
     * @param string $query   The search query string.
     * @param array  $options Search options — supports unified `page`/`perPage` plus engine-specific keys.
     * @return SearchResult Normalised result with hits, pagination, facets, and raw response.
     */
    public function search(string $handle, string $query, array $options = []): SearchResult
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return SearchResult::empty();
        }

        // Auto-generate embedding via Voyage AI when vectorSearch is enabled
        if (!empty($options['vectorSearch']) && !isset($options['embedding'])) {
            $options = $this->_resolveVectorSearchOptions($index, $query, $options);
        }

        $engine = $this->_getEngine($index);

        $start = microtime(true);
        $result = $engine->search($index, $query, $options);
        $elapsedMs = (microtime(true) - $start) * 1000;

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            // Strip embedding vector from logged options to avoid huge log entries
            $logOptions = $options;
            if (isset($logOptions['embedding'])) {
                $logOptions['embedding'] = '(' . count($logOptions['embedding']) . ' dims)';
            }

            $context = [
                'index' => $handle,
                'query' => $query,
                'options' => $logOptions,
                'engine' => $index->engineType,
                'elapsedMs' => (int)round($elapsedMs),
                'engineMs' => $result->processingTimeMs ?? null,
            ];
            Craft::info(array_merge(['msg' => 'searchIndex Twig search executed'], $context), __METHOD__);
        }

        return $result;
    }

    /**
     * Lightweight autocomplete search optimised for speed and minimal payload.
     *
     * Defaults to a small result set (5 hits), searches only the title field
     * (if a title role is configured), and returns only the title and objectID
     * attributes. All defaults can be overridden via `$options`.
     *
     * Usage: {% set suggestions = craft.searchIndex.autocomplete('places', 'lon', { perPage: 8 }) %}
     *
     * @param string $handle  The index handle to search.
     * @param string $query   The autocomplete query string.
     * @param array  $options Search options — same as search(), with autocomplete-friendly defaults.
     * @return SearchResult Normalised result with hits, pagination, facets, and raw response.
     */
    public function autocomplete(string $handle, string $query, array $options = []): SearchResult
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return SearchResult::empty();
        }

        // Default to small result set
        if (!isset($options['perPage'])) {
            $options['perPage'] = 5;
        }

        // Auto-detect role fields from mappings for attribute retrieval (cached per request)
        if (!isset($this->_roleFieldCache[$handle])) {
            $roleFields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->role !== null) {
                    $roleFields[$mapping->role] = $mapping->indexFieldName;
                }
            }
            $this->_roleFieldCache[$handle] = $roleFields;
        }
        $roleFields = $this->_roleFieldCache[$handle];

        // Default: return only objectID + role fields to minimise payload
        if (!isset($options['attributesToRetrieve']) && !empty($roleFields)) {
            $options['attributesToRetrieve'] = array_merge(['objectID'], array_values($roleFields));
        }

        return $this->search($handle, $query, $options);
    }

    /**
     * Search within facet values for a specific field.
     *
     * Useful when an index has hundreds of facet values (e.g. categories, tags)
     * and you need to let the user filter the facet list itself before selecting.
     *
     * Uses case-insensitive substring matching across all engines for
     * consistent mid-value matching (e.g. "sus" matches "East Sussex").
     * Algolia uses native searchForFacetValues where available; all other
     * engines fetch the full facet distribution and filter client-side.
     *
     * Returns an array of `['value' => string, 'count' => int]` items matching
     * the facet query, sorted by count descending.
     *
     * Usage: {% set values = craft.searchIndex.searchFacetValues('articles', 'category', 'tech') %}
     *
     * @param string $handle    The index handle.
     * @param string $facetName The facet field name to search within.
     * @param string $query     The query to match against facet values.
     * @param array  $options   Additional options (e.g. `maxValues` for limit).
     * @return array Array of ['value' => string, 'count' => int] items.
     */
    public function searchFacetValues(string $handle, string $facetName, string $query = '', array $options = []): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return [];
        }

        $maxValues = (int)($options['maxValues'] ?? 10);
        $filters = (array)($options['filters'] ?? []);
        $engine = $this->_getEngine($index);
        $result = $engine->searchFacetValues($index, [$facetName], $query, $maxValues, $filters);

        return $result[$facetName] ?? [];
    }

    /**
     * Search across multiple facet fields and return matching values grouped by field.
     *
     * Useful for autocomplete UIs that show categorized facet suggestions
     * (e.g. "Region: Scotland (5)") alongside document matches.
     *
     * Uses case-insensitive substring matching across all engines for
     * consistent behaviour. Algolia uses native searchForFacetValues where
     * available; all other engines fetch the full facet distribution and
     * filter client-side.
     *
     * Returns an associative array keyed by field name, each containing an array
     * of `['value' => string, 'count' => int]` items. Fields with no matches are omitted.
     *
     * Usage: {% set suggestions = craft.searchIndex.facetAutocomplete('places', 'scot', { maxPerField: 3 }) %}
     *
     * @param string $handle  The index handle.
     * @param string $query   The search query — passed to the engine's native facet search.
     * @param array  $options Options: `facetFields` (string[]) to specify fields, `maxPerField` (int, default 5).
     * @return array<string, array<array{value: string, count: int}>> Matching facet values grouped by field.
     */
    public function facetAutocomplete(string $handle, string $query, array $options = []): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return [];
        }

        // Resolve facet fields: explicit list or auto-detect from TYPE_FACET mappings
        $facetFields = $options['facetFields'] ?? [];

        if (empty($facetFields)) {
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_FACET) {
                    $facetFields[] = $mapping->indexFieldName;
                }
            }
            $facetFields = array_values(array_unique($facetFields));
        }

        if (empty($facetFields)) {
            return [];
        }

        $maxPerField = (int)($options['maxPerField'] ?? 5);
        $filters = (array)($options['filters'] ?? []);
        $engine = $this->_getEngine($index);

        return $engine->searchFacetValues($index, $facetFields, $query, $maxPerField, $filters);
    }

    /**
     * Execute multiple search queries across one or more indexes in a single batch.
     *
     * Queries are grouped by engine instance so engines with native multi-search
     * support can execute them in one round-trip. Results are returned in the
     * same order as the input queries.
     *
     * Usage: {% set results = craft.searchIndex.multiSearch([
     *   { handle: 'products', query: 'laptop' },
     *   { handle: 'articles', query: 'laptop review', options: { perPage: 5 } },
     * ]) %}
     *
     * @param array $searches Array of ['handle' => string, 'query' => string, 'options' => array]
     * @return SearchResult[] One result per query, in the same order.
     */
    public function multiSearch(array $searches): array
    {
        $indexService = SearchIndex::$plugin->getIndexes();

        // Resolve indexes and group queries by engine type + config
        $groups = [];      // engineKey => ['engine' => EngineInterface, 'queries' => [...]]
        $orderMap = [];    // originalIndex => [engineKey, queryIndex]

        foreach ($searches as $i => $search) {
            $handle = $search['handle'] ?? '';
            $query = $search['query'] ?? '';
            $options = $search['options'] ?? [];

            $index = $indexService->getIndexByHandle($handle);
            if (!$index) {
                $orderMap[$i] = null;
                continue;
            }

            $engineKey = $index->engineType . ':' . md5(json_encode($index->engineConfig ?? []));

            if (!isset($groups[$engineKey])) {
                $groups[$engineKey] = [
                    'engine' => $this->_getEngine($index),
                    'queries' => [],
                ];
            }

            $queryIndex = count($groups[$engineKey]['queries']);
            $groups[$engineKey]['queries'][] = [
                'index' => $index,
                'query' => $query,
                'options' => $options,
            ];

            $orderMap[$i] = [$engineKey, $queryIndex];
        }

        // Execute grouped multi-search calls
        $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        $groupResults = [];
        foreach ($groups as $engineKey => $group) {
            $start = microtime(true);
            $groupResults[$engineKey] = $group['engine']->multiSearch($group['queries']);
            $elapsedMs = (microtime(true) - $start) * 1000;

            if ($devMode) {
                Craft::info([
                    'msg' => 'searchIndex Twig multiSearch executed',
                    'engineGroup' => $engineKey,
                    'queryCount' => count($group['queries']),
                    'elapsedMs' => (int)round($elapsedMs),
                ], __METHOD__);
            }
        }

        // Reassemble results in original order
        $results = [];
        for ($i = 0; $i < count($searches); $i++) {
            if ($orderMap[$i] === null) {
                $results[] = SearchResult::empty();
            } else {
                [$engineKey, $queryIndex] = $orderMap[$i];
                $results[] = $groupResults[$engineKey][$queryIndex];
            }
        }

        return $results;
    }

    /**
     * Get the document count for an index.
     * Usage: {{ craft.searchIndex.docCount('places') }}
     *
     * @param string $handle
     * @return int|null Null if the index or engine is unavailable.
     */
    public function getDocCount(string $handle): ?int
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return null;
        }

        try {
            if (!class_exists($index->engineType)) {
                return null;
            }
            $engine = $this->_getEngine($index);
            if (!$engine->indexExists($index)) {
                return null;
            }
            return $engine->getDocumentCount($index);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Retrieve a single document from an index by handle and document ID.
     * Usage: {% set doc = craft.searchIndex.getDocument('places', '12345') %}
     *
     * @param string $handle     The index handle.
     * @param string $documentId The document ID.
     * @return array|null The document data, or null if not found.
     */
    public function getDocument(string $handle, string $documentId): ?array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return null;
        }

        try {
            if (!class_exists($index->engineType)) {
                return null;
            }
            $engine = $this->_getEngine($index);
            return $engine->getDocument($index, $documentId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if an index's engine is connected and the index exists.
     * Usage: {% if craft.searchIndex.isReady('places') %}
     *
     * @param string $handle
     * @return bool
     */
    public function isReady(string $handle): bool
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index || !$index->enabled) {
            return false;
        }

        try {
            if (!class_exists($index->engineType)) {
                return false;
            }
            $engine = $this->_getEngine($index);
            return $engine->indexExists($index);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate hidden `<input>` tags from a state array.
     *
     * Simplifies Sprig/form state management — define state once and inject
     * it into any form without manual hidden-input boilerplate.
     *
     * - Scalar values generate a single `<input>` per key.
     * - Indexed arrays expand into multiple `<input>` tags with a `[]` suffix.
     * - Nested associative arrays expand recursively (`name[key][]`).
     * - `null` and empty-string values are omitted.
     *
     * Usage: {{ craft.searchIndex.stateInputs({ query: query, sort: sort, page: 1, activeRegions: regions }, { exclude: 'query' }) }}
     *
     * @param array  $state   Key-value state to convert to hidden inputs.
     * @param array  $options Options: `exclude` (string|string[]) keys to skip.
     * @return Markup HTML-safe hidden input tags.
     */
    public function stateInputs(array $state, array $options = []): Markup
    {
        $exclude = $options['exclude'] ?? [];

        if (is_string($exclude)) {
            $exclude = [$exclude];
        }

        $html = '';

        foreach ($state as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }

            $html .= $this->_renderInputs((string)$key, $value);
        }

        return new Markup($html, 'UTF-8');
    }

    /**
     * Build a URL from a base path and query-parameter array.
     *
     * - Array values expand into `key[]=value` pairs.
     * - `null`, empty-string, and empty-array values are omitted for clean URLs.
     *
     * Usage: {{ craft.searchIndex.buildUrl('/search', { q: query, region: activeRegions, page: page > 1 ? page : null }) }}
     *
     * @param string $basePath The base URL path.
     * @param array  $params   Query parameters — arrays become `key[]=value` pairs.
     * @return string The assembled URL.
     */
    public function buildUrl(string $basePath, array $params): string
    {
        $parts = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            if (is_array($value)) {
                if (empty($value)) {
                    continue;
                }

                $encodedKey = rawurlencode((string)$key);

                foreach ($value as $v) {
                    if ($v !== null && $v !== '') {
                        $parts[] = $encodedKey . '[]=' . rawurlencode((string)$v);
                    }
                }
            } else {
                $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
            }
        }

        if (empty($parts)) {
            return $basePath;
        }

        return $basePath . '?' . implode('&', $parts);
    }

    /**
     * Get the engine schema/structure for an index (used by Sprig structure page).
     *
     * @param int $indexId
     * @return array{success: bool, schema?: mixed, message?: string}
     */
    public function getIndexSchema(int $indexId): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            return ['success' => false, 'message' => 'Index not found.'];
        }

        try {
            if (!class_exists($index->engineType)) {
                return ['success' => false, 'message' => 'Engine class not found.'];
            }

            $engine = $index->createEngine();

            if (!$engine->indexExists($index)) {
                return ['success' => false, 'message' => 'Index does not exist in the engine.'];
            }

            $schema = $engine->getIndexSchema($index);

            return ['success' => true, 'schema' => $schema];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Run a CP search with embedding resolution (used by Sprig search/document components).
     *
     * @param string $indexHandle
     * @param string $query
     * @param array  $options  Supports 'perPage', 'page', 'searchMode', 'embeddingField', 'voyageModel'.
     * @return array The same JSON shape as SearchController::actionSearch().
     */
    public function cpSearch(string $indexHandle, string $query, array $options = []): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return ['success' => false, 'message' => "Index \"{$indexHandle}\" not found."];
        }

        $perPage = (int)($options['perPage'] ?? 20);
        $page = (int)($options['page'] ?? 1);
        $searchMode = $options['searchMode'] ?? 'text';
        $embeddingField = $options['embeddingField'] ?? null;

        $searchOptions = [
            'perPage' => $perPage,
            'page' => $page,
        ];

        // Pass through additional unified search options used by frontend Sprig components.
        foreach (['facets', 'filters', 'sort', 'attributesToRetrieve', 'highlight', 'stats', 'histogram'] as $optionKey) {
            if (array_key_exists($optionKey, $options)) {
                $searchOptions[$optionKey] = $options[$optionKey];
            }
        }

        // Resolve embedding for vector/hybrid search modes
        if (in_array($searchMode, ['vector', 'hybrid'], true) && trim($query) !== '') {
            $embeddingField = $embeddingField ?: $index->getEmbeddingFieldName();

            if ($embeddingField === null) {
                return ['success' => false, 'message' => 'No embedding field found on this index.'];
            }

            $model = $options['voyageModel'] ?? 'voyage-3';
            $embedding = SearchIndex::$plugin->getVoyageClient()->embed($query, $model);

            if ($embedding === null) {
                return ['success' => false, 'message' => 'Voyage AI embedding failed. Check your API key in plugin settings.'];
            }

            $searchOptions['embedding'] = $embedding;
            $searchOptions['embeddingField'] = $embeddingField;
        }

        // For pure vector mode, use empty query so the engine does KNN-only search
        $searchQuery = $searchMode === 'vector' ? '' : $query;

        try {
            $engine = $this->_getEngine($index);
            $result = $engine->search($index, $searchQuery, $searchOptions);

            return [
                'success' => true,
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'processingTimeMs' => $result->processingTimeMs,
                'hits' => $result->hits,
                'facets' => $result->facets,
                'stats' => $result->stats,
                'histograms' => $result->histograms,
                'suggestions' => $result->suggestions,
                'raw' => $result->raw,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Search failed: ' . $e->getMessage()];
        }
    }

    /**
     * Build a complete search context for frontend Sprig stubs.
     *
     * Encapsulates all the logic that SearchBox.php performs (role detection,
     * facet field discovery, sort option resolution, filter normalisation, search
     * execution) so that published inline-Sprig templates can call one method
     * and receive everything they need to render.
     *
     * Usage in Twig:
     *   {% set ctx = craft.searchIndex.searchContext(indexHandle, {
     *       query: query, page: page, perPage: perPage,
     *       sortField: sortField, sortDirection: sortDirection,
     *       filters: filters, doSearch: 1,
     *   }) %}
     *
     * @param string $indexHandle The index handle.
     * @param array  $options     Keys: query, page, perPage, sortField, sortDirection, filters, doSearch.
     * @return array{roles: array, facetFields: string[], sortOptions: array, data: array|null}
     */
    public function searchContext(string $indexHandle, array $options = []): array
    {
        $empty = [
            'roles' => [],
            'facetFields' => [],
            'sortOptions' => [['label' => 'Relevance', 'value' => '']],
            'data' => null,
        ];

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return $empty;
        }

        // Single pass over field mappings to extract roles, facet fields, sort options, and numeric fields
        $roles = [];
        $facetFields = [];
        $numericFields = [];
        $sortOptions = [['label' => 'Relevance', 'value' => '']];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
            }

            if ($mapping->role !== null) {
                $roles[$mapping->role] = $mapping->indexFieldName;
            }

            if ($mapping->indexFieldType === FieldMapping::TYPE_FACET) {
                $facetFields[] = $mapping->indexFieldName;
            }

            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT], true)
                && $mapping->role === null
            ) {
                $numericFields[] = $mapping->indexFieldName;
            }

            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT, FieldMapping::TYPE_DATE], true)
                && $mapping->role === null
            ) {
                $sortOptions[] = [
                    'label' => $mapping->indexFieldName,
                    'value' => $mapping->indexFieldName,
                ];
            }
        }

        $facetFields = array_values(array_unique($facetFields));
        $numericFields = array_values(array_unique($numericFields));

        $result = [
            'roles' => $roles,
            'facetFields' => $facetFields,
            'numericFields' => $numericFields,
            'sortOptions' => $sortOptions,
            'data' => null,
        ];

        // Only execute the search when doSearch is truthy (match SprigBooleanTrait semantics)
        $doSearch = $options['doSearch'] ?? false;
        if (!is_bool($doSearch)) {
            $doSearch = in_array((string)$doSearch, ['1', 'true', 'yes', 'on'], true);
        }
        if (!$doSearch) {
            return $result;
        }

        $query = (string)($options['query'] ?? '');
        $perPage = max(1, (int)($options['perPage'] ?? 10));
        $page = max(1, (int)($options['page'] ?? 1));

        $searchOptions = [
            'perPage' => $perPage,
            'page' => $page,
        ];

        // Normalise and apply filters
        $filters = $this->_normaliseFilters($options['filters'] ?? []);
        if (!empty($filters)) {
            $searchOptions['filters'] = $filters;
        }

        if (!empty($facetFields)) {
            $searchOptions['facets'] = $facetFields;
        }

        if (!empty($numericFields)) {
            $searchOptions['stats'] = $numericFields;
        }

        // Histogram: pass through if provided (opt-in, interval is domain-specific)
        if (!empty($options['histogram'])) {
            $searchOptions['histogram'] = $options['histogram'];
        }

        $sortField = (string)($options['sortField'] ?? '');
        if ($sortField !== '') {
            $direction = ($options['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $searchOptions['sort'] = [$sortField => $direction];
        }

        $result['data'] = $this->cpSearch($indexHandle, $query, $searchOptions);

        // Auto-histogram: calculate intervals from stats, fetch in lightweight follow-up
        if (!empty($numericFields) && !isset($options['histogram'])
            && $result['data']['success'] && !empty($result['data']['stats'])
        ) {
            $histogramConfig = [];

            foreach ($result['data']['stats'] as $field => $stat) {
                $interval = $this->niceInterval($stat['min'] ?? 0, $stat['max'] ?? 0);

                if ($interval > 0) {
                    $histogramConfig[$field] = $interval;
                }
            }

            if (!empty($histogramConfig)) {
                $histogramOptions = [
                    'perPage' => 0,
                    'histogram' => $histogramConfig,
                ];

                if (!empty($filters)) {
                    $histogramOptions['filters'] = $filters;
                }

                $histogramResult = $this->cpSearch($indexHandle, $query, $histogramOptions);

                if (!empty($histogramResult['histograms'])) {
                    $result['data']['histograms'] = $histogramResult['histograms'];
                }
            }
        }

        return $result;
    }

    /**
     * Validate field mappings for an index (used by Sprig validation component).
     *
     * @param int $indexId
     * @return array The validation result from FieldMappingValidator::validateIndex().
     */
    public function validateFieldMappings(int $indexId): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            return ['success' => false, 'message' => 'Index not found.'];
        }

        return SearchIndex::$plugin->getFieldMappingValidator()->validateIndex($index);
    }

    /**
     * Build a markdown table from validation results.
     *
     * Delegates to FieldMappingValidator::buildValidationMarkdown().
     *
     * @param array       $data       The validation result array.
     * @param string|null $filterMode 'issues' to include only warnings/errors/nulls, null for all.
     * @param string      $titleSuffix Appended to the markdown title.
     * @return string Markdown string.
     */
    public function buildValidationMarkdown(array $data, ?string $filterMode = null, string $titleSuffix = ''): string
    {
        return SearchIndex::$plugin->getFieldMappingValidator()->buildValidationMarkdown($data, $filterMode, $titleSuffix);
    }

    /**
     * Get a map of index handles to their embedding field names.
     *
     * @return array<string, string[]>
     */
    public function getEmbeddingFieldsMap(): array
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();
        $embeddingFields = [];

        foreach ($indexes as $index) {
            $fields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_EMBEDDING) {
                    $fields[] = $mapping->indexFieldName;
                }
            }
            if (!empty($fields)) {
                $embeddingFields[$index->handle] = $fields;
            }
        }

        return $embeddingFields;
    }

    /**
     * Test the connection to a search engine (used by Sprig test-connection component).
     *
     * @param string $engineType Fully-qualified engine class name.
     * @param array  $config     Engine configuration array.
     * @return array{success: bool, message: string}
     */
    public function testConnection(string $engineType, array $config): array
    {
        if (!class_exists($engineType) || !is_subclass_of($engineType, EngineInterface::class)) {
            return ['success' => false, 'message' => 'Invalid engine type.'];
        }

        if (!$engineType::isClientInstalled()) {
            return [
                'success' => false,
                'message' => 'Client library not installed. Run: composer require ' . $engineType::requiredPackage(),
            ];
        }

        @set_time_limit(10);

        $engine = new $engineType($config);

        try {
            $result = $engine->testConnection();
            return [
                'success' => $result,
                'message' => $result ? 'Connection successful.' : 'Connection failed.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection error: ' . $e->getMessage()];
        }
    }

    /**
     * Resolve vector search options by generating an embedding and detecting the target field.
     *
     * Delegates to VoyageClient::resolveEmbeddingOptions() which centralises the logic.
     *
     * @param Index  $index   The index being searched.
     * @param string $query   The search query text.
     * @param array  $options The caller-provided search options.
     * @return array The options with `embedding` and `embeddingField` injected.
     */
    private function _resolveVectorSearchOptions(Index $index, string $query, array $options): array
    {
        return SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);
    }

    /**
     * Return a cached engine instance for the given index.
     *
     * Engines are cached by engine type + config hash so the same HTTP client
     * is reused across multiple calls within a single request (e.g. Twig loops).
     *
     * @param Index $index
     * @return EngineInterface
     */
    private function _getEngine(Index $index): EngineInterface
    {
        $key = $index->engineType . ':' . md5(json_encode($index->engineConfig ?? []));

        if (!isset($this->_engineCache[$key])) {
            $this->_engineCache[$key] = $index->createEngine();
        }

        return $this->_engineCache[$key];
    }

    /**
     * Recursively render hidden input tags for a given name/value pair.
     *
     * @param string $name  The input name (may include brackets for nesting).
     * @param mixed  $value The value — scalars, indexed arrays, and associative arrays are supported.
     * @return string HTML hidden input tag(s).
     */
    private function _renderInputs(string $name, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_array($value)) {
            $html = '';

            foreach ($value as $k => $v) {
                if (is_int($k)) {
                    // Indexed array: name[]
                    $html .= $this->_renderInputs($name . '[]', $v);
                } else {
                    // Associative array: name[key]
                    $html .= $this->_renderInputs($name . '[' . $k . ']', $v);
                }
            }

            return $html;
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">' . "\n",
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * Normalise filters to `field => [value, ...]` with unique non-empty strings.
     *
     * Mirrors the normalisation in SearchBox::normaliseFilters().
     *
     * @param array $filters
     * @return array<string, string[]>
     */
    private function _normaliseFilters(array $filters): array
    {
        $normalised = [];

        foreach ($filters as $field => $values) {
            $fieldName = (string)$field;
            if ($fieldName === '') {
                continue;
            }

            // Range filter: { min: ..., max: ... }
            if (is_array($values) && $this->_isRangeFilter($values)) {
                $range = [];
                if (isset($values['min']) && $values['min'] !== '' && $values['min'] !== null) {
                    $range['min'] = (float)$values['min'];
                }
                if (isset($values['max']) && $values['max'] !== '' && $values['max'] !== null) {
                    $range['max'] = (float)$values['max'];
                }
                if (!empty($range)) {
                    $normalised[$fieldName] = $range;
                }
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            $filteredValues = array_values(array_unique(array_filter(
                array_map('strval', $values),
                fn(string $value) => $value !== '',
            )));

            if (!empty($filteredValues)) {
                $normalised[$fieldName] = $filteredValues;
            }
        }

        return $normalised;
    }

    /**
     * Calculate a "nice" histogram interval for a numeric range using 1-2-5 rounding.
     *
     * Targets approximately `$targetBuckets` buckets and snaps the raw interval
     * to the nearest 1×, 2×, or 5× power of ten for human-readable bucket edges.
     *
     * @param float $min           The minimum value in the dataset.
     * @param float $max           The maximum value in the dataset.
     * @param int   $targetBuckets Desired number of buckets (default 10).
     * @return float The nice interval, or 0 if the range is non-positive.
     */
    public function niceInterval(float $min, float $max, int $targetBuckets = 10): float
    {
        $range = $max - $min;

        if ($range <= 0) {
            return 0;
        }

        $rawInterval = $range / $targetBuckets;
        $magnitude = pow(10, floor(log10($rawInterval)));
        $normalized = $rawInterval / $magnitude;

        if ($normalized <= 1.5) {
            $nice = 1;
        } elseif ($normalized <= 3.5) {
            $nice = 2;
        } elseif ($normalized <= 7.5) {
            $nice = 5;
        } else {
            $nice = 10;
        }

        return $nice * $magnitude;
    }

    /**
     * Check whether a filter value represents a range filter (min/max).
     *
     * @param array $value
     * @return bool
     */
    private function _isRangeFilter(array $value): bool
    {
        $keys = array_keys($value);
        $allowed = ['min', 'max'];

        return !empty($keys)
            && empty(array_diff($keys, $allowed))
            && !empty(array_intersect($keys, $allowed));
    }
}
