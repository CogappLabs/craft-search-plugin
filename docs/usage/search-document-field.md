# Search Document Field

The plugin provides a **Search Document** custom field type that lets editors pick a document from a search index. Useful for linking entries to specific search engine documents.

**Settings:** Select which index to search and the number of results per page.

**Editor UI:** A search input with autocomplete that queries the selected index. The selected document is stored as an index handle + document ID pair.

## Twig usage

Use the value object (`SearchDocumentValue`) for role-based helpers and document access.

Primary source references:

- API implementation: `src/fields/SearchDocumentValue.php`
- Role constants: `src/models/FieldMapping.php`

Most-used helpers:

- `getDocument()`
- `getEntry()` / `getEntryId()`
- `getTitle()`
- `getImage()` / `getImageUrl()` / `getAsset()`
- `getThumbnail()` / `getThumbnailUrl()`
- `getSummary()`
- `getUrl()`
- `getDate()`
- `getIiifInfoUrl()` / `getIiifImageUrl(width, height)`

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

### Thumbnail role helper

```twig
{% set thumb = entry.mySearchDocField.getThumbnail() %}
{% if thumb %}
    <img src="{{ thumb.getUrl({ width: 320, height: 200 }) }}" alt="{{ thumb.alt ?? entry.title }}">
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

### IIIF Image API

For indexes containing IIIF image URLs (common in museum/gallery collections), the `iiif` role provides helpers that generate IIIF Image API URLs:

```twig
{% set searchDoc = entry.mySearchDocField %}

{# Get the info.json URL #}
{% set infoUrl = searchDoc.getIiifInfoUrl() %}
{# → https://example.org/iiif/image123/info.json #}

{# Full-size image (no dimensions specified) #}
{% set imageUrl = searchDoc.getIiifImageUrl() %}
{# → https://example.org/iiif/image123/full/max/0/default.jpg #}

{# Width only (height auto-calculated) #}
{% set imageUrl = searchDoc.getIiifImageUrl(800) %}
{# → https://example.org/iiif/image123/full/800,/0/default.jpg #}

{# Width and height #}
{% set thumbUrl = searchDoc.getIiifImageUrl(800, 600) %}
{# → https://example.org/iiif/image123/full/800,600/0/default.jpg #}
```

#### `getIiifImageUrl()` parameters

| Parameter | Type       | Default | Description                                   |
|-----------|------------|---------|-----------------------------------------------|
| `width`   | `int|null` | `null`  | Image width in pixels. When omitted, `max` is used. |
| `height`  | `int|null` | `null`  | Image height in pixels. When omitted with a width, the height is auto-calculated. |

Region (`full`), rotation (`0`), quality (`default`), and format (`jpg`) are fixed.

The `iiif` role field should contain the IIIF Image API base URL (everything before `/info.json`).

## Known issues

### Focus loss on first keystroke (workaround applied)

When typing in the Search Document picker, htmx normally restores focus to elements with a matching `id` after a Sprig swap. However, this doesn't survive the **first** component-level `outerHTML` swap — the search input loses focus after the first character is entered. Subsequent swaps work fine.

Sprig's `s-preserve` attribute cannot help here either — it [does not preserve focus or caret position on text inputs](https://putyourlightson.com/plugins/sprig#s-preserve).

The plugin works around this in the JS bridge by detecting when the query input exists but doesn't have focus after a swap, and re-focusing it with the cursor at the end of the value.

<!-- Reference: src/web/assets/searchdocumentfield/src/search-document-field.ts (search for snippet:focus-workaround) -->

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
