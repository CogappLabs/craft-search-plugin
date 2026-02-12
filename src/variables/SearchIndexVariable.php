<?php

/**
 * Search Index plugin for Craft CMS -- Twig variable class.
 */

namespace cogapp\searchindex\variables;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;

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
     * Usage: {% set results = craft.searchIndex.search('places', 'london', { size: 20 }) %}
     *
     * @param string $handle  The index handle to search.
     * @param string $query   The search query string.
     * @param array  $options Engine-specific search options (e.g. size, filters).
     * @return array Result set with 'hits', 'totalHits', and engine-specific metadata.
     */
    public function search(string $handle, string $query, array $options = []): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return ['hits' => [], 'totalHits' => 0];
        }

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);

        return $engine->search($index, $query, $options);
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
            $engineClass = $index->engineType;
            if (!class_exists($engineClass)) {
                return null;
            }
            $engine = new $engineClass($index->engineConfig ?? []);
            if (!$engine->indexExists($index)) {
                return null;
            }
            return $engine->getDocumentCount($index);
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
            $engineClass = $index->engineType;
            if (!class_exists($engineClass)) {
                return false;
            }
            $engine = new $engineClass($index->engineConfig ?? []);
            return $engine->indexExists($index);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
