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

        $this->getClient()->indices()->close(['index' => $indexName]);

        try {
            $this->getClient()->indices()->putMapping([
                'index' => $indexName,
                'body' => $schema,
            ]);
        } finally {
            $this->getClient()->indices()->open(['index' => $indexName]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $this->getClient()->indices()->delete(['index' => $indexName]);
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
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);

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

        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        $fields = $remaining['fields'] ?? ['*'];

        // Engine-native from/size take precedence over unified page/perPage.
        $from = $remaining['from'] ?? ($page - 1) * $perPage;
        $size = $remaining['size'] ?? $perPage;

        $body = [
            'query' => [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $fields,
                    'type' => $remaining['matchType'] ?? 'best_fields',
                ],
            ],
            'from' => $from,
            'size' => $size,
        ];

        if (isset($remaining['sort'])) {
            $body['sort'] = $remaining['sort'];
        }

        if (isset($remaining['highlight'])) {
            $body['highlight'] = $remaining['highlight'];
        }

        if (isset($remaining['aggs'])) {
            $body['aggs'] = $remaining['aggs'];
        }

        $response = $this->getClient()->search([
            'index' => $indexName,
            'body' => $body,
        ]);

        // Flatten _source, preserve _id/_score, normalise highlights.
        $rawHits = array_map(function($hit) {
            return array_merge(
                $hit['_source'] ?? [],
                [
                    '_id' => $hit['_id'],
                    '_score' => $hit['_score'],
                    '_highlight' => $hit['highlight'] ?? [],
                ]
            );
        }, $response['hits']['hits'] ?? []);

        $hits = $this->normaliseHits($rawHits, '_id', '_score', '_highlight');

        $totalHits = $response['hits']['total']['value'] ?? 0;

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $size > 0 ? (int)floor($from / $size) + 1 : 1,
            perPage: $size,
            totalPages: $this->computeTotalPages($totalHits, $size),
            processingTimeMs: $response['took'] ?? 0,
            facets: $response['aggregations'] ?? [],
            raw: (array)$response,
        );
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

            $properties[$fieldName] = $fieldDef;
        }

        return [
            'properties' => $properties,
        ];
    }
}
