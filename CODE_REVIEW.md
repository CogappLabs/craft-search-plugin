# Code Review -- craft-search-plugin

**Date:** 2026-02-15
**Scope:** Full codebase review covering engines, models, services, controllers, GraphQL, Sprig components, field resolvers, queue jobs, events, and tests.

---

## Executive Summary

The codebase is well-architected with clean separation of concerns, consistent naming conventions, a well-designed engine abstraction layer, and solid defensive patterns (CSRF protection, CP request gating, read-only index guards). The plugin supports five search engines with a unified API, which is a significant design achievement.

That said, the review identified **82 issues** across the codebase. The most impactful findings cluster around:

1. **`multiSearch()` is incomplete across all engines** -- facets are missing/raw, unified options are not translated, and vector search is not resolved.
2. **Security gaps in filter string construction** -- Algolia, Meilisearch, and Typesense have injectable or under-escaped filter expressions.
3. **GraphQL has no authorization scoping** -- any GQL token can query any index.
4. **Resolver test coverage is zero** -- all 13 resolvers have no unit tests despite being the core data transformation layer.
5. **`handleElementDelete()` ignores the `syncOnSave` setting** -- inconsistent with `handleElementSave()`.
6. **Queue jobs swallow errors** -- `IndexElementJob` catches exceptions silently, preventing retries for transient failures.

---

## High Severity Issues

### H1. Algolia `getDocument()` filter injection
**File:** `src/engines/AlgoliaEngine.php:430`

The document ID is interpolated directly into an Algolia filter expression without quoting or escaping. A malformed ID containing Algolia filter syntax (e.g., `" OR title:secret"`) could manipulate query logic.

```php
'filters' => 'objectID:' . $documentId,
```

**Fix:** Quote and escape: `'objectID:"' . addcslashes($documentId, '"\\') . '"'`

---

### H2. `multiSearch()` facets not normalised (ElasticCompatEngine)
**File:** `src/engines/ElasticCompatEngine.php:754`

Single `search()` normalises ES aggregations to `{ field: [{value, count}] }`. `multiSearch()` passes raw ES aggregation structure (with `buckets`, `key`, `doc_count` keys) without normalisation. Consumers expecting the unified shape get incompatible data.

---

### H3. `multiSearch()` facets not normalised (TypesenseEngine)
**File:** `src/engines/TypesenseEngine.php:694`

Same issue as H2. Single `search()` normalises Typesense `facet_counts`, but `multiSearch()` passes the raw format through.

---

### H4. `_all` field reference in ES suggestions (removed in ES 6+)
**File:** `src/engines/ElasticCompatEngine.php:582`

The `_all` field was removed in Elasticsearch 6.0. When `suggest: true` is used with default fields (`['*']`), the suggestion query references `_all`, causing a server-side error. This is the common/default code path.

Additionally, the `.trigram` sub-field referenced on the same line is never created in `buildSchema()`.

---

### H5. Embedding schema missing dimension parameter
**File:** `src/engines/ElasticCompatEngine.php:836-871`

`buildSchema()` sets the type for embedding fields but does not add the required `dimension` parameter. Both ES `dense_vector` and OpenSearch `knn_vector` require dimensions. Index creation will fail for any index with an embedding field.

---

### H6. GraphQL resolver lacks authorization scope checks
**File:** `src/gql/resolvers/SearchResolver.php`

GQL queries are registered via `EVENT_REGISTER_GQL_QUERIES` without any `EVENT_REGISTER_GQL_SCHEMA_COMPONENTS`. The resolver performs no token scope validation. Any GraphQL token (even minimal scope) can query any search index by handle.

---

### H7. `handleElementDelete()` ignores `syncOnSave` setting
**File:** `src/services/Sync.php:124-138`

`handleElementSave()` checks `$settings->syncOnSave` and returns early when disabled. `handleElementDelete()` has no such check -- it always queues `DeindexElementJob`. When a site disables sync, element saves do nothing but deletes still push jobs. The relation re-indexing path (line 142) does check `syncOnSave`, making the inconsistency even more apparent.

---

### H8. Zero unit tests for all 13 resolver classes
**Affected:** All files in `src/resolvers/`

