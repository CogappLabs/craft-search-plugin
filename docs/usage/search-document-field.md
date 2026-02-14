# Search Document Field

The plugin provides a **Search Document** custom field type that lets editors pick a document from a search index. Useful for linking entries to specific search engine documents.

**Settings:** Select which index to search and the number of results per page.

**Editor UI:** A search input with autocomplete that queries the selected index. The selected document is stored as an index handle + document ID pair.

## Twig usage

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

### Basic card with role helpers

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

### Image transforms (e.g. with Imager X)

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

### Raw document access

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

### Linking to the source Craft entry

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

### Conditional rendering based on availability

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
