<?php

/**
 * Search Index plugin for Craft CMS -- AtomicSwapJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\queue\BaseJob;

/**
 * Queue job that atomically swaps a temporary index with the production index.
 *
 * Queued after all bulk import jobs when the engine supports atomic swap.
 *
 * @author cogapp
 * @since 1.0.0
 */
class AtomicSwapJob extends BaseJob
{
    /** @var int The search index ID. */
    public int $indexId;
    /** @var string Human-readable index name for queue descriptions. */
    public string $indexName = '';
    /** @var string|null The swap handle computed during import (prevents race conditions with alternating names). */
    public ?string $swapHandle = null;

    /**
     * Execute the atomic swap.
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

        try {
            $plugin->getSync()->performAtomicSwap($index, $this->swapHandle);
            Craft::info("Atomic swap completed for index '{$index->name}'.", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error(
                "Atomic swap failed for index '{$index->name}': " . $e->getMessage(),
                __METHOD__
            );
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
        return "Atomic swap for \"{$name}\"";
    }
}
