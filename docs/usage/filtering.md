# Filtering by Section & Entry Type

Every indexed document automatically includes `sectionHandle` and `entryTypeHandle` attributes. These are injected during indexing for all Entry elements, regardless of field mappings.

## Unified filtering (recommended)

Use the `filters` option to filter by any field -- the plugin translates this to the correct engine-native syntax automatically:

```twig
{# Filter by section — works with all engines #}
{% set results = craft.searchIndex.search('content', query, {
    filters: { sectionHandle: 'news' },
}) %}

{# Filter by entry type #}
{% set results = craft.searchIndex.search('content', query, {
    filters: { entryTypeHandle: 'blogPost' },
}) %}

{# Combine section filter with facet counts #}
{% set results = craft.searchIndex.search('content', query, {
    filters: { sectionHandle: 'news' },
    facets: ['entryTypeHandle', 'category'],
}) %}

{# Filter by multiple values (OR) #}
{% set results = craft.searchIndex.search('content', query, {
    filters: { sectionHandle: ['news', 'articles'] },
}) %}
```

See the [Twig documentation](twig.md#facets--filtering) for full facet and filter examples including building facet sidebar UIs with counts.

## Engine-native filtering (advanced)

You can also pass engine-native filter syntax directly. These take precedence over the unified `filters` option:

```twig
{# Elasticsearch/OpenSearch — filter by section #}
{% set results = craft.searchIndex.search('content', query, {
    body: {
        query: {
            bool: {
                must: { multi_match: { query: query, fields: ['title', 'summary'] } },
                filter: { term: { sectionHandle: 'news' } }
            }
        }
    }
}) %}

{# Meilisearch — filter by entry type #}
{% set results = craft.searchIndex.search('content', query, {
    filter: 'entryTypeHandle = blogPost'
}) %}

{# Typesense — filter by section #}
{% set results = craft.searchIndex.search('content', query, {
    filter_by: 'sectionHandle:news'
}) %}
```

For Typesense, `sectionHandle` and `entryTypeHandle` are declared as facetable string fields in the schema automatically.
