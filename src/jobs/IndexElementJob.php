<?php

/**
 * Search Index plugin for Craft CMS -- IndexElementJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Queue job that indexes a single element into a search engine.
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexElementJob extends BaseJob
{
    /** @var int The search index ID to add the document to. */
    public int $indexId;
    /** @var int The Craft element ID to index. */
    public int $elementId;
    /** @var int|null Site ID to scope the element query to. */
    public ?int $siteId = null;

    /**
     * Execute the job by resolving the element and sending it to the engine.
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

        $query = Entry::find()
            ->id($this->elementId)
            ->status(null);

        if ($this->siteId) {
            $query->siteId($this->siteId);
        }

        // Eager-load relation fields (same as BulkIndexJob)
        $eagerLoad = BulkIndexJob::buildEagerLoadConfig($index->getFieldMappings());
        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        $element = $query->one();
        if (!$element) {
            return;
        }

        // If element is no longer live, deindex instead
        $isLive = $element->enabled
            && $element->getEnabledForSite()
            && $element->getStatus() === Entry::STATUS_LIVE;
        if (!$isLive) {
            Craft::$app->getQueue()->push(new DeindexElementJob([
                'indexId' => $this->indexId,
                'elementId' => $this->elementId,
            ]));
            return;
        }

        try {
            $document = $plugin->getFieldMapper()->resolveElement($element, $index);
            $engineClass = $index->engineType;
            $engine = new $engineClass($index->engineConfig ?? []);
            $engine->indexDocument($index, $this->elementId, $document);
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to index element #{$this->elementId} for index '{$index->name}': " . $e->getMessage(),
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
        return "Indexing element #{$this->elementId}";
    }
}
