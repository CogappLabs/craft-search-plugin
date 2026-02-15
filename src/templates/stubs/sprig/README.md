# Search Index Sprig Starter Templates

These templates are a minimal frontend starter pack for the Search Index Sprig components.

## Included files

- `search-page.twig` — ready-to-style starter page with one joined-up search UI.
- `components/search-box.twig` — wrapper for `frontend.search-box`.
- `components/search-facets.twig` — wrapper for `frontend.search-facets`.
- `components/search-pagination.twig` — wrapper for `frontend.search-pagination`.

## Usage

1. Publish templates:
   `php craft search-index/index/publish-sprig-templates`
2. Render the page template from a route/controller, for example:
   `{% include 'search-index/sprig/search-page' %}`

Use the individual component templates when you want to compose a custom multi-component layout.
These are intentionally unstyled so they can be adapted to your project quickly.
