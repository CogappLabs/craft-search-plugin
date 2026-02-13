<?php

namespace cogapp\searchindex\tests\integration;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\engines\TypesenseEngine;
class TypesenseIntegrationTest extends EngineIntegrationTestCase
{
    protected static function engineDisplayName(): string
    {
        return 'Typesense';
    }

    protected function createEngine(): AbstractEngine
    {
        return new TypesenseEngine();
    }

    protected function createClient(): object
    {
        return new \Typesense\Client([
            'api_key' => 'ddev_typesense_key',
            'nodes' => [[
                'host' => 'typesense',
                'port' => '8108',
                'protocol' => 'http',
            ]],
            'connection_timeout_seconds' => 5,
        ]);
    }

    protected function isServiceReachable(): bool
    {
        return self::canConnect('typesense', 8108);
    }

    protected function waitForIndexing(): void
    {
        // Typesense indexing is synchronous — nothing to wait for.
    }
}
