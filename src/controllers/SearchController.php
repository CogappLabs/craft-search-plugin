<?php

/**
 * Search Index plugin for Craft CMS -- SearchController.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * AJAX controller for search and document retrieval from the CP.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'search';

    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = false;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    /**
     * Search an index via AJAX.
     *
     * @return Response JSON response with search results.
     */
    public function actionSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $indexHandle = $request->getRequiredBodyParam('indexHandle');
        $query = $request->getRequiredBodyParam('query');
        $perPage = (int)($request->getBodyParam('perPage') ?: 20);
        $page = (int)($request->getBodyParam('page') ?: 1);

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'message' => "Index \"{$indexHandle}\" not found.",
            ]);
        }

        $searchMode = $request->getBodyParam('searchMode') ?: 'text';
        $embeddingField = $request->getBodyParam('embeddingField') ?: null;

        $options = [
            'perPage' => $perPage,
            'page' => $page,
        ];

        // Resolve embedding for vector/hybrid search modes
        if (in_array($searchMode, ['vector', 'hybrid'], true) && trim($query) !== '') {
            $embeddingField = $embeddingField ?: $index->getEmbeddingFieldName();

            if ($embeddingField === null) {
                return $this->asJson([
                    'success' => false,
                    'message' => 'No embedding field found on this index.',
                ]);
            }

            $model = $request->getBodyParam('voyageModel') ?: 'voyage-3';
            $embedding = SearchIndex::$plugin->getVoyageClient()->embed($query, $model);

            if ($embedding === null) {
                return $this->asJson([
                    'success' => false,
                    'message' => 'Voyage AI embedding failed. Check your API key in plugin settings.',
                ]);
            }

            $options['embedding'] = $embedding;
            $options['embeddingField'] = $embeddingField;
        }

        // For pure vector mode, use empty query so the engine does KNN-only search
        $searchQuery = $searchMode === 'vector' ? '' : $query;

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $searchQuery, $options);

            return $this->asJson([
                'success' => true,
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'processingTimeMs' => $result->processingTimeMs,
                'hits' => $result->hits,
                'raw' => $result->raw,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Retrieve a single document by ID via AJAX.
     *
     * @return Response JSON response with the document data.
     */
    public function actionGetDocument(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $indexHandle = $request->getRequiredBodyParam('indexHandle');
        $documentId = $request->getRequiredBodyParam('documentId');

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'message' => "Index \"{$indexHandle}\" not found.",
            ]);
        }

        try {
            $engine = $index->createEngine();
            $document = $engine->getDocument($index, $documentId);

            return $this->asJson([
                'success' => true,
                'document' => $document,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => 'Document retrieval failed: ' . $e->getMessage(),
            ]);
        }
    }
}
