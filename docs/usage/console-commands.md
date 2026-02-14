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

# Debug how a specific entry resolves field mappings
php craft search-index/index/debug-entry myIndexHandle "entry-slug"
php craft search-index/index/debug-entry myIndexHandle "entry-slug" "fieldName"
```

## Validate

The `validate` command tests field resolution against real entries for each enabled field mapping -- the same logic used by the CP's **Validate Fields** button. For each field, it finds an entry with data, resolves it through the field mapper, and reports the resolved value, PHP type, and any type mismatches.

| Option     | Values              | Default    | Description                                 |
|------------|---------------------|------------|---------------------------------------------|
| `--format` | `markdown`, `json`  | `markdown` | Output format.                              |
| `--only`   | `all`, `issues`     | `all`      | Filter: `issues` shows only warnings/errors/nulls. |

## Debug Search

The `debug-search` command executes a search query and outputs both the normalised `SearchResult` and the raw engine response as JSON. Useful for diagnosing search relevance, verifying field configuration, and comparing engine behaviour.

## Debug Entry

The `debug-entry` command shows how a specific entry resolves each enabled field mapping. For each mapping, it displays the parent field, sub-field, resolver class, and resolved value. Useful for diagnosing why a particular entry's data isn't indexing as expected. Optionally pass a field name to inspect a single mapping.

## Running the queue

After `import` or `refresh`, run the queue to process the jobs:

```bash
php craft queue/run
```
