# Read-Only Indexes

By default, indexes run in **Synced** mode -- the plugin pushes Craft content to the search engine automatically. **Read-only** mode is for externally managed indexes that you only want to query from Craft, not write to.

## Creating a read-only index

1. Go to **Search Index > Indexes > New Index**.
2. Fill in the name, handle, engine, and engine connection settings.
3. Set the **Mode** dropdown to **Read-only**.
4. Save.

Read-only indexes skip field detection on creation and redirect straight to the index listing.

## What changes in read-only mode

| Feature | Synced | Read-only |
|---------|--------|-----------|
| Real-time sync on entry save | Yes | No |
| Field Mappings tab | Yes | No |
| Sources (Sections / Entry Types / Site) | Configurable | Hidden |
| Console `import`, `flush`, `refresh`, `redetect`, `validate` | Processed | Skipped |
| Console `status` | Shown | Shown |
| Twig search (`craft.searchIndex.search`) | Yes | Yes |
| GraphQL `searchIndex` query | Yes | Yes |
| `getDocument`, `docCount`, `isReady` | Yes | Yes |

Console commands that skip a read-only index print a yellow notice:

```text
Skipping read-only index: My External Index (myExternalIndex)
```

## Querying a read-only index

Read-only indexes are queried exactly like synced indexes -- all Twig, GraphQL, and CP Search features work without any changes:

```twig
{% set results = craft.searchIndex.search('myExternalIndex', 'london', { perPage: 20 }) %}
```

## When to use read-only mode

- You manage the index outside of Craft (e.g. a shared corporate search cluster, a third-party data pipeline, or a pre-built product catalog).
- You want to query an existing index from Twig or GraphQL without the plugin attempting to sync Craft entries into it.
- Multiple applications share the same index and Craft should only read from it.
