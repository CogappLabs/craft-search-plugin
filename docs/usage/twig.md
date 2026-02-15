# Twig

The plugin registers a `craft.searchIndex` Twig variable with the following methods.

## `craft.searchIndex.search(handle, query, options)`

Search an index and return a normalised `SearchResult` object. Results have the same shape regardless of which engine backs the index.

```twig
{% set results = craft.searchIndex.search('places', 'london', { perPage: 20, fields: ['title','summary'] }) %}

{% for hit in results.hits %}
    <p>{{ hit.title }} (score: {{ hit._score }})</p>
{% endfor %}

<p>Page {{ results.page }} of {{ results.totalPages }} ({{ results.totalHits }} total)</p>
```

### Search results page with pagination

```twig
{% set query = craft.app.request.getQueryParam('q') %}
{% set page = craft.app.request.getQueryParam('page')|default(1) %}
{% set results = craft.searchIndex.search('places', query, { perPage: 12, page: page }) %}

{% if results.totalHits > 0 %}
    <p>{{ results.totalHits }} results for "{{ query }}"</p>

    <div class="grid">
        {% for hit in results.hits %}
            <article class="card">
                <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
                {% if hit.summaryText is defined %}
                    <p>{{ hit.summaryText }}</p>
                {% endif %}
            </article>
        {% endfor %}
    </div>

    {# Pagination #}
    {% if results.totalPages > 1 %}
        <nav>
            {% for i in 1..results.totalPages %}
                {% if i == results.page %}
                    <span>{{ i }}</span>
                {% else %}
                    <a href="?q={{ query }}&page={{ i }}">{{ i }}</a>
                {% endif %}
            {% endfor %}
        </nav>
    {% endif %}
{% else %}
    <p>No results found for "{{ query }}".</p>
{% endif %}
```

### Pagination options

| Option    | Type  | Default | Description              |
|-----------|-------|---------|--------------------------|
| `page`    | `int` | `1`     | Page number (1-based).   |
| `perPage` | `int` | `20`    | Results per page.        |

Engine-native pagination keys (`from`/`size`, `offset`/`limit`, `hitsPerPage`, `per_page`) still work and take precedence if provided.

### Facets & filtering

Request facet counts for specific fields and apply facet filters -- works identically across all engines.

#### Requesting facet counts

```twig
{% set results = craft.searchIndex.search('articles', query, {
    facets: ['category', 'sectionHandle'],
    perPage: 12,
    page: page,
}) %}
```

The `results.facets` property returns a normalised structure, regardless of engine:

```
{
    category: [
        { value: 'News', count: 24 },
        { value: 'Blog', count: 18 },
        { value: 'Tutorial', count: 7 },
    ],
    sectionHandle: [
        { value: 'articles', count: 35 },
        { value: 'guides', count: 14 },
    ]
}
```

Values are sorted by count descending.

#### Building a facet sidebar with checkboxes

```twig
{% set query = craft.app.request.getQueryParam('q') %}
{% set page = craft.app.request.getQueryParam('page')|default(1) %}
{% set activeCategory = craft.app.request.getQueryParam('category') %}

{% set options = { facets: ['category'], perPage: 12, page: page } %}
{% if activeCategory %}
    {% set options = options|merge({ filters: { category: activeCategory } }) %}
{% endif %}

{% set results = craft.searchIndex.search('articles', query, options) %}

<form method="get">
    <input type="hidden" name="q" value="{{ query }}">

    {# Facet sidebar #}
    <aside>
        <h3>Category</h3>
        {% for facet in results.facets.category ?? [] %}
            <label>
                <input type="checkbox" name="category" value="{{ facet.value }}"
                    {{ activeCategory == facet.value ? 'checked' }}>
                {{ facet.value }} <span>({{ facet.count }})</span>
            </label>
        {% endfor %}
        <button type="submit">Filter</button>
    </aside>

    {# Results #}
    <div>
        <p>{{ results.totalHits }} results</p>
        {% for hit in results.hits %}
            <article>
                <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
            </article>
        {% endfor %}
    </div>
</form>
```

#### Filtering with multiple values (OR within a field)

Pass an array of values to match any of them:

```twig
{% set results = craft.searchIndex.search('articles', query, {
    facets: ['category'],
    filters: { category: ['News', 'Blog'] },
}) %}
```

