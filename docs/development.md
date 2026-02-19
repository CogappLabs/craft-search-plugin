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

The plugin's DDEV config provides the search engine services, but to test against real content you need to connect it to a Craft project.

### Step 1: Mount the plugin source (recommended)

If both the plugin and your Craft project use DDEV, copy the ready-made stub files from the plugin repo into your Craft project's `.ddev/` directory:

```bash
cp craft-search-index/stubs/ddev/docker-compose.craft-search-index.yaml your-craft-project/.ddev/
```

Edit the host path in the copied file to point to wherever you cloned the plugin:

```yaml
services:
  web:
    volumes:
      - $HOME/git/craft-search-index:/var/www/html/craft-search-index
```

Then require the plugin via a VCS repository in your Craft project's `composer.json`:

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

```bash
ddev composer require cogapp/craft-search-index:*@dev
```

To make local edits reflect immediately (without re-running `composer update`), add a DDEV `post-start` hook that symlinks the bind mount into `vendor/`. In your Craft project's `.ddev/config.yaml`:

```yaml
hooks:
  post-start:
    - exec: |
        if [ -d /var/www/html/craft-search-index ] && [ ! -L /var/www/html/vendor/cogapp/craft-search-index ]; then
          rm -rf /var/www/html/vendor/cogapp/craft-search-index
          ln -s /var/www/html/craft-search-index /var/www/html/vendor/cogapp/craft-search-index
          echo "Symlinked vendor/cogapp/craft-search-index -> bind mount"
        fi
```

This approach keeps `composer.lock` pointing at the VCS source (so deployments to Railway/CI work), while local development gets live edits via the symlink. After editing plugin templates, clear compiled templates for changes to take effect:

```bash
ddev exec php craft clear-caches/compiled-templates
```

!!! note "Why not a path repository?"
    A Composer `path` repository writes `"type": "path"` into `composer.lock`, which fails on any environment that doesn't have the bind mount (CI, staging, production). The DDEV hook approach keeps the lock file portable.

### Step 2: Connect to the search engines

The plugin's DDEV project runs the search engine containers. Copy the second stub file to connect your Craft project to them:

```bash
cp craft-search-index/stubs/ddev/docker-compose.search-engines.yaml your-craft-project/.ddev/
```

This joins the plugin's Docker network and aliases the service names, so your Craft project can reference the engines by short hostname (`elasticsearch`, `meilisearch`, etc.) instead of full DDEV container names.

In your Craft project's `.env`:

```bash
# Meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_API_KEY=ddev_meilisearch_key

# Typesense
TYPESENSE_HOST=typesense
TYPESENSE_PORT=8108
TYPESENSE_PROTOCOL=http
TYPESENSE_API_KEY=ddev_typesense_key

# Elasticsearch
ELASTICSEARCH_HOST=http://elasticsearch:9200

# OpenSearch
OPENSEARCH_HOST=http://opensearch:9200
```

Then in the plugin settings, use `$MEILISEARCH_HOST`, `$MEILISEARCH_API_KEY`, etc.

Restart both DDEV projects after adding these files (`ddev restart`).

!!! tip "Stub files"
    Both files are in [`stubs/ddev/`](https://github.com/CogappLabs/craft-search-plugin/tree/main/stubs/ddev) with inline comments explaining each option.

### Alternative: Copy engine services

If you'd rather not depend on the plugin's DDEV project for search engines, copy the relevant service definitions from the plugin's `.ddev/docker-compose.*.yaml` files into your Craft project's `.ddev/` directory. This runs the engines directly in your Craft project.

### Testing workflow

1. Install the plugin (path repository as above, or via Composer)
2. Configure engine credentials in **Settings > Search Index**
3. Create an index: **Search Index > Indexes > New Index**
4. Select sections, entry types, and site
5. Click **Re-detect Fields** to auto-generate field mappings
6. Review and adjust mappings, then **Save Mappings**
7. Click **Validate Fields** to test resolution against real entries
8. Run a bulk import: `php craft search-index/index/import --index=your_handle`
9. Process the queue: `php craft queue/run`
10. Test searches on the **Search** CP page or via Twig/GraphQL
