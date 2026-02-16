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
- `src/engines/AbstractEngine.php` -- base class with shared helpers (pagination, hit normalisation, embedding extraction, `getDocument()` fallback, `sortByWeight()`)
- `src/engines/ElasticCompatEngine.php` -- shared base for Elasticsearch + OpenSearch (~90% identical API surface)
- `src/engines/{Algolia,Elasticsearch,OpenSearch,Meilisearch,Typesense}Engine.php` -- concrete implementations

### Models
- `src/models/SearchResult.php` -- normalised DTO returned by all `search()` methods (readonly, ArrayAccess, Countable)
- `src/models/Index.php` -- index config model (extends `craft\base\Model`), `createEngine()` factory method
- `src/models/FieldMapping.php` -- field-to-index mapping model with TYPE_* (text, keyword, integer, float, boolean, date, geo_point, facet, object, embedding) and ROLE_* (title, image, thumbnail, summary, url, date, iiif) constants

### Services
- `src/services/FieldMapper.php` -- maps Craft fields to index types, resolves elements to documents
- `src/services/FieldMappingValidator.php` -- validates field mappings against real entries (shared by CP + console), also owns `buildValidationMarkdown()`
- `src/services/Indexes.php` -- CRUD for Index records
- `src/services/Sync.php` -- bulk import/export orchestration
- `src/services/VoyageClient.php` -- Voyage AI API wrapper for generating text embeddings (`embed()`, `resolveEmbeddingOptions()`)

### Field Resolvers
- `src/resolvers/FieldResolverInterface.php` -- resolver contract
- Individual resolvers: PlainText, RichText (CKEditor), Number, Boolean, Date, Options, Relation, Asset, Matrix, Table, Address, Attribute
- `DEFAULT_FIELD_TYPE_MAP` and `DEFAULT_RESOLVER_MAP` in FieldMapper map Craft field classes to index types and resolvers
- Matrix sub-fields resolved via `_resolveSubFieldValue()` which iterates blocks and uses typed resolvers per sub-field

### Custom Field Type
- `src/fields/SearchDocumentField.php` -- Craft field type for selecting a document from a search index
- `src/fields/SearchDocumentValue.php` -- value object (indexHandle + documentId, lazy `getDocument()`, role helpers: `getTitle()`, `getImage()`, `getImageUrl()`, `getThumbnail()`, `getThumbnailUrl()`, `getSummary()`, `getUrl()`, `getDate()`, `getIiifInfoUrl()`, `getIiifImageUrl()`, `getEntry()`, `getEntryId()`, `getAsset()`)
- `SearchDocumentPicker` Sprig component handles the picker UI (search, results, selection); hidden form inputs live in `_field/input.twig` outside Sprig (namespaced by Craft); thin JS bridge syncs `data-*` attributes to hidden inputs after each swap
- **Focus workaround**: htmx's id-based focus restoration doesn't survive the first Sprig outerHTML swap, so the JS bridge (`search-document-field.ts`, snippet `focus-workaround`) manually refocuses the query input after each settle. `s-preserve` can't help — it doesn't preserve focus/caret on text inputs.

### GraphQL
- `src/gql/queries/SearchIndex.php` -- registers `searchIndex` query (args: index, query, perPage, page, fields, sort, facets, filters, vectorSearch, voyageModel, embeddingField, highlight, includeTiming)
- `src/gql/resolvers/SearchResolver.php` -- resolves search queries, auto-generates embeddings for vectorSearch
- `src/gql/types/SearchHitType.php`, `SearchResultType.php`, `SearchDocumentFieldType.php`
- `SearchResultType` includes `facets` (JSON scalar) and `suggestions` fields
- `SearchHitType` includes `_highlights` (JSON scalar) field

### Controllers
- `IndexesController` -- CP CRUD for indexes, search page
- `FieldMappingsController` -- field mapping editor, save, re-detect, validate (delegates to FieldMappingValidator)
- `SearchController` -- AJAX search/getDocument endpoints for CP
- `DemoController` -- frontend developer demo page for Sprig (`/search-sprig--default-components`, dev mode only)
- `console/controllers/IndexController` -- console commands: import, flush, refresh, redetect (--fresh), status, validate (--slug, --format, --only), debug-search, debug-entry, debug-schema (--format), preview-schema (--format), get-document, debug-multi-search, publish-sprig-templates (--force)

