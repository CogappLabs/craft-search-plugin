<?php

/**
 * Elasticsearch search engine implementation.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * Search engine implementation backed by Elasticsearch.
 *
 * Extends the shared Elasticsearch-compatible base class with
 * Elasticsearch-specific client construction, ping/exists calls
 * (which return Elasticsearch\Response objects requiring ->asBool()),
 * and the Elasticsearch-specific 404 exception type.
 *
 * @author cogapp
 * @since 1.0.0
 */
class ElasticsearchEngine extends ElasticCompatEngine
{
    /**
     * Cached Elasticsearch client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Elasticsearch';
    }

    /**
     * @inheritdoc
     */
    public static function requiredPackage(): string
    {
        return 'elasticsearch/elasticsearch';
    }

    /**
     * @inheritdoc
     */
    public static function isClientInstalled(): bool
    {
        return class_exists(Client::class);
    }

    /**
     * @inheritdoc
     */
    public static function configFields(): array
    {
        return parent::configFields() + [
            'apiKey' => [
                'label' => 'API Key',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Elasticsearch API Key for this index. Leave blank to use the global setting.',
            ],
            'username' => [
                'label' => 'Username',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Elasticsearch username for this index. Leave blank to use the global setting.',
            ],
            'password' => [
                'label' => 'Password',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global Elasticsearch password for this index. Leave blank to use the global setting.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getClient(): Client
    {
        if ($this->_client === null) {
            if (!class_exists(Client::class)) {
                throw new \RuntimeException('The Elasticsearch engine requires the "elasticsearch/elasticsearch" package. Install it with: composer require elasticsearch/elasticsearch');
            }

            $settings = SearchIndex::$plugin->getSettings();

            $host = $this->resolveConfigOrGlobal('host', $settings->elasticsearchHost);

            if (empty($host)) {
                throw new \RuntimeException('No Elasticsearch host configured. Set it in plugin settings or on the index.');
            }

            $builder = ClientBuilder::create()
                ->setHosts([$host]);

            $apiKey = $this->resolveConfigOrGlobal('apiKey', $settings->elasticsearchApiKey);
            if (!empty($apiKey)) {
                $builder->setApiKey($apiKey);
            } else {
                $username = $this->resolveConfigOrGlobal('username', $settings->elasticsearchUsername);
                $password = $this->resolveConfigOrGlobal('password', $settings->elasticsearchPassword);

                if (!empty($username) && !empty($password)) {
                    $builder->setBasicAuthentication($username, $password);
                }
            }

            $this->_client = $builder->build();
        }

        return $this->_client;
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);

        // Check for both direct index and alias
        return $this->getClient()->indices()->exists(['index' => $indexName])->asBool()
            || $this->_aliasExists($indexName);
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(Index $index, int $elementId): void
    {
        $indexName = $this->getIndexName($index);

        try {
            $this->getClient()->delete([
                'index' => $indexName,
                'id' => (string)$elementId,
            ]);
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            // Ignore 404 (document not found)
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function _aliasExists(string $aliasName): bool
    {
        try {
            return $this->getClient()->indices()->existsAlias(['name' => $aliasName])->asBool();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    protected function _getAliasResponse(string $aliasName): array
    {
        return $this->getClient()->indices()->getAlias(['name' => $aliasName])->asArray();
    }

    /**
     * @inheritdoc
     */
    protected function _directIndexExists(string $indexName): bool
    {
        try {
            return $this->getClient()->indices()->exists(['index' => $indexName])->asBool();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        try {
            return $this->getClient()->ping()->asBool();
        } catch (\Exception $e) {
            Craft::warning('Elasticsearch connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