Resolvers are the core data transformation layer converting Craft field values into search engine documents. Edge cases identified in this review (Money objects, HTML entities, geo_point key mismatches, Matrix sub-field arrays) are all completely untested. These are pure transformation functions ideal for unit testing.

---

## Medium Severity Issues

### M1. `multiSearch()` facets omitted entirely (Algolia, Meilisearch)
**Files:** `src/engines/AlgoliaEngine.php:578`, `src/engines/MeilisearchEngine.php:555`

The `facets` parameter is omitted from the `SearchResult` constructor in `multiSearch()`. Single `search()` normalises and passes facets correctly.

---

### M2. `multiSearch()` page calculation wrong (ElasticCompatEngine)
**File:** `src/engines/ElasticCompatEngine.php:744-745`

When building the request, `from` is calculated as `($page - 1) * $perPage`. When constructing the result, `$from` is read from the original `$options` (not the computed value), defaulting to `0`. The page number in `multiSearch()` responses is always `1` when using unified pagination.

---

### M3. Meilisearch `deleteDocument`/`deleteDocuments` type mismatch
**File:** `src/engines/MeilisearchEngine.php:340, 358`

The primary key is `objectID` (string), but `deleteDocument()` passes raw `int $elementId`. The Meilisearch SDK may not match string `"123"` when given integer `123`. Algolia correctly uses `array_map('strval', ...)`.

---

### M4. Typesense `_coerceDocumentValues()` ignores `object` type fields
**File:** `src/engines/TypesenseEngine.php:896-915`

When the schema says a field is `object` type, nested array values are still JSON-encoded into a string. The method never checks `$expectedType === 'object'` to preserve the array as-is.

---

### M5. Unnecessary index close/open for `putMapping`
**File:** `src/engines/ElasticCompatEngine.php:79-88`

`putMapping` does not require closing the index. Closing makes it unavailable for search during the update. If the `open()` call in the `finally` block fails (network error), the index remains permanently closed.

---

### M6. `multiSearch()` does not translate unified options (all engines)
**Files:** All engine `multiSearch()` implementations

All engines only extract pagination in `multiSearch()`. They do not apply unified `facets`, `filters`, `sort`, `highlight`, `attributesToRetrieve`, or `suggest` transformations. Using unified options on `multiSearch()` silently produces different behavior than calling `search()`.

---

### M7. Meilisearch filter string escaping is incomplete
**File:** `src/engines/MeilisearchEngine.php:448-451`

Only double quotes are escaped. A value containing a literal backslash before a quote (`\"`) becomes `\\"` which terminates the string prematurely. Backslashes should be escaped first, then quotes.

---

### M8. Algolia `indexDocuments()` allows documents without `objectID`
**File:** `src/engines/AlgoliaEngine.php:364-371`

Documents without `objectID` are sent to Algolia, which auto-generates IDs. This breaks the convention that `objectID` always corresponds to the Craft element ID. ES and Typesense correctly skip such documents.

---

### M9. Bulk indexing errors logged but never thrown (ElasticCompatEngine)
**File:** `src/engines/ElasticCompatEngine.php:366-377`

Bulk indexing failures are logged as warnings but never thrown or returned. Callers have no way to detect partial failures. Documents silently fail to index during large imports.

---

### M10. `Index::getConfig()` can lose field mappings when UIDs are null
**File:** `src/models/Index.php:172-175`

`array_combine()` uses `FieldMapping::$uid` as keys. If two mappings have null UIDs, both coerce to empty string and the second silently overwrites the first.

---

### M11. Strict `in_array` type mismatch on section/type IDs
**File:** `src/services/Indexes.php:172-173`

`$index->sectionIds` may contain strings from JSON/YAML. With `strict: true`, `in_array(3, ["3", "5"], true)` returns `false`, meaning entries silently stop matching indexes.

**Fix:** `in_array($sectionId, array_map('intval', $index->sectionIds), true)`

---

### M12. Shared try/catch for three independent JSON decodes
**File:** `src/services/Indexes.php:413-425`

If `engineConfig` decodes successfully but `sectionIds` throws, `entryTypeIds` remains as a raw JSON string rather than being decoded.

---

### M13. Atomic swap counter stored in volatile cache
**File:** `src/services/Sync.php:331`

