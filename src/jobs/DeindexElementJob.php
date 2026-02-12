<?php

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use craft\queue\BaseJob;

class DeindexElementJob extends BaseJob
{
    public int $indexId;
    public int $elementId;

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

    protected function defaultDescription(): ?string
    {
        return "Removing element #{$this->elementId} from search index";
    }
}
