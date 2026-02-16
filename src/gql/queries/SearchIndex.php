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
                    'includeTiming' => [
                        'type' => Type::boolean(),
                        'defaultValue' => false,
                        'description' => 'Include total/overhead timing fields in the response.',
                    ],
                ],
                'resolve' => [SearchResolver::class, 'resolve'],
                'description' => 'Search a configured search index by handle.',
            ],
        ];
    }
}
