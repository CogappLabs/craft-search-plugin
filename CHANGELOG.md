# Changelog

All notable changes to the Search Index plugin for Craft CMS are documented in this file.

## [Unreleased]

### Vector Search & Embeddings
- Add `VoyageClient` service for generating query embeddings via the Voyage AI API
- Add `vectorSearch` option to Twig `search()`, GraphQL `searchIndex` query, and CLI `debug-search` command
- Auto-detect embedding field from `TYPE_EMBEDDING` field mappings when `embeddingField` not specified
- Add KNN query support to Elasticsearch and OpenSearch engines (vector-only and hybrid text+vector)
- Add `extractEmbeddingParams()` helper to `AbstractEngine`
- Add `TYPE_EMBEDDING` field type mapping to `knn_vector` (OpenSearch) / `dense_vector` (Elasticsearch)
- Add `voyageApiKey` setting to plugin settings (Integrations section)

### IIIF Support
- Add `ROLE_IIIF` semantic role for IIIF Image API info.json URLs
- Add `getIiifInfoUrl()` and `getIiifImageUrl(options)` helpers to `SearchDocumentValue`
- Fuzzy role matching in `defaultRoleForFieldName()` for broader auto-assignment

### GraphQL Enhancements
- Add `facets`, `filters`, `sort`, `highlight`, `suggest` arguments to `searchIndex` query
- Add `vectorSearch`, `voyageModel`, `embeddingField` arguments for vector search via GraphQL
- Add `facets` and `suggestions` fields to `SearchResult` type
- Add `_highlights` field to `SearchHit` type

### Read-Only Index Improvements
- Add document sampling fallback for `getSchemaFields()` in ElasticCompatEngine
- Add `inferFieldType()` and `reverseMapFieldType()` with knn_vector/dense_vector support
- Add 403 status fallback in Elasticsearch/OpenSearch `indexExists()` and `testConnection()`
- Structured host config via `buildHostConfig()` in OpenSearchEngine

### Atomic Swap
- Add zero-downtime atomic swap support to all 5 engines (previously Meilisearch only)
- Add `buildSwapHandle()` to `EngineInterface` for engine-specific swap index naming
- Algolia: atomic swap via `operationIndex('move')` API
- Elasticsearch/OpenSearch: alias-based swap with alternating `_swap_a`/`_swap_b` backing indexes
- Typesense: collection alias-based swap with alternating backing collections
- Pass swap handle through queue to prevent race conditions with alternating names
- All `refresh` commands now use zero-downtime swap automatically

### Performance
- Add persistent index config cache with `TagDependency` invalidation in `Indexes` service
- Add persistent role map cache in `SearchDocumentValue` with tag-based invalidation
- Register "Search Index data caches" option in Craft's Clear Caches utility
- Cache engine instances in `SearchIndexVariable` by type+config hash, eliminating redundant HTTP client creation in template loops
- Cache field UID lookups in `FieldMapper` to avoid repeated Craft field service calls during document resolution
- Cache resolver instances in `FieldMapper` since resolvers are stateless
- Use static role map cache in `SearchDocumentValue` keyed by index handle
- Cache Asset query result in `SearchDocumentValue::getImage()` to prevent duplicate DB queries

### Search Features
- Normalise highlights to a consistent `{ field: [fragments] }` format across all five engines with opt-in `highlight` option
- Add `suggest: true` for Elasticsearch/OpenSearch phrase suggestions and `suggestions` property on `SearchResult` for "did you mean?" UIs
- Add unified `sort` option (`{ field: 'asc' }`) translated to each engine's native sort syntax
- Add unified `attributesToRetrieve` option to limit returned fields per search
- Add `craft.searchIndex.autocomplete()` Twig method with sensible defaults (5 results, title-only search, minimal payload)
- Add `craft.searchIndex.searchFacetValues()` for searching within facet values
- Add unified facet/filter support across all search engines with consistent `facets` and `filters` options
- All engines return normalised facets as `{ field: [{ value: string, count: int }] }`

### Read-Only Indexes
- Add field role mapping for read-only indexes via engine schema introspection (`getSchemaFields()` on all engines)
- Add `detectSchemaFieldMappings()` and `redetectSchemaFieldMappings()` to `FieldMapper`
- Simplified role editor for read-only indexes in the CP
- Update `redetect` console command to support read-only indexes (calls schema-based detection)
- Add date role with `getDate()` helper on `SearchDocumentValue`
- Make `getImageUrl()` fall back to raw string value for read-only indexes with image URLs

### Security & Accessibility
- Add `requireCpRequest()` to all CP controllers
- Use `JSON_THROW_ON_ERROR` on all `json_decode` calls
- Log engine connection failures instead of silently swallowing
- Externalise all hard-coded JS strings via `data-*` attributes for i18n
- Add `AbortController` to cancel stale search-document-field requests
- Add focus management after validation results render
- Context-aware `aria-label` attributes on index action menus
- Error visual distinction on structure page
- Focus styles on search document field result items
- Responsive CSS breakpoints for search and field mapping pages

