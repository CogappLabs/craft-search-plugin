<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the unified API helpers added to AbstractEngine:
 * offsetFromPage, buildNativeSortParams, buildNativeFilterParams,
 * normaliseFacetMapResponse, normaliseRawHit, normaliseRawFacets,
 * parseSchemaFields, handleSchemaError.
 */
class UnifiedApiTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- offsetFromPage -------------------------------------------------------

    public function testOffsetFromPageFirstPage(): void
    {
        $this->assertSame(0, $this->engine->publicOffsetFromPage(1, 20));
    }

    public function testOffsetFromPageSecondPage(): void
    {
        $this->assertSame(20, $this->engine->publicOffsetFromPage(2, 20));
    }

    public function testOffsetFromPageCustomPerPage(): void
    {
        $this->assertSame(30, $this->engine->publicOffsetFromPage(4, 10));
    }

    public function testOffsetFromPageLargePage(): void
    {
        $this->assertSame(900, $this->engine->publicOffsetFromPage(10, 100));
    }

    // -- buildNativeSortParams (base implementation) --------------------------

    public function testBuildNativeSortParamsDefaultPassthrough(): void
    {
        $sort = ['title' => 'asc', 'price' => 'desc'];
        $this->assertSame($sort, $this->engine->publicBuildNativeSortParams($sort));
    }

    public function testBuildNativeSortParamsEmptyArray(): void
    {
        $this->assertSame([], $this->engine->publicBuildNativeSortParams([]));
    }

    // -- buildNativeFilterParams (base implementation) ------------------------

    public function testBuildNativeFilterParamsDefaultPassthrough(): void
    {
        $index = new Index();
        $filters = ['category' => 'news'];
        $this->assertSame($filters, $this->engine->publicBuildNativeFilterParams($filters, $index));
    }

    public function testBuildNativeFilterParamsEmptyFilters(): void
    {
        $index = new Index();
        $this->assertSame([], $this->engine->publicBuildNativeFilterParams([], $index));
    }

    // -- normaliseFacetMapResponse --------------------------------------------

    public function testNormaliseFacetMapResponseBasic(): void
    {
        $facetMap = [
            'category' => ['News' => 12, 'Blog' => 5],
            'status' => ['active' => 100],
        ];

        $result = $this->engine->publicNormaliseFacetMapResponse($facetMap);

        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame([
            ['value' => 'News', 'count' => 12],
            ['value' => 'Blog', 'count' => 5],
        ], $result['category']);
        $this->assertSame([
            ['value' => 'active', 'count' => 100],
        ], $result['status']);
    }

    public function testNormaliseFacetMapResponseEmpty(): void
    {
        $this->assertSame([], $this->engine->publicNormaliseFacetMapResponse([]));
    }

    // -- normaliseRawHit (base implementation) --------------------------------

    public function testNormaliseRawHitDefaultPassthrough(): void
    {
        $hit = ['objectID' => '123', 'title' => 'Test'];
        $this->assertSame($hit, $this->engine->publicNormaliseRawHit($hit));
    }

    // -- normaliseRawFacets (base implementation) -----------------------------

    public function testNormaliseRawFacetsDefaultReturnsEmpty(): void
    {
        $this->assertSame([], $this->engine->publicNormaliseRawFacets(['some' => 'data']));
    }

    // -- parseSchemaFields (base implementation) ------------------------------

    public function testParseSchemaFieldsDefaultReturnsEmpty(): void
    {
        $this->assertSame([], $this->engine->publicParseSchemaFields(['fields' => []]));
    }

    // -- handleSchemaError (base implementation) ------------------------------

    public function testHandleSchemaErrorDefaultReturnsEmpty(): void
    {
        $index = new Index();
        $this->assertSame([], $this->engine->publicHandleSchemaError($index));
    }
}
