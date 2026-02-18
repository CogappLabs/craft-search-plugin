# REST API

The plugin exposes a public read-only REST API under `/search-index/api/*`.

<swagger-ui src="./openapi/search-index-api.yaml" />

## Notes

- All endpoints are `GET`.
- Errors use `{ "error": "..." }` with appropriate HTTP status codes.
- JSON parameters (`filters`, `sort`, `histogram`, `searches`) must be URL-encoded.
