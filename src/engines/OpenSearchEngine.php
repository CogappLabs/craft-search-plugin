<?php

/**
 * OpenSearch search engine implementation.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Search engine implementation backed by OpenSearch.
 *
 * Connects to an OpenSearch cluster via the official PHP client. OpenSearch is
 * an open-source fork of Elasticsearch; this engine uses an almost identical
 * API surface with OpenSearch-specific client classes.
 *
 * @author cogapp
 * @since 1.0.0
 */
class OpenSearchEngine extends AbstractEngine
{
    /**
     * Cached OpenSearch client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'OpenSearch';
    }

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
     * Return the configured OpenSearch client, creating it on first access.
     *
     * @return Client
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            $settings = SearchIndex::$plugin->getSettings();

            $host = App::parseEnv($settings->opensearchHost);

            $builder = ClientBuilder::create()
                ->setHosts([$host]);

            $username = App::parseEnv($settings->opensearchUsername);
            $password = App::parseEnv($settings->opensearchPassword);

            if (!empty($username) && !empty($password)) {
                $builder->setBasicAuthentication($username, $password);
            }

            $this->_client = $builder->build();
        }

        return $this->_client;
    }

    /**
     * @inheritdoc
     */
    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->_getClient()->indices()->create([
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

        $this->_getClient()->indices()->close(['index' => $indexName]);

        try {
            $this->_getClient()->indices()->putMapping([
                'index' => $indexName,
                'body' => $schema,
            ]);
        } finally {
            $this->_getClient()->indices()->open(['index' => $indexName]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $this->_getClient()->indices()->delete(['index' => $indexName]);
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);
        return $this->_getClient()->indices()->exists(['index' => $indexName]);
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->index([
            'index' => $indexName,
            'id' => (string)$elementId,
            'body' => $document,
        ]);
    }

    /**
     * Bulk-index multiple documents using the OpenSearch _bulk API.
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

        $response = $this->_getClient()->bulk($params);

        if ($response['errors'] ?? false) {
            $errors = [];
            foreach ($response['items'] as $item) {
                $action = $item['index'] ?? $item['create'] ?? [];
                if (isset($action['error'])) {
                    $errors[] = $action['error']['reason'] ?? 'Unknown error';
                }
            }
            Craft::warning('OpenSearch bulk indexing errors: ' . implode('; ', $errors), __METHOD__);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->delete([
                'index' => $indexName,
                'id' => (string)$elementId,
            ]);
        } catch (\OpenSearch\Common\Exceptions\Missing404Exception $e) {
            // Document not found, that's OK
        }
    }

    /**
     * Bulk-delete multiple documents using the OpenSearch _bulk API.
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

        $this->_getClient()->bulk($params);
    }

    /**
     * @inheritdoc
     */
    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->deleteByQuery([
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
    public function search(Index $index, string $query, array $options = []): array
    {
        $indexName = $this->getIndexName($index);

        $fields = $options['fields'] ?? ['*'];
        $from = $options['from'] ?? 0;
        $size = $options['size'] ?? 20;

        $body = [
            'query' => [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $fields,
                    'type' => $options['matchType'] ?? 'best_fields',
                ],
            ],
            'from' => $from,
            'size' => $size,
        ];

        if (isset($options['sort'])) {
            $body['sort'] = $options['sort'];
        }

        if (isset($options['highlight'])) {
            $body['highlight'] = $options['highlight'];
        }

        if (isset($options['aggs'])) {
            $body['aggs'] = $options['aggs'];
        }

        $response = $this->_getClient()->search([
            'index' => $indexName,
            'body' => $body,
        ]);

        $hits = array_map(function($hit) {
            return array_merge(
                $hit['_source'] ?? [],
                [
                    '_id' => $hit['_id'],
                    '_score' => $hit['_score'],
                    '_highlight' => $hit['highlight'] ?? [],
                ]
            );
        }, $response['hits']['hits'] ?? []);

        return [
            'hits' => $hits,
            'totalHits' => $response['hits']['total']['value'] ?? 0,
            'from' => $from,
            'size' => $size,
            'processingTimeMs' => $response['took'] ?? 0,
            'aggregations' => $response['aggregations'] ?? [],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);
        $response = $this->_getClient()->count(['index' => $indexName]);
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

        $response = $this->_getClient()->search($params);
        $hits = $response['hits']['hits'] ?? [];

        while (!empty($hits)) {
            foreach ($hits as $hit) {
                $ids[] = $hit['_id'];
            }

            $lastHit = end($hits);
            $params['body']['search_after'] = $lastHit['sort'];
            $response = $this->_getClient()->search($params);
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
            $osType = $this->mapFieldType($mapping->indexFieldType);

            $fieldDef = ['type' => $osType];

            if ($osType === 'text') {
                $fieldDef['fields'] = [
                    'keyword' => [
                        'type' => 'keyword',
                        'ignore_above' => 256,
                    ],
                ];
            }

            if ($osType === 'date') {
                $fieldDef['format'] = 'epoch_second||epoch_millis||strict_date_optional_time';
            }

            $properties[$fieldName] = $fieldDef;
        }

        return [
            'properties' => $properties,
        ];
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        try {
            return $this->_getClient()->ping();
        } catch (\Exception $e) {
            Craft::warning('OpenSearch connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
