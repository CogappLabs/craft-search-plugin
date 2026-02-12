<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\MeilisearchEngine;
use cogapp\searchindex\models\FieldMapping;
use PHPUnit\Framework\TestCase;

class MeilisearchSchemaTest extends TestCase
{
    private MeilisearchEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new MeilisearchEngine();
    }

    public function testDisplayName(): void
    {
        $this->assertSame('Meilisearch', MeilisearchEngine::displayName());
    }

    public function testMapFieldTypeText(): void
    {
        $this->assertSame('searchableAttributes', $this->engine->mapFieldType(FieldMapping::TYPE_TEXT));
    }

    public function testMapFieldTypeKeyword(): void
    {
        $this->assertSame('filterableAttributes', $this->engine->mapFieldType(FieldMapping::TYPE_KEYWORD));
    }

    public function testMapFieldTypeFacet(): void
    {
        $this->assertSame('filterableAttributes', $this->engine->mapFieldType(FieldMapping::TYPE_FACET));
    }

    public function testMapFieldTypeBoolean(): void
    {
        $this->assertSame('filterableAttributes', $this->engine->mapFieldType(FieldMapping::TYPE_BOOLEAN));
    }

    public function testMapFieldTypeIntegerIsFilterableAndSortable(): void
    {
        $this->assertSame('filterableAndSortable', $this->engine->mapFieldType(FieldMapping::TYPE_INTEGER));
    }

    public function testMapFieldTypeFloatIsFilterableAndSortable(): void
    {
        $this->assertSame('filterableAndSortable', $this->engine->mapFieldType(FieldMapping::TYPE_FLOAT));
    }

    public function testMapFieldTypeDateIsFilterableAndSortable(): void
    {
        $this->assertSame('filterableAndSortable', $this->engine->mapFieldType(FieldMapping::TYPE_DATE));
    }

    public function testMapFieldTypeGeoPointIsFilterableAndSortable(): void
    {
        $this->assertSame('filterableAndSortable', $this->engine->mapFieldType(FieldMapping::TYPE_GEO_POINT));
    }

    public function testMapFieldTypeObject(): void
    {
        $this->assertSame('searchableAttributes', $this->engine->mapFieldType(FieldMapping::TYPE_OBJECT));
    }

    public function testMapFieldTypeUnknownDefaultsToSearchable(): void
    {
        $this->assertSame('searchableAttributes', $this->engine->mapFieldType('unknown'));
    }

    public function testBuildSchemaEmpty(): void
    {
        $schema = $this->engine->buildSchema([]);
        $this->assertSame([], $schema);
    }

    public function testBuildSchemaSearchableAttributes(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;
        $mapping->weight = 10;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertArrayHasKey('searchableAttributes', $schema);
        $this->assertContains('title', $schema['searchableAttributes']);
    }

    public function testBuildSchemaSearchableAttributesSortedByWeight(): void
    {
        $m1 = new FieldMapping();
        $m1->indexFieldName = 'body';
        $m1->indexFieldType = FieldMapping::TYPE_TEXT;
        $m1->enabled = true;
        $m1->weight = 3;

        $m2 = new FieldMapping();
        $m2->indexFieldName = 'title';
        $m2->indexFieldType = FieldMapping::TYPE_TEXT;
        $m2->enabled = true;
        $m2->weight = 10;

        $m3 = new FieldMapping();
        $m3->indexFieldName = 'summary';
        $m3->indexFieldType = FieldMapping::TYPE_TEXT;
        $m3->enabled = true;
        $m3->weight = 7;

        $schema = $this->engine->buildSchema([$m1, $m2, $m3]);

        // Should be ordered: title (10), summary (7), body (3)
        $this->assertSame(['title', 'summary', 'body'], $schema['searchableAttributes']);
    }

    public function testBuildSchemaFilterableAttributes(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'category';
        $mapping->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertArrayHasKey('filterableAttributes', $schema);
        $this->assertContains('category', $schema['filterableAttributes']);
        $this->assertArrayNotHasKey('searchableAttributes', $schema);
    }

    public function testBuildSchemaFilterableAndSortableAttributes(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'price';
        $mapping->indexFieldType = FieldMapping::TYPE_FLOAT;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertContains('price', $schema['filterableAttributes']);
        $this->assertContains('price', $schema['sortableAttributes']);
    }

    public function testBuildSchemaSkipsDisabledMappings(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = false;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame([], $schema);
    }

    public function testBuildSchemaDeduplicatesAttributes(): void
    {
        $m1 = new FieldMapping();
        $m1->indexFieldName = 'category';
        $m1->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $m1->enabled = true;

        $m2 = new FieldMapping();
        $m2->indexFieldName = 'tags';
        $m2->indexFieldType = FieldMapping::TYPE_FACET;
        $m2->enabled = true;

        $schema = $this->engine->buildSchema([$m1, $m2]);

        // Both keyword and facet map to filterableAttributes
        $this->assertCount(2, $schema['filterableAttributes']);
    }

    public function testBuildSchemaMixedTypes(): void
    {
        $text = new FieldMapping();
        $text->indexFieldName = 'title';
        $text->indexFieldType = FieldMapping::TYPE_TEXT;
        $text->enabled = true;
        $text->weight = 10;

        $keyword = new FieldMapping();
        $keyword->indexFieldName = 'status';
        $keyword->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $keyword->enabled = true;

        $numeric = new FieldMapping();
        $numeric->indexFieldName = 'price';
        $numeric->indexFieldType = FieldMapping::TYPE_INTEGER;
        $numeric->enabled = true;

        $schema = $this->engine->buildSchema([$text, $keyword, $numeric]);

        $this->assertSame(['title'], $schema['searchableAttributes']);
        $this->assertContains('status', $schema['filterableAttributes']);
        $this->assertContains('price', $schema['filterableAttributes']);
        $this->assertContains('price', $schema['sortableAttributes']);
    }
}
