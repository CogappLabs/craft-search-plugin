# Changelog

All notable changes to the Search Index plugin for Craft CMS are documented in this file.

## [0.0.12] - 2026-02-20

### Added
- `X-Search-Cache` response header on all API endpoints (`HIT` when served from server-side cache, `MISS` when the search engine was queried).
- Caching section in REST API docs covering invalidation triggers, HTTP headers, and observability.
- Server-side API caching feature listed in README.

### Fixed
- Duplicate `Cache-Control` directives when running behind Railway/Varnish edge. Plugin now only sets `max-age` (browser cache); CDN-level `s-maxage` is left to the hosting platform.

## [0.0.11] - 2026-02-20

### Added
- Server-side response caching on all 8 REST API endpoints (`/search`, `/autocomplete`, `/multi-search`, `/facet-values`, `/related`, `/document`, `/meta`, `/stats`) using Yii `TagDependency` with indefinite TTL.
- Cache invalidation on entry save/delete (Sync service), atomic swap (AtomicSwapJob), project config changes, and Craft's Clear Caches utility.
- `Cache-Control` headers for `/meta` (5 min browser cache) and `/stats` (1 min browser cache).
- Null-stripping for search and multi-search JSON responses to reduce payload size.
- `_getApiCache()` / `_setApiCache()` helpers on `ApiController` for centralised cache key and tag management.

### Changed
- `Indexes::invalidateCache()` now also busts the `API_CACHE_TAG`, so project config changes and the Clear Caches utility automatically invalidate API response caches.
- Clear Caches label updated to "Search Index data and API response caches".
- Engine instances reused via `EngineRegistry::get()` across all API actions within a request (replaced per-action `$index->createEngine()` calls).
- Shared loaded `Asset` objects between `injectRoles()` and `injectForHits()` to eliminate duplicate DB queries.
- Memoized `Index::getRoleFieldMap()`, `AbstractEngine::detectGeoField()`, `ElasticCompatEngine::detectSuggestField()`, and `ElasticCompatEngine::buildFieldTypeMap()`.
- Batched geo cluster sample hit normalisation into a single `injectRoles` call.
- Cleaned up `actionAutocomplete` and `actionMeta` to use `getRoleFieldMap()`.

### Fixed
- `autocomplete` endpoint `perPage` maximum now enforced at 50 (was uncapped).
- `/multi-search` supports full parameter parity with `/search` (documented in OpenAPI spec).
- Updated OpenAPI spec and api-rest.md documentation.

## [0.0.10] - 2026-02-20

### Added
- Geo search support: `geoFilter` (radius filtering), `geoSort` (distance sorting), and `geoGrid` (server-side geo tile clustering) parameters on `/search` and `/multi-search`.
- `geoClusters` response field with centroid coordinates, document counts, tile keys, and sample hits for map UIs (Elasticsearch/OpenSearch via `geotile_grid` aggregation with `geo_centroid` sub-aggregation).
- `geo` semantic role for field mappings, enabling automatic geo point detection across all engines.
- Batch-resolved roles and responsive images for geo cluster sample hits.
- `/related` endpoint for "More Like This" document similarity search.
- `/stats` endpoint for index statistics (document count, engine name, existence check).
- OpenAPI spec updated with geo parameters, geoClusters schema, related and stats endpoints.

## [0.0.9] - 2026-02-20

### Added
- Biome linting/formatting configuration for TypeScript source.
- CI updated to Node.js 24.

### Changed
- Thumbnail transform uses smaller dimensions and improved quality settings.
- Image quality defaults updated for better Lighthouse scores.

## [0.0.8] - 2026-02-19

### Added
- WebP image transforms served from REST API for hit images and thumbnails.
- `ResponsiveImages` service with `srcset` generation for responsive image delivery.

## [0.0.7] - 2026-02-19

