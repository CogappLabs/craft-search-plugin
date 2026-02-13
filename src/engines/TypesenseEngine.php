<?php

/**
 * Typesense search engine implementation.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Search engine implementation backed by Typesense.
 *
 * Connects to a Typesense server via the official PHP client. Typesense uses
 * collections instead of indices, and requires an explicit schema with typed
 * fields. This engine maps plugin field types to Typesense field definitions.
 *
 * @author cogapp
 * @since 1.0.0
 */
class TypesenseEngine extends AbstractEngine
{
    /**
     * Cached Typesense client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Typesense';
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
     * Return the configured Typesense client, creating it on first access.
     *
     * @return Client
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            if (!class_exists(Client::class)) {
                throw new \RuntimeException('The Typesense engine requires the "typesense/typesense-php" package. Install it with: composer require typesense/typesense-php');
            }

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

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $desiredFields = $this->buildSchema($index->getFieldMappings());

        // Retrieve the current schema to diff against
        $collection = $this->_getClient()->collections[$indexName]->retrieve();
        $existingByName = [];
        foreach ($collection['fields'] ?? [] as $field) {
            $existingByName[$field['name']] = $field;
        }

        $updateFields = [];

        foreach ($desiredFields as $field) {
            $name = $field['name'];

            if (!isset($existingByName[$name])) {
                // New field — add it
                $updateFields[] = $field;
            } else {
                // Existing field — only drop+re-add if the definition changed
                $existing = $existingByName[$name];
                $changed = ($existing['type'] ?? '') !== ($field['type'] ?? '')
                    || ($existing['facet'] ?? false) !== ($field['facet'] ?? false)
                    || ($existing['optional'] ?? false) !== ($field['optional'] ?? true);

                if ($changed) {
                    $updateFields[] = ['name' => $name, 'drop' => true];
                    $updateFields[] = $field;
                }
            }
        }

        if (!empty($updateFields)) {
            $this->_getClient()->collections[$indexName]->update([
                'fields' => $updateFields,
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->collections[$indexName]->delete();
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $schemaMap = $this->_buildSchemaMap($index);

        // Typesense requires 'id' as a string
        $document['id'] = (string)$elementId;
        $document = $this->_coerceDocumentValues($document, $schemaMap);

        $this->_getClient()->collections[$indexName]->documents->upsert($document);
    }

    /**
     * Batch-import multiple documents using the Typesense import API with upsert.
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
        $schemaMap = $this->_buildSchemaMap($index);

        // Ensure each document has a string 'id' and coerce values for Typesense
        $prepared = [];
        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? null;
            if (!$elementId) {
                continue;
            }
            $document['id'] = (string)$elementId;
            $prepared[] = $this->_coerceDocumentValues($document, $schemaMap);
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

    /**
     * @inheritdoc
     */
    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->collections[$indexName]->documents[(string)$elementId]->delete();
        } catch (ObjectNotFound $e) {
            // Ignore: document not found
        }
    }

    /**
     * Batch-delete documents using a Typesense filter_by query.
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
        $ids = array_map('strval', $elementIds);
        $filterBy = 'id:[' . implode(',', $ids) . ']';

        $this->_getClient()->collections[$indexName]->documents->delete(['filter_by' => $filterBy]);
    }

    /**
     * Flush the index by dropping and recreating the collection with the same schema.
     *
     * @param Index $index The index to flush.
     * @return void
     */
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

    /**
     * @inheritdoc
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        $indexName = $this->getIndexName($index);

        try {
            return $this->_getClient()->collections[$indexName]->documents[$documentId]->retrieve();
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

        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 10);

        // Engine-native page/per_page take precedence over unified values.
        if (!isset($remaining['page'])) {
            $remaining['page'] = $page; // Typesense is already 1-based
        }
        if (!isset($remaining['per_page'])) {
            $remaining['per_page'] = $perPage;
        }

        // Build search parameters
        $searchParams = array_merge([
            'q' => $query,
            'query_by' => $remaining['query_by'] ?? $this->_getSearchableFieldNames($index),
        ], $remaining);

        // Remove our custom keys that aren't Typesense params
        unset($searchParams['query_by_fields']);

        $response = $this->_getClient()->collections[$indexName]->documents->search($searchParams);

        // Flatten document, preserve score + highlight metadata.
        $rawHits = array_map(function($hit) {
            $document = $hit['document'] ?? [];
            $document['_score'] = $hit['text_match'] ?? null;
            $document['_highlights'] = $hit['highlights'] ?? [];
            $document['_highlight'] = $hit['highlight'] ?? [];
            // Map document.id → objectID
            if (isset($document['id']) && !isset($document['objectID'])) {
                $document['objectID'] = (string)$document['id'];
            }
            return $document;
        }, $response['hits'] ?? []);

        $hits = $this->normaliseHits($rawHits, 'id', '_score', '_highlights');

        $totalHits = $response['found'] ?? 0;
        $actualPerPage = $remaining['per_page'];

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $response['page'] ?? $page,
            perPage: $actualPerPage,
            totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
            processingTimeMs: $response['search_time_ms'] ?? 0,
            facets: $response['facet_counts'] ?? [],
            raw: (array)$response,
        );
    }

    /**
     * @inheritdoc
     */
    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $collection = $this->_getClient()->collections[$indexName]->retrieve();

        return $collection['num_documents'] ?? 0;
    }

    /**
     * Retrieve all document IDs by exporting the collection as JSONL.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings.
     */
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

    /**
     * @inheritdoc
     */
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
            FieldMapping::TYPE_FACET => ['type' => 'string[]', 'facet' => true],
            FieldMapping::TYPE_OBJECT => ['type' => 'object', 'facet' => false],
            default => ['type' => 'string', 'facet' => false],
        };
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
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
     * Build a map of field name → Typesense type string from the index's field mappings.
     *
     * @param Index $index The index to inspect.
     * @return array<string, string> Map of field name to Typesense type (e.g. 'string', 'string[]').
     */
    private function _buildSchemaMap(Index $index): array
    {
        $map = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }
            $typesenseType = $this->mapFieldType($mapping->indexFieldType);
            $map[$mapping->indexFieldName] = $typesenseType['type'];
        }
        return $map;
    }

    /**
     * Coerce document values for Typesense's strict type system.
     *
     * Uses the collection schema to determine whether array values should be
     * kept as `string[]` (for `string[]` typed fields) or joined into a single
     * string (for `string` typed fields). Nested structures are JSON-encoded.
     *
     * @param array $document   The document to coerce.
     * @param array $schemaMap  Map of field name → Typesense type string (e.g. 'string', 'string[]').
     * @return array The coerced document.
     */
    private function _coerceDocumentValues(array $document, array $schemaMap = []): array
    {
        foreach ($document as $key => $value) {
            if (is_array($value)) {
                $expectedType = $schemaMap[$key] ?? null;
                $allScalar = array_reduce($value, fn($carry, $v) => $carry && is_scalar($v), true);

                if ($allScalar && $expectedType === 'string[]') {
                    // Keep as array of strings for string[] fields
                    $document[$key] = array_map('strval', $value);
                } elseif ($allScalar) {
                    // Scalar array but schema expects string — join into one string
                    $document[$key] = implode(', ', array_map('strval', $value));
                } else {
                    // Nested structures — JSON-encode for string fields
                    $document[$key] = json_encode($value);
                }
            }
        }

        return $document;
    }

    /**
     * Build a comma-separated list of searchable field names for the given index.
     *
     * Only string-type fields are included. Falls back to '*' if none are found.
     *
     * @param Index $index The index whose field mappings should be inspected.
     * @return string Comma-separated field names, or '*' as a wildcard.
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
