<?php

namespace cogapp\searchindex\tests\unit\models;

use cogapp\searchindex\models\SearchResult;
use PHPUnit\Framework\TestCase;

class SearchResultTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $result = new SearchResult();

        $this->assertSame([], $result->hits);
        $this->assertSame(0, $result->totalHits);
        $this->assertSame(1, $result->page);
        $this->assertSame(20, $result->perPage);
        $this->assertSame(0, $result->totalPages);
        $this->assertSame(0, $result->processingTimeMs);
        $this->assertSame([], $result->facets);
        $this->assertSame([], $result->stats);
        $this->assertSame([], $result->histograms);
        $this->assertSame([], $result->raw);
        $this->assertSame([], $result->suggestions);
    }

    public function testEmptyFactory(): void
    {
        $result = SearchResult::empty();

        $this->assertSame([], $result->hits);
        $this->assertSame(0, $result->totalHits);
        $this->assertSame(1, $result->page);
        $this->assertSame(20, $result->perPage);
        $this->assertSame(0, $result->totalPages);
    }

    public function testConstructorWithValues(): void
    {
        $hits = [
            ['objectID' => '1', 'title' => 'Hello'],
            ['objectID' => '2', 'title' => 'World'],
        ];

        $histograms = ['population' => [['key' => 0, 'count' => 5], ['key' => 100000, 'count' => 12]]];

        $result = new SearchResult(
            hits: $hits,
            totalHits: 42,
            page: 3,
            perPage: 10,
            totalPages: 5,
            processingTimeMs: 15,
            facets: ['category' => ['a' => 5]],
            stats: ['population' => ['min' => 100.0, 'max' => 50000.0]],
            histograms: $histograms,
            raw: ['engine_key' => 'value'],
            suggestions: ['london bridge', 'london eye'],
        );

        $this->assertSame($hits, $result->hits);
        $this->assertSame(42, $result->totalHits);
        $this->assertSame(3, $result->page);
        $this->assertSame(10, $result->perPage);
        $this->assertSame(5, $result->totalPages);
        $this->assertSame(15, $result->processingTimeMs);
        $this->assertSame(['category' => ['a' => 5]], $result->facets);
        $this->assertSame(['population' => ['min' => 100.0, 'max' => 50000.0]], $result->stats);
        $this->assertSame($histograms, $result->histograms);
        $this->assertSame(['engine_key' => 'value'], $result->raw);
        $this->assertSame(['london bridge', 'london eye'], $result->suggestions);
    }

    // -- ArrayAccess ----------------------------------------------------------

    public function testArrayAccessOffsetExists(): void
    {
        $result = new SearchResult();

        $this->assertTrue(isset($result['hits']));
        $this->assertTrue(isset($result['totalHits']));
        $this->assertTrue(isset($result['page']));
        $this->assertTrue(isset($result['perPage']));
        $this->assertTrue(isset($result['totalPages']));
        $this->assertTrue(isset($result['processingTimeMs']));
        $this->assertTrue(isset($result['facets']));
        $this->assertTrue(isset($result['stats']));
        $this->assertTrue(isset($result['histograms']));
        $this->assertTrue(isset($result['raw']));
        $this->assertTrue(isset($result['suggestions']));
        $this->assertFalse(isset($result['nonExistentKey']));
    }

    public function testArrayAccessOffsetGet(): void
    {
        $hits = [['objectID' => '1']];
        $result = new SearchResult(hits: $hits, totalHits: 7, page: 2);

        $this->assertSame($hits, $result['hits']);
        $this->assertSame(7, $result['totalHits']);
        $this->assertSame(2, $result['page']);
        $this->assertNull($result['nonExistentKey']);
    }

    public function testArrayAccessSetThrows(): void
    {
        $result = new SearchResult(totalHits: 5);

        $this->expectException(\BadMethodCallException::class);
        $result['totalHits'] = 999;
    }

    public function testArrayAccessUnsetThrows(): void
    {
        $result = new SearchResult(totalHits: 5);

        $this->expectException(\BadMethodCallException::class);
        unset($result['totalHits']);
    }

    // -- Countable ------------------------------------------------------------

    public function testCountReturnsHitCount(): void
    {
        $result = new SearchResult(hits: [
            ['objectID' => '1'],
            ['objectID' => '2'],
            ['objectID' => '3'],
        ]);

        $this->assertCount(3, $result);
    }

    public function testCountEmptyResult(): void
    {
        $result = SearchResult::empty();

        $this->assertCount(0, $result);
    }

    // -- facetsWithActive -----------------------------------------------------

    public function testFacetsWithActiveMarksActiveValues(): void
    {
        $result = new SearchResult(facets: [
            'region' => [
                ['value' => 'Highland', 'count' => 42],
                ['value' => 'Central', 'count' => 18],
                ['value' => 'Borders', 'count' => 7],
            ],
        ]);

        $enriched = $result->facetsWithActive(['region' => ['Highland', 'Borders']]);

        $this->assertTrue($enriched['region'][0]['active']);
        $this->assertFalse($enriched['region'][1]['active']);
        $this->assertTrue($enriched['region'][2]['active']);
    }

    public function testFacetsWithActiveHandlesStringFilter(): void
    {
        $result = new SearchResult(facets: [
            'category' => [
                ['value' => 'Gardens', 'count' => 10],
                ['value' => 'Castles', 'count' => 5],
            ],
        ]);

        // Single string value (not array) — should still work.
        $enriched = $result->facetsWithActive(['category' => 'Gardens']);

        $this->assertTrue($enriched['category'][0]['active']);
        $this->assertFalse($enriched['category'][1]['active']);
    }

    public function testFacetsWithActiveNoFiltersAllInactive(): void
    {
        $result = new SearchResult(facets: [
            'region' => [
                ['value' => 'Highland', 'count' => 42],
            ],
        ]);

        $enriched = $result->facetsWithActive([]);

        $this->assertFalse($enriched['region'][0]['active']);
    }

    public function testFacetsWithActivePreservesOriginalData(): void
    {
        $result = new SearchResult(facets: [
            'region' => [
                ['value' => 'Highland', 'count' => 42],
            ],
        ]);

        $enriched = $result->facetsWithActive(['region' => ['Highland']]);

        // Original count is preserved.
        $this->assertSame(42, $enriched['region'][0]['count']);
        $this->assertSame('Highland', $enriched['region'][0]['value']);

        // Original facets are not mutated.
        $this->assertArrayNotHasKey('active', $result->facets['region'][0]);
    }

    public function testFacetsWithActiveMultipleFacetFields(): void
    {
        $result = new SearchResult(facets: [
            'region' => [
                ['value' => 'Highland', 'count' => 42],
            ],
            'category' => [
                ['value' => 'Gardens', 'count' => 10],
            ],
        ]);

        $enriched = $result->facetsWithActive([
            'region' => ['Highland'],
            'category' => [],
        ]);

        $this->assertTrue($enriched['region'][0]['active']);
        $this->assertFalse($enriched['category'][0]['active']);
    }

    public function testFacetsWithActiveUnmatchedFilterFieldIgnored(): void
    {
        $result = new SearchResult(facets: [
            'region' => [
                ['value' => 'Highland', 'count' => 42],
            ],
        ]);

        // Filter for a field not in facets — should not cause error.
        $enriched = $result->facetsWithActive(['nonExistent' => ['x']]);

        $this->assertFalse($enriched['region'][0]['active']);
        $this->assertArrayNotHasKey('nonExistent', $enriched);
    }

    // -- Immutability ---------------------------------------------------------

    public function testPropertiesAreReadonly(): void
    {
        $ref = new \ReflectionClass(SearchResult::class);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }
}
