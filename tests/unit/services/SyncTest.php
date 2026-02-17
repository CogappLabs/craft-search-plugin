<?php

namespace cogapp\searchindex\tests\unit\services;

use cogapp\searchindex\engines\EngineInterface;
use cogapp\searchindex\events\DocumentSyncEvent;
use cogapp\searchindex\jobs\AtomicSwapJob;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;
use cogapp\searchindex\services\Sync;
use cogapp\searchindex\tests\unit\services\Support\TestApp;
use PHPUnit\Framework\TestCase;

class StubSyncEngine implements EngineInterface
{
    public static int $constructCount = 0;

    public function __construct(array $config = [])
    {
        self::$constructCount++;
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

    public function indexDocuments(Index $index, array $documents): void
    {
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
    }

    public function deleteDocuments(Index $index, array $elementIds): void
    {
    }

    public function flushIndex(Index $index): void
    {
    }

    public function getDocument(Index $index, string $documentId): ?array
    {
        return null;
    }

    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5, array $filters = []): array
    {
        return [];
    }

    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        return new SearchResult();
    }

    public function getDocumentCount(Index $index): int
    {
        return 0;
    }

    public function getAllDocumentIds(Index $index): array
    {
        return [];
    }

    public function multiSearch(array $queries): array
    {
        return [];
    }

    public function getIndexSchema(Index $index): array
    {
        return [];
    }

    public function getSchemaFields(Index $index): array
    {
        return [];
    }

    public function mapFieldType(string $indexFieldType): mixed
    {
        return $indexFieldType;
    }

    public function buildSchema(array $fieldMappings): array
    {
        return [];
    }

    public function supportsAtomicSwap(): bool
    {
        return true;
    }

    public function buildSwapHandle(Index $index): string
    {
        return $index->handle . '_swap';
    }

    public function swapIndex(Index $index, Index $swapIndex): void
    {
    }

    public static function displayName(): string
    {
        return 'Stub';
    }

    public static function requiredPackage(): string
    {
        return 'stub/stub';
    }

    public static function isClientInstalled(): bool
    {
        return true;
    }

    public static function configFields(): array
    {
        return [];
    }

    public function testConnection(): bool
    {
        return true;
    }
}

