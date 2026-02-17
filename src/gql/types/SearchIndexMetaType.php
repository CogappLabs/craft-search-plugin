<?php

/**
 * Search Index plugin for Craft CMS -- SearchIndexMetaType GQL type.
 */

namespace cogapp\searchindex\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for index metadata (roles, facet fields, sort options).
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndexMetaType
{
    /**
     * Return the SearchIndexMeta GraphQL type, registering it if needed.
     *
     * @return ObjectType
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SearchIndexMeta', fn() => new ObjectType([
            'name' => 'SearchIndexMeta',
            'fields' => [
                'roles' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Role-to-field mapping as JSON, e.g. {"title":"title","image":"mainImage"}.',
                ],
                'facetFields' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'Field names configured as facets.',
                ],
                'sortOptions' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Available sort options as JSON, e.g. [{"label":"Relevance","value":""}].',
                ],
            ],
        ]));
    }
}
