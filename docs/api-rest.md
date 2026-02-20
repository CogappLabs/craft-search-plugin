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

## Notes

- All endpoints are `GET`.
- Errors use `{ "error": "..." }` with appropriate HTTP status codes.
- JSON parameters (`filters`, `sort`, `histogram`, `geoGrid`, `searches`) must be URL-encoded.
- `/multi-search` accepts a JSON array of search definitions. Each definition supports all the same parameters as `/search` (`query`, `page`, `perPage`, `facets`, `maxValuesPerFacet`, `filters`, `sort`, `fields`, `highlight`, `suggest`, `stats`, `histogram`, `geoFilter`, `geoSort`, `geoGrid`). Each search can target a different index. The response includes `stats`, `histograms`, `suggestions`, and `geoClusters` per result.
- `/autocomplete` caps `perPage` at 50 and returns only role-assigned fields for minimal payload.
- For cross-origin browser usage (Swagger "Try it out", separate frontend domains), configure `SEARCH_INDEX_API_CORS_ORIGINS` in production.
  - Example: `SEARCH_INDEX_API_CORS_ORIGINS=https://cogapplabs.github.io,https://your-app.example`
