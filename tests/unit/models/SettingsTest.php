<?php

namespace cogapp\searchindex\tests\unit\models;

use cogapp\searchindex\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new Settings();

        // Algolia
        $this->assertSame('', $settings->algoliaAppId);
        $this->assertSame('', $settings->algoliaApiKey);
        $this->assertSame('', $settings->algoliaSearchApiKey);

        // Elasticsearch
        $this->assertSame('', $settings->elasticsearchHost);
        $this->assertSame('', $settings->elasticsearchUsername);
        $this->assertSame('', $settings->elasticsearchPassword);
        $this->assertSame('', $settings->elasticsearchApiKey);

        // OpenSearch
        $this->assertSame('', $settings->opensearchHost);
        $this->assertSame('', $settings->opensearchUsername);
        $this->assertSame('', $settings->opensearchPassword);

        // Meilisearch
        $this->assertSame('', $settings->meilisearchHost);
        $this->assertSame('', $settings->meilisearchApiKey);

        // Typesense
        $this->assertSame('', $settings->typesenseHost);
        $this->assertSame('8108', $settings->typesensePort);
        $this->assertSame('http', $settings->typesenseProtocol);
        $this->assertSame('', $settings->typesenseApiKey);

        // General
        $this->assertSame(500, $settings->batchSize);
        $this->assertTrue($settings->syncOnSave);
        $this->assertTrue($settings->indexRelations);
    }

    public function testPropertyAssignment(): void
    {
        $settings = new Settings();
        $settings->algoliaAppId = 'my-app-id';
        $settings->batchSize = 100;
        $settings->syncOnSave = false;

        $this->assertSame('my-app-id', $settings->algoliaAppId);
        $this->assertSame(100, $settings->batchSize);
        $this->assertFalse($settings->syncOnSave);
    }
}
