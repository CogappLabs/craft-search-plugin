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
     * @param \yii\base\Action<\craft\web\Controller> $action
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

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $indexHandle = $request->getRequiredBodyParam('indexHandle');
        $query = $request->getRequiredBodyParam('query');
        $perPage = min(max(1, (int)($request->getBodyParam('perPage') ?: 20)), 250);
        $page = max(1, (int)($request->getBodyParam('page') ?: 1));

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('search-index', 'errors.indexHandleNotFound', ['handle' => $indexHandle]),
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
            if ($embeddingField !== null) {
                $options['embeddingField'] = $embeddingField;
            }

            $voyageModel = $request->getBodyParam('voyageModel');
            if ($voyageModel) {
                $options['voyageModel'] = $voyageModel;
            }

            $options = SearchIndex::$plugin->getVoyageClient()->resolveEmbeddingOptions($index, $query, $options);

            if (!isset($options['embeddingField'])) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('search-index', 'errors.noEmbeddingFieldFoundOnThisIndex'),
                ]);
            }

            if (!isset($options['embedding'])) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('search-index', 'errors.voyageAiEmbeddingFailedCheckYourApiKeyInPluginSettings'),
                ]);
            }
        }

        // For pure vector mode, use empty query so the engine does KNN-only search
        $searchQuery = $searchMode === 'vector' ? '' : $query;

        try {
            $engine = $index->createEngine();
            $result = $engine->search($index, $searchQuery, $options);

            $response = [
                'success' => true,
                'totalHits' => $result->totalHits,
                'page' => $result->page,
                'perPage' => $result->perPage,
                'totalPages' => $result->totalPages,
                'hits' => $result->hits,
            ];

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                $response['raw'] = $result->raw;
            }

            return $this->asJson($response);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('search-index', 'errors.searchFailedError', ['error' => $e->getMessage()]),
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

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $indexHandle = $request->getRequiredBodyParam('indexHandle');
        $documentId = $request->getRequiredBodyParam('documentId');

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($indexHandle);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('search-index', 'errors.indexHandleNotFound', ['handle' => $indexHandle]),
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
                'message' => Craft::t('search-index', 'errors.documentRetrievalFailedError', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
