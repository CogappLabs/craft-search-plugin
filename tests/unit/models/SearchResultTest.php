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

        $result = new SearchResult(
            hits: $hits,
            totalHits: 42,
            page: 3,
            perPage: 10,
            totalPages: 5,
            processingTimeMs: 15,
            facets: ['category' => ['a' => 5]],
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

    public function testArrayAccessSetIsNoOp(): void
    {
        $result = new SearchResult(totalHits: 5);

        // Should not throw — silently ignored.
        $result['totalHits'] = 999;

        $this->assertSame(5, $result->totalHits);
    }

    public function testArrayAccessUnsetIsNoOp(): void
    {
        $result = new SearchResult(totalHits: 5);

        // Should not throw — silently ignored.
        unset($result['totalHits']);

        $this->assertSame(5, $result->totalHits);
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

    // -- Immutability ---------------------------------------------------------

    public function testPropertiesAreReadonly(): void
    {
        $ref = new \ReflectionClass(SearchResult::class);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }
}
