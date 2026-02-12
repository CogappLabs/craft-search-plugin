<?php

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\SearchIndex;
use craft\elements\Entry;
use craft\queue\BaseJob;

class IndexElementJob extends BaseJob
{
    public int $indexId;
    public int $elementId;
    public ?int $siteId = null;

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

        $element = $query->one();
        if (!$element) {
            return;
        }

        $document = $plugin->getFieldMapper()->resolveElement($element, $index);

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);
        $engine->indexDocument($index, $this->elementId, $document);
    }

    protected function defaultDescription(): ?string
    {
        return "Indexing element #{$this->elementId}";
    }
}
