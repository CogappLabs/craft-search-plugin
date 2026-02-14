# Sprig Integration

[Sprig](https://putyourlightson.com/plugins/sprig) is a reactive component framework for Craft CMS that enables real-time UI updates without writing JavaScript. It pairs well with this plugin for building interactive search experiences.

These examples assume you have an index with handle `articles` containing `title`, `category`, `postDate`, and `uri` fields, with appropriate roles configured (title, url).

## Autocomplete

A search-as-you-type autocomplete input that shows suggestions after the user types 2+ characters.

### `_components/search-autocomplete.twig`

```twig
{# Sprig component: search autocomplete #}
{% set q = q ?? '' %}

<input sprig
       s-trigger="keyup changed delay:300ms"
       s-replace="#suggestions"
       type="search"
       name="q"
       value="{{ q }}"
       placeholder="Search..."
       autocomplete="off"
       aria-label="Search"
       aria-controls="suggestions">

<div id="suggestions" role="listbox">
    {% if q|length >= 2 %}
        {% set suggestions = craft.searchIndex.autocomplete('articles', q) %}

        {% if suggestions.totalHits > 0 %}
            {% for hit in suggestions.hits %}
                <a href="/{{ hit.uri }}" class="suggestion" role="option">
                    {{ hit.title }}
                </a>
            {% endfor %}

            {% if suggestions.totalHits > suggestions.perPage %}
                <a href="/search?q={{ q|url_encode }}" class="suggestion suggestion--more">
                    View all {{ suggestions.totalHits }} results
                </a>
            {% endif %}
        {% else %}
            <div class="suggestion suggestion--empty">No results found</div>
        {% endif %}
    {% endif %}
</div>
```

Include it in any template:

```twig
{{ sprig('_components/search-autocomplete') }}
```

### Key details

- `sprig` on the input makes it the reactive trigger element
- `s-trigger="keyup changed delay:300ms"` only fires when the value actually changes, with a 300ms debounce
- `s-replace="#suggestions"` swaps only the suggestions dropdown, keeping the input focused
- `craft.searchIndex.autocomplete()` defaults to 5 results searching only the title field

## Full Search with Pagination

A complete search page with query, paginated results, and result count.

### `_components/search-results.twig`

```twig
{# Sprig component: paginated search results #}
{% set q = q ?? '' %}
{% set page = page ?? 1 %}
{% set _perPage = 12 %}

<div sprig id="search-results">
    <form sprig s-vals='{"page": 1}'>
        <input type="search"
               name="q"
               value="{{ q }}"
               placeholder="Search articles..."
               aria-label="Search">
        <button type="submit">Search</button>
    </form>

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

## Complete Example: Search + Autocomplete + Filters + Sorting + Pagination

Combines all features into a single search experience.

### Page template: `templates/search.twig`

```twig
{% extends '_layouts/main' %}

{% block content %}
    <h1>Search</h1>

    {# Autocomplete input #}
    {{ sprig('_components/search-autocomplete') }}

    {# Full results (shown after form submission or direct URL) #}
    {{ sprig('_components/search-full', {
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
