<?php

/**
 * OpenSearch search engine implementation.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Search engine implementation backed by OpenSearch.
 *
 * Extends the shared Elasticsearch-compatible base class with
 * OpenSearch-specific client construction, the OpenSearch 404 exception
 * type, and the OpenSearch ping/exists return conventions (plain booleans
 * instead of Elasticsearch's Response objects).
 *
 * @author cogapp
 * @since 1.0.0
 */
class OpenSearchEngine extends ElasticCompatEngine
{
    /**
     * Cached OpenSearch client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'OpenSearch';
    }

    /**
     * @inheritdoc
     */
    public static function requiredPackage(): string
    {
        return 'opensearch-project/opensearch-php';
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
            'username' => [
                'label' => 'Username',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global OpenSearch username for this index. Leave blank to use the global setting.',
            ],
            'password' => [
                'label' => 'Password',
                'type' => 'text',
                'required' => false,
                'instructions' => 'Override the global OpenSearch password for this index. Leave blank to use the global setting.',
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
                throw new \RuntimeException('The OpenSearch engine requires the "opensearch-project/opensearch-php" package. Install it with: composer require opensearch-project/opensearch-php');
            }

            $settings = SearchIndex::$plugin->getSettings();

            $host = $this->resolveConfigOrGlobal('host', $settings->opensearchHost);

            if (empty($host)) {
                throw new \RuntimeException('No OpenSearch host configured. Set it in plugin settings or on the index.');
            }

            $builder = ClientBuilder::create()
                ->setHosts([$host]);

            $username = $this->resolveConfigOrGlobal('username', $settings->opensearchUsername);
            $password = $this->resolveConfigOrGlobal('password', $settings->opensearchPassword);

            if (!empty($username) && !empty($password)) {
                $builder->setBasicAuthentication($username, $password);
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
        return (bool)$this->getClient()->indices()->exists(['index' => $indexName])
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
        } catch (\OpenSearch\Common\Exceptions\Missing404Exception $e) {
            // Document not found, that's OK
        }
    }

    /**
     * @inheritdoc
     */
    public function testConnection(): bool
    {
        try {
            return (bool)$this->getClient()->ping();
        } catch (\Exception $e) {
            Craft::warning('OpenSearch connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
