<?php

/**
 * Search Index plugin for Craft CMS -- SearchResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\gql\GqlPermissions;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\Asset;

/**
 * GraphQL resolver for the searchIndex query.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchResolver
{
    use EngineCacheTrait;

    /** @var array<string, array<string, string>> Cached role field maps keyed by index handle. */
    private static array $_roleFieldCache = [];

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
        $perPage = min(max(1, (int)($args['perPage'] ?? 20)), 250);
        $page = $args['page'] ?? 1;
        $fields = $args['fields'] ?? null;
        $includeTiming = (bool)($args['includeTiming'] ?? false);

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            throw new \GraphQL\Error\UserError('Index not found: ' . $handle);
        }

        GqlPermissions::requireIndexReadAccess($index);

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
        if (isset($args['maxValuesPerFacet'])) {
            $options['maxValuesPerFacet'] = $args['maxValuesPerFacet'];
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
            if (trim($query) !== '') {
                $options = SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);
            }
        }

        $engine = self::getEngine($index);

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

        // Inject _roles into each hit
        $hits = self::injectRoles($result->hits, $index);

        return [
            'totalHits' => $result->totalHits,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalPages' => $result->totalPages,
            'processingTimeMs' => $result->processingTimeMs,
            'totalTimeMs' => $includeTiming ? $totalTimeMs : null,
            'overheadTimeMs' => $includeTiming ? $overheadTimeMs : null,
            'hits' => $hits,
            'facets' => !empty($result->facets) ? json_encode($result->facets) : null,
            'stats' => !empty($result->stats) ? json_encode($result->stats) : null,
            'histograms' => !empty($result->histograms) ? json_encode($result->histograms) : null,
            'suggestions' => $result->suggestions,
        ];
    }

    /**
     * Inject `_roles` into each hit based on the index's role mappings.
     *
     * For image/thumbnail roles, if the value is numeric (asset ID), resolve to URL.
     * Asset IDs are batch-loaded in a single query to avoid N+1 queries.
     *
     * @param array $hits  Array of hit arrays.
     * @param Index $index The index to read role mappings from.
     * @return array Hits with `_roles` injected.
     */
    public static function injectRoles(array $hits, Index $index): array
    {
        $roleFields = self::getRoleFields($index);

        if (empty($roleFields)) {
            return $hits;
        }

        // Collect image/thumbnail roles that may contain asset IDs
        $assetRoles = array_filter(
            $roleFields,
            fn(string $role) => in_array($role, [FieldMapping::ROLE_IMAGE, FieldMapping::ROLE_THUMBNAIL], true),
            ARRAY_FILTER_USE_KEY,
        );

        // First pass: collect all unique numeric asset IDs across all hits
        $assetIds = [];
        foreach ($hits as $hit) {
            foreach ($assetRoles as $role => $fieldName) {
                $value = $hit[$fieldName] ?? null;
                if ($value !== null && is_numeric($value)) {
                    $assetIds[(int)$value] = true;
                }
            }
        }

        // Batch load all assets in a single query and build an id → url map
        $assetUrlMap = [];
        if (!empty($assetIds)) {
            $assets = Asset::find()->id(array_keys($assetIds))->all();
            foreach ($assets as $asset) {
                $assetUrlMap[$asset->id] = $asset->getUrl();
            }
        }

        // Second pass: inject roles, using the pre-loaded map for asset URL resolution
        foreach ($hits as &$hit) {
            $roles = [];
            foreach ($roleFields as $role => $fieldName) {
                $value = $hit[$fieldName] ?? null;

                // For image/thumbnail roles, resolve asset ID to URL via batch map
                if ($value !== null && is_numeric($value)
                    && in_array($role, [FieldMapping::ROLE_IMAGE, FieldMapping::ROLE_THUMBNAIL], true)
                ) {
                    $value = $assetUrlMap[(int)$value] ?? null;
                }

                $roles[$role] = $value;
            }
            $hit['_roles'] = $roles;
        }

        return $hits;
    }

    /**
     * Get the role-to-field mapping for an index (cached per request).
     *
     * @param Index $index
     * @return array<string, string> role => fieldName
     */
    private static function getRoleFields(Index $index): array
    {
        $handle = $index->handle;

        if (!isset(self::$_roleFieldCache[$handle])) {
            $roleFields = [];
            foreach ($index->getFieldMappings() as $mapping) {
                if ($mapping->enabled && $mapping->role !== null) {
                    $roleFields[$mapping->role] = $mapping->indexFieldName;
                }
            }
            self::$_roleFieldCache[$handle] = $roleFields;
        }

        return self::$_roleFieldCache[$handle];
    }
}
