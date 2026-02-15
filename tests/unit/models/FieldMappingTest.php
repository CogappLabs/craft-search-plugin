<?php

namespace cogapp\searchindex\tests\unit\models;

use cogapp\searchindex\models\FieldMapping;
use PHPUnit\Framework\TestCase;

class FieldMappingTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $mapping = new FieldMapping();

        $this->assertNull($mapping->id);
        $this->assertNull($mapping->indexId);
        $this->assertNull($mapping->fieldUid);
        $this->assertNull($mapping->parentFieldUid);
        $this->assertNull($mapping->attribute);
        $this->assertSame('', $mapping->indexFieldName);
        $this->assertSame(FieldMapping::TYPE_TEXT, $mapping->indexFieldType);
        $this->assertTrue($mapping->enabled);
        $this->assertSame(5, $mapping->weight);
        $this->assertNull($mapping->resolverConfig);
        $this->assertSame(0, $mapping->sortOrder);
        $this->assertNull($mapping->uid);
    }

    public function testFieldTypeConstants(): void
    {
        $this->assertSame('text', FieldMapping::TYPE_TEXT);
        $this->assertSame('keyword', FieldMapping::TYPE_KEYWORD);
        $this->assertSame('integer', FieldMapping::TYPE_INTEGER);
        $this->assertSame('float', FieldMapping::TYPE_FLOAT);
        $this->assertSame('boolean', FieldMapping::TYPE_BOOLEAN);
        $this->assertSame('date', FieldMapping::TYPE_DATE);
        $this->assertSame('geo_point', FieldMapping::TYPE_GEO_POINT);
        $this->assertSame('facet', FieldMapping::TYPE_FACET);
        $this->assertSame('object', FieldMapping::TYPE_OBJECT);
        $this->assertSame('embedding', FieldMapping::TYPE_EMBEDDING);
    }

    public function testFieldTypesArrayContainsAllTypes(): void
    {
        $expected = [
            'text', 'keyword', 'integer', 'float', 'boolean',
            'date', 'geo_point', 'facet', 'object', 'embedding',
        ];

        $this->assertSame($expected, FieldMapping::FIELD_TYPES);
        $this->assertCount(10, FieldMapping::FIELD_TYPES);
    }

    public function testIsAttributeReturnsFalseByDefault(): void
    {
        $mapping = new FieldMapping();
        $this->assertFalse($mapping->isAttribute());
    }

    public function testIsAttributeReturnsTrueWhenAttributeSet(): void
    {
        $mapping = new FieldMapping();
        $mapping->attribute = 'title';
        $this->assertTrue($mapping->isAttribute());
    }

    public function testIsSubFieldReturnsFalseByDefault(): void
    {
        $mapping = new FieldMapping();
        $this->assertFalse($mapping->isSubField());
    }

    public function testIsSubFieldReturnsTrueWhenParentFieldUidSet(): void
    {
        $mapping = new FieldMapping();
        $mapping->parentFieldUid = 'abc-123';
        $this->assertTrue($mapping->isSubField());
    }

    public function testGetConfigReturnsCorrectStructure(): void
    {
        $mapping = new FieldMapping();
        $mapping->fieldUid = 'field-uid-1';
        $mapping->parentFieldUid = 'parent-uid-1';
        $mapping->attribute = 'title';
        $mapping->indexFieldName = 'myField';
        $mapping->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $mapping->enabled = false;
        $mapping->weight = 8;
        $mapping->resolverConfig = ['format' => 'titles'];
        $mapping->sortOrder = 3;

        $config = $mapping->getConfig();

        $this->assertSame('field-uid-1', $config['fieldUid']);
        $this->assertSame('parent-uid-1', $config['parentFieldUid']);
        $this->assertSame('title', $config['attribute']);
        $this->assertSame('myField', $config['indexFieldName']);
        $this->assertSame('keyword', $config['indexFieldType']);
        $this->assertFalse($config['enabled']);
        $this->assertSame(8, $config['weight']);
        $this->assertSame(['format' => 'titles'], $config['resolverConfig']);
        $this->assertSame(3, $config['sortOrder']);
    }

    public function testGetConfigExcludesNonConfigFields(): void
    {
        $mapping = new FieldMapping();
        $mapping->id = 42;
        $mapping->indexId = 7;
        $mapping->uid = 'some-uid';

        $config = $mapping->getConfig();

        $this->assertArrayNotHasKey('id', $config);
        $this->assertArrayNotHasKey('indexId', $config);
        $this->assertArrayNotHasKey('uid', $config);
    }
}
