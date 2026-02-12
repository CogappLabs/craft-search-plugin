<?php

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use Meilisearch\Client;

class MeilisearchEngine extends AbstractEngine
{
    private ?Client $_client = null;

    public static function displayName(): string
    {
        return 'Meilisearch';
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
     * Returns the configured Meilisearch Client instance.
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            $settings = SearchIndex::$plugin->getSettings();

            $host = App::parseEnv($settings->meilisearchHost);
            $apiKey = App::parseEnv($settings->meilisearchApiKey);

            $this->_client = new Client($host, $apiKey);
        }

        return $this->_client;
    }

    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $task = $this->_getClient()->createIndex($indexName, ['primaryKey' => 'objectID']);
        $this->_getClient()->waitForTask($task['taskUid']);
    }

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

    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->deleteIndex($indexName);
    }

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

    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $document['objectID'] = (string)$elementId;

        $this->_getClient()->index($indexName)->addDocuments([$document]);
    }

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

    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->index($indexName)->deleteDocument($elementId);
    }

    public function deleteDocuments(Index $index, array $elementIds): void
    {
        if (empty($elementIds)) {
            return;
        }

        $indexName = $this->getIndexName($index);

        $this->_getClient()->index($indexName)->deleteDocuments($elementIds);
    }

    public function flushIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        $this->_getClient()->index($indexName)->deleteAllDocuments();
    }

    public function search(Index $index, string $query, array $options = []): array
    {
        $indexName = $this->getIndexName($index);

        $response = $this->_getClient()->index($indexName)->search($query, $options);

        return [
            'hits' => $response->getHits(),
            'totalHits' => $response->getTotalHits() ?? $response->getEstimatedTotalHits() ?? 0,
            'processingTimeMs' => $response->getProcessingTimeMs(),
            'query' => $response->getQuery(),
        ];
    }

    public function getDocumentCount(Index $index): int
    {
        $indexName = $this->getIndexName($index);

        $stats = $this->_getClient()->index($indexName)->stats();

        return $stats['numberOfDocuments'] ?? 0;
    }

    public function getAllDocumentIds(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $ids = [];
        $offset = 0;
        $limit = 1000;

        $meilisearchIndex = $this->_getClient()->index($indexName);

        while (true) {
            $response = $meilisearchIndex->getDocuments([
                'fields' => ['objectID'],
                'offset' => $offset,
                'limit' => $limit,
            ]);

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
