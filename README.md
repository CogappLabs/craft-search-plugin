# Search Index for Craft CMS

[![CI](https://github.com/CogappLabs/craft-search-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/CogappLabs/craft-search-plugin/actions/workflows/ci.yml)

A UI-driven search index configuration plugin for Craft CMS 5 with multi-engine support. Define indexes, map fields, and sync content to external search engines -- all from the control panel.

Supports **Algolia**, **Elasticsearch**, **OpenSearch**, **Meilisearch**, and **Typesense**.

**[Read the full documentation](https://cogapplabs.github.io/craft-search-plugin/)**

## Requirements

- PHP 8.2 or later
- Craft CMS 5.0 or later

## Installation

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

Activate the plugin:

```bash
php craft plugin/install search-index
```

All engine SDKs are optional -- install only the one you need:

```bash
composer require elasticsearch/elasticsearch            # Elasticsearch
composer require algolia/algoliasearch-client-php       # Algolia
composer require opensearch-project/opensearch-php      # OpenSearch
composer require meilisearch/meilisearch-php            # Meilisearch
composer require typesense/typesense-php                # Typesense
```

## Features

<!-- --8<-- [start:features] -->
- **Multi-engine support** -- Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense through a single unified API
- **CP-driven index management** -- create indexes, configure engines, map fields, and assign semantic roles without touching config files
- **Auto-detected field mappings** with per-field type, weight, enable/disable control, and Matrix sub-field expansion
- **Semantic roles** -- assign title, image, thumbnail, summary, URL, date, IIIF, and geo roles so templates can render results generically
- **Faceted search & filtering** -- request facets on any mapped field, apply filters with a simple `{ field: value }` syntax across all engines
- **Highlighting & suggestions** -- opt-in hit highlighting and phrase suggestions (Elasticsearch/OpenSearch)
- **Vector search** -- generate embeddings via Voyage AI and run semantic or hybrid (text + vector) search with a single `vectorSearch: true` flag
- **Autocomplete** -- lightweight prefix search optimised for type-ahead UIs
- **Sprig search UI** -- publish customisable frontend starter templates (search form, results, facets, pagination) via CLI and style with your own CSS
- **Geo search** -- radius filtering, distance sorting, and server-side geo grid clustering (ES/OpenSearch) with centroid-based coordinates and sample hits for map UIs
- **Related documents** -- "More Like This" endpoint for finding similar content
- **Index statistics** -- document count, engine name, and existence check via REST API
- **Responsive images** -- automatic WebP transforms with srcset for hit images and thumbnails
- **Multi-search** -- batch queries across multiple indexes in a single call
- **Read-only mode** for querying externally managed indexes with auto-detected schema fields
- **Twig, GraphQL, and console** interfaces
- **Search Document field type** for linking entries to search engine documents
- **Validation & diagnostics** -- validate field mappings against real entries, debug search results and indexed documents from the CLI
- **Server-side API caching** -- all REST API responses cached indefinitely and invalidated automatically on content changes, config updates, or via Craft's Clear Caches utility
- **Real-time sync** on entry save/delete, queue-based bulk import, and atomic swap (zero-downtime refresh)
- **Built-in CP search page** with single and compare modes
- **Extensible** -- register custom engines, field resolvers, and listen to lifecycle events
<!-- --8<-- [end:features] -->

## Quick Start

```twig
{% set results = craft.searchIndex.search('myIndex', 'london', { perPage: 20 }) %}

{% for hit in results.hits %}
    <p>{{ hit.title }} ({{ hit._score }})</p>
{% endfor %}
```

See the [full documentation](https://cogapplabs.github.io/craft-search-plugin/) for configuration, Twig/GraphQL usage, field resolvers, extending, and more.

## Development

```bash
ddev start                                    # Start DDEV + search engines
ddev exec vendor/bin/phpunit                  # Unit tests
ddev exec vendor/bin/phpunit --testsuite integration  # Integration tests
ddev exec composer phpstan                    # Static analysis
ddev exec composer check-cs                   # Coding standards
```

See the [development docs](https://cogapplabs.github.io/craft-search-plugin/development/) for DDEV setup, testing against a Craft project, and code quality tools.

A companion [testbed project](https://github.com/CogappLabs/craft-search-plugin-testbed) provides a full Craft CMS site with demo content, Tailwind-styled Sprig search templates, and DDEV configuration for testing the plugin end-to-end.

## License

This plugin is licensed under the [MIT License](LICENSE).
