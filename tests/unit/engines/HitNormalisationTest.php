<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Minimal concrete subclass of AbstractEngine used to expose protected helpers.
 */
class StubEngine extends AbstractEngine
{
    public static function displayName(): string
    {
        return 'Stub';
    }

    public static function configFields(): array
    {
        return [];
    }

    public function createIndex(Index $index): void
    {
    }

    public function updateIndexSettings(Index $index): void
    {
    }

    public function deleteIndex(Index $index): void
    {
    }

    public function indexExists(Index $index): bool
    {
        return false;
    }

    public function indexDocument(Index $index, int $elementId, array $document): void
    {
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
    }

    public function flushIndex(Index $index): void
    {
    }

    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        return SearchResult::empty();
    }

    public function getDocumentCount(Index $index): int
    {
        return 0;
    }

    public function getAllDocumentIds(Index $index): array
    {
        return [];
    }

    public function mapFieldType(string $indexFieldType): mixed
    {
        return 'text';
    }

    public function buildSchema(array $fieldMappings): array
    {
        return [];
    }

    public function testConnection(): bool
    {
        return false;
    }

    // -- Expose protected helpers for testing ---------------------------------

    public function publicNormaliseHits(array $hits, string $idKey, string $scoreKey, ?string $highlightKey): array
    {
        return $this->normaliseHits($hits, $idKey, $scoreKey, $highlightKey);
    }

    public function publicExtractPaginationParams(array $options, int $defaultPerPage = 20): array
    {
        return $this->extractPaginationParams($options, $defaultPerPage);
    }

    public function publicComputeTotalPages(int $totalHits, int $perPage): int
    {
        return $this->computeTotalPages($totalHits, $perPage);
    }
}

class HitNormalisationTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- normaliseHits --------------------------------------------------------

    public function testNormaliseHitsAddsObjectIdFromIdKey(): void
    {
        $hits = [
            ['_id' => '42', 'title' => 'Hello'],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', '_highlight');

        $this->assertSame('42', $result[0]['objectID']);
    }

    public function testNormaliseHitsPreservesExistingObjectId(): void
    {
        $hits = [
            ['objectID' => '99', '_id' => '42', 'title' => 'Hello'],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', '_highlight');

        $this->assertSame('99', $result[0]['objectID']);
    }

    public function testNormaliseHitsSetsScoreFromScoreKey(): void
    {
        $hits = [
            ['_id' => '1', 'text_match' => 0.95],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', 'text_match', null);

        $this->assertSame(0.95, $result[0]['_score']);
    }

    public function testNormaliseHitsScoreIsNullWhenMissing(): void
    {
        $hits = [
            ['_id' => '1'],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', null);

        $this->assertNull($result[0]['_score']);
    }

    public function testNormaliseHitsPreservesExistingScore(): void
    {
        $hits = [
            ['_id' => '1', '_score' => 1.5, 'text_match' => 0.5],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', 'text_match', null);

        $this->assertSame(1.5, $result[0]['_score']);
    }

    public function testNormaliseHitsSetsHighlightsFromHighlightKey(): void
    {
        $hits = [
            ['_id' => '1', '_highlight' => ['title' => '<em>Match</em>']],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', '_highlight');

        $this->assertSame(['title' => '<em>Match</em>'], $result[0]['_highlights']);
    }

    public function testNormaliseHitsHighlightsEmptyWhenNoHighlightKey(): void
    {
        $hits = [
            ['_id' => '1'],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', null);

        $this->assertSame([], $result[0]['_highlights']);
    }

    public function testNormaliseHitsPreservesAllOriginalKeys(): void
    {
        $hits = [
            ['_id' => '1', 'title' => 'Test', 'custom_field' => 'value', '_score' => 2.0],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', null);

        $this->assertSame('Test', $result[0]['title']);
        $this->assertSame('value', $result[0]['custom_field']);
        $this->assertSame('1', $result[0]['_id']);
    }

    public function testNormaliseHitsEmptyInput(): void
    {
        $result = $this->engine->publicNormaliseHits([], '_id', '_score', null);

        $this->assertSame([], $result);
    }

    public function testNormaliseHitsCastsObjectIdToString(): void
    {
        $hits = [
            ['_id' => 42],
        ];

        $result = $this->engine->publicNormaliseHits($hits, '_id', '_score', null);

        $this->assertSame('42', $result[0]['objectID']);
        $this->assertIsString($result[0]['objectID']);
    }

    // -- extractPaginationParams ----------------------------------------------

    public function testExtractPaginationParamsDefaults(): void
    {
        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams([]);

        $this->assertSame(1, $page);
        $this->assertSame(20, $perPage);
        $this->assertSame([], $remaining);
    }

    public function testExtractPaginationParamsCustomDefaults(): void
    {
        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams([], 10);

        $this->assertSame(1, $page);
        $this->assertSame(10, $perPage);
    }

    public function testExtractPaginationParamsFromOptions(): void
    {
        $options = ['page' => 3, 'perPage' => 15, 'filter' => 'category:news'];

        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams($options);

        $this->assertSame(3, $page);
        $this->assertSame(15, $perPage);
        $this->assertSame(['filter' => 'category:news'], $remaining);
        $this->assertArrayNotHasKey('page', $remaining);
        $this->assertArrayNotHasKey('perPage', $remaining);
    }

    public function testExtractPaginationParamsClampsPageToMin1(): void
    {
        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams(['page' => 0]);

        $this->assertSame(1, $page);
    }

    public function testExtractPaginationParamsClampsNegativePage(): void
    {
        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams(['page' => -5]);

        $this->assertSame(1, $page);
    }

    public function testExtractPaginationParamsClampsPerPageToDefault(): void
    {
        [$page, $perPage, $remaining] = $this->engine->publicExtractPaginationParams(['perPage' => 0]);

        $this->assertSame(20, $perPage);
    }

    // -- computeTotalPages ----------------------------------------------------

    public function testComputeTotalPagesExactDivision(): void
    {
        $this->assertSame(5, $this->engine->publicComputeTotalPages(50, 10));
    }

    public function testComputeTotalPagesPartialPage(): void
    {
        $this->assertSame(6, $this->engine->publicComputeTotalPages(51, 10));
    }

    public function testComputeTotalPagesZeroHits(): void
    {
        $this->assertSame(0, $this->engine->publicComputeTotalPages(0, 10));
    }

    public function testComputeTotalPagesZeroPerPage(): void
    {
        $this->assertSame(0, $this->engine->publicComputeTotalPages(100, 0));
    }

    public function testComputeTotalPagesOneHitOnePerPage(): void
    {
        $this->assertSame(1, $this->engine->publicComputeTotalPages(1, 1));
    }
}
