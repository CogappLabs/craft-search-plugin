<?php

/**
 * Search Index plugin for Craft CMS -- SearchResultType GQL type.
 */

namespace cogapp\searchindex\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for a paginated search result set.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchResultType
{
    /**
     * Return the SearchResult GraphQL type, registering it if needed.
     *
     * @return ObjectType
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SearchResult', fn() => new ObjectType([
            'name' => 'SearchResult',
            'fields' => [
                'totalHits' => Type::nonNull(Type::int()),
                'page' => Type::nonNull(Type::int()),
                'perPage' => Type::nonNull(Type::int()),
                'totalPages' => Type::nonNull(Type::int()),
                'processingTimeMs' => Type::nonNull(Type::int()),
                'hits' => Type::nonNull(Type::listOf(Type::nonNull(SearchHitType::getType()))),
            ],
        ]));
    }
}
