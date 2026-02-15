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

## Auto-derived `has_image` boolean

When an index has a field mapping with the **image** role, every indexed document automatically includes a `has_image` boolean. The value is `true` when the image field resolves to a non-empty value (e.g. an asset ID), `false` otherwise.

This enables faceting and filtering on whether an entry has an image without requiring a separate Craft field:

```twig
{# Filter to entries with images #}
{% set results = craft.searchIndex.search('content', query, {
    filters: { has_image: true },
}) %}

{# Use as a facet to show counts #}
{% set results = craft.searchIndex.search('content', query, {
    facets: ['has_image', 'sectionHandle'],
}) %}
```

For Typesense, `has_image` is declared as a facetable bool field in the schema automatically. Other engines (Elasticsearch, OpenSearch, Meilisearch, Algolia) handle the boolean type via dynamic mapping or auto-detection.
