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
    public static function requiredPackage(): string
    {
        return 'typesense/typesense-php';
    }

    /**
     * @inheritdoc
     */
    public static function isClientInstalled(): bool
    {
        return class_exists(Client::class);
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
            'host' => [
                'label' => 'Host',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Typesense host for this index. Leave blank to use the global setting.',
            ],
            'port' => [
                'label' => 'Port',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Typesense port for this index. Leave blank to use the global setting.',
            ],
            'protocol' => [
                'label' => 'Protocol',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Typesense protocol for this index. Leave blank to use the global setting.',
            ],
            'apiKey' => [
                'label' => 'API Key',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Typesense API Key for this index. Leave blank to use the global setting.',
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

            $host = $this->resolveConfigOrGlobal('host', $settings->typesenseHost);
            $port = $this->resolveConfigOrGlobal('port', $settings->typesensePort);
            $protocol = $this->resolveConfigOrGlobal('protocol', $settings->typesenseProtocol);
            $apiKey = $this->resolveConfigOrGlobal('apiKey', $settings->typesenseApiKey);

            if (empty($host) || empty($apiKey)) {
                throw new \RuntimeException('Typesense host and API Key are required. Set them in plugin settings or on the index.');
            }

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
        $collectionName = $this->getIndexName($index);
        $fields = $this->buildSchema($index->getFieldMappings());

        $schema = [
            'name' => $collectionName,
            'fields' => $fields,
        ];

        $this->_getClient()->collections->create($schema);
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
     * Checks which collection the production alias currently points to
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
     * Atomically swap the production alias to point to the new collection.
     *
     * On first swap (migrating from a direct collection to aliases), the direct
     * collection is deleted and replaced with an alias. Subsequent swaps are atomic.
     *
     * @inheritdoc
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        $aliasName = $this->getIndexName($index);
        $newTarget = $this->getIndexName($swapIndex);
        $currentTarget = $this->_getAliasTarget($aliasName);

        if ($currentTarget !== null) {
            // Update alias to point to new collection
            $this->_getClient()->aliases->upsert($aliasName, ['collection_name' => $newTarget]);
            // Clean up old collection
            $this->_getClient()->collections[$currentTarget]->delete();
        } else {
            // First swap: migrate from direct collection to alias-based
            if ($this->_directCollectionExists($aliasName)) {
                $this->_getClient()->collections[$aliasName]->delete();
            }
            $this->_getClient()->aliases->upsert($aliasName, ['collection_name' => $newTarget]);
        }
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $collectionName = $this->_resolveToCollectionName($this->getIndexName($index));
        $desiredFields = $this->buildSchema($index->getFieldMappings());

        // Retrieve the current schema to diff against
        $collection = $this->_getClient()->collections[$collectionName]->retrieve();
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
            $this->_getClient()->collections[$collectionName]->update([
                'fields' => $updateFields,
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $name = $this->getIndexName($index);
        $target = $this->_getAliasTarget($name);

        if ($target !== null) {
            // Alias-based: delete alias first, then the backing collection
            $this->_getClient()->aliases[$name]->delete();
            $this->_getClient()->collections[$target]->delete();
        } else {
            // Direct collection
            $this->_getClient()->collections[$name]->delete();
        }
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $name = $this->getIndexName($index);

        // Check alias first, then direct collection
        if ($this->_getAliasTarget($name) !== null) {
            return true;
        }

        try {
            $this->_getClient()->collections[$name]->retrieve();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getIndexSchema(Index $index): array
    {
        $collectionName = $this->_resolveToCollectionName($this->getIndexName($index));

        try {
            return $this->_getClient()->collections[$collectionName]->retrieve();
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
     * Map a Typesense native type back to a plugin field type constant.
     *
     * @param string $nativeType The Typesense type string.
     * @return string A FieldMapping::TYPE_* constant.
     */
    private function reverseMapFieldType(string $nativeType): string
    {
        return match ($nativeType) {
            'string' => FieldMapping::TYPE_TEXT,
            'string[]' => FieldMapping::TYPE_FACET,
            'int32', 'int64' => FieldMapping::TYPE_INTEGER,
            'float' => FieldMapping::TYPE_FLOAT,
            'bool' => FieldMapping::TYPE_BOOLEAN,
            'geopoint' => FieldMapping::TYPE_GEO_POINT,
            'object', 'object[]' => FieldMapping::TYPE_OBJECT,
            default => FieldMapping::TYPE_TEXT,
        };
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $schemaMap = $this->_buildSchemaMap($index);

        // Typesense requires 'id' as a string
        $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_EPOCH_SECONDS);
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
            $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_EPOCH_SECONDS);
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
        $collectionName = $this->_resolveToCollectionName($this->getIndexName($index));

        try {
            $collectionInfo = $this->_getClient()->collections[$collectionName]->retrieve();
            $this->_getClient()->collections[$collectionName]->delete();

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

            $newCollection = $this->_getClient()->collections->create($schema);

            // If we were alias-based, re-point the alias to the new collection
            $name = $this->getIndexName($index);
            if ($this->_getAliasTarget($name) !== null || $collectionName !== $name) {
                $this->_getClient()->aliases->upsert($name, ['collection_name' => $collectionName]);
            }
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
     * Native Typesense facet value search using the facet_query parameter.
     *
     * Typesense supports prefix matching within facet values via the
     * `facet_query` search parameter.
     *
     * @inheritdoc
     */
    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5): array
    {
        $indexName = $this->getIndexName($index);
        $queryBy = $this->_getSearchableFieldNames($index);
        $grouped = [];

        // Fetch all facet values in a single request, then filter client-side.
        // Typesense's native facet_query only does word-level prefix matching
        // (e.g. "sus" matches "Sussex" but "shire" won't match "Stirlingshire"),
        // so we use client-side substring matching for full coverage.
        $response = $this->_getClient()->collections[$indexName]->documents->search([
            'q' => '*',
            'query_by' => $queryBy,
            'facet_by' => implode(',', $facetFields),
            'per_page' => 0,
            'max_facet_values' => 100,
        ]);

        $allFacets = $this->normaliseRawFacets((array)$response);
        $queryLower = mb_strtolower($query);

        foreach ($facetFields as $field) {
            $allValues = $allFacets[$field] ?? [];
            $values = [];

            if ($query === '') {
                $values = array_slice($allValues, 0, $maxPerField);
            } else {
                foreach ($allValues as $facetValue) {
                    if (mb_strpos(mb_strtolower((string)$facetValue['value']), $queryLower) !== false) {
                        $values[] = $facetValue;
                        if (count($values) >= $maxPerField) {
                            break;
                        }
                    }
                }
            }

            if (!empty($values)) {
                $grouped[$field] = $values;
            }
        }

        return $grouped;
    }

    /**
     * @inheritdoc
     */
    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        $indexName = $this->getIndexName($index);

        [$facets, $filters, $options] = $this->extractFacetParams($options);
        [, $options] = $this->extractStatsParams($options);
        [$histogramConfig, $options] = $this->extractHistogramParams($options);
        [$sort, $options] = $this->extractSortParams($options);
        [$attributesToRetrieve, $options] = $this->extractAttributesToRetrieve($options);
        [$highlight, $options] = $this->extractHighlightParams($options);
        [, $options] = $this->extractSuggestParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        // Engine-native page/per_page take precedence over unified values.
        if (!isset($remaining['page'])) {
            $remaining['page'] = $page; // Typesense is already 1-based
        }
        if (!isset($remaining['per_page'])) {
            $remaining['per_page'] = $perPage;
        }

        // Unified sort → Typesense sort_by: 'field:direction,...'
        if (!empty($sort) && !isset($remaining['sort_by'])) {
            $remaining['sort_by'] = $this->buildNativeSortParams($sort);
        }

        // Unified attributesToRetrieve → Typesense include_fields
        if ($attributesToRetrieve !== null && !isset($remaining['include_fields'])) {
            $remaining['include_fields'] = implode(',', $attributesToRetrieve);
        }

        // Unified highlight → Typesense highlight_fields
        if ($highlight !== null && !isset($remaining['highlight_fields'])) {
            if (is_array($highlight)) {
                $remaining['highlight_fields'] = implode(',', $highlight);
            }
            // highlight: true uses default (all query_by fields) — no param needed
        }

        // Unified facets → Typesense facet_by
        if (!empty($facets) && !isset($remaining['facet_by'])) {
            $remaining['facet_by'] = implode(',', $facets);
        }

        // Unified filters → Typesense filter_by
        if (!empty($filters) && !isset($remaining['filter_by'])) {
            $remaining['filter_by'] = $this->buildNativeFilterParams($filters, $index);
        }

        // Histogram range facets → Typesense facet_by with range syntax
        $histogramFields = [];
        if (!empty($histogramConfig)) {
            foreach ($histogramConfig as $field => $config) {
                $interval = (float)$config['interval'];
                $min = $config['min'] ?? null;
                $max = $config['max'] ?? null;

                // If min/max not provided, do a lightweight pre-flight stats query
                if ($min === null || $max === null) {
                    $fieldStats = $this->_getFieldStats($index, $field);
                    $min = $min ?? $fieldStats['min'];
                    $max = $max ?? $fieldStats['max'];
                }

                if ($min === null || $max === null) {
                    continue;
                }

                $ranges = $this->_buildHistogramRanges((float)$min, (float)$max, $interval);
                if (empty($ranges)) {
                    continue;
                }

                // Build range facet syntax: field(label1:[min,max], label2:[min,max])
                $rangeParts = [];
                foreach ($ranges as $range) {
                    $rangeParts[] = $range['label'] . ':[' . $range['min'] . ',' . $range['max'] . ']';
                }

                $histogramFields[] = $field;
                $facetByEntry = $field . '(' . implode(', ', $rangeParts) . ')';

                if (isset($remaining['facet_by']) && $remaining['facet_by'] !== '') {
                    $remaining['facet_by'] .= ',' . $facetByEntry;
                } else {
                    $remaining['facet_by'] = $facetByEntry;
                }
            }
        }

        // Build search parameters
        $searchParams = array_merge([
            'q' => $query,
            'query_by' => $remaining['query_by'] ?? $this->_getSearchableFieldNames($index),
        ], $remaining);

        // Remove our custom keys that aren't Typesense params
        unset($searchParams['query_by_fields']);

        $response = $this->_getClient()->collections[$indexName]->documents->search($searchParams);

        // Flatten document, preserve score, normalise highlights.
        $rawHits = array_map([$this, 'normaliseRawHit'], $response['hits'] ?? []);
        $hits = $this->normaliseHits($rawHits, 'id', '_score', null);

        $totalHits = $response['found'] ?? 0;
        $actualPerPage = $remaining['per_page'];

        // Normalise Typesense facet_counts → unified shape
        $normalisedFacets = $this->normaliseRawFacets((array)$response);

        // Normalise histogram fields and remove them from regular facets
        $normalisedHistograms = [];
        if (!empty($histogramFields)) {
            $normalisedHistograms = $this->normaliseRawHistograms((array)$response, $histogramConfig);
            foreach ($histogramFields as $field) {
                unset($normalisedFacets[$field]);
            }
        }

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $response['page'] ?? $page,
            perPage: $actualPerPage,
            totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
            processingTimeMs: $response['search_time_ms'] ?? 0,
            facets: $normalisedFacets,
            histograms: $normalisedHistograms,
            raw: (array)$response,
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

        $searches = [];

        foreach ($queries as $query) {
            $index = $query['index'];
            $indexName = $this->getIndexName($index);
            $options = $query['options'] ?? [];

            [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

            if (!isset($remaining['page'])) {
                $remaining['page'] = $page;
            }
            if (!isset($remaining['per_page'])) {
                $remaining['per_page'] = $perPage;
            }

            $searches[] = array_merge([
                'collection' => $indexName,
                'q' => $query['query'],
                'query_by' => $remaining['query_by'] ?? $this->_getSearchableFieldNames($index),
            ], $remaining);
        }

        $response = $this->_getClient()->multiSearch->perform(['searches' => $searches]);

        $results = [];
        foreach ($response['results'] ?? [] as $i => $resp) {
            $options = $queries[$i]['options'] ?? [];
            $perPage = (int)($options['perPage'] ?? 10);
            $actualPerPage = (int)($resp['request_params']['per_page'] ?? $perPage);

            $rawHits = array_map([$this, 'normaliseRawHit'], $resp['hits'] ?? []);
            $hits = $this->normaliseHits($rawHits, 'id', '_score', null);
            $totalHits = $resp['found'] ?? 0;

            // Normalise Typesense facet_counts → unified shape
            $normalisedFacets = $this->normaliseRawFacets((array)$resp);

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: $resp['page'] ?? 1,
                perPage: $actualPerPage,
                totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
                processingTimeMs: $resp['search_time_ms'] ?? 0,
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
        $collectionName = $this->_resolveToCollectionName($this->getIndexName($index));

        $collection = $this->_getClient()->collections[$collectionName]->retrieve();

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

            // Enable sorting for numeric, date, and title fields
            if (in_array($typesenseType['type'], ['int32', 'int64', 'float'], true)) {
                $field['sort'] = true;
            } elseif ($typesenseType['type'] === 'string' && $mapping->role === FieldMapping::ROLE_TITLE) {
                $field['sort'] = true;
            }

            $fields[] = $field;
        }

        // Always include sectionHandle and entryTypeHandle
        $fields[] = ['name' => 'sectionHandle', 'type' => 'string', 'facet' => true, 'optional' => true];
        $fields[] = ['name' => 'entryTypeHandle', 'type' => 'string', 'facet' => true, 'optional' => true];

        // Auto-derive has_image boolean when a ROLE_IMAGE mapping exists
        foreach ($fieldMappings as $mapping) {
            if ($mapping instanceof FieldMapping && $mapping->enabled && $mapping->role === FieldMapping::ROLE_IMAGE) {
                $fields[] = ['name' => 'has_image', 'type' => 'bool', 'facet' => true, 'optional' => true];
                break;
            }
        }

        return $fields;
    }

    /**
     * Convert unified sort to Typesense sort_by: `'field:direction,...'`.
     *
     * @inheritdoc
     */
    protected function buildNativeSortParams(array $sort): mixed
    {
        if (empty($sort) || !$this->isUnifiedSort($sort)) {
            return $sort;
        }

        $parts = [];
        foreach ($sort as $field => $direction) {
            $parts[] = "{$field}:{$direction}";
        }
        return implode(',', $parts);
    }

    /**
     * Convert unified filters to Typesense filter_by string.
     *
     * @inheritdoc
     */
    protected function buildNativeFilterParams(array $filters, Index $index): mixed
    {
        if (empty($filters)) {
            return '';
        }

        $clauses = [];
        foreach ($filters as $field => $value) {
            if ($this->isRangeFilter($value)) {
                $parts = [];
                if (isset($value['min']) && $value['min'] !== '' && is_numeric($value['min'])) {
                    $parts[] = "{$field}:>=" . (float)$value['min'];
                }
                if (isset($value['max']) && $value['max'] !== '' && is_numeric($value['max'])) {
                    $parts[] = "{$field}:<=" . (float)$value['max'];
                }
                if (empty($parts)) {
                    continue;
                }
                $clauses[] = implode(' && ', $parts);
            } elseif (is_array($value)) {
                $clauses[] = $field . ':=[' . implode(',', array_map(fn($v) => '`' . str_replace('`', '\\`', (string)$v) . '`', $value)) . ']';
            } else {
                $clauses[] = $field . ':=`' . str_replace('`', '\\`', (string)$value) . '`';
            }
        }
        return implode(' && ', $clauses);
    }

    /**
     * Normalise a Typesense hit: extract document, score, highlights.
     *
     * @inheritdoc
     */
    protected function normaliseRawHit(array $hit): array
    {
        $document = $hit['document'] ?? [];
        $document['_score'] = $hit['text_match'] ?? null;

        // Prefer object format (v26+), fall back to legacy array
        $highlightData = $hit['highlight'] ?? [];
        if (empty($highlightData)) {
            // Convert legacy array: [{ field: 'title', snippet: 'text' }] → { title: 'text' }
            foreach ($hit['highlights'] ?? [] as $hl) {
                $f = $hl['field'] ?? '';
                $s = $hl['snippet'] ?? '';
                if ($f !== '' && $s !== '') {
                    $highlightData[$f] = $s;
                }
            }
        }
        $document['_highlights'] = $this->normaliseHighlightData($highlightData);

        if (isset($document['id']) && !isset($document['objectID'])) {
            $document['objectID'] = (string)$document['id'];
        }
        return $document;
    }

    /**
     * Normalise Typesense facet_counts → unified facet shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawFacets(array $response): array
    {
        $normalised = [];
        foreach ($response['facet_counts'] ?? [] as $facetGroup) {
            $field = $facetGroup['field_name'] ?? '';
            if ($field === '') {
                continue;
            }
            $normalised[$field] = array_map(fn($item) => [
                'value' => (string)($item['value'] ?? ''),
                'count' => (int)($item['count'] ?? 0),
            ], $facetGroup['counts'] ?? []);
        }
        return $normalised;
    }

    /**
     * @inheritdoc
     */
    protected function parseSchemaFields(array $schema): array
    {
        $fields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $name = $field['name'] ?? null;
            if ($name === null || $name === '.*') {
                continue;
            }
            $nativeType = $field['type'] ?? 'string';
            $fields[] = ['name' => $name, 'type' => $this->reverseMapFieldType($nativeType)];
        }
        return $fields;
    }

    /**
     * Normalise Typesense highlight data into unified { field: [fragments] } format.
     *
     * Handles both the v26+ object format `{ field: { snippet: 'text' } }` and
     * the legacy string-value format `{ field: 'text' }` (converted from array
     * format before calling this method).
     *
     * @param array $highlightData Raw Typesense highlight data.
     * @return array<string, string[]> Normalised highlights.
     */
    protected function normaliseHighlightData(array $highlightData): array
    {
        // Detect object format: { field: { snippet: 'text', matched_tokens: [...] } }
        $first = reset($highlightData);
        if (is_array($first) && isset($first['snippet'])) {
            $normalised = [];
            foreach ($highlightData as $field => $data) {
                if (is_string($field) && is_array($data) && isset($data['snippet'])) {
                    $snippet = $data['snippet'];
                    if (is_string($snippet) && $snippet !== '') {
                        $normalised[$field] = [$snippet];
                    }
                }
            }
            return $normalised;
        }

        // Legacy string-value format — delegate to base
        return parent::normaliseHighlightData($highlightData);
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
        // Always-present fields
        $map['sectionHandle'] = 'string';
        $map['entryTypeHandle'] = 'string';

        // Auto-derived has_image when a ROLE_IMAGE mapping exists
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping instanceof FieldMapping && $mapping->enabled && $mapping->role === FieldMapping::ROLE_IMAGE) {
                $map['has_image'] = 'bool';
                break;
            }
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

                if ($expectedType === 'object' || $expectedType === 'object[]') {
                    // Preserve arrays/objects as-is for object-typed fields
                    continue;
                } elseif ($allScalar && $expectedType === 'string[]') {
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

    /**
     * Normalise Typesense range facet counts into unified histogram shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawHistograms(array $response, array $histogramConfig = []): array
    {
        $normalised = [];
        foreach ($histogramConfig as $field => $config) {
            $buckets = [];
            foreach ($response['facet_counts'] ?? [] as $facetGroup) {
                if (($facetGroup['field_name'] ?? '') !== $field) {
                    continue;
                }
                foreach ($facetGroup['counts'] ?? [] as $item) {
                    $label = (string)($item['value'] ?? '');
                    $count = (int)($item['count'] ?? 0);

                    // Extract numeric key from range label (e.g. "0_100000" → 0)
                    $parts = explode('_', $label, 2);
                    if (count($parts) >= 1 && is_numeric($parts[0])) {
                        $key = (float)$parts[0];
                        // Use int when the value is a whole number
                        $buckets[] = [
                            'key' => ($key == (int)$key) ? (int)$key : $key,
                            'count' => $count,
                        ];
                    }
                }
            }

            if (!empty($buckets)) {
                // Sort by key ascending
                usort($buckets, fn($a, $b) => $a['key'] <=> $b['key']);
                $normalised[$field] = $buckets;
            }
        }
        return $normalised;
    }

    /**
     * Get min/max stats for a numeric field via a lightweight pre-flight query.
     *
     * @param Index  $index The index to query.
     * @param string $field The field to get stats for.
     * @return array{min: float|null, max: float|null}
     */
    private function _getFieldStats(Index $index, string $field): array
    {
        $indexName = $this->getIndexName($index);
        $queryBy = $this->_getSearchableFieldNames($index);

        try {
            $response = $this->_getClient()->collections[$indexName]->documents->search([
                'q' => '*',
                'query_by' => $queryBy,
                'facet_by' => $field,
                'per_page' => 0,
            ]);

            $stats = $response['facet_counts'][0]['stats'] ?? [];

            return [
                'min' => isset($stats['min']) ? (float)$stats['min'] : null,
                'max' => isset($stats['max']) ? (float)$stats['max'] : null,
            ];
        } catch (\Throwable $e) {
            return ['min' => null, 'max' => null];
        }
    }

    /**
     * Generate histogram range definitions from bounds and interval.
     *
     * @param float $min      Lower bound.
     * @param float $max      Upper bound.
     * @param float $interval Bucket interval width.
     * @return array<array{label: string, min: float, max: float}>
     */
    private function _buildHistogramRanges(float $min, float $max, float $interval): array
    {
        if ($interval <= 0 || $min > $max) {
            return [];
        }

        $ranges = [];
        $current = $min;
        $maxBuckets = 200;

        while ($current < $max && count($ranges) < $maxBuckets) {
            $rangeEnd = $current + $interval;
            // Use integer formatting when values are whole numbers
            $labelMin = ($current == (int)$current) ? (int)$current : $current;
            $labelMax = ($rangeEnd == (int)$rangeEnd) ? (int)$rangeEnd : $rangeEnd;
            $ranges[] = [
                'label' => $labelMin . '_' . $labelMax,
                'min' => $current,
                'max' => $rangeEnd,
            ];
            $current = $rangeEnd;
        }

        return $ranges;
    }

    // -- Alias helpers --------------------------------------------------------

    /**
     * Get the collection name that a Typesense alias points to.
     *
     * @param string $aliasName The alias name.
     * @return string|null The backing collection name, or null if no alias exists.
     */
    private function _getAliasTarget(string $aliasName): ?string
    {
        try {
            $alias = $this->_getClient()->aliases[$aliasName]->retrieve();
            return $alias['collection_name'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve a name to its backing collection name.
     *
     * If the name is an alias, returns the collection it points to.
     * Otherwise returns the name as-is (it's a direct collection).
     *
     * @param string $name The alias or collection name.
     * @return string The resolved collection name.
     */
    private function _resolveToCollectionName(string $name): string
    {
        $target = $this->_getAliasTarget($name);

        return $target ?? $name;
    }

    /**
     * Check whether a direct collection (not an alias) exists.
     *
     * @param string $name The collection name to check.
     * @return bool
     */
    private function _directCollectionExists(string $name): bool
    {
        try {
            $this->_getClient()->collections[$name]->retrieve();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
