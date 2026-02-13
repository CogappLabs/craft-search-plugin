<?php

namespace cogapp\searchindex\tests\integration;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\engines\MeilisearchEngine;
class MeilisearchIntegrationTest extends EngineIntegrationTestCase
{
    private const HOST = 'http://meilisearch:7700';
    private const API_KEY = 'ddev_meilisearch_key';

    protected static function engineDisplayName(): string
    {
        return 'Meilisearch';
    }

    protected function createEngine(): AbstractEngine
    {
        return new MeilisearchEngine();
    }

    protected function createClient(): object
    {
        return new \Meilisearch\Client(self::HOST, self::API_KEY);
    }

    protected function isServiceReachable(): bool
    {
        return self::canConnect('meilisearch', 7700);
    }

    /**
     * Override seed to use the client directly so we can wait for the async task.
     */
    protected function seed(): void
    {
        $meiliIndex = $this->client->index('integration_test');

        // Apply searchable/filterable settings and wait for that task first.
        $task = $meiliIndex->updateSearchableAttributes(['title', 'body']);
        $this->client->waitForTask($task['taskUid']);

        $task = $meiliIndex->updateFilterableAttributes(['category']);
        $this->client->waitForTask($task['taskUid']);

        // Now add documents and wait.
        $task = $meiliIndex->addDocuments($this->getSeedDocuments(), 'objectID');
        $this->client->waitForTask($task['taskUid']);
    }

    protected function waitForIndexing(): void
    {
        // Already handled in seed() via waitForTask.
    }
}
