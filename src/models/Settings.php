<?php

/**
 * Plugin settings model for the Search Index plugin.
 */

namespace cogapp\searchindex\models;

use cogapp\searchindex\SearchIndex;
use craft\base\Model;

/**
 * Stores plugin-wide configuration settings for all supported search engines.
 *
 * @author cogapp
 * @since 1.0.0
 */
class Settings extends Model
{
    /**
     * Engine credential property names that can be overridden per-environment
     * via the DB (bypassing project config / allowAdminChanges).
     */
    public const ENGINE_CREDENTIAL_KEYS = [
        'algoliaAppId',
        'algoliaApiKey',
        'algoliaSearchApiKey',
        'elasticsearchHost',
        'elasticsearchUsername',
        'elasticsearchPassword',
        'elasticsearchApiKey',
        'opensearchHost',
        'opensearchUsername',
        'opensearchPassword',
        'meilisearchHost',
        'meilisearchApiKey',
        'typesenseHost',
        'typesensePort',
        'typesenseProtocol',
        'typesenseApiKey',
        'voyageApiKey',
    ];

    /** @var string Algolia application ID */
    public string $algoliaAppId = '';

    /** @var string Algolia admin API key */
    public string $algoliaApiKey = '';

    /** @var string Algolia search-only API key */
    public string $algoliaSearchApiKey = '';

    /** @var string Elasticsearch host URL */
    public string $elasticsearchHost = '';

    /** @var string Elasticsearch username for authentication */
    public string $elasticsearchUsername = '';

    /** @var string Elasticsearch password for authentication */
    public string $elasticsearchPassword = '';

    /** @var string Elasticsearch API key for authentication */
    public string $elasticsearchApiKey = '';

    /** @var string OpenSearch host URL */
    public string $opensearchHost = '';

    /** @var string OpenSearch username for authentication */
    public string $opensearchUsername = '';

    /** @var string OpenSearch password for authentication */
    public string $opensearchPassword = '';

    /** @var string Meilisearch host URL */
    public string $meilisearchHost = '';

    /** @var string Meilisearch API key */
    public string $meilisearchApiKey = '';

    /** @var string Typesense host URL */
    public string $typesenseHost = '';

    /** @var string Typesense port number */
    public string $typesensePort = '8108';

    /** @var string Typesense protocol (http or https) */
    public string $typesenseProtocol = 'http';

    /** @var string Typesense API key */
    public string $typesenseApiKey = '';

    /** @var string Voyage AI API key (used for embeddings and reranking) */
    public string $voyageApiKey = '';

    /** @var int Number of elements to process per batch during indexing */
    public int $batchSize = 500;

    /** @var bool Whether to sync elements to the search index on save */
    public bool $syncOnSave = true;

    /** @var bool Whether to re-index related elements when an element is saved */
    public bool $indexRelations = true;

    /** @var string[] Enabled engine class names. Empty array means all engines are available (backward compat). */
    public array $enabledEngines = [];

    /**
     * Return the effective value for an engine credential key.
     *
     * Checks the DB override first; falls back to the project config value.
     * Empty overrides are treated as "no override" (fall through).
     *
     * @param string $key A property name from ENGINE_CREDENTIAL_KEYS
     * @return string The effective value (may contain env var references like $ENV_VAR)
     */
    public function getEffective(string $key): string
    {
        if (!in_array($key, self::ENGINE_CREDENTIAL_KEYS, true)) {
            return $this->$key ?? '';
        }

        return SearchIndex::$plugin->getEngineOverrides()->getOverride($key, $this->$key);
    }

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        // Lightswitch array fields submit '' for off entries — filter those out
        if (isset($values['enabledEngines']) && is_array($values['enabledEngines'])) {
            $values['enabledEngines'] = array_values(array_filter($values['enabledEngines']));
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Returns the validation rules for the settings.
     *
     * @return array Validation rules
     */
    public function defineRules(): array
    {
        return [
            [['algoliaAppId', 'algoliaApiKey', 'algoliaSearchApiKey'], 'string'],
            [['elasticsearchHost', 'elasticsearchUsername', 'elasticsearchPassword', 'elasticsearchApiKey'], 'string'],
            [['opensearchHost', 'opensearchUsername', 'opensearchPassword'], 'string'],
            [['meilisearchHost', 'meilisearchApiKey'], 'string'],
            [['typesenseHost', 'typesensePort', 'typesenseProtocol', 'typesenseApiKey'], 'string'],
            ['voyageApiKey', 'string'],
            ['batchSize', 'integer', 'min' => 1, 'max' => 5000],
            [['syncOnSave', 'indexRelations'], 'boolean'],
            ['enabledEngines', 'each', 'rule' => ['string']],
        ];
    }
}
