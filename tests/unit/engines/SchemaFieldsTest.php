<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AlgoliaEngine;
use cogapp\searchindex\engines\ElasticsearchEngine;
use cogapp\searchindex\engines\MeilisearchEngine;
use cogapp\searchindex\engines\TypesenseEngine;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests getSchemaFields() for all engine implementations.
 *
 * Uses partial mocks to override getIndexSchema() with test data.
 */
class SchemaFieldsTest extends TestCase
{
    private function createIndex(): Index
    {
        $index = new Index();
        $index->handle = 'test';
        return $index;
    }

    // -- Elasticsearch -------------------------------------------------------

    public function testElasticsearchSchemaFieldsFromProperties(): void
    {
        $engine = $this->createPartialMock(ElasticsearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text'],
                    'status' => ['type' => 'keyword'],
                    'price' => ['type' => 'float'],
                    'isPublished' => ['type' => 'boolean'],
                    'publishedAt' => ['type' => 'date'],
                    'location' => ['type' => 'geo_point'],
                    'metadata' => ['type' => 'object'],
                ],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(7, $fields);
        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[0]['type']);
        $this->assertSame('status', $fields[1]['name']);
        $this->assertSame(FieldMapping::TYPE_KEYWORD, $fields[1]['type']);
        $this->assertSame('price', $fields[2]['name']);
        $this->assertSame(FieldMapping::TYPE_FLOAT, $fields[2]['type']);
        $this->assertSame('isPublished', $fields[3]['name']);
        $this->assertSame(FieldMapping::TYPE_BOOLEAN, $fields[3]['type']);
        $this->assertSame('publishedAt', $fields[4]['name']);
        $this->assertSame(FieldMapping::TYPE_DATE, $fields[4]['type']);
        $this->assertSame('location', $fields[5]['name']);
        $this->assertSame(FieldMapping::TYPE_GEO_POINT, $fields[5]['type']);
        $this->assertSame('metadata', $fields[6]['name']);
        $this->assertSame(FieldMapping::TYPE_OBJECT, $fields[6]['type']);
    }

    public function testElasticsearchSchemaFieldsIntegerVariants(): void
    {
        $engine = $this->createPartialMock(ElasticsearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'mappings' => [
                'properties' => [
                    'count' => ['type' => 'integer'],
                    'bigCount' => ['type' => 'long'],
                ],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(2, $fields);
        $this->assertSame(FieldMapping::TYPE_INTEGER, $fields[0]['type']);
        $this->assertSame(FieldMapping::TYPE_INTEGER, $fields[1]['type']);
    }

    public function testElasticsearchSchemaFieldsEmpty(): void
    {
        $engine = $this->createPartialMock(ElasticsearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([]);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    public function testElasticsearchSchemaFieldsOnError(): void
    {
        $engine = $this->createPartialMock(ElasticsearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn(['error' => 'Connection failed']);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    public function testElasticsearchSchemaFieldsFlatProperties(): void
    {
        // Some responses put properties at top level without mappings wrapper
        $engine = $this->createPartialMock(ElasticsearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'properties' => [
                'title' => ['type' => 'text'],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertCount(1, $fields);
        $this->assertSame('title', $fields[0]['name']);
    }

    // -- Algolia -------------------------------------------------------------

    public function testAlgoliaSchemaFieldsFromSettings(): void
    {
        $engine = $this->createPartialMock(AlgoliaEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['title', 'body'],
            'attributesForFaceting' => ['searchable(category)', 'filterOnly(status)'],
            'numericAttributesForFiltering' => ['price'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(5, $fields);

        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[0]['type']);

        $this->assertSame('body', $fields[1]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[1]['type']);

        $this->assertSame('category', $fields[2]['name']);
        $this->assertSame(FieldMapping::TYPE_FACET, $fields[2]['type']);

        $this->assertSame('status', $fields[3]['name']);
        $this->assertSame(FieldMapping::TYPE_FACET, $fields[3]['type']);

        $this->assertSame('price', $fields[4]['name']);
        $this->assertSame(FieldMapping::TYPE_INTEGER, $fields[4]['type']);
    }

    public function testAlgoliaSchemaFieldsWithOrderedWrapper(): void
    {
        $engine = $this->createPartialMock(AlgoliaEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['ordered(title)', 'unordered(body)'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(2, $fields);
        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame('body', $fields[1]['name']);
    }

    public function testAlgoliaSchemaFieldsDeduplicates(): void
    {
        $engine = $this->createPartialMock(AlgoliaEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['title'],
            'attributesForFaceting' => ['searchable(title)'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        // title should only appear once
        $this->assertCount(1, $fields);
        $this->assertSame('title', $fields[0]['name']);
    }

    public function testAlgoliaSchemaFieldsEmpty(): void
    {
        $engine = $this->createPartialMock(AlgoliaEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([]);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    public function testAlgoliaSchemaFieldsOnError(): void
    {
        $engine = $this->createPartialMock(AlgoliaEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn(['error' => 'API key invalid']);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    // -- Meilisearch ---------------------------------------------------------

    public function testMeilisearchSchemaFieldsFromSettings(): void
    {
        $engine = $this->createPartialMock(MeilisearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['title', 'body'],
            'filterableAttributes' => ['category', 'status'],
            'sortableAttributes' => ['publishedAt'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(5, $fields);

        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[0]['type']);

        $this->assertSame('body', $fields[1]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[1]['type']);

        $this->assertSame('category', $fields[2]['name']);
        $this->assertSame(FieldMapping::TYPE_KEYWORD, $fields[2]['type']);

        $this->assertSame('status', $fields[3]['name']);
        $this->assertSame(FieldMapping::TYPE_KEYWORD, $fields[3]['type']);

        $this->assertSame('publishedAt', $fields[4]['name']);
        $this->assertSame(FieldMapping::TYPE_KEYWORD, $fields[4]['type']);
    }

    public function testMeilisearchSchemaFieldsDeduplicates(): void
    {
        $engine = $this->createPartialMock(MeilisearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['title'],
            'filterableAttributes' => ['title'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(1, $fields);
        $this->assertSame('title', $fields[0]['name']);
    }

    public function testMeilisearchSchemaFieldsSkipsWildcard(): void
    {
        $engine = $this->createPartialMock(MeilisearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'searchableAttributes' => ['*'],
            'filterableAttributes' => ['category'],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(1, $fields);
        $this->assertSame('category', $fields[0]['name']);
    }

    public function testMeilisearchSchemaFieldsEmpty(): void
    {
        $engine = $this->createPartialMock(MeilisearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([]);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    public function testMeilisearchSchemaFieldsOnError(): void
    {
        $engine = $this->createPartialMock(MeilisearchEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn(['error' => 'Index not found']);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    // -- Typesense -----------------------------------------------------------

    public function testTypesenseSchemaFieldsFromCollection(): void
    {
        $engine = $this->createPartialMock(TypesenseEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'name' => 'test',
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'facet' => false],
                ['name' => 'count', 'type' => 'int32', 'facet' => false],
                ['name' => 'price', 'type' => 'float', 'facet' => false],
                ['name' => 'isPublished', 'type' => 'bool', 'facet' => false],
                ['name' => 'categories', 'type' => 'string[]', 'facet' => true],
                ['name' => 'location', 'type' => 'geopoint', 'facet' => false],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(6, $fields);
        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame(FieldMapping::TYPE_TEXT, $fields[0]['type']);
        $this->assertSame('count', $fields[1]['name']);
        $this->assertSame(FieldMapping::TYPE_INTEGER, $fields[1]['type']);
        $this->assertSame('price', $fields[2]['name']);
        $this->assertSame(FieldMapping::TYPE_FLOAT, $fields[2]['type']);
        $this->assertSame('isPublished', $fields[3]['name']);
        $this->assertSame(FieldMapping::TYPE_BOOLEAN, $fields[3]['type']);
        $this->assertSame('categories', $fields[4]['name']);
        $this->assertSame(FieldMapping::TYPE_FACET, $fields[4]['type']);
        $this->assertSame('location', $fields[5]['name']);
        $this->assertSame(FieldMapping::TYPE_GEO_POINT, $fields[5]['type']);
    }

    public function testTypesenseSchemaFieldsSkipsWildcard(): void
    {
        $engine = $this->createPartialMock(TypesenseEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'name' => 'test',
            'fields' => [
                ['name' => '.*', 'type' => 'auto'],
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(1, $fields);
        $this->assertSame('title', $fields[0]['name']);
    }

    public function testTypesenseSchemaFieldsInt64MapsToInteger(): void
    {
        $engine = $this->createPartialMock(TypesenseEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([
            'name' => 'test',
            'fields' => [
                ['name' => 'publishedAt', 'type' => 'int64'],
            ],
        ]);

        $fields = $engine->getSchemaFields($this->createIndex());

        $this->assertCount(1, $fields);
        $this->assertSame(FieldMapping::TYPE_INTEGER, $fields[0]['type']);
    }

    public function testTypesenseSchemaFieldsEmpty(): void
    {
        $engine = $this->createPartialMock(TypesenseEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn([]);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }

    public function testTypesenseSchemaFieldsOnError(): void
    {
        $engine = $this->createPartialMock(TypesenseEngine::class, ['getIndexSchema']);
        $engine->method('getIndexSchema')->willReturn(['error' => 'Collection not found']);

        $fields = $engine->getSchemaFields($this->createIndex());
        $this->assertSame([], $fields);
    }
}
