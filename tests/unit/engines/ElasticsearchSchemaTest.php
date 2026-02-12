<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\ElasticsearchEngine;
use cogapp\searchindex\models\FieldMapping;
use PHPUnit\Framework\TestCase;

class ElasticsearchSchemaTest extends TestCase
{
    private ElasticsearchEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ElasticsearchEngine();
    }

    public function testDisplayName(): void
    {
        $this->assertSame('Elasticsearch', ElasticsearchEngine::displayName());
    }

    public function testConfigFieldsContainsIndexPrefix(): void
    {
        $fields = ElasticsearchEngine::configFields();
        $this->assertArrayHasKey('indexPrefix', $fields);
        $this->assertSame('text', $fields['indexPrefix']['type']);
    }

    public function testMapFieldTypeText(): void
    {
        $this->assertSame('text', $this->engine->mapFieldType(FieldMapping::TYPE_TEXT));
    }

    public function testMapFieldTypeKeyword(): void
    {
        $this->assertSame('keyword', $this->engine->mapFieldType(FieldMapping::TYPE_KEYWORD));
    }

    public function testMapFieldTypeInteger(): void
    {
        $this->assertSame('integer', $this->engine->mapFieldType(FieldMapping::TYPE_INTEGER));
    }

    public function testMapFieldTypeFloat(): void
    {
        $this->assertSame('float', $this->engine->mapFieldType(FieldMapping::TYPE_FLOAT));
    }

    public function testMapFieldTypeBoolean(): void
    {
        $this->assertSame('boolean', $this->engine->mapFieldType(FieldMapping::TYPE_BOOLEAN));
    }

    public function testMapFieldTypeDate(): void
    {
        $this->assertSame('date', $this->engine->mapFieldType(FieldMapping::TYPE_DATE));
    }

    public function testMapFieldTypeGeoPoint(): void
    {
        $this->assertSame('geo_point', $this->engine->mapFieldType(FieldMapping::TYPE_GEO_POINT));
    }

    public function testMapFieldTypeFacetMapsToKeyword(): void
    {
        $this->assertSame('keyword', $this->engine->mapFieldType(FieldMapping::TYPE_FACET));
    }

    public function testMapFieldTypeObject(): void
    {
        $this->assertSame('object', $this->engine->mapFieldType(FieldMapping::TYPE_OBJECT));
    }

    public function testMapFieldTypeUnknownDefaultsToText(): void
    {
        $this->assertSame('text', $this->engine->mapFieldType('unknown_type'));
    }

    public function testBuildSchemaEmpty(): void
    {
        $schema = $this->engine->buildSchema([]);
        $this->assertSame(['properties' => []], $schema);
    }

    public function testBuildSchemaSkipsDisabledMappings(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = false;

        $schema = $this->engine->buildSchema([$mapping]);
        $this->assertSame(['properties' => []], $schema);
    }

    public function testBuildSchemaSkipsNonFieldMappingObjects(): void
    {
        $schema = $this->engine->buildSchema([new \stdClass(), 'not a mapping']);
        $this->assertSame(['properties' => []], $schema);
    }

    public function testBuildSchemaTextField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertSame('text', $schema['properties']['title']['type']);
        // Text fields should have keyword sub-field
        $this->assertArrayHasKey('fields', $schema['properties']['title']);
        $this->assertSame('keyword', $schema['properties']['title']['fields']['keyword']['type']);
        $this->assertSame(256, $schema['properties']['title']['fields']['keyword']['ignore_above']);
    }

    public function testBuildSchemaDateFieldHasFormat(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'publishedAt';
        $mapping->indexFieldType = FieldMapping::TYPE_DATE;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame('date', $schema['properties']['publishedAt']['type']);
        $this->assertSame(
            'epoch_second||epoch_millis||strict_date_optional_time',
            $schema['properties']['publishedAt']['format']
        );
    }

    public function testBuildSchemaKeywordFieldHasNoSubFields(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'status';
        $mapping->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame('keyword', $schema['properties']['status']['type']);
        $this->assertArrayNotHasKey('fields', $schema['properties']['status']);
    }

    public function testBuildSchemaMultipleFields(): void
    {
        $m1 = new FieldMapping();
        $m1->indexFieldName = 'title';
        $m1->indexFieldType = FieldMapping::TYPE_TEXT;
        $m1->enabled = true;

        $m2 = new FieldMapping();
        $m2->indexFieldName = 'category';
        $m2->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $m2->enabled = true;

        $m3 = new FieldMapping();
        $m3->indexFieldName = 'price';
        $m3->indexFieldType = FieldMapping::TYPE_FLOAT;
        $m3->enabled = true;

        $m4 = new FieldMapping();
        $m4->indexFieldName = 'hidden';
        $m4->indexFieldType = FieldMapping::TYPE_TEXT;
        $m4->enabled = false;

        $schema = $this->engine->buildSchema([$m1, $m2, $m3, $m4]);

        $this->assertCount(3, $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('category', $schema['properties']);
        $this->assertArrayHasKey('price', $schema['properties']);
        $this->assertArrayNotHasKey('hidden', $schema['properties']);
    }

    public function testBuildSchemaBooleanField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'isPublished';
        $mapping->indexFieldType = FieldMapping::TYPE_BOOLEAN;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame('boolean', $schema['properties']['isPublished']['type']);
    }

    public function testBuildSchemaGeoPointField(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'location';
        $mapping->indexFieldType = FieldMapping::TYPE_GEO_POINT;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame('geo_point', $schema['properties']['location']['type']);
    }
}
