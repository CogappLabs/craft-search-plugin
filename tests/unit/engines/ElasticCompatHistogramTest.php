<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\ElasticCompatEngine;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that exposes histogram normalisation.
 */
class StubElasticCompatHistogramEngine extends ElasticCompatEngine
{
    public static function displayName(): string
    {
        return 'StubElasticCompatHistogram';
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

    public function publicNormaliseRawHistograms(array $response, array $histogramConfig = []): array
    {
        return $this->normaliseRawHistograms($response, $histogramConfig);
    }
}

/**
 * Unit tests for ElasticCompatEngine histogram normalisation.
 */
class ElasticCompatHistogramTest extends TestCase
{
    private StubElasticCompatHistogramEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubElasticCompatHistogramEngine();
    }

    public function testNormaliseRawHistogramsSingleField(): void
    {
        $response = [
            'aggregations' => [
                'population_histogram' => [
                    'buckets' => [
                        ['key' => 0, 'doc_count' => 5],
                        ['key' => 100000, 'doc_count' => 12],
                        ['key' => 200000, 'doc_count' => 8],
                    ],
                ],
            ],
        ];

        $histograms = $this->engine->publicNormaliseRawHistograms(
            $response,
            ['population' => ['interval' => 100000]],
        );

        $this->assertArrayHasKey('population', $histograms);
        $this->assertCount(3, $histograms['population']);
        $this->assertSame(['key' => 0, 'count' => 5], $histograms['population'][0]);
        $this->assertSame(['key' => 100000, 'count' => 12], $histograms['population'][1]);
        $this->assertSame(['key' => 200000, 'count' => 8], $histograms['population'][2]);
    }

    public function testNormaliseRawHistogramsMultipleFields(): void
    {
        $response = [
            'aggregations' => [
                'population_histogram' => [
                    'buckets' => [
                        ['key' => 0, 'doc_count' => 5],
                        ['key' => 1000, 'doc_count' => 10],
                    ],
                ],
                'area_histogram' => [
                    'buckets' => [
                        ['key' => 0, 'doc_count' => 3],
                        ['key' => 50, 'doc_count' => 7],
                    ],
                ],
            ],
        ];

        $histograms = $this->engine->publicNormaliseRawHistograms($response, [
            'population' => ['interval' => 1000],
            'area' => ['interval' => 50],
        ]);

        $this->assertCount(2, $histograms);
        $this->assertCount(2, $histograms['population']);
        $this->assertCount(2, $histograms['area']);
    }

    public function testNormaliseRawHistogramsEmptyBuckets(): void
    {
        $response = [
            'aggregations' => [
                'population_histogram' => [
                    'buckets' => [],
                ],
            ],
        ];

        $histograms = $this->engine->publicNormaliseRawHistograms(
            $response,
            ['population' => ['interval' => 100000]],
        );

        // Empty buckets → field not included in result
        $this->assertArrayNotHasKey('population', $histograms);
    }

    public function testNormaliseRawHistogramsMissingAggregations(): void
    {
        $histograms = $this->engine->publicNormaliseRawHistograms(
            [],
            ['population' => ['interval' => 100000]],
        );

        $this->assertSame([], $histograms);
    }

    public function testNormaliseRawHistogramsNoConfigReturnsEmpty(): void
    {
        $response = [
            'aggregations' => [
                'population_histogram' => [
                    'buckets' => [['key' => 0, 'doc_count' => 5]],
                ],
            ],
        ];

        $histograms = $this->engine->publicNormaliseRawHistograms($response, []);

        $this->assertSame([], $histograms);
    }

    public function testNormaliseRawHistogramsPreservesFloatKeys(): void
    {
        $response = [
            'aggregations' => [
                'price_histogram' => [
                    'buckets' => [
                        ['key' => 0.0, 'doc_count' => 2],
                        ['key' => 9.99, 'doc_count' => 15],
                        ['key' => 19.98, 'doc_count' => 8],
                    ],
                ],
            ],
        ];

        $histograms = $this->engine->publicNormaliseRawHistograms(
            $response,
            ['price' => ['interval' => 9.99]],
        );

        $this->assertArrayHasKey('price', $histograms);
        $this->assertSame(0.0, $histograms['price'][0]['key']);
        $this->assertSame(9.99, $histograms['price'][1]['key']);
    }
}
