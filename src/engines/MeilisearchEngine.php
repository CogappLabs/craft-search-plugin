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
     * Ranking rules with sort prioritised before textual relevance rules.
     *
     * When a `sort` option is provided at query time, this ensures sorted
     * order wins consistently (e.g. date asc/desc) instead of relevance-first.
     */
    private const RANKING_RULES_SORT_FIRST = [
        'sort',
        'words',
        'typo',
        'proximity',
        'attribute',
        'exactness',
    ];

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
    public static function requiredPackage(): string
    {
        return 'meilisearch/meilisearch-php';
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
            'indexName' => [
                'label' => 'Index Name',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Use a specific Meilisearch index name instead of the handle. Supports environment variables.',
            ],
            'indexPrefix' => [
                'label' => 'Index Prefix',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Optional prefix for this index name (e.g. "production_"). Ignored when Index Name is set. Supports environment variables.',
            ],
            'host' => [
                'label' => 'Host',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Meilisearch host URL for this index. Leave blank to use the global setting.',
            ],
            'apiKey' => [
                'label' => 'API Key',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Meilisearch API Key for this index. Leave blank to use the global setting.',
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

            $host = $this->resolveConfigOrGlobal('host', $settings->getEffective('meilisearchHost'));
            $apiKey = $this->resolveConfigOrGlobal('apiKey', $settings->getEffective('meilisearchApiKey'));

            if (empty($host)) {
                throw new \RuntimeException('No Meilisearch host configured. Set it in plugin settings or on the index.');
            }

            $httpClient = new \GuzzleHttp\Client([
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
            $this->_client = new Client($host, $apiKey, $httpClient);
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
            $task = $meilisearchIndex->updateSearchableAttributes($schema['searchableAttributes']);
            $this->waitForTask($task);
        }

        if (!empty($schema['filterableAttributes'])) {
            $task = $meilisearchIndex->updateFilterableAttributes($schema['filterableAttributes']);
            $this->waitForTask($task);
        }

        if (!empty($schema['sortableAttributes'])) {
            $task = $meilisearchIndex->updateSortableAttributes($schema['sortableAttributes']);
            $this->waitForTask($task);
        }

        if (!empty($schema['rankingRules'])) {
            $task = $meilisearchIndex->updateRankingRules($schema['rankingRules']);
            $this->waitForTask($task);
        }
    }

    /**
     * Wait for an async settings task to complete when task metadata is available.
     *
     * @param mixed $task
     * @return void
     */
    private function waitForTask(mixed $task): void
    {
        if (!is_array($task)) {
            return;
        }

        $taskUid = $task['taskUid'] ?? $task['uid'] ?? null;
        if ($taskUid === null) {
            return;
        }

        $this->_getClient()->waitForTask((int)$taskUid);
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->deleteIndex($indexName);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            // Ignore "index not found" errors (already deleted)
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
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
            return $this->handleSchemaError($index);
        }

        return $this->parseSchemaFields($schema);
    }

    /**
     * @inheritdoc
     */
    protected function sampleDocumentsForSchemaInference(Index $index): array
    {
        $indexName = $this->getIndexName($index);
        $response = $this->_getClient()->index($indexName)->search('', ['limit' => 10]);

        return array_values(array_filter($response['hits'] ?? [], 'is_array'));
    }

    /**
     * @inheritdoc
     */
    public function indexDocument(Index $index, int $elementId, array $document): void
    {
        $indexName = $this->getIndexName($index);
        $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_EPOCH_SECONDS);
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
            $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_EPOCH_SECONDS);
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

        $this->_getClient()->index($indexName)->deleteDocument((string)$elementId);
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

        $this->_getClient()->index($indexName)->deleteDocuments(array_map('strval', $elementIds));
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
     * Meilisearch facet value search using substring matching.
     *
     * Meilisearch's native facetSearch endpoint only matches from the start
     * of the facet value string (e.g. "ea" matches "East Sussex" but "sus"
     * does not). Instead, we fetch the full facet distribution and perform
     * case-insensitive substring matching client-side, which handles
     * mid-value queries like "sus" → "East Sussex".
     *
     * @inheritdoc
     */
    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5, array $filters = []): array
    {
        // When filters are active, use the search-based fallback which already
        // handles building engine-native filter syntax via search().
        if (!empty($filters)) {
            return parent::searchFacetValues($index, $facetFields, $query, $maxPerField, $filters);
        }

        $meilisearchIndex = $this->_getClient()->index($this->getIndexName($index));
        $grouped = [];

        // Single request: fetch facet distribution for all fields at once
        $searchResult = $meilisearchIndex->search('', ['facets' => $facetFields, 'limit' => 0]);
        $distribution = $searchResult->getFacetDistribution();
        $queryLower = mb_strtolower($query);

        foreach ($facetFields as $field) {
            $allValues = $distribution[$field] ?? [];
            $values = [];

            if ($query === '') {
                // No query: return top values by count
                foreach (array_slice($allValues, 0, $maxPerField, true) as $facetValue => $count) {
                    $values[] = ['value' => (string)$facetValue, 'count' => (int)$count];
                }
            } else {
                foreach ($allValues as $facetValue => $count) {
                    if (mb_strpos(mb_strtolower((string)$facetValue), $queryLower) !== false) {
                        $values[] = ['value' => (string)$facetValue, 'count' => (int)$count];
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

        [$facets, $filters, $maxValuesPerFacet, $options] = $this->extractFacetParams($options);
        [$statsFields, $options] = $this->extractStatsParams($options);
        [, $options] = $this->extractHistogramParams($options);
        [$sort, $options] = $this->extractSortParams($options);
        [$attributesToRetrieve, $options] = $this->extractAttributesToRetrieve($options);
        [$highlight, $options] = $this->extractHighlightParams($options);
        [, $options] = $this->extractSuggestParams($options);
        [$geoFilter, $geoSort, $options] = $this->extractGeoParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        // Engine-native offset/limit take precedence over unified page/perPage.
        if (!isset($remaining['offset'])) {
            $remaining['offset'] = $this->offsetFromPage($page, $perPage);
        }
        if (!isset($remaining['limit'])) {
            $remaining['limit'] = $perPage;
        }
        if (!isset($remaining['showRankingScore'])) {
            $remaining['showRankingScore'] = true;
        }

        // Unified sort → Meilisearch native sort: ['field:direction', ...]
        if (!empty($sort) && !isset($remaining['sort'])) {
            $remaining['sort'] = $this->buildNativeSortParams($sort);
        }

        // Unified attributesToRetrieve → Meilisearch native param
        if ($attributesToRetrieve !== null && !isset($remaining['attributesToRetrieve'])) {
            $remaining['attributesToRetrieve'] = $attributesToRetrieve;
        }

        // Unified highlight → Meilisearch attributesToHighlight
        if ($highlight !== null && !isset($remaining['attributesToHighlight'])) {
            if ($highlight === true) {
                $remaining['attributesToHighlight'] = ['*'];
            } elseif (is_array($highlight)) {
                $remaining['attributesToHighlight'] = $highlight;
            }
        }

        // Merge stats fields into facets (Meilisearch returns facetStats only for fields in facets)
        $statsOnlyFields = [];
        if (!empty($statsFields)) {
            foreach ($statsFields as $field) {
                if (!in_array($field, $facets, true)) {
                    $facets[] = $field;
                    $statsOnlyFields[] = $field;
                }
            }
        }

        // Unified facets → Meilisearch native facets param
        if (!empty($facets) && !isset($remaining['facets'])) {
            $remaining['facets'] = $facets;
        }

        // Unified filters → Meilisearch filter string
        if (!empty($filters) && !isset($remaining['filter'])) {
            $remaining['filter'] = $this->buildNativeFilterParams($filters, $index);
        }

        // Geo filter → Meilisearch _geoRadius filter
        if ($geoFilter !== null) {
            $radiusMetres = $this->parseRadiusToMetres($geoFilter['radius']);
            $geoClause = "_geoRadius({$geoFilter['lat']}, {$geoFilter['lng']}, {$radiusMetres})";
            if (isset($remaining['filter']) && $remaining['filter'] !== '') {
                $remaining['filter'] .= ' AND ' . $geoClause;
            } else {
                $remaining['filter'] = $geoClause;
            }
        }

        // Geo sort → Meilisearch _geoPoint sort
        if ($geoSort !== null) {
            $geoSortValue = "_geoPoint({$geoSort['lat']}, {$geoSort['lng']}):asc";
            if (isset($remaining['sort']) && is_array($remaining['sort'])) {
                array_unshift($remaining['sort'], $geoSortValue);
            } else {
                $remaining['sort'] = [$geoSortValue];
            }
        }

        $response = $this->_getClient()->index($indexName)->search($query, $remaining);

        // Normalise _formatted into unified highlights (detect fields with highlight markers)
        $rawHits = array_map([$this, 'normaliseRawHit'], $response->getHits());
        $hits = $this->normaliseHits($rawHits, 'objectID', '_rankingScore', null);

        $totalHits = $response->getTotalHits() ?? $response->getEstimatedTotalHits() ?? 0;
        $actualPerPage = $remaining['limit'];

        // Normalise Meilisearch facetDistribution → unified shape
        $rawResponse = $response->toArray();

        // SDK toArray() omits facetStats — include it from the dedicated getter
        $facetStats = $response->getFacetStats();
        if (!empty($facetStats)) {
            $rawResponse['facetStats'] = $facetStats;
        }

        $normalisedFacets = $this->normaliseRawFacets($rawResponse);

        // Remove stats-only fields from facets to avoid polluting the facet UI
        foreach ($statsOnlyFields as $field) {
            unset($normalisedFacets[$field]);
        }

        // Meilisearch returns all facet values; truncate if maxValuesPerFacet is set
        if ($maxValuesPerFacet !== null) {
            foreach ($normalisedFacets as $field => $values) {
                $normalisedFacets[$field] = array_slice($values, 0, $maxValuesPerFacet);
            }
        }

        // Normalise Meilisearch facetStats → unified stats shape
        $normalisedStats = $this->normaliseRawStats($rawResponse, $statsFields);

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: $actualPerPage > 0 ? (int)floor($remaining['offset'] / $actualPerPage) + 1 : 1,
            perPage: $actualPerPage,
            totalPages: $this->computeTotalPages($totalHits, $actualPerPage),
            processingTimeMs: $response->getProcessingTimeMs(),
            facets: $normalisedFacets,
            stats: $normalisedStats,
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
                $remaining['offset'] = $this->offsetFromPage($page, $perPage);
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

            $rawHits = array_map([$this, 'normaliseRawHit'], $resp['hits'] ?? []);
            $hits = $this->normaliseHits($rawHits, 'objectID', '_rankingScore', null);

            $totalHits = $resp['totalHits'] ?? $resp['estimatedTotalHits'] ?? 0;

            // Normalise facetDistribution → unified shape
            $normalisedFacets = $this->normaliseRawFacets((array)$resp);

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: $limit > 0 ? (int)floor($offset / $limit) + 1 : 1,
                perPage: $limit,
                totalPages: $this->computeTotalPages($totalHits, $limit),
                processingTimeMs: $resp['processingTimeMs'] ?? 0,
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

        // Meilisearch PHP client expects a list of index-name pairs and wraps each
        // pair into {"indexes":[...]} internally.
        $task = $this->_getClient()->swapIndexes([[$indexName, $swapIndexName]]);
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
                    // Title fields should also be sortable (A-Z sorting)
                    if ($mapping->role === FieldMapping::ROLE_TITLE) {
                        $sortableAttributes[] = $fieldName;
                    }
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

        $schema['rankingRules'] = self::RANKING_RULES_SORT_FIRST;

        return $schema;
    }

    /**
     * Convert unified sort to Meilisearch native: `['field:direction', ...]`.
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
            $result[] = "{$field}:{$direction}";
        }
        return $result;
    }

    /**
     * Convert unified filters to Meilisearch filter string.
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
                    $parts[] = "{$field} >= " . (float)$value['min'];
                }
                if (isset($value['max']) && $value['max'] !== '' && is_numeric($value['max'])) {
                    $parts[] = "{$field} <= " . (float)$value['max'];
                }
                if (empty($parts)) {
                    continue;
                }
                $clauses[] = '(' . implode(' AND ', $parts) . ')';
            } elseif (is_array($value)) {
                // OR within same field — escape backslashes first, then quotes
                $orParts = array_map(fn($v) => "{$field} = \"" . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . "\"", $value);
                $clauses[] = '(' . implode(' OR ', $orParts) . ')';
            } else {
                $clauses[] = "{$field} = \"" . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value) . "\"";
            }
        }
        return implode(' AND ', $clauses);
    }

    /**
     * Normalise a Meilisearch hit: extract highlights from _formatted.
     *
     * @inheritdoc
     */
    protected function normaliseRawHit(array $hit): array
    {
        $formatted = $hit['_formatted'] ?? [];
        $highlights = [];
        foreach ($formatted as $field => $value) {
            if (is_string($value) && str_contains($value, '<em>')) {
                $highlights[$field] = $value;
            }
        }
        $hit['_highlights'] = $this->normaliseHighlightData($highlights);
        return $hit;
    }

    /**
     * Normalise Meilisearch facetDistribution → unified shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawFacets(array $response): array
    {
        return $this->normaliseFacetMapResponse($response['facetDistribution'] ?? []);
    }

    /**
     * Normalise Meilisearch facetStats → unified stats shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawStats(array $response, array $statsFields = []): array
    {
        $normalised = [];
        foreach ($statsFields as $field) {
            $statsData = $response['facetStats'][$field] ?? null;
            if ($statsData !== null && isset($statsData['min'], $statsData['max'])) {
                $normalised[$field] = [
                    'min' => (float)$statsData['min'],
                    'max' => (float)$statsData['max'],
                ];
            }
        }
        return $normalised;
    }

    /**
     * @inheritdoc
     */
    protected function parseSchemaFields(array $schema): array
    {
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
    protected function handleSchemaError(Index $index): array
    {
        if ($index->isReadOnly()) {
            return $this->inferSchemaFieldsFromSampleDocuments($index);
        }
        return [];
    }

    /**
     * Meilisearch uses direct rename (same as default) — `{handle}_swap`.
     *
     * @inheritdoc
     */
    public function buildSwapHandle(Index $index): string
    {
        return $index->handle . '_swap';
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