The swap batch counter uses Craft's application cache (Redis/Memcached/file). If cache is cleared during a long import, the swap never completes and the temporary index becomes orphaned with no recovery mechanism.

---

### M14. Unbounded related-entry reindex query
**File:** `src/services/Sync.php:522-529`

`limit(null)` loads ALL related entries into memory. A category used by thousands of entries could exhaust memory on a single save event. Consider `->batch(100)` or `->ids()`.

---

### M15. `objectID` set as integer in document payload
**File:** `src/services/FieldMapper.php:507`

Per convention, `objectID` should be string. The document passed to `EVENT_BEFORE_INDEX_ELEMENT` contains an integer. Also, `$element->id` can be null for unsaved elements.

---

### M16. Duplicated helper methods between FieldMapper and FieldMappingValidator
**File:** `src/services/FieldMapper.php:~763` and `src/services/FieldMappingValidator.php:~426`

`_extractSubFieldHandle()` and `_findSubFieldByHandle()` contain identical logic in both classes. If one is updated without the other, behavior diverges.

---

### M17. N+1 query pattern in sub-field validation
**File:** `src/services/FieldMappingValidator.php:359-416`

Fetches up to 20 candidates, then for each triggers Matrix element queries and block field lookups. Can cause dozens of DB queries.

---

### M18. SearchController exposes raw engine response to all CP users
**File:** `src/controllers/SearchController.php:122`

`raw` response (containing cluster metadata, shard info, internal hostnames) is returned in JSON. Controller only requires `requireCpRequest()` with no permission check. Any authenticated CP user can access this.

---

### M19. `processingTimeMs` declared non-null in GraphQL but can be null
**File:** `src/gql/types/SearchResultType.php:35`

`Type::nonNull(Type::int())` but engines may not report timing. Causes GraphQL runtime errors.

**Fix:** Use `Type::int()` (nullable) or coerce: `$result->processingTimeMs ?? 0`

---

### M20. `debug-entry` command calls `resolveElement()` N times in loop
**File:** `src/console/controllers/IndexController.php:599`

Inside the per-field-mapping loop, `resolveElement()` is called on every iteration, resolving the entire document repeatedly. Should be moved before the loop.

---

### M21. Missing route params on field mapping save failure
**File:** `src/controllers/FieldMappingsController.php`

When `saveIndex()` fails, `setRouteParams` is not called, so the user loses unsaved form data. Compare with `IndexesController::actionSave()` which handles this correctly.

---

### M22. SearchHitType only exposes 5 fixed fields in GraphQL
**File:** `src/gql/types/SearchHitType.php:30-39`

Only `objectID`, `title`, `uri`, `_score`, `_highlights` are exposed. Custom index fields are inaccessible via GraphQL, making the API nearly unusable for real applications. Consider adding a `data` JSON scalar field.

---

### M23. `multiSearch()` in SearchIndexVariable does not resolve vector search
**File:** `src/variables/SearchIndexVariable.php:219-287`

Does not check for `vectorSearch: true` in individual query options. Users get text search instead of vector search, with no error.

---

### M24. `doSearch` conditional has dead code
**File:** `src/variables/SearchIndexVariable.php:633-636`

`$doSearch !== 1 && $doSearch !== '1'` are dead conditions. The logic is functionally equivalent to `if (!$doSearch)`.

---

### M25. IndexElementJob errors swallowed, preventing queue retries
**File:** `src/jobs/IndexElementJob.php:78-88`

`try/catch(\Throwable)` logs the error but completes the job "successfully". Transient failures (network timeouts, rate limiting) are never retried. Compare with `AtomicSwapJob` which correctly re-throws.

---

### M26. AddressResolver geo point key mismatch
**File:** `src/resolvers/AddressResolver.php:95-98`

Returns `{lat, lng}`. Elasticsearch/OpenSearch require `{lat, lon}`. Typesense requires `[lat, lng]` flat array. No engine-level transformation exists, so geo_point data will fail to index correctly.

---

### M27. No unit tests for queue job classes
**Affected:** All 5 files in `src/jobs/`

Key testable logic includes `BulkIndexJob::buildEagerLoadConfig()` (public static, pure function), the "is live?" guard in `IndexElementJob`, and orphan detection in `CleanupOrphansJob`.

---

### M28. `SearchCompare` executes sequential searches
**File:** `src/sprig/components/SearchCompare.php:72-74`

