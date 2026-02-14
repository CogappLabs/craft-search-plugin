# Search Index for Craft CMS

A UI-driven search index configuration plugin for Craft CMS 5 with multi-engine support. Define indexes, map fields, and sync content to external search engines -- all from the control panel.

## Features

- **Multi-engine support** -- Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense.
- **Control panel UI** -- Create indexes, configure field mappings, and test searches without touching code.
- **Real-time sync** -- Entries are automatically indexed on save, delete, and restore.
- **Read-only indexes** -- Query externally managed indexes without syncing Craft content.
- **Twig & GraphQL** -- Search from templates or headless frontends with a normalised result shape.
- **Search Document field** -- A custom Craft field type for linking entries to search engine documents.
- **Console commands** -- Bulk import, flush, refresh, validate, and debug from the CLI.
- **Extensible** -- Register custom engines, field resolvers, and listen to lifecycle events.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.0 or later
