<?php

namespace cogapp\searchindex\services;

use cogapp\searchindex\events\IndexEvent;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\records\FieldMappingRecord;
use cogapp\searchindex\records\IndexRecord;
use Craft;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use yii\base\Component;

class Indexes extends Component
{
    public const CONFIG_KEY = 'searchIndex.indexes';

    public const EVENT_BEFORE_SAVE_INDEX = 'beforeSaveIndex';
    public const EVENT_AFTER_SAVE_INDEX = 'afterSaveIndex';
    public const EVENT_BEFORE_DELETE_INDEX = 'beforeDeleteIndex';
    public const EVENT_AFTER_DELETE_INDEX = 'afterDeleteIndex';

    private ?array $_indexesById = null;
    private ?array $_indexesByHandle = null;

    public function getAllIndexes(): array
    {
        if ($this->_indexesById !== null) {
            return $this->_indexesById;
        }

        $this->_indexesById = [];
        $this->_indexesByHandle = [];

        $records = IndexRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $index = $this->_createIndexFromRecord($record);
            $this->_indexesById[$index->id] = $index;
            $this->_indexesByHandle[$index->handle] = $index;
        }

        return $this->_indexesById;
    }

    public function getIndexById(int $id): ?Index
    {
        $indexes = $this->getAllIndexes();
        return $indexes[$id] ?? null;
    }

    public function getIndexByHandle(string $handle): ?Index
    {
        $this->getAllIndexes();
        return $this->_indexesByHandle[$handle] ?? null;
    }

    public function getEnabledIndexes(): array
    {
        return array_filter($this->getAllIndexes(), fn(Index $index) => $index->enabled);
    }

    public function getIndexesForElement(\craft\base\Element $element): array
    {
        if (!$element instanceof \craft\elements\Entry) {
            return [];
        }

        $sectionId = $element->sectionId;
        $entryTypeId = $element->typeId;

        return array_filter($this->getEnabledIndexes(), function(Index $index) use ($sectionId, $entryTypeId) {
            $sectionMatch = empty($index->sectionIds) || in_array($sectionId, $index->sectionIds, true);
            $typeMatch = empty($index->entryTypeIds) || in_array($entryTypeId, $index->entryTypeIds, true);
            return $sectionMatch && $typeMatch;
        });
    }

    public function saveIndex(Index $index, bool $runValidation = true): bool
    {
        $isNew = !$index->id;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_INDEX)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_INDEX, new IndexEvent([
                'index' => $index,
                'isNew' => $isNew,
            ]));
        }

        if ($runValidation && !$index->validate()) {
            Craft::info('Index not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($isNew) {
            $index->uid = StringHelper::UUID();
        } elseif (!$index->uid) {
            $index->uid = Db::uidById('{{%searchindex_indexes}}', $index->id);
        }

        $configPath = self::CONFIG_KEY . '.' . $index->uid;
        Craft::$app->getProjectConfig()->set($configPath, $index->getConfig());

        if ($isNew) {
            $index->id = Db::idByUid('{{%searchindex_indexes}}', $index->uid);
        }

        $this->_indexesById = null;
        $this->_indexesByHandle = null;

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_INDEX)) {
            $this->trigger(self::EVENT_AFTER_SAVE_INDEX, new IndexEvent([
                'index' => $index,
                'isNew' => $isNew,
            ]));
        }

        return true;
    }

    public function deleteIndex(Index $index): bool
    {
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_INDEX)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_INDEX, new IndexEvent([
                'index' => $index,
            ]));
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_KEY . '.' . $index->uid);

        $this->_indexesById = null;
        $this->_indexesByHandle = null;

        return true;
    }

    public function handleChangedIndex(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        ProjectConfigHelper::ensureAllSitesProcessed();

        $record = IndexRecord::findOne(['uid' => $uid]);

        if (!$record) {
            $record = new IndexRecord();
        }

        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->engineType = $data['engineType'];
        $record->engineConfig = $data['engineConfig'] ?? null;
        $record->sectionIds = $data['sectionIds'] ?? null;
        $record->entryTypeIds = $data['entryTypeIds'] ?? null;
        $record->siteId = $data['siteId'] ?? null;
        $record->enabled = $data['enabled'] ?? true;
        $record->sortOrder = $data['sortOrder'] ?? 0;
        $record->uid = $uid;

        $record->save(false);

        // Sync field mappings
        if (isset($data['fieldMappings']) && is_array($data['fieldMappings'])) {
            $this->_syncFieldMappings($record->id, $data['fieldMappings']);
        }

        $this->_indexesById = null;
        $this->_indexesByHandle = null;
    }

    public function handleDeletedIndex(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];

        $record = IndexRecord::findOne(['uid' => $uid]);

        if (!$record) {
            return;
        }

        // Delete field mappings first (FK cascade should handle, but be explicit)
        FieldMappingRecord::deleteAll(['indexId' => $record->id]);
        $record->delete();

        $this->_indexesById = null;
        $this->_indexesByHandle = null;
    }

    public function rebuildProjectConfig(): array
    {
        $output = [];
        $records = IndexRecord::find()->all();

        foreach ($records as $record) {
            $index = $this->_createIndexFromRecord($record);
            $output['indexes'][$record->uid] = $index->getConfig();
        }

        return $output;
    }

    public function getFieldMappingsForIndex(int $indexId): array
    {
        $records = FieldMappingRecord::find()
            ->where(['indexId' => $indexId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return array_map(fn($record) => $this->_createFieldMappingFromRecord($record), $records);
    }

    public function saveFieldMappings(int $indexId, array $mappings): bool
    {
        $index = $this->getIndexById($indexId);
        if (!$index) {
            return false;
        }

        $index->setFieldMappings($mappings);

        return $this->saveIndex($index, false);
    }

    private function _syncFieldMappings(int $indexId, array $mappingsData): void
    {
        // Delete existing mappings
        FieldMappingRecord::deleteAll(['indexId' => $indexId]);

        foreach ($mappingsData as $uid => $data) {
            $record = new FieldMappingRecord();
            $record->indexId = $indexId;
            $record->fieldUid = $data['fieldUid'] ?? null;
            $record->parentFieldUid = $data['parentFieldUid'] ?? null;
            $record->attribute = $data['attribute'] ?? null;
            $record->indexFieldName = $data['indexFieldName'];
            $record->indexFieldType = $data['indexFieldType'];
            $record->enabled = $data['enabled'] ?? true;
            $record->weight = $data['weight'] ?? 5;
            $record->resolverConfig = $data['resolverConfig'] ?? null;
            $record->sortOrder = $data['sortOrder'] ?? 0;
            $record->uid = is_string($uid) && StringHelper::isUUID($uid) ? $uid : StringHelper::UUID();
            $record->save(false);
        }
    }

    private function _createIndexFromRecord(IndexRecord $record): Index
    {
        $index = new Index();
        $index->id = $record->id;
        $index->name = $record->name;
        $index->handle = $record->handle;
        $index->engineType = $record->engineType;
        $index->engineConfig = is_string($record->engineConfig) ? json_decode($record->engineConfig, true) : $record->engineConfig;
        $index->sectionIds = is_string($record->sectionIds) ? json_decode($record->sectionIds, true) : $record->sectionIds;
        $index->entryTypeIds = is_string($record->entryTypeIds) ? json_decode($record->entryTypeIds, true) : $record->entryTypeIds;
        $index->siteId = $record->siteId;
        $index->enabled = (bool)$record->enabled;
        $index->sortOrder = (int)$record->sortOrder;
        $index->uid = $record->uid;

        // Load field mappings
        $index->setFieldMappings($this->getFieldMappingsForIndex($index->id));

        return $index;
    }

    private function _createFieldMappingFromRecord(FieldMappingRecord $record): FieldMapping
    {
        $mapping = new FieldMapping();
        $mapping->id = $record->id;
        $mapping->indexId = $record->indexId;
        $mapping->fieldUid = $record->fieldUid;
        $mapping->parentFieldUid = $record->parentFieldUid;
        $mapping->attribute = $record->attribute;
        $mapping->indexFieldName = $record->indexFieldName;
        $mapping->indexFieldType = $record->indexFieldType;
        $mapping->enabled = (bool)$record->enabled;
        $mapping->weight = (int)$record->weight;
        $mapping->resolverConfig = is_string($record->resolverConfig) ? json_decode($record->resolverConfig, true) : $record->resolverConfig;
        $mapping->sortOrder = (int)$record->sortOrder;
        $mapping->uid = $record->uid;

        return $mapping;
    }
}