### Sprig + Twig Helpers
- CP UI is implemented with class-based Sprig components under `src/sprig/components/`
- Frontend starter components live under `src/sprig/components/frontend/`:
  - `SearchBox`, `SearchFacets`, `SearchPagination`
  - `SearchBox` supports `autoSearch` + `hideSubmit` flags for debounce/search UX control
- Publishable starter templates live under `src/templates/stubs/sprig/` and can be copied into project templates via CLI
- Published stubs contain full customizable HTML (not wrappers): `search.twig` (layout) includes separate partials (`search-form`, `search-filters`, `search-results`, `search-facets`, `search-pagination`)
- Published stubs call `craft.searchIndex.searchContext()` once in the layout and pass a `shared` context object to all partials
- `src/sprig/SprigBooleanTrait.php` -- shared `toBool(mixed $value): bool` for Sprig property coercion (used by all components)
- `src/web/twig/SearchIndexTwigExtension.php` registers:
  - `searchIndexSprig(aliasOrComponent, variables, attributes)` -- safe wrapper around Twig `sprig()`
  - `searchIndexSprigComponent(alias)` -- resolves short alias to concrete component class
  - `siToBool(value)` -- Twig boolean coercion matching `SprigBooleanTrait::toBool()`
- Alias map includes:
  - CP: `cp.test-connection`, `cp.validation-results`, `cp.index-structure`, `cp.index-health`, `cp.search-single`, `cp.search-compare`, `cp.search-document-picker`
  - Frontend: `frontend.search-box`, `frontend.search-facets`, `frontend.search-pagination`
- CP search components (`SearchSingle`, `SearchCompare`) default to auto-search behavior with debounce in templates

### Twig (SearchIndexVariable)
- `craft.searchIndex.search(handle, query, options)` -- search an index
- `craft.searchIndex.autocomplete(handle, query, options)` -- lightweight autocomplete (5 results, title-only, minimal payload)
- `craft.searchIndex.multiSearch(queries)` -- batch search across indexes (engine-grouped)
- `craft.searchIndex.searchFacetValues(handle, facetName, query, options)` -- search within facet values
- `craft.searchIndex.getDocument(handle, documentId)` -- retrieve a single document
- `craft.searchIndex.getIndexes()` / `getIndex(handle)` -- get index configs
- `craft.searchIndex.getDocCount(handle)` -- document count
- `craft.searchIndex.isReady(handle)` -- check engine connectivity
- `craft.searchIndex.stateInputs(state, options)` -- render hidden `<input>` fields for Sprig state; `{ exclude: 'key' }` to omit keys
- `craft.searchIndex.buildUrl(basePath, params)` -- build clean URLs with query params (arrays expand to `key[]=value`, null/empty omitted)
- `craft.searchIndex.searchContext(handle, options)` -- all-in-one context for frontend Sprig stubs: returns `{ roles, facetFields, sortOptions, data }` in one call; mirrors SearchBox.php logic
- `cpSearch()` now also returns `facets` and `suggestions` in addition to hits/pagination/raw

