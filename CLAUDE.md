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
- `src/engines/AbstractEngine.php` -- base class with shared helpers (pagination, hit normalisation, `getDocument()` fallback, `sortByWeight()`)
- `src/engines/ElasticCompatEngine.php` -- shared base for Elasticsearch + OpenSearch (~90% identical API surface)
- `src/engines/{Algolia,Elasticsearch,OpenSearch,Meilisearch,Typesense}Engine.php` -- concrete implementations

### Models
- `src/models/SearchResult.php` -- normalised DTO returned by all `search()` methods (readonly, ArrayAccess, Countable)
- `src/models/Index.php` -- index config model (extends `craft\base\Model`), `createEngine()` factory method
- `src/models/FieldMapping.php` -- field-to-index mapping model with TYPE_* and ROLE_* constants

### Services
- `src/services/FieldMapper.php` -- maps Craft fields to index types, resolves elements to documents
- `src/services/FieldMappingValidator.php` -- validates field mappings against real entries (shared by CP + console)
- `src/services/Indexes.php` -- CRUD for Index records
- `src/services/Sync.php` -- bulk import/export orchestration

### Field Resolvers
- `src/resolvers/FieldResolverInterface.php` -- resolver contract
- Individual resolvers: PlainText, RichText (CKEditor), Number, Boolean, Date, Options, Relation, Asset, Matrix, Table, Address, Attribute
- `DEFAULT_FIELD_TYPE_MAP` and `DEFAULT_RESOLVER_MAP` in FieldMapper map Craft field classes to index types and resolvers
- Matrix sub-fields resolved via `_resolveSubFieldValue()` which iterates blocks and uses typed resolvers per sub-field

### Custom Field Type
- `src/fields/SearchDocumentField.php` -- Craft field type for selecting a document from a search index
- `src/fields/SearchDocumentValue.php` -- value object (indexHandle + documentId, lazy `getDocument()`, role helpers: `getTitle()`, `getImage()`, `getImageUrl()`, `getSummary()`, `getUrl()`, `getDate()`, `getEntry()`, `getEntryId()`, `getAsset()`)

### GraphQL
- `src/gql/queries/SearchIndex.php` -- registers `searchIndex` query
- `src/gql/resolvers/SearchResolver.php` -- resolves search queries
- `src/gql/types/SearchHitType.php`, `SearchResultType.php`, `SearchDocumentFieldType.php`

### Controllers
- `IndexesController` -- CP CRUD for indexes, search page
- `FieldMappingsController` -- field mapping editor, save, re-detect, validate (delegates to FieldMappingValidator)
- `SearchController` -- AJAX search/getDocument endpoints for CP
- `console/controllers/IndexController` -- console commands: import, flush, refresh, redetect (--fresh), status, validate (--slug, --format, --only), debug-search, debug-entry

### Twig (SearchIndexVariable)
- `craft.searchIndex.search(handle, query, options)` -- search an index
- `craft.searchIndex.autocomplete(handle, query, options)` -- lightweight autocomplete (5 results, title-only, minimal payload)
- `craft.searchIndex.multiSearch(queries)` -- batch search across indexes (engine-grouped)
- `craft.searchIndex.searchFacetValues(handle, facetName, query, options)` -- search within facet values
- `craft.searchIndex.getDocument(handle, documentId)` -- retrieve a single document
- `craft.searchIndex.getIndexes()` / `getIndex(handle)` -- get index configs
- `craft.searchIndex.getDocCount(handle)` -- document count
- `craft.searchIndex.isReady(handle)` -- check engine connectivity

### Search Options (unified across engines)
- `page` / `perPage` -- pagination
- `sort` -- `{ field: 'asc'|'desc' }`, translated to each engine's native format
- `facets` -- `['field1', 'field2']`, returns normalised `{ field: [{ value, count }] }`
- `filters` -- `{ field: 'value' }` or `{ field: ['val1', 'val2'] }`, engine-agnostic
- `attributesToRetrieve` -- limit returned fields per search
- `highlight` -- opt-in, returns normalised `{ field: [fragments] }` on each hit
- `suggest` -- (ES/OpenSearch) phrase suggestions, populates `SearchResult::$suggestions`
- Engine-native options always take precedence when provided directly

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

