<?php

namespace cogapp\searchindex\tests\unit\engines;

use PHPUnit\Framework\TestCase;

/**
 * Tests for facet parameter extraction and facet count normalisation helpers.
 *
 * Uses the StubEngine from StubEngine.php.
 */
class FacetNormalisationTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- extractFacetParams ---------------------------------------------------

    public function testExtractFacetParamsDefaults(): void
    {
        [$facets, $filters, $maxValuesPerFacet, $remaining] = $this->engine->publicExtractFacetParams([]);

        $this->assertSame([], $facets);
        $this->assertSame([], $filters);
        $this->assertNull($maxValuesPerFacet);
        $this->assertSame([], $remaining);
    }

    public function testExtractFacetParamsWithFacets(): void
    {
        $options = ['facets' => ['category', 'status'], 'perPage' => 10];

        [$facets, $filters, $maxValuesPerFacet, $remaining] = $this->engine->publicExtractFacetParams($options);

        $this->assertSame(['category', 'status'], $facets);
        $this->assertSame([], $filters);
        $this->assertNull($maxValuesPerFacet);
        $this->assertSame(['perPage' => 10], $remaining);
        $this->assertArrayNotHasKey('facets', $remaining);
    }

    public function testExtractFacetParamsWithFilters(): void
    {
        $options = ['filters' => ['category' => 'News'], 'page' => 2];

        [$facets, $filters, $maxValuesPerFacet, $remaining] = $this->engine->publicExtractFacetParams($options);

        $this->assertSame([], $facets);
        $this->assertSame(['category' => 'News'], $filters);
        $this->assertNull($maxValuesPerFacet);
        $this->assertSame(['page' => 2], $remaining);
        $this->assertArrayNotHasKey('filters', $remaining);
    }

    public function testExtractFacetParamsWithBoth(): void
    {
        $options = [
            'facets' => ['category'],
            'filters' => ['status' => 'published'],
            'perPage' => 5,
        ];

        [$facets, $filters, $maxValuesPerFacet, $remaining] = $this->engine->publicExtractFacetParams($options);

        $this->assertSame(['category'], $facets);
        $this->assertSame(['status' => 'published'], $filters);
        $this->assertNull($maxValuesPerFacet);
        $this->assertSame(['perPage' => 5], $remaining);
    }

    public function testExtractFacetParamsWithArrayFilterValue(): void
    {
        $options = ['filters' => ['category' => ['News', 'Blog']]];

        [, $filters] = $this->engine->publicExtractFacetParams($options);

        $this->assertSame(['category' => ['News', 'Blog']], $filters);
    }

    public function testExtractFacetParamsPreservesOtherOptions(): void
    {
        $options = [
            'facets' => ['category'],
            'filters' => ['status' => 'published'],
            'page' => 3,
            'perPage' => 20,
            'sort' => 'title:asc',
        ];

        [, , , $remaining] = $this->engine->publicExtractFacetParams($options);

        $this->assertArrayHasKey('page', $remaining);
        $this->assertArrayHasKey('perPage', $remaining);
        $this->assertArrayHasKey('sort', $remaining);
    }

    public function testExtractFacetParamsWithMaxValuesPerFacet(): void
    {
        $options = [
            'facets' => ['category'],
            'maxValuesPerFacet' => 25,
            'perPage' => 10,
        ];

        [$facets, $filters, $maxValuesPerFacet, $remaining] = $this->engine->publicExtractFacetParams($options);

        $this->assertSame(['category'], $facets);
        $this->assertSame([], $filters);
        $this->assertSame(25, $maxValuesPerFacet);
        $this->assertSame(['perPage' => 10], $remaining);
        $this->assertArrayNotHasKey('maxValuesPerFacet', $remaining);
    }

    // -- normaliseFacetCounts -------------------------------------------------

    public function testNormaliseFacetCountsEmpty(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts([]);
        $this->assertSame([], $result);
    }

    public function testNormaliseFacetCountsSingleValue(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts(['News' => 12]);

        $this->assertCount(1, $result);
        $this->assertSame('News', $result[0]['value']);
        $this->assertSame(12, $result[0]['count']);
    }

    public function testNormaliseFacetCountsMultipleValuesSortedByCountDesc(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts([
            'Blog' => 5,
            'News' => 12,
            'Tutorial' => 8,
        ]);

        $this->assertCount(3, $result);
        // Should be sorted: News (12), Tutorial (8), Blog (5)
        $this->assertSame('News', $result[0]['value']);
        $this->assertSame(12, $result[0]['count']);
        $this->assertSame('Tutorial', $result[1]['value']);
        $this->assertSame(8, $result[1]['count']);
        $this->assertSame('Blog', $result[2]['value']);
        $this->assertSame(5, $result[2]['count']);
    }

    public function testNormaliseFacetCountsCastsValueToString(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts([42 => 3]);

        $this->assertSame('42', $result[0]['value']);
        $this->assertIsString($result[0]['value']);
    }

    public function testNormaliseFacetCountsCastsCountToInt(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts(['News' => '15']);

        $this->assertSame(15, $result[0]['count']);
        $this->assertIsInt($result[0]['count']);
    }

    public function testNormaliseFacetCountsZeroCounts(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts([
            'Active' => 10,
            'Empty' => 0,
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('Active', $result[0]['value']);
        $this->assertSame(10, $result[0]['count']);
        $this->assertSame('Empty', $result[1]['value']);
        $this->assertSame(0, $result[1]['count']);
    }

    public function testNormaliseFacetCountsEqualCountsPreserved(): void
    {
        $result = $this->engine->publicNormaliseFacetCounts([
            'A' => 5,
            'B' => 5,
            'C' => 5,
        ]);

        $this->assertCount(3, $result);
        // All have the same count, all should be present
        $values = array_column($result, 'value');
        $this->assertContains('A', $values);
        $this->assertContains('B', $values);
        $this->assertContains('C', $values);
    }
}
