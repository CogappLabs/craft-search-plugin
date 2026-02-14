# GraphQL

The plugin registers a `searchIndex` query for headless search:

```graphql
{
  searchIndex(index: "places", query: "castle", perPage: 10, page: 1, fields: ["title","summary"]) {
    totalHits
    page
    perPage
    totalPages
    processingTimeMs
    hits {
      objectID
      title
      uri
      _score
    }
  }
}
```

The Search Document custom field type also exposes its data via GraphQL:

```graphql
{
  entries(section: "places") {
    ... on places_default_Entry {
      mySearchDocField {
        indexHandle
        documentId
      }
    }
  }
}
```
