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

            $username = $this->resolveConfigOrGlobal('username', $settings->opensearchUsername);
            $password = $this->resolveConfigOrGlobal('password', $settings->opensearchPassword);

            // Build a structured host config so the client uses the correct
            // port (443 for HTTPS, 9200 for HTTP) and handles special chars
            // in credentials properly. Passing a plain URL string causes the
            // client to default to port 9200 even for HTTPS URLs.
            $hostConfig = $this->buildHostConfig($host, $username, $password);

            $builder = ClientBuilder::create()
                ->setHosts([$hostConfig])
                ->setConnectionPool('\OpenSearch\ConnectionPool\StaticNoPingConnectionPool')
                ->setConnectionParams([
                    'client' => [
                        'connect_timeout' => 5,
                        'timeout' => 10,
                    ],
                ]);

            $this->_client = $builder->build();
        }

        return $this->_client;
    }

    /**
     * Parse a host URL into the structured array format expected by the OpenSearch client.
     *
     * @param string $host   The host URL (e.g. "https://hostname.com" or "opensearch:9200").
     * @param string $username Basic auth username.
     * @param string $password Basic auth password.
     * @return array Structured host config with scheme, host, port, and optional credentials.
     */
    private function buildHostConfig(string $host, string $username, string $password): array
    {
        // Prepend http:// when no scheme is present so parse_url correctly
        // identifies the host component (e.g. "opensearch:9200" would otherwise
        // be parsed with "opensearch" as the scheme).
        if (!str_contains($host, '://')) {
            $host = 'http://' . $host;
        }

        $parts = parse_url($host);

        $scheme = $parts['scheme'] ?? 'http';
        $defaultPort = ($scheme === 'https') ? 443 : 9200;

        $config = [
            'host' => $parts['host'] ?? $host,
            'port' => $parts['port'] ?? $defaultPort,
            'scheme' => $scheme,
        ];

        if (!empty($username) && !empty($password)) {
            $config['user'] = $username;
            $config['pass'] = $password;
        }

        return $config;
    }

    /**
     * @inheritdoc
     */
    public function indexExists(Index $index): bool
    {
        $indexName = $this->getIndexName($index);

        try {
            // Check for both direct index and alias
            return (bool)$this->getClient()->indices()->exists(['index' => $indexName])
                || $this->_aliasExists($indexName);
        } catch (\Exception $e) {
            // Read-only users (e.g. AWS OpenSearch) may lack indices:admin/exists permission.
            // Fall back to _count which only requires read access.
            if ($e->getCode() === 403 || str_contains($e->getMessage(), '403')) {
                try {
                    $this->getClient()->count(['index' => $indexName]);
                    return true;
                } catch (\Exception) {
                    return false;
                }
            }
            return false;
        }
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
            // Read-only users (e.g. AWS OpenSearch) lack cluster:monitor/main permission,
            // but a 403 proves we reached the server and auth succeeded.
            if ($e->getCode() === 403 || str_contains($e->getMessage(), '403')) {
                return true;
            }
            Craft::warning('OpenSearch connection test failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
