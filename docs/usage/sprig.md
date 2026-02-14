# Sprig Integration

[Sprig](https://putyourlightson.com/plugins/sprig) is a reactive component framework for Craft CMS that enables real-time UI updates without writing JavaScript. It pairs well with this plugin for building interactive search experiences.

These examples assume you have an index with handle `articles` containing `title`, `category`, `postDate`, and `uri` fields, with appropriate roles configured (title, url).

## Autocomplete

A search-as-you-type autocomplete input that shows suggestions after the user types 2+ characters.

### `_components/search-autocomplete.twig`

```twig
{# Sprig component: search autocomplete #}
{% set query = query ?? '' %}

<div style="position:relative;">
    <input sprig
           s-trigger="keyup changed delay:250ms"
           s-replace="#autocomplete-results"
           s-indicator="#autocomplete-spinner"
           s-cache="60"
           type="search"
           name="query"
           value="{{ query }}"
           placeholder="Search..."
           autocomplete="off"
           aria-label="Search"
           aria-controls="autocomplete-results">

    <span id="autocomplete-spinner" class="htmx-indicator"
          style="position:absolute;right:12px;top:50%;transform:translateY(-50%);">
        ...
    </span>

    <div id="autocomplete-results" role="listbox">
        {% if query|length >= 2 %}
            {% set results = craft.searchIndex.autocomplete('articles', query, { perPage: 6 }) %}

            {% if results.hits|length %}
                {% for hit in results.hits %}
                    <a href="/{{ hit.uri }}" class="suggestion" role="option">
                        {{ hit.title ?? hit.objectID }}
                    </a>
                {% endfor %}

                {% if results.totalHits > results.perPage %}
                    <a href="/search?q={{ query|url_encode }}" class="suggestion suggestion--more">
                        View all {{ results.totalHits }} results &rarr;
                    </a>
                {% endif %}
            {% endif %}
        {% endif %}
    </div>
</div>
```

Minimal CSS for the loading indicator and dropdown:

```css
.htmx-indicator { display: none; }
.htmx-request .htmx-indicator,
.htmx-request.htmx-indicator { display: inline; }
```

Include it in any template with a protected `_indexHandle`:

```twig
{{ sprig('_components/search-autocomplete', {
    _indexHandle: 'articles',
}) }}
```

### Key details

- `sprig` on the input makes it the reactive trigger element
- `s-trigger="keyup changed delay:250ms"` only fires when the value actually changes, with a 250ms debounce
- `s-replace="#autocomplete-results"` swaps only the suggestions dropdown, keeping the input focused
- `s-indicator="#autocomplete-spinner"` shows a loading indicator during the request
- `s-cache="60"` caches responses for 60 seconds so repeated queries are instant
- `craft.searchIndex.autocomplete()` defaults to 5 results and returns all role-mapped fields (title, url, image, etc.) so links and thumbnails work out of the box
- The `_indexHandle` variable uses an underscore prefix, making it a Sprig protected variable that cannot be tampered with via the request

## Full Search with Pagination

A complete search page with query, paginated results, and result count.

### `_components/search-results.twig`

```twig
{# Sprig component: paginated search results #}
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set _perPage = 12 %}

<div sprig s-indicator="#search-spinner" id="search-results">
    <form sprig s-vals='{"page": 1}' s-disabled-elt="find button">
        <input type="search"
               name="q"
               value="{{ q }}"
               placeholder="Search articles..."
               aria-label="Search">
        <button type="submit">Search</button>
    </form>

    <div id="search-spinner" class="htmx-indicator">Searching...</div>

    {% if q|length > 0 %}
        {% set results = craft.searchIndex.search('articles', q, {
            perPage: _perPage,
            page: page,
        }) %}

        <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }} for "{{ q }}"</p>

        {% if results.totalHits > 0 %}
            <div class="results-grid">
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
                <nav aria-label="Search results pages">
                    {% if results.page > 1 %}
                        <button sprig
                                s-val:page="{{ results.page - 1 }}"
                                s-val:q="{{ q }}"
                                s-push-url="?q={{ q|url_encode }}&page={{ results.page - 1 }}">
                            Previous
                        </button>
                    {% endif %}

                    {% for i in 1..results.totalPages %}
                        {% if i == results.page %}
                            <span aria-current="page">{{ i }}</span>
                        {% else %}
                            <button sprig
                                    s-val:page="{{ i }}"
                                    s-val:q="{{ q }}"
                                    s-push-url="?q={{ q|url_encode }}&page={{ i }}">
                                {{ i }}
                            </button>
                        {% endif %}
                    {% endfor %}

                    {% if results.page < results.totalPages %}
                        <button sprig
                                s-val:page="{{ results.page + 1 }}"
                                s-val:q="{{ q }}"
                                s-push-url="?q={{ q|url_encode }}&page={{ results.page + 1 }}">
                            Next
                        </button>
                    {% endif %}
                </nav>
            {% endif %}
        {% endif %}
    {% endif %}
</div>
```

### Key details

- `<form sprig>` — forms default to triggering on `submit`, no `s-trigger` needed
- `s-vals='{"page": 1}'` resets to page 1 on new searches
- `_perPage` uses an underscore prefix so it cannot be tampered with via the request
- `s-push-url` updates the browser URL so pagination is bookmarkable
- `s-indicator="#search-spinner"` shows a loading message during requests
- `s-disabled-elt="find button"` disables the submit button while a request is in flight

## Faceted Filtering

A search page with dynamic facet filters that update counts in real time.

### `_components/search-with-filters.twig`

```twig
{# Sprig component: search with facet filters #}
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set activeCategory = activeCategory ?? '' %}
{% set activeSection = activeSection ?? '' %}
{% set _perPage = 12 %}

<div sprig id="search-filtered">
    {# Search input #}
    <form sprig s-vals='{"page": 1}'>
        <input type="search"
               name="q"
               value="{{ q }}"
               placeholder="Search...">
        <button type="submit">Search</button>
    </form>

    {% if q|length > 0 %}
        {# Build options with active filters #}
        {% set options = {
            facets: ['category', 'sectionHandle'],
            perPage: _perPage,
            page: page,
        } %}

        {% set activeFilters = {} %}
        {% if activeCategory %}
            {% set activeFilters = activeFilters|merge({ category: activeCategory }) %}
        {% endif %}
        {% if activeSection %}
            {% set activeFilters = activeFilters|merge({ sectionHandle: activeSection }) %}
        {% endif %}
        {% if activeFilters|length > 0 %}
            {% set options = options|merge({ filters: activeFilters }) %}
        {% endif %}

        {% set results = craft.searchIndex.search('articles', q, options) %}

        <div class="search-layout">
            {# Facet sidebar #}
            <aside class="filters">
                {# Category filter #}
                <fieldset>
                    <legend>Category</legend>
                    {% for facet in results.facets.category ?? [] %}
                        <label>
                            <input type="radio"
                                   name="activeCategory"
                                   value="{{ facet.value }}"
                                   {{ activeCategory == facet.value ? 'checked' }}
                                   sprig
                                   s-val:page="1"
                                   s-val:q="{{ q }}"
                                   s-val:activeSection="{{ activeSection }}">
                            {{ facet.value }} ({{ facet.count }})
                        </label>
                    {% endfor %}
                    {% if activeCategory %}
                        <button sprig
                                s-val:activeCategory=""
                                s-val:page="1"
                                s-val:q="{{ q }}"
                                s-val:activeSection="{{ activeSection }}">
                            Clear
                        </button>
                    {% endif %}
                </fieldset>

                {# Section filter #}
                <fieldset>
                    <legend>Section</legend>
                    {% for facet in results.facets.sectionHandle ?? [] %}
                        <label>
                            <input type="radio"
                                   name="activeSection"
                                   value="{{ facet.value }}"
                                   {{ activeSection == facet.value ? 'checked' }}
                                   sprig
                                   s-val:page="1"
                                   s-val:q="{{ q }}"
                                   s-val:activeCategory="{{ activeCategory }}">
                            {{ facet.value }} ({{ facet.count }})
                        </label>
                    {% endfor %}
                    {% if activeSection %}
                        <button sprig
                                s-val:activeSection=""
                                s-val:page="1"
                                s-val:q="{{ q }}"
                                s-val:activeCategory="{{ activeCategory }}">
                            Clear
                        </button>
                    {% endif %}
                </fieldset>
            </aside>

            {# Results #}
            <div class="results">
                <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }}</p>

                {% for hit in results.hits %}
                    <article>
                        <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
                    </article>
                {% endfor %}

                {# Pagination #}
                {% if results.totalPages > 1 %}
                    <nav aria-label="Pages">
                        {% for i in 1..results.totalPages %}
                            {% if i == results.page %}
                                <span aria-current="page">{{ i }}</span>
                            {% else %}
                                <button sprig
                                        s-val:page="{{ i }}"
                                        s-val:q="{{ q }}"
                                        s-val:activeCategory="{{ activeCategory }}"
                                        s-val:activeSection="{{ activeSection }}"
                                        s-push-url="?q={{ q|url_encode }}&page={{ i }}">
                                    {{ i }}
                                </button>
                            {% endif %}
                        {% endfor %}
                    </nav>
                {% endif %}
            </div>
        </div>
    {% endif %}
</div>
```

## Searchable Facet Values

When you have many facet values (e.g. hundreds of categories), let users search within the facet list to narrow it down.

### `_components/facet-search.twig`

```twig
{# Sprig component: searchable facet dropdown #}
{% set facetQuery = facetQuery ?? '' %}
{% set selectedCategory = selectedCategory ?? '' %}

<input sprig
       s-trigger="keyup changed delay:200ms"
       s-replace="#facet-list"
       type="search"
       name="facetQuery"
       value="{{ facetQuery }}"
       placeholder="Type to filter categories...">

<ul id="facet-list" role="listbox">
    {% set values = craft.searchIndex.searchFacetValues('articles', 'category', facetQuery, {
        maxValues: 20,
    }) %}

    {% for item in values %}
        <li role="option">
            <label>
                <input type="radio"
                       name="selectedCategory"
                       value="{{ item.value }}"
                       {{ selectedCategory == item.value ? 'checked' }}>
                {{ item.value }} <span class="count">({{ item.count }})</span>
            </label>
        </li>
    {% endfor %}

    {% if values|length == 0 and facetQuery|length > 0 %}
        <li class="empty">No categories matching "{{ facetQuery }}"</li>
    {% endif %}
</ul>
```

## Sorted Results

Results sorted by a specific field instead of relevance.

```twig
{# Sort by date, newest first #}
{% set results = craft.searchIndex.search('articles', q, {
    sort: { postDate: 'desc' },
    perPage: 12,
    page: page,
}) %}

{# Sort by price ascending #}
{% set results = craft.searchIndex.search('products', q, {
    sort: { price: 'asc' },
}) %}
```

### Sort toggle in a Sprig component

```twig
{% set q = q ?? '' %}
{% set sortField = sortField ?? '' %}
{% set sortDir = sortDir ?? 'desc' %}
{% set page = page ?? 1 %}
{% set _perPage = 12 %}

<div sprig id="sorted-search">
    <form sprig s-vals='{"page": 1}'>
        <input type="search" name="q" value="{{ q }}">
        <button type="submit">Search</button>
    </form>

    {% if q|length > 0 %}
        {# Sort controls #}
        <div class="sort-controls">
            <span>Sort by:</span>
            <button sprig
                    s-val:sort-field=""
                    s-val:q="{{ q }}"
                    s-val:page="1"
                    class="{{ sortField == '' ? 'active' }}">
                Relevance
            </button>
            <button sprig
                    s-val:sort-field="postDate"
                    s-val:sort-dir="desc"
                    s-val:q="{{ q }}"
                    s-val:page="1"
                    class="{{ sortField == 'postDate' ? 'active' }}">
                Newest
            </button>
            <button sprig
                    s-val:sort-field="title"
                    s-val:sort-dir="asc"
                    s-val:q="{{ q }}"
                    s-val:page="1"
                    class="{{ sortField == 'title' ? 'active' }}">
                A-Z
            </button>
        </div>

        {% set options = { perPage: _perPage, page: page } %}
        {% if sortField %}
            {% set options = options|merge({ sort: { (sortField): sortDir } }) %}
        {% endif %}

        {% set results = craft.searchIndex.search('articles', q, options) %}

        {% for hit in results.hits %}
            <article>
                <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
            </article>
        {% endfor %}
    {% endif %}
</div>
```

**Note:** `s-val:sort-field` uses kebab-case which Sprig converts to camelCase (`sortField`) in the component.

## Highlighted Results with "Did You Mean?"

Search results with normalised highlighting and spelling suggestions (ES/OpenSearch).

### `_components/search-highlighted.twig`

```twig
{# Sprig component: highlighted search results with suggestions #}
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set _perPage = 10 %}

<div sprig id="search-highlighted">
    <form sprig s-vals='{"page": 1}'>
        <input type="search" name="q" value="{{ q }}" placeholder="Search...">
        <button type="submit">Search</button>
    </form>

    {% if q|length > 0 %}
        {% set results = craft.searchIndex.search('articles', q, {
            highlight: ['title', 'body'],
            suggest: true,
            perPage: _perPage,
            page: page,
        }) %}

        {# "Did you mean?" suggestions #}
        {% if results.suggestions is not empty %}
            <p class="did-you-mean">Did you mean:
                {% for suggestion in results.suggestions %}
                    <button sprig
                            s-val:q="{{ suggestion }}"
                            s-val:page="1">
                        {{ suggestion }}
                    </button>{{ not loop.last ? ',' }}
                {% endfor %}
                ?
            </p>
        {% endif %}

        <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }} for "{{ q }}"</p>

        {% for hit in results.hits %}
            <article>
                {# Use highlighted title if available #}
                {% if hit._highlights.title is defined %}
                    <h3><a href="/{{ hit.uri }}">{{ hit._highlights.title|first|raw }}</a></h3>
                {% else %}
                    <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
                {% endif %}

                {# Show highlighted body snippets #}
                {% if hit._highlights.body is defined %}
                    {% for fragment in hit._highlights.body %}
                        <p class="snippet">...{{ fragment|raw }}...</p>
                    {% endfor %}
                {% endif %}
            </article>
        {% endfor %}
    {% endif %}
</div>
```

### Key details

- `highlight: ['title', 'body']` requests highlighting for specific fields — works across all engines
- `suggest: true` enables spelling suggestions (Elasticsearch/OpenSearch only; other engines handle typos automatically)
- `_highlights` is always in the normalised `{ field: ['fragment', ...] }` format, regardless of engine
- Use `|first|raw` to get the first highlighted fragment and render the HTML tags
- Suggestions trigger a new search via Sprig when clicked

## Complete Example: Search + Autocomplete + Filters + Sorting + Pagination

Combines all features into a single search experience.

### Page template: `templates/search.twig`

```twig
{% extends '_layouts/main' %}

{% block content %}
    <h1>Search</h1>

    {# Autocomplete input #}
    {{ sprig('_components/search-autocomplete', {
        _indexHandle: 'articles',
    }) }}

    {# Full results (shown after form submission or direct URL) #}
    {{ sprig('_components/search-full', {
        _indexHandle: 'articles',
        q: craft.app.request.getQueryParam('q') ?? '',
    }) }}
{% endblock %}
```

### `_components/search-full.twig`

```twig
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set activeCategory = activeCategory ?? '' %}
{% set sortField = sortField ?? '' %}
{% set sortDir = sortDir ?? 'desc' %}
{% set _perPage = 12 %}

<div sprig id="search-full">
    {# Search form #}
    <form sprig s-vals='{"page": 1}'>
        <input type="search" name="q" value="{{ q }}" placeholder="Search...">
        <button type="submit">Search</button>
    </form>

    {% if q|length > 0 %}
        {# Build search options #}
        {% set options = {
            facets: ['category'],
            perPage: _perPage,
            page: page,
        } %}

        {% if activeCategory %}
            {% set options = options|merge({ filters: { category: activeCategory } }) %}
        {% endif %}

        {% if sortField %}
            {% set options = options|merge({ sort: { (sortField): sortDir } }) %}
        {% endif %}

        {% set results = craft.searchIndex.search('articles', q, options) %}

        <div class="search-layout">
            {# Sidebar: filters + sort #}
            <aside>
                {# Sort controls #}
                <fieldset>
                    <legend>Sort by</legend>
                    <button sprig s-val:sort-field="" s-val:q="{{ q }}" s-val:page="1"
                            s-val:active-category="{{ activeCategory }}"
                            class="{{ sortField == '' ? 'active' }}">
                        Relevance
                    </button>
                    <button sprig s-val:sort-field="postDate" s-val:sort-dir="desc"
                            s-val:q="{{ q }}" s-val:page="1"
                            s-val:active-category="{{ activeCategory }}"
                            class="{{ sortField == 'postDate' ? 'active' }}">
                        Newest
                    </button>
                </fieldset>

                {# Category filter #}
                <fieldset>
                    <legend>Category</legend>
                    {% for facet in results.facets.category ?? [] %}
                        <label>
                            <input type="radio" name="activeCategory"
                                   value="{{ facet.value }}"
                                   {{ activeCategory == facet.value ? 'checked' }}
                                   sprig s-val:page="1" s-val:q="{{ q }}"
                                   s-val:sort-field="{{ sortField }}"
                                   s-val:sort-dir="{{ sortDir }}">
                            {{ facet.value }} ({{ facet.count }})
                        </label>
                    {% endfor %}
                    {% if activeCategory %}
                        <button sprig s-val:active-category="" s-val:page="1"
                                s-val:q="{{ q }}" s-val:sort-field="{{ sortField }}"
                                s-val:sort-dir="{{ sortDir }}">
                            Clear filter
                        </button>
                    {% endif %}
                </fieldset>
            </aside>

            {# Main results #}
            <main>
                <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }}
                    for "{{ q }}"
                    {% if activeCategory %} in {{ activeCategory }}{% endif %}
                </p>

                {% for hit in results.hits %}
                    <article>
                        <h3><a href="/{{ hit.uri }}">{{ hit.title }}</a></h3>
                        {% if hit.summaryText is defined %}
                            <p>{{ hit.summaryText }}</p>
                        {% endif %}
                    </article>
                {% endfor %}

                {# Pagination #}
                {% if results.totalPages > 1 %}
                    <nav aria-label="Pages">
                        {% if results.page > 1 %}
                            <button sprig s-val:page="{{ results.page - 1 }}"
                                    s-val:q="{{ q }}"
                                    s-val:active-category="{{ activeCategory }}"
                                    s-val:sort-field="{{ sortField }}"
                                    s-val:sort-dir="{{ sortDir }}"
                                    s-push-url="?q={{ q|url_encode }}&page={{ results.page - 1 }}">
                                Previous
                            </button>
                        {% endif %}

                        {% for i in 1..results.totalPages %}
                            {% if i == results.page %}
                                <span aria-current="page">{{ i }}</span>
                            {% else %}
                                <button sprig s-val:page="{{ i }}"
                                        s-val:q="{{ q }}"
                                        s-val:active-category="{{ activeCategory }}"
                                        s-val:sort-field="{{ sortField }}"
                                        s-val:sort-dir="{{ sortDir }}"
                                        s-push-url="?q={{ q|url_encode }}&page={{ i }}">
                                    {{ i }}
                                </button>
                            {% endif %}
                        {% endfor %}

                        {% if results.page < results.totalPages %}
                            <button sprig s-val:page="{{ results.page + 1 }}"
                                    s-val:q="{{ q }}"
                                    s-val:active-category="{{ activeCategory }}"
                                    s-val:sort-field="{{ sortField }}"
                                    s-val:sort-dir="{{ sortDir }}"
                                    s-push-url="?q={{ q|url_encode }}&page={{ results.page + 1 }}">
                                Next
                            </button>
                        {% endif %}
                    </nav>
                {% endif %}
            </main>
        </div>
    {% endif %}
</div>
```

## Tips & Patterns

### Loading indicators

Use `s-indicator` to show a spinner or message while a search request is in flight. The element gets the `htmx-request` class during the request.

```twig
<input sprig s-indicator="#spinner" ...>
<span id="spinner" class="htmx-indicator">Loading...</span>
```

```css
.htmx-indicator { display: none; }
.htmx-request .htmx-indicator,
.htmx-request.htmx-indicator { display: inline; }
```

Use `s-disabled-elt` to disable buttons during a request to prevent double-clicks:

```twig
<form sprig s-disabled-elt="find button">
```

### Client-side caching

Use `s-cache` on autocomplete inputs to cache responses for repeated queries. This means typing "lon", backspacing, then typing "lon" again won't make a second server request:

```twig
<input sprig s-cache="60" ...>
```

### Skip work on initial page load

Use `sprig.isRequest` to skip the search query on the initial page render and only run it on AJAX re-renders:

```twig
{% if sprig.isRequest and q|length > 0 %}
    {% set results = craft.searchIndex.search('articles', q, options) %}
    {# ... render results ... #}
{% elseif q|length > 0 %}
    {# Initial load with query param — still search #}
    {% set results = craft.searchIndex.search('articles', q, options) %}
    {# ... render results ... #}
{% endif %}
```

### Cross-component communication

Use `s-listen` to refresh one component when another changes. For example, update a result count in the header when the search results change:

```twig
{# In your header #}
{{ sprig('_components/result-count', {}, {'s-listen': '#search-results'}) }}

{# _components/result-count.twig #}
{% set q = q ?? '' %}
{% if sprig.isRequest and q|length > 0 %}
    {% set results = craft.searchIndex.search('articles', q, { perPage: 0 }) %}
    <span>{{ results.totalHits }} results</span>
{% endif %}
```

### Browse mode (empty query with filters only)

For filter-only UIs where you want to browse all content without a search query, pass an empty string:

```twig
{% set results = craft.searchIndex.search('articles', '', {
    facets: ['category'],
    filters: activeFilters,
    sort: { postDate: 'desc' },
    perPage: _perPage,
    page: page,
}) %}
```

**Note:** Not all engines return results for empty queries by default. Meilisearch and Typesense support it well. For Elasticsearch/OpenSearch, the `bool_prefix` match type may return no results for an empty query — use `matchType: 'match_all'` or pass a native `body` query instead.
