<?php

namespace cogapp\searchindex\tests\unit\variables;

use cogapp\searchindex\variables\SearchIndexVariable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the niceInterval() histogram interval calculation.
 */
class NiceIntervalTest extends TestCase
{
    private SearchIndexVariable $variable;

    protected function setUp(): void
    {
        $this->variable = new SearchIndexVariable();
    }

    private function niceInterval(float $min, float $max, int $targetBuckets = 10): float
    {
        return $this->variable->niceInterval($min, $max, $targetBuckets);
    }

    public function testLargeRangeMillions(): void
    {
        // 0–9,000,000 → raw 900,000 → magnitude 100,000 → 9.0 → snap to 10 → 1,000,000
        $this->assertSame(1_000_000.0, $this->niceInterval(0, 9_000_000));
    }

    public function testMediumRangeHundreds(): void
    {
        // 0–500 → raw 50 → magnitude 10 → 5.0 → snap to 5 → 50
        $this->assertSame(50.0, $this->niceInterval(0, 500));
    }

    public function testOffsetRangeMillions(): void
    {
        // 10,000–8,982,000 → range 8,972,000 → raw 897,200 → magnitude 100,000 → 8.97 → snap to 10 → 1,000,000
        $this->assertSame(1_000_000.0, $this->niceInterval(10_000, 8_982_000));
    }

    public function testEqualMinMaxReturnsZero(): void
    {
        $this->assertSame(0.0, $this->niceInterval(100, 100));
    }

    public function testMinGreaterThanMaxReturnsZero(): void
    {
        $this->assertSame(0.0, $this->niceInterval(500, 100));
    }

    public function testSmallRange(): void
    {
        // 0–3 → raw 0.3 → magnitude 0.1 → 3.0 → snap to 2 → 0.2
        $this->assertSame(0.2, $this->niceInterval(0, 3));
    }

    public function testVerySmallRange(): void
    {
        // 0–0.05 → raw 0.005 → magnitude 0.001 → 5.0 → snap to 5 → 0.005
        $this->assertSame(0.005, $this->niceInterval(0, 0.05));
    }

    public function testRangeOneThousand(): void
    {
        // 0–1000 → raw 100 → magnitude 100 → 1.0 → snap to 1 → 100
        $this->assertSame(100.0, $this->niceInterval(0, 1000));
    }

    public function testRangeSnapsToTwo(): void
    {
        // 0–200 → raw 20 → magnitude 10 → 2.0 → snap to 2 → 20
        $this->assertSame(20.0, $this->niceInterval(0, 200));
    }

    public function testNegativeMinPositiveMax(): void
    {
        // -500–500 → range 1000 → raw 100 → magnitude 100 → 1.0 → snap to 1 → 100
        $this->assertSame(100.0, $this->niceInterval(-500, 500));
    }

    public function testZeroRange(): void
    {
        $this->assertSame(0.0, $this->niceInterval(0, 0));
    }
}
