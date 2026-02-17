<?php

/**
 * Search Index plugin for Craft CMS -- MetaResolver.
 */

namespace cogapp\searchindex\gql\resolvers;

use cogapp\searchindex\gql\GqlPermissions;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;

/**
 * GraphQL resolver for the searchIndexMeta query.
 *
 * Mirrors the metadata extraction from SearchIndexVariable::searchContext()
 * — returns roles, facet fields, and sort options for a given index.
 *
 * @author cogapp
 * @since 1.0.0
 */
class MetaResolver
{
    /**
     * Resolve the searchIndexMeta GraphQL query.
     *
     * @param mixed $root The root value.
     * @param array $args The query arguments.
     * @return array
     */
    public static function resolve(mixed $root, array $args): array
    {
        $handle = $args['index'];

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($handle);

        if (!$index) {
            throw new \GraphQL\Error\UserError('Index not found: ' . $handle);
        }

        GqlPermissions::requireIndexReadAccess($index);

        $roles = [];
        $facetFields = [];
        $sortOptions = [['label' => 'Relevance', 'value' => '']];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
            }

            if ($mapping->role !== null) {
                $roles[$mapping->role] = $mapping->indexFieldName;
            }

            if ($mapping->indexFieldType === FieldMapping::TYPE_FACET) {
                $facetFields[] = $mapping->indexFieldName;
            }

            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT, FieldMapping::TYPE_DATE], true)
                && $mapping->role === null
            ) {
                $sortOptions[] = [
                    'label' => $mapping->indexFieldName,
                    'value' => $mapping->indexFieldName,
                ];
            }
        }

        $facetFields = array_values(array_unique($facetFields));

        return [
            'roles' => json_encode($roles),
            'facetFields' => $facetFields,
            'sortOptions' => json_encode($sortOptions),
        ];
    }
}
