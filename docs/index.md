# Search Index for Craft CMS

A UI-driven search index configuration plugin for Craft CMS 5 with multi-engine support. Define indexes, map fields, and sync content to external search engines -- all from the control panel.

Supports **Algolia**, **Elasticsearch**, **OpenSearch**, **Meilisearch**, and **Typesense**.

## Features

--8<-- "README.md:features"

## Get Started

- [Installation](installation.md) -- install the plugin and engine SDKs
- [Configuration](configuration.md) -- plugin settings and environment variables
- [Control Panel](usage/control-panel.md) -- create indexes, map fields, and test searches

## Usage

- [Twig](usage/twig.md) -- search, pagination, and template helpers
- [GraphQL](usage/graphql.md) -- headless search queries
- [Console Commands](usage/console-commands.md) -- import, flush, refresh, validate, and debug
- [Search Document Field](usage/search-document-field.md) -- custom field type for linking to search documents
- [Read-Only Indexes](usage/read-only-indexes.md) -- query externally managed indexes
- [Filtering](usage/filtering.md) -- filter results by section and entry type

## Reference

- [Field Resolvers](field-resolvers.md) -- how Craft fields map to index types
- [Extending](extending.md) -- custom engines, field resolvers, and events
- [How It Works](how-it-works.md) -- architecture and sync lifecycle
- [Development](development.md) -- DDEV setup, tests, and code quality
