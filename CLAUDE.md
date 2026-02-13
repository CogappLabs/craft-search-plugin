# CLAUDE.md

## Project Overview

Craft CMS 5 plugin that syncs content to external search engines via UI-configured indexes and field mappings. Supports Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense.

## Tech Stack

- PHP 8.2+, Craft CMS 5
- PHPUnit 11 for tests
- DDEV for local development (PHP runtime + search engine services)

## Key Architecture

### Engines
- `src/engines/EngineInterface.php` -- contract all engines implement (`search()`, `upsert()`, `delete()`, `getDocument()`, schema methods)
- `src/engines/AbstractEngine.php` -- base class with shared helpers (pagination, hit normalisation, `getDocument()` fallback)
- `src/engines/{Algolia,Elasticsearch,OpenSearch,Meilisearch,Typesense}Engine.php` -- concrete implementations

### Models
- `src/models/SearchResult.php` -- normalised DTO returned by all `search()` methods (readonly, ArrayAccess, Countable)
- `src/models/Index.php` -- index config model (extends `craft\base\Model`)
- `src/models/FieldMapping.php` -- field-to-index mapping model with TYPE_* constants

### Services
- `src/services/FieldMapper.php` -- maps Craft fields to index types, resolves elements to documents
- `src/services/Indexes.php` -- CRUD for Index records
- `src/services/Sync.php` -- bulk import/export orchestration

### Field Resolvers
- `src/resolvers/FieldResolverInterface.php` -- resolver contract
- Individual resolvers: PlainText, RichText (CKEditor), Number, Boolean, Date, Options, Relation, Asset, Matrix, Table, Address, Attribute
- `DEFAULT_FIELD_TYPE_MAP` and `DEFAULT_RESOLVER_MAP` in FieldMapper map Craft field classes to index types and resolvers
- Matrix sub-fields resolved via `_resolveSubFieldValue()` which iterates blocks and uses typed resolvers per sub-field

### Custom Field Type
- `src/fields/SearchDocumentField.php` -- Craft field type for selecting a document from a search index
- `src/fields/SearchDocumentValue.php` -- value object (indexHandle + documentId, lazy `getDocument()`)

### GraphQL
- `src/gql/queries/SearchIndex.php` -- registers `searchIndex` query
- `src/gql/resolvers/SearchResolver.php` -- resolves search queries
- `src/gql/types/SearchHitType.php`, `SearchResultType.php`, `SearchDocumentFieldType.php`

### Controllers
- `IndexesController` -- CP CRUD for indexes, search page
- `FieldMappingsController` -- field mapping editor, save, re-detect, validate
- `SearchController` -- AJAX search/getDocument endpoints for CP

### Twig
- `craft.searchIndex.search(handle, query, options)` -- search an index
- `craft.searchIndex.getDocument(handle, documentId)` -- retrieve a single document

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

- `tests/unit/` -- fast tests, no external services required, run by default
- `tests/integration/` -- real engine round-trip tests (seed, search, verify normalised shape, cleanup). Skip gracefully when services are down.
- `phpunit.xml` has `defaultTestSuite="unit"` so `vendor/bin/phpunit` only runs unit tests

## Conventions

- Engine `search()` methods return `SearchResult`, never raw arrays
- Every hit has `objectID` (string), `_score` (float|int|null), `_highlights` (array)
- Unified pagination: `page` (1-based) + `perPage` in options; engine-native keys take precedence
- `SearchResult::$raw` preserves the original engine response for engine-specific access
- Engine clients are injected in integration tests via reflection on the private `$_client` property
- All engine client libraries are dev dependencies (except `elasticsearch/elasticsearch` which is a hard dep)
- Field mappings use `fieldUid` + `parentFieldUid` for Matrix sub-field relationships
- Validate Fields button tests field resolution against real entries without saving
- Deep sub-field lookup checks actual block data (not just parent :notempty:) for validation
