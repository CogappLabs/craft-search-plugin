<?php

/**
 * Search Index plugin for Craft CMS -- Public REST API controller.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\gql\resolvers\SearchResolver;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\filters\Cors;
use yii\web\Response;

/**
 * Public REST API for querying search indexes.
 *
 * All endpoints are anonymous GET requests returning JSON.
 * Mirrors the four GraphQL queries plus document retrieval and multi-search.
 *
 * @author cogapp
 * @since 1.0.0
 */
class ApiController extends Controller
{
    /** @inheritdoc */
    protected array|int|bool $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $origins = Craft::$app->getConfig()->getGeneral()->devMode
            ? ['*']
            : array_values(array_filter(array_map('trim', explode(',', (string)(getenv('SEARCH_INDEX_API_CORS_ORIGINS') ?: '*')))));

        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => $origins,
                'Access-Control-Request-Method' => ['GET', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /search-index/api/search
     *
     * Full search with all options.
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();

        $indexHandle = $request->getQueryParam('index');
        if ($indexHandle === null || $indexHandle === '') {
            return $this->_errorResponse('Missing required parameter: index', 400);
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            return $this->_errorResponse("Index not found: {$indexHandle}", 404);
        }

        $query = (string)($request->getQueryParam('query') ?? '');
        $page = max(1, (int)($request->getQueryParam('page') ?? 1));
        $perPage = min(max(1, (int)($request->getQueryParam('perPage') ?? 20)), 250);

        $options = [
            'page' => $page,
            'perPage' => $perPage,
        ];

        // Sort: JSON object
        $sort = $request->getQueryParam('sort');
        if ($sort !== null && $sort !== '') {
            $decoded = $this->_decodeJson($sort, 'sort');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in sort parameter', 400);
            }
            if (is_array($decoded)) {
                $options['sort'] = $decoded;
            }
        }

        // Facets: comma-separated field names
        $facets = $request->getQueryParam('facets');
        if ($facets !== null && $facets !== '') {
            $options['facets'] = array_filter(array_map('trim', explode(',', $facets)));
        }

        // Max values per facet
        $maxValuesPerFacet = $request->getQueryParam('maxValuesPerFacet');
        if ($maxValuesPerFacet !== null) {
            $options['maxValuesPerFacet'] = (int)$maxValuesPerFacet;
        }

