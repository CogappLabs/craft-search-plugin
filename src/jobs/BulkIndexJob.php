<?php

/**
 * Search Index plugin for Craft CMS -- BulkIndexJob queue job.
 */

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Queue job that indexes a batch of entries into a search engine in a single operation.
 *
 * @author cogapp
 * @since 1.0.0
 */
class BulkIndexJob extends BaseJob
{
    /** @var int The search index ID to populate. */
    public int $indexId;
    /** @var string Human-readable index name for queue descriptions. */
    public string $indexName = '';
    /** @var int[]|null Section IDs to filter entries by. */
    public ?array $sectionIds = null;
    /** @var int[]|null Entry type IDs to filter entries by. */
    public ?array $entryTypeIds = null;
    /** @var int|null Site ID to scope the query to. */
    public ?int $siteId = null;
    /** @var int Query offset for this batch. */
    public int $offset = 0;
    /** @var int Maximum number of entries to process in this batch. */
    public int $limit = 500;
    /** @var string|null Override index name for atomic swap (targets temp index). */
    public ?string $indexNameOverride = null;

    /**
     * Execute the bulk index job by resolving entries and sending them to the engine.
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

        // Eager-load relation fields to avoid N+1 queries
        $eagerLoad = self::buildEagerLoadConfig($index->getFieldMappings());
        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        $query->offset($this->offset)->limit($this->limit);
        $entries = $query->all();

        try {
            if (empty($entries)) {
                return;
            }

            $documents = [];
            $failedIds = [];
            $fieldMapper = $plugin->getFieldMapper();

            foreach ($entries as $i => $entry) {
                $this->setProgress($queue, $i / count($entries));
                try {
                    $document = $fieldMapper->resolveElement($entry, $index);
                    $documents[] = $document;
                } catch (\Throwable $e) {
                    $failedIds[] = $entry->id;
                    Craft::error(
                        "Failed to resolve element #{$entry->id} for index '{$index->name}': " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }

            if (!empty($documents)) {
                // If targeting a swap index, clone with override handle
                $targetIndex = $index;
                if ($this->indexNameOverride) {
                    $targetIndex = clone $index;
                    $targetIndex->handle = $this->indexNameOverride;
                }

                $engine = $index->createEngine();
                $engine->indexDocuments($targetIndex, $documents);
                $plugin->getSync()->afterBulkIndex($index);
            }

            if (!empty($failedIds)) {
                Craft::warning(
                    "Skipped " . count($failedIds) . " entries during bulk index: " . implode(', ', $failedIds),
                    __METHOD__
                );
            }
        } finally {
            // Always decrement the swap batch counter, even on exception or empty batch,
            // so the AtomicSwapJob doesn't wait forever for a counter that never reaches zero.
            if ($this->indexNameOverride) {
                $plugin->getSync()->decrementSwapBatchCounter($this->indexNameOverride);
            }
        }
    }

    /**
     * Build eager-loading config from field mappings to avoid N+1 queries.
     * Detects relation fields (Categories, Tags, Entries, Assets, Users)
     * and Matrix fields with nested relations.
     *
     * Public static so IndexElementJob can reuse this logic.
     */
    public static function buildEagerLoadConfig(array $mappings): array
    {
        $eagerLoad = [];
        $relationFieldClasses = [
            \craft\fields\Categories::class,
            \craft\fields\Tags::class,
            \craft\fields\Entries::class,
            \craft\fields\Users::class,
            \craft\fields\Assets::class,
        ];

        // Track which Matrix parent UIDs we've already added eager loading for
        $eagerLoadedMatrixUids = [];

        foreach ($mappings as $mapping) {
            if (!$mapping instanceof FieldMapping || !$mapping->enabled || $mapping->isAttribute()) {
                continue;
            }

            // Sub-field mapping: eager load the parent Matrix field + the sub-field if it's a relation
            if ($mapping->isSubField()) {
                $parentField = $mapping->parentFieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->parentFieldUid) : null;
                if (!$parentField) {
                    continue;
                }

                // Ensure the parent Matrix field itself is eager loaded
                if (!isset($eagerLoadedMatrixUids[$mapping->parentFieldUid])) {
                    $eagerLoad[] = $parentField->handle;
                    $eagerLoadedMatrixUids[$mapping->parentFieldUid] = true;
                }

                // If the sub-field is a relation type, eager load it nested
                $subField = $mapping->fieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;
                if ($subField && in_array(get_class($subField), $relationFieldClasses, true)) {
                    $eagerLoad[] = $parentField->handle . '.' . $subField->handle;
                }

                continue;
            }

            $field = $mapping->fieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;
            if (!$field) {
                continue;
            }

            $fieldClass = get_class($field);

            // Direct relation fields
            if (in_array($fieldClass, $relationFieldClasses, true)) {
                $eagerLoad[] = $field->handle;
                continue;
            }

            // Matrix fields (old-style single mapping, no sub-fields) - eager load the field + nested relations
            if ($fieldClass === \craft\fields\Matrix::class && !isset($eagerLoadedMatrixUids[$field->uid])) {
                $eagerLoad[] = $field->handle;
                $eagerLoadedMatrixUids[$field->uid] = true;

                // Also eager-load nested relation fields within matrix blocks
                foreach ($field->getEntryTypes() as $entryType) {
                    $fieldLayout = $entryType->getFieldLayout();
                    if (!$fieldLayout) {
                        continue;
                    }
                    foreach ($fieldLayout->getCustomFields() as $blockField) {
                        if (in_array(get_class($blockField), $relationFieldClasses, true)) {
                            $eagerLoad[] = $field->handle . '.' . $blockField->handle;
                        }
                    }
                }
            }
        }

        return $eagerLoad;
    }

    /**
     * Return a human-readable description for the queue manager.
     *
     * @return string|null
     */
    protected function defaultDescription(): ?string
    {
        $name = $this->indexName ?: "#{$this->indexId}";
        return "Bulk indexing \"{$name}\" (offset: {$this->offset}, limit: {$this->limit})";
    }
}
