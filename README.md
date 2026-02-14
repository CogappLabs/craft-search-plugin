# Search Index for Craft CMS

<!-- badges placeholder -->

A UI-driven search index configuration plugin for Craft CMS 5 with multi-engine support. Define indexes, map fields, and sync content to external search engines -- all from the control panel.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Plugin Settings](#plugin-settings)
  - [Environment Variables](#environment-variables)
- [Usage](#usage)
  - [Control Panel](#control-panel)
  - [Console Commands](#console-commands)
  - [Twig](#twig)
  - [Filtering by Section & Entry Type](#filtering-by-section--entry-type)
  - [GraphQL](#graphql)
  - [Search Document Field](#search-document-field)
- [Field Resolvers](#field-resolvers)
  - [Built-in Resolvers](#built-in-resolvers)
  - [Matrix Sub-field Expansion](#matrix-sub-field-expansion)
  - [Field Type Mapping](#field-type-mapping)
- [Extending](#extending)
  - [Custom Engines](#custom-engines)
  - [Custom Field Resolvers](#custom-field-resolvers)
  - [Events](#events)
  - [Document Sync Events](#document-sync-events)
- [How It Works](#how-it-works)
  - [Atomic Swap (Zero-Downtime Refresh)](#atomic-swap-zero-downtime-refresh)
  - [Project Config Storage](#project-config-storage)
  - [Real-time Sync](#real-time-sync)
  - [Bulk Import and Orphan Cleanup](#bulk-import-and-orphan-cleanup)
- [Development](#development)
- [License](#license)

---

## Requirements

- PHP 8.2 or later
- Craft CMS 5.0 or later

## Installation

### From Packagist (recommended)

```bash
composer require cogapp/craft-search-index
```

### From GitHub

Add the repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/CogappLabs/craft-search-plugin.git"
        }
    ]
}
```

Then require the package:

```bash
composer require cogapp/craft-search-index:dev-main
```

### Local development (path repository)

For local plugin development, clone the repo alongside or inside your Craft project and add it as a path repository in your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./craft-search-index"
        }
    ]
}
```

Then require it (the `*@dev` constraint tells Composer to use the path symlink):

```bash
composer require cogapp/craft-search-index:*@dev
```

Composer will create a symlink in `vendor/cogapp/craft-search-index` pointing to your local copy. Changes you make to the plugin files take effect immediately.

**DDEV users:** If your plugin directory is outside the DDEV project root, create a symlink inside the project so the web container can access it:

```bash
ln -s /path/to/craft-search-index ./craft-search-index
```

### Activating the plugin

After installing, activate via the Craft control panel under **Settings > Plugins**, or via the CLI:

```bash
php craft plugin/install search-index
```

### Engine SDKs

The `elasticsearch/elasticsearch` package (`^8.0`) is a hard dependency and is installed automatically.

The other engine SDKs are optional. Install only the one(s) you need:

| Engine        | Package                                    | Install command                                        |
|---------------|--------------------------------------------|--------------------------------------------------------|
| Algolia       | `algolia/algoliasearch-client-php ^3.0 \|\| ^4.0` | `composer require algolia/algoliasearch-client-php`    |
| OpenSearch    | `opensearch-project/opensearch-php ^2.0`   | `composer require opensearch-project/opensearch-php`   |
| Meilisearch   | `meilisearch/meilisearch-php ^1.0`         | `composer require meilisearch/meilisearch-php`         |
| Typesense     | `typesense/typesense-php ^4.0 \|\| ^6.0`  | `composer require typesense/typesense-php`             |

## Configuration

### Plugin Settings

Settings are managed in the control panel at **Search Index > Settings** (or via `config/search-index.php` if you create a config file).

#### General

| Setting          | Type   | Default | Description                                             |
|------------------|--------|---------|---------------------------------------------------------|
| `syncOnSave`     | `bool` | `true`  | Automatically sync entries to the search index on save. |
| `indexRelations`  | `bool` | `true`  | Re-index related entries when relations change.         |
| `batchSize`      | `int`  | `500`   | Number of entries per bulk index queue job (1--5000).    |

#### Elasticsearch

| Setting                    | Type     | Default | Description                    |
|----------------------------|----------|---------|--------------------------------|
| `elasticsearchHost`       | `string` | `''`    | Elasticsearch host URL.        |
| `elasticsearchApiKey`     | `string` | `''`    | API key for authentication.    |
| `elasticsearchUsername`   | `string` | `''`    | Username for authentication.   |
| `elasticsearchPassword`   | `string` | `''`    | Password for authentication.   |

#### Algolia

| Setting               | Type     | Default | Description                |
|-----------------------|----------|---------|----------------------------|
| `algoliaAppId`        | `string` | `''`    | Algolia application ID.    |
| `algoliaApiKey`       | `string` | `''`    | Algolia admin API key.     |
| `algoliaSearchApiKey` | `string` | `''`    | Algolia search-only key.   |

#### OpenSearch

| Setting                 | Type     | Default | Description                  |
|-------------------------|----------|---------|------------------------------|
| `opensearchHost`       | `string` | `''`    | OpenSearch host URL.         |
| `opensearchUsername`   | `string` | `''`    | Username for authentication. |
| `opensearchPassword`   | `string` | `''`    | Password for authentication. |

#### Meilisearch

| Setting              | Type     | Default | Description            |
|----------------------|----------|---------|------------------------|
| `meilisearchHost`   | `string` | `''`    | Meilisearch host URL.  |
| `meilisearchApiKey` | `string` | `''`    | Meilisearch API key.   |

#### Typesense

| Setting              | Type     | Default  | Description                           |
|----------------------|----------|----------|---------------------------------------|
| `typesenseHost`     | `string` | `''`     | Typesense host URL.                   |
| `typesensePort`     | `string` | `'8108'` | Typesense port number.                |
| `typesenseProtocol` | `string` | `'http'` | Protocol (`http` or `https`).         |
| `typesenseApiKey`   | `string` | `''`     | Typesense API key.                    |

### Environment Variables

All engine connection settings support Craft's `$VARIABLE` syntax for environment variable resolution. This lets you keep credentials out of project config and vary them per environment:

```
# .env
ELASTICSEARCH_HOST=https://my-cluster.es.io:9200
ELASTICSEARCH_API_KEY=abc123
```

Then in plugin settings, enter `$ELASTICSEARCH_HOST` and `$ELASTICSEARCH_API_KEY`.

Per-index engine config fields (such as `indexPrefix`) also support environment variables.

## Usage

### Control Panel

The plugin adds a **Search Index** section to the control panel with two sub-navigation items:

**Indexes** -- Create, edit, delete, and manage search indexes. Each index is configured with:

- A **name** and **handle** (the handle becomes the index name in the search engine).
- An **engine** (Elasticsearch, Algolia, OpenSearch, Meilisearch, or Typesense).
- Per-engine **configuration** (e.g. index prefix), with environment variable support.
- **Sections** and/or **entry types** to include.
- An optional **site** restriction.
- An **enabled/disabled** toggle.

**Field Mappings** -- After creating an index, configure which fields are indexed. The field mapping UI provides:

- An auto-detected list of fields based on the selected entry types.
- Per-field **enable/disable** toggle.
- Customizable **index field name** (the key stored in the search engine document).
- **Field type** selection (text, keyword, integer, float, boolean, date, geo_point, facet, object).
- **Weight** control (1--10) for search relevance boosting.
- Matrix fields expand into individual sub-field rows for granular control.
- **Validate Fields** -- Tests field resolution against real entries without modifying the index. For each enabled field, finds an entry with data (deep sub-field lookup for Matrix blocks) and reports the resolved value, PHP type, and any type mismatches. Results can be copied as Markdown.
- **Semantic roles** -- Assign a role (title, image, summary, URL) to key fields. Roles power the `SearchDocumentValue` helper methods and are enforced one-per-index.
- **Re-detect Fields** -- Regenerates field mappings from the current entry type field layouts. A "fresh" re-detect discards existing settings; a normal re-detect preserves user customizations while refreshing field UIDs.

**Search** -- A built-in CP page for testing searches across indexes:

- **Single mode** -- Search one index and view results with title, URI, score, and raw document data.
- **Compare mode** -- Search multiple indexes simultaneously with side-by-side results.

**Settings** -- Global plugin settings for engine credentials, sync behavior, and batch size.

### Console Commands

All console commands accept an optional `handle` argument to target a specific index. When omitted, the command operates on all indexes.

```bash
# Show status of all indexes (connection, document count)
php craft search-index/index/status

# Full re-index: queues bulk import jobs for all entries
php craft search-index/index/import
php craft search-index/index/import myIndexHandle

# Flush and re-import (destructive refresh)
php craft search-index/index/refresh
php craft search-index/index/refresh myIndexHandle

# Clear all documents from an index
php craft search-index/index/flush
php craft search-index/index/flush myIndexHandle

# Re-detect field mappings (merge with existing settings)
php craft search-index/index/redetect
php craft search-index/index/redetect myIndexHandle

# Re-detect field mappings (fresh -- discard existing settings)
php craft search-index/index/redetect --fresh

# Validate field mappings against real entries
php craft search-index/index/validate
php craft search-index/index/validate myIndexHandle
php craft search-index/index/validate --format=json
php craft search-index/index/validate --only=issues

# Debug a search query (returns raw + normalised results as JSON)
php craft search-index/index/debug-search myIndexHandle "search query"
php craft search-index/index/debug-search myIndexHandle "search query" '{"perPage":10,"page":1}'

# Debug how a specific entry resolves field mappings
php craft search-index/index/debug-entry myIndexHandle "entry-slug"
php craft search-index/index/debug-entry myIndexHandle "entry-slug" "fieldName"
```

#### Validate

The `validate` command tests field resolution against real entries for each enabled field mapping -- the same logic used by the CP's **Validate Fields** button. For each field, it finds an entry with data, resolves it through the field mapper, and reports the resolved value, PHP type, and any type mismatches.

| Option     | Values              | Default    | Description                                 |
|------------|---------------------|------------|---------------------------------------------|
| `--format` | `markdown`, `json`  | `markdown` | Output format.                              |
| `--only`   | `all`, `issues`     | `all`      | Filter: `issues` shows only warnings/errors/nulls. |

#### Debug Search

The `debug-search` command executes a search query and outputs both the normalised `SearchResult` and the raw engine response as JSON. Useful for diagnosing search relevance, verifying field configuration, and comparing engine behaviour.

#### Debug Entry

The `debug-entry` command shows how a specific entry resolves each enabled field mapping. For each mapping, it displays the parent field, sub-field, resolver class, and resolved value. Useful for diagnosing why a particular entry's data isn't indexing as expected. Optionally pass a field name to inspect a single mapping.

After `import` or `refresh`, run the queue to process the jobs:

```bash
php craft queue/run
```

### Twig

The plugin registers a `craft.searchIndex` Twig variable with the following methods:

#### `craft.searchIndex.search(handle, query, options)`

Search an index and return a normalised `SearchResult` object. Results have the same shape regardless of which engine backs the index.

```twig
{% set results = craft.searchIndex.search('places', 'london', { perPage: 20, fields: ['title','summary'] }) %}

{% for hit in results.hits %}
    <p>{{ hit.title }} (score: {{ hit._score }})</p>
{% endfor %}

<p>Page {{ results.page }} of {{ results.totalPages }} ({{ results.totalHits }} total)</p>
```

**Search results page with pagination:**

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

**Unified pagination options:**

| Option    | Type  | Default | Description              |
|-----------|-------|---------|--------------------------|
| `page`    | `int` | `1`     | Page number (1-based).   |
| `perPage` | `int` | `20`    | Results per page.        |

Engine-native pagination keys (`from`/`size`, `offset`/`limit`, `hitsPerPage`, `per_page`) still work and take precedence if provided.

**Optional search field restriction:**

Pass a `fields` array in the options to limit which indexed fields are searched (engine support varies, but Elasticsearch/OpenSearch accept this).

**Normalised hit shape:**

Every hit in `results.hits` always contains these keys, regardless of engine:

| Key           | Type               | Description                                      |
|---------------|--------------------|--------------------------------------------------|
| `objectID`    | `string`           | The document ID.                                 |
| `_score`      | `float\|int\|null` | Relevance score (engine-dependent, may be null).  |
| `_highlights`  | `array`            | Highlight/snippet data.                          |

All original engine-specific fields on each hit are preserved alongside the normalised ones.

**SearchResult properties:**

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

#### `craft.searchIndex.multiSearch(searches)`

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

#### `craft.searchIndex.indexes`

Get all configured indexes.

```twig
{% set indexes = craft.searchIndex.indexes %}
{% for index in indexes %}
    <p>{{ index.name }} ({{ index.handle }})</p>
{% endfor %}
```

#### `craft.searchIndex.index(handle)`

Get a single index by handle.

```twig
{% set index = craft.searchIndex.index('places') %}
{% if index %}
    <p>{{ index.name }}</p>
{% endif %}
```

#### `craft.searchIndex.docCount(handle)`

Get the document count for an index.

```twig
<p>{{ craft.searchIndex.docCount('places') }} documents indexed</p>
```

#### `craft.searchIndex.getDocument(handle, documentId)`

Retrieve a single document from an index by its ID.

```twig
{% set doc = craft.searchIndex.getDocument('places', '12345') %}
{% if doc %}
    <p>{{ doc.title }} -- {{ doc.uri }}</p>
{% endif %}
```

#### `craft.searchIndex.isReady(handle)`

Check whether an index's engine is connected and the index exists.

```twig
{% if craft.searchIndex.isReady('places') %}
    {# safe to search #}
{% endif %}
```

### Filtering by Section & Entry Type

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

### GraphQL

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

### Search Document Field

The plugin provides a **Search Document** custom field type that lets editors pick a document from a search index. Useful for linking entries to specific search engine documents.

**Settings:** Select which index to search and the number of results per page.

**Editor UI:** A search input with autocomplete that queries the selected index. The selected document is stored as an index handle + document ID pair.

**Twig usage:**

The value object (`SearchDocumentValue`) provides:

- **`indexHandle`** -- The index handle (string).
- **`documentId`** -- The document ID (string).
- **`sectionHandle`** -- The Craft section handle (string, stored with the field value).
- **`entryTypeHandle`** -- The Craft entry type handle (string, stored with the field value).
- **`getDocument()`** -- Lazy-loads and caches the full document from the engine. Returns an associative array keyed by index field names.
- **`getEntry()`** -- Returns the Craft `Entry` element by ID (when `documentId` is a numeric Craft entry ID). Useful for linking directly to the source entry in templates.
- **`getEntryId()`** -- Returns the document ID as an integer if it's numeric, or `null` otherwise.
- **`getTitle()`** -- Returns the value of the field with the `title` role.
- **`getImage()`** -- Returns a full Craft `Asset` element for the field with the `image` role (the index stores the asset ID). Gives templates access to transforms, alt text, focal points, and all other asset methods.
- **`getAsset()`** -- Alias for `getImage()`. Returns the Craft `Asset` element for the image role.
- **`getImageUrl()`** -- Convenience shortcut: returns the asset URL string (equivalent to `getImage().getUrl()`).
- **`getSummary()`** -- Returns the value of the field with the `summary` role.
- **`getUrl()`** -- Returns the value of the field with the `url` role.

**Basic card with role helpers:**

```twig
{% set searchDoc = entry.mySearchDocField %}
{% if searchDoc and searchDoc.documentId %}
    {% set title = searchDoc.getTitle() ?? 'Linked document' %}
    {% set image = searchDoc.getImage() %}
    {% set summary = searchDoc.getSummary() %}
    {% set url = searchDoc.getUrl() %}

    <article class="card">
        {% if image %}
            <img src="{{ image.getUrl() }}" alt="{{ image.alt ?? title }}">
        {% endif %}
        <h3>
            {% if url %}<a href="/{{ url }}">{% endif %}
            {{ title }}
            {% if url %}</a>{% endif %}
        </h3>
        {% if summary %}<p>{{ summary }}</p>{% endif %}
    </article>
{% endif %}
```

**Image transforms (e.g. with Imager X):**

Since `getImage()` returns a real Craft Asset, you can use any image transform plugin:

```twig
{% set image = entry.mySearchDocField.getImage() %}
{% if image %}
    {# Craft native transforms #}
    <img src="{{ image.getUrl({ width: 400, height: 300 }) }}" alt="{{ image.alt }}">

    {# Imager X named transforms #}
    {% include 'components/picture' with {
        transformName: 'card',
        image: image,
        altText: image.alt ?? entry.title,
    } only %}

    {# Imager X inline transforms #}
    {% set transformed = craft.imagerx.transformImage(image, { width: 800 }) %}
    <img src="{{ transformed.url }}" alt="{{ image.alt }}">
{% endif %}
```

**Raw document access:**

For fields that don't have a role assigned, access the document directly:

```twig
{% set doc = entry.mySearchDocField.getDocument() %}
{% if doc %}
    <dl>
        <dt>Status</dt>
        <dd>{{ doc.status }}</dd>
        <dt>Slug</dt>
        <dd>{{ doc.slug }}</dd>
        <dt>Custom field</dt>
        <dd>{{ doc.myCustomField ?? 'N/A' }}</dd>
    </dl>
{% endif %}
```

**Linking to the source Craft entry:**

When the document ID corresponds to a Craft entry ID (the default for synced indexes), `getEntry()` returns the full Entry element:

```twig
{% set searchDoc = entry.mySearchDocField %}
{% if searchDoc and searchDoc.getEntry() %}
    {% set linkedEntry = searchDoc.getEntry() %}
    <p>
        From section: {{ searchDoc.sectionHandle }} / {{ searchDoc.entryTypeHandle }}<br>
        Entry: <a href="{{ linkedEntry.url }}">{{ linkedEntry.title }}</a><br>
        Posted: {{ linkedEntry.postDate|date('d M Y') }}
    </p>
{% endif %}
```

**Conditional rendering based on availability:**

```twig
{% set searchDoc = entry.mySearchDocField %}
{% if searchDoc and searchDoc.documentId %}
    {% set doc = searchDoc.getDocument() %}
    {% if doc %}
        {# Document exists in the search engine #}
    {% else %}
        {# Document ID {{ searchDoc.documentId }} not found -- may have been removed #}
    {% endif %}
{% endif %}
```

## Field Resolvers

### Built-in Resolvers

The plugin ships with 11 typed field resolvers (plus an attribute resolver for element attributes like `title`, `slug`, `uri`):

| Resolver         | Handles                                                                                         |
|------------------|-------------------------------------------------------------------------------------------------|
| PlainText        | Plain Text, Email, URL, Link, Color, Country                                                   |
| RichText         | CKEditor (auto-detected when `craft\ckeditor\Field` is present)                                |
| Number           | Number, Range, Money                                                                            |
| Date             | Date, Time                                                                                      |
| Boolean          | Lightswitch                                                                                     |
| Options          | Dropdown, Radio Buttons, Button Group, Checkboxes, Multi-select                                 |
| Relation         | Entries, Categories, Tags, Users                                                                |
| Asset            | Assets (default: stores asset ID as integer; configurable via `mode` resolver config)           |
| Address          | Addresses                                                                                       |
| Table            | Table                                                                                           |
| Matrix           | Matrix (when indexed as a single field rather than expanded sub-fields)                         |
| Attribute        | Element attributes: `title`, `slug`, `postDate`, `dateCreated`, `dateUpdated`, `uri`, `status` |

### Matrix Sub-field Expansion

When a Matrix field is detected, the plugin expands it into individual sub-field rows in the field mapping UI. Each sub-field gets its own mapping with a compound index field name (`matrixHandle_subFieldHandle`), its own field type, weight, and enable/disable toggle. Sub-fields from all entry types within the Matrix are collected and de-duplicated by handle.

### Field Type Mapping

Each field is assigned a default **index field type** based on its Craft field class:

| Index Field Type | Description                                                |
|------------------|------------------------------------------------------------|
| `text`           | Full-text searchable content.                              |
| `keyword`        | Exact-match strings (URLs, slugs, status values).          |
| `integer`        | Integer numeric values.                                    |
| `float`          | Floating-point numeric values.                             |
| `boolean`        | True/false values.                                         |
| `date`           | Date/time values.                                          |
| `geo_point`      | Geographic coordinates.                                    |
| `facet`          | Multi-value fields used for filtering (categories, tags).  |
| `object`         | Nested/structured data.                                    |

These can be overridden per-mapping in the field mapping UI.

## Extending

### Custom Engines

Register additional search engines by listening to the `EVENT_REGISTER_ENGINE_TYPES` event on `IndexesController`:

```php
use cogapp\searchindex\controllers\IndexesController;
use cogapp\searchindex\events\RegisterEngineTypesEvent;
use yii\base\Event;

Event::on(
    IndexesController::class,
    IndexesController::EVENT_REGISTER_ENGINE_TYPES,
    function(RegisterEngineTypesEvent $event) {
        $event->types[] = \myplugin\engines\MyCustomEngine::class;
    }
);
```

Your engine class must implement `cogapp\searchindex\engines\EngineInterface`. You can extend `cogapp\searchindex\engines\AbstractEngine` for a head start, which provides environment variable parsing, index name prefixing, and default batch method implementations.

The interface requires these methods:

```php
// Lifecycle
public function createIndex(Index $index): void;
public function updateIndexSettings(Index $index): void;
public function deleteIndex(Index $index): void;
public function indexExists(Index $index): bool;

// Document CRUD
public function indexDocument(Index $index, int $elementId, array $document): void;
public function indexDocuments(Index $index, array $documents): void;
public function deleteDocument(Index $index, int $elementId): void;
public function deleteDocuments(Index $index, array $elementIds): void;
public function flushIndex(Index $index): void;

// Search
public function search(Index $index, string $query, array $options = []): SearchResult;
public function multiSearch(array $queries): array;
public function getDocumentCount(Index $index): int;
public function getAllDocumentIds(Index $index): array;

// Schema
public function mapFieldType(string $indexFieldType): mixed;
public function buildSchema(array $fieldMappings): array;

// Atomic swap
public function supportsAtomicSwap(): bool;
public function swapIndex(Index $index, Index $swapIndex): void;

// Info
public static function displayName(): string;
public static function configFields(): array;
public function testConnection(): bool;
```

### Custom Field Resolvers

Register additional field resolvers by listening to the `EVENT_REGISTER_FIELD_RESOLVERS` event on `FieldMapper`:

```php
use cogapp\searchindex\services\FieldMapper;
use cogapp\searchindex\events\RegisterFieldResolversEvent;
use yii\base\Event;

Event::on(
    FieldMapper::class,
    FieldMapper::EVENT_REGISTER_FIELD_RESOLVERS,
    function(RegisterFieldResolversEvent $event) {
        // Map a Craft field class to your resolver class
        $event->resolvers[\myplugin\fields\MyField::class] = \myplugin\resolvers\MyFieldResolver::class;
    }
);
```

Your resolver must implement `cogapp\searchindex\resolvers\FieldResolverInterface`:

```php
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\resolvers\FieldResolverInterface;
use craft\base\Element;
use craft\base\FieldInterface;

class MyFieldResolver implements FieldResolverInterface
{
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        // Extract and return the indexable value
        $value = $element->getFieldValue($field->handle);
        return (string) $value;
    }

    public static function supportedFieldTypes(): array
    {
        return [\myplugin\fields\MyField::class];
    }
}
```

### Events

#### `FieldMapper::EVENT_REGISTER_FIELD_RESOLVERS`

Fired when building the resolver map. Use this to add or override field resolvers.

- **Event class:** `cogapp\searchindex\events\RegisterFieldResolversEvent`
- **Property:** `resolvers` -- `array<string, string>` mapping field class names to resolver class names.

#### `FieldMapper::EVENT_BEFORE_INDEX_ELEMENT`

Fired after a document is resolved but before it is sent to the engine. Use this to modify the document data.

- **Event class:** `cogapp\searchindex\events\ElementIndexEvent`
- **Properties:** `element` (the Craft element), `index` (the Index model), `document` (the resolved document array -- mutable).

```php
use cogapp\searchindex\services\FieldMapper;
use cogapp\searchindex\events\ElementIndexEvent;
use yii\base\Event;

Event::on(
    FieldMapper::class,
    FieldMapper::EVENT_BEFORE_INDEX_ELEMENT,
    function(ElementIndexEvent $event) {
        // Add a custom field to every document
        $event->document['customField'] = 'custom value';
    }
);
```

#### `IndexesController::EVENT_REGISTER_ENGINE_TYPES`

Fired when building the list of available engine types. Use this to register custom engines.

- **Event class:** `cogapp\searchindex\events\RegisterEngineTypesEvent`
- **Property:** `types` -- `string[]` array of engine class names.

#### Index Lifecycle Events

Fired by the `Indexes` service during index save/delete operations:

| Constant                                | When                            | Event class                              |
|-----------------------------------------|---------------------------------|------------------------------------------|
| `Indexes::EVENT_BEFORE_SAVE_INDEX`      | Before an index is saved.       | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_AFTER_SAVE_INDEX`       | After an index is saved.        | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_BEFORE_DELETE_INDEX`    | Before an index is deleted.     | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_AFTER_DELETE_INDEX`     | After an index is deleted.      | `cogapp\searchindex\events\IndexEvent`   |

#### Document Sync Events

Fired by the `Sync` service after documents are indexed or deleted. Use these to trigger side effects (e.g. cache invalidation, analytics, webhooks).

| Constant                              | When                                          | Fired from            |
|---------------------------------------|-----------------------------------------------|-----------------------|
| `Sync::EVENT_AFTER_INDEX_ELEMENT`     | After a single document is indexed.           | `IndexElementJob`     |
| `Sync::EVENT_AFTER_DELETE_ELEMENT`    | After a single document is deleted.           | `DeindexElementJob`   |
| `Sync::EVENT_AFTER_BULK_INDEX`        | After a batch of documents is indexed.        | `BulkIndexJob`        |

- **Event class:** `cogapp\searchindex\events\DocumentSyncEvent`
- **Properties:** `index` (Index model), `elementId` (int), `action` (`'upsert'` or `'delete'`).

```php
use cogapp\searchindex\services\Sync;
use cogapp\searchindex\events\DocumentSyncEvent;
use yii\base\Event;

Event::on(
    Sync::class,
    Sync::EVENT_AFTER_INDEX_ELEMENT,
    function(DocumentSyncEvent $event) {
        // $event->index — the Index model
        // $event->elementId — the Craft element ID
        // $event->action — 'upsert'
    }
);
```

## How It Works

### Project Config Storage

Index definitions and their field mappings are stored in Craft's **Project Config** (`config/project/searchIndex/`). The database tables (`searchindex_indexes`, `searchindex_field_mappings`) serve as a runtime cache and are rebuilt from project config during `project-config/apply`. This means index configuration is version-controlled and portable across environments.

### Real-time Sync

When `syncOnSave` is enabled, the plugin listens to element lifecycle events:

- **`EVENT_AFTER_SAVE_ELEMENT`** -- If the saved entry is live, an `IndexElementJob` is queued. If the entry is disabled, expired, or future-dated, a `DeindexElementJob` removes it from the index.
- **`EVENT_AFTER_RESTORE_ELEMENT`** -- Restored entries are re-indexed.
- **`EVENT_BEFORE_DELETE_ELEMENT`** -- Deleted entries are removed from all matching indexes.
- **`EVENT_AFTER_UPDATE_SLUG_AND_URI`** -- Entries with changed URIs are re-indexed.

When `indexRelations` is enabled, saving or deleting any element triggers a relation cascade: all entries related to the changed element are found and re-indexed. This ensures that, for example, renaming a category updates every entry that references it. Duplicate queue jobs within the same request are automatically deduplicated.

### Bulk Import and Orphan Cleanup

The `import` and `refresh` console commands queue `BulkIndexJob` instances in batches (controlled by `batchSize`). After all bulk jobs are queued, a `CleanupOrphansJob` is appended to remove stale documents from the engine that no longer correspond to live entries. The cleanup job uses the engine's `getAllDocumentIds()` method to compare engine state against the current Craft entries.

### Atomic Swap (Zero-Downtime Refresh)

When running `php craft search-index/index/refresh`, engines that support atomic swap perform a zero-downtime reindex:

1. A temporary index (`{handle}_swap`) is created.
2. All documents are bulk-imported into the temporary index.
3. The temporary and production indexes are swapped atomically.
4. The old temporary index is deleted.

During the swap, the production index remains fully searchable. There is no window where queries return empty results.

| Engine        | Atomic swap | Method                        |
|---------------|-------------|-------------------------------|
| Meilisearch   | Yes         | Native `swapIndexes()` API    |
| Elasticsearch | No          | Standard flush + re-import    |
| OpenSearch    | No          | Standard flush + re-import    |
| Algolia       | No          | Standard flush + re-import    |
| Typesense     | No          | Standard flush + re-import    |

Engines without atomic swap support fall back to the standard refresh behavior (delete index, recreate, re-import). Custom engines can opt in by implementing `supportsAtomicSwap()` and `swapIndex()` on the engine interface.

## Connecting to a Craft Project for Testing

The plugin's DDEV config provides the search engine services, but to test against real content you need to connect it to a Craft project. There are two approaches:

### Option 1: Path repository (recommended)

Add the plugin as a path repository in your Craft project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../craft-search-index"
        }
    ]
}
```

Then require it:

```bash
composer require cogapp/craft-search-index:*@dev
```

Composer creates a symlink in `vendor/cogapp/craft-search-index`, so code changes take effect immediately.

### Option 2: DDEV add-on services in your Craft project

If your Craft project uses DDEV, you can add the search engine services directly to it. Copy the relevant service definitions from the plugin's `.ddev/docker-compose.*.yaml` files into your Craft project's `.ddev/` directory.

### Connecting your Craft project to the plugin's search engines

When the plugin is installed in a separate DDEV project, your Craft project needs to reach the search engine containers. Since DDEV containers share a Docker network, you can reference them by their DDEV project name:

| Engine        | Host from another DDEV project                    | Port  | Auth                        |
|---------------|---------------------------------------------------|-------|-----------------------------|
| Elasticsearch | `ddev-craft-search-index-elasticsearch`            | 9200  | None                        |
| OpenSearch    | `ddev-craft-search-index-opensearch`               | 9200  | None                        |
| Meilisearch   | `ddev-craft-search-index-meilisearch`              | 7700  | Key: `ddev_meilisearch_key` |
| Typesense     | `ddev-craft-search-index-typesense`                | 8108  | Key: `ddev_typesense_key`   |

In your Craft project's `.env`:

```
# Meilisearch (running in the plugin's DDEV project)
MEILISEARCH_HOST=http://ddev-craft-search-index-meilisearch:7700
MEILISEARCH_API_KEY=ddev_meilisearch_key

# Typesense
TYPESENSE_HOST=ddev-craft-search-index-typesense
TYPESENSE_PORT=8108
TYPESENSE_PROTOCOL=http
TYPESENSE_API_KEY=ddev_typesense_key

# Elasticsearch
ELASTICSEARCH_HOST=http://ddev-craft-search-index-elasticsearch:9200

# OpenSearch
OPENSEARCH_HOST=http://ddev-craft-search-index-opensearch:9200
```

Then in the plugin settings, use `$MEILISEARCH_HOST`, `$MEILISEARCH_API_KEY`, etc.

### Testing workflow

1. Install the plugin in your Craft project (path repository or Composer)
2. Configure engine credentials in **Settings > Search Index**
3. Create an index: **Search Index > Indexes > New Index**
4. Select sections, entry types, and site
5. Click **Re-detect Fields** to auto-generate field mappings
6. Review and adjust mappings, then **Save Mappings**
7. Click **Validate Fields** to test resolution against real entries
8. Run a bulk import: `php craft search-index/index/import --index=your_handle`
9. Process the queue: `php craft queue/run`
10. Test searches on the **Search** CP page or via Twig/GraphQL

## Development

### DDEV Setup

The plugin ships with a DDEV configuration that provides PHP and all four local search engines:

```bash
ddev start
```

This starts:

| Service         | Image                                    | Internal host:port     |
|-----------------|------------------------------------------|------------------------|
| Elasticsearch   | `elasticsearch:8.17.0`                   | `elasticsearch:9200`   |
| OpenSearch      | `opensearchproject/opensearch:2.19.1`    | `opensearch:9200`      |
| Meilisearch     | `getmeili/meilisearch:v1.13`             | `meilisearch:7700`     |
| Typesense       | `typesense/typesense:30.1`               | `typesense:8108`       |

Dev credentials: Meilisearch key `ddev_meilisearch_key`, Typesense key `ddev_typesense_key`. Elasticsearch and OpenSearch have security disabled.

### Tests

```bash
# Unit tests (default -- no services required)
ddev exec vendor/bin/phpunit

# Integration tests (requires DDEV services running)
ddev exec vendor/bin/phpunit --testsuite integration

# All tests
ddev exec vendor/bin/phpunit --testsuite unit,integration
```

Unit tests cover models, schema building, and the `SearchResult` DTO. Integration tests create real indexes, seed documents, search, and verify the normalised response shape across all four local engines. If the services aren't reachable, integration tests skip gracefully.

### Code Quality

```bash
# Coding standards (ECS)
ddev exec vendor/bin/ecs check
ddev exec vendor/bin/ecs check --fix

# Static analysis (PHPStan, level 0)
ddev exec vendor/bin/phpstan --memory-limit=1G

# Rector (automated refactoring, dry-run)
ddev exec vendor/bin/rector process src --dry-run
```

Or using Composer scripts:

```bash
ddev exec composer test
ddev exec composer check-cs
ddev exec composer fix-cs
ddev exec composer phpstan
```

## License

This plugin is licensed under the [MIT License](LICENSE).
