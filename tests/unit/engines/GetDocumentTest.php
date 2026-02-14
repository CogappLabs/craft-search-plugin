<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Stub engine that returns a controllable search result for getDocument tests.
 */
class GetDocumentStubEngine extends AbstractEngine
{
    /** @var SearchResult The result to return from search(). */
    public SearchResult $searchResult;

    /** @var int How many times search() was called. */
    public int $searchCallCount = 0;

    public function __construct()
    {
        parent::__construct();
        $this->searchResult = SearchResult::empty();
    }

    public static function displayName(): string
    {
        return 'GetDocumentStub';
    }

    public static function configFields(): array
    {
        return [];
    }

    public static function requiredPackage(): string
    {
        return 'stub/stub';
    }

    public static function isClientInstalled(): bool
    {
        return true;
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
        return true;
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
        $this->searchCallCount++;
        return $this->searchResult;
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
        return true;
    }
}

class GetDocumentTest extends TestCase
{
    private GetDocumentStubEngine $engine;
    private Index $index;

    protected function setUp(): void
    {
        $this->engine = new GetDocumentStubEngine();
        $this->index = new Index();
        $this->index->handle = 'test';
    }

    public function testGetDocumentReturnsMatchingHit(): void
    {
        $this->engine->searchResult = new SearchResult(
            hits: [
                ['objectID' => '42', 'title' => 'Castle', 'uri' => '/castle'],
            ],
            totalHits: 1,
        );

        $result = $this->engine->getDocument($this->index, '42');

        $this->assertNotNull($result);
        $this->assertSame('42', $result['objectID']);
        $this->assertSame('Castle', $result['title']);
    }

    public function testGetDocumentReturnsNullWhenNoMatch(): void
    {
        $this->engine->searchResult = new SearchResult(
            hits: [
                ['objectID' => '99', 'title' => 'Other'],
            ],
            totalHits: 1,
        );

        $result = $this->engine->getDocument($this->index, '42');

        $this->assertNull($result);
    }

    public function testGetDocumentReturnsNullOnEmptyResults(): void
    {
        $this->engine->searchResult = SearchResult::empty();

        $result = $this->engine->getDocument($this->index, '42');

        $this->assertNull($result);
    }

    public function testGetDocumentCallsSearchOnce(): void
    {
        $this->engine->searchResult = new SearchResult(
            hits: [
                ['objectID' => '42', 'title' => 'Found'],
            ],
            totalHits: 1,
        );

        $this->engine->getDocument($this->index, '42');

        $this->assertSame(1, $this->engine->searchCallCount);
    }

    public function testGetDocumentSelectsCorrectHitFromMultiple(): void
    {
        $this->engine->searchResult = new SearchResult(
            hits: [
                ['objectID' => '10', 'title' => 'First'],
                ['objectID' => '42', 'title' => 'Target'],
                ['objectID' => '99', 'title' => 'Last'],
            ],
            totalHits: 3,
        );

        $result = $this->engine->getDocument($this->index, '42');

        $this->assertNotNull($result);
        $this->assertSame('Target', $result['title']);
    }
}
