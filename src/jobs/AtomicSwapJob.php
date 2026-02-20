<?php

/**
 * Search Index plugin for Craft CMS -- AtomicSwapJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\controllers\ApiController;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\queue\BaseJob;
use yii\caching\TagDependency;

/**
 * Queue job that atomically swaps a temporary index with the production index.
 *
 * Queued automatically by {@see \cogapp\searchindex\services\Sync::decrementSwapBatchCounter()}
 * when the last bulk import batch completes, or directly by
 * {@see \cogapp\searchindex\services\Sync::importIndexForSwap()} when there are
 * zero entries to import. No polling or retry logic needed — the job only runs
 * once all batches are done.
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
            TagDependency::invalidate(Craft::$app->getCache(), ApiController::API_CACHE_TAG);
            Craft::info("Atomic swap completed for index '{$index->name}'.", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error(
                "Atomic swap failed for index '{$index->name}': " . $e->getMessage(),
                __METHOD__,
            );

            // Re-throw so the queue retries on transient failures (network
            // timeouts, etc.) instead of silently marking the job as successful.
            throw $e;
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
