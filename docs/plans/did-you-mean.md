# "Did You Mean?" — Cross-Engine Suggestions Plan

## Current State

- `suggest: true` option exists but **only works on ES/OpenSearch** (phrase suggester)
- Meilisearch, Typesense, and Algolia have built-in typo tolerance but **no separate "did you mean" API**
- `SearchResult::$suggestions` is already a readonly array, populated from ES/OS responses
- Twig, GraphQL, and Sprig docs already show how to render suggestions
- The infrastructure is there — we just need to populate `$suggestions` on the other engines

## Goal

Make `suggest: true` return useful suggestions on **all engines**, not just ES/OpenSearch. The user types "edinbrugh" and gets back results (via typo tolerance) plus a suggestion like "edinburgh".

## Engine Capabilities

| Engine | Typo tolerance | Separate suggestion API | Strategy |
|--------|---------------|------------------------|----------|
| **ES/OpenSearch** | No built-in | Yes — phrase suggester | Already implemented |
| **Meilisearch** | Built-in | No | Infer from results (see below) |
| **Typesense** | Built-in | No | Infer from results (see below) |
| **Algolia** | Built-in | No | Infer from results (see below) |

## Approach: Result-Inferred Suggestions

For engines without a suggestion API, we can infer "did you mean" from the search results themselves:

1. If the query has results but the **title of the top hit** doesn't contain the exact query terms, extract the corrected form from highlights or the title field
2. Meilisearch returns `_matchesPosition` which shows where matches occurred in the corrected form — this can reveal the corrected spelling
3. Typesense returns `highlights` with `matched_tokens` showing what the engine actually matched
4. Algolia returns `_highlightResult` with the matched/corrected form

### Strategy per engine

#### Meilisearch

Meilisearch doesn't have a dedicated suggestion endpoint. However, when typo tolerance kicks in, the results still come back. Options:

**Option A — Extract from highlights (recommended):**
When `suggest: true`, enable `showMatchesPosition: true` and `attributesToHighlight: ['title']`. If the query doesn't exactly match any title but results exist, the highlighted title reveals the corrected term. Parse the `_formatted` response to extract the corrected query.

**Option B — Simple heuristic:**
If query returns results and `query.toLowerCase() !== topHit.title.toLowerCase()` (fuzzy match), suggest the title of the top hit. This is crude but effective for single-word queries.

**Option C — No change, document limitation:**
Since Meilisearch already returns correct results via typo tolerance, "did you mean" is less important. Just document that suggestions are ES/OS only and Meilisearch handles typos automatically.

#### Typesense

Typesense returns `highlights[].matched_tokens` in its response, which shows the actual tokens that matched (post-typo-correction). When `suggest: true`:

```php
// In TypesenseEngine::search(), after getting results:
if ($suggest && !empty($hits)) {
    $suggestions = $this->inferSuggestionsFromHighlights($hits, $query);
    // ... pass to SearchResult
}
```

The `matched_tokens` array contains the corrected forms, so "edinbrugh" → matched_tokens: ["Edinburgh"] reveals the correction.

#### Algolia

Algolia's `_highlightResult` contains `matchedWords` and the highlighted value. Similar extraction to Typesense:

```php
if ($suggest && !empty($hits)) {
    $suggestions = $this->inferSuggestionsFromHighlights($rawHits, $query);
}
```

### Implementation Detail: `inferSuggestionsFromHighlights()`

Each engine would implement its own version in the concrete engine class, since highlight formats differ. The logic:

1. Get the title field value from the top hit
2. Compare each query word against the matched/highlighted words
3. If a query word differs from the matched word (Levenshtein distance > 0 but < threshold), the matched word is a correction
4. Build a corrected query string by replacing misspelled words with corrections
5. Only suggest if the corrected query differs from the original

```php
protected function inferSuggestionsFromHighlights(array $rawHits, string $query): array
{
    if (empty($rawHits)) {
        return [];
    }

    // Engine-specific: extract matched/corrected tokens from top hit
    $correctedTokens = $this->extractCorrectedTokens($rawHits[0]);

    $queryWords = preg_split('/\s+/', mb_strtolower($query));
    $corrected = [];
    $changed = false;

    foreach ($queryWords as $word) {
        $bestMatch = $word;
        foreach ($correctedTokens as $token) {
            $tokenLower = mb_strtolower($token);
            $distance = levenshtein($word, $tokenLower);
            if ($distance > 0 && $distance <= 2) {
                $bestMatch = $tokenLower;
                $changed = true;
                break;
            }
        }
        $corrected[] = $bestMatch;
    }

    return $changed ? [implode(' ', $corrected)] : [];
}
```

## Alternative: Abstract Suggestion Provider

Instead of putting inference logic in each engine, create a `SuggestionProvider` that wraps any engine:

```php
interface SuggestionProviderInterface {
    public function getSuggestions(string $query, SearchResult $result, array $rawResponse): array;
}
```

- `ElasticSuggestionProvider` — delegates to ES phrase suggester (existing logic)
- `HighlightInferenceSuggestionProvider` — extracts from highlights (Meili/Typesense/Algolia)

This keeps engines focused on search and puts suggestion logic in a separate layer.

## Scope & Files

### Minimum viable (Option A — highlight inference)

| File | Change |
|------|--------|
| `src/engines/MeilisearchEngine.php` | Extract corrections from `_formatted`/`_matchesPosition` |
| `src/engines/TypesenseEngine.php` | Extract corrections from `highlights[].matched_tokens` |
| `src/engines/AlgoliaEngine.php` | Extract corrections from `_highlightResult.*.matchedWords` |
| `src/engines/AbstractEngine.php` | Add shared `inferSuggestionsFromTokenComparison()` helper |
| `docs/usage/twig.md` | Update "ES/OpenSearch only" notes |
| `docs/usage/graphql.md` | Update engine limitation notes |
| `CLAUDE.md` | Update suggest documentation |

### Tests

| File | Test |
|------|------|
| `tests/unit/engines/HighlightAndSuggestTest.php` | Add cases for inferred suggestions |
| `tests/integration/` | Test `suggest: true` on all engines with a known typo query |

## Open Questions

1. **Should we always infer, or only when `suggest: true`?** — Recommend: only when `suggest: true` to avoid extra processing overhead.

2. **What if the engine returns results but highlight extraction fails?** — Return empty suggestions (graceful degradation). The user still gets results thanks to typo tolerance.

3. **Multi-word queries** — "edinbrugh castle" should suggest "edinburgh castle". Need to handle word-by-word correction. The Levenshtein approach handles this naturally.

4. **Should we request highlights automatically when `suggest: true`?** — Yes, enable engine highlights internally even if the user didn't request `highlight: true`. Strip them from the response unless the user also requested highlights.

5. **Performance** — Highlight inference adds no extra API calls (highlights come free with search results). The only cost is string comparison logic in PHP, which is negligible.

## Recommendation

**Start with Option A (highlight inference)** for Meilisearch and Typesense since those are the synced engines in active use. Algolia can follow the same pattern. This gives cross-engine "did you mean" with zero extra API calls.

The existing `suggest: true` option and `SearchResult::$suggestions` infrastructure means no Twig/GraphQL/Sprig changes are needed — it all just starts working.
