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
            ],
        ]));
    }
}
