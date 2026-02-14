# Development

## DDEV Setup

The plugin ships with a DDEV configuration that provides PHP and all four local search engines:

```bash
ddev start
```

This starts:

| Service         | Image                                    | Internal host:port     |
|-----------------|------------------------------------------|------------------------|
| Elasticsearch   | `elasticsearch:8.17.0`                   | `elasticsearch:9200`   |
| OpenSearch      | `opensearchproject/opensearch:2.19.1`    | `opensearch:9200`      |
| Meilisearch     | `getmeili/meilisearch:v1.13`             | `meilisearch:7700`     |
| Typesense       | `typesense/typesense:30.1`               | `typesense:8108`       |

Dev credentials: Meilisearch key `ddev_meilisearch_key`, Typesense key `ddev_typesense_key`. Elasticsearch and OpenSearch have security disabled.

## Tests

```bash
# Unit tests (default -- no services required)
ddev exec vendor/bin/phpunit

# Integration tests (requires DDEV services running)
ddev exec vendor/bin/phpunit --testsuite integration

# All tests
ddev exec vendor/bin/phpunit --testsuite unit,integration
```

Unit tests cover models, schema building, and the `SearchResult` DTO. Integration tests create real indexes, seed documents, search, and verify the normalised response shape across all four local engines. If the services aren't reachable, integration tests skip gracefully.

## Code Quality

```bash
# Coding standards (ECS)
ddev exec vendor/bin/ecs check
ddev exec vendor/bin/ecs check --fix

# Static analysis (PHPStan, level 0)
ddev exec vendor/bin/phpstan --memory-limit=1G

# Rector (automated refactoring, dry-run)
ddev exec vendor/bin/rector process src --dry-run
```

Or using Composer scripts:

```bash
ddev exec composer test
ddev exec composer check-cs
ddev exec composer fix-cs
ddev exec composer phpstan
```

## Documentation

The docs site is built with [MkDocs Material](https://squidfizz.com/mkdocs-material/). Python is handled inside the DDEV container so nothing extra is needed on the host.

```bash
# Live preview with hot-reload (http://localhost:8000)
ddev mkdocs serve

# Build static HTML to site/
ddev mkdocs build
```

Source files live in `docs/` and the site is configured in `mkdocs.yml`.

## Connecting to a Craft Project for Testing

The plugin's DDEV config provides the search engine services, but to test against real content you need to connect it to a Craft project. There are two approaches:

### Option 1: Path repository (recommended)

Add the plugin as a path repository in your Craft project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../craft-search-index"
        }
    ]
}
```

Then require it:

```bash
composer require cogapp/craft-search-index:*@dev
```

Composer creates a symlink in `vendor/cogapp/craft-search-index`, so code changes take effect immediately.

### Option 2: DDEV add-on services in your Craft project

If your Craft project uses DDEV, you can add the search engine services directly to it. Copy the relevant service definitions from the plugin's `.ddev/docker-compose.*.yaml` files into your Craft project's `.ddev/` directory.

### Connecting your Craft project to the plugin's search engines

When the plugin is installed in a separate DDEV project, your Craft project needs to reach the search engine containers. Since DDEV containers share a Docker network, you can reference them by their DDEV project name:

| Engine        | Host from another DDEV project                    | Port  | Auth                        |
|---------------|---------------------------------------------------|-------|-----------------------------|
| Elasticsearch | `ddev-craft-search-index-elasticsearch`            | 9200  | None                        |
| OpenSearch    | `ddev-craft-search-index-opensearch`               | 9200  | None                        |
| Meilisearch   | `ddev-craft-search-index-meilisearch`              | 7700  | Key: `ddev_meilisearch_key` |
| Typesense     | `ddev-craft-search-index-typesense`                | 8108  | Key: `ddev_typesense_key`   |

In your Craft project's `.env`:

```
# Meilisearch (running in the plugin's DDEV project)
MEILISEARCH_HOST=http://ddev-craft-search-index-meilisearch:7700
MEILISEARCH_API_KEY=ddev_meilisearch_key

# Typesense
TYPESENSE_HOST=ddev-craft-search-index-typesense
TYPESENSE_PORT=8108
TYPESENSE_PROTOCOL=http
TYPESENSE_API_KEY=ddev_typesense_key

# Elasticsearch
ELASTICSEARCH_HOST=http://ddev-craft-search-index-elasticsearch:9200

# OpenSearch
OPENSEARCH_HOST=http://ddev-craft-search-index-opensearch:9200
```

Then in the plugin settings, use `$MEILISEARCH_HOST`, `$MEILISEARCH_API_KEY`, etc.

### Testing workflow

1. Install the plugin in your Craft project (path repository or Composer)
2. Configure engine credentials in **Settings > Search Index**
3. Create an index: **Search Index > Indexes > New Index**
4. Select sections, entry types, and site
5. Click **Re-detect Fields** to auto-generate field mappings
6. Review and adjust mappings, then **Save Mappings**
7. Click **Validate Fields** to test resolution against real entries
8. Run a bulk import: `php craft search-index/index/import --index=your_handle`
9. Process the queue: `php craft queue/run`
10. Test searches on the **Search** CP page or via Twig/GraphQL
