# Extending

## Custom Engines

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

## Custom Field Resolvers

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

## Events

### `FieldMapper::EVENT_REGISTER_FIELD_RESOLVERS`

Fired when building the resolver map. Use this to add or override field resolvers.

- **Event class:** `cogapp\searchindex\events\RegisterFieldResolversEvent`
- **Property:** `resolvers` -- `array<string, string>` mapping field class names to resolver class names.

### `FieldMapper::EVENT_BEFORE_INDEX_ELEMENT`

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

### `IndexesController::EVENT_REGISTER_ENGINE_TYPES`

Fired when building the list of available engine types. Use this to register custom engines.

- **Event class:** `cogapp\searchindex\events\RegisterEngineTypesEvent`
- **Property:** `types` -- `string[]` array of engine class names.

### Index Lifecycle Events

Fired by the `Indexes` service during index save/delete operations:

| Constant                                | When                            | Event class                              |
|-----------------------------------------|---------------------------------|------------------------------------------|
| `Indexes::EVENT_BEFORE_SAVE_INDEX`      | Before an index is saved.       | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_AFTER_SAVE_INDEX`       | After an index is saved.        | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_BEFORE_DELETE_INDEX`    | Before an index is deleted.     | `cogapp\searchindex\events\IndexEvent`   |
| `Indexes::EVENT_AFTER_DELETE_INDEX`     | After an index is deleted.      | `cogapp\searchindex\events\IndexEvent`   |

### Document Sync Events

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
