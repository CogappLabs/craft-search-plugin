<?php

/**
 * Search Index plugin for Craft CMS -- SearchDocumentFieldType GQL type.
 */

namespace cogapp\searchindex\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL type for the Search Document field value.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchDocumentFieldType
{
    /**
     * Return the SearchDocumentFieldData GraphQL type, registering it if needed.
     *
     * @return ObjectType
     */
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SearchDocumentFieldData', fn() => new ObjectType([
            'name' => 'SearchDocumentFieldData',
            'fields' => [
                'indexHandle' => Type::string(),
                'documentId' => Type::string(),
            ],
        ]));
    }
}
