<?php

/**
 * Search Index plugin for Craft CMS -- Sync service.
 */

namespace cogapp\searchindex\services;

use cogapp\searchindex\events\DocumentSyncEvent;
use cogapp\searchindex\jobs\AtomicSwapJob;
use cogapp\searchindex\jobs\BulkIndexJob;
use cogapp\searchindex\jobs\CleanupOrphansJob;
use cogapp\searchindex\jobs\DeindexElementJob;
use cogapp\searchindex\jobs\IndexElementJob;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\base\Element;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\helpers\Queue;
use yii\base\Component;

/**
 * Handles real-time and bulk synchronisation of Craft elements with search engine indexes.
 *
 * @author cogapp
 * @since 1.0.0
 */
class Sync extends Component
{
    /** @event DocumentSyncEvent Fired after a single element is indexed. */
    public const EVENT_AFTER_INDEX_ELEMENT = 'afterIndexElement';
    /** @event DocumentSyncEvent Fired after a single element is deleted from the index. */
    public const EVENT_AFTER_DELETE_ELEMENT = 'afterDeleteElement';
    /** @event DocumentSyncEvent Fired after a bulk index batch completes. */
    public const EVENT_AFTER_BULK_INDEX = 'afterBulkIndex';

    /**
     * Track element IDs already queued for re-index this request,
     * to avoid duplicate jobs when multiple relations change.
     */
    private array $_queuedElementIds = [];

    /** Cached engine instances keyed by type + config hash. */
    private array $_engineCache = [];

