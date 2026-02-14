<?php

/**
 * Search Index plugin for Craft CMS -- Indexes service.
 */

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

/**
 * Manages search index CRUD operations and project config synchronisation.
 *
 * @author cogapp
 * @since 1.0.0
 */
class Indexes extends Component
{
    /** Project config key for storing index definitions. */
    public const CONFIG_KEY = 'searchIndex.indexes';

    /** Fired before an index is saved. */
    public const EVENT_BEFORE_SAVE_INDEX = 'beforeSaveIndex';
    /** Fired after an index is saved. */
    public const EVENT_AFTER_SAVE_INDEX = 'afterSaveIndex';
    /** Fired before an index is deleted. */
    public const EVENT_BEFORE_DELETE_INDEX = 'beforeDeleteIndex';
    /** Fired after an index is deleted. */
    public const EVENT_AFTER_DELETE_INDEX = 'afterDeleteIndex';

    /** @var array<int, Index>|null Indexes cached by ID. */
    private ?array $_indexesById = null;
    /** @var array<string, Index>|null Indexes cached by handle. */
    private ?array $_indexesByHandle = null;

    /**
     * Return all indexes, ordered by sort order.
     *
     * @return Index[]
     */
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

    /**
     * Return a single index by its database ID.
     *
     * @param int $id
     * @return Index|null
     */
    public function getIndexById(int $id): ?Index
    {
        $indexes = $this->getAllIndexes();
        return $indexes[$id] ?? null;
    }

    /**
     * Return a single index by its handle.
     *
     * @param string $handle
     * @return Index|null
     */
    public function getIndexByHandle(string $handle): ?Index
    {
        $this->getAllIndexes();
        return $this->_indexesByHandle[$handle] ?? null;
    }

    /**
     * Return only indexes that are currently enabled.
     *
     * @return Index[]
     */
    public function getEnabledIndexes(): array
    {
        return array_filter($this->getAllIndexes(), fn(Index $index) => $index->enabled);
    }

    /**
     * Return enabled indexes whose section/entry-type filters match the given element.
     *
     * @param \craft\base\Element $element
     * @return Index[]
     */
    public function getIndexesForElement(\craft\base\Element $element): array
    {
        if (!$element instanceof \craft\elements\Entry) {
            return [];
        }

        $sectionId = $element->sectionId;
        $entryTypeId = $element->typeId;

        return array_filter($this->getEnabledIndexes(), function(Index $index) use ($sectionId, $entryTypeId) {
            if ($index->isReadOnly()) {
                return false;
            }
            $sectionMatch = empty($index->sectionIds) || in_array($sectionId, $index->sectionIds, true);
            $typeMatch = empty($index->entryTypeIds) || in_array($entryTypeId, $index->entryTypeIds, true);
            return $sectionMatch && $typeMatch;
        });
    }

    /**
     * Save an index to the project config (and indirectly to the database).
     *
     * @param Index $index
     * @param bool  $runValidation Whether to validate the model before saving.
     * @return bool
     */
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

    /**
     * Delete an index from the project config.
     *
     * @param Index $index
     * @return bool
     */
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

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_INDEX)) {
            $this->trigger(self::EVENT_AFTER_DELETE_INDEX, new IndexEvent([
                'index' => $index,
            ]));
        }

        return true;
    }

    /**
     * Apply a project config add/update event to the database.
     *
     * @param ConfigEvent $event
     * @return void
     */
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
        $record->mode = $data['mode'] ?? 'synced';
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

    /**
     * Apply a project config removal event by deleting the index and its field mappings.
     *
     * @param ConfigEvent $event
     * @return void
     */
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

    /**
     * Rebuild the project config data from database records.
     *
     * @return array
     */
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

    /**
     * Return all field mappings for a given index, ordered by sort order.
     *
     * @param int $indexId
     * @return FieldMapping[]
     */
    public function getFieldMappingsForIndex(int $indexId): array
    {
        $records = FieldMappingRecord::find()
            ->where(['indexId' => $indexId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return array_map(fn($record) => $this->_createFieldMappingFromRecord($record), $records);
    }

    /**
     * Replace field mappings for an index and persist via project config.
     *
     * @param int   $indexId
     * @param FieldMapping[] $mappings
     * @return bool
     */
    public function saveFieldMappings(int $indexId, array $mappings): bool
    {
        $index = $this->getIndexById($indexId);
        if (!$index) {
            return false;
        }

        $index->setFieldMappings($mappings);

        return $this->saveIndex($index, false);
    }

    /**
     * Delete existing field mapping records and recreate them from config data.
     *
     * @param int   $indexId
     * @param array $mappingsData Raw mapping arrays keyed by UID.
     * @return void
     */
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
            $record->role = $data['role'] ?? null;
            $record->enabled = $data['enabled'] ?? true;
            $record->weight = $data['weight'] ?? 5;
            $record->resolverConfig = $data['resolverConfig'] ?? null;
            $record->sortOrder = $data['sortOrder'] ?? 0;
            $record->uid = is_string($uid) && StringHelper::isUUID($uid) ? $uid : StringHelper::UUID();
            $record->save(false);
        }
    }

    /**
     * Hydrate an Index model from a database record.
     *
     * @param IndexRecord $record
     * @return Index
     */
    private function _createIndexFromRecord(IndexRecord $record): Index
    {
        $index = new Index();
        $index->id = $record->id;
        $index->name = $record->name;
        $index->handle = $record->handle;
        $index->engineType = $record->engineType;
        $index->engineConfig = is_string($record->engineConfig) ? json_decode($record->engineConfig, true, 512, JSON_THROW_ON_ERROR) : $record->engineConfig;
        $index->sectionIds = is_string($record->sectionIds) ? json_decode($record->sectionIds, true, 512, JSON_THROW_ON_ERROR) : $record->sectionIds;
        $index->entryTypeIds = is_string($record->entryTypeIds) ? json_decode($record->entryTypeIds, true, 512, JSON_THROW_ON_ERROR) : $record->entryTypeIds;
        $index->siteId = $record->siteId;
        $index->enabled = (bool)$record->enabled;
        $index->mode = $record->mode ?? 'synced';
        $index->sortOrder = (int)$record->sortOrder;
        $index->uid = $record->uid;

        // Load field mappings
        $index->setFieldMappings($this->getFieldMappingsForIndex($index->id));

        return $index;
    }

    /**
     * Hydrate a FieldMapping model from a database record.
     *
     * @param FieldMappingRecord $record
     * @return FieldMapping
     */
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
        $mapping->role = $record->role;
        $mapping->enabled = (bool)$record->enabled;
        $mapping->weight = (int)$record->weight;
        $mapping->resolverConfig = is_string($record->resolverConfig) ? json_decode($record->resolverConfig, true, 512, JSON_THROW_ON_ERROR) : $record->resolverConfig;
        $mapping->sortOrder = (int)$record->sortOrder;
        $mapping->uid = $record->uid;

        return $mapping;
    }
}
