<?php

/**
 * Algolia search engine implementation.
 */

namespace cogapp\searchindex\engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\SearchIndex;
use Craft;

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
    private ?SearchClient $_searchClient = null;

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
    public static function requiredPackage(): string
    {
        return 'algolia/algoliasearch-client-php';
    }

    /**
     * @inheritdoc
     */
    public static function isClientInstalled(): bool
    {
        return class_exists(SearchClient::class);
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
            'appId' => [
                'label' => 'App ID',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Algolia App ID for this index. Leave blank to use the global setting.',
            ],
            'apiKey' => [
                'label' => 'Admin API Key',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Algolia Admin API Key for this index. Leave blank to use the global setting.',
            ],
            'searchApiKey' => [
                'label' => 'Search API Key',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Algolia Search API Key for this index. Required for read-only indexes without an Admin API Key.',
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

            [$appId, $adminKey] = $this->_resolveAdminCredentials();
            $apiKey = $adminKey;

            if (empty($appId) || empty($apiKey)) {
                throw new \RuntimeException('Algolia App ID and API Key are required. Set them in plugin settings or on the index.');
            }

            $this->_client = SearchClient::create($appId, $apiKey);
        }

        return $this->_client;
    }

    /**
     * Return an Algolia client configured for search-only operations.
     *
     * @return SearchClient
     */
    private function _getSearchClient(): SearchClient
    {
        if ($this->_searchClient === null) {
            if (!class_exists(SearchClient::class)) {
                throw new \RuntimeException('The Algolia engine requires the "algolia/algoliasearch-client-php" package. Install it with: composer require algolia/algoliasearch-client-php');
            }

            [$appId, $searchKey] = $this->_resolveSearchCredentials();
            $apiKey = $searchKey;

            if (empty($appId) || empty($apiKey)) {
                throw new \RuntimeException('Algolia App ID and API Key are required. Set them in plugin settings or on the index.');
            }

            $this->_searchClient = SearchClient::create($appId, $apiKey);
        }

        return $this->_searchClient;
    }

    /**
     * Return the best available client for read operations.
     *
     * Tries the admin client first; if no admin key is configured, falls back
     * to the search-only client. This allows read-only indexes to work with
     * just a Search API Key.
     *
     * @return SearchClient
     */
    private function _getReadClient(): SearchClient
    {
        [$appId, $adminKey] = $this->_resolveAdminCredentials();

        if ($appId !== '' && $adminKey !== '') {
            return $this->_getClient();
        }

        return $this->_getSearchClient();
    }

    /**
     * Resolve Algolia App ID and admin key credentials.
     *
     * @return array{string, string} [appId, adminKey]
     */
    private function _resolveAdminCredentials(): array
    {
        $settings = SearchIndex::$plugin->getSettings();

        $appId = $this->resolveConfigOrGlobal('appId', $settings->algoliaAppId);
        $adminKey = $this->resolveConfigOrGlobal('apiKey', $settings->algoliaApiKey);

        return [$appId, $adminKey];
    }

    /**
     * Resolve Algolia App ID and search key credentials.
     *
     * @return array{string, string} [appId, searchKey]
     */
    private function _resolveSearchCredentials(): array
    {
        $settings = SearchIndex::$plugin->getSettings();

        $appId = $this->resolveConfigOrGlobal('appId', $settings->algoliaAppId);
        $searchKey = $this->resolveConfigOrGlobal('searchApiKey', $settings->algoliaSearchApiKey);

        return [$appId, $searchKey];
    }

    /**
     * @inheritdoc
     */
    public function createIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        // Pass raw array — Algolia SDK v4 ApiWrapper cannot array_merge() model objects.
        $this->_getClient()->setSettings($indexName, $schema);
    }

    /**
     * @inheritdoc
     */
    public function updateIndexSettings(Index $index): void
    {
        $indexName = $this->getIndexName($index);
        $schema = $this->buildSchema($index->getFieldMappings());

        $this->_getClient()->setSettings($indexName, $schema);
    }

    /**
     * @inheritdoc
     */
    public function deleteIndex(Index $index): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->_getClient()->deleteIndex($indexName);
        } catch (\Algolia\AlgoliaSearch\Exceptions\NotFoundException $e) {
            // Ignore — index already deleted
        }
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);

        // Try admin client (getSettings) first; fall back to search client
        // for read-only indexes that only have a Search API Key.
        try {
            $this->_getClient()->getSettings($indexName);
            return true;
        } catch (\Exception $e) {
            // Admin key may be missing — try a lightweight search instead.
            try {
                $this->_getSearchClient()->searchSingleIndex($indexName, [
                    'query' => '',
                    'hitsPerPage' => 0,
                    'page' => 0,
                ]);
                return true;
            } catch (\Exception $e2) {
                return false;
            }
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

        $response = $this->_getReadClient()->searchSingleIndex($indexName, [
            'query' => '',
            'hitsPerPage' => 10,
            'page' => 0,
        ]);

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
            $document = $this->normaliseDateFields($index, $document, self::DATE_FORMAT_EPOCH_SECONDS);
            // Skip documents without objectID to prevent auto-generated IDs
            if (!isset($document['objectID'])) {
                continue;
            }
            $document['objectID'] = (string)$document['objectID'];
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

        // Try admin client (getObject) first; fall back to search for
        // read-only indexes that only have a Search API Key.
        try {
            $response = $this->_getClient()->getObject($indexName, $documentId);
            return (array)$response;
        } catch (\Exception $e) {
            // Admin key may be missing — fall back to search by objectID.
            try {
                $result = $this->_getSearchClient()->searchSingleIndex($indexName, [
                    'query' => '',
                    'filters' => 'objectID:"' . addcslashes($documentId, '"\\') . '"',
                    'hitsPerPage' => 1,
                ]);

                foreach ($result['hits'] ?? [] as $hit) {
                    if (($hit['objectID'] ?? null) === $documentId) {
                        return (array)$hit;
                    }
                }
            } catch (\Exception $e2) {
                // Fall through
            }

            return null;
        }
    }

    /**
     * Native Algolia facet value search using the searchForFacetValues endpoint.
     *
     * Searches directly within facet values with typo tolerance.
     *
     * Requires facet attributes to be declared as `searchable(attribute)` in
     * Algolia's `attributesForFaceting`. Falls back to the AbstractEngine
     * approach (document search + facets) for fields that don't support it.
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

        $indexName = $this->getIndexName($index);
        $client = $this->_getReadClient();
        $grouped = [];
        $fallbackFields = [];

        foreach ($facetFields as $field) {
            try {
                $response = $client->searchForFacetValues($indexName, $field, [
                    'facetQuery' => $query,
                    'maxFacetHits' => $maxPerField,
                ]);

                $values = [];
                foreach ($response->getFacetHits() ?? [] as $hit) {
                    $hitValue = is_array($hit) ? ($hit['value'] ?? '') : $hit->getValue();
                    $hitCount = is_array($hit) ? ($hit['count'] ?? 0) : $hit->getCount();
                    $values[] = [
                        'value' => (string)$hitValue,
                        'count' => (int)$hitCount,
                    ];
                }

                if (!empty($values)) {
                    $grouped[$field] = $values;
                }
            } catch (\Throwable $e) {
                // Field not declared as searchable(attr) — fall back to document search
                $fallbackFields[] = $field;
            }
        }

        // Use AbstractEngine fallback for fields that don't support native facet search
        if (!empty($fallbackFields)) {
            $fallback = parent::searchFacetValues($index, $fallbackFields, $query, $maxPerField);
            $grouped = array_merge($grouped, $fallback);
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
        [$statsFields, $options] = $this->extractStatsParams($options);
        [, $options] = $this->extractHistogramParams($options);
        [$sort, $options] = $this->extractSortParams($options);
        [$attributesToRetrieve, $options] = $this->extractAttributesToRetrieve($options);
        [$highlight, $options] = $this->extractHighlightParams($options);
        [, $options] = $this->extractSuggestParams($options);
        [$page, $perPage, $remaining] = $this->extractPaginationParams($options, 20);

        // Translate to Algolia's 0-based page and hitsPerPage unless caller
        // already provided engine-native pagination keys.
        if (!isset($remaining['hitsPerPage'])) {
            $remaining['hitsPerPage'] = $perPage;
        }
        if (!array_key_exists('page', $remaining)) {
            $remaining['page'] = $page - 1; // Algolia pages are 0-based
        }

        // Unified attributesToRetrieve → Algolia native param (same name)
        if ($attributesToRetrieve !== null && !isset($remaining['attributesToRetrieve'])) {
            $remaining['attributesToRetrieve'] = $attributesToRetrieve;
        }

        // Unified highlight → Algolia attributesToHighlight
        if ($highlight !== null && !isset($remaining['attributesToHighlight'])) {
            if ($highlight === true) {
                $remaining['attributesToHighlight'] = ['*'];
            } elseif (is_array($highlight)) {
                $remaining['attributesToHighlight'] = $highlight;
            }
        }

        // Unified facets → Algolia native facets param
        if (!empty($facets) && !isset($remaining['facets'])) {
            $remaining['facets'] = $facets;
        }

        // Separate range filters from equality filters
        $rangeFilters = [];
        $equalityFilters = [];
        foreach ($filters as $field => $value) {
            if ($this->isRangeFilter($value)) {
                $rangeFilters[$field] = $value;
            } else {
                $equalityFilters[$field] = $value;
            }
        }

        // Unified equality filters → Algolia facetFilters
        if (!empty($equalityFilters) && !isset($remaining['facetFilters'])) {
            $remaining['facetFilters'] = $this->buildNativeFilterParams($equalityFilters, $index);
        }

        // Range filters → Algolia numericFilters
        if (!empty($rangeFilters) && !isset($remaining['numericFilters'])) {
            $numericParts = [];
            foreach ($rangeFilters as $field => $range) {
                if (isset($range['min']) && $range['min'] !== '' && is_numeric($range['min'])) {
                    $numericParts[] = "{$field} >= " . (float)$range['min'];
                }
                if (isset($range['max']) && $range['max'] !== '' && is_numeric($range['max'])) {
                    $numericParts[] = "{$field} <= " . (float)$range['max'];
                }
            }
            if (!empty($numericParts)) {
                $remaining['numericFilters'] = $numericParts;
            }
        }

        $searchParams = array_merge(['query' => $query], $remaining);

        $response = $this->_getReadClient()->searchSingleIndex($indexName, $searchParams);

        $totalHits = $response['nbHits'] ?? 0;

        // Normalise Algolia _highlightResult into unified { field: [fragments] } format
        $rawHits = array_map([$this, 'normaliseRawHit'], $response['hits'] ?? []);
        $hits = $this->normaliseHits($rawHits, 'objectID', '_score', null);

        // Normalise Algolia facets → unified shape
        $normalisedFacets = $this->normaliseRawFacets((array)$response);

        return new SearchResult(
            hits: $hits,
            totalHits: $totalHits,
            page: ($response['page'] ?? 0) + 1, // Convert 0-based → 1-based
            perPage: $response['hitsPerPage'] ?? $perPage,
            totalPages: $response['nbPages'] ?? $this->computeTotalPages($totalHits, $perPage),
            processingTimeMs: $response['processingTimeMS'] ?? 0,
            facets: $normalisedFacets,
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

            $requests[] = array_merge([
                'indexName' => $indexName,
                'query' => $query['query'],
                'type' => 'default',
            ], $remaining);
        }

        // Pass raw arrays — Algolia SDK v4 ApiWrapper cannot array_merge() model objects.
        $response = $this->_getReadClient()->search(['requests' => $requests]);

        $results = [];
        foreach ($response['results'] ?? [] as $i => $resp) {
            $options = $queries[$i]['options'] ?? [];
            $perPage = (int)($options['perPage'] ?? 20);

            $totalHits = $resp['nbHits'] ?? 0;

            $rawHits = array_map([$this, 'normaliseRawHit'], $resp['hits'] ?? []);
            $hits = $this->normaliseHits($rawHits, 'objectID', '_score', null);

            // Normalise Algolia facets → unified shape
            $normalisedFacets = $this->normaliseRawFacets((array)$resp);

            $results[] = new SearchResult(
                hits: $hits,
                totalHits: $totalHits,
                page: ($resp['page'] ?? 0) + 1,
                perPage: $resp['hitsPerPage'] ?? $perPage,
                totalPages: $resp['nbPages'] ?? $this->computeTotalPages($totalHits, $perPage),
                processingTimeMs: $resp['processingTimeMS'] ?? 0,
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

        $response = $this->_getReadClient()->searchSingleIndex($indexName, [
            'query' => '',
            'hitsPerPage' => 0,
        ]);

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

        // Pass raw arrays — Algolia SDK v4 ApiWrapper cannot array_merge() model objects.
        $response = $this->_getClient()->browse($indexName, [
            'attributesToRetrieve' => [],
        ]);

        foreach ($response['hits'] ?? [] as $hit) {
            $ids[] = $hit['objectID'];
        }

        while (!empty($response['cursor'])) {
            $response = $this->_getClient()->browse($indexName, [
                'attributesToRetrieve' => [],
                'cursor' => $response['cursor'],
            ]);

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
     * Convert unified filters to Algolia facetFilters format.
     *
     * @inheritdoc
     */
    protected function buildNativeFilterParams(array $filters, Index $index): mixed
    {
        if (empty($filters)) {
            return [];
        }

        $facetFilters = [];
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // OR within same field: [['field:val1', 'field:val2']]
                $facetFilters[] = array_map(fn($v) => "{$field}:{$v}", $value);
            } else {
                $facetFilters[] = "{$field}:{$value}";
            }
        }
        return $facetFilters;
    }

    /**
     * Normalise an Algolia hit: extract _highlightResult.
     *
     * @inheritdoc
     */
    protected function normaliseRawHit(array $hit): array
    {
        $hit['_highlights'] = $this->normaliseHighlightData($hit['_highlightResult'] ?? []);
        return $hit;
    }

    /**
     * Normalise Algolia facets: `{ field: { value: count } }` → unified shape.
     *
     * @inheritdoc
     */
    protected function normaliseRawFacets(array $response): array
    {
        return $this->normaliseFacetMapResponse($response['facets'] ?? []);
    }

    /**
     * @inheritdoc
     */
    protected function parseSchemaFields(array $schema): array
    {
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
    protected function handleSchemaError(Index $index): array
    {
        if ($index->isReadOnly()) {
            return $this->inferSchemaFieldsFromSampleDocuments($index);
        }
        return [];
    }

    /**
     * Normalise Algolia's _highlightResult format into unified { field: [fragments] }.
     *
     * Algolia format: `{ field: { value: 'text', matchLevel: 'full' } }`
     * Array fields: `{ field: [{ value: 'text', matchLevel: 'full' }, ...] }`
     *
     * @param array $highlightData Raw Algolia _highlightResult data.
     * @return array<string, string[]> Normalised highlights.
     */
    protected function normaliseHighlightData(array $highlightData): array
    {
        $normalised = [];
        foreach ($highlightData as $field => $data) {
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['value']) && is_string($data['value'])) {
                // Single value: { value: 'text', matchLevel: 'full' }
                if (($data['matchLevel'] ?? 'none') !== 'none') {
                    $normalised[$field] = [$data['value']];
                }
            } elseif (isset($data[0]) && is_array($data[0])) {
                // Array of values: [{ value: 'text', matchLevel: 'full' }, ...]
                $fragments = [];
                foreach ($data as $item) {
                    if (is_array($item) && isset($item['value']) && is_string($item['value'])
                        && ($item['matchLevel'] ?? 'none') !== 'none') {
                        $fragments[] = $item['value'];
                    }
                }
                if (!empty($fragments)) {
                    $normalised[$field] = $fragments;
                }
            }
        }
        return $normalised;
    }

    /**
     * @inheritdoc
     */
    public function supportsAtomicSwap(): bool
    {
        return true;
    }

    /**
     * Atomically replace the production index with the swap index using Algolia's move operation.
     *
     * The move operation replaces the destination index with the source and deletes the source.
     *
     * @inheritdoc
     */
    public function swapIndex(Index $index, Index $swapIndex): void
    {
        $prodName = $this->getIndexName($index);
        $swapName = $this->getIndexName($swapIndex);

        // Pass raw array — Algolia SDK v4 ApiWrapper cannot array_merge() model objects.
        $response = $this->_getClient()->operationIndex(
            $swapName,
            [
                'operation' => 'move',
                'destination' => $prodName,
            ],
        );

        // Wait for the move task to complete
        $this->_getClient()->waitForTask($prodName, $response['taskID']);
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        $mode = (string)($this->config['__mode'] ?? Index::MODE_SYNCED);
        $handle = trim((string)($this->config['__handle'] ?? ''));

        // Read-only mode may intentionally use only a Search API key.
        // In that case, validate by running a lightweight search against the index.
        if ($mode === Index::MODE_READONLY) {
            [$appId, $adminKey] = $this->_resolveAdminCredentials();
            [$searchAppId, $searchKey] = $this->_resolveSearchCredentials();

            if ($adminKey === '' && $searchAppId !== '' && $searchKey !== '') {
                if ($handle === '') {
                    return false;
                }

                try {
                    $index = new Index();
                    $index->handle = $handle;
                    $indexName = $this->getIndexName($index);

                    $this->_getSearchClient()->searchSingleIndex($indexName, [
                        'query' => '',
                        'hitsPerPage' => 0,
                        'page' => 0,
                    ]);

                    return true;
                } catch (\Throwable $e) {
                    Craft::warning('Algolia read-only search-key connection test failed: ' . $e->getMessage(), __METHOD__);
                    return false;
                }
            }
        }

        try {
            $this->_getClient()->listIndices();
            return true;
        } catch (\Exception $e) {
            Craft::warning('Algolia connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
