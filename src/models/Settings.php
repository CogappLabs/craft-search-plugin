<?php

namespace cogapp\searchindex\models;

use craft\base\Model;

class Settings extends Model
{
    public string $algoliaAppId = '';
    public string $algoliaApiKey = '';
    public string $algoliaSearchApiKey = '';

    public string $elasticsearchHost = '';
    public string $elasticsearchUsername = '';
    public string $elasticsearchPassword = '';
    public string $elasticsearchApiKey = '';

    public string $opensearchHost = '';
    public string $opensearchUsername = '';
    public string $opensearchPassword = '';

    public string $meilisearchHost = '';
    public string $meilisearchApiKey = '';

    public string $typesenseHost = '';
    public string $typesensePort = '8108';
    public string $typesenseProtocol = 'http';
    public string $typesenseApiKey = '';

    public int $batchSize = 500;
    public bool $syncOnSave = true;
    public bool $indexRelations = true;

    public function defineRules(): array
    {
        return [
            [['algoliaAppId', 'algoliaApiKey', 'algoliaSearchApiKey'], 'string'],
            [['elasticsearchHost', 'elasticsearchUsername', 'elasticsearchPassword', 'elasticsearchApiKey'], 'string'],
            [['opensearchHost', 'opensearchUsername', 'opensearchPassword'], 'string'],
            [['meilisearchHost', 'meilisearchApiKey'], 'string'],
            [['typesenseHost', 'typesensePort', 'typesenseProtocol', 'typesenseApiKey'], 'string'],
            ['batchSize', 'integer', 'min' => 1, 'max' => 5000],
            [['syncOnSave', 'indexRelations'], 'boolean'],
        ];
    }
}
