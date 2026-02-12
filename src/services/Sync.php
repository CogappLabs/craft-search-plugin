<?php

namespace cogapp\searchindex\services;

use cogapp\searchindex\jobs\BulkIndexJob;
use cogapp\searchindex\jobs\CleanupOrphansJob;
use cogapp\searchindex\jobs\DeindexElementJob;
use cogapp\searchindex\jobs\IndexElementJob;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\ElementEvent;
use yii\base\Component;

class Sync extends Component
{
    /**
     * Track element IDs already queued for re-index this request,
     * to avoid duplicate jobs when multiple relations change.
     */
    private array $_queuedElementIds = [];

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

        // Index the element itself (if it's an Entry in one of our indexes)
        if ($element instanceof Entry) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);

            foreach ($indexes as $index) {
                $isLive = $element->enabled
                    && $element->getEnabledForSite()
                    && $element->getStatus() === Entry::STATUS_LIVE;

                if ($isLive) {
                    $this->_pushIndexJob($index->id, $element->id, $element->siteId);
                } else {
                    // Entry is disabled, expired, or future-dated — remove from index
                    Craft::$app->getQueue()->push(new DeindexElementJob([
                        'indexId' => $index->id,
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

    public function handleElementDelete(ElementEvent $event): void
    {
        $element = $event->element;

        if ($element instanceof Entry) {
            $indexes = SearchIndex::$plugin->getIndexes()->getIndexesForElement($element);

            foreach ($indexes as $index) {
                Craft::$app->getQueue()->push(new DeindexElementJob([
                    'indexId' => $index->id,
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
            $this->_pushIndexJob($index->id, $element->id, $element->siteId);
        }
    }

    public function importIndex(Index $index): void
    {
        // Ensure the engine index exists and schema is up to date
        $engine = $this->_getEngine($index);
        if (!$engine->indexExists($index)) {
            $engine->createIndex($index);
        }
        $engine->updateIndexSettings($index);

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

        $settings = SearchIndex::$plugin->getSettings();
        $batchSize = $settings->batchSize;

        $totalEntries = $query->count();
        $offset = 0;

        while ($offset < $totalEntries) {
            Craft::$app->getQueue()->push(new BulkIndexJob([
                'indexId' => $index->id,
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
            'sectionIds' => $index->sectionIds,
            'entryTypeIds' => $index->entryTypeIds,
            'siteId' => $index->siteId,
        ]));
    }

    public function flushIndex(Index $index): void
    {
        $engine = $this->_getEngine($index);
        $engine->flushIndex($index);
    }

    public function refreshIndex(Index $index): void
    {
        $this->flushIndex($index);
        $this->importIndex($index);
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
                $this->_pushIndexJob($index->id, $entry->id, $entry->siteId);
            }
        }
    }

    /**
     * Push an IndexElementJob, deduplicating within the current request.
     */
    private function _pushIndexJob(int $indexId, int $elementId, ?int $siteId): void
    {
        $key = "{$indexId}:{$elementId}:{$siteId}";

        if (isset($this->_queuedElementIds[$key])) {
            return;
        }

        $this->_queuedElementIds[$key] = true;

        Craft::$app->getQueue()->push(new IndexElementJob([
            'indexId' => $indexId,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]));
    }

    private function _getEngine(Index $index): \cogapp\searchindex\engines\EngineInterface
    {
        $engineClass = $index->engineType;
        $config = $index->engineConfig ?? [];

        return new $engineClass($config);
    }
}
