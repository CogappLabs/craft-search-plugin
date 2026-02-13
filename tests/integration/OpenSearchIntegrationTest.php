<?php

namespace cogapp\searchindex\tests\integration;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\engines\OpenSearchEngine;
use OpenSearch\ClientBuilder;

class OpenSearchIntegrationTest extends EngineIntegrationTestCase
{
    protected static function engineDisplayName(): string
    {
        return 'OpenSearch';
    }

    protected function createEngine(): AbstractEngine
    {
        return new OpenSearchEngine();
    }

    protected function createClient(): object
    {
        return ClientBuilder::create()
            ->setHosts(['http://opensearch:9200'])
            ->build();
    }

    protected function isServiceReachable(): bool
    {
        return self::canConnect('opensearch', 9200);
    }

    protected function waitForIndexing(): void
    {
        $this->client->indices()->refresh(['index' => 'integration_test']);
    }
}