        // Filters: JSON object
        $filters = $request->getQueryParam('filters');
        if ($filters !== null && $filters !== '') {
            $decoded = $this->_decodeJson($filters, 'filters');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in filters parameter', 400);
            }
            if (is_array($decoded)) {
                $options['filters'] = $decoded;
            }
        }

        // Fields: comma-separated field names to search within
        $fields = $request->getQueryParam('fields');
        if ($fields !== null && $fields !== '') {
            $options['fields'] = array_filter(array_map('trim', explode(',', $fields)));
        }

        // Highlight
        if ($this->_isTruthy($request->getQueryParam('highlight'))) {
            $options['highlight'] = true;
        }

        // Suggest
        if ($this->_isTruthy($request->getQueryParam('suggest'))) {
            $options['suggest'] = true;
        }

        // Stats: comma-separated field names
        $stats = $request->getQueryParam('stats');
        if ($stats !== null && $stats !== '') {
            $options['stats'] = array_filter(array_map('trim', explode(',', $stats)));
        }

        // Histogram: JSON config
        $histogram = $request->getQueryParam('histogram');
        if ($histogram !== null && $histogram !== '') {
            $decoded = $this->_decodeJson($histogram, 'histogram');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in histogram parameter', 400);
            }
            if (is_array($decoded)) {
                $options['histogram'] = $decoded;
            }
        }

        // Vector search
        if ($this->_isTruthy($request->getQueryParam('vectorSearch'))) {
            $voyageModel = $request->getQueryParam('voyageModel');
            if ($voyageModel !== null && $voyageModel !== '') {
                $options['voyageModel'] = $voyageModel;
            }

            $embeddingField = $request->getQueryParam('embeddingField');
            if ($embeddingField !== null && $embeddingField !== '') {
                $options['embeddingField'] = $embeddingField;
            }

            if (trim($query) !== '') {
                $options = SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);
            }
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $query, $options);

            $rawHits = $result->hits;
            $hits = SearchResolver::injectRoles($rawHits, $index);
            $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index);

            return $this->asJson([
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'processingTimeMs' => $result->processingTimeMs,
                'hits' => $hits,
                'facets' => !empty($result->facets) ? $result->facets : null,
                'stats' => !empty($result->stats) ? $result->stats : null,
                'histograms' => !empty($result->histograms) ? $result->histograms : null,
                'suggestions' => $result->suggestions,
            ]);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /search-index/api/autocomplete
     *
     * Lightweight autocomplete with role fields only.
     */
    public function actionAutocomplete(): Response
    {
        $request = Craft::$app->getRequest();

        $indexHandle = $request->getQueryParam('index');
        if ($indexHandle === null || $indexHandle === '') {
            return $this->_errorResponse('Missing required parameter: index', 400);
        }

        $query = (string)($request->getQueryParam('query') ?? '');
        if ($query === '') {
            return $this->_errorResponse('Missing required parameter: query', 400);
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            return $this->_errorResponse("Index not found: {$indexHandle}", 404);
        }

        $perPage = min(max(1, (int)($request->getQueryParam('perPage') ?? 5)), 250);

        $options = [
            'perPage' => $perPage,
            'page' => 1,
        ];

        // Auto-detect role fields for minimal payload
        $roleFields = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping->enabled && $mapping->role !== null) {
                $roleFields[$mapping->role] = $mapping->indexFieldName;
            }
        }

        if (!empty($roleFields)) {
            $options['attributesToRetrieve'] = array_merge(['objectID'], array_values($roleFields));
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $query, $options);

            $rawHits = $result->hits;
            $hits = SearchResolver::injectRoles($rawHits, $index);
            $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index);

            return $this->asJson([
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'processingTimeMs' => $result->processingTimeMs,
                'hits' => $hits,
            ]);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Autocomplete failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /search-index/api/facet-values
     *
     * Search within facet values for a specific field.
     */
    public function actionFacetValues(): Response
    {
        $request = Craft::$app->getRequest();

        $indexHandle = $request->getQueryParam('index');
        if ($indexHandle === null || $indexHandle === '') {
            return $this->_errorResponse('Missing required parameter: index', 400);
        }

        $facetField = $request->getQueryParam('facetField');
        if ($facetField === null || $facetField === '') {
            return $this->_errorResponse('Missing required parameter: facetField', 400);
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            return $this->_errorResponse("Index not found: {$indexHandle}", 404);
        }

        $query = (string)($request->getQueryParam('query') ?? '');
        $maxValues = max(1, (int)($request->getQueryParam('maxValues') ?? 10));

        // Filters: JSON object
        $filters = [];
        $filtersParam = $request->getQueryParam('filters');
        if ($filtersParam !== null && $filtersParam !== '') {
            $decoded = $this->_decodeJson($filtersParam, 'filters');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in filters parameter', 400);
            }
            if (is_array($decoded)) {
                $filters = $decoded;
            }
        }

        try {
            $engine = $index->createEngine();
            $result = $engine->searchFacetValues($index, [$facetField], $query, $maxValues, $filters);

            return $this->asJson($result[$facetField] ?? []);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Facet search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /search-index/api/meta
     *
     * Index metadata: roles, facet fields, sort options.
     */
    public function actionMeta(): Response
    {
        $request = Craft::$app->getRequest();

        $indexHandle = $request->getQueryParam('index');
        if ($indexHandle === null || $indexHandle === '') {
            return $this->_errorResponse('Missing required parameter: index', 400);
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            return $this->_errorResponse("Index not found: {$indexHandle}", 404);
        }

        $roles = [];
        $facetFields = [];
        $sortOptions = [['label' => 'Relevance', 'value' => '']];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
            }

            if ($mapping->role !== null) {
                $roles[$mapping->role] = $mapping->indexFieldName;
            }

            if ($mapping->indexFieldType === FieldMapping::TYPE_FACET) {
                $facetFields[] = $mapping->indexFieldName;
            }

            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT, FieldMapping::TYPE_DATE], true)
                && $mapping->role === null
            ) {
                $sortOptions[] = [
                    'label' => $mapping->indexFieldName,
                    'value' => $mapping->indexFieldName,
                ];
            }
        }

        $facetFields = array_values(array_unique($facetFields));

        return $this->asJson([
            'roles' => $roles,
            'facetFields' => $facetFields,
            'sortOptions' => $sortOptions,
        ]);
    }

    /**
     * GET /search-index/api/document
     *
     * Single document retrieval.
     */
    public function actionDocument(): Response
    {
        $request = Craft::$app->getRequest();

        $indexHandle = $request->getQueryParam('index');
        if ($indexHandle === null || $indexHandle === '') {
            return $this->_errorResponse('Missing required parameter: index', 400);
        }

        $documentId = $request->getQueryParam('documentId');
        if ($documentId === null || $documentId === '') {
            return $this->_errorResponse('Missing required parameter: documentId', 400);
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);
        if (!$index) {
            return $this->_errorResponse("Index not found: {$indexHandle}", 404);
        }

        try {
            $engine = $index->createEngine();
            $document = $engine->getDocument($index, $documentId);

            if ($document === null) {
                return $this->_errorResponse("Document not found: {$documentId}", 404);
            }

            return $this->asJson($document);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Document retrieval failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /search-index/api/multi-search
     *
     * Batch search across multiple indexes.
     */
    public function actionMultiSearch(): Response
    {
        $request = Craft::$app->getRequest();

        $searchesParam = $request->getQueryParam('searches');
        if ($searchesParam === null || $searchesParam === '') {
            return $this->_errorResponse('Missing required parameter: searches', 400);
        }

        $decoded = $this->_decodeJson($searchesParam, 'searches');
        if ($decoded === false || !is_array($decoded)) {
            return $this->_errorResponse('Invalid JSON in searches parameter', 400);
        }

        // Validate that it's an array of search objects
        if (empty($decoded) || !isset($decoded[0])) {
            return $this->_errorResponse('searches must be a non-empty JSON array', 400);
        }

        $indexService = SearchIndex::$plugin->getIndexes();
        $results = [];

        foreach ($decoded as $i => $searchDef) {
            $handle = $searchDef['index'] ?? null;
            if ($handle === null || $handle === '') {
                return $this->_errorResponse("Missing index handle in searches[{$i}]", 400);
            }

            $index = $indexService->getIndexByHandle($handle);
            if (!$index) {
                return $this->_errorResponse("Index not found: {$handle}", 404);
            }

            $query = (string)($searchDef['query'] ?? '');
            $options = [
                'page' => max(1, (int)($searchDef['page'] ?? 1)),
                'perPage' => min(max(1, (int)($searchDef['perPage'] ?? 20)), 250),
            ];

            if (!empty($searchDef['facets'])) {
                $options['facets'] = is_string($searchDef['facets'])
                    ? array_filter(array_map('trim', explode(',', $searchDef['facets'])))
                    : (array)$searchDef['facets'];
            }

            if (!empty($searchDef['filters']) && is_array($searchDef['filters'])) {
                $options['filters'] = $searchDef['filters'];
            }

            if (!empty($searchDef['sort']) && is_array($searchDef['sort'])) {
                $options['sort'] = $searchDef['sort'];
            }

            if (!empty($searchDef['highlight'])) {
                $options['highlight'] = true;
            }

            try {
                $engine = $index->createEngine();
                $result = $engine->search($index, $query, $options);

                $rawHits = $result->hits;
                $hits = SearchResolver::injectRoles($rawHits, $index);
                $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index);

                $results[] = [
                    'totalHits' => $result->totalHits,
                    'page' => $result->page,
                    'perPage' => $result->perPage,
                    'totalPages' => $result->totalPages,
                    'processingTimeMs' => $result->processingTimeMs,
                    'hits' => $hits,
                    'facets' => !empty($result->facets) ? $result->facets : null,
                    'suggestions' => $result->suggestions,
                ];
            } catch (\Throwable $e) {
                return $this->_errorResponse("Search failed for index \"{$handle}\": " . $e->getMessage(), 500);
            }
        }

        return $this->asJson($results);
    }

    /**
     * Return a JSON error response with the given HTTP status code.
     */
    private function _errorResponse(string $message, int $statusCode): Response
    {
        $response = $this->asJson(['error' => $message]);
        $response->setStatusCode($statusCode);

        return $response;
    }

    /**
     * Decode a JSON string, returning false on failure.
     *
     * @return array|false The decoded value, or false if invalid JSON.
     */
    private function _decodeJson(string $value, string $paramName): array|false
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : false;
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * Check if a query param value is truthy.
     */
    private function _isTruthy(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
