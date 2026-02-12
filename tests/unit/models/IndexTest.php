<?php

namespace cogapp\searchindex\tests\unit\models;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $index = new Index();

        $this->assertNull($index->id);
        $this->assertSame('', $index->name);
        $this->assertSame('', $index->handle);
        $this->assertSame('', $index->engineType);
        $this->assertNull($index->engineConfig);
        $this->assertNull($index->sectionIds);
        $this->assertNull($index->entryTypeIds);
        $this->assertNull($index->siteId);
        $this->assertTrue($index->enabled);
        $this->assertSame(0, $index->sortOrder);
        $this->assertNull($index->uid);
    }

    public function testGetFieldMappingsReturnsEmptyByDefault(): void
    {
        $index = new Index();
        $this->assertSame([], $index->getFieldMappings());
    }

    public function testSetAndGetFieldMappings(): void
    {
        $index = new Index();

        $mapping1 = new FieldMapping();
        $mapping1->indexFieldName = 'title';
        $mapping1->uid = 'uid-1';

        $mapping2 = new FieldMapping();
        $mapping2->indexFieldName = 'body';
        $mapping2->uid = 'uid-2';

        $index->setFieldMappings([$mapping1, $mapping2]);

        $result = $index->getFieldMappings();
        $this->assertCount(2, $result);
        $this->assertSame('title', $result[0]->indexFieldName);
        $this->assertSame('body', $result[1]->indexFieldName);
    }

    public function testGetConfigReturnsCorrectStructure(): void
    {
        $index = new Index();
        $index->name = 'Test Index';
        $index->handle = 'testIndex';
        $index->engineType = 'cogapp\\searchindex\\engines\\ElasticsearchEngine';
        $index->engineConfig = ['indexPrefix' => 'prod_'];
        $index->sectionIds = [1, 2];
        $index->entryTypeIds = [3];
        $index->siteId = 1;
        $index->enabled = true;
        $index->sortOrder = 2;

        $mapping = new FieldMapping();
        $mapping->uid = 'mapping-uid-1';
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;

        $index->setFieldMappings([$mapping]);

        $config = $index->getConfig();

        $this->assertSame('Test Index', $config['name']);
        $this->assertSame('testIndex', $config['handle']);
        $this->assertSame('cogapp\\searchindex\\engines\\ElasticsearchEngine', $config['engineType']);
        $this->assertSame(['indexPrefix' => 'prod_'], $config['engineConfig']);
        $this->assertSame([1, 2], $config['sectionIds']);
        $this->assertSame([3], $config['entryTypeIds']);
        $this->assertSame(1, $config['siteId']);
        $this->assertTrue($config['enabled']);
        $this->assertSame(2, $config['sortOrder']);
        $this->assertArrayHasKey('fieldMappings', $config);
        $this->assertArrayHasKey('mapping-uid-1', $config['fieldMappings']);
    }

    public function testGetConfigFieldMappingsKeyedByUid(): void
    {
        $index = new Index();
        $index->name = 'Test';
        $index->handle = 'test';
        $index->engineType = 'TestEngine';

        $m1 = new FieldMapping();
        $m1->uid = 'aaa';
        $m1->indexFieldName = 'field_a';

        $m2 = new FieldMapping();
        $m2->uid = 'bbb';
        $m2->indexFieldName = 'field_b';

        $index->setFieldMappings([$m1, $m2]);

        $config = $index->getConfig();

        $this->assertArrayHasKey('aaa', $config['fieldMappings']);
        $this->assertArrayHasKey('bbb', $config['fieldMappings']);
        $this->assertSame('field_a', $config['fieldMappings']['aaa']['indexFieldName']);
        $this->assertSame('field_b', $config['fieldMappings']['bbb']['indexFieldName']);
    }
}
