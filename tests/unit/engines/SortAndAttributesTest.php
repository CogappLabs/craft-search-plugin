<?php

namespace cogapp\searchindex\tests\unit\engines;

use PHPUnit\Framework\TestCase;

/**
 * Tests for sort extraction, unified sort detection, and attributesToRetrieve extraction.
 *
 * Uses the StubEngine defined in HitNormalisationTest.php.
 */
class SortAndAttributesTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- extractSortParams ----------------------------------------------------

    public function testExtractSortParamsDefaults(): void
    {
        [$sort, $remaining] = $this->engine->publicExtractSortParams([]);

        $this->assertSame([], $sort);
        $this->assertSame([], $remaining);
    }

    public function testExtractSortParamsWithSort(): void
    {
        $options = ['sort' => ['title' => 'asc'], 'perPage' => 10];

        [$sort, $remaining] = $this->engine->publicExtractSortParams($options);

        $this->assertSame(['title' => 'asc'], $sort);
        $this->assertSame(['perPage' => 10], $remaining);
        $this->assertArrayNotHasKey('sort', $remaining);
    }

    public function testExtractSortParamsMultipleFields(): void
    {
        $options = ['sort' => ['price' => 'asc', 'title' => 'desc']];

        [$sort, $remaining] = $this->engine->publicExtractSortParams($options);

        $this->assertSame(['price' => 'asc', 'title' => 'desc'], $sort);
        $this->assertSame([], $remaining);
    }

    public function testExtractSortParamsNonArrayValueBecomesEmpty(): void
    {
        $options = ['sort' => 'invalid'];

        [$sort, $remaining] = $this->engine->publicExtractSortParams($options);

        $this->assertSame([], $sort);
    }

    public function testExtractSortParamsPreservesOtherOptions(): void
    {
        $options = [
            'sort' => ['title' => 'asc'],
            'page' => 3,
            'perPage' => 20,
            'facets' => ['category'],
        ];

        [$sort, $remaining] = $this->engine->publicExtractSortParams($options);

        $this->assertArrayHasKey('page', $remaining);
        $this->assertArrayHasKey('perPage', $remaining);
        $this->assertArrayHasKey('facets', $remaining);
        $this->assertArrayNotHasKey('sort', $remaining);
    }

    // -- isUnifiedSort --------------------------------------------------------

    public function testIsUnifiedSortEmptyArray(): void
    {
        $this->assertFalse($this->engine->publicIsUnifiedSort([]));
    }

    public function testIsUnifiedSortValidAsc(): void
    {
        $this->assertTrue($this->engine->publicIsUnifiedSort(['title' => 'asc']));
    }

    public function testIsUnifiedSortValidDesc(): void
    {
        $this->assertTrue($this->engine->publicIsUnifiedSort(['price' => 'desc']));
    }

    public function testIsUnifiedSortMultipleFields(): void
    {
        $this->assertTrue($this->engine->publicIsUnifiedSort([
            'price' => 'asc',
            'title' => 'desc',
        ]));
    }

    public function testIsUnifiedSortRejectsNumericKeys(): void
    {
        // Indexed array (ES DSL style): [['price' => 'asc']]
        $this->assertFalse($this->engine->publicIsUnifiedSort([['price' => 'asc']]));
    }

    public function testIsUnifiedSortRejectsNonDirectionValues(): void
    {
        $this->assertFalse($this->engine->publicIsUnifiedSort(['price' => 'ascending']));
    }

    public function testIsUnifiedSortRejectsObjectValues(): void
    {
        // ES DSL: ['price' => ['order' => 'asc']]
        $this->assertFalse($this->engine->publicIsUnifiedSort([
            'price' => ['order' => 'asc'],
        ]));
    }

    public function testIsUnifiedSortRejectsMixedValues(): void
    {
        $this->assertFalse($this->engine->publicIsUnifiedSort([
            'title' => 'asc',
            'price' => ['order' => 'desc'],
        ]));
    }

    public function testIsUnifiedSortRejectsMeilisearchNativeFormat(): void
    {
        // Meilisearch native: ['price:asc', 'title:desc']
        $this->assertFalse($this->engine->publicIsUnifiedSort(['price:asc', 'title:desc']));
    }

    // -- extractAttributesToRetrieve ------------------------------------------

    public function testExtractAttributesToRetrieveDefaults(): void
    {
        [$attributes, $remaining] = $this->engine->publicExtractAttributesToRetrieve([]);

        $this->assertNull($attributes);
        $this->assertSame([], $remaining);
    }

    public function testExtractAttributesToRetrieveWithArray(): void
    {
        $options = ['attributesToRetrieve' => ['objectID', 'title'], 'perPage' => 5];

        [$attributes, $remaining] = $this->engine->publicExtractAttributesToRetrieve($options);

        $this->assertSame(['objectID', 'title'], $attributes);
        $this->assertSame(['perPage' => 5], $remaining);
        $this->assertArrayNotHasKey('attributesToRetrieve', $remaining);
    }

    public function testExtractAttributesToRetrieveNonArrayReturnsNull(): void
    {
        $options = ['attributesToRetrieve' => 'title'];

        [$attributes, $remaining] = $this->engine->publicExtractAttributesToRetrieve($options);

        $this->assertNull($attributes);
    }

    public function testExtractAttributesToRetrievePreservesOtherOptions(): void
    {
        $options = [
            'attributesToRetrieve' => ['title'],
            'page' => 2,
            'sort' => ['title' => 'asc'],
        ];

        [$attributes, $remaining] = $this->engine->publicExtractAttributesToRetrieve($options);

        $this->assertArrayHasKey('page', $remaining);
        $this->assertArrayHasKey('sort', $remaining);
        $this->assertArrayNotHasKey('attributesToRetrieve', $remaining);
    }

    public function testExtractAttributesToRetrieveEmptyArray(): void
    {
        $options = ['attributesToRetrieve' => []];

        [$attributes, $remaining] = $this->engine->publicExtractAttributesToRetrieve($options);

        // Empty array is valid — means "return no fields" (e.g. for count-only)
        $this->assertSame([], $attributes);
    }
}
