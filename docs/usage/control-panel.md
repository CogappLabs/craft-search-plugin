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
- **Semantic roles** -- Assign a role (title, image, thumbnail, summary, URL, date, IIIF) to key fields. Roles power the `SearchDocumentValue` helper methods and are enforced one-per-index.
- **Role behavior** -- Role-assigned fields are auto-enabled. During (re)detect, duplicate roles are de-duped and for Craft entries the `date` role prioritises `postDate`.
- **Re-detect Fields** -- Regenerates field mappings from the current entry type field layouts. A "fresh" re-detect discards existing settings; a normal re-detect preserves user customizations while refreshing field UIDs.

Source of truth for roles:

```php
--8<-- "src/models/FieldMapping.php:field-mapping-roles"
```

## Search

A built-in CP page for testing searches across indexes:

- **Single mode** -- Auto-runs on page load (default results), then auto-searches with debounce while typing and when changing index/per-page/mode controls.
- **Compare mode** -- Auto-searches with debounce while typing and when changing selected indexes/per-page.
- **Loading states** -- Search actions and validation/test actions include Sprig loading indicators (`s-indicator` + `htmx-indicator`) for clearer request feedback.

## Sprig Component Architecture (CP)

The CP UI now uses class-based Sprig components (via `putyourlightson/sprig-core`) for server-side state and rendering:

- `cogapp\searchindex\sprig\components\TestConnection`
- `cogapp\searchindex\sprig\components\ValidationResults`
- `cogapp\searchindex\sprig\components\IndexHealth`
- `cogapp\searchindex\sprig\components\IndexStructure`
- `cogapp\searchindex\sprig\components\SearchSingle`
- `cogapp\searchindex\sprig\components\SearchCompare`

Templates invoke these through the plugin Twig helper aliases, e.g. `searchIndexSprig('cp.search-single', {...})`, rather than long class strings.

## Settings

Global plugin settings for engine credentials, sync behavior, and batch size.
