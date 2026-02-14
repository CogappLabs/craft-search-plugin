# Filtering by Section & Entry Type

Every indexed document automatically includes `sectionHandle` and `entryTypeHandle` attributes. These are injected during indexing for all Entry elements, regardless of field mappings. You can use them to filter search results by section or entry type at the engine level:

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
