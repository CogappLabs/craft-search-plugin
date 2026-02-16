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
     * @param array $args   The query arguments.
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
            throw new \GraphQL\Error\UserError('Index not found: ' . $handle);
        }

        $options = [
            'perPage' => $perPage,
            'page' => $page,
        ];
        if (is_array($fields) && !empty($fields)) {
            $options['fields'] = $fields;
        }

        // Sort: decode JSON string to array
        if (!empty($args['sort'])) {
            try {
                $sort = json_decode($args['sort'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($sort)) {
                    $options['sort'] = $sort;
                }
            } catch (\JsonException $e) {
                throw new \GraphQL\Error\UserError('Invalid JSON in sort argument: ' . $e->getMessage());
            }
        }

        // Facets
        if (!empty($args['facets'])) {
            $options['facets'] = $args['facets'];
        }

        // Filters: decode JSON string to array
        if (!empty($args['filters'])) {
            try {
                $filters = json_decode($args['filters'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($filters)) {
                    $options['filters'] = $filters;
                }
            } catch (\JsonException $e) {
                throw new \GraphQL\Error\UserError('Invalid JSON in filters argument: ' . $e->getMessage());
            }
        }

        // Stats
        if (!empty($args['stats'])) {
            $options['stats'] = $args['stats'];
        }

        // Histogram: decode JSON string to array
        if (!empty($args['histogram'])) {
            try {
                $histogram = json_decode($args['histogram'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($histogram)) {
                    $options['histogram'] = $histogram;
                }
            } catch (\JsonException $e) {
                throw new \GraphQL\Error\UserError('Invalid JSON in histogram argument: ' . $e->getMessage());
            }
        }

        // Highlighting
        if (!empty($args['highlight'])) {
            $options['highlight'] = true;
        }

        // Suggestions
        if (!empty($args['suggest'])) {
            $options['suggest'] = true;
        }

        // Vector search: generate embedding via Voyage AI
        if (!empty($args['vectorSearch']) && !isset($options['embedding'])) {
            if (!empty($args['embeddingField'])) {
                $options['embeddingField'] = $args['embeddingField'];
            }
            if (!empty($args['voyageModel'])) {
                $options['voyageModel'] = $args['voyageModel'];
            }
            $options = SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);
        }

        $engine = $index->createEngine();

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
            'facets' => !empty($result->facets) ? json_encode($result->facets) : null,
            'stats' => !empty($result->stats) ? json_encode($result->stats) : null,
            'histograms' => !empty($result->histograms) ? json_encode($result->histograms) : null,
            'suggestions' => $result->suggestions,
        ];
    }
}
