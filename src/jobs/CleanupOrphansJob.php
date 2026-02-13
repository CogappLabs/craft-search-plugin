<?php

/**
 * Search Index plugin for Craft CMS -- CleanupOrphansJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Removes orphan documents from the search engine that no longer
 * correspond to live Craft entries. Queued after bulk import jobs.
 *
 * @author cogapp
 * @since 1.0.0
 */
class CleanupOrphansJob extends BaseJob
{
    /** @var int The search index ID to clean up. */
    public int $indexId;
    /** @var string Human-readable index name for queue descriptions. */
    public string $indexName = '';
    /** @var int[]|null Section IDs to filter live entries by. */
    public ?array $sectionIds = null;
    /** @var int[]|null Entry type IDs to filter live entries by. */
    public ?array $entryTypeIds = null;
    /** @var int|null Site ID to scope the query to. */
    public ?int $siteId = null;

    /**
     * Execute the cleanup by comparing engine document IDs against live entry IDs.
     *
     * @param \yii\queue\Queue $queue
     * @return void
     */
    public function execute($queue): void
    {
        $plugin = SearchIndex::$plugin;

        $index = $plugin->getIndexes()->getIndexById($this->indexId);
        if (!$index || !$index->enabled) {
            return;
        }

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);

        if (!$engine->indexExists($index)) {
            return;
        }

        // Get all document IDs currently in the engine
        $engineDocIds = $engine->getAllDocumentIds($index);

        if (empty($engineDocIds)) {
            return;
        }

        // Get all live entry IDs that should be in this index
        $query = Entry::find()->status('live');

        if (!empty($this->sectionIds)) {
            $query->sectionId($this->sectionIds);
        }

        if (!empty($this->entryTypeIds)) {
            $query->typeId($this->entryTypeIds);
        }

        if ($this->siteId) {
            $query->siteId($this->siteId);
        }

        $liveIds = array_map('strval', $query->ids());

        // Find document IDs in the engine that aren't in the live set
        $orphanIds = array_diff($engineDocIds, $liveIds);

        if (empty($orphanIds)) {
            Craft::info("No orphan documents found in index '{$index->handle}'.", __METHOD__);
            return;
        }

        Craft::info("Removing " . count($orphanIds) . " orphan document(s) from index '{$index->handle}'.", __METHOD__);

        // Delete orphans in batches
        $orphanIntIds = array_map('intval', array_values($orphanIds));
        $batchSize = 500;

        for ($i = 0, $total = count($orphanIntIds); $i < $total; $i += $batchSize) {
            $batch = array_slice($orphanIntIds, $i, $batchSize);
            $engine->deleteDocuments($index, $batch);

            $this->setProgress($queue, min(1, ($i + $batchSize) / $total));
        }
    }

    /**
     * Return a human-readable description for the queue manager.
     *
     * @return string|null
     */
    protected function defaultDescription(): ?string
    {
        $name = $this->indexName ?: "#{$this->indexId}";
        return "Cleaning up orphan documents from \"{$name}\"";
    }
}
