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

Engine SDKs (other than Elasticsearch, which is a hard dependency) are optional -- install only the one you need:

```bash
composer require algolia/algoliasearch-client-php       # Algolia
composer require opensearch-project/opensearch-php      # OpenSearch
composer require meilisearch/meilisearch-php            # Meilisearch
composer require typesense/typesense-php                # Typesense
```

## Features

- **CP-driven index management** -- create indexes, configure engines, map fields, and assign semantic roles without touching config files
- **Auto-detected field mappings** with per-field type, weight, and enable/disable control
- **Matrix sub-field expansion** -- granular control over nested fields
- **Real-time sync** on entry save/delete with relation cascade support
- **Bulk import** with queue-based batching and orphan cleanup
- **Atomic swap** (zero-downtime refresh) for supported engines
- **Read-only mode** for querying externally managed indexes
- **Twig, GraphQL, and console** interfaces
- **Search Document field type** for linking entries to search engine documents
- **Built-in CP search page** with single and compare modes
- **Extensible** -- register custom engines, field resolvers, and listen to lifecycle events

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

## License

This plugin is licensed under the [MIT License](LICENSE).
