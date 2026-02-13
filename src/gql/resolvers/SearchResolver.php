<?php

/**
 * Search Index plugin for Craft CMS -- SearchResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\SearchIndex;

/**
 * GraphQL resolver for the searchIndex query.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchResolver
{
    /**
     * Resolve the searchIndex GraphQL query.
     *
     * @param mixed $root   The root value.
     * @param array $args   The query arguments (index, query, perPage, page).
     * @return array|null The search result as an array, or null if the index is not found.
     */
    public static function resolve(mixed $root, array $args): ?array
    {
        $handle = $args['index'];
        $query = $args['query'];
        $perPage = $args['perPage'] ?? 20;
        $page = $args['page'] ?? 1;

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return null;
        }

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);

        $result = $engine->search($index, $query, [
            'perPage' => $perPage,
            'page' => $page,
        ]);

        return [
            'totalHits' => $result->totalHits,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalPages' => $result->totalPages,
            'processingTimeMs' => $result->processingTimeMs,
            'hits' => $result->hits,
        ];
    }
}
