<?php

namespace cogapp\searchindex\tests\unit\engines;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbstractEngine histogram helpers.
 */
class HistogramTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- extractHistogramParams -----------------------------------------------

    public function testExtractHistogramParamsEmpty(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams(['perPage' => 10]);

        $this->assertSame([], $config);
        $this->assertArrayNotHasKey('histogram', $remaining);
        $this->assertSame(10, $remaining['perPage']);
    }

    public function testExtractHistogramParamsShorthandNormalisation(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => ['population' => 100000],
        ]);

        $this->assertSame(['population' => ['interval' => 100000.0]], $config);
        $this->assertArrayNotHasKey('histogram', $remaining);
    }

    public function testExtractHistogramParamsFullConfig(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => ['population' => ['interval' => 100000, 'min' => 0, 'max' => 10000000]],
        ]);

        $this->assertSame(['population' => ['interval' => 100000, 'min' => 0, 'max' => 10000000]], $config);
        $this->assertArrayNotHasKey('histogram', $remaining);
    }

    public function testExtractHistogramParamsKeyRemoval(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => ['pop' => 1000],
            'perPage' => 5,
            'page' => 2,
        ]);

        $this->assertArrayNotHasKey('histogram', $remaining);
        $this->assertSame(5, $remaining['perPage']);
        $this->assertSame(2, $remaining['page']);
    }

    public function testExtractHistogramParamsDiscardsNonArray(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => 'not-an-array',
        ]);

        $this->assertSame([], $config);
    }

    public function testExtractHistogramParamsDiscardsInvalidEntries(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => [
                'valid' => 1000,
                'invalid_string' => 'nope',
                'invalid_no_interval' => ['min' => 0, 'max' => 100],
            ],
        ]);

        $this->assertCount(1, $config);
        $this->assertArrayHasKey('valid', $config);
        $this->assertArrayNotHasKey('invalid_string', $config);
        $this->assertArrayNotHasKey('invalid_no_interval', $config);
    }

    public function testExtractHistogramParamsMultipleFields(): void
    {
        [$config, $remaining] = $this->engine->publicExtractHistogramParams([
            'histogram' => [
                'population' => 100000,
                'area' => ['interval' => 50, 'min' => 0, 'max' => 500],
            ],
        ]);

        $this->assertCount(2, $config);
        $this->assertSame(['interval' => 100000.0], $config['population']);
        $this->assertSame(['interval' => 50, 'min' => 0, 'max' => 500], $config['area']);
    }

    // -- normaliseRawHistograms (base) ----------------------------------------

    public function testNormaliseRawHistogramsBaseReturnsEmpty(): void
    {
        $result = $this->engine->publicNormaliseRawHistograms(
            ['aggregations' => ['pop_histogram' => ['buckets' => [['key' => 0, 'doc_count' => 5]]]]],
            ['pop' => ['interval' => 1000]],
        );

        $this->assertSame([], $result);
    }
}
