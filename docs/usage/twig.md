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

### Search field restriction

Pass a `fields` array in the options to limit which indexed fields are searched (engine support varies, but Elasticsearch/OpenSearch accept this).

### Normalised hit shape

Every hit in `results.hits` always contains these keys, regardless of engine:

| Key           | Type               | Description                                      |
|---------------|--------------------|--------------------------------------------------|
| `objectID`    | `string`           | The document ID.                                 |
| `_score`      | `float\|int\|null` | Relevance score (engine-dependent, may be null).  |
| `_highlights`  | `array`            | Highlight/snippet data.                          |

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

`SearchResult` implements `ArrayAccess` and `Countable`, so `results['hits']` and `results|length` both work in Twig for backward compatibility.

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

## `craft.searchIndex.isReady(handle)`

Check whether an index's engine is connected and the index exists.

```twig
{% if craft.searchIndex.isReady('places') %}
    {# safe to search #}
{% endif %}
```
