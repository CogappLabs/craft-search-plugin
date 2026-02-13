<?php

namespace cogapp\searchindex\tests\integration;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\engines\ElasticsearchEngine;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticsearchIntegrationTest extends EngineIntegrationTestCase
{
    protected static function engineDisplayName(): string
    {
        return 'Elasticsearch';
    }

    protected function createEngine(): AbstractEngine
    {
        return new ElasticsearchEngine();
    }

    protected function createClient(): object
    {
        return ClientBuilder::create()
            ->setHosts(['http://elasticsearch:9200'])
            ->build();
    }

    protected function isServiceReachable(): bool
    {
        return self::canConnect('elasticsearch', 9200);
    }

    protected function waitForIndexing(): void
    {
        $this->client->indices()->refresh(['index' => 'integration_test']);
    }
}
