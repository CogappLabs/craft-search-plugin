# Control Panel

The plugin adds a **Search Index** section to the control panel with the following sub-navigation items.

## Indexes

Create, edit, delete, and manage search indexes. Each index is configured with:

- A **name** and **handle** (the handle becomes the index name in the search engine).
- An **engine** (Elasticsearch, Algolia, OpenSearch, Meilisearch, or Typesense).
- Per-engine **configuration** (e.g. index prefix), with environment variable support.
- **Sections** and/or **entry types** to include.
- An optional **site** restriction.
- An **enabled/disabled** toggle.

## Field Mappings

After creating an index, configure which fields are indexed. The field mapping UI provides:

- An auto-detected list of fields based on the selected entry types.
- Per-field **enable/disable** toggle.
- Customizable **index field name** (the key stored in the search engine document).
- **Field type** selection (text, keyword, integer, float, boolean, date, geo_point, facet, object).
- **Weight** control (1--10) for search relevance boosting.
- Matrix fields expand into individual sub-field rows for granular control.
- **Validate Fields** -- Tests field resolution against real entries without modifying the index. For each enabled field, finds an entry with data (deep sub-field lookup for Matrix blocks) and reports the resolved value, PHP type, and any type mismatches. Results can be copied as Markdown.
- **Semantic roles** -- Assign a role (title, image, summary, URL) to key fields. Roles power the `SearchDocumentValue` helper methods and are enforced one-per-index.
- **Re-detect Fields** -- Regenerates field mappings from the current entry type field layouts. A "fresh" re-detect discards existing settings; a normal re-detect preserves user customizations while refreshing field UIDs.

## Search

A built-in CP page for testing searches across indexes:

- **Single mode** -- Search one index and view results with title, URI, score, and raw document data.
- **Compare mode** -- Search multiple indexes simultaneously with side-by-side results.

## Settings

Global plugin settings for engine credentials, sync behavior, and batch size.
