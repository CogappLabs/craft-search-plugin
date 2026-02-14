<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AlgoliaEngine;
use cogapp\searchindex\engines\MeilisearchEngine;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Tests for atomic swap support across all engines.
 */
class AtomicSwapTest extends TestCase
{
    private function _makeIndex(string $handle = 'test_index'): Index
    {
        $index = new Index();
        $index->handle = $handle;
        return $index;
    }

    // -- supportsAtomicSwap ---------------------------------------------------

    public function testAlgoliaSupportsAtomicSwap(): void
    {
        $engine = new AlgoliaEngine();
        $this->assertTrue($engine->supportsAtomicSwap());
    }

    public function testMeilisearchSupportsAtomicSwap(): void
    {
        $engine = new MeilisearchEngine();
        $this->assertTrue($engine->supportsAtomicSwap());
    }

    // Elasticsearch, OpenSearch, Typesense need client classes to instantiate
    // so we test via StubEngine (which inherits AbstractEngine defaults)
    // and check the concrete classes are declared

    public function testStubEngineDefaultDoesNotSupportSwap(): void
    {
        $engine = new StubEngine();
        $this->assertFalse($engine->supportsAtomicSwap());
    }

    // -- buildSwapHandle (default) -------------------------------------------

    public function testDefaultBuildSwapHandleAppendsSuffix(): void
    {
        $engine = new StubEngine();
        $index = $this->_makeIndex('places');

        $this->assertSame('places_swap', $engine->buildSwapHandle($index));
    }

    public function testMeilisearchBuildSwapHandle(): void
    {
        $engine = new MeilisearchEngine();
        $index = $this->_makeIndex('articles');

        $this->assertSame('articles_swap', $engine->buildSwapHandle($index));
    }

    public function testAlgoliaBuildSwapHandleUsesDefault(): void
    {
        $engine = new AlgoliaEngine();
        $index = $this->_makeIndex('products');

        // Algolia uses the default _swap suffix
        $this->assertSame('products_swap', $engine->buildSwapHandle($index));
    }

    // -- swapIndex throws for unsupported engines ----------------------------

    public function testSwapIndexThrowsForUnsupportedEngine(): void
    {
        $engine = new StubEngine();
        $index = $this->_makeIndex();
        $swapIndex = $this->_makeIndex('test_index_swap');

        $this->expectException(\RuntimeException::class);
        $engine->swapIndex($index, $swapIndex);
    }
}
