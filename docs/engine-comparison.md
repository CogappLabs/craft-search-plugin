# Engine Comparison

Search Index supports five search engines. Each has different strengths, pricing models, and feature sets. This page compares what each engine supports through the plugin's unified API, and notes where the plugin applies workarounds or where native limitations exist.

## Quick Summary

| | Algolia | Elasticsearch | OpenSearch | Meilisearch | Typesense |
|---|:---:|:---:|:---:|:---:|:---:|
| **Hosting** | Cloud (SaaS) | Self-hosted or cloud | Self-hosted or cloud | Self-hosted or cloud | Self-hosted or cloud |
| **Schema** | Dynamic | Mapping-based | Mapping-based | Attribute-based | Strict typed |
| **Best for** | Managed search with minimal ops | Full-featured self-hosted search | AWS-native ES alternative | Simple, fast setup | Type-safe with good defaults |

## Search Features

| Feature | Algolia | Elasticsearch | OpenSearch | Meilisearch | Typesense |
|---|:---:|:---:|:---:|:---:|:---:|
| Full-text search | ✅ | ✅ | ✅ | ✅ | ✅ |
| Faceted search | ✅ | ✅ | ✅ | ✅ | ✅ |
| Facet value search | ✅ Native | ✅ Server-side | ✅ Server-side | ✅ Native | ✅ Native |
| Equality filters | ✅ | ✅ | ✅ | ✅ | ✅ |
| Multi-value filters | ✅ | ✅ | ✅ | ✅ | ✅ |
| Range filters | ✅ | ✅ | ✅ | ✅ | ✅ |
| Sorting | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pagination | ✅ | ✅ | ✅ | ✅ | ✅ |
| Highlighting | ✅ | ✅ | ✅ | ✅ | ✅ |
| Suggestions | ❌ | ✅ | ✅ | ❌ | ❌ |
| Vector / KNN search | ❌ | ✅ | ✅ | ❌ | ❌ |
| Autocomplete | ✅ | ✅ | ✅ | ✅ | ✅ |
| Multi-search | ✅ | ✅ | ✅ | ✅ | ✅ |
| Stats (min/max) | ❌ | ✅ | ✅ | ✅ | ✅ |
| Histograms | ❌ | ✅ | ✅ | ❌ | ✅ |

### Notes on search features

**Facet value search** — Algolia has a native `searchForFacetValues` API. Meilisearch has native facet search. Typesense uses its `facet_query` parameter (word-level prefix only — the plugin falls back to substring matching client-side when needed). Elasticsearch and OpenSearch don't have a dedicated facet search API, but the plugin uses the `include` regex parameter on terms aggregations to filter facet values server-side. This is case-insensitive and works correctly even with high-cardinality facets.

**Suggestions / did-you-mean** — Only Elasticsearch and OpenSearch support phrase suggestions via their built-in `suggest` API. The plugin normalises these into a `suggestions` array on the search result. Algolia, Meilisearch, and Typesense handle misspellings via built-in typo tolerance instead — the `suggestions` array is always empty for these engines.

**Highlighting** — All engines support search result highlighting, opt-in via the `highlight: true` option. Highlights are normalised to a `_highlights` object on each hit in `{field: [fragments]}` format. Each engine's native highlight markers are preserved as-is (e.g. `<em>` for ES/OpenSearch, `<mark>` for Meilisearch).

**Vector search** — Only Elasticsearch and OpenSearch support embedding-based KNN search. The plugin integrates with Voyage AI for generating embeddings automatically. The embedding field type maps to `dense_vector` on Elasticsearch and `knn_vector` on OpenSearch — the plugin handles this difference automatically. When both a text query and an embedding are provided, the plugin builds a hybrid search combining both signals in a `bool/should` query (not pick-one). Vector-only and text-only modes are also supported.

**Autocomplete** — All engines support autocomplete via the `craft.searchIndex.autocomplete()` helper. This is a lightweight wrapper that returns 5 results with only role-assigned fields (title, url, image) for minimal payload. It is not a separate index or completion suggester — it uses the same full-text search API with reduced output. Per-engine behaviour: Algolia uses prefix search, Meilisearch uses typo-tolerant prefix matching, Typesense uses word-level prefix matching, and Elasticsearch/OpenSearch use standard full-text search.

