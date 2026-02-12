<?php

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class TypesenseEngine extends AbstractEngine
{
    private ?Client $_client = null;

    public static function displayName(): string
    {
        return 'Typesense';
    }

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
     * Returns the configured Typesense Client instance.
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            $settings = SearchIndex::$plugin->getSettings();

            $host = App::parseEnv($settings->typesenseHost);
            $port = App::parseEnv($settings->typesensePort);
            $protocol = App::parseEnv($settings->typesenseProtocol);
            $apiKey = App::parseEnv($settings->typesenseApiKey);

            $this->_client = new Client([
                'api_key' => $apiKey,
                'nodes' => [
                    [
                        'host' => $host,
                        'port' => $port,
                        'protocol' => $protocol,
                    ],
                ],
                'connection_timeout_seconds' => 5,
            ]);
        }

        return $this->_client;
    }

    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $fields = $this->buildSchema($index->getFieldMappings());

        $schema = [
            'name' => $indexName,
            'fields' => $fields,
        ];

        $this->_getClient()->collections->create($schema);
    }

    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $fields = $this->buildSchema($index->getFieldMappings());

        $schema = [
            'fields' => $fields,
        ];

        $this->_getClient()->collections[$indexName]->update($schema);
    }

    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->collections[$indexName]->delete();
    }

    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->collections[$indexName]->retrieve();
            return true;
        } catch (ObjectNotFound $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);

        // Typesense requires 'id' as a string
        $document['id'] = (string)$elementId;

        $this->_getClient()->collections[$indexName]->documents->upsert($document);
    }

    public function indexDocuments(Index $index, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $indexName = $this->getIndexName($index);

        // Ensure each document has a string 'id'
        $prepared = [];
        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? null;
            if (!$elementId) {
                continue;
            }
            $document['id'] = (string)$elementId;
            $prepared[] = $document;
        }

        if (!empty($prepared)) {
            $results = $this->_getClient()->collections[$indexName]->documents->import($prepared, ['action' => 'upsert']);

            // Check for errors in the import results
            foreach ($results as $result) {
                if (isset($result['success']) && $result['success'] === false) {
                    Craft::warning('Typesense import error: ' . ($result['error'] ?? 'Unknown error'), __METHOD__);
                }
            }
        }
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->collections[$indexName]->documents[(string)$elementId]->delete();
        } catch (ObjectNotFound $e) {
            // Ignore: document not found
        }
    }

    public function deleteDocuments(Index $index, array $elementIds): void
    {
        if (empty($elementIds)) {
            return;
        }

        $indexName = $this->getIndexName($index);
        $ids = array_map('strval', $elementIds);
        $filterBy = 'id:[' . implode(',', $ids) . ']';

        $this->_getClient()->collections[$indexName]->documents->delete(['filter_by' => $filterBy]);
    }

    public function flushIndex(Index $index): void
    {
        // Drop and recreate the collection to remove all documents
        $indexName = $this->getIndexName($index);

        try {
            $collectionInfo = $this->_getClient()->collections[$indexName]->retrieve();
            $this->_getClient()->collections[$indexName]->delete();

            // Recreate with the same schema
            $schema = [
                'name' => $collectionInfo['name'],
                'fields' => $collectionInfo['fields'],
            ];

            // Remove auto-generated field metadata that cannot be passed back on create
            foreach ($schema['fields'] as &$field) {
                unset($field['indexed']);
            }
            unset($field);

            $this->_getClient()->collections->create($schema);
        } catch (\Exception $e) {
            Craft::warning('Typesense flush failed: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    public function search(Index $index, string $query, array $options = []): array
    {
        $indexName = $this->getIndexName($index);

        // Build search parameters
        $searchParams = array_merge([
            'q' => $query,
            'query_by' => $options['query_by'] ?? $this->_getSearchableFieldNames($index),
        ], $options);

        // Remove our custom keys that aren't Typesense params
        unset($searchParams['query_by_fields']);

        $response = $this->_getClient()->collections[$indexName]->documents->search($searchParams);

        $hits = array_map(function($hit) {
            $document = $hit['document'] ?? [];
            $document['_score'] = $hit['text_match'] ?? 0;
            $document['_highlight'] = $hit['highlight'] ?? [];
            $document['_highlights'] = $hit['highlights'] ?? [];
            return $document;
        }, $response['hits'] ?? []);

        return [
            'hits' => $hits,
            'totalHits' => $response['found'] ?? 0,
            'page' => $response['page'] ?? 1,
            'perPage' => $options['per_page'] ?? 10,
            'processingTimeMs' => $response['search_time_ms'] ?? 0,
            'facetCounts' => $response['facet_counts'] ?? [],
        ];
    }

    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $collection = $this->_getClient()->collections[$indexName]->retrieve();

        return $collection['num_documents'] ?? 0;
    }

    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];

        // Typesense export returns JSONL (one JSON object per line)
        $export = $this->_getClient()->collections[$indexName]->documents->export(['include_fields' => 'id']);
        $lines = explode("\n", $export);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $doc = json_decode($line, true);
            if (isset($doc['id'])) {
                $ids[] = $doc['id'];
            }
        }

        return $ids;
    }

    public function mapFieldType(string $indexFieldType): mixed
    {
        return match ($indexFieldType) {
            FieldMapping::TYPE_TEXT => ['type' => 'string', 'facet' => false],
            FieldMapping::TYPE_KEYWORD => ['type' => 'string', 'facet' => true],
            FieldMapping::TYPE_INTEGER => ['type' => 'int32', 'facet' => false],
            FieldMapping::TYPE_FLOAT => ['type' => 'float', 'facet' => false],
            FieldMapping::TYPE_BOOLEAN => ['type' => 'bool', 'facet' => false],
            FieldMapping::TYPE_DATE => ['type' => 'int64', 'facet' => false],
            FieldMapping::TYPE_GEO_POINT => ['type' => 'geopoint', 'facet' => false],
            FieldMapping::TYPE_FACET => ['type' => 'string', 'facet' => true],
            FieldMapping::TYPE_OBJECT => ['type' => 'object', 'facet' => false],
            default => ['type' => 'string', 'facet' => false],
        };
    }

    public function buildSchema(array $fieldMappings): array
    {
        $fields = [];

        foreach ($fieldMappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            $fieldName = $mapping->indexFieldName;
            $typesenseType = $this->mapFieldType($mapping->indexFieldType);

            $field = [
                'name' => $fieldName,
                'type' => $typesenseType['type'],
                'facet' => $typesenseType['facet'],
                'optional' => true,
            ];

            // Enable sorting for numeric and date types
            if (in_array($typesenseType['type'], ['int32', 'int64', 'float'], true)) {
                $field['sort'] = true;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    public function testConnection(): bool
    {
        try {
            $health = $this->_getClient()->health->retrieve();
            return ($health['ok'] ?? false) === true;
        } catch (\Exception $e) {
            Craft::warning('Typesense connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Returns a comma-separated list of searchable field names for the given index.
     */
    private function _getSearchableFieldNames(Index $index): string
    {
        $fieldNames = [];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            // Only include string-type fields in query_by
            $typesenseType = $this->mapFieldType($mapping->indexFieldType);
            if ($typesenseType['type'] === 'string') {
                $fieldNames[] = $mapping->indexFieldName;
            }
        }

        return !empty($fieldNames) ? implode(',', $fieldNames) : '*';
    }
}
