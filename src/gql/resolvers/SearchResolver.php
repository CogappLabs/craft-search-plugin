<?php

/**
 * Search Index plugin for Craft CMS -- SearchResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\SearchIndex;
use Craft;

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
        $fields = $args['fields'] ?? null;
        $includeTiming = (bool)($args['includeTiming'] ?? false);

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            return null;
        }

        $engine = $index->createEngine();

        $options = [
            'perPage' => $perPage,
            'page' => $page,
        ];
        if (is_array($fields) && !empty($fields)) {
            $options['fields'] = $fields;
        }

        $start = microtime(true);
        $result = $engine->search($index, $query, $options);
        $elapsedMs = (microtime(true) - $start) * 1000;
        $totalTimeMs = (int)round($elapsedMs);
        $engineTimeMs = $result->processingTimeMs ?? null;
        $overheadTimeMs = $engineTimeMs !== null ? max(0, $totalTimeMs - (int)$engineTimeMs) : null;

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            $context = [
                'index' => $handle,
                'query' => $query,
                'page' => $page,
                'perPage' => $perPage,
                'fields' => $fields,
                'engine' => $index->engineType,
                'elapsedMs' => (int)round($elapsedMs),
                'engineMs' => $result->processingTimeMs ?? null,
            ];
            Craft::info(array_merge(['msg' => 'searchIndex GraphQL query executed'], $context), __METHOD__);
        }

        return [
            'totalHits' => $result->totalHits,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalPages' => $result->totalPages,
            'processingTimeMs' => $result->processingTimeMs,
            'totalTimeMs' => $includeTiming ? $totalTimeMs : null,
            'overheadTimeMs' => $includeTiming ? $overheadTimeMs : null,
            'hits' => $result->hits,
        ];
    }
}
