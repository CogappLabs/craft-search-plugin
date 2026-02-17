<?php

/**
 * Search Index plugin for Craft CMS -- FacetValuesResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\gql\GqlPermissions;
use cogapp\searchindex\SearchIndex;

/**
 * GraphQL resolver for the searchIndexFacetValues query.
 *
 * Mirrors SearchIndexVariable::searchFacetValues() — returns matching
 * facet values for a specific field with optional text filtering.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FacetValuesResolver
{
    use EngineCacheTrait;

    /**
     * Resolve the searchIndexFacetValues GraphQL query.
     *
     * @param mixed $root The root value.
     * @param array $args The query arguments.
     * @return array Array of ['value' => string, 'count' => int] items.
     */
    public static function resolve(mixed $root, array $args): array
    {
        $handle = $args['index'];
        $facetField = $args['facetField'];
        $query = $args['query'] ?? '';
        $maxValues = $args['maxValues'] ?? 10;

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            throw new \GraphQL\Error\UserError('Index not found: ' . $handle);
        }

        GqlPermissions::requireIndexReadAccess($index);

        // Decode filters JSON if provided
        $filters = [];
        if (!empty($args['filters'])) {
            try {
                $decoded = json_decode($args['filters'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $filters = $decoded;
                }
            } catch (\JsonException $e) {
                throw new \GraphQL\Error\UserError('Invalid JSON in filters argument: ' . $e->getMessage());
            }
        }

        $engine = self::getEngine($index);
        $result = $engine->searchFacetValues($index, [$facetField], $query, $maxValues, $filters);

        return $result[$facetField] ?? [];
    }
}
