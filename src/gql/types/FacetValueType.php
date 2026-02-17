<?php

/**
 * Search Index plugin for Craft CMS -- FacetValueType GQL type.
 */

namespace cogapp\searchindex\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for a single facet value with its count.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FacetValueType
{
    /**
     * Return the FacetValue GraphQL type, registering it if needed.
     *
     * @return ObjectType
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('FacetValue', fn() => new ObjectType([
            'name' => 'FacetValue',
            'fields' => [
                'value' => Type::nonNull(Type::string()),
                'count' => Type::nonNull(Type::int()),
            ],
        ]));
    }
}
