<?php

/**
 * Search Index plugin for Craft CMS -- IndexesController.
 */

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
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP controller for managing search indexes (list, create, edit, delete, sync, flush).
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexesController extends Controller
{
    /** Fired to allow third-party plugins to register additional search engine types. */
    public const EVENT_REGISTER_ENGINE_TYPES = 'registerEngineTypes';

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        // The search page is accessible to all CP users; everything else
        // requires the manageIndexes permission.
        if ($action->id !== 'search-page') {
            $this->requirePermission('searchIndex-manageIndexes');
        }

        return true;
    }

    /**
     * Display the index listing page with document counts.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();

        // Get document counts for each index
        $indexData = [];
        foreach ($indexes as $index) {
            $docCount = null;
            $connected = null;
            try {
                if (class_exists($index->engineType)) {
                    $engine = $index->createEngine();
                    $connected = $engine->testConnection();
                    if ($connected && $engine->indexExists($index)) {
                        $docCount = $engine->getDocumentCount($index);
                    }
                }
            } catch (\Throwable $e) {
                $connected = false;
                Craft::warning("Failed to connect to engine for index \"{$index->handle}\": {$e->getMessage()}", __METHOD__);
            }

            // Resolve section names for the template
            $sectionNames = [];
            if (!empty($index->sectionIds)) {
                foreach ($index->sectionIds as $sectionId) {
                    $section = Craft::$app->getEntries()->getSectionById($sectionId);
                    if ($section) {
                        $sectionNames[] = $section->name;
                    }
                }
            }

            $indexData[] = [
                'index' => $index,
                'docCount' => $docCount,
                'connected' => $connected,
                'sectionNames' => $sectionNames,
            ];
        }

        return $this->renderTemplate('search-index/indexes/index', [
            'indexes' => $indexData,
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * Display the index edit form (new or existing).
     *
     * @param int|null   $indexId
     * @param Index|null $index Pre-populated index model (e.g. after validation failure).
     * @return Response
     */
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

        // Build site options for the dropdown
        $siteOptions = [['label' => Craft::t('search-index', 'Primary site'), 'value' => '']];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = ['label' => $site->name, 'value' => $site->id];
        }

        $isNew = !$index->id;
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $response = $this->asCpScreen()
            ->title($isNew ? Craft::t('search-index', 'New Index') : Craft::t('search-index', 'Edit Index: {name}', ['name' => $index->name]))
            ->selectedSubnavItem('indexes')
            ->addCrumb(Craft::t('search-index', 'Search Indexes'), 'search-index/indexes')
            ->formAttributes([
                'id' => 'search-index-edit-form',
                'data-is-new' => $isNew ? 'true' : 'false',
            ])
            ->contentTemplate('search-index/indexes/_edit', [
                'index' => $index,
                'isNew' => $isNew,
                'engineTypes' => $engineTypes,
                'sectionOptions' => $sectionOptions,
                'entryTypeOptions' => $entryTypeOptions,
                'siteOptions' => $siteOptions,
                'allowAdminChanges' => $allowAdminChanges,
            ]);

        if ($allowAdminChanges) {
            $response
                ->action('search-index/indexes/save')
                ->redirectUrl('search-index/indexes/{id}')
                ->addAltAction(Craft::t('search-index', 'Save and continue editing'), [
                    'redirect' => 'search-index/indexes/{id}',
                    'shortcut' => true,
                    'retainScroll' => true,
                ]);
        }

        return $response;
    }

    /**
     * Save an index from POST data. Auto-detects field mappings for new indexes.
     *
     * @return Response|null Null when validation fails and the form is re-rendered.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

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
        $index->mode = $request->getBodyParam('mode', Index::MODE_SYNCED);
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

        // If new synced index, auto-detect fields and redirect to fields screen
        if ($isNew && !$index->isReadOnly()) {
            $mappings = SearchIndex::$plugin->getFieldMapper()->detectFieldMappings($index);
            $index->setFieldMappings($mappings);
            SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

            return $this->redirect("search-index/indexes/{$index->id}/fields");
        }

        // If new read-only index, auto-detect schema fields and redirect to field roles
        if ($isNew && $index->isReadOnly()) {
            try {
                $mappings = SearchIndex::$plugin->getFieldMapper()->detectSchemaFieldMappings($index);
                $index->setFieldMappings($mappings);
                SearchIndex::$plugin->getIndexes()->saveIndex($index, false);
            } catch (\Throwable $e) {
                Craft::warning("Could not auto-detect schema fields for read-only index \"{$index->handle}\": {$e->getMessage()}", __METHOD__);
            }

            return $this->redirect("search-index/indexes/{$index->id}/fields");
        }

        return $this->redirectToPostedUrl($index);
    }

    /**
     * Delete an index (and its engine counterpart) via AJAX.
     *
     * @return Response JSON response.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        // Try to delete from the engine too
        try {
            if (class_exists($index->engineType)) {
                $engine = $index->createEngine();
                $engine->deleteIndex($index);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to delete index from engine: " . $e->getMessage(), __METHOD__);
        }

        SearchIndex::$plugin->getIndexes()->deleteIndex($index);

        return $this->asJson(['success' => true]);
    }

    /**
     * Queue a full import (sync) for an index via AJAX.
     *
     * @return Response JSON response.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        if ($index->isReadOnly()) {
            return $this->asJson(['success' => false, 'message' => 'Cannot sync a read-only index.']);
        }

        SearchIndex::$plugin->getSync()->importIndex($index);

        return $this->asJson(['success' => true]);
    }

    /**
     * Flush all documents from an index via AJAX.
     *
     * @return Response JSON response.
     */
    public function actionFlush(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        if ($index->isReadOnly()) {
            return $this->asJson(['success' => false, 'message' => 'Cannot flush a read-only index.']);
        }

        SearchIndex::$plugin->getSync()->flushIndex($index);

        return $this->asJson(['success' => true]);
    }

    /**
     * Test the connection to a search engine via AJAX.
     *
     * @return Response JSON response with success boolean and message.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Prevent hanging PHP workers if the engine client blocks on connect
        set_time_limit(10);

        $request = Craft::$app->getRequest();
        $engineType = $request->getRequiredBodyParam('engineType');

        if (!class_exists($engineType) || !is_subclass_of($engineType, EngineInterface::class)) {
            return $this->asJson(['success' => false, 'message' => 'Invalid engine type.']);
        }

        $engineConfig = $request->getBodyParam('engineConfig') ?: [];

        // Validate that the engine's client library is installed before attempting
        if (!$engineType::isClientInstalled()) {
            return $this->asJson([
                'success' => false,
                'message' => 'Client library not installed. Run: composer require ' . $engineType::requiredPackage(),
            ]);
        }

        $engine = new $engineType($engineConfig);

        try {
            $result = $engine->testConnection();
            return $this->asJson([
                'success' => $result,
                'message' => $result ? 'Connection successful.' : 'Connection failed.',
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Return the engine schema/structure for an index via AJAX.
     *
     * @return Response JSON response with schema data.
     */
    public function actionStructure(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            return $this->asJson(['success' => false, 'message' => 'Index not found.']);
        }

        try {
            if (!class_exists($index->engineType)) {
                return $this->asJson(['success' => false, 'message' => 'Engine class not found.']);
            }

            $engine = $index->createEngine();

            if (!$engine->indexExists($index)) {
                return $this->asJson(['success' => false, 'message' => 'Index does not exist in the engine.']);
            }

            $schema = $engine->getIndexSchema($index);

            return $this->asJson([
                'success' => true,
                'schema' => $schema,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Display the index structure/schema page.
     *
     * @param int $indexId
     * @return Response
     */
    public function actionStructurePage(int $indexId): Response
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        return $this->asCpScreen()
            ->title(Craft::t('search-index', 'Index Structure: {name}', ['name' => $index->name]))
            ->selectedSubnavItem('indexes')
            ->addCrumb(Craft::t('search-index', 'Search Indexes'), 'search-index/indexes')
            ->addCrumb($index->name, "search-index/indexes/{$index->id}")
            ->contentTemplate('search-index/indexes/_structure', [
                'index' => $index,
            ]);
    }

    /**
     * Display the CP search testing page.
     *
     * @return Response
     */
    public function actionSearchPage(): Response
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();

        $indexOptions = [];
        foreach ($indexes as $index) {
            $indexOptions[] = [
                'label' => $index->name . ' (' . $index->handle . ')',
                'value' => $index->handle,
            ];
        }

        return $this->renderTemplate('search-index/indexes/search', [
            'indexOptions' => $indexOptions,
        ]);
    }

    /**
     * Display the plugin settings page.
     *
     * @return Response
     */
    public function actionSettings(): Response
    {
        $engineClasses = [
            AlgoliaEngine::class,
            ElasticsearchEngine::class,
            MeilisearchEngine::class,
            OpenSearchEngine::class,
            TypesenseEngine::class,
        ];

        $engineInfo = [];
        foreach ($engineClasses as $class) {
            $engineInfo[] = [
                'class' => $class,
                'displayName' => $class::displayName(),
                'installed' => $class::isClientInstalled(),
                'package' => $class::requiredPackage(),
            ];
        }

        return $this->renderTemplate('search-index/settings/index', [
            'settings' => SearchIndex::$plugin->getSettings(),
            'engineInfo' => $engineInfo,
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * Collect registered engine types, including any added via the event system.
     *
     * @return array[] Each element contains 'class', 'displayName', and 'configFields'.
     */
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

        $enabledEngines = SearchIndex::$plugin->getSettings()->enabledEngines;

        $engineTypes = [];
        foreach ($event->types as $type) {
            if (is_subclass_of($type, EngineInterface::class) || in_array(EngineInterface::class, class_implements($type), true)) {
                // Filter by enabled engines (empty = all enabled for backward compat)
                if (!empty($enabledEngines) && !in_array($type, $enabledEngines, true)) {
                    continue;
                }

                // Skip engines whose client library is not installed
                if (!$type::isClientInstalled()) {
                    continue;
                }

                $engineTypes[] = [
                    'class' => $type,
                    'displayName' => $type::displayName(),
                    'configFields' => $type::configFields(),
                    'installed' => $type::isClientInstalled(),
                    'package' => $type::requiredPackage(),
                ];
            }
        }

        return $engineTypes;
    }
}
