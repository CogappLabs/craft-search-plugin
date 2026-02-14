<?php

/**
 * Algolia search engine implementation.
 */

namespace cogapp\searchindex\engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Model\Search\IndexSettings;
use Algolia\AlgoliaSearch\Model\Search\SearchForHits;
use Algolia\AlgoliaSearch\Model\Search\SearchMethodParams;
use Algolia\AlgoliaSearch\Model\Search\SearchParamsObject;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;

/**
 * Search engine implementation backed by Algolia.
 *
 * Connects to the Algolia SaaS search platform via the official PHP client.
 * Translates plugin field types into Algolia index settings (searchable
 * attributes, faceting, numeric filtering).
 *
 * @author cogapp
 * @since 1.0.0
 */
class AlgoliaEngine extends AbstractEngine
{
    /**
     * Cached Algolia search client instance.
     *
     * @var SearchClient|null
     */
    private ?SearchClient $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Algolia';
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
     * Return the configured Algolia search client, creating it on first access.
     *
     * @return SearchClient
     */
    private function _getClient(): SearchClient
    {
        if ($this->_client === null) {
            if (!class_exists(SearchClient::class)) {
                throw new \RuntimeException('The Algolia engine requires the "algolia/algoliasearch-client-php" package. Install it with: composer require algolia/algoliasearch-client-php');
            }

            $settings = SearchIndex::$plugin->getSettings();

            $appId = App::parseEnv($settings->algoliaAppId);
            $apiKey = App::parseEnv($settings->algoliaApiKey);

            $this->_client = SearchClient::create($appId, $apiKey);
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

        $settings = new IndexSettings($schema);
        $this->_getClient()->setSettings($indexName, $settings);
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $settings = new IndexSettings($schema);
        $this->_getClient()->setSettings($indexName, $settings);
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->deleteIndex($indexName);
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->getSettings($indexName);
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
        $indexName = $this->getIndexName($index);

        try {
            $settings = $this->_getClient()->getSettings($indexName);
            return json_decode(json_encode($settings), true) ?: [];
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
            return [];
        }

        $fields = [];
        $seen = [];

        // searchableAttributes → text fields
        foreach ($schema['searchableAttributes'] ?? [] as $attr) {
            // Strip ordered()/unordered() wrappers
            $name = preg_replace('/^(?:ordered|unordered)\((.+)\)$/', '$1', $attr);
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_TEXT];
                $seen[$name] = true;
            }
        }

