<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\OpenSearchEngine;
use cogapp\searchindex\models\FieldMapping;
use PHPUnit\Framework\TestCase;

class OpenSearchSchemaTest extends TestCase
{
    private OpenSearchEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new OpenSearchEngine();
    }

    public function testDisplayName(): void
    {
        $this->assertSame('OpenSearch', OpenSearchEngine::displayName());
    }

    public function testMapFieldTypeText(): void
    {
        $this->assertSame('text', $this->engine->mapFieldType(FieldMapping::TYPE_TEXT));
    }

    public function testMapFieldTypeFacetMapsToKeyword(): void
    {
        $this->assertSame('keyword', $this->engine->mapFieldType(FieldMapping::TYPE_FACET));
    }

    public function testMapFieldTypeDate(): void
    {
        $this->assertSame('date', $this->engine->mapFieldType(FieldMapping::TYPE_DATE));
    }

    public function testBuildSchemaProducesProperties(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'title';
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertSame('text', $schema['properties']['title']['type']);
        // Text fields should have keyword sub-field
        $this->assertArrayHasKey('fields', $schema['properties']['title']);
    }

    public function testBuildSchemaDateFieldHasFormat(): void
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldName = 'publishedAt';
        $mapping->indexFieldType = FieldMapping::TYPE_DATE;
        $mapping->enabled = true;

        $schema = $this->engine->buildSchema([$mapping]);

        $this->assertSame(
            'epoch_second||epoch_millis||strict_date_optional_time',
            $schema['properties']['publishedAt']['format']
        );
    }
}
