<?php

namespace cogapp\searchindex\tests\integration;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that run against real search engine services.
 *
 * Each concrete subclass provides the engine, client, and service-reachability
 * check. The base class handles index creation, document seeding, assertions
 * on the normalised SearchResult shape, and cleanup.
 *
 * These tests are NOT included in the default `unit` suite. Run them with:
 *
 *     ddev exec vendor/bin/phpunit --testsuite integration
 */
abstract class EngineIntegrationTestCase extends TestCase
{
    protected AbstractEngine $engine;

    /** Raw engine client, stored so subclasses can call refresh/wait after seeding. */
    protected object $client;

    protected Index $index;

    /**
     * Build the concrete engine instance (e.g. new ElasticsearchEngine()).
     */
    abstract protected function createEngine(): AbstractEngine;

    /**
     * Build a raw client pointed at the DDEV service.
     */
    abstract protected function createClient(): object;

    /**
     * Return true if the backing service is reachable (used to skip tests).
     */
    abstract protected function isServiceReachable(): bool;

    /**
     * Called after seeding — override to refresh/wait so documents are searchable.
     */
    abstract protected function waitForIndexing(): void;

    // -- Lifecycle ------------------------------------------------------------

    protected function setUp(): void
    {
        if (!$this->isServiceReachable()) {
            $this->markTestSkipped(static::engineDisplayName() . ' service is not reachable — skipping integration tests');
        }

        $this->client = $this->createClient();
        $this->engine = $this->createEngine();
        $this->injectClient($this->engine, $this->client);
        $this->index = $this->buildTestIndex();

        $this->clear();
        $this->engine->createIndex($this->index);
        $this->seed();
        $this->waitForIndexing();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    // -- Helpers --------------------------------------------------------------

    protected static function engineDisplayName(): string
    {
        return 'Engine';
    }

    /**
     * Inject a pre-built client into an engine's private $_client property.
     */
    protected function injectClient(AbstractEngine $engine, object $client): void
    {
        $ref = new \ReflectionProperty($engine, '_client');
        $ref->setValue($engine, $client);
    }

    /**
     * Build a test Index with text + keyword field mappings.
     */
    protected function buildTestIndex(): Index
    {
        $index = new Index();
        $index->handle = 'integration_test';

        $title = new FieldMapping();
        $title->indexFieldName = 'title';
        $title->indexFieldType = FieldMapping::TYPE_TEXT;
        $title->enabled = true;
        $title->weight = 10;

        $body = new FieldMapping();
        $body->indexFieldName = 'body';
        $body->indexFieldType = FieldMapping::TYPE_TEXT;
        $body->enabled = true;
        $body->weight = 5;

        $category = new FieldMapping();
        $category->indexFieldName = 'category';
        $category->indexFieldType = FieldMapping::TYPE_KEYWORD;
        $category->enabled = true;

        $index->setFieldMappings([$title, $body, $category]);

        return $index;
    }

    /**
     * Seed documents. Override in subclasses that need custom seeding (e.g. Meilisearch task waiting).
     */
    protected function seed(): void
    {
        $this->engine->indexDocuments($this->index, $this->getSeedDocuments());
    }

    /**
     * Delete the test index, ignoring errors (index may not exist).
     */
    protected function clear(): void
    {
        try {
            if (isset($this->engine, $this->index)) {
                $this->engine->deleteIndex($this->index);
            }
        } catch (\Throwable) {
            // Index may not exist — that's fine.
        }
    }

    /**
     * Documents used to populate the test index.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getSeedDocuments(): array
    {
        return [
            ['objectID' => '1', 'title' => 'London Bridge', 'body' => 'A famous bridge in London', 'category' => 'landmark'],
            ['objectID' => '2', 'title' => 'Big Ben', 'body' => 'Historic clock tower in London', 'category' => 'landmark'],
            ['objectID' => '3', 'title' => 'Eiffel Tower', 'body' => 'Famous tower in Paris France', 'category' => 'landmark'],
            ['objectID' => '4', 'title' => 'The London Eye', 'body' => 'Observation wheel on the South Bank', 'category' => 'attraction'],
            ['objectID' => '5', 'title' => 'Colosseum', 'body' => 'Ancient amphitheatre in Rome', 'category' => 'landmark'],
        ];
    }

    /**
     * Try to open a TCP socket to the given host/port.
     */
    protected static function canConnect(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }

    // -- Shared test methods --------------------------------------------------

    public function testSearchReturnsSearchResult(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertInstanceOf(SearchResult::class, $result);
    }

    public function testSearchFindsMatchingDocuments(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertGreaterThan(0, $result->totalHits);
        $this->assertNotEmpty($result->hits);
    }

    public function testHitsHaveNormalisedObjectId(): void
    {
        $result = $this->engine->search($this->index, 'London');
        foreach ($result->hits as $hit) {
            $this->assertArrayHasKey('objectID', $hit, 'Every hit must have an objectID key');
            $this->assertIsString($hit['objectID'], 'objectID must be a string');
        }
    }

    public function testHitsHaveScoreKey(): void
    {
        $result = $this->engine->search($this->index, 'London');
        foreach ($result->hits as $hit) {
            $this->assertArrayHasKey('_score', $hit, 'Every hit must have a _score key');
        }
    }

    public function testHitsHaveHighlightsArray(): void
    {
        $result = $this->engine->search($this->index, 'London');
        foreach ($result->hits as $hit) {
            $this->assertArrayHasKey('_highlights', $hit, 'Every hit must have a _highlights key');
            $this->assertIsArray($hit['_highlights'], '_highlights must be an array');
        }
    }

    public function testHitsPreserveOriginalFields(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertNotEmpty($result->hits, 'Expected at least one hit');

        $hit = $result->hits[0];
        $this->assertArrayHasKey('title', $hit, 'Original document fields should be preserved');
    }

    public function testPaginationFieldsArePopulated(): void
    {
        $result = $this->engine->search($this->index, 'London');

        $this->assertIsInt($result->page);
        $this->assertGreaterThanOrEqual(1, $result->page);
        $this->assertIsInt($result->perPage);
        $this->assertGreaterThan(0, $result->perPage);
        $this->assertIsInt($result->totalPages);
        $this->assertGreaterThanOrEqual(0, $result->totalPages);
        $this->assertIsInt($result->totalHits);
    }

    public function testUnifiedPaginationParams(): void
    {
        $result = $this->engine->search($this->index, 'London', ['page' => 1, 'perPage' => 2]);

        $this->assertSame(1, $result->page);
        $this->assertSame(2, $result->perPage);
        $this->assertLessThanOrEqual(2, count($result->hits));
    }

    public function testRawResponseIsPopulated(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertNotEmpty($result->raw, 'raw should contain the original engine response');
    }

    public function testArrayAccessMatchesProperties(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertSame($result->hits, $result['hits']);
        $this->assertSame($result->totalHits, $result['totalHits']);
        $this->assertSame($result->page, $result['page']);
    }

    public function testCountableReturnsHitCount(): void
    {
        $result = $this->engine->search($this->index, 'London');
        $this->assertCount(count($result->hits), $result);
    }

    public function testSearchNoResults(): void
    {
        $result = $this->engine->search($this->index, 'xyznonexistentterm12345');
        $this->assertSame(0, $result->totalHits);
        $this->assertEmpty($result->hits);
    }
}
