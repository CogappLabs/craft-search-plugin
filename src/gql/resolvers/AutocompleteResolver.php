<?php

/**
 * Search Index plugin for Craft CMS -- AutocompleteResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\gql\GqlPermissions;
use cogapp\searchindex\SearchIndex;

/**
 * GraphQL resolver for the searchIndexAutocomplete query.
 *
 * Mirrors SearchIndexVariable::autocomplete() — returns a small result set
 * with only role fields (title, url, image) for minimal payload.
 *
 * @author cogapp
 * @since 1.0.0
 */
class AutocompleteResolver
{
    use EngineCacheTrait;

    /**
     * Resolve the searchIndexAutocomplete GraphQL query.
     *
     * @param mixed $root The root value.
     * @param array $args The query arguments.
     * @return array|null
     */
    public static function resolve(mixed $root, array $args): ?array
    {
        $handle = $args['index'];
        $query = $args['query'];
        $perPage = $args['perPage'] ?? 5;

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            throw new \GraphQL\Error\UserError('Index not found: ' . $handle);
        }

        GqlPermissions::requireIndexReadAccess($index);

        $options = [
            'perPage' => $perPage,
            'page' => 1,
        ];

        // Auto-detect role fields for minimal payload
        $roleFields = $index->getRoleFieldMap();

        if (!empty($roleFields)) {
            $options['attributesToRetrieve'] = array_merge(['objectID'], array_values($roleFields));
        }

        $engine = self::getEngine($index);
        $result = $engine->search($index, $query, $options);

        // Inject _roles into each hit
        $hits = SearchResolver::injectRoles($result->hits, $index);

        return [
            'totalHits' => $result->totalHits,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalPages' => $result->totalPages,
            'processingTimeMs' => $result->processingTimeMs,
            'hits' => $hits,
            'facets' => null,
            'stats' => null,
            'histograms' => null,
            'suggestions' => [],
        ];
    }
}
