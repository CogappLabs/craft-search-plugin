<?php

namespace cogapp\searchindex\jobs;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

class BulkIndexJob extends BaseJob
{
    public int $indexId;
    public ?array $sectionIds = null;
    public ?array $entryTypeIds = null;
    public ?int $siteId = null;
    public int $offset = 0;
    public int $limit = 500;

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
        $eagerLoad = $this->_buildEagerLoadConfig($index->getFieldMappings());
        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        $query->offset($this->offset)->limit($this->limit);
        $entries = $query->all();

        if (empty($entries)) {
            return;
        }

        $documents = [];
        $fieldMapper = $plugin->getFieldMapper();

        foreach ($entries as $i => $entry) {
            $this->setProgress($queue, $i / count($entries));
            $document = $fieldMapper->resolveElement($entry, $index);
            $documents[] = $document;
        }

        $engineClass = $index->engineType;
        $engine = new $engineClass($index->engineConfig ?? []);
        $engine->indexDocuments($index, $documents);
    }

    /**
     * Build eager-loading config from field mappings to avoid N+1 queries.
     * Detects relation fields (Categories, Tags, Entries, Assets, Users)
     * and Matrix fields with nested relations.
     */
    private function _buildEagerLoadConfig(array $mappings): array
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

    protected function defaultDescription(): ?string
    {
        return "Bulk indexing (offset: {$this->offset}, limit: {$this->limit})";
    }
}
