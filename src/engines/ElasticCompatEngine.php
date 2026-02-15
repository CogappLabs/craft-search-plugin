<?php

/**
 * Shared base class for Elasticsearch and OpenSearch engines.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use Craft;

/**
 * Abstract base for Elasticsearch-compatible engines (Elasticsearch, OpenSearch).
 *
 * Both engines share an almost identical REST API surface. This base class
 * implements every shared operation; subclasses only need to provide the
 * client, display name, and the handful of methods whose signatures or
 * exception types differ between the two client libraries.
 *
 * @author cogapp
 * @since 1.0.0
 */
abstract class ElasticCompatEngine extends AbstractEngine
{
    /**
     * Return the underlying client (Elasticsearch or OpenSearch).
     *
     * @return mixed The engine-specific client instance.
     */
    abstract protected function getClient(): mixed;

    /**
     * @inheritdoc
     */
    public static function configFields(): array
    {
        return [
            'indexPrefix' => [
                'label' => 'Index Prefix',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Optional prefix for this index name (e.g. "production_"). Supports environment variables.',
            ],
            'host' => [
                'label' => 'Host',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global host URL for this index. Leave blank to use the global setting.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->getClient()->indices()->create([
            'index' => $indexName,
            'body' => [
                'mappings' => $schema,
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->getClient()->indices()->putMapping([
            'index' => $indexName,
            'body' => $schema,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function supportsAtomicSwap(): bool
    {
        return true;
    }

    /**
     * Return the swap handle, alternating between `_swap_a` and `_swap_b`.
     *
     * Checks which backing index the production alias currently points to
     * and returns the other suffix so the swap can alternate.
     *
     * @inheritdoc
     */
    public function buildSwapHandle(Index $index): string
    {
        $aliasName = $this->getIndexName($index);
        $currentTarget = $this->_getAliasTarget($aliasName);

        if ($currentTarget !== null && str_ends_with($currentTarget, '_swap_a')) {
            return $index->handle . '_swap_b';
        }

        return $index->handle . '_swap_a';
    }

    /**
     * Atomically swap the production alias to point to the new backing index.
     *
     * On first swap (migrating from a direct index to aliases), the direct index
     * is deleted and replaced with an alias. Subsequent swaps are fully atomic.
     *
     * @inheritdoc
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        $aliasName = $this->getIndexName($index);
        $newTarget = $this->getIndexName($swapIndex);
        $currentTarget = $this->_getAliasTarget($aliasName);

        if ($currentTarget !== null) {
            // Atomic alias swap: remove old, add new in a single request
            $this->getClient()->indices()->updateAliases([
                'body' => [
                    'actions' => [
                        ['remove' => ['index' => $currentTarget, 'alias' => $aliasName]],
                        ['add' => ['index' => $newTarget, 'alias' => $aliasName]],
                    ],
                ],
            ]);
            // Clean up old backing index
            $this->getClient()->indices()->delete(['index' => $currentTarget]);
        } else {
            // First swap: migrate from direct index to alias-based
            // Delete the direct index (brief gap is unavoidable on first swap)
            if ($this->_directIndexExists($aliasName)) {
                $this->getClient()->indices()->delete(['index' => $aliasName]);
            }
            // Create alias pointing to the new backing index
            $this->getClient()->indices()->putAlias([
                'index' => $newTarget,
                'name' => $aliasName,
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $target = $this->_getAliasTarget($indexName);

        if ($target !== null) {
            // Alias-based: delete the alias and its backing index
            $this->getClient()->indices()->deleteAlias(['index' => $target, 'name' => $indexName]);
            $this->getClient()->indices()->delete(['index' => $target]);
        } else {
            // Direct index
            $this->getClient()->indices()->delete(['index' => $indexName]);
        }
    }

    /**
     * @inheritdoc
     */
    public function getIndexSchema(Index $index): array
    {
        $indexName = $this->getIndexName($index);

        try {
            $response = $this->getClient()->indices()->getMapping(['index' => $indexName]);
            return $response[$indexName] ?? $response;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @inheritdoc
     */
    public function getSchemaFields(Index $index): array
    {
        $schema = $this->getIndexSchema($index);

        if (isset($schema['error'])) {
            return $this->handleSchemaError($index);
        }

        return $this->parseSchemaFields($schema);
    }

    /**
     * @inheritdoc
     */
    protected function parseSchemaFields(array $schema): array
    {
        // Schema structure: ['mappings']['properties'] => ['field' => ['type' => '...']]
        $properties = $schema['mappings']['properties'] ?? $schema['properties'] ?? [];
        $fields = [];

        foreach ($properties as $name => $definition) {
            $nativeType = $definition['type'] ?? 'text';
            $fields[] = ['name' => $name, 'type' => $this->reverseMapFieldType($nativeType)];
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    protected function handleSchemaError(Index $index): array
    {
        return $this->inferSchemaFieldsFromSampleDocuments($index);
    }

    /**
     * @inheritdoc
     */
    protected function sampleDocumentsForSchemaInference(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $response = $this->getClient()->search([
            'index' => $indexName,
            'body' => ['size' => 5, 'query' => ['match_all' => (object)[]]],
        ]);

        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            if (is_array($hit['_source'] ?? null)) {
                $documents[] = $hit['_source'];
            }
        }

        return $documents;
    }

    /**
     * Infer a plugin field type from a field name and sample value.
     *
     * Uses both the value's PHP type and name-based heuristics (e.g. fields
     * ending in `_at` or containing `date` are likely dates even when stored
     * as epoch integers).
     *
     * @param string $name  The field name.
     * @param mixed  $value A sample value from a document.
     * @return string A FieldMapping::TYPE_* constant.
     */
    protected function inferFieldType(string $name, mixed $value): string
    {
        // Unambiguous value types take priority over name heuristics
        if (is_bool($value)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        // Name-based overrides (for integer timestamps, ambiguous strings, etc.)
        $lower = strtolower($name);

        // Date heuristics: epoch timestamps are integers but semantically dates
        if (preg_match('/(_at|_date|_time|timestamp)$/', $lower)
            || preg_match('/^(created|updated|deleted|modified|date)_/', $lower)
        ) {
            return FieldMapping::TYPE_DATE;
        }

        // Boolean heuristics: is_*, has_*, *_enabled, *_active
        if (preg_match('/^(is_|has_)/', $lower) || preg_match('/_(enabled|active|visible|archived)$/', $lower)) {
            return FieldMapping::TYPE_BOOLEAN;
        }

        // Value-based inference
        if (is_int($value)) {
            return FieldMapping::TYPE_INTEGER;
        }
        if (is_float($value)) {
            return FieldMapping::TYPE_FLOAT;
        }
        if (is_array($value)) {
            if (array_is_list($value) && !empty($value)) {
                $first = $value[0];
                // List of strings → facet (e.g. ["red","green","blue"])
                if (is_string($first)) {
                    return FieldMapping::TYPE_FACET;
                }
                // Large numeric array → vector embedding
                if ((is_float($first) || is_int($first)) && count($value) > 50) {
                    return FieldMapping::TYPE_EMBEDDING;
                }
            }
            return FieldMapping::TYPE_OBJECT;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return FieldMapping::TYPE_DATE;
        }

        return FieldMapping::TYPE_TEXT;
    }

    /**
     * Map an Elasticsearch/OpenSearch native type back to a plugin field type constant.
     *
     * @param string $nativeType The engine-native type string.
     * @return string A FieldMapping::TYPE_* constant.
     */
    protected function reverseMapFieldType(string $nativeType): string
    {
        return match ($nativeType) {
            'text' => FieldMapping::TYPE_TEXT,
            'keyword' => FieldMapping::TYPE_KEYWORD,
            'integer', 'long', 'short', 'byte' => FieldMapping::TYPE_INTEGER,
            'float', 'double', 'half_float', 'scaled_float' => FieldMapping::TYPE_FLOAT,
            'boolean' => FieldMapping::TYPE_BOOLEAN,
            'date', 'date_nanos' => FieldMapping::TYPE_DATE,
            'geo_point' => FieldMapping::TYPE_GEO_POINT,
            'object', 'nested' => FieldMapping::TYPE_OBJECT,
            'knn_vector', 'dense_vector' => FieldMapping::TYPE_EMBEDDING,
            default => FieldMapping::TYPE_TEXT,
        };
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_ISO8601);

        $this->getClient()->index([
            'index' => $indexName,
            'id' => (string)$elementId,
            'body' => $document,
        ]);
    }

    /**
     * Bulk-index multiple documents using the _bulk API.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $indexName = $this->getIndexName($index);
        $params = ['body' => []];

        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? null;
            if (!$elementId) {
                continue;
            }
            $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_ISO8601);

            $params['body'][] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => (string)$elementId,
                ],
            ];
            $params['body'][] = $document;
        }

        $response = $this->getClient()->bulk($params);

        if ($response['errors'] ?? false) {
            $errors = [];
            foreach ($response['items'] as $item) {
                $action = $item['index'] ?? $item['create'] ?? [];
                if (isset($action['error'])) {
                    $errors[] = $action['error']['reason'] ?? 'Unknown error';
                }
            }
            Craft::warning(static::displayName() . ' bulk indexing errors: ' . implode('; ', $errors), __METHOD__);
        }
    }

    /**
     * Bulk-delete multiple documents using the _bulk API.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        if (empty($elementIds)) {
            return;
        }

        $indexName = $this->getIndexName($index);
        $params = ['body' => []];

        foreach ($elementIds as $elementId) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $indexName,
                    '_id' => (string)$elementId,
                ],
            ];
        }

        $this->getClient()->bulk($params);
    }

    /**
     * @inheritdoc
     */
    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->getClient()->deleteByQuery([
            'index' => $indexName,
            'body' => [
                'query' => [
                    'match_all' => (object)[],
                ],
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        $indexName = $this->getIndexName($index);

        try {
            $response = $this->getClient()->get([
                'index' => $indexName,
                'id' => $documentId,
            ]);
            return $response['_source'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        $indexName = $this->getIndexName($index);

        [$facets, $filters, $options] = $this->extractFacetParams($options);
        [$sort, $options] = $this->extractSortParams($options);
        [$attributesToRetrieve, $options] = $this->extractAttributesToRetrieve($options);
        [$highlight, $options] = $this->extractHighlightParams($options);
        [$suggest, $options] = $this->extractSuggestParams($options);
        [$embedding, $embeddingField, $options] = $this->extractEmbeddingParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        $fields = $remaining['fields'] ?? ['*'];

        // Engine-native from/size take precedence over unified page/perPage.
        $from = $remaining['from'] ?? $this->offsetFromPage($page, $perPage);
        $size = $remaining['size'] ?? $perPage;

        // Build the text query component
        if ($query === '') {
            $textQuery = null;
        } else {
            $textQuery = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $fields,
                    'type' => $remaining['matchType'] ?? 'bool_prefix',
                ],
            ];
        }

        // Build the KNN vector query component
        $knnQuery = null;
        if ($embedding !== null && $embeddingField !== null) {
            $knnQuery = [
                'knn' => [
                    $embeddingField => [
                        'vector' => $embedding,
                        'k' => $size,
                    ],
                ],
            ];
        }

        // Combine text and KNN into the final match query
        if ($knnQuery !== null && $textQuery !== null) {
            // Hybrid: text + vector combined via bool/should
            $matchQuery = [
                'bool' => [
                    'should' => [
                        $textQuery,
                        $knnQuery,
                    ],
                ],
            ];
        } elseif ($knnQuery !== null) {
            // Vector-only search
            $matchQuery = $knnQuery;
        } elseif ($textQuery !== null) {
            // Text-only search
            $matchQuery = $textQuery;
        } else {
            // No query and no embedding → match_all (browse mode)
            $matchQuery = ['match_all' => (object)[]];
        }

        // If unified filters are provided, wrap in a bool query with filter clauses
        if (!empty($filters)) {
            $filterClauses = $this->buildNativeFilterParams($filters, $index);
            $body = [
                'query' => [
                    'bool' => [
                        'must' => [$matchQuery],
                        'filter' => $filterClauses,
                    ],
                ],
            ];
        } else {
            $body = ['query' => $matchQuery];
        }

        $body['from'] = $from;
        $body['size'] = $size;

        // Unified sort → ES DSL: ['field' => 'asc'] → [['field' => ['order' => 'asc']]]
        if (!empty($sort)) {
            $body['sort'] = $this->buildNativeSortParams($sort);
        }

        // Unified attributesToRetrieve → ES _source filter
        if ($attributesToRetrieve !== null) {
            $body['_source'] = $attributesToRetrieve;
        }

        // Unified highlight → ES highlight DSL
        if ($highlight !== null && !isset($remaining['highlight'])) {
            if ($highlight === true) {
                // Highlight all fields with a wildcard
                $body['highlight'] = ['fields' => ['*' => new \stdClass()]];
            } elseif (is_array($highlight)) {
                $highlightFields = [];
                foreach ($highlight as $field) {
                    $highlightFields[$field] = new \stdClass();
                }
                $body['highlight'] = ['fields' => $highlightFields];
            }
        } elseif (isset($remaining['highlight'])) {
            // Engine-native highlight — pass through
            $body['highlight'] = $remaining['highlight'];
        }

        // Unified suggest → ES phrase suggester
        // Requires an explicit text field — the _all meta-field was removed in ES 6.0.
        if ($suggest && $query !== '' && $fields[0] !== '*') {
            $body['suggest'] = [
                'text' => $query,
                'phrase_suggestion' => [
                    'phrase' => [
                        'field' => $fields[0],
                        'size' => 3,
                        'gram_size' => 3,
                        'direct_generator' => [[
                            'field' => $fields[0],
                            'suggest_mode' => 'missing',
                        ]],
                    ],
                ],
            ];
        }

        // Engine-native aggs take precedence over unified facets
        if (isset($remaining['aggs'])) {
            $body['aggs'] = $remaining['aggs'];
        } elseif (!empty($facets)) {
            $fieldTypeMap = $this->buildFieldTypeMap($index);
            $body['aggs'] = [];
            foreach ($facets as $field) {
                // Only text fields need the .keyword sub-field for term aggregations;
                // keyword, facet, integer, date, etc. use the base field name.
                $aggField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
                $body['aggs'][$field] = [
                    'terms' => ['field' => $aggField, 'size' => 100],
                ];
            }
        }

        $response = $this->getClient()->search([
            'index' => $indexName,
            'body' => $body,
        ]);

        // Flatten _source, preserve _id/_score, normalise highlights.
        $rawHits = array_map([$this, 'normaliseRawHit'], $response['hits']['hits'] ?? []);
        $hits = $this->normaliseHits($rawHits, '_id', '_score', null);

        $totalHits = $response['hits']['total']['value'] ?? 0;

        // Normalise aggregations → unified facet shape
        $normalisedFacets = $this->normaliseRawFacets((array)$response);

        // Extract spelling suggestions from phrase suggester response
        $suggestions = [];
        foreach ($response['suggest']['phrase_suggestion'] ?? [] as $entry) {
            foreach ($entry['options'] ?? [] as $option) {
                if (isset($option['text']) && $option['text'] !== $query) {
                    $suggestions[] = $option['text'];
                }
            }
        }

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $size > 0 ? (int)floor($from / $size) + 1 : 1,
            perPage: $size,
            totalPages: $this->computeTotalPages($totalHits, $size),
            processingTimeMs: $response['took'] ?? 0,
            facets: $normalisedFacets,
            raw: (array)$response,
            suggestions: $suggestions,
        );
    }

    /**
     * @inheritdoc
     */
    public function multiSearch(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        $body = [];

        foreach ($queries as $query) {
            $index = $query['index'];
            $indexName = $this->getIndexName($index);
            $options = $query['options'] ?? [];

            [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

            $fields = $remaining['fields'] ?? ['*'];
            $from = $remaining['from'] ?? $this->offsetFromPage($page, $perPage);
            $size = $remaining['size'] ?? $perPage;

            // Header line
            $body[] = ['index' => $indexName];

            // Body line — empty query uses match_all for browse mode
            if ($query['query'] === '') {
                $queryClause = ['match_all' => (object)[]];
            } else {
                $queryClause = [
                    'multi_match' => [
                        'query' => $query['query'],
                        'fields' => $fields,
                        'type' => $remaining['matchType'] ?? 'bool_prefix',
                    ],
                ];
            }

            $searchBody = [
                'query' => $queryClause,
                'from' => $from,
                'size' => $size,
            ];

            if (isset($remaining['sort'])) {
                $searchBody['sort'] = $remaining['sort'];
            }
            if (isset($remaining['highlight'])) {
                $searchBody['highlight'] = $remaining['highlight'];
            }

            $body[] = $searchBody;
        }

        $response = $this->getClient()->msearch(['body' => $body]);

        $results = [];
        foreach ($response['responses'] ?? [] as $i => $resp) {
            $options = $queries[$i]['options'] ?? [];
            $perPage = (int)($options['perPage'] ?? 20);

            $rawHits = array_map([$this, 'normaliseRawHit'], $resp['hits']['hits'] ?? []);
            $hits = $this->normaliseHits($rawHits, '_id', '_score', null);
            $totalHits = $resp['hits']['total']['value'] ?? 0;
            $from = (int)($remaining['from'] ?? $this->offsetFromPage($page, $perPage));
            $size = (int)($remaining['size'] ?? $perPage);

            // Normalise aggregations → unified facet shape
            $normalisedFacets = $this->normaliseRawFacets((array)$resp);

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: $size > 0 ? (int)floor($from / $size) + 1 : 1,
                perPage: $size,
                totalPages: $this->computeTotalPages($totalHits, $size),
                processingTimeMs: $resp['took'] ?? 0,
                facets: $normalisedFacets,
                raw: (array)$resp,
            );
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $response = $this->getClient()->count(['index' => $indexName]);

        return $response['count'] ?? 0;
    }

    /**
     * Retrieve all document IDs using search_after pagination.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings.
     */
    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];

        // Use search_after with _doc sort for efficient pagination
        $params = [
            'index' => $indexName,
            'body' => [
                'size' => 1000,
                'query' => ['match_all' => (object)[]],
                'sort' => ['_doc'],
                '_source' => false,
            ],
        ];

        $response = $this->getClient()->search($params);
        $hits = $response['hits']['hits'] ?? [];

        while (!empty($hits)) {
            foreach ($hits as $hit) {
                $ids[] = $hit['_id'];
            }

            $lastHit = end($hits);
            $params['body']['search_after'] = $lastHit['sort'];
            $response = $this->getClient()->search($params);
            $hits = $response['hits']['hits'] ?? [];
        }

        return $ids;
    }

    /**
     * @inheritdoc
     */
    public function mapFieldType(string $indexFieldType): mixed
    {
        return match ($indexFieldType) {
            FieldMapping::TYPE_TEXT => 'text',
            FieldMapping::TYPE_KEYWORD => 'keyword',
            FieldMapping::TYPE_INTEGER => 'integer',
            FieldMapping::TYPE_FLOAT => 'float',
            FieldMapping::TYPE_BOOLEAN => 'boolean',
            FieldMapping::TYPE_DATE => 'date',
            FieldMapping::TYPE_GEO_POINT => 'geo_point',
            FieldMapping::TYPE_FACET => 'keyword',
            FieldMapping::TYPE_OBJECT => 'object',
            FieldMapping::TYPE_EMBEDDING => 'knn_vector',
            default => 'text',
        };
    }

    /**
     * @inheritdoc
     */
    public function buildSchema(array $fieldMappings): array
    {
        $properties = [];

        foreach ($fieldMappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            $fieldName = $mapping->indexFieldName;
            $type = $this->mapFieldType($mapping->indexFieldType);

            $fieldDef = ['type' => $type];

            // Text fields with keyword sub-field for exact matching
            if ($type === 'text') {
                $fieldDef['fields'] = [
                    'keyword' => [
                        'type' => 'keyword',
                        'ignore_above' => 256,
                    ],
                ];
            }

            // Date fields with format
            if ($type === 'date') {
                $fieldDef['format'] = 'epoch_second||epoch_millis||strict_date_optional_time';
            }

            // Embedding fields require a dimension parameter
            if ($type === 'knn_vector' && $mapping->indexFieldType === FieldMapping::TYPE_EMBEDDING) {
                $dimension = $mapping->resolverConfig['dimension'] ?? 1024;
                $fieldDef['dimension'] = (int)$dimension;
            }

            $properties[$fieldName] = $fieldDef;
        }

        return [
            'properties' => $properties,
        ];
    }

    /**
     * Convert unified sort to ES DSL: `['field' => 'asc']` → `[['field' => ['order' => 'asc']]]`.
     *
     * @inheritdoc
     */
    protected function buildNativeSortParams(array $sort): mixed
    {
        if (empty($sort)) {
            return [];
        }

        if (!$this->isUnifiedSort($sort)) {
            return $sort;
        }

        $result = [];
        foreach ($sort as $field => $direction) {
            $result[] = [$field => ['order' => $direction]];
        }
        return $result;
    }

    /**
     * Convert unified filters to ES bool/filter clauses.
     *
     * @inheritdoc
     */
    protected function buildNativeFilterParams(array $filters, Index $index): mixed
    {
        if (empty($filters)) {
            return [];
        }

        $fieldTypeMap = $this->buildFieldTypeMap($index);
        $filterClauses = [];

        foreach ($filters as $field => $value) {
            // Only text fields need the .keyword sub-field for exact matching;
            // keyword, integer, date, boolean, etc. use the base field name.
            $filterField = ($fieldTypeMap[$field] ?? '') === 'text' ? $field . '.keyword' : $field;
            if (is_array($value)) {
                $filterClauses[] = ['terms' => [$filterField => $value]];
            } else {
                $filterClauses[] = ['term' => [$filterField => $value]];
            }
        }

        return $filterClauses;
    }

    /**
     * Flatten an ES/OpenSearch hit: merge _source with _id/_score, normalise highlights.
     *
     * @inheritdoc
     */
    protected function normaliseRawHit(array $hit): array
    {
        $doc = array_merge(
            $hit['_source'] ?? [],
            [
                '_id' => $hit['_id'],
                '_score' => $hit['_score'],
            ]
        );
        $doc['_highlights'] = $this->normaliseHighlightData($hit['highlight'] ?? []);
        return $doc;
    }

    /**
     * Normalise ES/OpenSearch aggregation buckets into unified facet shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawFacets(array $response): array
    {
        $normalised = [];
        foreach ($response['aggregations'] ?? [] as $field => $agg) {
            if (isset($agg['buckets'])) {
                $normalised[$field] = array_map(fn($bucket) => [
                    'value' => (string)$bucket['key'],
                    'count' => (int)$bucket['doc_count'],
                ], $agg['buckets']);
            }
        }
        return $normalised;
    }

    /**
     * Build a map of index field names to their engine-native types.
     *
     * Used at search time to decide whether a field needs a `.keyword`
     * sub-field for exact matching (only text fields have one).
     *
     * @param Index $index
     * @return array<string, string> Map of fieldName => native type string.
     */
    protected function buildFieldTypeMap(Index $index): array
    {
        $map = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping instanceof FieldMapping && $mapping->enabled) {
                $map[$mapping->indexFieldName] = $this->mapFieldType($mapping->indexFieldType);
            }
        }
        return $map;
    }

    // -- Alias helpers --------------------------------------------------------

    /**
     * Check whether an alias exists.
     *
     * Subclasses override this to handle client-specific return types
     * (Elasticsearch returns a Response requiring `->asBool()`).
     *
     * @param string $aliasName The alias name to check.
     * @return bool
     */
    protected function _aliasExists(string $aliasName): bool
    {
        try {
            return (bool)$this->getClient()->indices()->existsAlias(['name' => $aliasName]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the backing index name that an alias points to.
     *
     * @param string $aliasName The alias name.
     * @return string|null The backing index name, or null if no alias exists.
     */
    protected function _getAliasTarget(string $aliasName): ?string
    {
        try {
            $response = $this->_getAliasResponse($aliasName);

            // Response shape: { 'backing_index_name': { 'aliases': { 'alias_name': {} } } }
            foreach ($response as $indexName => $data) {
                if (isset($data['aliases'][$aliasName])) {
                    return $indexName;
                }
            }
        } catch (\Throwable $e) {
            // Alias doesn't exist
        }

        return null;
    }

    /**
     * Get the raw alias response from the engine.
     *
     * Subclasses override this to handle client-specific return types
     * (Elasticsearch returns a Response requiring `->asArray()`).
     *
     * @param string $aliasName The alias name.
     * @return array Raw response data.
     */
    protected function _getAliasResponse(string $aliasName): array
    {
        return (array)$this->getClient()->indices()->getAlias(['name' => $aliasName]);
    }

    /**
     * Check whether a direct index (not an alias) exists.
     *
     * Subclasses override this to handle client-specific return types.
     *
     * @param string $indexName The index name to check.
     * @return bool
     */
    protected function _directIndexExists(string $indexName): bool
    {
        try {
            return (bool)$this->getClient()->indices()->exists(['index' => $indexName]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
