# How It Works

## Project Config Storage

Index definitions and their field mappings are stored in Craft's **Project Config** (`config/project/searchIndex/`). The database tables (`searchindex_indexes`, `searchindex_field_mappings`) serve as a runtime cache and are rebuilt from project config during `project-config/apply`. This means index configuration is version-controlled and portable across environments.

## Real-time Sync

When `syncOnSave` is enabled, the plugin listens to element lifecycle events:

- **`EVENT_AFTER_SAVE_ELEMENT`** -- If the saved entry is live, an `IndexElementJob` is queued. If the entry is disabled, expired, or future-dated, a `DeindexElementJob` removes it from the index.
- **`EVENT_AFTER_RESTORE_ELEMENT`** -- Restored entries are re-indexed.
- **`EVENT_BEFORE_DELETE_ELEMENT`** -- Deleted entries are removed from all matching indexes.
- **`EVENT_AFTER_UPDATE_SLUG_AND_URI`** -- Entries with changed URIs are re-indexed.

When `indexRelations` is enabled, saving or deleting any element triggers a relation cascade: all entries related to the changed element are found and re-indexed. This ensures that, for example, renaming a category updates every entry that references it. Duplicate queue jobs within the same request are automatically deduplicated.

## Bulk Import and Orphan Cleanup

The `import` and `refresh` console commands queue `BulkIndexJob` instances in batches (controlled by `batchSize`). After all bulk jobs are queued, a `CleanupOrphansJob` is appended to remove stale documents from the engine that no longer correspond to live entries. The cleanup job uses the engine's `getAllDocumentIds()` method to compare engine state against the current Craft entries.

## Atomic Swap (Zero-Downtime Refresh)

When running `php craft search-index/index/refresh`, engines that support atomic swap perform a zero-downtime reindex:

1. A temporary index (`{handle}_swap`) is created.
2. All documents are bulk-imported into the temporary index.
3. The temporary and production indexes are swapped atomically.
4. The old temporary index is deleted.

During the swap, the production index remains fully searchable. There is no window where queries return empty results.

| Engine        | Atomic swap | Method                        |
|---------------|-------------|-------------------------------|
| Meilisearch   | Yes         | Native `swapIndexes()` API    |
| Elasticsearch | No          | Standard flush + re-import    |
| OpenSearch    | No          | Standard flush + re-import    |
| Algolia       | No          | Standard flush + re-import    |
| Typesense     | No          | Standard flush + re-import    |

Engines without atomic swap support fall back to the standard refresh behavior (delete index, recreate, re-import). Custom engines can opt in by implementing `supportsAtomicSwap()` and `swapIndex()` on the engine interface.
