# Installation

## From GitHub

Add the repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/CogappLabs/craft-search-plugin.git"
        }
    ]
}
```

Then require the package:

```bash
composer require cogapp/craft-search-index:dev-main
```

## Local development (path repository)

For local plugin development, clone the repo alongside or inside your Craft project and add it as a path repository in your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./craft-search-index"
        }
    ]
}
```

Then require it (the `*@dev` constraint tells Composer to use the path symlink):

```bash
composer require cogapp/craft-search-index:*@dev
```

Composer will create a symlink in `vendor/cogapp/craft-search-index` pointing to your local copy. Changes you make to the plugin files take effect immediately.

**DDEV users:** If your plugin directory is outside the DDEV project root, create a symlink inside the project so the web container can access it:

```bash
ln -s /path/to/craft-search-index ./craft-search-index
```

## Activating the plugin

After installing, activate via the Craft control panel under **Settings > Plugins**, or via the CLI:

```bash
php craft plugin/install search-index
```

## Engine SDKs

All engine SDKs are optional. Install only the one(s) you need:

| Engine        | Package                                    | Install command                                        |
|---------------|--------------------------------------------|--------------------------------------------------------|
| Elasticsearch | `elasticsearch/elasticsearch ^8.0`         | `composer require elasticsearch/elasticsearch`         |
| Algolia       | `algolia/algoliasearch-client-php ^3.0 \|\| ^4.0` | `composer require algolia/algoliasearch-client-php`    |
| OpenSearch    | `opensearch-project/opensearch-php ^2.0`   | `composer require opensearch-project/opensearch-php`   |
| Meilisearch   | `meilisearch/meilisearch-php ^1.0`         | `composer require meilisearch/meilisearch-php`         |
| Typesense     | `typesense/typesense-php ^4.0 \|\| ^6.0`  | `composer require typesense/typesense-php`             |
