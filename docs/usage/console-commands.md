# Console Commands

All console commands accept an optional `handle` argument to target a specific index. When omitted, the command operates on all indexes.

```bash
# Show status of all indexes (connection, document count)
php craft search-index/index/status

# Full re-index: queues bulk import jobs for all entries
php craft search-index/index/import
php craft search-index/index/import myIndexHandle

# Flush and re-import (destructive refresh)
php craft search-index/index/refresh
php craft search-index/index/refresh myIndexHandle

# Clear all documents from an index
php craft search-index/index/flush
php craft search-index/index/flush myIndexHandle

# Re-detect field mappings (merge with existing settings)
php craft search-index/index/redetect
php craft search-index/index/redetect myIndexHandle

# Re-detect field mappings (fresh -- discard existing settings)
php craft search-index/index/redetect --fresh

# Validate field mappings against real entries
php craft search-index/index/validate
php craft search-index/index/validate myIndexHandle
php craft search-index/index/validate --format=json
php craft search-index/index/validate --only=issues

# Debug a search query (returns raw + normalised results as JSON)
php craft search-index/index/debug-search myIndexHandle "search query"
php craft search-index/index/debug-search myIndexHandle "search query" '{"perPage":10,"page":1}'

# Debug autocomplete results (lightweight, role-based field retrieval)
php craft search-index/index/debug-autocomplete myIndexHandle "search query"
php craft search-index/index/debug-autocomplete myIndexHandle "search query" '{"perPage":8}'

# Debug facet value search (search within facet values)
php craft search-index/index/debug-facet-search myIndexHandle
php craft search-index/index/debug-facet-search myIndexHandle "search term"
php craft search-index/index/debug-facet-search myIndexHandle "search term" '{"maxPerField":10,"facetFields":["region"]}'

# Debug how a specific entry resolves field mappings
php craft search-index/index/debug-entry myIndexHandle "entry-slug"
php craft search-index/index/debug-entry myIndexHandle "entry-slug" "fieldName"

# Fetch a raw document from the engine by ID
php craft search-index/index/get-document myIndexHandle 12345

# Show live engine schema (normalised fields + raw engine schema)
php craft search-index/index/debug-schema myIndexHandle
php craft search-index/index/debug-schema myIndexHandle --format=json

# Preview what schema would be built from current field mappings vs live schema
php craft search-index/index/preview-schema myIndexHandle
php craft search-index/index/preview-schema myIndexHandle --format=json

# Run multiple search queries in a single batch
php craft search-index/index/debug-multi-search '[{"handle":"indexA","query":"castle"},{"handle":"indexB","query":"london"}]'
php craft search-index/index/debug-multi-search '[{"handle":"indexA","query":"castle","options":{"perPage":5}}]'

# Publish starter frontend Sprig templates into project templates/search-index/sprig
php craft search-index/index/publish-sprig-templates

# Publish to a custom templates subpath
php craft search-index/index/publish-sprig-templates custom/search/starter

# Overwrite existing published files
php craft search-index/index/publish-sprig-templates --force=1
```

## Validate

The `validate` command tests field resolution against real entries for each enabled field mapping -- the same logic used by the CP's **Validate Fields** button. For each field, it finds an entry with data, resolves it through the field mapper, and reports the resolved value, PHP type, and any type mismatches.

| Option     | Values              | Default    | Description                                 |
|------------|---------------------|------------|---------------------------------------------|
| `--format` | `markdown`, `json`  | `markdown` | Output format.                              |
| `--only`   | `all`, `issues`     | `all`      | Filter: `issues` shows only warnings/errors/nulls. |

## Debug Search

The `debug-search` command executes a search query and outputs both the normalised `SearchResult` and the raw engine response as JSON. Useful for diagnosing search relevance, verifying field configuration, and comparing engine behaviour.

## Debug Autocomplete

The `debug-autocomplete` command executes an autocomplete search and displays the results with role-mapped fields (title, url, image) and relevance score. Uses the same lightweight `autocomplete()` method as the Twig variable — small result set with only role-mapped fields returned.

## Debug Facet Search

The `debug-facet-search` command searches within facet values using case-insensitive substring matching. Auto-detects all `TYPE_FACET` fields when `facetFields` is omitted. Useful for verifying that facet values are searchable and for testing the facet autocomplete experience. Pass an empty query to list all facet values.

## Debug Entry

The `debug-entry` command shows how a specific entry resolves each enabled field mapping. For each mapping, it displays the parent field, sub-field, block details (including which blocks contain the target field), and the resolved value. Useful for diagnosing why a particular entry's data isn't indexing as expected. Optionally pass a field name to inspect a single mapping.

## Get Document

The `get-document` command fetches and displays a single raw document from the engine by its document ID (`objectID`). Outputs the document as JSON. Returns an error if the document is not found.

## Debug Schema

The `debug-schema` command shows the live schema for an index as it exists in the engine. Default output includes a normalised field table (name and type) plus the raw engine-native schema as JSON. Use `--format=json` for a single JSON payload containing both. Works on both synced and read-only indexes.

| Option     | Values              | Default    | Description     |
|------------|---------------------|------------|-----------------|
| `--format` | `markdown`, `json`  | `markdown` | Output format.  |

## Preview Schema

The `preview-schema` command shows what schema *would* be built from the current field mappings (via `buildSchema()`), alongside the live engine schema if the index exists. Flags whether the two differ -- useful for checking if a `refresh` is needed after changing field mappings.

Not available for read-only indexes (which have no field mappings) -- use `debug-schema` instead.

| Option     | Values              | Default    | Description     |
|------------|---------------------|------------|-----------------|
| `--format` | `markdown`, `json`  | `markdown` | Output format.  |

## Debug Multi-Search

The `debug-multi-search` command runs multiple search queries in a single batch. Input is a JSON array of query objects, each with `handle`, `query`, and optional `options`. Uses the same engine-grouped batching as `craft.searchIndex.multiSearch()` in Twig.

Output is a JSON array with each query echoed alongside its normalised result (hits, pagination, facets, suggestions).

!!! note
    Auto-embedding via `vectorSearch: true` is not supported in multi-search. Pass pre-computed `embedding` arrays in options if needed.

## Running the queue

After `import` or `refresh`, run the queue to process the jobs:

```bash
php craft queue/run
```