### Console Commands (run from host Craft project via DDEV)

```bash
php craft search-index/index/status                     # Show all indexes, connection status, doc counts
php craft search-index/index/import [handle]            # Queue bulk import jobs
php craft search-index/index/flush [handle]             # Clear all documents
php craft search-index/index/refresh [handle]           # Flush + re-import
php craft search-index/index/redetect [handle]          # Re-detect field mappings (merge with existing)
php craft search-index/index/redetect --fresh [handle]  # Re-detect field mappings (discard existing settings)
php craft search-index/index/validate [handle]          # Validate field mappings against real entries
php craft search-index/index/validate --format=json     # JSON output
php craft search-index/index/validate --only=issues     # Only show warnings/errors/nulls
php craft search-index/index/debug-search <handle> "<query>" ['{"perPage":10}']  # Debug search results
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

### Index Modes
- `Index::MODE_SYNCED` (default) -- plugin syncs Craft content to the engine
- `Index::MODE_READONLY` -- externally managed index, query-only (no sync, no field mappings)
- `$index->isReadOnly()` helper; read-only indexes are excluded from `getIndexesForElement()` (real-time sync gate)
- Console commands (import/flush/refresh/redetect/validate) skip read-only indexes
- CP: read-only indexes have no Fields tab, no Sync/Flush buttons; Sources section hidden on edit form

### Asset Bundles
- `src/web/assets/` — one bundle per CP template (IndexEditAsset, IndexListAsset, IndexStructureAsset, FieldMappingsAsset, SearchPageAsset, SearchDocumentFieldAsset)
- Each bundle has a PHP class + `dist/` folder with JS (and optionally CSS)
- Templates pass data via `data-*` attributes; JS reads from the DOM
- No inline `{% js %}` / `<script>` blocks in templates

## Conventions

- Engine `search()` methods return `SearchResult`, never raw arrays
- Every hit has `objectID` (string), `_score` (float|int|null), `_highlights` (array)
- Unified pagination: `page` (1-based) + `perPage` in options; engine-native keys take precedence
- `SearchResult::$raw` preserves the original engine response for engine-specific access
- Use `$index->createEngine()` to instantiate engines — never call `new $engineClass()` directly
- Engine clients are injected in integration tests via reflection on the private `$_client` property
- All engine client libraries are dev dependencies (listed in `suggest` for production)
- Field mappings use `fieldUid` + `parentFieldUid` for Matrix sub-field relationships
- Field mappings support semantic roles (title, image, summary, url, date) -- one per role per index
- Asset resolver defaults to storing the Craft asset ID (integer), not the URL -- `getImage()` returns a full Asset element
- Fields with auto-assigned roles are auto-enabled during detection
- Stale UID fallback: `_resolveSubFieldValue()` and validator derive expected handle from `indexFieldName` when UID lookup fails
- Merge-based redetect (`redetectFieldMappings()`) preserves user settings; fresh redetect (`detectFieldMappings()`) resets to defaults
- Refresh command deletes and recreates indexes (not just flush) so field type changes take effect
- Validate Fields button tests field resolution against real entries without saving
- Deep sub-field lookup checks actual block data (not just parent :notempty:) for validation
- `sectionHandle` and `entryTypeHandle` always injected into indexed documents for Entry elements
- Request-scoped caching: engine instances, field UID lookups, resolver instances, role maps, asset queries
- Read-only indexes support schema introspection via `getSchemaFields()` on all engines
- Atomic swap currently only supported by Meilisearch (via native `swapIndexes()` API)
- `DocumentSyncEvent` fired after index/delete/bulk operations for third-party hooks
- `EVENT_REGISTER_FIELD_RESOLVERS` on FieldMapper allows third-party resolver registration
- `EVENT_BEFORE_INDEX_ELEMENT` on FieldMapper allows document modification before indexing
