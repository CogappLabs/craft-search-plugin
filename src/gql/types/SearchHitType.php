<?php

/**
 * Search Index plugin for Craft CMS -- SearchHitType GQL type.
 */

namespace cogapp\searchindex\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for a single search hit.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchHitType
{
    /**
     * Return the SearchHit GraphQL type, registering it if needed.
     *
     * @return ObjectType
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SearchHit', fn() => new ObjectType([
            'name' => 'SearchHit',
            'fields' => [
                'objectID' => Type::string(),
                'title' => Type::string(),
                'uri' => Type::string(),
                '_score' => Type::float(),
                '_highlights' => [
                    'type' => Type::string(),
                    'description' => 'Highlight fragments as JSON: {"field":["fragment",...]}.',
                    'resolve' => fn(array $hit) => !empty($hit['_highlights']) ? json_encode($hit['_highlights']) : null,
                ],
                '_roles' => [
                    'type' => Type::string(),
                    'description' => 'Resolved role values as JSON: {"title":"...","image":"https://...","url":"..."}.',
                    'resolve' => fn(array $hit) => !empty($hit['_roles']) ? json_encode($hit['_roles']) : null,
                ],
                'data' => [
                    'type' => Type::string(),
                    'description' => 'All document fields as a JSON string.',
                    'resolve' => fn(array $hit) => json_encode(
                        array_diff_key($hit, array_flip(['objectID', 'title', 'uri', '_score', '_highlights', '_roles'])),
                    ),
                ],
            ],
        ]));
    }
}