#### Combining multiple filter fields (AND across fields)

```twig
{% set results = craft.searchIndex.search('articles', query, {
    facets: ['category', 'status'],
    filters: {
        category: 'News',
        sectionHandle: 'articles',
    },
}) %}
```

#### Facet options reference

| Option    | Type    | Description                                                                 |
|-----------|---------|-----------------------------------------------------------------------------|
| `facets`  | `array` | Field names to aggregate (e.g. `['category', 'status']`).                   |
| `filters` | `array` | Associative array of field to value or array of values to filter by.        |

Engine-native facet/filter keys (`facetFilters`, `aggs`, `filter`, `facet_by`, `filter_by`) still work and take precedence if provided.

### Sorting

Pass a unified `sort` option to control result ordering. The plugin translates this to each engine's native sort syntax automatically.

```twig
{# Sort by price ascending, then by title #}
{% set results = craft.searchIndex.search('products', query, {
    sort: { price: 'asc', title: 'asc' },
}) %}

{# Sort by date descending (newest first) #}
{% set results = craft.searchIndex.search('articles', query, {
    sort: { postDate: 'desc' },
}) %}
```

#### Sort options reference

| Option | Type    | Description                                                      |
|--------|---------|------------------------------------------------------------------|
| `sort` | `array` | Associative array of field name to direction (`'asc'` or `'desc'`). |

Engine-native sort keys (`sort_by` for Typesense, ES DSL arrays, Meilisearch `['field:dir']`) still work if passed directly, and take precedence over the unified format.

**Note:** Algolia does not support runtime sorting — sort order is determined by the index's ranking configuration. For Algolia, use replica indexes for alternative sort orders.

**Note:** Fields used for sorting must be declared as sortable in the engine schema. For Meilisearch, numeric and date fields are automatically sortable. For Typesense, numeric fields have `sort: true` by default.

### Restricting returned attributes

Pass `attributesToRetrieve` to limit which document fields are returned in the response. This reduces payload size and is useful for autocomplete or list views.

```twig
{# Only return title and uri — faster response, less data #}
{% set results = craft.searchIndex.search('articles', query, {
    attributesToRetrieve: ['objectID', 'title', 'uri'],
    perPage: 5,
}) %}
```

| Option                 | Type    | Description                                                    |
|------------------------|---------|----------------------------------------------------------------|
| `attributesToRetrieve` | `array` | Field names to include in hits. Omit to return all fields.     |

### Search field restriction

Pass a `fields` array in the options to limit which indexed fields are searched (engine support varies, but Elasticsearch/OpenSearch accept this).

### Highlighting

Every hit includes a normalised `_highlights` object mapping field names to arrays of highlighted fragments. The shape is identical across all engines:

```
{ fieldName: ['fragment with <em>match</em>', ...], ... }
```

#### Enabling highlights

Pass the unified `highlight` option to request highlighting:

```twig
{# Highlight all searchable fields #}
{% set results = craft.searchIndex.search('articles', query, {
    highlight: true,
    perPage: 10,
}) %}

{# Highlight specific fields only #}
{% set results = craft.searchIndex.search('articles', query, {
    highlight: ['title', 'body'],
    perPage: 10,
}) %}
```

| Option      | Type          | Description                                              |
|-------------|---------------|----------------------------------------------------------|
| `highlight` | `true\|array` | `true` for all fields, or array of field names to highlight. |

Engine-native highlight options (e.g. ES `highlight: { fields: { body: { fragment_size: 150 } } }`) still work and take precedence over the unified option.

**Note:** Algolia and Typesense return highlights by default (even without the `highlight` option). Meilisearch requires `highlight` to be set. Elasticsearch/OpenSearch require it for any highlight data to be returned.

#### Using highlights in templates

```twig
{% set results = craft.searchIndex.search('articles', query, { highlight: true }) %}

{% for hit in results.hits %}
    <article>
        {# Use highlighted title if available, fall back to plain title #}
        {% if hit._highlights.title is defined %}
            <h3>{{ hit._highlights.title|first|raw }}</h3>
        {% else %}
            <h3>{{ hit.title }}</h3>
        {% endif %}

        {# Show highlighted body snippets #}
        {% if hit._highlights.body is defined %}
            {% for fragment in hit._highlights.body %}
                <p class="snippet">...{{ fragment|raw }}...</p>
            {% endfor %}
        {% endif %}
    </article>
{% endfor %}
```