Searches across indexes are sequential. The existing `multiSearch()` method could batch engine-grouped queries to reduce round-trips.

---

### M29. Direct engine instantiation violates convention
**Files:** `src/variables/SearchIndexVariable.php:747`, `src/controllers/IndexesController.php:370`, `src/sprig/components/TestConnection.php:116`

CLAUDE.md: "Use `$index->createEngine()` to instantiate engines -- never call `new $engineClass()` directly." These are intentional exceptions (no saved index) but the exception is not documented.

---

### M30. API keys stored as plain strings with no env-var enforcement
**File:** `src/models/Settings.php:20-68`

Credential fields accept raw strings. When project config is exported to YAML (often committed to git), plaintext credentials can be leaked. No validation warns when values don't start with `$`.

---

## Low Severity Issues

### L1. Default `perPage` inconsistency
Typesense uses `10` (TypesenseEngine.php:504), all others use `20`.

### L2. Algolia `_score` always null
AlgoliaEngine.php:514 -- Algolia hits don't contain `_score`. Always returns null.

### L3. Inconsistent embedding inference threshold
AbstractEngine.php:455 uses 8+ elements, ElasticCompatEngine.php:284 uses 50+.

### L4. Algolia `indexDocuments()` no error handling
AlgoliaEngine.php:373 -- No error checking, no `waitForTask()`. Silent failures.

### L5. Meilisearch fire-and-forget indexing
MeilisearchEngine.php:304, 330 -- Neither waits for task completion nor checks errors.

### L6. Meilisearch `deleteIndex()` no error handling for missing index
MeilisearchEngine.php:198 -- Throws exception for non-existent index. Other engines handle gracefully.

### L7. Algolia `deleteIndex()` same issue
AlgoliaEngine.php:225 -- No try/catch for already-deleted index.

### L8. ES `deleteDocuments()` ignores bulk API errors
ElasticCompatEngine.php:387-406 -- Unlike `indexDocuments()`, doesn't check response for errors.

### L9. Typesense filter escaping limited
TypesenseEngine.php:548 -- Only backtick escaped. Field name not validated.

### L10. Typesense `getAllDocumentIds()` loads entire export into memory
TypesenseEngine.php:726 -- Full JSONL string then `explode()`. Large indexes hit memory limits.

### L11. Double field mapping iteration in Typesense
TypesenseEngine.php:769/797, 861/873 -- ROLE_IMAGE check could be merged into primary loop.

### L12. `buildFieldTypeMap()` not cached (ElasticCompatEngine)
ElasticCompatEngine.php:514, 598 -- Called multiple times per search without caching.

### L13. Unreachable native highlight branch
ElasticCompatEngine.php:571-573 -- `extractHighlightParams()` removes `highlight` from remaining, making the `elseif` dead code.

### L14. Unreliable `getDocument()` fallback in AbstractEngine
AbstractEngine.php:314-328 -- Uses doc ID as full-text query. May match wrong documents. All engines override this.

### L15. `__` prefixed keys in engine config
Index.php:88-89 -- `__mode` and `__handle` could collide with engine-native config keys.

### L16. No mutual exclusivity validation for fieldUid/attribute
FieldMapping.php:105-111 -- Both null or both set are invalid states with no validation.

### L17. Silent no-op on SearchResult offsetSet/offsetUnset
SearchResult.php:106-114 -- Should throw `BadMethodCallException` per PHP immutable ArrayAccess convention.

### L18. `facetsWithActive()` assumes `value` key exists
SearchResult.php:82 -- Malformed facet data triggers PHP warning.

### L19. `typesensePort` typed as string with no numeric validation
Settings.php:59 -- `"abc"` passes validation.

### L20. Double truncation in validation display
FieldMappingValidator.php:504, 571 -- 200 chars then 60 chars, potentially cutting mid-entity.

### L21. `json_encode` failure in cache key
Sync.php:592 -- Non-UTF-8 bytes cause `json_encode` to return false, collapsing cache keys.

### L22. Offset-based pagination in `importIndex()` can miss entries
Sync.php:196-211 -- Count computed once, entries may change before jobs execute.

### L23. Swap index clone retains original `id`
Sync.php:278 -- Cloned swap index keeps production ID, potentially misleading event listeners.

