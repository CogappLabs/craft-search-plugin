<?php

/**
 * Search Index plugin for Craft CMS -- GQL query definitions.
 */

namespace cogapp\searchindex\gql\queries;

use cogapp\searchindex\gql\resolvers\AutocompleteResolver;
use cogapp\searchindex\gql\resolvers\FacetValuesResolver;
use cogapp\searchindex\gql\resolvers\MetaResolver;
use cogapp\searchindex\gql\resolvers\SearchResolver;
use cogapp\searchindex\gql\types\FacetValueType;
use cogapp\searchindex\gql\types\SearchIndexMetaType;
use cogapp\searchindex\gql\types\SearchResultType;
use GraphQL\Type\Definition\Type;

/**
 * Registers the searchIndex top-level GraphQL queries.
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
                    'fields' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Optional list of fields to search within.',
                    ],
                    'sort' => [
                        'type' => Type::string(),
                        'description' => 'Sort as JSON object, e.g. {"postDate":"desc"}.',
                    ],
                    'facets' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Field names to return facet counts for.',
                    ],
                    'filters' => [
                        'type' => Type::string(),
                        'description' => 'Filters as JSON object, e.g. {"category":"News"}.',
                    ],
                    'highlight' => [
                        'type' => Type::boolean(),
                        'defaultValue' => false,
                        'description' => 'Enable hit highlighting.',
                    ],
                    'suggest' => [
                        'type' => Type::boolean(),
                        'defaultValue' => false,
                        'description' => 'Request spelling suggestions (ES/OpenSearch only).',
                    ],
                    'vectorSearch' => [
                        'type' => Type::boolean(),
                        'defaultValue' => false,
                        'description' => 'Generate a Voyage AI embedding from the query for vector search.',
                    ],
                    'voyageModel' => [
                        'type' => Type::string(),
                        'description' => 'Voyage AI model to use (default: voyage-3).',
                    ],
                    'embeddingField' => [
                        'type' => Type::string(),
                        'description' => 'Target embedding field name (auto-detected if omitted).',
                    ],
                    'stats' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Field names to return min/max stats for (numeric fields only).',
                    ],
                    'histogram' => [
                        'type' => Type::string(),
                        'description' => 'Histogram config as JSON object, e.g. {"population":100000} or {"population":{"interval":100000,"min":0,"max":10000000}}.',
                    ],
                    'includeTiming' => [
                        'type' => Type::boolean(),
                        'defaultValue' => false,
                        'description' => 'Include total/overhead timing fields in the response.',
                    ],
                ],
                'resolve' => [SearchResolver::class, 'resolve'],
                'description' => 'Search a configured search index by handle.',
            ],
            'searchIndexAutocomplete' => [
                'type' => SearchResultType::getType(),
                'args' => [
                    'index' => Type::nonNull(Type::string()),
                    'query' => Type::nonNull(Type::string()),
                    'perPage' => [
                        'type' => Type::int(),
                        'defaultValue' => 5,
                        'description' => 'Maximum number of results (default: 5).',
                    ],
                ],
                'resolve' => [AutocompleteResolver::class, 'resolve'],
                'description' => 'Lightweight autocomplete search with minimal payload (role fields only).',
            ],
            'searchIndexFacetValues' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(FacetValueType::getType()))),
                'args' => [
                    'index' => Type::nonNull(Type::string()),
                    'facetField' => Type::nonNull(Type::string()),
                    'query' => [
                        'type' => Type::string(),
                        'defaultValue' => '',
                        'description' => 'Text query to filter facet values.',
                    ],
                    'maxValues' => [
                        'type' => Type::int(),
                        'defaultValue' => 10,
                        'description' => 'Maximum number of facet values to return.',
                    ],
                    'filters' => [
                        'type' => Type::string(),
                        'description' => 'Active filters as JSON object for contextual facet counts.',
                    ],
                ],
                'resolve' => [FacetValuesResolver::class, 'resolve'],
                'description' => 'Search within facet values for a specific field.',
            ],
            'searchIndexMeta' => [
                'type' => SearchIndexMetaType::getType(),
                'args' => [
                    'index' => Type::nonNull(Type::string()),
                ],
                'resolve' => [MetaResolver::class, 'resolve'],
                'description' => 'Get index metadata: roles, facet fields, and sort options.',
            ],
        ];
    }
}