**Note:** Highlight fragments contain HTML tags (e.g. `<em>match</em>`), so use the `|raw` filter to render them.

### Suggestions ("Did you mean?")

For Elasticsearch and OpenSearch, pass `suggest: true` to request spelling suggestions. The engine will return alternative query strings in `results.suggestions` when the original query may contain typos.

```twig
{% set results = craft.searchIndex.search('articles', query, {
    suggest: true,
    highlight: true,
}) %}

{% if results.suggestions is not empty %}
    <p>Did you mean:
        {% for suggestion in results.suggestions %}
            <a href="?q={{ suggestion }}">{{ suggestion }}</a>{{ not loop.last ? ', ' }}
        {% endfor %}
        ?
    </p>
{% endif %}
```

| Option    | Type   | Description                                                    |
|-----------|--------|----------------------------------------------------------------|
| `suggest` | `bool` | Request spelling suggestions (ES/OpenSearch only). Default: `false`. |

**Note:** Algolia, Meilisearch, and Typesense handle typo tolerance automatically (built-in) and do not return separate suggestions. The `suggest` option only affects Elasticsearch and OpenSearch, which use a phrase suggester.

### Range and numeric filters

The unified `filters` option supports equality and OR (`{ field: 'value' }` or `{ field: ['a', 'b'] }`). For range/numeric filters (greater than, less than, between), use engine-native filter syntax:

```twig
{# Elasticsearch/OpenSearch — price range #}
{% set results = craft.searchIndex.search('products', query, {
    body: {
        query: {
            bool: {
                must: { multi_match: { query: query, fields: ['title'] } },
                filter: [
                    { range: { price: { gte: 10, lte: 100 } } },
                    { range: { postDate: { gte: 'now-30d' } } },
                ]
            }
        }
    }
}) %}

{# Meilisearch — price range #}
{% set results = craft.searchIndex.search('products', query, {
    filter: 'price >= 10 AND price <= 100',
}) %}

{# Typesense — price range #}
{% set results = craft.searchIndex.search('products', query, {
    filter_by: 'price:>=10 && price:<=100',
}) %}
```

**Note:** Engine-native filter keys take precedence over unified `filters`, so you can combine them. If you need both unified facet/equality filters and engine-native range filters, use the engine-native syntax for everything.

### Browse mode (empty query)

To build filter-only UIs where users browse content by facets without a text query, pass an empty string:

```twig
{% set results = craft.searchIndex.search('articles', '', {
    facets: ['category', 'sectionHandle'],
    filters: activeFilters,
    sort: { postDate: 'desc' },
    perPage: 12,
    page: page,
}) %}
```

Engine support for empty queries varies:

| Engine          | Empty query support                                                |
|-----------------|-------------------------------------------------------------------|
| Meilisearch     | Full support — returns all documents, filtered and sorted.         |
| Typesense       | Full support — use `q: '*'` for match-all, or empty string.       |
| Algolia         | Full support — returns all records when query is empty.            |
| Elasticsearch   | Full support — automatically uses `match_all` for empty queries.   |
| OpenSearch      | Full support — automatically uses `match_all` for empty queries.   |

### Normalised hit shape

Every hit in `results.hits` always contains these keys, regardless of engine:

| Key           | Type               | Description                                      |
|---------------|--------------------|--------------------------------------------------|
| `objectID`    | `string`           | The document ID.                                 |
| `_score`      | `float\|int\|null` | Relevance score (engine-dependent, may be null).  |
| `_highlights`  | `array`            | Normalised highlights: `{ field: ['fragment', ...] }`. |

All original engine-specific fields on each hit are preserved alongside the normalised ones.

### SearchResult properties

| Property          | Type    | Description                          |
|-------------------|---------|--------------------------------------|
| `hits`            | `array` | Normalised hit documents.            |
| `totalHits`       | `int`   | Total matching documents.            |
| `page`            | `int`   | Current page (1-based).              |
| `perPage`         | `int`   | Results per page.                    |
| `totalPages`      | `int`   | Total number of pages.               |
| `processingTimeMs`| `int`   | Query processing time in ms.         |
| `facets`          | `array` | Aggregation/facet data.              |
| `raw`             | `array` | Original unmodified engine response. |
| `suggestions`     | `array` | Spelling suggestions ("did you mean?"). |

