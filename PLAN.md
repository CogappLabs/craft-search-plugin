# Remaining Work Plan

## 1. Search Document Field — Refactor to Craft Autosuggest

**Current state:** Custom DOM implementation with manual debounce, inline styles, no keyboard nav, no ARIA.

**Target:** Use Craft's `vue-autosuggest` component which already supports:
- `name` + `hint` per suggestion (title + URI)
- Keyboard navigation (arrow keys, Enter, Escape)
- Proper ARIA attributes
- Craft-native styling

**Approach:** Craft's autosuggest (`_includes/forms/autosuggest.twig`) is Vue-based and filters a static `suggestions` array client-side. For our async search use case, we need to **extend the `{% block methods %}`** to override `updateFilteredOptions()` with an AJAX call instead of client-side filtering.

**Files to change:**
- `src/templates/_field/input.twig` — Replace custom HTML with `forms.autosuggestField` macro, extending the `methods` block for async search via `Craft.sendActionRequest`
- `src/web/assets/searchdocumentfield/src/search-document-field.ts` — May be significantly reduced or eliminated if the Vue component handles everything. Still needed for: loading existing document on page load, managing the selected/cleared state, hidden input sync.
- `src/web/assets/searchdocumentfield/src/search-document-field.css` — Reduce to just the selected-state chip styling
- `src/fields/SearchDocumentField.php` — May need to adjust `getInputHtml()` to pass suggestions format

**Key detail:** The autosuggest component uses `{name: "Title", hint: "uri/path"}` objects in its `suggestions[].data[]` arrays. We need to map search hits to this format. The `onSelected` callback gives us the selected item, where we can extract `objectID` and store it in the hidden input.

**Suggestion format confirmed:** Each hit maps to `{name: hit.title, hint: hit.uri, objectID: hit.objectID}`. Title + URI is the desired display.

**Alternative if autosuggest is too rigid:** Keep custom implementation but fix the issues:
- Move inline styles to CSS classes
- Remove `!` non-null assertions, add proper null guards
- Add keyboard navigation (arrow keys, Enter to select, Escape to close)
- Add `role="listbox"` / `role="option"` / `aria-activedescendant`
- Use `classList.toggle('hidden')` instead of `style.display`

## 2. Code Review Fixes (deferred from earlier)

A thorough review was requested but rejected due to agent spawning. Do this manually or in next session. Key areas:
- PHP: check all controllers have `requireCpRequest()` / `requirePostRequest()` where needed
- TS: audit all `!` non-null assertions across all asset bundles
- Templates: verify all user-facing strings use `|t('search-index')`
- CSS: check for remaining inline styles in TS files

## 3. Cleanup

- Delete `scripts/check-document.php` (debug script, not committed)
- Verify `scripts/` dir in `.gitignore` or remove
- Run `ddev exec composer phpstan` and `ddev exec composer check-cs` to catch any issues
- Run `npm run typecheck` (already passing via lefthook)

## 4. Index Structure Page (Task #4 from memory)

- `getIndexSchema()` is implemented on all engines
- `_structure.twig` template exists
- Controller endpoint exists
- Needs testing and polish — verify it shows useful schema info for each engine type

## 5. Documentation

- README examples were expanded — review for accuracy
- Consider adding a "Roles" section explaining the role system (title, image, summary, url)
- Consider adding an "Asset Resolution" section explaining that image fields store asset IDs
