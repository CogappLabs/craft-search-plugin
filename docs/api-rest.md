# REST API

The plugin exposes a public read-only REST API under `/search-index/api/*`.

<swagger-ui src="./openapi/search-index-api.yaml" />

## Endpoints

| Endpoint | Description |
|---|---|
| `/meta` | Index metadata (roles, facet fields, sort options) |
| `/search` | Full-text search with facets, filters, sorting, highlighting, geo, and more |
| `/autocomplete` | Lightweight prefix search for type-ahead UIs |
| `/facet-values` | Search/filter values for a single facet field |
| `/document` | Get a single document by ID |
| `/multi-search` | Batch multiple searches in one request (full `/search` param parity) |
| `/related` | Find documents related to a source document (More Like This) |
| `/stats` | Index statistics (document count, engine name, existence) |

## Caching

All API responses are cached server-side using Yii's `TagDependency` with indefinite TTL. Responses are served from Craft's application cache until explicitly invalidated — the search engine is only queried on the first request for a given set of parameters.

### Invalidation

The cache is automatically cleared when:

- An **entry is saved or deleted** (via the Sync service)
- An **atomic swap** completes after a full reindex
- **Project config changes** are applied (field mapping updates, index config changes)
- A user clears the **"Search Index data and API response caches"** option in Craft's CP Utilities → Clear Caches
- The `craft clear-caches/all` CLI command runs (e.g. in deploy scripts)

### HTTP cache headers

| Endpoint | `Cache-Control` | Purpose |
|---|---|---|
| `/meta` | `public, max-age=300` | Browser caches for 5 min (schema rarely changes) |
| `/stats` | `public, max-age=60` | Browser caches for 1 min |
| All others | _(none set by plugin)_ | CDN/edge proxy provides its own headers |

The plugin only sets `max-age` (browser-level caching). CDN-level caching (`s-maxage`) is left to the hosting platform to avoid duplicate directives.

### Observability

Every API response includes an `X-Search-Cache` header:

- `HIT` — served from the server-side application cache (no search engine query)
- `MISS` — cache was empty or invalidated; the search engine was queried

## Notes

- All endpoints are `GET`.
- Errors use `{ "error": "..." }` with appropriate HTTP status codes.
- JSON parameters (`filters`, `sort`, `histogram`, `geoGrid`, `searches`) must be URL-encoded.
- `/multi-search` accepts a JSON array of search definitions. Each definition supports all the same parameters as `/search` (`query`, `page`, `perPage`, `facets`, `maxValuesPerFacet`, `filters`, `sort`, `fields`, `highlight`, `suggest`, `stats`, `histogram`, `geoFilter`, `geoSort`, `geoGrid`). Each search can target a different index. The response includes `stats`, `histograms`, `suggestions`, and `geoClusters` per result.
- `/autocomplete` caps `perPage` at 50 and returns only role-assigned fields for minimal payload.
- For cross-origin browser usage (Swagger "Try it out", separate frontend domains), configure `SEARCH_INDEX_API_CORS_ORIGINS` in production.
  - Example: `SEARCH_INDEX_API_CORS_ORIGINS=https://cogapplabs.github.io,https://your-app.example`
