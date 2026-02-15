# Field Resolvers

## Built-in Resolvers

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

## Matrix Sub-field Expansion

When a Matrix field is detected, the plugin expands it into individual sub-field rows in the field mapping UI. Each sub-field gets its own mapping with a compound index field name (`matrixHandle_subFieldHandle`), its own field type, weight, and enable/disable toggle. Sub-fields from all entry types within the Matrix are collected and de-duplicated by handle.

## Field Type Mapping

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
| `embedding`      | Vector embedding field (for semantic/vector search).       |
| `object`         | Nested/structured data.                                    |

These can be overridden per-mapping in the field mapping UI.
