<?php

namespace cogapp\searchindex\tests\unit\engines;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbstractEngine range filter and stats helpers.
 */
class RangeFilterTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- isRangeFilter --------------------------------------------------------

    public function testIsRangeFilterWithMinAndMax(): void
    {
        $this->assertTrue($this->engine->publicIsRangeFilter(['min' => 100, 'max' => 500]));
    }

    public function testIsRangeFilterWithMinOnly(): void
    {
        $this->assertTrue($this->engine->publicIsRangeFilter(['min' => 100]));
    }

    public function testIsRangeFilterWithMaxOnly(): void
    {
        $this->assertTrue($this->engine->publicIsRangeFilter(['max' => 500]));
    }

    public function testIsRangeFilterWithIndexedArray(): void
    {
        $this->assertFalse($this->engine->publicIsRangeFilter(['UK', 'FR']));
    }

    public function testIsRangeFilterWithEmptyArray(): void
    {
        $this->assertFalse($this->engine->publicIsRangeFilter([]));
    }

    public function testIsRangeFilterWithExtraKeys(): void
    {
        $this->assertFalse($this->engine->publicIsRangeFilter(['min' => 100, 'extra' => 'nope']));
    }

    public function testIsRangeFilterWithString(): void
    {
        $this->assertFalse($this->engine->publicIsRangeFilter('not an array'));
    }

    public function testIsRangeFilterWithNull(): void
    {
        $this->assertFalse($this->engine->publicIsRangeFilter(null));
    }

    // -- extractStatsParams ---------------------------------------------------

    public function testExtractStatsParamsExtractsFields(): void
    {
        [$statsFields, $remaining] = $this->engine->publicExtractStatsParams([
            'stats' => ['population', 'area'],
            'perPage' => 10,
        ]);

        $this->assertSame(['population', 'area'], $statsFields);
        $this->assertArrayNotHasKey('stats', $remaining);
        $this->assertSame(10, $remaining['perPage']);
    }

    public function testExtractStatsParamsDefaultsToEmpty(): void
    {
        [$statsFields, $remaining] = $this->engine->publicExtractStatsParams(['perPage' => 10]);

        $this->assertSame([], $statsFields);
        $this->assertArrayNotHasKey('stats', $remaining);
    }

    public function testExtractStatsParamsHandlesNonArray(): void
    {
        [$statsFields, $remaining] = $this->engine->publicExtractStatsParams(['stats' => 'not-array']);

        $this->assertSame([], $statsFields);
    }

    // -- normaliseRawStats (base) ---------------------------------------------

    public function testNormaliseRawStatsBaseReturnsEmpty(): void
    {
        $result = $this->engine->publicNormaliseRawStats(
            ['aggregations' => ['pop_stats' => ['min' => 100, 'max' => 5000]]],
            ['pop'],
        );

        $this->assertSame([], $result);
    }
}