`SearchResult` implements `ArrayAccess` and `Countable`, so `results['hits']` and `results|length` both work in Twig for backward compatibility.

### `results.facetsWithActive(activeFilters)`

Returns facets enriched with an `active` boolean on each value. This eliminates manual `in` checks when rendering facet checkboxes, and enables generic facet loops.

```twig
{% set results = craft.searchIndex.search('places', query, {
    facets: ['region', 'category'],
    filters: _filters,
}) %}

{# Enrich facets with active state in one call #}
{% set enrichedFacets = results.facetsWithActive({
    region: activeRegions,
    category: activeCategories,
}) %}

{# Generic facet rendering loop #}
{% for facetName, options in enrichedFacets %}
    <fieldset>
        <legend>{{ facetName|title }}</legend>
        {% for option in options %}
            <label>
                <input type="checkbox" name="filters[{{ facetName }}][]"
                    value="{{ option.value }}" {{ option.active ? 'checked' }}>
                {{ option.value }} ({{ option.count }})
            </label>
        {% endfor %}
    </fieldset>
{% endfor %}
```

| Parameter       | Type    | Description                                             |
|-----------------|---------|---------------------------------------------------------|
| `activeFilters` | `array` | Map of field name to active value(s): `{ field: ['val1', 'val2'] }`. |

Each facet value in the returned array has the original `value` and `count` plus an `active` boolean.

## `craft.searchIndex.searchContext(indexHandle, options)`

Returns a pre-built search context for use in Sprig templates. Encapsulates the logic of scanning field mappings for roles, facet fields, and sort options, and optionally executes a search — all in one call.

```twig
{% set ctx = craft.searchIndex.searchContext('places', {
    query: query,
    page: page,
    perPage: 12,
    sortField: sortField,
    sortDirection: sortDirection,
    filters: filters,
    doSearch: true,
}) %}

{# ctx.roles — { title: 'title', image: 'placeHeroImage', url: 'uri', ... } #}
{# ctx.facetFields — ['placeRegion', 'placeCountry'] #}
{# ctx.sortOptions — [{ label: 'Relevance', value: '' }, { label: 'postDate', value: 'postDate' }] #}
{# ctx.data — search results (same shape as cpSearch()), or null if doSearch is falsy #}
```

### Options

| Option           | Type     | Default | Description                                      |
|------------------|----------|---------|--------------------------------------------------|
| `query`          | `string` | `''`    | Search query text.                               |
| `page`           | `int`    | `1`     | Page number.                                     |
| `perPage`        | `int`    | `10`    | Results per page.                                |
| `sortField`      | `string` | `''`    | Field to sort by (empty = relevance).            |
| `sortDirection`  | `string` | `'desc'`| Sort direction (`'asc'` or `'desc'`).            |
| `filters`        | `array`  | `{}`    | Filter map: `{ field: ['value1', 'value2'] }`.   |
| `doSearch`       | `bool`   | `false` | Whether to execute the search.                   |

### Return value

| Key            | Type     | Description                                                      |
|----------------|----------|------------------------------------------------------------------|
| `roles`        | `array`  | Map of role name to index field name (e.g. `{ title: 'title' }`). |
| `facetFields`  | `array`  | List of facet field names from enabled TYPE_FACET mappings.      |
| `sortOptions`  | `array`  | List of `{ label, value }` for sortable fields (prepends Relevance). |
| `data`         | `array\|null` | Search results when `doSearch` is truthy, otherwise `null`.  |

