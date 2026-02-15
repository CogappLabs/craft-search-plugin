# GraphQL

The plugin registers a `searchIndex` query for headless search. All search features available in Twig -- facets, filters, sorting, highlighting, suggestions, and vector search -- are also available via GraphQL.

## Basic search

```graphql
{
  searchIndex(index: "places", query: "castle", perPage: 10, page: 1) {
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

## Search with field restriction

Restrict which indexed fields are searched:

```graphql
{
  searchIndex(index: "places", query: "castle", fields: ["title", "summary"]) {
    totalHits
    hits {
      objectID
      title
      _score
    }
  }
}
```

## Facets and filters

Request facet counts and apply filters. Filters are passed as a JSON string:

```graphql
{
  searchIndex(
    index: "articles"
    query: "technology"
    facets: ["category", "sectionHandle"]
    filters: "{\"category\":\"News\"}"
  ) {
    totalHits
    hits {
      objectID
      title
    }
    facets
  }
}
```

The `facets` field returns a JSON string with the normalised facet structure:

```json
{
  "category": [
    { "value": "News", "count": 24 },
    { "value": "Blog", "count": 18 }
  ],
  "sectionHandle": [
    { "value": "articles", "count": 35 }
  ]
}
```

## Sorting

Sort results by a field. The sort argument is a JSON string mapping field names to directions:

```graphql
{
  searchIndex(
    index: "articles"
    query: ""
    sort: "{\"postDate\":\"desc\"}"
    perPage: 10
  ) {
    totalHits
    hits {
      objectID
      title
    }
  }
}
```

## Highlighting

Enable hit highlighting to get matching fragments:

```graphql
{
  searchIndex(index: "articles", query: "architecture", highlight: true) {
    totalHits
    hits {
      objectID
      title
      _score
      _highlights
    }
  }
}
```

The `_highlights` field returns a JSON string with fragment arrays per field:

```json
{
  "title": ["Gothic <em>architecture</em> in Europe"],
  "body": ["The history of <em>architecture</em> spans..."]
}
```

## Suggestions ("Did you mean?")

Request spelling suggestions (Elasticsearch/OpenSearch only):

```graphql
{
  searchIndex(index: "articles", query: "architeture", suggest: true) {
    totalHits
    suggestions
    hits {
      objectID
      title
    }
  }
}
```

The `suggestions` field returns an array of alternative query strings, e.g. `["architecture"]`.

## Vector search

When a [Voyage AI API key](../configuration.md#integrations) is configured, generate semantic embeddings from the query:

```graphql
{
  searchIndex(
    index: "artworks"
    query: "impressionist landscapes"
    vectorSearch: true
    perPage: 10
  ) {
    totalHits
    hits {
      objectID
      title
      _score
    }
  }
}
```

Specify a model or target field:

```graphql
{
  searchIndex(
    index: "artworks"
    query: "sunset over water"
    vectorSearch: true
    voyageModel: "voyage-3"
    embeddingField: "description_embedding"
  ) {
    totalHits
    hits {
      objectID
      title
    }
  }
}
```

## Timing

Include overhead timing for performance debugging:

```graphql
{
  searchIndex(index: "places", query: "castle", includeTiming: true) {
    totalHits
    processingTimeMs
    totalTimeMs
    overheadTimeMs
    hits {
      objectID
      title
    }
  }
}
```

## Arguments reference

| Argument        | Type       | Default | Description                                                       |
|-----------------|------------|---------|-------------------------------------------------------------------|
| `index`         | `String!`  | —       | Index handle to search.                                           |
| `query`         | `String!`  | —       | Search query text.                                                |
| `perPage`       | `Int`      | `20`    | Results per page.                                                 |
| `page`          | `Int`      | `1`     | Page number (1-based).                                            |
| `fields`        | `[String]` | —       | Fields to search within.                                          |
| `sort`          | `String`   | —       | Sort as JSON object, e.g. `{"postDate":"desc"}`.                  |
| `facets`        | `[String]` | —       | Field names to return facet counts for.                           |
| `filters`       | `String`   | —       | Filters as JSON object, e.g. `{"category":"News"}`.               |
| `highlight`     | `Boolean`  | `false` | Enable hit highlighting.                                          |
| `suggest`       | `Boolean`  | `false` | Request spelling suggestions (ES/OpenSearch only).                |
| `vectorSearch`  | `Boolean`  | `false` | Generate a Voyage AI embedding for KNN search.                    |
| `voyageModel`   | `String`   | —       | Voyage AI model (default: `voyage-3`).                            |
| `embeddingField`| `String`   | —       | Target embedding field (auto-detected if omitted).                |
| `includeTiming` | `Boolean`  | `false` | Include `totalTimeMs` and `overheadTimeMs` in the response.      |

## Response types

### SearchResult

| Field             | Type            | Description                                          |
|-------------------|-----------------|------------------------------------------------------|
| `totalHits`       | `Int!`          | Total matching documents.                            |
| `page`            | `Int!`          | Current page (1-based).                              |
| `perPage`         | `Int!`          | Results per page.                                    |
| `totalPages`      | `Int!`          | Total number of pages.                               |
| `processingTimeMs`| `Int!`          | Engine processing time in milliseconds.              |
| `totalTimeMs`     | `Int`           | Total time including overhead (when `includeTiming`). |
| `overheadTimeMs`  | `Int`           | PHP overhead time (when `includeTiming`).            |
| `hits`            | `[SearchHit!]!` | Array of matching documents.                         |
| `facets`          | `String`        | Facet counts as JSON (when `facets` requested).      |
| `suggestions`     | `[String]`      | Spelling suggestions (when `suggest` is true).       |

### SearchHit

| Field         | Type     | Description                                          |
|---------------|----------|------------------------------------------------------|
| `objectID`    | `String` | Document ID.                                         |
| `title`       | `String` | Document title.                                      |
| `uri`         | `String` | Document URI.                                        |
| `_score`      | `Float`  | Relevance score (engine-dependent).                  |
| `_highlights` | `String` | Highlight fragments as JSON (when `highlight` is true). |

## Search Document field

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
