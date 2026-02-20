<?php

/**
 * Search Index plugin for Craft CMS -- Public REST API controller.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\gql\resolvers\EngineRegistry;
use cogapp\searchindex\gql\resolvers\SearchResolver;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\base\Action;
use yii\caching\TagDependency;
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
     * Cache tag for API search results.
     *
     * Invalidated by Sync service when entries are saved/deleted, so cached
     * search responses stay fresh until content actually changes.
     */
    public const API_CACHE_TAG = 'searchIndex:apiResults';

    /**
     * Browser-level Cache-Control headers per action.
     *
     * Centralised here so all cache rules are visible in one place.
     * Only sets max-age (browser cache); CDN/edge s-maxage is handled by
     * the hosting platform (e.g. Railway, Cloudflare) to avoid duplicate directives.
     * Actions not listed receive no Cache-Control header (dynamic by default).
     */
    private const CACHE_CONTROL = [
        'meta' => 'public, max-age=300',     // 5 min — changes only on schema updates
        'stats' => 'public, max-age=60',     // 1 min — changes on index writes
    ];

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
     * Apply Cache-Control headers from the CACHE_CONTROL map after each action.
     *
     * @inheritdoc
     */
    public function afterAction($action, $result): mixed
    {
        $result = parent::afterAction($action, $result);

        $header = self::CACHE_CONTROL[$action->id] ?? null;
        if ($header !== null && $result instanceof Response) {
            $result->getHeaders()->set('Cache-Control', $header);
        }

        return $result;
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
        if ($maxValuesPerFacet !== null && $maxValuesPerFacet !== '') {
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

        // Geo filter: JSON object with lat, lng, radius
        $geoFilter = $request->getQueryParam('geoFilter');
        if ($geoFilter !== null && $geoFilter !== '') {
            $decoded = $this->_decodeJson($geoFilter, 'geoFilter');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in geoFilter parameter', 400);
            }
            if (is_array($decoded)) {
                $options['geoFilter'] = $decoded;
            }
        }

        // Geo sort: JSON object with lat, lng
        $geoSort = $request->getQueryParam('geoSort');
        if ($geoSort !== null && $geoSort !== '') {
            $decoded = $this->_decodeJson($geoSort, 'geoSort');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in geoSort parameter', 400);
            }
            if (is_array($decoded)) {
                $options['geoSort'] = $decoded;
            }
        }

        // Geo grid: JSON object with precision (and optional field)
        $geoGrid = $request->getQueryParam('geoGrid');
        if ($geoGrid !== null && $geoGrid !== '') {
            $decoded = $this->_decodeJson($geoGrid, 'geoGrid');
            if ($decoded === false) {
                return $this->_errorResponse('Invalid JSON in geoGrid parameter', 400);
            }
            if (is_array($decoded)) {
                $options['geoGrid'] = $decoded;
            }
        }

        // Check cache before vector search (avoids unnecessary embedding API calls on hits).
        $cacheKey = 'searchIndex:api:search:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        // Vector search (only on cache miss — embedding resolution requires API call)
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
            $engine = EngineRegistry::get($index);
            $result = $engine->search($index, $query, $options);

            $rawHits = $result->hits;
            $loadedAssets = [];
            $hits = SearchResolver::injectRoles($rawHits, $index, $loadedAssets);
            $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index, $loadedAssets);

            // Batch-resolve roles and responsive images for geoCluster sample hits
            $geoClusters = !empty($result->geoClusters) ? $result->geoClusters : null;
            if ($geoClusters !== null) {
                $rawSamples = [];
                $sampleIndexes = [];
                foreach ($geoClusters as $ci => $cluster) {
                    if (isset($cluster['hit'])) {
                        $sampleIndexes[] = $ci;
                        $rawSamples[] = $cluster['hit'];
                    }
                }
                if (!empty($rawSamples)) {
                    $clusterAssets = [];
                    $resolved = SearchResolver::injectRoles($rawSamples, $index, $clusterAssets);
                    $resolved = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawSamples, $resolved, $index, $clusterAssets);
                    foreach ($sampleIndexes as $j => $ci) {
                        $geoClusters[$ci]['hit'] = $resolved[$j] ?? $geoClusters[$ci]['hit'];
                    }
                }
            }

            $data = $this->_stripNulls([
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
                'geoClusters' => $geoClusters,
            ]);

            $this->_setApiCache($cacheKey, $data);

            return $this->asJson($data);
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

        $perPage = min(max(1, (int)($request->getQueryParam('perPage') ?? 5)), 50);

        $options = [
            'perPage' => $perPage,
            'page' => 1,
        ];

        $cacheKey = 'searchIndex:api:autocomplete:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        // Auto-detect role fields for minimal payload (uses memoized map)
        $roleFields = $index->getRoleFieldMap();
        if (!empty($roleFields)) {
            $options['attributesToRetrieve'] = array_merge(['objectID'], array_values($roleFields));
        }

        try {
            $engine = EngineRegistry::get($index);
            $result = $engine->search($index, $query, $options);

            $rawHits = $result->hits;
            $loadedAssets = [];
            $hits = SearchResolver::injectRoles($rawHits, $index, $loadedAssets);
            $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index, $loadedAssets);

            $data = [
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'processingTimeMs' => $result->processingTimeMs,
                'hits' => $hits,
            ];

            $this->_setApiCache($cacheKey, $data);

            return $this->asJson($data);
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

        $cacheKey = 'searchIndex:api:facet-values:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        try {
            $engine = EngineRegistry::get($index);
            $result = $engine->searchFacetValues($index, [$facetField], $query, $maxValues, $filters);

            $data = $result[$facetField] ?? [];

            $this->_setApiCache($cacheKey, $data);

            return $this->asJson($data);
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

        $cacheKey = 'searchIndex:api:meta:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        // Reuse memoized role map
        $roles = $index->getRoleFieldMap();

        $facetFields = [];
        $sortOptions = [['label' => 'Relevance', 'value' => '']];

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
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

        $data = [
            'roles' => $roles,
            'facetFields' => $facetFields,
            'sortOptions' => $sortOptions,
        ];

        $this->_setApiCache($cacheKey, $data);

        return $this->asJson($data);
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

        $cacheKey = 'searchIndex:api:document:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        try {
            $engine = EngineRegistry::get($index);
            $document = $engine->getDocument($index, $documentId);

            if ($document === null) {
                return $this->_errorResponse("Document not found: {$documentId}", 404);
            }

            $this->_setApiCache($cacheKey, $document);

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

        $cacheKey = 'searchIndex:api:multi-search:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
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

            if (isset($searchDef['maxValuesPerFacet']) && $searchDef['maxValuesPerFacet'] !== '' && $searchDef['maxValuesPerFacet'] !== null) {
                $options['maxValuesPerFacet'] = (int)$searchDef['maxValuesPerFacet'];
            }

            if (!empty($searchDef['filters']) && is_array($searchDef['filters'])) {
                $options['filters'] = $searchDef['filters'];
            }

            if (!empty($searchDef['sort']) && is_array($searchDef['sort'])) {
                $options['sort'] = $searchDef['sort'];
            }

            if (!empty($searchDef['fields'])) {
                $options['fields'] = is_string($searchDef['fields'])
                    ? array_filter(array_map('trim', explode(',', $searchDef['fields'])))
                    : (array)$searchDef['fields'];
            }

            if (!empty($searchDef['highlight'])) {
                $options['highlight'] = true;
            }

            if (!empty($searchDef['suggest'])) {
                $options['suggest'] = true;
            }

            // Stats: comma-separated or array
            if (!empty($searchDef['stats'])) {
                $options['stats'] = is_string($searchDef['stats'])
                    ? array_filter(array_map('trim', explode(',', $searchDef['stats'])))
                    : (array)$searchDef['stats'];
            }

            // Histogram: JSON config
            if (!empty($searchDef['histogram']) && is_array($searchDef['histogram'])) {
                $options['histogram'] = $searchDef['histogram'];
            }

            // Geo params
            if (!empty($searchDef['geoFilter']) && is_array($searchDef['geoFilter'])) {
                $options['geoFilter'] = $searchDef['geoFilter'];
            }
            if (!empty($searchDef['geoSort']) && is_array($searchDef['geoSort'])) {
                $options['geoSort'] = $searchDef['geoSort'];
            }
            if (!empty($searchDef['geoGrid']) && is_array($searchDef['geoGrid'])) {
                $options['geoGrid'] = $searchDef['geoGrid'];
            }

            try {
                $engine = EngineRegistry::get($index);
                $result = $engine->search($index, $query, $options);

                $rawHits = $result->hits;
                $loadedAssets = [];
                $hits = SearchResolver::injectRoles($rawHits, $index, $loadedAssets);
                $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index, $loadedAssets);

                $results[] = $this->_stripNulls([
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
                    'geoClusters' => !empty($result->geoClusters) ? $result->geoClusters : null,
                ]);
            } catch (\Throwable $e) {
                return $this->_errorResponse("Search failed for index \"{$handle}\": " . $e->getMessage(), 500);
            }
        }

        $this->_setApiCache($cacheKey, $results);

        return $this->asJson($results);
    }

    /**
     * GET /search-index/api/related
     *
     * Find documents related to a given document ("More Like This").
     */
    public function actionRelated(): Response
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

        $perPage = min(max(1, (int)($request->getQueryParam('perPage') ?? 5)), 50);

        // Optional: comma-separated field names to base similarity on
        $fields = [];
        $fieldsParam = $request->getQueryParam('fields');
        if ($fieldsParam !== null && $fieldsParam !== '') {
            $fields = array_filter(array_map('trim', explode(',', $fieldsParam)));
        }

        $cacheKey = 'searchIndex:api:related:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        try {
            $engine = EngineRegistry::get($index);
            $result = $engine->relatedSearch($index, $documentId, $perPage, $fields);

            $rawHits = $result->hits;
            $loadedAssets = [];
            $hits = SearchResolver::injectRoles($rawHits, $index, $loadedAssets);
            $hits = SearchIndex::$plugin->getResponsiveImages()->injectForHits($rawHits, $hits, $index, $loadedAssets);

            $data = [
                'totalHits' => $result->totalHits,
                'hits' => $hits,
                'processingTimeMs' => $result->processingTimeMs,
            ];

            $this->_setApiCache($cacheKey, $data);

            return $this->asJson($data);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Related search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /search-index/api/stats
     *
     * Index statistics: document count, engine name.
     */
    public function actionStats(): Response
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

        $cacheKey = 'searchIndex:api:stats:' . md5($request->getQueryString());
        $cached = $this->_getApiCache($cacheKey);
        if ($cached !== false) {
            return $this->asJson($cached);
        }

        try {
            $engine = EngineRegistry::get($index);
            $documentCount = $engine->getDocumentCount($index);

            $data = [
                'index' => $indexHandle,
                'engine' => $engine::displayName(),
                'documentCount' => $documentCount,
                'indexExists' => $engine->indexExists($index),
            ];

            $this->_setApiCache($cacheKey, $data);

            return $this->asJson($data);
        } catch (\Throwable $e) {
            return $this->_errorResponse('Stats retrieval failed: ' . $e->getMessage(), 500);
        }
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
    private function _decodeJson(string $value, string $paramName = ''): array|false
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : false;
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * Remove null values from a response array to reduce payload size.
     *
     * Only strips top-level keys — nested data (hits, facets) is left intact.
     *
     * @param array $data The response array.
     * @return array The array with null values removed.
     */
    private function _stripNulls(array $data): array
    {
        return array_filter($data, static fn($v) => $v !== null);
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

    /**
     * Fetch a cached API response by key.
     *
     * @return mixed The cached data, or false on miss.
     */
    private function _getApiCache(string $key): mixed
    {
        return Craft::$app->getCache()->get($key);
    }

    /**
     * Store an API response in the cache with the API_CACHE_TAG dependency.
     *
     * Cached forever (TTL 0) — invalidated explicitly by entry save/delete,
     * project config changes, atomic swap, or Craft's Clear Caches utility.
     */
    private function _setApiCache(string $key, array $data): void
    {
        Craft::$app->getCache()->set(
            $key,
            $data,
            0,
            new TagDependency(['tags' => [self::API_CACHE_TAG]]),
        );
    }
}
