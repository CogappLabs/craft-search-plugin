# CLAUDE.md

## Project Overview

Craft CMS 5 plugin that syncs content to external search engines via UI-configured indexes and field mappings. Supports Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense.

## Tech Stack

- PHP 8.2+, Craft CMS 5
- PHPUnit 11 for tests
- DDEV for local development (PHP runtime + search engine services)

## Key Architecture

- `src/engines/EngineInterface.php` — contract all engines implement
- `src/engines/AbstractEngine.php` — base class with shared helpers (pagination, hit normalisation)
- `src/engines/{Algolia,Elasticsearch,OpenSearch,Meilisearch,Typesense}Engine.php` — concrete implementations
- `src/models/SearchResult.php` — normalised DTO returned by all `search()` methods (readonly, ArrayAccess, Countable)
- `src/models/Index.php` — index config model (extends `craft\base\Model`)
- `src/models/FieldMapping.php` — field-to-index mapping model
- `src/variables/SearchIndexVariable.php` — Twig `craft.searchIndex` interface

## Development Commands

```bash
ddev start                                              # Start DDEV + all search engines
ddev exec vendor/bin/phpunit                            # Unit tests only (default suite)
ddev exec vendor/bin/phpunit --testsuite integration    # Integration tests (needs DDEV services)
ddev exec vendor/bin/phpunit --testsuite unit,integration  # All tests
ddev exec composer phpstan                              # Static analysis
ddev exec composer check-cs                             # Coding standards check
ddev exec composer fix-cs                               # Auto-fix coding standards
```

## DDEV Services

| Service       | Host (inside container) | Port | Auth                          |
|---------------|-------------------------|------|-------------------------------|
| Elasticsearch | `elasticsearch`         | 9200 | None (security disabled)      |
| OpenSearch    | `opensearch`            | 9200 | None (security disabled)      |
| Meilisearch   | `meilisearch`           | 7700 | Key: `ddev_meilisearch_key`   |
| Typesense     | `typesense`             | 8108 | Key: `ddev_typesense_key`     |

## Test Structure

- `tests/unit/` — fast tests, no external services required, run by default
- `tests/integration/` — real engine round-trip tests (seed, search, verify normalised shape, cleanup). Skip gracefully when services are down.
- `phpunit.xml` has `defaultTestSuite="unit"` so `vendor/bin/phpunit` only runs unit tests

## Conventions

- Engine `search()` methods return `SearchResult`, never raw arrays
- Every hit has `objectID` (string), `_score` (float|int|null), `_highlights` (array)
- Unified pagination: `page` (1-based) + `perPage` in options; engine-native keys take precedence
- `SearchResult::$raw` preserves the original engine response for engine-specific access
- Engine clients are injected in integration tests via reflection on the private `$_client` property
- All engine client libraries are dev dependencies (except `elasticsearch/elasticsearch` which is a hard dep)
