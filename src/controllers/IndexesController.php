<?php

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\engines\AlgoliaEngine;
use cogapp\searchindex\engines\ElasticsearchEngine;
use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\engines\MeilisearchEngine;
use cogapp\searchindex\engines\OpenSearchEngine;
use cogapp\searchindex\engines\TypesenseEngine;
use cogapp\searchindex\events\RegisterEngineTypesEvent;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\base\Event;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class IndexesController extends Controller
{
    public const EVENT_REGISTER_ENGINE_TYPES = 'registerEngineTypes';

    public function actionIndex(): Response
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();

        // Get document counts for each index
        $indexData = [];
        foreach ($indexes as $index) {
            $docCount = null;
            try {
                $engineClass = $index->engineType;
                if (class_exists($engineClass)) {
                    $engine = new $engineClass($index->engineConfig ?? []);
                    if ($engine->indexExists($index)) {
                        $docCount = $engine->getDocumentCount($index);
                    }
                }
            } catch (\Throwable $e) {
                // Silently fail for document count (missing SDK, connection issues, etc.)
            }

            $indexData[] = [
                'index' => $index,
                'docCount' => $docCount,
            ];
        }

        return $this->renderTemplate('search-index/indexes/index', [
            'indexes' => $indexData,
        ]);
    }

    public function actionEdit(?int $indexId = null, ?Index $index = null): Response
    {
        if ($index === null) {
            if ($indexId !== null) {
                $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);
                if (!$index) {
                    throw new NotFoundHttpException('Index not found');
                }
            } else {
                $index = new Index();
            }
        }

        $engineTypes = $this->_getEngineTypes();

        // Get sections and entry types for the multi-select
        $sections = Craft::$app->getEntries()->getAllSections();
        $sectionOptions = [];
        $entryTypeOptions = [];

        foreach ($sections as $section) {
            $sectionOptions[] = [
                'label' => $section->name,
                'value' => $section->id,
            ];

            foreach ($section->getEntryTypes() as $entryType) {
                $entryTypeOptions[$section->id][] = [
                    'label' => $entryType->name,
                    'value' => $entryType->id,
                ];
            }
        }

        return $this->renderTemplate('search-index/indexes/edit', [
            'index' => $index,
            'isNew' => !$index->id,
            'engineTypes' => $engineTypes,
            'sectionOptions' => $sectionOptions,
            'entryTypeOptions' => $entryTypeOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $indexId = $request->getBodyParam('indexId');

        if ($indexId) {
            $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);
            if (!$index) {
                throw new NotFoundHttpException('Index not found');
            }
        } else {
            $index = new Index();
        }

        $index->name = $request->getBodyParam('name');
        $index->handle = $request->getBodyParam('handle');
        $index->engineType = $request->getBodyParam('engineType');
        $index->engineConfig = $request->getBodyParam('engineConfig') ?: [];
        $sectionIds = $request->getBodyParam('sectionIds');
        $entryTypeIds = $request->getBodyParam('entryTypeIds');
        $index->siteId = $request->getBodyParam('siteId') ?: null;
        $index->enabled = (bool)$request->getBodyParam('enabled', true);

        // Ensure arrays contain integers (body params can be strings or empty)
        $index->sectionIds = is_array($sectionIds) ? array_map('intval', array_filter($sectionIds)) : [];
        $index->entryTypeIds = is_array($entryTypeIds) ? array_map('intval', array_filter($entryTypeIds)) : [];

        $isNew = !$index->id;

        if (!SearchIndex::$plugin->getIndexes()->saveIndex($index)) {
            Craft::$app->getSession()->setError('Couldn\'t save index.');

            Craft::$app->getUrlManager()->setRouteParams([
                'index' => $index,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice('Index saved.');

        // If new, auto-detect fields and redirect to fields screen
        if ($isNew) {
            $mappings = SearchIndex::$plugin->getFieldMapper()->detectFieldMappings($index);
            $index->setFieldMappings($mappings);
            SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

            return $this->redirect("search-index/indexes/{$index->id}/fields");
        }

        return $this->redirectToPostedUrl($index);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        // Try to delete from the engine too
        try {
            $engineClass = $index->engineType;
            if (class_exists($engineClass)) {
                $engine = new $engineClass($index->engineConfig ?? []);
                $engine->deleteIndex($index);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to delete index from engine: " . $e->getMessage(), __METHOD__);
        }

        SearchIndex::$plugin->getIndexes()->deleteIndex($index);

        return $this->asJson(['success' => true]);
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        SearchIndex::$plugin->getSync()->importIndex($index);

        return $this->asJson(['success' => true]);
    }

    public function actionFlush(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        SearchIndex::$plugin->getSync()->flushIndex($index);

        return $this->asJson(['success' => true]);
    }

    public function actionSettings(): Response
    {
        return $this->renderTemplate('search-index/settings/index', [
            'settings' => SearchIndex::$plugin->getSettings(),
        ]);
    }

    private function _getEngineTypes(): array
    {
        $types = [
            AlgoliaEngine::class,
            ElasticsearchEngine::class,
            MeilisearchEngine::class,
            OpenSearchEngine::class,
            TypesenseEngine::class,
        ];

        $event = new RegisterEngineTypesEvent([
            'types' => $types,
        ]);
        Event::trigger(self::class, self::EVENT_REGISTER_ENGINE_TYPES, $event);

        $engineTypes = [];
        foreach ($event->types as $type) {
            if (is_subclass_of($type, EngineInterface::class) || in_array(EngineInterface::class, class_implements($type), true)) {
                $engineTypes[] = [
                    'class' => $type,
                    'displayName' => $type::displayName(),
                    'configFields' => $type::configFields(),
                ];
            }
        }

        return $engineTypes;
    }
}
