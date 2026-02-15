# Search Index Sprig Starter Templates

These templates provide a fully customizable frontend search experience powered by Sprig. Unlike the plugin's built-in pre-styled components, **these stubs contain real HTML markup** that you can edit, rearrange, and style with your own CSS framework (Tailwind, Bootstrap, etc.).

## Included files

- `search-page.twig` — example page that loads the search component with initial state.
- `components/search.twig` — layout component: calls `searchContext()` and includes the partials below.
- `components/search-form.twig` — search input, per-page, and sort controls.
- `components/search-filters.twig` — active filter badges with "Clear all" button.
- `components/search-results.twig` — result cards with role-based field resolution.
- `components/search-facets.twig` — checkbox facet groups.
- `components/search-pagination.twig` — windowed page navigation.

## Quick start

1. Publish templates into your project:
   ```
   php craft search-index/index/publish-sprig-templates
   ```
2. Edit `search-index/sprig/search-page.twig` — set `indexHandle` to your index.
3. Style the individual component files in `search-index/sprig/components/` with your own classes and layout.

## How it works

The main `search.twig` calls `craft.searchIndex.searchContext(indexHandle, options)` which returns everything needed to render:

- `roles` — map of semantic role names to index field names (e.g. `{ title: 'title', image: 'heroImage' }`)
- `facetFields` — auto-detected facet field names from your index mappings
- `sortOptions` — sortable fields with labels, for building sort dropdowns
- `data` — search results (hits, pagination, facets) when `doSearch` is truthy

It then includes each partial, passing a `shared` context object. Edit each partial's markup independently — rearrange the layout in `search.twig`.

## State variables

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `indexHandle` | string | `''` | Search index handle (required) |
| `query` | string | `''` | Search query |
| `page` | int | `1` | Current page |
| `perPage` | int | `10` | Results per page |
| `sortField` | string | `''` | Sort field name (empty = relevance) |
| `sortDirection` | string | `'desc'` | Sort direction (`asc` or `desc`) |
| `filters` | object | `{}` | Active filters (`{ field: [values] }`) |
| `doSearch` | bool | `0` | Whether to execute the search |
| `autoSearch` | bool | `0` | Auto-search on keyup with debounce |
| `hideSubmit` | bool | `0` | Hide the submit button |

## Pre-styled components

The plugin also ships with pre-styled internal components for quick prototyping. These remain available via:

```twig
{{ searchIndexSprig('frontend.search-box', state) }}
```

The pre-styled version is used by the plugin's demo page and cannot be customized.