### Added
- `ResponsiveImages` service for automatic WebP transforms with `srcset` for hit images and thumbnails.
- Development docs updated for DDEV symlink approach.

### Changed
- Improved Sprig template accessibility.

## [0.0.6] - 2026-02-19

### Added
- `Index::getRoleFieldMap()` method to deduplicate role-field iteration across callers.
- `EngineRegistry::reset()` for clearing static engine cache in tests and long-lived workers.
- `autoHistogram` opt-out parameter for `searchContext()` to skip the silent second engine call.

### Changed
- `SearchResult::$processingTimeMs` is now `?int` (null when the engine doesn't report timing) instead of defaulting to 0.
- REST controller gates raw engine response behind `devMode`.
- REST controller clamps `perPage` to 1–250.
- `cpSearch()` now uses centralised `resolveEmbeddingOptions()` for vector/hybrid search modes.
- Updated Sprig docs code examples from `<h3>` to `<h2>` for correct heading hierarchy.

### Fixed
- WCAG 2.1 AA accessibility violations in built-in Sprig templates: colour contrast (gray-400/500 to gray-600), unique `aria-label` attributes on code examples, `tabindex="0"` on scrollable regions, correct `aria-live` placement on stable wrapper elements.

## [0.0.5] - 2026-02-19

### Fixed
- 25 issues from code review across engines, services, templates, and configuration.
- Documentation refresh across API and UI integration guides.

## [0.0.4] - 2026-02-18

### Added
- CORS support for public REST API endpoints via Yii `Cors` filter on `ApiController`.
- `SEARCH_INDEX_API_CORS_ORIGINS` environment variable for configuring allowed origins in non-dev environments.
- Default dev-mode behavior allows all origins (`*`) to simplify local Swagger/UI testing.

## [0.0.3] - 2026-02-18

### Added
- Public REST API endpoints under `/search-index/api/*`:
  - `/search`
  - `/autocomplete`
  - `/facet-values`
  - `/meta`
  - `/document`
  - `/multi-search`
- OpenAPI 3.0 specification for REST endpoints (`docs/openapi/search-index-api.yaml`).
- Swagger UI page in MkDocs (`docs/api-rest.md`).
- Unit test coverage for the new API controller routes and response behavior.

### Changed
- Bumped package version to `0.0.3`.

## [0.0.2] - 2026-02-18

### Changed
- Switched plugin version reporting to use declared/tagged versions (removed init-time version hack).
- Updated Control Panel version display to show declared package version.

### Fixed
- Guarded `App::parseEnv()` callsites against `null` values.
- Fixed `getEffective()` null-handling edge case.
- Fixed lightswitch toggle and test-connection behavior when fields are disabled.

## [0.0.1] - 2026-02-18

### Added
- Initial public release of Search Index for Craft CMS 5.
- Multi-engine support: Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense.
- Control Panel index management, field mapping, index structure view, validation, and search tooling.
- Twig API with search, autocomplete, facet value search, multi-search, and document retrieval helpers.
- GraphQL support for search queries, autocomplete, facet value search, and metadata queries.
- Search Document custom field and value object helpers for role-aware rendering.
- Read-only index mode with schema introspection and role mapping.
- Queue-based import/refresh/flush workflows with atomic swap support.
- Real-time sync hooks for element save/delete lifecycle events.
- Vector search and embedding support (Voyage AI), including hybrid search options.
- Highlighting, suggestions, facets, filters, sorting, stats, and histogram aggregation support.
- CLI tooling for import, refresh, validation, schema/debug inspection, and multi-search debugging.
- MkDocs documentation site, CI workflow, and project/testbed integration guidance.

### Fixed
- Engine normalization and compatibility fixes across Algolia, Elasticsearch, OpenSearch, Meilisearch, and Typesense.
- SearchDocumentField UX and accessibility issues in CP integrations.
- Facet handling, range filtering, and histogram stability improvements.
- Security hardening and error-handling consistency across controllers and services.