### Search Options (unified across engines)
- `page` / `perPage` -- pagination
- `sort` -- `{ field: 'asc'|'desc' }`, translated to each engine's native format
- `facets` -- `['field1', 'field2']`, returns normalised `{ field: [{ value, count }] }`
- `filters` -- `{ field: 'value' }` or `{ field: ['val1', 'val2'] }`, engine-agnostic
- `attributesToRetrieve` -- limit returned fields per search
- `highlight` -- opt-in, returns normalised `{ field: [fragments] }` on each hit
- `suggest` -- (ES/OpenSearch) phrase suggestions, populates `SearchResult::$suggestions`
- `vectorSearch` -- `true` to auto-generate embedding via Voyage AI and run KNN search
- `voyageModel` -- Voyage AI model name (default: `voyage-3`), only used with `vectorSearch`
- `embeddingField` -- target embedding field name; auto-detected from TYPE_EMBEDDING mappings if omitted
- `embedding` -- pre-computed float[] vector; skips Voyage API call when provided directly
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
php craft search-index/index/debug-autocomplete <handle> "<query>" ['{"perPage":5}']  # Debug autocomplete results
php craft search-index/index/debug-facet-search <handle> ["<query>"] ['{"maxPerField":10}']  # Debug facet value search
php craft search-index/index/get-document <handle> <documentId>                   # Fetch and display a raw document by ID
php craft search-index/index/debug-schema <handle> [--format=json]               # Show live engine schema (fields + raw)
php craft search-index/index/preview-schema <handle> [--format=json]             # Preview schema from field mappings vs live
php craft search-index/index/debug-multi-search '[{"handle":"h","query":"q"}]'   # Batch multi-search (JSON array input)
php craft search-index/index/publish-sprig-templates [subpath] [--force=1]       # Publish frontend Sprig starter templates
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
- All assets built through Vite (`vite.config.ts`); CSS-only bundles use tiny TS stubs that import CSS
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
- Field mappings support semantic roles (title, image, thumbnail, summary, url, date, iiif) -- one per role per index
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
- ElasticCompatEngine falls back to document sampling when mapping API is blocked (403) for field inference
- ElasticCompatEngine `indexExists()` and `testConnection()` handle 403 gracefully for read-only users
- OpenSearch client uses structured host config (`buildHostConfig()`) for correct HTTPS port detection
- TYPE_EMBEDDING maps to `knn_vector` in ES/OpenSearch; ElasticCompatEngine builds KNN queries for vector search
- When both text query and embedding are provided, ElasticCompatEngine combines them in `bool/should` (hybrid search)
- `VoyageClient::embed()` generates embeddings via Voyage AI API; returns null gracefully when no key configured
- `VoyageClient::resolveEmbeddingOptions()` centralises embedding resolution (shared by SearchIndexVariable, SearchResolver, SearchController, console)
- SearchIndexVariable auto-generates embeddings when `vectorSearch: true` and auto-detects embedding field from TYPE_EMBEDDING mappings
- `FieldMappingValidator::buildValidationMarkdown()` is the single source for validation result formatting (delegates from Twig variable, Sprig component, console)
- `SprigBooleanTrait::toBool()` is the single source for Sprig boolean coercion; all components `use SprigBooleanTrait`
- `siToBool()` Twig function mirrors the trait for templates; replaces verbose `is same as(true) or ... in [...]` pattern
- `@set_time_limit()` with error suppression for hosting environments that disable the function
- `getIiifImageUrl(width, height)` derives IIIF Image API URLs from the info.json URL stored in the iiif role
- Fuzzy role matching in `defaultRoleForFieldName()` maps common external field names (description, summary, iiif_info_url, etc.) to roles
- Atomic swap supported by Meilisearch (native `swapIndexes()`), Elasticsearch, OpenSearch, and Typesense (alias-based)
- `DocumentSyncEvent` fired after index/delete/bulk operations for third-party hooks
- `EVENT_REGISTER_FIELD_RESOLVERS` on FieldMapper allows third-party resolver registration
- `EVENT_BEFORE_INDEX_ELEMENT` on FieldMapper allows document modification before indexing
- Auto-derived `has_image` boolean: when a ROLE_IMAGE mapping exists, `resolveElement()` injects `has_image: true/false` based on whether the image field resolved to a non-empty value; Typesense schema includes it as a facetable bool

## Related Repositories

- **Testbed**: [craft-search-plugin-testbed](https://github.com/CogappLabs/craft-search-plugin-testbed) — Craft CMS 5 site with demo content, Tailwind-styled Sprig search templates, and DDEV config for end-to-end plugin testing. Local path: `~/git/craft5-ddev-plugin-dev`

## External References (Local, Gitignored)

- Sprig docs snapshot: `references/external/putyourlightson-sprig.md`
- Craft API v5 snapshot: `references/external/craft-api-v5.md`
- Source URLs:
  - `https://putyourlightson.com/plugins/sprig`
  - `https://docs.craftcms.com/api/v5/`