class SyncTest extends TestCase
{
    private mixed $previousApp = null;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\Yii::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
        }
        if (!class_exists(\Craft::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }
    }

    protected function setUp(): void
    {
        $this->previousApp = \Yii::$app ?? null;
        StubSyncEngine::$constructCount = 0;
    }

    protected function tearDown(): void
    {
        \Yii::$app = $this->previousApp;
    }

    public function testBuildSwapIndexWithExplicitHandleClonesAndOverridesHandle(): void
    {
        $sync = new Sync();

        $index = new Index();
        $index->id = 10;
        $index->name = 'Articles';
        $index->handle = 'articles';

        $swap = $this->invokePrivateMethod($sync, '_buildSwapIndex', [$index, 'articles_swap_b']);

        $this->assertInstanceOf(Index::class, $swap);
        $this->assertNotSame($index, $swap);
        $this->assertSame('articles', $index->handle);
        $this->assertSame('articles_swap_b', $swap->handle);
        $this->assertSame(10, $swap->id);
        $this->assertSame('Articles', $swap->name);
    }

    public function testSupportsAtomicSwapCachesEngineInstancesByTypeAndConfig(): void
    {
        $sync = new Sync();

        $indexA = new Index();
        $indexA->engineType = StubSyncEngine::class;
        $indexA->engineConfig = ['host' => 'localhost'];

        $indexB = new Index();
        $indexB->engineType = StubSyncEngine::class;
        $indexB->engineConfig = ['host' => 'localhost'];

        $indexC = new Index();
        $indexC->engineType = StubSyncEngine::class;
        $indexC->engineConfig = ['host' => 'other'];

        $this->assertTrue($sync->supportsAtomicSwap($indexA));
        $this->assertTrue($sync->supportsAtomicSwap($indexB));
        $this->assertSame(1, StubSyncEngine::$constructCount);

        $this->assertTrue($sync->supportsAtomicSwap($indexC));
        $this->assertSame(2, StubSyncEngine::$constructCount);
    }

    public function testAfterSyncEventsEmitExpectedPayloads(): void
    {
        $sync = new Sync();

        $index = new Index();
        $index->id = 42;
        $index->name = 'Docs';
        $index->handle = 'docs';

        $captured = [];

        $sync->on(Sync::EVENT_AFTER_INDEX_ELEMENT, function(DocumentSyncEvent $event) use (&$captured): void {
            $captured['index'] = $event;
        });
        $sync->on(Sync::EVENT_AFTER_DELETE_ELEMENT, function(DocumentSyncEvent $event) use (&$captured): void {
            $captured['delete'] = $event;
        });
        $sync->on(Sync::EVENT_AFTER_BULK_INDEX, function(DocumentSyncEvent $event) use (&$captured): void {
            $captured['bulk'] = $event;
        });

        $sync->afterIndexElement($index, 1001);
        $sync->afterDeleteElement($index, 1002);
        $sync->afterBulkIndex($index);

        $this->assertSame('upsert', $captured['index']->action);
        $this->assertSame(1001, $captured['index']->elementId);
        $this->assertSame($index, $captured['index']->index);

        $this->assertSame('delete', $captured['delete']->action);
        $this->assertSame(1002, $captured['delete']->elementId);

        $this->assertSame('upsert', $captured['bulk']->action);
        $this->assertSame(0, $captured['bulk']->elementId);
    }

    public function testDecrementSwapBatchCounterThrowsWhenMutexUnavailable(): void
    {
        $app = new TestApp();
        $app->mutex->acquireResult = false;
        \Yii::$app = $app;

        $sync = new Sync();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Could not acquire mutex for swap counter 'docs_swap'");

        $sync->decrementSwapBatchCounter('docs_swap');
    }

    public function testDecrementSwapBatchCounterReturnsGracefullyWhenCounterMissing(): void
    {
        $app = new TestApp();
        \Yii::$app = $app;

        $sync = new Sync();
        $sync->decrementSwapBatchCounter('missing_swap');

        $this->assertCount(1, $app->mutex->releaseCalls);
        $this->assertCount(0, $app->queue->jobs);
    }

    public function testDecrementSwapBatchCounterQueuesAtomicSwapAtZero(): void
    {
        $app = new TestApp();
        \Yii::$app = $app;

        $app->cache->set('searchIndex:swapPending:docs_swap', [
            'remaining' => 1,
            'indexId' => 12,
            'indexName' => 'Docs',
        ], 86400);

        $sync = new Sync();
        $sync->decrementSwapBatchCounter('docs_swap');

        $cached = $app->cache->get('searchIndex:swapPending:docs_swap');

        $this->assertIsArray($cached);
        $this->assertSame(0, $cached['remaining']);
        $this->assertCount(1, $app->queue->jobs);
        $this->assertInstanceOf(AtomicSwapJob::class, $app->queue->jobs[0]['job']);
        $this->assertSame('docs_swap', $app->queue->jobs[0]['job']->swapHandle);
    }

    public function testDecrementSwapBatchCounterRestoresCounterWhenQueuePushFails(): void
    {
        $app = new TestApp();
        $app->queue->throwOnPush = true;
        \Yii::$app = $app;

        $app->cache->set('searchIndex:swapPending:docs_swap', [
            'remaining' => 1,
            'indexId' => 12,
            'indexName' => 'Docs',
        ], 86400);

        $sync = new Sync();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue push failed.');

        try {
            $sync->decrementSwapBatchCounter('docs_swap');
        } finally {
            $cached = $app->cache->get('searchIndex:swapPending:docs_swap');
            $this->assertIsArray($cached);
            $this->assertSame(1, $cached['remaining']);
        }
    }

    public function testPerformAtomicSwapDeletesPendingCounter(): void
    {
        $app = new TestApp();
        \Yii::$app = $app;

        $app->cache->set('searchIndex:swapPending:docs_swap', ['remaining' => 1], 86400);

        $sync = new Sync();

        $index = new Index();
        $index->engineType = StubSyncEngine::class;
        $index->handle = 'docs';

        $sync->performAtomicSwap($index, 'docs_swap');

        $this->assertFalse($app->cache->get('searchIndex:swapPending:docs_swap'));
    }

    private function invokePrivateMethod(object $object, string $name, array $args = []): mixed
    {
        $ref = new \ReflectionClass($object);
        $method = $ref->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
