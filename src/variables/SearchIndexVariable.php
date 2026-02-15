<?php

/**
 * Search Index plugin for Craft CMS -- Twig variable class.
 */

namespace cogapp\searchindex\variables;

use cogapp\searchindex\engines\EngineInterface;
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
     * Returns an array of `['value' => string, 'count' => int]` items matching
     * the facet query, sorted by count descending.
     *
     * Usage: {% set values = craft.searchIndex.searchFacetValues('articles', 'category', 'tech') %}
     *
     * @param string $handle    The index handle.
     * @param string $facetName The facet field name to search within.
     * @param string $query     The query to match against facet values.
     * @param array  $options   Additional options (e.g. `filters` to narrow the base set, `maxValues` for limit).
     * @return array Array of ['value' => string, 'count' => int] items.
     */
    public function searchFacetValues(string $handle, string $facetName, string $query = '', array $options = []): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return [];
        }

        $engine = $this->_getEngine($index);
        $maxValues = $options['maxValues'] ?? 10;
        unset($options['maxValues']);

        // Strategy: perform a faceted search and filter the returned facet values
        // by the query prefix. This works across all engines.
        $searchOptions = array_merge($options, [
            'facets' => [$facetName],
            'perPage' => 0, // We only want facet counts, not hits
        ]);

        // For engines that support engine-native facet search, use per-page=0
        // to minimise hit payload. We search with empty query to get all facet values.
        $result = $engine->search($index, '', $searchOptions);

        $facetValues = $result->facets[$facetName] ?? [];

        // Client-side filter by query prefix (case-insensitive)
        if ($query !== '') {
            $queryLower = mb_strtolower($query);
            $facetValues = array_values(array_filter(
                $facetValues,
                fn($item) => str_contains(mb_strtolower($item['value']), $queryLower),
            ));
        }

        return array_slice($facetValues, 0, (int)$maxValues);
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
     * Resolve vector search options by generating an embedding and detecting the target field.
     *
     * Called when `vectorSearch: true` is set but no pre-computed `embedding` is provided.
     * Uses the Voyage AI client to generate an embedding from the query text and
     * auto-detects the embedding field from the index's field mappings if not specified.
     *
     * @param Index  $index   The index being searched.
     * @param string $query   The search query text.
     * @param array  $options The caller-provided search options.
     * @return array The options with `embedding` and `embeddingField` injected.
     */
    private function _resolveVectorSearchOptions(Index $index, string $query, array $options): array
    {
        if (trim($query) === '') {
            return $options;
        }

        // Normalise empty string to unset so auto-detection kicks in
        if (isset($options['embeddingField']) && $options['embeddingField'] === '') {
            unset($options['embeddingField']);
        }

        // Determine the target embedding field before calling Voyage AI
        if (!isset($options['embeddingField'])) {
            $options['embeddingField'] = $index->getEmbeddingFieldName();

            if ($options['embeddingField'] === null) {
                Craft::warning('vectorSearch requested but no embedding field found on index "' . $index->handle . '"', __METHOD__);
                return $options;
            }
        }

        $model = is_string($options['voyageModel'] ?? null) && $options['voyageModel'] !== ''
            ? $options['voyageModel']
            : 'voyage-3';
        $embedding = SearchIndex::$plugin->getVoyageClient()->embed($query, $model);

        if ($embedding === null) {
            return $options;
        }

        $options['embedding'] = $embedding;

        return $options;
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
}
