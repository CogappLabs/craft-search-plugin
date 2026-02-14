<?php

/**
 * Search Index plugin for Craft CMS -- Twig variable class.
 */

namespace cogapp\searchindex\variables;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;

/**
 * Provides Search Index functionality to Twig templates via craft.searchIndex.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndexVariable
{
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

        $engine = $index->createEngine();

        $start = microtime(true);
        $result = $engine->search($index, $query, $options);
        $elapsedMs = (microtime(true) - $start) * 1000;

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            $context = [
                'index' => $handle,
                'query' => $query,
                'options' => $options,
                'engine' => $index->engineType,
                'elapsedMs' => (int)round($elapsedMs),
                'engineMs' => $result->processingTimeMs ?? null,
            ];
            Craft::info(array_merge(['msg' => 'searchIndex Twig search executed'], $context), __METHOD__);
        }

        return $result;
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
                    'engine' => $index->createEngine(),
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
            $engine = $index->createEngine();
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
            $engine = $index->createEngine();
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
            $engine = $index->createEngine();
            return $engine->indexExists($index);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
