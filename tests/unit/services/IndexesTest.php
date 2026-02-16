<?php

namespace cogapp\searchindex\tests\unit\services;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\records\FieldMappingRecord;
use cogapp\searchindex\records\IndexRecord;
use cogapp\searchindex\services\Indexes;
use cogapp\searchindex\tests\unit\services\Support\TestApp;
use craft\events\ConfigEvent;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

class IndexesTest extends TestCase
{
    private mixed $previousApp = null;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\Yii::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
        }
        if (!class_exists(\Craft::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }
    }

    protected function setUp(): void
    {
        $this->previousApp = \Yii::$app ?? null;
    }

    protected function tearDown(): void
    {
        \Yii::$app = $this->previousApp;
    }

    public function testBuildAndHydrateCacheDataRoundTrip(): void
    {
        $service = new Indexes();

        $mapping = new FieldMapping();
        $mapping->id = 11;
        $mapping->indexId = 7;
        $mapping->fieldUid = 'field-uid';
        $mapping->parentFieldUid = 'parent-uid';
        $mapping->attribute = 'title';
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->role = FieldMapping::ROLE_TITLE;
        $mapping->enabled = true;
        $mapping->weight = 9;
        $mapping->resolverConfig = ['format' => 'raw'];
        $mapping->sortOrder = 3;
        $mapping->uid = 'mapping-uid';

        $index = new Index();
        $index->id = 7;
        $index->name = 'Articles';
        $index->handle = 'articles';
        $index->engineType = 'stub\\Engine';
        $index->engineConfig = ['host' => '127.0.0.1'];
        $index->sectionIds = [1, 2];
        $index->entryTypeIds = [3];
        $index->siteId = 1;
        $index->enabled = true;
        $index->mode = Index::MODE_READONLY;
        $index->sortOrder = 4;
        $index->uid = 'index-uid';
        $index->setFieldMappings([$mapping]);

        $cacheData = $this->invokePrivateMethod($service, '_buildCacheData', [$index]);
        $hydrated = $this->invokePrivateMethod($service, '_hydrateIndexFromCacheData', [$cacheData]);

        $this->assertSame('articles', $cacheData['handle']);
        $this->assertSame('title', $cacheData['fieldMappings'][0]['indexFieldName']);

        $this->assertInstanceOf(Index::class, $hydrated);
        $this->assertSame(7, $hydrated->id);
        $this->assertSame(Index::MODE_READONLY, $hydrated->mode);
        $this->assertTrue($hydrated->enabled);

        $hydratedMappings = $hydrated->getFieldMappings();
        $this->assertCount(1, $hydratedMappings);
        $this->assertSame('title', $hydratedMappings[0]->indexFieldName);
        $this->assertSame(9, $hydratedMappings[0]->weight);
        $this->assertSame(['format' => 'raw'], $hydratedMappings[0]->resolverConfig);
    }

    public function testGetIndexByHandleAndEnabledIndexesUseWarmInMemoryCache(): void
    {
        $service = new Indexes();

        $enabled = new Index();
        $enabled->id = 1;
        $enabled->handle = 'enabled';
        $enabled->enabled = true;

        $disabled = new Index();
        $disabled->id = 2;
        $disabled->handle = 'disabled';
        $disabled->enabled = false;

        $this->setPrivateProperty($service, '_indexesById', [
            1 => $enabled,
            2 => $disabled,
        ]);
        $this->setPrivateProperty($service, '_indexesByHandle', [
            'enabled' => $enabled,
            'disabled' => $disabled,
        ]);

        $this->assertSame($enabled, $service->getIndexByHandle('enabled'));
        $this->assertNull($service->getIndexByHandle('missing'));

        $enabledOnly = $service->getEnabledIndexes();
        $this->assertCount(1, $enabledOnly);
        $this->assertSame($enabled, array_values($enabledOnly)[0]);
    }

    public function testSaveFieldMappingsReturnsFalseWhenIndexMissing(): void
    {
        $service = new Indexes();
        $this->setPrivateProperty($service, '_indexesById', []);
        $this->setPrivateProperty($service, '_indexesByHandle', []);

        $this->assertFalse($service->saveFieldMappings(999, []));
    }

    public function testHandleChangedIndexCreatesRecordAndSyncsMappings(): void
    {
        $app = $this->createTestAppWithSchema();
        \Yii::$app = $app;

        $service = new Indexes();
        $uid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $event = new ConfigEvent([
            'path' => Indexes::CONFIG_KEY . '.' . $uid,
            'tokenMatches' => [$uid],
            'newValue' => [
                'name' => 'Articles',
                'handle' => 'articles',
                'engineType' => 'stub\\Engine',
                'engineConfig' => ['host' => 'localhost'],
                'sectionIds' => [1],
                'entryTypeIds' => [2],
                'siteId' => 1,
                'enabled' => true,
                'mode' => 'readonly',
                'sortOrder' => 7,
                'fieldMappings' => [
                    '11111111-1111-4111-8111-111111111111' => [
                        'indexFieldName' => 'title',
                        'indexFieldType' => 'text',
                        'enabled' => true,
                        'weight' => 9,
                        'sortOrder' => 0,
                    ],
                    'not-a-uuid' => [
                        'indexFieldName' => 'summary',
                        'indexFieldType' => 'keyword',
                        'enabled' => false,
                        'weight' => 5,
                        'sortOrder' => 1,
                    ],
                ],
            ],
        ]);

        $service->handleChangedIndex($event);

        $record = IndexRecord::findOne(['uid' => $uid]);
        $this->assertNotNull($record);
        $this->assertSame('Articles', $record->name);
        $this->assertSame('articles', $record->handle);
        $this->assertSame('readonly', $record->mode);
        $this->assertSame(7, (int)$record->sortOrder);

        $mappings = FieldMappingRecord::find()->where(['indexId' => $record->id])->orderBy(['sortOrder' => SORT_ASC])->all();
        $this->assertCount(2, $mappings);
        $this->assertSame('title', $mappings[0]->indexFieldName);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $mappings[0]->uid);
        $this->assertSame('summary', $mappings[1]->indexFieldName);
        $this->assertNotSame('not-a-uuid', $mappings[1]->uid);
    }

    public function testHandleDeletedIndexRemovesRecordAndMappings(): void
    {
        $app = $this->createTestAppWithSchema();
        \Yii::$app = $app;

        $indexUid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $app->db->createCommand()->insert('searchindex_indexes', [
            'name' => 'To Delete',
            'handle' => 'toDelete',
            'engineType' => 'stub\\Engine',
            'engineConfig' => '{}',
            'sectionIds' => '[]',
            'entryTypeIds' => '[]',
            'siteId' => null,
            'enabled' => 1,
            'mode' => 'synced',
            'sortOrder' => 0,
            'dateCreated' => '2026-02-16 00:00:00',
            'dateUpdated' => '2026-02-16 00:00:00',
            'uid' => $indexUid,
        ])->execute();

        $indexId = (int)$app->db->getLastInsertID();

        $app->db->createCommand()->insert('searchindex_field_mappings', [
            'indexId' => $indexId,
            'fieldUid' => null,
            'parentFieldUid' => null,
            'attribute' => null,
            'indexFieldName' => 'title',
            'indexFieldType' => 'text',
            'role' => null,
            'enabled' => 1,
            'weight' => 5,
            'resolverConfig' => null,
            'sortOrder' => 0,
            'dateCreated' => '2026-02-16 00:00:00',
            'dateUpdated' => '2026-02-16 00:00:00',
            'uid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ])->execute();

        $service = new Indexes();
        $service->handleDeletedIndex(new ConfigEvent([
            'path' => Indexes::CONFIG_KEY . '.' . $indexUid,
            'tokenMatches' => [$indexUid],
        ]));

        $this->assertNull(IndexRecord::findOne(['uid' => $indexUid]));
        $this->assertSame(0, (int)FieldMappingRecord::find()->where(['indexId' => $indexId])->count());
    }

    private function invokePrivateMethod(object $object, string $name, array $args = []): mixed
    {
        $ref = new \ReflectionClass($object);
        $method = $ref->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    private function setPrivateProperty(object $object, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    private function createTestAppWithSchema(): TestApp
    {
        $db = new Connection(['dsn' => 'sqlite::memory:']);
        $db->open();

        $db->createCommand(<<<'SQL'
CREATE TABLE searchindex_indexes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    handle TEXT NOT NULL UNIQUE,
    engineType TEXT NOT NULL,
    engineConfig JSON NULL,
    sectionIds JSON NULL,
    entryTypeIds JSON NULL,
    siteId INTEGER NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    mode TEXT NOT NULL DEFAULT 'synced',
    sortOrder INTEGER NOT NULL DEFAULT 0,
    dateCreated TEXT NOT NULL,
    dateUpdated TEXT NOT NULL,
    uid TEXT NOT NULL
)
SQL)->execute();

        $db->createCommand(<<<'SQL'
CREATE TABLE searchindex_field_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    indexId INTEGER NOT NULL,
    fieldUid TEXT NULL,
    parentFieldUid TEXT NULL,
    attribute TEXT NULL,
    indexFieldName TEXT NOT NULL,
    indexFieldType TEXT NOT NULL DEFAULT 'text',
    role TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    weight INTEGER NOT NULL DEFAULT 5,
    resolverConfig JSON NULL,
    sortOrder INTEGER NOT NULL DEFAULT 0,
    dateCreated TEXT NOT NULL,
    dateUpdated TEXT NOT NULL,
    uid TEXT NOT NULL
)
SQL)->execute();

        return new TestApp($db);
    }
}
