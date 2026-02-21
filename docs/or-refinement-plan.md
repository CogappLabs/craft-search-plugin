# OR Refinement (Disjunctive Faceting)

## Status: Implemented

Initial implementation complete. The `RefinementList` component accepts an `operator` prop to enable OR (disjunctive) faceting per field.

## Problem

When a user selects a value in a facet (e.g. "England" in Country), other values in the same facet disappear. This makes multi-select within a single facet group useless — you can't build a query like "England OR Scotland".

## Root Cause

The backend already handles OR correctly — all five engines translate `["England", "Scotland"]` into OR queries, and multi-value filter arrays are AND-ed across fields. The issue was entirely in the UI layer:

1. The main search response returns **conjunctive** facet counts (filtered by all active filters including the current field)
2. `RefinementList` uses these main response facets by default
3. After selecting "England", the main response only contains England results, so the facet list shrinks to just "England"
4. The `useFacetSearch` hook already had disjunctive logic (`delete otherFilters[facetField]`), but it only fired when typing in the facet search box or clicking "Show More"

## Solution

A UI-only change — no backend/plugin/API changes needed.

### `useFacetSearch` hook

Added `disjunctive` option that bypasses the early return, causing the hook to fire the `/facet-values` API call (with the current field excluded from filters) even without a text query or expansion.

### `RefinementList` component

Added `operator?: 'or' | 'and'` prop (default: `'and'`).

When `operator="or"` and the facet has active selections:
- `useFacetSearch` fires with `disjunctive: true`
- The component prefers the disjunctive server values over the main response facets
- All facet values remain visible with accurate counts

### Usage

```tsx
<RefinementList attribute="region" label="Region" operator="or" />
<RefinementList attribute="country" label="Country" />  {/* default AND */}
```

## Query Semantics

- Within one facet field (when `operator="or"`): **OR** — selecting England and Scotland shows results from either
- Across different facet fields: **AND** — selecting Region=London and Country=England narrows to results matching both
- Default behavior (`operator="and"` or omitted): unchanged — facet values narrow to match current results

## Backend (already correct)

All engines translate `filters: { "country": ["England", "Scotland"] }` as OR:

- **Elasticsearch/OpenSearch:** `{ "terms": { "country.keyword": ["England", "Scotland"] } }`
- **Meilisearch:** `(country = "England" OR country = "Scotland")`
- **Algolia:** `[["country:England", "country:Scotland"]]`
- **Typesense:** `` country:=[`England`,`Scotland`] ``

Different fields are AND-ed together at the top level.

## Trade-offs

- Each OR-mode facet with active selections makes one extra `/facet-values` API call (same lightweight call used by facet text search)
- No `filterOperators` plumbing needed in the API or URL — the operator is a UI-level configuration choice, not user state
- The `and` operator within a facet is the default behavior and works for the common case where facet values narrow as you filter

## Files Changed

- `packages/craft-search-ui/src/hooks/useFacetSearch.ts` — added `disjunctive` option
- `packages/craft-search-ui/src/components/RefinementList.tsx` — added `operator` prop
- `src/js/components/SearchPage.tsx` — applied `operator="or"` to `placeRegion` facet
