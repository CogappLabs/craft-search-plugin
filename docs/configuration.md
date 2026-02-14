# Configuration

## Plugin Settings

Settings are managed in the control panel at **Search Index > Settings** (or via `config/search-index.php` if you create a config file).

### General

| Setting          | Type   | Default | Description                                             |
|------------------|--------|---------|---------------------------------------------------------|
| `syncOnSave`     | `bool` | `true`  | Automatically sync entries to the search index on save. |
| `indexRelations`  | `bool` | `true`  | Re-index related entries when relations change.         |
| `batchSize`      | `int`  | `500`   | Number of entries per bulk index queue job (1--5000).    |

### Elasticsearch

| Setting                    | Type     | Default | Description                    |
|----------------------------|----------|---------|--------------------------------|
| `elasticsearchHost`       | `string` | `''`    | Elasticsearch host URL.        |
| `elasticsearchApiKey`     | `string` | `''`    | API key for authentication.    |
| `elasticsearchUsername`   | `string` | `''`    | Username for authentication.   |
| `elasticsearchPassword`   | `string` | `''`    | Password for authentication.   |

### Algolia

| Setting               | Type     | Default | Description                |
|-----------------------|----------|---------|----------------------------|
| `algoliaAppId`        | `string` | `''`    | Algolia application ID.    |
| `algoliaApiKey`       | `string` | `''`    | Algolia admin API key.     |
| `algoliaSearchApiKey` | `string` | `''`    | Algolia search-only key.   |

### OpenSearch

| Setting                 | Type     | Default | Description                  |
|-------------------------|----------|---------|------------------------------|
| `opensearchHost`       | `string` | `''`    | OpenSearch host URL.         |
| `opensearchUsername`   | `string` | `''`    | Username for authentication. |
| `opensearchPassword`   | `string` | `''`    | Password for authentication. |

### Meilisearch

| Setting              | Type     | Default | Description            |
|----------------------|----------|---------|------------------------|
| `meilisearchHost`   | `string` | `''`    | Meilisearch host URL.  |
| `meilisearchApiKey` | `string` | `''`    | Meilisearch API key.   |

### Typesense

| Setting              | Type     | Default  | Description                           |
|----------------------|----------|----------|---------------------------------------|
| `typesenseHost`     | `string` | `''`     | Typesense host URL.                   |
| `typesensePort`     | `string` | `'8108'` | Typesense port number.                |
| `typesenseProtocol` | `string` | `'http'` | Protocol (`http` or `https`).         |
| `typesenseApiKey`   | `string` | `''`     | Typesense API key.                    |

## Environment Variables

All engine connection settings support Craft's `$VARIABLE` syntax for environment variable resolution. This lets you keep credentials out of project config and vary them per environment:

```
# .env
ELASTICSEARCH_HOST=https://my-cluster.es.io:9200
ELASTICSEARCH_API_KEY=abc123
```

Then in plugin settings, enter `$ELASTICSEARCH_HOST` and `$ELASTICSEARCH_API_KEY`.

Per-index engine config fields (such as `indexPrefix`) also support environment variables.
