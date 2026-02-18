# Changelog

All notable changes to the Search Index plugin for Craft CMS are documented in this file.

## [Unreleased]

### Changed
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
