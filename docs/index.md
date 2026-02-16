# Search Index for Craft CMS

A UI-driven search index configuration plugin for Craft CMS 5 with multi-engine support. Define indexes, map fields, and sync content to external search engines -- all from the control panel.

Supports **Algolia**, **Elasticsearch**, **OpenSearch**, **Meilisearch**, and **Typesense**.

## Features

- **Multi-engine support** -- Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense through a single unified API
- **CP-driven index management** -- create indexes, configure engines, map fields, and assign semantic roles without touching config files
- **Auto-detected field mappings** with per-field type, weight, enable/disable control, and Matrix sub-field expansion
- **Semantic roles** -- assign title, image, thumbnail, summary, URL, date, and IIIF roles so templates can render results generically
- **Faceted search & filtering** -- request facets on any mapped field, apply filters with a simple `{ field: value }` syntax across all engines
- **Highlighting & suggestions** -- opt-in hit highlighting and phrase suggestions (Elasticsearch/OpenSearch)
- **Vector search** -- generate embeddings via Voyage AI and run semantic or hybrid (text + vector) search with a single `vectorSearch: true` flag
- **Autocomplete** -- lightweight prefix search optimised for type-ahead UIs
- **Sprig search UI** -- publish customisable frontend starter templates (search form, results, facets, pagination) via CLI and style with your own CSS
- **Multi-search** -- batch queries across multiple indexes in a single call
- **Read-only mode** for querying externally managed indexes with auto-detected schema fields
- **Twig, GraphQL, and console** interfaces
- **Search Document field type** for linking entries to search engine documents
- **Validation & diagnostics** -- validate field mappings against real entries, debug search results and indexed documents from the CLI
- **Real-time sync** on entry save/delete, queue-based bulk import, and atomic swap (zero-downtime refresh)
- **Built-in CP search page** with single and compare modes
- **Extensible** -- register custom engines, field resolvers, and listen to lifecycle events

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
