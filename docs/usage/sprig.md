# Sprig Integration

[Sprig](https://putyourlightson.com/plugins/sprig) is a reactive component framework for Craft CMS that enables real-time UI updates without writing JavaScript. It pairs well with this plugin for building interactive search experiences.

These examples assume you have an index with handle `articles` containing `title`, `category`, `postDate`, and `uri` fields, with appropriate roles configured (title, url).

## Built-in Search Index Sprig Helpers

The plugin now provides a Twig helper wrapper around Sprig so templates can use short aliases instead of full class names:

```twig
{{ searchIndexSprig('frontend.search-box', {
    indexHandle: 'articles',
    perPage: 10,
}) }}
```

You can also resolve an alias to its concrete component identifier:

```twig
{{ searchIndexSprigComponent('frontend.search-box') }}
```

### Available aliases

- `cp.test-connection`
- `cp.validation-results`
- `cp.index-structure`
- `cp.index-health`
- `cp.search-single`
- `cp.search-compare`
- `cp.search-document-picker`
- `frontend.search-box`
- `frontend.search-facets`
- `frontend.search-pagination`

## Default Frontend Components (Starter Set)

The plugin ships unstyled, composable frontend Sprig components for quick testing/prototyping:

- `frontend.search-box` -- query input, sort, per-page, result list, optional raw JSON, inline facets + pagination controls. Supports `autoSearch` + `hideSubmit` flags.
- `frontend.search-facets` -- facets-only view using shared search state.
- `frontend.search-pagination` -- pagination-only view using shared search state.

These components share a common state contract:

- `indexHandle`
- `query`
- `doSearch`
- `page`
- `perPage`
- `sortField`
- `sortDirection`
- `filters`
- `facetFields`
- `showRaw`
- `autoSearch`
- `hideSubmit`
- `clearFilters`
- `searchPagePath`

### Published Starter Templates

You can publish editable starter templates into your project:

```bash
php craft search-index/index/publish-sprig-templates
```

This writes files to `templates/search-index/sprig/` (use `--force=1` to overwrite existing files).

#### Published files

| File | Purpose |
|------|---------|
| `components/search.twig` | Main layout component — calls `searchContext()`, includes partials, handles URL push |
| `components/search-form.twig` | Query input, per-page, sort controls with auto-search trigger |
| `components/search-results.twig` | Result cards with role-based field resolution (title, image, summary, url) |
| `components/search-facets.twig` | Checkbox facet groups, one form per facet field |
| `components/search-range-filters.twig` | Min/max numeric range inputs with histogram modal |
| `components/search-filters.twig` | Active filter pills with "clear all" button |
| `components/search-pagination.twig` | Windowed page buttons with prev/next |
| `js/histogram.js` | SVG histogram chart for range filter distribution dialogs |
| `search-page.twig` | Example page template showing how to include the component |
| `README.md` | Usage guide and state variable reference |

The published templates contain **real HTML markup** that you can edit, restyle, and rearrange. They use `craft.searchIndex.searchContext()` to get roles, facet fields, sort options, and search results in a single call.

#### Customising

Edit any component file to change markup, add CSS classes, or rearrange elements. The layout in `search.twig` includes the partials via `{% include %}` — rearrange or remove them as needed.

#### Bookmarkable URLs with `searchPagePath`

Set `searchPagePath` in your parent template to enable URL history push. After each Sprig interaction, the browser URL updates to reflect the current query, filters, page, and sort — making searches bookmarkable and shareable.

```twig
{# Parent template (e.g. search.twig) #}
{% set state = {
    indexHandle: 'places',
    searchPagePath: '/search',
    query: craft.app.request.getQueryParam('query') ?? '',
    page: craft.app.request.getQueryParam('page') ?? 1,
    filters: craft.app.request.getQueryParam('filters') ?? {},
    doSearch: 1,
    autoSearch: 1,
    hideSubmit: 1,
} %}

{{ sprig('search-index/sprig/components/search', state) }}
```

This enables:

- **URL push**: `sprig.pushUrl()` updates the URL bar on each Sprig request (e.g. `/search?query=london&filters[region][]=Highland&page=2`)
- **URL hydration**: reading query params in the parent template pre-populates the initial state
- **Pre-faceted links**: other pages can link to `/search?filters[region][]=Highland` to arrive with filters already applied

#### Pre-faceted search links

Link to the search page with pre-applied filters from other templates:

```twig
{# On a detail page, link a region badge to a filtered search #}
<a href="/search?filters[placeRegion][]={{ region.title|url_encode }}">
    {{ region.title }}
</a>
```

### Dev demo route (optional reference)

The plugin includes a developer-only demo route for internal testing:

- `/search-sprig--default-components`

This route is only registered in `devMode` and returns 404 in non-dev environments.

### Example: multi-component layout

```twig
{% set state = {
    indexHandle: 'articles',
    query: query ?? '',
    doSearch: 1,
    perPage: 10,
    page: page ?? 1,
    sortField: sortField ?? '',
    sortDirection: sortDirection ?? 'desc',
} %}

{{ searchIndexSprig('frontend.search-box', state) }}
{{ searchIndexSprig('frontend.search-facets', state) }}
{{ searchIndexSprig('frontend.search-pagination', state) }}
```

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

### Faceted autocomplete

Combine `facetAutocomplete()` with `autocomplete()` to show categorized facet suggestions (e.g. "Region: Scotland") above document matches — similar to British Museum or e-commerce search patterns.

```twig
{# Sprig component: faceted autocomplete #}
{% set query = query ?? '' %}
{% set showFacets = showFacets ?? true %}
{% set facetFields = facetFields ?? [] %}

<div style="position:relative;">
    <input sprig
           s-trigger="keyup changed delay:300ms"
           s-target="#autocomplete-results"
           s-select="#autocomplete-results"
           s-swap="innerHTML transition:true"
           type="search"
           name="query"
           value="{{ query }}"
           placeholder="Search..."
           autocomplete="off">

    <div id="autocomplete-results">
        {% if query|length >= 2 %}
            {% set facetOptions = facetFields|length ? { maxPerField: 4, facetFields: facetFields } : { maxPerField: 4 } %}
            {% set facetSuggestions = showFacets ? craft.searchIndex.facetAutocomplete('articles', query, facetOptions) : {} %}
            {% set results = craft.searchIndex.autocomplete('articles', query, { perPage: 5 }) %}

            {# Facet suggestions grouped by field #}
            {% for fieldName, values in facetSuggestions %}
                <div class="facet-group-label">{{ fieldName }}</div>
                {% for item in values %}
                    <a href="/search?filters[{{ fieldName }}][]={{ item.value|url_encode }}">
                        {{ item.value }} <span>({{ item.count }})</span>
                    </a>
                {% endfor %}
            {% endfor %}

            {# Document matches #}
            {% for hit in results.hits %}
                <a href="/{{ hit.uri }}">{{ hit.title ?? hit.objectID }}</a>
            {% endfor %}

            {# Footer #}
            <a href="/search?query={{ query|url_encode }}">
                View all results for "{{ query }}"
            </a>
        {% endif %}
    </div>
</div>
```

Include it with optional overrides:

```twig
{# All facets auto-detected (default) #}
{{ sprig('_components/autocomplete') }}

{# Specific facet fields only #}
{{ sprig('_components/autocomplete', { facetFields: ['category', 'region'] }) }}

{# No facets — document matches only #}
{{ sprig('_components/autocomplete', { showFacets: false }) }}
```

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
                        <h2><a href="/{{ hit.uri }}">{{ hit.title }}</a></h2>
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

A search page with dynamic facet filters that update counts in real time. Uses `stateInputs()` to eliminate hidden-input boilerplate and `buildUrl()` for clean pagination URLs.

### `_components/search-with-filters.twig`

```twig
{# Sprig component: search with facet filters #}
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set activeCategories = activeCategories ?? [] %}
{% set _perPage = 12 %}

{# Normalise array values (Sprig sends a string when only one item is selected) #}
{% if activeCategories is not iterable %}
    {% set activeCategories = activeCategories ? [activeCategories] : [] %}
{% endif %}
{% set activeCategories = activeCategories|filter(v => v is not same as('')) %}

{# Shared form state — page defaults to 1 so all forms reset pagination #}
{% set _state = { q: q, page: 1, activeCategories: activeCategories } %}

{# URL params for clean link hrefs #}
{% set _urlParams = {
    q: q ?: null,
    category: activeCategories|length ? activeCategories : null,
} %}

{# Push canonical URL on Sprig AJAX responses #}
{% if sprig.isRequest %}
    {% header "HX-Push-Url: " ~ craft.searchIndex.buildUrl('/search', page > 1 ? _urlParams|merge({page: page}) : _urlParams) %}
{% endif %}

<div id="search-filtered">
    {# Search input — stateInputs() replaces manual hidden inputs #}
    <form sprig s-include="this" s-replace="#search-results" s-swap="innerHTML transition:true">
        <input type="search" name="q" value="{{ q }}" placeholder="Search...">
        <button type="submit">Search</button>
        {{ craft.searchIndex.stateInputs(_state, { exclude: 'q' }) }}
    </form>

    <div id="search-results">
        {# Build search options with active filters #}
        {% set options = { facets: ['category'], perPage: _perPage, page: page } %}
        {% if activeCategories|length %}
            {% set options = options|merge({ filters: { category: activeCategories } }) %}
        {% endif %}

        {% set results = craft.searchIndex.search('articles', q, options) %}

        <div class="search-layout">
            {# Facet sidebar — one form per facet, exclude its own key #}
            <aside class="filters">
                <form sprig s-include="this" s-trigger="change"
                      s-replace="#search-results" s-swap="innerHTML transition:true"
                      s-indicator="#spinner" s-val:page="1">
                    {{ craft.searchIndex.stateInputs(_state, { exclude: ['activeCategories', 'page'] }) }}

                    <fieldset>
                        <legend>Category</legend>
                        {% for facet in results.facets.category ?? [] %}
                            <label>
                                <input type="checkbox"
                                       name="activeCategories[]"
                                       value="{{ facet.value }}"
                                       {{ facet.value in activeCategories ? 'checked' }}>
                                {{ facet.value }} ({{ facet.count }})
                            </label>
                        {% endfor %}
                    </fieldset>
                </form>

                {# Active filter pills #}
                {% for cat in activeCategories %}
                    {% set _remaining = activeCategories|filter(c => c != cat) %}
                    <form class="d-inline">
                        {{ craft.searchIndex.stateInputs(_state|merge({ activeCategories: _remaining })) }}
                        <a sprig s-include="closest form"
                           s-replace="#search-results" s-swap="innerHTML transition:true"
                           href="{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({ category: _remaining|length ? _remaining : null })) }}">
                            {{ cat }} &times;
                        </a>
                    </form>
                {% endfor %}
            </aside>

            {# Results #}
            <div class="results">
                <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }}</p>

                {% for hit in results.hits %}
                    <article>
                        <h2><a href="/{{ hit.uri }}">{{ hit.title }}</a></h2>
                    </article>
                {% endfor %}

                {# Pagination — hidden form carries state, links override page #}
                {% if results.totalPages > 1 %}
                    <nav aria-label="Pages">
                        <form id="page-state" class="d-none">
                            {{ craft.searchIndex.stateInputs(_state, { exclude: 'page' }) }}
                        </form>
                        {% for i in 1..results.totalPages %}
                            {% if i == results.page %}
                                <span aria-current="page">{{ i }}</span>
                            {% else %}
                                <a sprig s-include="#page-state" s-val:page="{{ i }}"
                                   s-replace="#search-results" s-swap="innerHTML transition:true"
                                   href="{{ craft.searchIndex.buildUrl('/search', i > 1 ? _urlParams|merge({page: i}) : _urlParams) }}">
                                    {{ i }}
                                </a>
                            {% endif %}
                        {% endfor %}
                    </nav>
                {% endif %}
            </div>
        </div>
    </div>
</div>
```

### Key details

- **`stateInputs()`** generates hidden inputs from a state hash — define once, reuse everywhere with `{ exclude: '...' }`
- **`buildUrl()`** builds clean URLs from a param hash — arrays become `key[]=value`, nulls are omitted
- The `_state` variable has `page: 1` so all filter/search forms reset pagination automatically
- Pagination links use a **hidden `<form>`** with `s-include` to carry state, avoiding `s-vals` serialization issues with arrays
- `s-swap="innerHTML transition:true"` enables smooth View Transitions between swaps
- `{% header "HX-Push-Url: ..." %}` pushes the canonical URL on every Sprig response — bookmarkable state

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
                <h2><a href="/{{ hit.uri }}">{{ hit.title }}</a></h2>
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
                    <h2><a href="/{{ hit.uri }}">{{ hit._highlights.title|first|raw }}</a></h2>
                {% else %}
                    <h2><a href="/{{ hit.uri }}">{{ hit.title }}</a></h2>
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

Combines all features into a single search experience using `stateInputs()` and `buildUrl()` for minimal boilerplate.

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
        page: craft.app.request.getQueryParam('page')|integer ?: 1,
        activeCategories: craft.app.request.getQueryParam('category') ?? [],
        sort: craft.app.request.getQueryParam('sort') ?? 'relevance',
    }) }}
{% endblock %}
```

### `_components/search-full.twig`

```twig
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set activeCategories = activeCategories ?? [] %}
{% set sort = sort ?? 'relevance' %}
{% set _perPage = 12 %}