        // attributesForFaceting → facet/keyword fields
        foreach ($schema['attributesForFaceting'] ?? [] as $attr) {
            // Strip searchable()/filterOnly() wrappers
            $name = preg_replace('/^(?:searchable|filterOnly)\((.+)\)$/', '$1', $attr);
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_FACET];
                $seen[$name] = true;
            }
        }

        // numericAttributesForFiltering → integer fields
        foreach ($schema['numericAttributesForFiltering'] ?? [] as $name) {
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_INTEGER];
                $seen[$name] = true;
            }
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $document['objectID'] = (string)$elementId;

        $this->_getClient()->saveObject($indexName, $document);
    }

    /**
     * Batch-save multiple documents using the Algolia saveObjects API.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        $indexName = $this->getIndexName($index);
        $objects = [];

        foreach ($documents as $document) {
            // Ensure objectID is set (resolveElement sets it)
            if (isset($document['objectID'])) {
                $document['objectID'] = (string)$document['objectID'];
            }
            $objects[] = $document;
        }

        if (!empty($objects)) {
            $this->_getClient()->saveObjects($indexName, $objects);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->deleteObject($indexName, (string)$elementId);
    }

    /**
     * Batch-delete multiple documents using the Algolia deleteObjects API.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        $indexName = $this->getIndexName($index);
        $objectIds = array_map('strval', $elementIds);

        $this->_getClient()->deleteObjects($indexName, $objectIds);
    }

    /**
     * @inheritdoc
     */
    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->clearObjects($indexName);
    }

    /**
     * @inheritdoc
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        $indexName = $this->getIndexName($index);

        try {
            $response = $this->_getClient()->getObject($indexName, $documentId);
            return (array)$response;
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

        // Translate to Algolia's 0-based page and hitsPerPage unless caller
        // already provided engine-native pagination keys.
        if (!isset($remaining['hitsPerPage'])) {
            $remaining['hitsPerPage'] = $perPage;
        }
        if (!array_key_exists('page', $remaining)) {
            $remaining['page'] = $page - 1; // Algolia pages are 0-based
        }

        $searchParams = new SearchParamsObject(array_merge([
            'query' => $query,
        ], $remaining));

        $response = $this->_getClient()->searchSingleIndex($indexName, $searchParams);

        $totalHits = $response['nbHits'] ?? 0;
        $hits = $this->normaliseHits(
            $response['hits'] ?? [],
            'objectID',
            '_score',
            '_highlightResult',
        );

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: ($response['page'] ?? 0) + 1, // Convert 0-based → 1-based
            perPage: $response['hitsPerPage'] ?? $perPage,
            totalPages: $response['nbPages'] ?? $this->computeTotalPages($totalHits, $perPage),
            processingTimeMs: $response['processingTimeMS'] ?? 0,
            raw: (array)$response,
        );
    }

    /**
     * @inheritdoc
     */
    public function multiSearch(array $queries): array
    {
        $requests = [];

        foreach ($queries as $query) {
            $index = $query['index'];
            $indexName = $this->getIndexName($index);
            $options = $query['options'] ?? [];

            [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

            if (!isset($remaining['hitsPerPage'])) {
                $remaining['hitsPerPage'] = $perPage;
            }
            if (!array_key_exists('page', $remaining)) {
                $remaining['page'] = $page - 1;
            }

            $requests[] = new SearchForHits(array_merge([
                'indexName' => $indexName,
                'query' => $query['query'],
                'type' => 'default',
            ], $remaining));
        }

        $response = $this->_getClient()->search(new SearchMethodParams(['requests' => $requests]));

        $results = [];
        foreach ($response['results'] ?? [] as $i => $resp) {
            $options = $queries[$i]['options'] ?? [];
            $perPage = (int)($options['perPage'] ?? 20);

            $totalHits = $resp['nbHits'] ?? 0;
            $hits = $this->normaliseHits(
                $resp['hits'] ?? [],
                'objectID',
                '_score',
                '_highlightResult',
            );

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: ($resp['page'] ?? 0) + 1,
                perPage: $resp['hitsPerPage'] ?? $perPage,
                totalPages: $resp['nbPages'] ?? $this->computeTotalPages($totalHits, $perPage),
                processingTimeMs: $resp['processingTimeMS'] ?? 0,
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

        $searchParams = new SearchParamsObject([
            'query' => '',
            'hitsPerPage' => 0,
        ]);

        $response = $this->_getClient()->searchSingleIndex($indexName, $searchParams);

        return $response['nbHits'] ?? 0;
    }

    /**
     * Retrieve all document IDs using the Algolia browse API with cursor-based pagination.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings (objectIDs).
     */
    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];

        $browseParams = new \Algolia\AlgoliaSearch\Model\Search\BrowseParamsObject([
            'attributesToRetrieve' => [],
        ]);

        $response = $this->_getClient()->browse($indexName, $browseParams);

        foreach ($response['hits'] ?? [] as $hit) {
            $ids[] = $hit['objectID'];
        }

        while (!empty($response['cursor'])) {
            $browseParams = new \Algolia\AlgoliaSearch\Model\Search\BrowseParamsObject([
                'attributesToRetrieve' => [],
                'cursor' => $response['cursor'],
            ]);

            $response = $this->_getClient()->browse($indexName, $browseParams);

            foreach ($response['hits'] ?? [] as $hit) {
                $ids[] = $hit['objectID'];
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
            FieldMapping::TYPE_TEXT => 'searchableAttributes',
            FieldMapping::TYPE_KEYWORD => 'attributesForFaceting',
            FieldMapping::TYPE_FACET => 'attributesForFaceting',
            FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT => 'numericAttributesForFiltering',
            FieldMapping::TYPE_BOOLEAN => 'attributesForFaceting',
            FieldMapping::TYPE_DATE => 'numericAttributesForFiltering',
            FieldMapping::TYPE_GEO_POINT => '_geoloc',
            FieldMapping::TYPE_OBJECT => 'searchableAttributes',
            default => 'searchableAttributes',
        };
    }

    /**
     * @inheritdoc
     */
    public function buildSchema(array $fieldMappings): array
    {
        $searchableAttributes = [];
        $attributesForFaceting = [];
        $numericAttributesForFiltering = [];

        foreach ($fieldMappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            $fieldName = $mapping->indexFieldName;
            $algoliaType = $this->mapFieldType($mapping->indexFieldType);

            switch ($algoliaType) {
                case 'searchableAttributes':
                    // Use weight for ordering (higher weight = higher priority)
                    $searchableAttributes[] = [
                        'name' => $fieldName,
                        'weight' => $mapping->weight,
                    ];
                    break;

                case 'attributesForFaceting':
                    $prefix = $mapping->indexFieldType === FieldMapping::TYPE_BOOLEAN ? 'filterOnly' : 'searchable';
                    $attributesForFaceting[] = "{$prefix}({$fieldName})";
                    break;

                case 'numericAttributesForFiltering':
                    $numericAttributesForFiltering[] = $fieldName;
                    break;

                case '_geoloc':
                    // Algolia handles _geoloc automatically when the field is named _geoloc
                    break;
            }
        }

        $formattedSearchable = $this->sortByWeight($searchableAttributes);

        $settings = [];

        if (!empty($formattedSearchable)) {
            $settings['searchableAttributes'] = $formattedSearchable;
        }

        if (!empty($attributesForFaceting)) {
            $settings['attributesForFaceting'] = $attributesForFaceting;
        }

        if (!empty($numericAttributesForFiltering)) {
            $settings['numericAttributesForFiltering'] = $numericAttributesForFiltering;
        }

        return $settings;
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        try {
            $this->_getClient()->listIndices();
            return true;
        } catch (\Exception $e) {
            Craft::warning('Algolia connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