**Processing time** — All engines return search timing normalised as `processingTimeMs` on the `SearchResult` object. Sources: Algolia `processingTimeMS`, Meilisearch `processingTimeMs`, Elasticsearch/OpenSearch `took`, Typesense `search_time_ms`.

**Stats** — Elasticsearch and OpenSearch use native `stats` aggregations. Meilisearch returns `facetStats` (requires the relevant fields to be listed in the `facets` option). Typesense returns min/max via `facet_counts`. Algolia doesn't support server-side stats aggregations.

**Histograms** — Elasticsearch and OpenSearch use native `histogram` aggregations with configurable intervals and extended bounds. Typesense achieves the same result using range facet syntax (the plugin pre-computes bucket ranges and generates Typesense's `field(label:[min,max])` syntax). Algolia and Meilisearch don't support histogram aggregations.

## Index Management

| Feature | Algolia | Elasticsearch | OpenSearch | Meilisearch | Typesense |
|---|:---:|:---:|:---:|:---:|:---:|
| Create / delete index | ✅ | ✅ | ✅ | ✅ | ✅ |
| Bulk upsert | ✅ | ✅ | ✅ | ✅ | ✅ |
| Atomic swap | ✅ | ✅ | ✅ | ✅ | ✅ |
| Read-only indexes | ✅ | ✅ | ✅ | ✅ | ✅ |
| Schema introspection | ✅ | ✅ | ✅ | ✅ | ✅ |

### Atomic swap details

Zero-downtime reindexing is supported on all engines, but the implementation varies:

- **Algolia** — Uses the native `operationIndex` (move/copy) API to atomically replace one index with another.
- **Elasticsearch / OpenSearch** — Alias-based swap. The plugin creates alternating concrete indexes (`_swap_a` / `_swap_b`) behind an alias. The production alias is atomically switched to point to the new index.
- **Meilisearch** — Uses the native `swapIndexes` API, then deletes the temporary index.
- **Typesense** — Alias-based swap, same pattern as Elasticsearch/OpenSearch with alternating `_swap_a` / `_swap_b` collections behind an alias.

### Read-only mode details

All engines support connecting to externally-managed indexes in read-only mode. The plugin handles permission limitations gracefully:

- **Algolia** — Works with a Search API Key (no admin key needed). Falls back to a search query when `getObject` is not available (single-document retrieval uses search with `objectID` filter).
- **Elasticsearch / OpenSearch** — Handles `403 Forbidden` errors gracefully. Falls back to `_count` API when `indices:admin/exists` is blocked. Falls back to document sampling when the mapping API is blocked (for schema introspection). `testConnection()` and `indexExists()` both handle 403 without throwing.
- **Meilisearch** — Schema inference from settings and sample documents.
- **Typesense** — Collection schema retrieval works with read-only API keys.

## Field Types

| Type | Algolia | Elasticsearch | OpenSearch | Meilisearch | Typesense |
|---|---|---|---|---|---|
| **Text** | `searchableAttributes` | `text` + `.keyword` sub-field | `text` + `.keyword` sub-field | `searchableAttributes` | `string` |
| **Keyword** | `attributesForFaceting` | `keyword` | `keyword` | `filterableAttributes` | `string` (facetable) |
| **Integer** | `numericAttributesForFiltering` | `integer` | `integer` | filterable + sortable | `int32` / `int64` |
| **Float** | `numericAttributesForFiltering` | `float` | `float` | filterable + sortable | `float` |
| **Boolean** | `attributesForFaceting` (filterOnly) | `boolean` | `boolean` | `filterableAttributes` | `bool` |
| **Date** | numeric (epoch seconds) | `date` (ISO 8601) | `date` (ISO 8601) | numeric (epoch seconds) | `int64` (epoch seconds) |
| **Geo Point** | `_geoloc` (special field) | `geo_point` | `geo_point` | filterable + sortable | `geopoint` |
| **Facet** | `attributesForFaceting` (searchable) | `keyword` | `keyword` | `filterableAttributes` | `string[]` (facetable) |
| **Object** | `searchableAttributes` | `object` | `object` | `searchableAttributes` | `object` |
| **Embedding** | ❌ Not supported | `dense_vector` | `knn_vector` | ❌ Not supported | ❌ Not supported |

### Geo search

All engines support the `geo_point` field type for storing geographic coordinates. The expected format is `{lat, lng}`. Engine-native field names are:

| Engine | Native type | Notes |
|---|---|---|
| **Algolia** | `_geoloc` | Special reserved field name with `{lat, lng}` format |
| **Elasticsearch** | `geo_point` | Standard mapping type |
| **OpenSearch** | `geo_point` | Standard mapping type |
| **Meilisearch** | `_geo` | Registered as filterable + sortable |
| **Typesense** | `geopoint` | Typesense-specific type |

!!! note
    Geo filtering and sorting (e.g. "within radius" or "sort by distance") are not yet exposed in the plugin's unified API. To use geo features, pass engine-native options directly in the `options` array.

### Date handling

Dates are stored differently depending on the engine. The plugin normalises dates to the appropriate format automatically during indexing — millisecond timestamps are auto-detected and converted:

- **Elasticsearch / OpenSearch** — Stored as ISO 8601 strings with flexible parsing: `epoch_second||epoch_millis||strict_date_optional_time`.
- **Algolia / Meilisearch / Typesense** — Stored as Unix epoch seconds (integers).

### Text field sorting

Elasticsearch and OpenSearch text fields cannot be sorted directly — they require a `.keyword` sub-field. The plugin automatically appends `.keyword` when sorting or filtering on text fields in these engines. This is transparent — you use the same field name in the `sort` option regardless of engine.

## Filter Implementation

The unified filter syntax (`filters` option) is translated to each engine's native format:

| Engine | Equality | Multi-value | Range |
|---|---|---|---|
| **Algolia** | `facetFilters` | `facetFilters` (OR within field) | `numericFilters` (separate param) |
| **Elasticsearch** | `term` query in `bool/filter` | `terms` query in `bool/filter` | `range` query in `bool/filter` |
| **OpenSearch** | `term` query in `bool/filter` | `terms` query in `bool/filter` | `range` query in `bool/filter` |
| **Meilisearch** | `field = "value"` string syntax | `field = "a" OR field = "b"` | `field >= min AND field <= max` |
| **Typesense** | `` field:=`value` `` string syntax | `` field:=[`a`,`b`] `` | `field:>=min && field:<=max` |

!!! note
    Algolia separates range filters (`numericFilters`) from equality filters (`facetFilters`) at the API level. The plugin handles this split automatically.

### Unified vs engine-native parameters

The plugin translates unified search options (`page`, `perPage`, `sort`, `filters`, `facets`, `highlight`, etc.) to each engine's native format automatically. If you also pass engine-native parameters in the same options array (e.g. `from`/`size` for Elasticsearch, `hitsPerPage` for Algolia), the engine-native parameters take precedence. This lets you use the unified API for most queries while dropping down to native options when needed.

## Choosing an Engine

### Algolia

Best for teams that want a fully managed search service with no infrastructure to maintain. Algolia's cloud-hosted model means zero ops overhead, and it has excellent typo tolerance and relevance tuning out of the box.

**Limitations:** No vector search, no suggestions, no stats/histogram aggregations. Pay-per-search pricing can become expensive at scale.

### Elasticsearch

The most feature-complete option. Supports everything: vector search, suggestions, stats, histograms, and complex aggregations. A strong choice when you need advanced search features or already run an Elasticsearch cluster.

**Limitations:** Requires infrastructure management (or a managed service like Elastic Cloud). More complex to configure and operate than simpler engines.

### OpenSearch

Functionally equivalent to Elasticsearch for this plugin's purposes. A good choice if you're on AWS (OpenSearch Service) or prefer the open-source fork.

**Limitations:** Same operational complexity as Elasticsearch. Uses `knn_vector` instead of `dense_vector` for embeddings (the plugin handles this automatically).

### Meilisearch

A lightweight, fast engine that's easy to set up and run. Excellent typo tolerance, instant search, and simple configuration. Good for small-to-medium datasets where you want quick results without complex infrastructure.

**Limitations:** No vector search, no suggestions, no histogram aggregations. Settings updates are asynchronous (task-based) — the plugin handles task polling where needed. The plugin always sets sort-first ranking rules for consistent sorted results.

### Typesense

A fast, typo-tolerant engine with a strict schema model. Good default relevance, built-in faceting, and lightweight resource usage. The plugin auto-derives a `has_image` boolean field when a ROLE_IMAGE mapping exists, making it available as a facetable filter.

**Limitations:** No vector search, no suggestions. Strict schema means all fields must be defined upfront (the plugin handles this automatically). Facet value search only supports word-level prefix matching natively (the plugin supplements with client-side substring filtering).
