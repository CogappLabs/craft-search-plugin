<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\ElasticCompatEngine;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that exposes protected methods and stubs the client.
 */
class StubElasticCompatEngine extends ElasticCompatEngine
{
    public static function displayName(): string
    {
        return 'StubElasticCompat';
    }

    public static function requiredPackage(): string
    {
        return 'stub/elastic-compat';
    }

    public static function isClientInstalled(): bool
    {
        return true;
    }

    public function indexExists(Index $index): bool
    {
        return false;
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
    }

    public function testConnection(): bool
    {
        return false;
    }

    protected function getClient(): mixed
    {
        throw new \RuntimeException('No client in test stub');
    }

    public function publicBuildNativeFilterParams(array $filters, Index $index): mixed
    {
        return $this->buildNativeFilterParams($filters, $index);
    }

    public function publicNormaliseRawStats(array $response, array $statsFields = []): array
    {
        return $this->normaliseRawStats($response, $statsFields);
    }
}

/**
 * Unit tests for ElasticCompatEngine range filter and stats support.
 */
class ElasticCompatRangeFilterTest extends TestCase
{
    private StubElasticCompatEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubElasticCompatEngine();
    }

    private function createIndexWithFloatField(string $fieldName): Index
    {
        $index = new Index();
        $index->handle = 'test';

        $mapping = new FieldMapping();
        $mapping->indexFieldName = $fieldName;
        $mapping->indexFieldType = FieldMapping::TYPE_FLOAT;
        $mapping->enabled = true;

        $ref = new \ReflectionProperty(Index::class, '_fieldMappings');
        $ref->setAccessible(true);
        $ref->setValue($index, [$mapping]);

        return $index;
    }

    private function createEmptyIndex(): Index
    {
        $index = new Index();
        $index->handle = 'test';

        $ref = new \ReflectionProperty(Index::class, '_fieldMappings');
        $ref->setAccessible(true);
        $ref->setValue($index, []);

        return $index;
    }

    private function createIndexWithTextField(string $fieldName): Index
    {
        $index = new Index();
        $index->handle = 'test';

        $mapping = new FieldMapping();
        $mapping->indexFieldName = $fieldName;
        $mapping->indexFieldType = FieldMapping::TYPE_TEXT;
        $mapping->enabled = true;

        $ref = new \ReflectionProperty(Index::class, '_fieldMappings');
        $ref->setAccessible(true);
        $ref->setValue($index, [$mapping]);

        return $index;
    }

    // -- buildNativeFilterParams (range) --------------------------------------

    public function testRangeFilterMinAndMax(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['population' => ['min' => 1000, 'max' => 50000]],
            $index,
        );

        $this->assertCount(1, $clauses);
        $this->assertSame(['range' => ['population' => ['gte' => 1000, 'lte' => 50000]]], $clauses[0]);
    }

    public function testRangeFilterMinOnly(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['population' => ['min' => 500]],
            $index,
        );

        $this->assertCount(1, $clauses);
        $this->assertSame(['range' => ['population' => ['gte' => 500]]], $clauses[0]);
    }

    public function testRangeFilterMaxOnly(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['population' => ['max' => 10000]],
            $index,
        );

        $this->assertCount(1, $clauses);
        $this->assertSame(['range' => ['population' => ['lte' => 10000]]], $clauses[0]);
    }

    public function testMixedRangeAndEqualityFilters(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            [
                'country' => ['UK', 'FR'],
                'population' => ['min' => 1000, 'max' => 50000],
                'status' => 'active',
            ],
            $index,
        );

        $this->assertCount(3, $clauses);
        $this->assertSame(['terms' => ['country' => ['UK', 'FR']]], $clauses[0]);
        $this->assertSame(['range' => ['population' => ['gte' => 1000, 'lte' => 50000]]], $clauses[1]);
        $this->assertSame(['term' => ['status' => 'active']], $clauses[2]);
    }

    public function testRangeFilterEmptyStringMinIgnored(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['population' => ['min' => '', 'max' => 5000]],
            $index,
        );

        $this->assertCount(1, $clauses);
        $this->assertSame(['range' => ['population' => ['lte' => 5000]]], $clauses[0]);
    }

    public function testRangeFilterEmptyStringBothSkipped(): void
    {
        $index = $this->createEmptyIndex();
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['population' => ['min' => '', 'max' => '']],
            $index,
        );

        $this->assertCount(0, $clauses);
    }

    public function testRangeFilterDoesNotUseKeywordSuffix(): void
    {
        $index = $this->createIndexWithTextField('description');
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['description' => ['min' => 10, 'max' => 100]],
            $index,
        );

        $this->assertCount(1, $clauses);
        // Range queries must use the bare field name, never .keyword
        $this->assertSame(['range' => ['description' => ['gte' => 10, 'lte' => 100]]], $clauses[0]);
    }

    public function testEqualityFilterUsesKeywordSuffix(): void
    {
        $index = $this->createIndexWithTextField('description');
        $clauses = $this->engine->publicBuildNativeFilterParams(
            ['description' => 'test'],
            $index,
        );

        $this->assertCount(1, $clauses);
        // Equality filters on text fields must use .keyword suffix
        $this->assertSame(['term' => ['description.keyword' => 'test']], $clauses[0]);
    }

    // -- normaliseRawStats ----------------------------------------------------

    public function testNormaliseRawStatsExtractsMinMax(): void
    {
        $response = [
            'aggregations' => [
                'population_stats' => [
                    'count' => 42,
                    'min' => 150.0,
                    'max' => 98765.0,
                    'avg' => 5000.0,
                    'sum' => 210000.0,
                ],
            ],
        ];

        $stats = $this->engine->publicNormaliseRawStats($response, ['population']);

        $this->assertSame([
            'population' => ['min' => 150.0, 'max' => 98765.0],
        ], $stats);
    }

    public function testNormaliseRawStatsIgnoresUnrequestedFields(): void
    {
        $response = [
            'aggregations' => [
                'population_stats' => ['min' => 100, 'max' => 5000],
                'area_stats' => ['min' => 10, 'max' => 1000],
            ],
        ];

        $stats = $this->engine->publicNormaliseRawStats($response, ['population']);

        $this->assertArrayHasKey('population', $stats);
        $this->assertArrayNotHasKey('area', $stats);
    }

    public function testNormaliseRawStatsReturnsEmptyForMissingAggs(): void
    {
        $stats = $this->engine->publicNormaliseRawStats([], ['population']);

        $this->assertSame([], $stats);
    }

    public function testNormaliseRawStatsNoFields(): void
    {
        $response = [
            'aggregations' => [
                'population_stats' => ['min' => 100, 'max' => 5000],
            ],
        ];

        $stats = $this->engine->publicNormaliseRawStats($response, []);

        $this->assertSame([], $stats);
    }
}
