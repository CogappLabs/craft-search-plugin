<?php

/**
 * Meilisearch search engine implementation.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use Meilisearch\Client;

/**
 * Search engine implementation backed by Meilisearch.
 *
 * Connects to a Meilisearch instance via the official PHP SDK. Meilisearch
 * is a lightweight, typo-tolerant search engine. This engine translates
 * plugin field types into searchable, filterable, and sortable attribute
 * settings.
 *
 * @author cogapp
 * @since 1.0.0
 */
class MeilisearchEngine extends AbstractEngine
{
    /**
     * Cached Meilisearch client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Meilisearch';
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
     * Return the configured Meilisearch client, creating it on first access.
     *
     * @return Client
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            if (!class_exists(Client::class)) {
                throw new \RuntimeException('The Meilisearch engine requires the "meilisearch/meilisearch-php" package. Install it with: composer require meilisearch/meilisearch-php');
            }

            $settings = SearchIndex::$plugin->getSettings();

            $host = App::parseEnv($settings->meilisearchHost);
            $apiKey = App::parseEnv($settings->meilisearchApiKey);

            $this->_client = new Client($host, $apiKey);
        }

        return $this->_client;
    }

    /**
     * @inheritdoc
     */
    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $task = $this->_getClient()->createIndex($indexName, ['primaryKey' => 'objectID']);
        $this->_getClient()->waitForTask($task['taskUid']);
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $meilisearchIndex = $this->_getClient()->index($indexName);

        if (!empty($schema['searchableAttributes'])) {
            $meilisearchIndex->updateSearchableAttributes($schema['searchableAttributes']);
        }

        if (!empty($schema['filterableAttributes'])) {
            $meilisearchIndex->updateFilterableAttributes($schema['filterableAttributes']);
        }

        if (!empty($schema['sortableAttributes'])) {
            $meilisearchIndex->updateSortableAttributes($schema['sortableAttributes']);
        }
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
            $this->_getClient()->getIndex($indexName);
            return true;
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
        $document['objectID'] = (string)$elementId;

        $this->_getClient()->index($indexName)->addDocuments([$document]);
    }

    /**
     * Batch-add multiple documents using the Meilisearch addDocuments API.
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

        foreach ($documents as &$document) {
            if (isset($document['objectID'])) {
                $document['objectID'] = (string)$document['objectID'];
            }
        }
        unset($document);

        $this->_getClient()->index($indexName)->addDocuments($documents);
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->index($indexName)->deleteDocument($elementId);
    }

    /**
     * Batch-delete multiple documents using the Meilisearch deleteDocuments API.
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

        $this->_getClient()->index($indexName)->deleteDocuments($elementIds);
    }

    /**
     * @inheritdoc
     */
    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->index($indexName)->deleteAllDocuments();
    }

    /**
     * @inheritdoc
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        $indexName = $this->getIndexName($index);

        try {
            $document = $this->_getClient()->index($indexName)->getDocument($documentId);
            return (array)$document;
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

        // Engine-native offset/limit take precedence over unified page/perPage.
        if (!isset($remaining['offset'])) {
            $remaining['offset'] = ($page - 1) * $perPage;
        }
        if (!isset($remaining['limit'])) {
            $remaining['limit'] = $perPage;
        }

        $response = $this->_getClient()->index($indexName)->search($query, $remaining);

        $rawHits = $response->getHits();
        $hits = $this->normaliseHits($rawHits, 'objectID', '_rankingScore', '_formatted');

        $totalHits = $response->getTotalHits() ?? $response->getEstimatedTotalHits() ?? 0;
        $actualPerPage = $remaining['limit'];

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $actualPerPage > 0 ? (int)floor($remaining['offset'] / $actualPerPage) + 1 : 1,
            perPage: $actualPerPage,
            totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
            processingTimeMs: $response->getProcessingTimeMs(),
            raw: $response->toArray(),
        );
    }

    /**
     * @inheritdoc
     */
    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $stats = $this->_getClient()->index($indexName)->stats();

        return $stats['numberOfDocuments'] ?? 0;
    }

    /**
     * Retrieve all document IDs using offset-based pagination via getDocuments.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings (objectIDs).
     */
    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];
        $offset = 0;
        $limit = 1000;

        $meilisearchIndex = $this->_getClient()->index($indexName);

        while (true) {
            $query = new \Meilisearch\Contracts\DocumentsQuery();
            $query->setFields(['objectID']);
            $query->setOffset($offset);
            $query->setLimit($limit);

            $response = $meilisearchIndex->getDocuments($query);

            $results = $response->getResults();
            if (empty($results)) {
                break;
            }

            foreach ($results as $doc) {
                $ids[] = $doc['objectID'];
            }

            if (count($results) < $limit) {
                break;
            }

            $offset += $limit;
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
            FieldMapping::TYPE_KEYWORD => 'filterableAttributes',
            FieldMapping::TYPE_FACET => 'filterableAttributes',
            FieldMapping::TYPE_BOOLEAN => 'filterableAttributes',
            FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT => 'filterableAndSortable',
            FieldMapping::TYPE_DATE => 'filterableAndSortable',
            FieldMapping::TYPE_GEO_POINT => 'filterableAndSortable',
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
        $filterableAttributes = [];
        $sortableAttributes = [];

        foreach ($fieldMappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled) {
                continue;
            }

            $fieldName = $mapping->indexFieldName;
            $meilisearchType = $this->mapFieldType($mapping->indexFieldType);

            switch ($meilisearchType) {
                case 'searchableAttributes':
                    $searchableAttributes[] = [
                        'name' => $fieldName,
                        'weight' => $mapping->weight,
                    ];
                    break;

                case 'filterableAttributes':
                    $filterableAttributes[] = $fieldName;
                    break;

                case 'filterableAndSortable':
                    $filterableAttributes[] = $fieldName;
                    $sortableAttributes[] = $fieldName;
                    break;
            }
        }

        // Sort searchable attributes by weight (descending) then format
        usort($searchableAttributes, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $formattedSearchable = array_map(fn($attr) => $attr['name'], $searchableAttributes);

        $schema = [];

        if (!empty($formattedSearchable)) {
            $schema['searchableAttributes'] = $formattedSearchable;
        }

        if (!empty($filterableAttributes)) {
            $schema['filterableAttributes'] = array_unique($filterableAttributes);
        }

        if (!empty($sortableAttributes)) {
            $schema['sortableAttributes'] = array_unique($sortableAttributes);
        }

        return $schema;
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        try {
            $health = $this->_getClient()->health();
            return ($health['status'] ?? '') === 'available';
        } catch (\Exception $e) {
            Craft::warning('Meilisearch connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
