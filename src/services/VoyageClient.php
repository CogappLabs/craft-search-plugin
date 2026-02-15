<?php

/**
 * Voyage AI API client for generating text embeddings.
 */

namespace cogapp\searchindex\services;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use yii\base\Component;

/**
 * Wraps the Voyage AI embeddings API for converting text queries into vectors.
 *
 * Used by the search variable to auto-generate embeddings when `vectorSearch`
 * is enabled. Falls back gracefully when no API key is configured.
 *
 * Embeddings are cached using Craft's cache component so repeated searches
 * for the same query don't make redundant API calls.
 *
 * @author cogapp
 * @since 1.0.0
 */
class VoyageClient extends Component
{
    private const API_URL = 'https://api.voyageai.com/v1/embeddings';
    private const DEFAULT_MODEL = 'voyage-3';
    private const TIMEOUT = 10;
    private const CACHE_DURATION = 86400 * 7; // 7 days

    /**
     * Cached Guzzle HTTP client instance.
     *
     * @var Client|null
     */
    private ?Client $_client = null;

    /**
     * Generate an embedding vector for the given text.
     *
     * Results are cached for 7 days keyed by text + model + inputType,
     * so repeated searches for the same query avoid redundant API calls.
     *
     * @param string $text      The text to embed.
     * @param string $model     The Voyage AI model to use.
     * @param string $inputType The input type hint ('query' for search queries, 'document' for indexing).
     * @return float[]|null The embedding vector, or null if unavailable.
     */
    public function embed(string $text, string $model = self::DEFAULT_MODEL, string $inputType = 'query'): ?array
    {
        $apiKey = $this->_getApiKey();

        if ($apiKey === null || $apiKey === '') {
            return null;
        }

        if (trim($text) === '') {
            return null;
        }

        // Check cache first
        $cacheKey = 'searchindex:voyage:' . md5($text . '|' . $model . '|' . $inputType);
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $response = $this->_getClient()->post(self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'input' => [$text],
                    'model' => $model,
                    'input_type' => $inputType,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $embedding = $data['data'][0]['embedding'] ?? null;

            if (!is_array($embedding) || empty($embedding)) {
                Craft::warning('Voyage AI returned empty embedding', __METHOD__);
                return null;
            }

            Craft::$app->getCache()->set($cacheKey, $embedding, self::CACHE_DURATION);

            return $embedding;
        } catch (\Throwable $e) {
            Craft::warning('Voyage AI embedding failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Resolve vector search options by generating an embedding and detecting the target field.
     *
     * Centralises the embedding resolution logic shared by the Twig variable,
     * GraphQL resolver, and console controller. When `vectorSearch` is enabled
     * but no pre-computed `embedding` is provided, this method:
     *
     * 1. Normalises empty-string `embeddingField` to null for auto-detection
     * 2. Auto-detects the embedding field from the index's TYPE_EMBEDDING mappings
     * 3. Determines the Voyage AI model (defaults to `voyage-3`)
     * 4. Generates the embedding vector via the Voyage AI API
     *
     * @param Index  $index   The index being searched.
     * @param string $query   The search query text.
     * @param array  $options The caller-provided search options.
     * @return array The options with `embedding` and `embeddingField` injected.
     */
    public function resolveEmbeddingOptions(Index $index, string $query, array $options): array
    {
        if (trim($query) === '') {
            return $options;
        }

        // Normalise empty string to unset so auto-detection kicks in
        if (isset($options['embeddingField']) && $options['embeddingField'] === '') {
            unset($options['embeddingField']);
        }

        // Determine the target embedding field
        if (!isset($options['embeddingField'])) {
            $options['embeddingField'] = $index->getEmbeddingFieldName();

            if ($options['embeddingField'] === null) {
                Craft::warning(
                    'vectorSearch requested but no embedding field found on index "' . $index->handle . '"',
                    __METHOD__,
                );
                return $options;
            }
        }

        $model = is_string($options['voyageModel'] ?? null) && $options['voyageModel'] !== ''
            ? $options['voyageModel']
            : self::DEFAULT_MODEL;
        $embedding = $this->embed($query, $model);

        if ($embedding === null) {
            return $options;
        }

        $options['embedding'] = $embedding;

        return $options;
    }

    /**
     * Return the resolved Voyage AI API key from plugin settings.
     *
     * @return string|null
     */
    private function _getApiKey(): ?string
    {
        $settings = SearchIndex::$plugin->getSettings();
        $key = App::parseEnv($settings->voyageApiKey);

        return ($key !== '' && $key !== false) ? $key : null;
    }

    /**
     * Return a cached Guzzle client instance.
     *
     * @return Client
     */
    private function _getClient(): Client
    {
        if ($this->_client === null) {
            $this->_client = Craft::createGuzzleClient([
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 5,
            ]);
        }

        return $this->_client;
    }
}
