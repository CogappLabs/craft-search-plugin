# React UI Package

The `@cogapp/craft-search-ui` package provides headless React components and hooks for building search UIs against the plugin REST API.

## Package location

The package lives in the companion testbed repository:

- `craft-search-plugin-testbed/packages/craft-search-ui`

## API endpoint contract

Use the Search Index REST base endpoint:

- `/search-index/api`

The package consumes:

- `GET /search-index/api/meta`
- `GET /search-index/api/search`
- `GET /search-index/api/facet-values`
- `GET /search-index/api/autocomplete`

## Key concepts

- `SearchProvider` centralizes query, filters, pagination, URL sync, and debounced requests.
- Widgets (`SearchBox`, `Hits`, `RefinementList`, `Pagination`, etc.) are unstyled and controlled via `classNames` props.
- Hooks (`useSearch`, `useMeta`, `useFacetSearch`, `useAutocomplete`) can be used directly for custom UIs.

## Where to read usage docs

- UI package README in the testbed repo:
  - `craft-search-plugin-testbed/packages/craft-search-ui/README.md`
- REST API reference in this docs site:
  - [REST API](api-rest.md)
