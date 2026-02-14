<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\TypesenseEngine;
use cogapp\searchindex\models\FieldMapping;
use PHPUnit\Framework\TestCase;

class TypesenseSchemaTest extends TestCase
{
    private TypesenseEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TypesenseEngine();
    }

    public function testDisplayName(): void
    {
        $this->assertSame('Typesense', TypesenseEngine::displayName());
    }

    public function testMapFieldTypeText(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_TEXT);
        $this->assertSame(['type' => 'string', 'facet' => false], $result);
    }

    public function testMapFieldTypeKeyword(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_KEYWORD);
        $this->assertSame(['type' => 'string', 'facet' => true], $result);
    }

    public function testMapFieldTypeInteger(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_INTEGER);
        $this->assertSame(['type' => 'int32', 'facet' => false], $result);
    }

    public function testMapFieldTypeFloat(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_FLOAT);
        $this->assertSame(['type' => 'float', 'facet' => false], $result);
    }

    public function testMapFieldTypeBoolean(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_BOOLEAN);
        $this->assertSame(['type' => 'bool', 'facet' => false], $result);
    }

    public function testMapFieldTypeDate(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_DATE);
        $this->assertSame(['type' => 'int64', 'facet' => false], $result);
    }

    public function testMapFieldTypeGeoPoint(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_GEO_POINT);
        $this->assertSame(['type' => 'geopoint', 'facet' => false], $result);
    }

    public function testMapFieldTypeFacet(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_FACET);
        $this->assertSame(['type' => 'string[]', 'facet' => true], $result);
    }

    public function testMapFieldTypeObject(): void
    {
        $result = $this->engine->mapFieldType(FieldMapping::TYPE_OBJECT);
        $this->assertSame(['type' => 'object', 'facet' => false], $result);
    }

    public function testMapFieldTypeUnknown(): void
    {
        $result = $this->engine->mapFieldType('unknown');
        $this->assertSame(['type' => 'string', 'facet' => false], $result);
    }

    public function testBuildSchemaEmpty(): void
    {
        $fields = $this->engine->buildSchema([]);
        // Always includes sectionHandle + entryTypeHandle
        $this->assertCount(2, $fields);
        $this->assertSame('sectionHandle', $fields[0]['name']);
        $this->assertSame('entryTypeHandle', $fields[1]['name']);
    }

    public function testBuildSchemaTextField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;

        $fields = $this->engine->buildSchema([$mapping]);

        // 1 user field + 2 always-present fields
        $this->assertCount(3, $fields);
        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame('string', $fields[0]['type']);
        $this->assertFalse($fields[0]['facet']);
        $this->assertTrue($fields[0]['optional']);
        $this->assertArrayNotHasKey('sort', $fields[0]);
    }

    public function testBuildSchemaFacetField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'category';
        $mapping->indexFieldType = FieldMapping::TYPE_FACET;
        $mapping->enabled = true;

        $fields = $this->engine->buildSchema([$mapping]);

        $this->assertSame('string[]', $fields[0]['type']);
        $this->assertTrue($fields[0]['facet']);
    }

    public function testBuildSchemaNumericFieldsAreSortable(): void
    {
        $m1 = new FieldMapping();
        $m1->indexFieldName = 'count';
        $m1->indexFieldType = FieldMapping::TYPE_INTEGER;
        $m1->enabled = true;

        $m2 = new FieldMapping();
        $m2->indexFieldName = 'price';
        $m2->indexFieldType = FieldMapping::TYPE_FLOAT;
        $m2->enabled = true;

        $m3 = new FieldMapping();
        $m3->indexFieldName = 'publishedAt';
        $m3->indexFieldType = FieldMapping::TYPE_DATE;
        $m3->enabled = true;

        $fields = $this->engine->buildSchema([$m1, $m2, $m3]);

        // 3 user fields + 2 always-present fields
        $this->assertCount(5, $fields);

        // int32 should be sortable
        $this->assertTrue($fields[0]['sort']);
        // float should be sortable
        $this->assertTrue($fields[1]['sort']);
        // int64 (date) should be sortable
        $this->assertTrue($fields[2]['sort']);
    }

    public function testBuildSchemaStringFieldsNotSortable(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;

        $fields = $this->engine->buildSchema([$mapping]);

        $this->assertArrayNotHasKey('sort', $fields[0]);
    }

    public function testBuildSchemaSkipsDisabledMappings(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = false;

        $fields = $this->engine->buildSchema([$mapping]);
        // Only the 2 always-present fields remain
        $this->assertCount(2, $fields);
        $this->assertSame('sectionHandle', $fields[0]['name']);
        $this->assertSame('entryTypeHandle', $fields[1]['name']);
    }

    public function testBuildSchemaAllFieldsOptional(): void
    {
        $m1 = new FieldMapping();
        $m1->indexFieldName = 'title';
        $m1->indexFieldType = FieldMapping::TYPE_TEXT;
        $m1->enabled = true;

        $m2 = new FieldMapping();
        $m2->indexFieldName = 'count';
        $m2->indexFieldType = FieldMapping::TYPE_INTEGER;
        $m2->enabled = true;

        $fields = $this->engine->buildSchema([$m1, $m2]);

        foreach ($fields as $field) {
            $this->assertTrue($field['optional']);
        }
    }

    public function testBuildSchemaBooleanField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'isPublished';
        $mapping->indexFieldType = FieldMapping::TYPE_BOOLEAN;
        $mapping->enabled = true;

        $fields = $this->engine->buildSchema([$mapping]);

        $this->assertSame('bool', $fields[0]['type']);
        $this->assertFalse($fields[0]['facet']);
    }

    public function testBuildSchemaGeoPointField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'location';
        $mapping->indexFieldType = FieldMapping::TYPE_GEO_POINT;
        $mapping->enabled = true;

        $fields = $this->engine->buildSchema([$mapping]);

        $this->assertSame('geopoint', $fields[0]['type']);
    }
}
