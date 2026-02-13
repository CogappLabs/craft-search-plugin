<?php

/**
 * Search Index plugin for Craft CMS -- DeindexElementJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use craft\queue\BaseJob;

/**
 * Queue job that removes a single element from a search engine index.
 *
 * @author cogapp
 * @since 1.0.0
 */
class DeindexElementJob extends BaseJob
{
    /** @var int The search index ID to remove the document from. */
    public int $indexId;
    /** @var string Human-readable index name for queue descriptions. */
    public string $indexName = '';
    /** @var int The Craft element ID to deindex. */
    public int $elementId;

    /**
     * Execute the job by deleting the document from the engine.
     *
     * @param \yii\queue\Queue $queue
     * @return void
     */
    public function execute($queue): void
    {
        $plugin = SearchIndex::$plugin;

        $index = $plugin->getIndexes()->getIndexById($this->indexId);
        if (!$index) {
            return;
        }

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);

        try {
            $engine->deleteDocument($index, $this->elementId);
        } catch (\Exception $e) {
            // Document may not exist in the index, that's OK
            \Craft::warning("Failed to deindex element #{$this->elementId}: " . $e->getMessage(), __METHOD__);
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
        return "Removing element #{$this->elementId} from \"{$name}\"";
    }
}