    /**
     * Handle an element save event by queuing index or deindex jobs as appropriate.
     *
     * Skips drafts, revisions, and propagating saves. For live entries, queues
     * an index job; for non-live entries, queues a deindex job.
     *
     * @param ElementEvent $event
     * @return void
     */
    public function handleElementSave(ElementEvent $event): void
    {
        $element = $event->element;

        $settings = SearchIndex::$plugin->getSettings();
        if (!$settings->syncOnSave) {
            return;
        }

        // Skip drafts, revisions, propagating
        if ($element->propagating) {
            return;
        }
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        // Trashed entries should be deindexed
        if ($element instanceof Entry && $element->trashed) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);
            foreach ($indexes as $index) {
                Craft::$app->getQueue()->push(new DeindexElementJob([
                    'indexId' => $index->id,
                    'indexName' => $index->name,
                    'elementId' => $element->id,
                ]));
            }
            return;
        }

        // Index the element itself (if it's an Entry in one of our indexes)
        if ($element instanceof Entry) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);

            foreach ($indexes as $index) {
                $isLive = $element->enabled
                    && $element->getEnabledForSite()
                    && $element->getStatus() === Entry::STATUS_LIVE;

                if ($isLive) {
                    $this->_pushIndexJob($index, $element->id, $element->siteId);
                } else {
                    // Entry is disabled, expired, or future-dated — remove from index
                    Craft::$app->getQueue()->push(new DeindexElementJob([
                        'indexId' => $index->id,
                        'indexName' => $index->name,
                        'elementId' => $element->id,
                    ]));
                }
            }
        }

        // Re-index related entries (relation cascade)
        if ($settings->indexRelations) {
            $this->_reindexRelatedEntries($element);
        }
    }

    /**
     * Handle an element deletion event by queuing deindex jobs.
     *
     * Also re-indexes related entries when relation cascading is enabled.
     *
     * @param ElementEvent $event
     * @return void
     */
    public function handleElementDelete(ElementEvent $event): void
    {
        $element = $event->element;

        if ($element instanceof Entry) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);

            foreach ($indexes as $index) {
                Craft::$app->getQueue()->push(new DeindexElementJob([
                    'indexId' => $index->id,
                    'indexName' => $index->name,
                    'elementId' => $element->id,
                ]));
            }
        }

        // Re-index entries that were related to the deleted element
        $settings = SearchIndex::$plugin->getSettings();
        if ($settings->syncOnSave && $settings->indexRelations) {
            $this->_reindexRelatedEntries($element);
        }
    }

    /**
     * Handle a slug/URI change event by re-indexing the affected entry.
     *
     * @param ElementEvent $event
     * @return void
     */
    public function handleSlugChange(ElementEvent $event): void
    {
        $element = $event->element;

        if (!($element instanceof Entry)) {
            return;
        }

        $settings = SearchIndex::$plugin->getSettings();
        if (!$settings->syncOnSave) {
            return;
        }

        // Re-index this entry (its URI changed)
        $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);
        foreach ($indexes as $index) {
            $this->_pushIndexJob($index, $element->id, $element->siteId);
        }
    }

    /**
     * Queue bulk index jobs for all live entries matching the index configuration.
     *
     * Creates the engine index if it does not exist and updates its settings,
     * then queues batched BulkIndexJob and a trailing CleanupOrphansJob.
     *
     * @param Index $index
     * @return void
     */
    public function importIndex(Index $index): void
    {
        // Ensure the engine index exists and schema is up to date
        $engine = $this->_getEngine($index);
        if (!$engine->indexExists($index)) {
            $engine->createIndex($index);
        }
        $engine->updateIndexSettings($index);

        $query = $this->_buildEntryQuery($index);

        $settings = SearchIndex::$plugin->getSettings();
        $batchSize = $settings->batchSize;

        $totalEntries = $query->count();
        $offset = 0;

        while ($offset < $totalEntries) {
            Craft::$app->getQueue()->push(new BulkIndexJob([
                'indexId' => $index->id,
                'indexName' => $index->name,
                'sectionIds' => $index->sectionIds,
                'entryTypeIds' => $index->entryTypeIds,
                'siteId' => $index->siteId,
                'offset' => $offset,
                'limit' => $batchSize,
            ]));

            $offset += $batchSize;
        }

        // Queue orphan cleanup after all bulk index jobs
        Craft::$app->getQueue()->push(new CleanupOrphansJob([
            'indexId' => $index->id,
            'indexName' => $index->name,
            'sectionIds' => $index->sectionIds,
            'entryTypeIds' => $index->entryTypeIds,
            'siteId' => $index->siteId,
        ]));
    }

    /**
     * Remove all documents from an index in the search engine.
     *
     * @param Index $index
     * @return void
     */
    public function flushIndex(Index $index): void
    {
        $engine = $this->_getEngine($index);
        $engine->flushIndex($index);
    }

    /**
     * Flush and then re-import an index (full refresh).
     *
     * @param Index $index
     * @return void
     */
    public function refreshIndex(Index $index): void
    {
        $this->flushIndex($index);
        $this->importIndex($index);
    }

    /**
     * Check whether the engine for this index supports atomic swap.
     *
     * @param Index $index
     * @return bool
     */
    public function supportsAtomicSwap(Index $index): bool
    {
        return $this->_getEngine($index)->supportsAtomicSwap();
    }

    /**
     * Build a clone of the given index with the swap handle applied.
     *
     * Uses the engine's `buildSwapHandle()` to determine the correct
     * swap handle (alias-based engines alternate `_swap_a`/`_swap_b`).
     *
     * @param Index  $index
     * @param string|null $swapHandle Override swap handle (used when replaying from queue to avoid race conditions).
     * @return Index
     */
    private function _buildSwapIndex(Index $index, ?string $swapHandle = null): Index
    {
        if ($swapHandle === null) {
            $engine = $this->_getEngine($index);
            $swapHandle = $engine->buildSwapHandle($index);
        }

        $swapIndex = clone $index;
        $swapIndex->handle = $swapHandle;

        return $swapIndex;
    }

    /**
     * Import documents into a temporary swap index.
     *
     * Creates the temp index, configures its settings, and queues bulk import
     * jobs followed by an AtomicSwapJob that swaps the temp with production.
     *
     * The swap handle is computed once here and passed through the queue to
     * prevent race conditions with alias-based engines that alternate names.
     *
     * @param Index $index
     * @return void
     */
    public function importIndexForSwap(Index $index): void
    {
        $engine = $this->_getEngine($index);
        $swapHandle = $engine->buildSwapHandle($index);
        $swapIndex = $this->_buildSwapIndex($index, $swapHandle);

        // Create and configure the temp index
        if ($engine->indexExists($swapIndex)) {
            $engine->deleteIndex($swapIndex);
        }
        $engine->createIndex($swapIndex);
        $engine->updateIndexSettings($swapIndex);

        // Queue bulk import jobs targeting the swap index
        $query = $this->_buildEntryQuery($index);

        $settings = SearchIndex::$plugin->getSettings();
        $batchSize = $settings->batchSize;
        $totalEntries = $query->count();
        $offset = 0;

        while ($offset < $totalEntries) {
            Craft::$app->getQueue()->push(new BulkIndexJob([
                'indexId' => $index->id,
                'indexName' => $index->name,
                'sectionIds' => $index->sectionIds,
                'entryTypeIds' => $index->entryTypeIds,
                'siteId' => $index->siteId,
                'offset' => $offset,
                'limit' => $batchSize,
                'indexNameOverride' => $swapHandle,
            ]));

            $offset += $batchSize;
        }

        // Queue the atomic swap job at lower priority (higher number) so bulk
        // import jobs are picked up first, even with concurrent queue workers.
        Queue::push(new AtomicSwapJob([
            'indexId' => $index->id,
            'indexName' => $index->name,
            'swapHandle' => $swapHandle,
        ]), 2048);
    }

    /**
     * Perform the atomic swap between production and temporary index.
     *
     * @param Index       $index
     * @param string|null $swapHandle The swap handle computed during import (avoids re-computing for alias-based engines).
     * @return void
     */
    public function performAtomicSwap(Index $index, ?string $swapHandle = null): void
    {
        $engine = $this->_getEngine($index);
        $swapIndex = $this->_buildSwapIndex($index, $swapHandle);

        $engine->swapIndex($index, $swapIndex);
    }

    /**
     * Fire the afterIndexElement event.
     *
     * Called by IndexElementJob after a single document is successfully indexed.
     *
     * @param Index $index
     * @param int   $elementId
     */
    public function afterIndexElement(Index $index, int $elementId): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_INDEX_ELEMENT)) {
            $this->trigger(self::EVENT_AFTER_INDEX_ELEMENT, new DocumentSyncEvent([
                'index' => $index,
                'elementId' => $elementId,
                'action' => 'upsert',
            ]));
        }
    }

    /**
     * Fire the afterDeleteElement event.
     *
     * Called by DeindexElementJob after a single document is successfully deleted.
     *
     * @param Index $index
     * @param int   $elementId
     */
    public function afterDeleteElement(Index $index, int $elementId): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_ELEMENT)) {
            $this->trigger(self::EVENT_AFTER_DELETE_ELEMENT, new DocumentSyncEvent([
                'index' => $index,
                'elementId' => $elementId,
                'action' => 'delete',
            ]));
        }
    }

    /**
     * Fire the afterBulkIndex event.
     *
     * Called by BulkIndexJob after a batch of documents is successfully indexed.
     *
     * @param Index $index
     */
    public function afterBulkIndex(Index $index): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_BULK_INDEX)) {
            $this->trigger(self::EVENT_AFTER_BULK_INDEX, new DocumentSyncEvent([
                'index' => $index,
                'elementId' => 0,
                'action' => 'upsert',
            ]));
        }
    }

    /**
     * Find all entries in our indexes that are related to $element, and re-index them.
     * This handles the cascade: Category renamed → all entries using that category get re-indexed.
     */
    private function _reindexRelatedEntries(Element $element): void
    {
        $enabledIndexes = SearchIndex::$plugin->getIndexes()->getEnabledIndexes();

        if (empty($enabledIndexes)) {
            return;
        }

        // Find entries related to this element across all sites
        $relatedEntries = Entry::find()
            ->relatedTo($element)
            ->status('live')
            ->site('*')
            ->unique()
            ->limit(null)
            ->all();

        foreach ($relatedEntries as $entry) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($entry);
            foreach ($indexes as $index) {
                $this->_pushIndexJob($index, $entry->id, $entry->siteId);
            }
        }
    }

    /**
     * Push an IndexElementJob, deduplicating within the current request.
     */
    private function _pushIndexJob(Index $index, int $elementId, ?int $siteId): void
    {
        $key = "{$index->id}:{$elementId}:{$siteId}";

        if (isset($this->_queuedElementIds[$key])) {
            return;
        }

        $this->_queuedElementIds[$key] = true;

        Craft::$app->getQueue()->push(new IndexElementJob([
            'indexId' => $index->id,
            'indexName' => $index->name,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]));
    }

    /**
     * Build the base Entry query for an index, applying section/type/site filters.
     */
    private function _buildEntryQuery(Index $index): EntryQuery
    {
        $query = Entry::find();

        if (!empty($index->sectionIds)) {
            $query->sectionId($index->sectionIds);
        }

        if (!empty($index->entryTypeIds)) {
            $query->typeId($index->entryTypeIds);
        }

        if ($index->siteId) {
            $query->siteId($index->siteId);
        }

        $query->status('live');

        return $query;
    }

    /**
     * Instantiate the search engine for a given index.
     *
     * @param Index $index
     * @return \cogapp\searchindex\engines\EngineInterface
     */
    private function _getEngine(Index $index): \cogapp\searchindex\engines\EngineInterface
    {
        $key = $index->engineType . ':' . md5(json_encode($index->engineConfig ?? []));

        if (!isset($this->_engineCache[$key])) {
            $this->_engineCache[$key] = $index->createEngine();
        }

        return $this->_engineCache[$key];
    }
}
