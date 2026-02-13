# Plan: Split into Generic PHP Package + Craft Wrapper

## Motivation

The engine layer (AbstractEngine, all 5 engines, SearchResult, EngineInterface) has almost no Craft CMS dependencies. Extracting it into a standalone Composer package would let us reuse the same search abstraction in Laravel, Symfony, or any PHP project — while the Craft plugin becomes a thin wrapper that provides the CP UI, queue jobs, Twig variable, and element event listeners.

## Current Craft Dependencies in the Engine Layer

Only two touch points:

1. **`craft\helpers\App::parseEnv()`** — used in `AbstractEngine::getIndexName()` and `AbstractEngine::parseSetting()` to resolve `$ENV_VAR` syntax in config values.
2. **`cogapp\searchindex\models\Index`** — extends `craft\base\Model`. Engines use `$index->handle` and `$index->getFieldMappings()`.

Everything else in the engine layer is plain PHP.

## Proposed Structure

### `cogapp/search-index` (new generic package)

```
src/
  Contracts/
    EngineInterface.php          # moved from engines/EngineInterface.php
    IndexInterface.php           # new — defines getHandle(), getFieldMappings()
  Engines/
    AbstractEngine.php           # env resolution becomes a callable
    AlgoliaEngine.php
    ElasticsearchEngine.php
    OpenSearchEngine.php
    MeilisearchEngine.php
    TypesenseEngine.php
  Models/
    SearchResult.php             # unchanged
    FieldMapping.php             # plain PHP class (no craft\base\Model)
tests/
  Unit/
    Models/SearchResultTest.php
    Engines/HitNormalisationTest.php
  Integration/                   # same DDEV-based integration tests
```

**Key changes:**

- `EngineInterface::search()` accepts `IndexInterface` instead of `Index`
- `AbstractEngine` constructor accepts an optional `envResolver` callable (`?callable $envResolver = null`). Defaults to passthrough (`fn($v) => $v`). Craft wrapper passes `App::parseEnv(...)`.
- `FieldMapping` becomes a plain PHP class with public properties (no `craft\base\Model`, no `defineRules()`)
- New `IndexInterface` with `getHandle(): string` and `getFieldMappings(): array`
- Namespace changes from `cogapp\searchindex\engines\*` to `Cogapp\SearchIndex\Engines\*` (PSR-4 standard casing)

### `cogapp/craft-search-index` (existing repo, slimmed down)

```
src/
  Plugin.php                     # (renamed from SearchIndex.php or kept)
  models/
    Index.php                    # extends craft\base\Model, implements IndexInterface
    FieldMapping.php             # extends craft\base\Model, wraps core FieldMapping
    Settings.php                 # unchanged
  services/
    Indexes.php                  # unchanged
    FieldMapper.php              # unchanged
  engines/
    CraftEngineFactory.php       # new — builds engines with App::parseEnv as envResolver
  controllers/                   # unchanged
  variables/                     # unchanged
  jobs/                          # unchanged
  events/                        # unchanged
  resolvers/                     # unchanged
```

**Key changes:**

- `composer.json` requires `cogapp/search-index` as a dependency
- `Index` model implements `Cogapp\SearchIndex\Contracts\IndexInterface`
- Engine instantiation goes through `CraftEngineFactory` which injects `App::parseEnv` as the env resolver
- All CP, queue, event, and Twig code stays here untouched

## Migration Path

### Phase 1: Extract core package

1. Create new repo `cogapp/search-index`
2. Copy engine classes, SearchResult, FieldMapping (plain version), and EngineInterface
3. Introduce `IndexInterface` and `envResolver` callable
4. Add PSR-4 namespacing (`Cogapp\SearchIndex\*`)
5. Move unit + integration tests for the engine layer
6. Publish to Packagist

### Phase 2: Slim down Craft plugin

1. Add `cogapp/search-index` as a Composer dependency
2. Remove engine classes from the Craft plugin
3. Make `Index` implement `IndexInterface`
4. Create `CraftEngineFactory` that wraps engine construction with `App::parseEnv`
5. Update all references to use the new namespaces
6. Verify all existing tests pass

### Phase 3: Laravel adapter (future, optional)

A separate `cogapp/search-index-laravel` package could provide:
- A ServiceProvider that registers engines from `config/search-index.php`
- Laravel Scout-style integration or standalone facade
- Artisan commands for import/flush/status
- `config()` helper as the env resolver

## What Stays Generic vs. Framework-Specific

| Concern | Generic package | Craft wrapper | Laravel adapter |
|---|---|---|---|
| Engine implementations | Yes | — | — |
| SearchResult DTO | Yes | — | — |
| FieldMapping (plain) | Yes | Wraps in Craft Model | Wraps in config array |
| Index definition | Interface only | Craft Model + Project Config | Eloquent or config |
| Env var resolution | Callable injection | `App::parseEnv` | `env()` / `config()` |
| CP UI | — | Yes | — |
| Queue jobs | — | Craft Queue | Laravel Queue |
| Event system | — | Yii2 Events | Laravel Events |
| Twig integration | — | Yes | — |
| Blade integration | — | — | Optional |

## Risks and Considerations

- **Namespace change** — moving from `cogapp\searchindex\engines\*` to `Cogapp\SearchIndex\*` is a breaking change for anyone importing engine classes directly. Mitigate with class aliases in the Craft plugin for one major version.
- **Typesense SDK version** — the core package should specify a wide version range (`^4.0 || ^6.0`) since Typesense PHP client had breaking changes between v4 and v6.
- **Testing** — integration tests with DDEV services should live in the core package. The Craft plugin only needs tests for its wrapper logic (factory, Twig variable, queue jobs).
- **Two repos vs. monorepo** — could use a monorepo with `packages/core` and `packages/craft` if managing two repos is overhead. Composer supports path repositories for local dev either way.