### L24. VoyageClient cache key separator collision
VoyageClient.php:66 -- `|` separator can appear in text, creating hash collisions.

### L25. `getIndexSchema()` creates fresh engine instance
SearchIndexVariable.php:473 -- Bypasses `_getEngine()` cache.

### L26. Asymmetric boolean handling in `buildUrl()`
SearchIndexVariable.php:426 -- `false` skipped, `true` becomes `"1"`.

### L27. NumberResolver wrong result for Money fields
NumberResolver.php:42-46 -- `Money\Money` object cast to float produces `0.0`.

### L28. HTML entities left in stripped text
RichTextResolver.php:40, TableResolver.php:52, MatrixResolver.php:263 -- `strip_tags()` without `html_entity_decode()`.

### L29. Matrix sub-field arrays silently dropped
MatrixResolver.php:261-271 -- Table fields and multi-select inside Matrix produce null.

### L30. DeindexElementJob catches `\Exception` not `\Throwable`
DeindexElementJob.php:47 -- Inconsistent with IndexElementJob. PHP `Error` subclasses would crash.

### L31. `afterBulkIndex` event fires with production index during swap
BulkIndexJob.php:111 -- Event listeners see production handle, not swap handle.

### L32. DemoController `allowAnonymous = true`
DemoController.php:26 -- Only guarded by devMode. Accidental production exposure possible.

### L33. `showRoleDebug` controllable by frontend users
SearchBox.php:66 -- Public Sprig property leaks field config. Should gate on devMode.

### L34. SearchDocumentField treats `"0"` as empty
SearchDocumentField.php:104 -- PHP-falsy check discards document ID `"0"`.

### L35. Weak URL validation in SearchDocumentValue
SearchDocumentValue.php:261, 330 -- `str_starts_with($raw, 'http')` matches `httpfoo`.

### L36. `DocumentSyncEvent` uses `0` sentinel for bulk
Sync.php:504 -- Should use `?int` with `null` instead of `0` as sentinel.

### L37. GraphQL resolver returns null silently for invalid index
SearchResolver.php -- No error message. Client cannot distinguish "not found" from other failures.

### L38. No exception handling in FieldMappings redetect/refresh actions
FieldMappingsController.php -- Console counterpart handles with try/catch; CP does not.

### L39. Integration tests missing coverage for delete, count, getDocument, facets, highlights, sort, and filter operations
tests/integration/EngineIntegrationTestCase.php

### L40. Static role map cache never cleared
SearchDocumentValue.php:82 -- Stale in long-running processes.

### L41. `SearchIndex::$plugin` not null-safe before `init()`
SearchIndex.php:61 -- Makes unit testing without Craft bootstrap impossible.

### L42. Inconsistent error response patterns in controllers
Some throw `NotFoundHttpException`, others return JSON `success: false` for the same condition.

---

## Recommendations (Priority Order)

1. **Fix filter injection** in AlgoliaEngine `getDocument()` and harden Meilisearch/Typesense filter escaping (H1, M7).
2. **Add GraphQL authorization scoping** -- register schema components, validate token permissions (H6).
3. **Normalise facets in all `multiSearch()` implementations** to match single `search()` output (H2, H3, M1).
4. **Fix `_all` field reference** in ES suggestions -- use text field or disable suggest for `['*']` (H4).
5. **Add embedding dimensions to `buildSchema()`** (H5).
6. **Check `syncOnSave` in `handleElementDelete()`** (H7).
7. **Re-throw in `IndexElementJob` catch block** for transient failures to enable queue retries (M25).
8. **Add resolver unit tests** -- start with AddressResolver, NumberResolver, RichTextResolver (H8).
9. **Fix geo_point key mismatch** -- `lng` to `lon` for ES/OpenSearch (M26).
10. **Cast Meilisearch delete IDs to string** (M3).
11. **Fix `multiSearch()` page calculation** in ElasticCompatEngine (M2).
12. **Strip `raw` from responses** or gate behind devMode/permission (M18).
13. **Add `data` JSON scalar to SearchHitType** for custom field access (M22).
14. **Normalize section/type ID comparison** to avoid strict type mismatch (M11).
15. **Move `resolveElement()` outside debug-entry loop** (M20).
