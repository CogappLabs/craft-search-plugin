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
    public function getIndexSchema(Index $index): array
    {
        $indexName = $this->getIndexName($index);

        try {
            $meilisearchIndex = $this->_getClient()->index($indexName);
            $settings = $meilisearchIndex->getSettings();
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
        foreach ($schema['searchableAttributes'] ?? [] as $name) {
            if ($name === '*') {
                continue;
            }
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_TEXT];
                $seen[$name] = true;
            }
        }

        // filterableAttributes → keyword fields
        foreach ($schema['filterableAttributes'] ?? [] as $name) {
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_KEYWORD];
                $seen[$name] = true;
            }
        }

        // sortableAttributes → any remaining sortable fields
        foreach ($schema['sortableAttributes'] ?? [] as $name) {
            if (!isset($seen[$name])) {
                $fields[] = ['name' => $name, 'type' => FieldMapping::TYPE_KEYWORD];
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

        [$facets, $filters, $options] = $this->extractFacetParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        // Engine-native offset/limit take precedence over unified page/perPage.
        if (!isset($remaining['offset'])) {
            $remaining['offset'] = ($page - 1) * $perPage;
        }
        if (!isset($remaining['limit'])) {
            $remaining['limit'] = $perPage;
        }
        if (!isset($remaining['showRankingScore'])) {
            $remaining['showRankingScore'] = true;
        }

        // Unified facets → Meilisearch native facets param
        if (!empty($facets) && !isset($remaining['facets'])) {
            $remaining['facets'] = $facets;
        }

        // Unified filters → Meilisearch filter string
        if (!empty($filters) && !isset($remaining['filter'])) {
            $clauses = [];
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    // OR within same field
                    $orParts = array_map(fn($v) => "{$field} = \"{$v}\"", $value);
                    $clauses[] = '(' . implode(' OR ', $orParts) . ')';
                } else {
                    $clauses[] = "{$field} = \"{$value}\"";
                }
            }
            $remaining['filter'] = implode(' AND ', $clauses);
        }

        $response = $this->_getClient()->index($indexName)->search($query, $remaining);

        $rawHits = $response->getHits();
        $hits = $this->normaliseHits($rawHits, 'objectID', '_rankingScore', '_formatted');

        $totalHits = $response->getTotalHits() ?? $response->getEstimatedTotalHits() ?? 0;
        $actualPerPage = $remaining['limit'];

        // Normalise Meilisearch facetDistribution: { field: { value: count } } → unified shape
        $rawResponse = $response->toArray();
        $normalisedFacets = [];
        foreach ($rawResponse['facetDistribution'] ?? [] as $field => $valueCounts) {
            $normalisedFacets[$field] = $this->normaliseFacetCounts($valueCounts);
        }

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $actualPerPage > 0 ? (int)floor($remaining['offset'] / $actualPerPage) + 1 : 1,
            perPage: $actualPerPage,
            totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
            processingTimeMs: $response->getProcessingTimeMs(),
            facets: $normalisedFacets,
            raw: $rawResponse,
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

            if (!isset($remaining['offset'])) {
                $remaining['offset'] = ($page - 1) * $perPage;
            }
            if (!isset($remaining['limit'])) {
                $remaining['limit'] = $perPage;
            }
            if (!isset($remaining['showRankingScore'])) {
                $remaining['showRankingScore'] = true;
            }

            $searches[] = array_merge([
                'indexUid' => $indexName,
                'q' => $query['query'],
            ], $remaining);
        }

        $response = $this->_getClient()->multiSearch($searches);

        $results = [];
        foreach ($response['results'] ?? [] as $i => $resp) {
            $options = $queries[$i]['options'] ?? [];
            $perPage = (int)($options['perPage'] ?? 20);
            $limit = (int)($resp['limit'] ?? $perPage);
            $offset = (int)($resp['offset'] ?? 0);

            $rawHits = $resp['hits'] ?? [];
            $hits = $this->normaliseHits($rawHits, 'objectID', '_rankingScore', '_formatted');

            $totalHits = $resp['totalHits'] ?? $resp['estimatedTotalHits'] ?? 0;

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: $limit > 0 ? (int)floor($offset / $limit) + 1 : 1,
                perPage: $limit,
                totalPages: $this->computeTotalPages($totalHits, $limit),
                processingTimeMs: $resp['processingTimeMs'] ?? 0,
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
    public function supportsAtomicSwap(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        $indexName = $this->getIndexName($index);
        $swapIndexName = $this->getIndexName($swapIndex);

        $task = $this->_getClient()->swapIndexes([['indexes' => [$indexName, $swapIndexName]]]);
        $this->_getClient()->waitForTask($task['taskUid']);

        // Delete the temporary index (now contains old data)
        $this->_getClient()->deleteIndex($swapIndexName);
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

        $formattedSearchable = $this->sortByWeight($searchableAttributes);

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