### Documentation
- Add MkDocs documentation site with comprehensive guides
- Document highlighting, range filters, browse mode, and Sprig UX patterns
- Document facets and filtering with examples
- Document read-only indexes
- Deploy docs to GitHub Pages via CI

### CI/CD
- Add GitHub Actions CI workflow (ECS, PHPStan, PHPUnit, Biome, TypeScript)
- Add docs build validation on pull requests
- Add PHP ECS pre-commit hook to lefthook

### Bug Fixes
- Fix PHPStan error: qualify `Craft` class with leading backslash in console controller
- Fix Sprig examples to match official Sprig conventions
- Fix import ordering in `Sync.php`
- Fix docs build: use `uv run` instead of `uv pip install --system`

## [1.0.0] - 2026-02-14

### Core Features
- Multi-engine search support: Algolia, Elasticsearch, OpenSearch, Meilisearch, Typesense
- `SearchResult` DTO with normalised hits (`objectID`, `_score`, `_highlights`), pagination, facets, and raw engine response
- Unified `page`/`perPage` pagination across all engines
- `multiSearch()` with native batch implementations for all 5 engines and Twig variable with engine grouping
- `getDocument()` on `EngineInterface` with native implementations and search-based fallback
- Atomic swap for Meilisearch via native `swapIndexes()` API with `AtomicSwapJob` queue job
- `DocumentSyncEvent` fired after index/delete/bulk operations

### Field System
- CP field mapping UI with per-field type, weight, and enable controls
- 11 typed field resolvers: PlainText, RichText (CKEditor), Number, Boolean, Date, Options, Relation, Asset, Matrix, Table, Address, Attribute
- Matrix sub-field expansion for granular indexing
- Semantic roles (title, image, summary, url, date) with auto-assignment during detection
- Asset resolver stores Craft asset ID (integer) for full `Asset` element access
- Merge-based redetect preserves user settings; `--fresh` flag resets to defaults
- Stale UID fallback for Matrix sub-field resolution (handle-based matching)
- `sectionHandle` and `entryTypeHandle` injected into indexed documents for Entry elements

### Custom Field Type
- `SearchDocumentField` custom field type with autocomplete search input
- `SearchDocumentValue` lazy-loading value object with role helpers: `getTitle()`, `getImage()`, `getImageUrl()`, `getSummary()`, `getUrl()`, `getDate()`, `getEntry()`, `getEntryId()`

### GraphQL
- `searchIndex` query with `SearchResultType`, `SearchHitType`, `SearchDocumentFieldType`

### Control Panel
- Index listing with connection status indicators and Test Connection button
- Field mapping editor with Validate Fields button (per-field entry lookup and type diagnostics)
- CP search page with single and compare modes
- Index structure/schema viewer tab
- Read-only index support (no sync, no field mappings, query-only)

### Console Commands
- `search-index/index/import` -- Queue bulk import jobs
- `search-index/index/flush` -- Clear all documents
- `search-index/index/refresh` -- Flush + re-import (with atomic swap when supported)
- `search-index/index/redetect` -- Re-detect field mappings (with `--fresh` flag)
- `search-index/index/validate` -- Validate field mappings against real entries (`--format`, `--only`, `--slug`)
- `search-index/index/status` -- Show all indexes, connection status, document counts
- `search-index/index/debug-search` -- Debug search results
- `search-index/index/debug-entry` -- Debug entry field resolution

### Twig
- `craft.searchIndex.search(handle, query, options)` -- Search an index
- `craft.searchIndex.multiSearch(queries)` -- Batch search across indexes
- `craft.searchIndex.autocomplete(handle, query, options)` -- Lightweight autocomplete
- `craft.searchIndex.searchFacetValues(handle, facetName, query)` -- Search facet values
- `craft.searchIndex.getDocument(handle, documentId)` -- Retrieve a single document
- `craft.searchIndex.getIndexes()` -- Get all indexes
- `craft.searchIndex.getIndex(handle)` -- Get single index
- `craft.searchIndex.getDocCount(handle)` -- Get document count
- `craft.searchIndex.isReady(handle)` -- Check engine connectivity

### Infrastructure
- DDEV configuration with Elasticsearch, OpenSearch, Meilisearch, and Typesense services
- Vite + TypeScript build system for CP asset bundles
- Project Config storage for index/field mapping configuration
- Bulk import with batch jobs and orphan document cleanup
- Real-time sync on element save/delete with relation cascade
- `ElasticCompatEngine` shared base for Elasticsearch/OpenSearch (~90% shared code)
- Integration test suite with real engine round-trips
- Unit tests for models, engine schemas, and search normalisation
- PHPStan, ECS, and Rector configuration