This method is the recommended way to build search UIs with the [published Sprig stubs](sprig.md#published-starter-templates). It replaces the need to manually scan field mappings or duplicate SearchBox logic in templates.

## Template Helpers

### `craft.searchIndex.stateInputs(state, options)`

Generate hidden `<input>` tags from a state array. Simplifies Sprig form state management — define state once, inject into any form without manual hidden-input boilerplate.

```twig
{# Define state once #}
{% set _state = {
    query: query,
    sort: sort,
    page: 1,
    activeRegions: activeRegions,
} %}

{# Sort form — exclude 'sort' since the <select> provides it #}
<form sprig s-include="this">
    {{ craft.searchIndex.stateInputs(_state, { exclude: 'sort' }) }}
    <select name="sort">...</select>
</form>

{# Facet form — exclude the facet's own key since checkboxes provide it #}
<form sprig s-include="this">
    {{ craft.searchIndex.stateInputs(_state, { exclude: 'activeRegions' }) }}
    {% for facet in results.facets.region %}
        <input type="checkbox" name="activeRegions[]" value="{{ facet.value }}">
    {% endfor %}
</form>
```

**Behaviour:**
- Scalar values generate a single `<input>` per key
- Array values expand into multiple `<input>` tags with `[]` suffix
- Nested associative arrays expand recursively (`name[key][]`)
- `null` and empty-string values are omitted
- Returns `Twig\Markup` so output is not auto-escaped

| Parameter | Type    | Description                                                   |
|-----------|---------|---------------------------------------------------------------|
| `state`   | `array` | Key-value state to convert to hidden inputs.                  |
| `options` | `array` | Optional. `exclude`: string or array of keys to skip.         |

### `craft.searchIndex.buildUrl(basePath, params)`

Build a URL from a base path and query-parameter array. Useful for pagination links, filter pill removal URLs, and URL push headers.

```twig
{# Define URL params once (separate from Sprig state because param names may differ) #}
{% set _urlParams = {
    q: query ?: null,
    region: activeRegions|length ? activeRegions : null,
    sort: sort != 'relevance' ? sort : null,
} %}

{# Build a URL — null/empty values are omitted for clean URLs #}
{{ craft.searchIndex.buildUrl('/search', _urlParams) }}
{# → /search?q=london&region[]=Highland&region[]=Central #}

{# Pagination URL — merge page param #}
{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({ page: 2 })) }}
{# → /search?q=london&region[]=Highland&page=2 #}

{# Omit page=1 for clean URLs #}
{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({ page: page > 1 ? page : null })) }}
```

**Behaviour:**
- Array values expand into `key[]=value` pairs
- `null`, empty-string, `false`, and empty-array values are omitted
- Values are URL-encoded

| Parameter  | Type     | Description                                                     |
|------------|----------|-----------------------------------------------------------------|
| `basePath` | `string` | The base URL path (e.g. `/search`).                             |
| `params`   | `array`  | Query parameters. Arrays become `key[]=value` pairs.            |

## `craft.searchIndex.multiSearch(searches)`

Execute multiple search queries across one or more indexes in a single batch. Queries are grouped by engine instance so engines with native multi-search support (all five built-in engines) execute them in one round-trip. Results are returned in the same order as the input queries.

```twig
{% set results = craft.searchIndex.multiSearch([
    { handle: 'products', query: 'laptop' },
    { handle: 'articles', query: 'laptop review', options: { perPage: 5 } },
]) %}

{% for result in results %}
    <h2>{{ result.totalHits }} hits</h2>
    {% for hit in result.hits %}
        <p>{{ hit.title }}</p>
    {% endfor %}
{% endfor %}
```

Each item in the `searches` array accepts `handle` (string), `query` (string), and optionally `options` (array, same as single search). Returns a `SearchResult[]` array.

## `craft.searchIndex.autocomplete(handle, query, options)`

Lightweight autocomplete search optimised for speed. Defaults to a small result set (5 hits) and returns only the role-mapped fields (title, url, image, etc.) plus objectID to minimise payload.

```twig
{% set suggestions = craft.searchIndex.autocomplete('articles', 'lond') %}

{% for hit in suggestions.hits %}
    <div class="suggestion">{{ hit.title }}</div>
{% endfor %}
```

All standard search options are accepted and override the autocomplete defaults:

```twig
{# Override defaults: return more fields, search more fields #}
{% set suggestions = craft.searchIndex.autocomplete('places', userInput, {
    perPage: 8,
    fields: ['title', 'city'],
    attributesToRetrieve: ['objectID', 'title', 'city', 'uri'],
}) %}
```

| Default              | Value          | Override with                  |
|----------------------|----------------|--------------------------------|
| `perPage`            | `5`            | `perPage: 10`                  |
| `attributesToRetrieve` | `['objectID'] + role fields` | `attributesToRetrieve: [...]`  |

Works well with [Sprig](sprig.md) for real-time autocomplete UIs.

## `craft.searchIndex.searchFacetValues(handle, facetName, query, options)`

Search within facet values for a specific field. Useful when an index has hundreds of facet values (e.g. categories, tags) and you need to let users filter the facet list before selecting.

```twig
{# Search for categories containing "tech" #}
{% set values = craft.searchIndex.searchFacetValues('articles', 'category', 'tech') %}

{% for item in values %}
    <label>
        <input type="checkbox" name="category" value="{{ item.value }}">
        {{ item.value }} ({{ item.count }})
    </label>
{% endfor %}
```

Returns an array of `{ value: string, count: int }` items, sorted by count descending.

| Parameter  | Type     | Description                                                    |
|------------|----------|----------------------------------------------------------------|
| `handle`   | `string` | The index handle.                                              |
| `facetName`| `string` | The facet field name to search within.                         |
| `query`    | `string` | Text to match against facet values (case-insensitive contains).|
| `options`  | `array`  | Optional: `filters` to narrow the base set, `maxValues` (default 10). |

```twig
{# Search facet values with an active filter applied #}
{% set values = craft.searchIndex.searchFacetValues('articles', 'category', 'tech', {
    filters: { sectionHandle: 'news' },
    maxValues: 20,
}) %}
```

## `craft.searchIndex.indexes`

Get all configured indexes.

```twig
{% set indexes = craft.searchIndex.indexes %}
{% for index in indexes %}
    <p>{{ index.name }} ({{ index.handle }})</p>
{% endfor %}
```

## `craft.searchIndex.index(handle)`

Get a single index by handle.

```twig
{% set index = craft.searchIndex.index('places') %}
{% if index %}
    <p>{{ index.name }}</p>
{% endif %}
```

## `craft.searchIndex.docCount(handle)`

Get the document count for an index.

```twig
<p>{{ craft.searchIndex.docCount('places') }} documents indexed</p>
```

## `craft.searchIndex.getDocument(handle, documentId)`

Retrieve a single document from an index by its ID.

```twig
{% set doc = craft.searchIndex.getDocument('places', '12345') %}
{% if doc %}
    <p>{{ doc.title }} -- {{ doc.uri }}</p>
{% endif %}
```

## Vector Search

When a [Voyage AI](../configuration.md#integrations) API key is configured and your index has an embedding field (type `embedding`), you can perform semantic vector search by passing `vectorSearch: true`. The plugin generates an embedding from the query text via Voyage AI and sends a KNN query to the search engine.

### Basic semantic search

```twig
{% set results = craft.searchIndex.search('artworks', 'impressionist landscapes', {
    vectorSearch: true,
}) %}
```

This generates an embedding from the query, auto-detects the embedding field from the index's field mappings, and sends a KNN query to the engine.

### Hybrid search (text + vector)

When a text query is provided alongside `vectorSearch`, the engine combines both signals using a `bool/should` query (ES/OpenSearch), which blends keyword relevance with semantic similarity:

```twig
{% set results = craft.searchIndex.search('artworks', 'monet water lilies', {
    vectorSearch: true,
    perPage: 20,
}) %}
```

### Specifying model and target field

```twig
{% set results = craft.searchIndex.search('artworks', 'sunset over water', {
    vectorSearch: true,
    voyageModel: 'voyage-3',
    embeddingField: 'description_embedding',
}) %}
```

### Pre-computed embedding (skip Voyage API)

If you already have an embedding vector, pass it directly:

```twig
{% set results = craft.searchIndex.search('artworks', '', {
    embedding: precomputedVector,
    embeddingField: 'clip_embedding',
}) %}
```

### Vector search options reference

| Option           | Type     | Default     | Description                                                       |
|------------------|----------|-------------|-------------------------------------------------------------------|
| `vectorSearch`   | `bool`   | `false`     | Generate a Voyage AI embedding from the query for KNN search.     |
| `voyageModel`    | `string` | `'voyage-3'`| Voyage AI model to use for embedding generation.                  |
| `embeddingField` | `string` | auto        | Target embedding field in the index. Auto-detected from field mappings if omitted. |
| `embedding`      | `array`  | —           | Pre-computed embedding vector (skips Voyage API call).            |

**Note:** Vector search is currently supported on Elasticsearch and OpenSearch engines. The embedding field must be mapped as type `embedding` (which maps to `knn_vector`/`dense_vector` in the engine schema).

## `craft.searchIndex.isReady(handle)`

Check whether an index's engine is connected and the index exists.

```twig
{% if craft.searchIndex.isReady('places') %}
    {# safe to search #}
{% endif %}
```
