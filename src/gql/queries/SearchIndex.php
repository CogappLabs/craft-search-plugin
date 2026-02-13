<?php

/**
 * Search Index plugin for Craft CMS -- GQL query definitions.
 */

namespace cogapp\searchindex\gql\queries;

use cogapp\searchindex\gql\resolvers\SearchResolver;
use cogapp\searchindex\gql\types\SearchResultType;
use GraphQL\Type\Definition\Type;

/**
 * Registers the searchIndex top-level GraphQL query.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndex
{
    /**
     * Return the GraphQL query definitions provided by this plugin.
     *
     * @return array
     */
    public static function getQueries(): array
    {
        return [
            'searchIndex' => [
                'type' => SearchResultType::getType(),
                'args' => [
                    'index' => Type::nonNull(Type::string()),
                    'query' => Type::nonNull(Type::string()),
                    'perPage' => [
                        'type' => Type::int(),
                        'defaultValue' => 20,
                    ],
                    'page' => [
                        'type' => Type::int(),
                        'defaultValue' => 1,
                    ],
                ],
                'resolve' => [SearchResolver::class, 'resolve'],
                'description' => 'Search a configured search index by handle.',
            ],
        ];
    }
}