{% if activeCategories is not iterable %}
    {% set activeCategories = activeCategories ? [activeCategories] : [] %}
{% endif %}
{% set activeCategories = activeCategories|filter(v => v is not same as('')) %}

{# Define state once — page: 1 so all filter/search forms reset pagination #}
{% set _state = { q: q, sort: sort, page: 1, activeCategories: activeCategories } %}
{% set _urlParams = {
    q: q ?: null,
    category: activeCategories|length ? activeCategories : null,
    sort: sort != 'relevance' ? sort : null,
} %}

{% if sprig.isRequest %}
    {% header "HX-Push-Url: " ~ craft.searchIndex.buildUrl('/search', page > 1 ? _urlParams|merge({page: page}) : _urlParams) %}
{% endif %}

<div id="search-full">
    {# Search form #}
    <form sprig s-include="this" s-replace="#search-results" s-swap="innerHTML transition:true">
        <input type="search" name="q" value="{{ q }}" placeholder="Search...">
        <button type="submit">Search</button>
        {{ craft.searchIndex.stateInputs(_state, { exclude: 'q' }) }}
    </form>

    <div id="search-results">
        {# Build search options #}
        {% set options = { facets: ['category'], perPage: _perPage, page: page } %}
        {% if activeCategories|length %}
            {% set options = options|merge({ filters: { category: activeCategories } }) %}
        {% endif %}
        {% if sort == 'dateDesc' %}
            {% set options = options|merge({ sort: { postDate: 'desc' } }) %}
        {% endif %}

        {% set results = craft.searchIndex.search(_indexHandle, q, options) %}

        <div class="search-layout">
            {# Sidebar: sort + filters #}
            <aside>
                {# Sort dropdown #}
                <form sprig s-include="this" s-trigger="change" s-replace="#search-results"
                      s-swap="innerHTML transition:true" s-val:page="1">
                    {{ craft.searchIndex.stateInputs(_state, { exclude: ['sort', 'page'] }) }}
                    <label>Sort by
                        <select name="sort">
                            <option value="relevance" {{ sort == 'relevance' ? 'selected' }}>Relevance</option>
                            <option value="dateDesc" {{ sort == 'dateDesc' ? 'selected' }}>Newest</option>
                        </select>
                    </label>
                </form>

                {# Category facet checkboxes #}
                <form sprig s-include="this" s-trigger="change" s-replace="#search-results"
                      s-swap="innerHTML transition:true" s-val:page="1">
                    {{ craft.searchIndex.stateInputs(_state, { exclude: ['activeCategories', 'page'] }) }}
                    <fieldset>
                        <legend>Category</legend>
                        {% for facet in results.facets.category ?? [] %}
                            <label>
                                <input type="checkbox" name="activeCategories[]"
                                       value="{{ facet.value }}"
                                       {{ facet.value in activeCategories ? 'checked' }}>
                                {{ facet.value }} ({{ facet.count }})
                            </label>
                        {% endfor %}
                    </fieldset>
                </form>

                {# Active filter pills #}
                {% for cat in activeCategories %}
                    {% set _remaining = activeCategories|filter(c => c != cat) %}
                    <form class="d-inline">
                        {{ craft.searchIndex.stateInputs(_state|merge({ activeCategories: _remaining })) }}
                        <a sprig s-include="closest form" s-replace="#search-results"
                           s-swap="innerHTML transition:true"
                           href="{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({ category: _remaining|length ? _remaining : null })) }}">
                            {{ cat }} &times;
                        </a>
                    </form>
                {% endfor %}
            </aside>

            {# Main results #}
            <main>
                <p>{{ results.totalHits }} result{{ results.totalHits != 1 ? 's' }}
                    {% if q %} for "{{ q }}"{% endif %}
                    {% if activeCategories|length %} in {{ activeCategories|join(', ') }}{% endif %}
                </p>

                {% for hit in results.hits %}
                    <article>
                        <h2><a href="/{{ hit.uri }}">{{ hit.title }}</a></h2>
                    </article>
                {% endfor %}

                {# Pagination — hidden form carries state, links override page only #}
                {% if results.totalPages > 1 %}
                    <nav aria-label="Pages">
                        <form id="page-state" class="d-none">
                            {{ craft.searchIndex.stateInputs(_state, { exclude: 'page' }) }}
                        </form>

                        {% if results.page > 1 %}
                            <a sprig s-include="#page-state" s-val:page="{{ results.page - 1 }}"
                               s-replace="#search-results" s-swap="innerHTML transition:true"
                               href="{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({page: results.page - 1})) }}">
                                Previous
                            </a>
                        {% endif %}

                        {% for i in 1..results.totalPages %}
                            {% if i == results.page %}
                                <span aria-current="page">{{ i }}</span>
                            {% else %}
                                <a sprig s-include="#page-state" s-val:page="{{ i }}"
                                   s-replace="#search-results" s-swap="innerHTML transition:true"
                                   href="{{ craft.searchIndex.buildUrl('/search', i > 1 ? _urlParams|merge({page: i}) : _urlParams) }}">
                                    {{ i }}
                                </a>
                            {% endif %}
                        {% endfor %}

                        {% if results.page < results.totalPages %}
                            <a sprig s-include="#page-state" s-val:page="{{ results.page + 1 }}"
                               s-replace="#search-results" s-swap="innerHTML transition:true"
                               href="{{ craft.searchIndex.buildUrl('/search', _urlParams|merge({page: results.page + 1})) }}">
                                Next
                            </a>
                        {% endif %}
                    </nav>
                {% endif %}
            </main>
        </div>
    </div>
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

**Note:** All engines support empty queries for browse mode. The plugin automatically uses `match_all` for Elasticsearch/OpenSearch when the query string is empty.
