<?php

namespace cogapp\searchindex\services;

use cogapp\searchindex\events\ElementIndexEvent;
use cogapp\searchindex\events\RegisterFieldResolversEvent;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\resolvers\AddressResolver;
use cogapp\searchindex\resolvers\AssetResolver;
use cogapp\searchindex\resolvers\AttributeResolver;
use cogapp\searchindex\resolvers\BooleanResolver;
use cogapp\searchindex\resolvers\DateResolver;
use cogapp\searchindex\resolvers\FieldResolverInterface;
use cogapp\searchindex\resolvers\MatrixResolver;
use cogapp\searchindex\resolvers\NumberResolver;
use cogapp\searchindex\resolvers\OptionsResolver;
use cogapp\searchindex\resolvers\PlainTextResolver;
use cogapp\searchindex\resolvers\RelationResolver;
use cogapp\searchindex\resolvers\RichTextResolver;
use cogapp\searchindex\resolvers\TableResolver;
use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Addresses;
use craft\fields\Assets;
use craft\fields\ButtonGroup;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Time;
use craft\fields\Url;
use craft\fields\Users;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\base\Event;

class FieldMapper extends Component
{
    public const EVENT_REGISTER_FIELD_RESOLVERS = 'registerFieldResolvers';
    public const EVENT_BEFORE_INDEX_ELEMENT = 'beforeIndexElement';

    private ?array $_resolverMap = null;

    private const DEFAULT_FIELD_TYPE_MAP = [
        PlainText::class => FieldMapping::TYPE_TEXT,
        Email::class => FieldMapping::TYPE_KEYWORD,
        Url::class => FieldMapping::TYPE_KEYWORD,
        Link::class => FieldMapping::TYPE_KEYWORD,
        Color::class => FieldMapping::TYPE_KEYWORD,
        Country::class => FieldMapping::TYPE_KEYWORD,
        Number::class => FieldMapping::TYPE_FLOAT,
        Range::class => FieldMapping::TYPE_FLOAT,
        Money::class => FieldMapping::TYPE_FLOAT,
        Lightswitch::class => FieldMapping::TYPE_BOOLEAN,
        Date::class => FieldMapping::TYPE_DATE,
        Time::class => FieldMapping::TYPE_DATE,
        Dropdown::class => FieldMapping::TYPE_KEYWORD,
        RadioButtons::class => FieldMapping::TYPE_KEYWORD,
        ButtonGroup::class => FieldMapping::TYPE_KEYWORD,
        Checkboxes::class => FieldMapping::TYPE_FACET,
        MultiSelect::class => FieldMapping::TYPE_FACET,
        Categories::class => FieldMapping::TYPE_FACET,
        Tags::class => FieldMapping::TYPE_FACET,
        EntriesField::class => FieldMapping::TYPE_TEXT,
        Users::class => FieldMapping::TYPE_TEXT,
        Assets::class => FieldMapping::TYPE_KEYWORD,
        Matrix::class => FieldMapping::TYPE_TEXT,
        Table::class => FieldMapping::TYPE_TEXT,
        Addresses::class => FieldMapping::TYPE_TEXT,
    ];

    private const DEFAULT_RESOLVER_MAP = [
        PlainText::class => PlainTextResolver::class,
        Email::class => PlainTextResolver::class,
        Url::class => PlainTextResolver::class,
        Link::class => PlainTextResolver::class,
        Color::class => PlainTextResolver::class,
        Country::class => PlainTextResolver::class,
        Number::class => NumberResolver::class,
        Range::class => NumberResolver::class,
        Money::class => NumberResolver::class,
        Lightswitch::class => BooleanResolver::class,
        Date::class => DateResolver::class,
        Time::class => DateResolver::class,
        Dropdown::class => OptionsResolver::class,
        RadioButtons::class => OptionsResolver::class,
        ButtonGroup::class => OptionsResolver::class,
        Checkboxes::class => OptionsResolver::class,
        MultiSelect::class => OptionsResolver::class,
        Categories::class => RelationResolver::class,
        Tags::class => RelationResolver::class,
        EntriesField::class => RelationResolver::class,
        Users::class => RelationResolver::class,
        Assets::class => AssetResolver::class,
        Matrix::class => MatrixResolver::class,
        Table::class => TableResolver::class,
        Addresses::class => AddressResolver::class,
    ];

    private const ATTRIBUTE_DEFAULTS = [
        'title' => FieldMapping::TYPE_TEXT,
        'slug' => FieldMapping::TYPE_KEYWORD,
        'postDate' => FieldMapping::TYPE_DATE,
        'dateCreated' => FieldMapping::TYPE_DATE,
        'dateUpdated' => FieldMapping::TYPE_DATE,
        'uri' => FieldMapping::TYPE_KEYWORD,
        'status' => FieldMapping::TYPE_KEYWORD,
    ];

    public function detectFieldMappings(Index $index): array
    {
        $mappings = [];
        $sortOrder = 0;

        // Element attributes first
        foreach (self::ATTRIBUTE_DEFAULTS as $attribute => $type) {
            $mapping = new FieldMapping();
            $mapping->attribute = $attribute;
            $mapping->indexFieldName = $attribute;
            $mapping->indexFieldType = $type;
            $mapping->enabled = in_array($attribute, ['title', 'slug', 'uri', 'status'], true);
            $mapping->weight = $attribute === 'title' ? 10 : 5;
            $mapping->sortOrder = $sortOrder++;
            $mapping->uid = StringHelper::UUID();
            $mappings[] = $mapping;
        }

        // Collect fields from selected entry types
        $fields = $this->_getFieldsForIndex($index);

        foreach ($fields as $field) {
            $fieldClass = get_class($field);

            // Expand Matrix fields into parent header + individual sub-field mappings
            if ($fieldClass === Matrix::class) {
                // Parent mapping (disabled group header)
                $parentMapping = new FieldMapping();
                $parentMapping->fieldUid = $field->uid;
                $parentMapping->indexFieldName = $field->handle;
                $parentMapping->indexFieldType = FieldMapping::TYPE_TEXT;
                $parentMapping->enabled = false;
                $parentMapping->weight = 5;
                $parentMapping->sortOrder = $sortOrder++;
                $parentMapping->uid = StringHelper::UUID();
                $mappings[] = $parentMapping;

                // Collect sub-fields from all entry types, de-duplicated by handle
                $seenSubHandles = [];
                foreach ($field->getEntryTypes() as $entryType) {
                    $fieldLayout = $entryType->getFieldLayout();
                    if (!$fieldLayout) {
                        continue;
                    }
                    foreach ($fieldLayout->getCustomFields() as $subField) {
                        if (isset($seenSubHandles[$subField->handle])) {
                            continue;
                        }
                        $seenSubHandles[$subField->handle] = true;

                        $subFieldClass = get_class($subField);
                        $subDefaultType = self::DEFAULT_FIELD_TYPE_MAP[$subFieldClass] ?? FieldMapping::TYPE_TEXT;

                        $subMapping = new FieldMapping();
                        $subMapping->fieldUid = $subField->uid;
                        $subMapping->parentFieldUid = $field->uid;
                        $subMapping->indexFieldName = $field->handle . '_' . $subField->handle;
                        $subMapping->indexFieldType = $subDefaultType;
                        $subMapping->enabled = true;
                        $subMapping->weight = 5;
                        $subMapping->sortOrder = $sortOrder++;
                        $subMapping->uid = StringHelper::UUID();
                        $mappings[] = $subMapping;
                    }
                }

                continue;
            }

            $defaultType = self::DEFAULT_FIELD_TYPE_MAP[$fieldClass] ?? FieldMapping::TYPE_TEXT;

            $mapping = new FieldMapping();
            $mapping->fieldUid = $field->uid;
            $mapping->indexFieldName = $field->handle;
            $mapping->indexFieldType = $defaultType;
            $mapping->enabled = true;
            $mapping->weight = 5;
            $mapping->sortOrder = $sortOrder++;
            $mapping->uid = StringHelper::UUID();
            $mappings[] = $mapping;
        }

        return $mappings;
    }

    public function getDefaultIndexType(FieldInterface $field): string
    {
        return self::DEFAULT_FIELD_TYPE_MAP[get_class($field)] ?? FieldMapping::TYPE_TEXT;
    }

    public function resolveElement(Element $element, Index $index): array
    {
        $document = [
            'objectID' => $element->id,
        ];

        $mappings = $index->getFieldMappings();

        // Build a set of parent UIDs that have sub-field children
        $parentsWithChildren = [];
        foreach ($mappings as $mapping) {
            if ($mapping->isSubField()) {
                $parentsWithChildren[$mapping->parentFieldUid] = true;
            }
        }

        foreach ($mappings as $mapping) {
            if (!$mapping->enabled) {
                continue;
            }

            // Skip parent Matrix header mappings that have sub-field children
            if (!$mapping->isAttribute() && !$mapping->isSubField()
                && $mapping->fieldUid && isset($parentsWithChildren[$mapping->fieldUid])) {
                continue;
            }

            $value = $this->_resolveFieldValue($element, $mapping);
            if ($value !== null) {
                $document[$mapping->indexFieldName] = $value;
            }
        }

        // Fire event to allow modifications
        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_ELEMENT)) {
            $event = new ElementIndexEvent([
                'element' => $element,
                'index' => $index,
                'document' => $document,
            ]);
            $this->trigger(self::EVENT_BEFORE_INDEX_ELEMENT, $event);
            $document = $event->document;
        }

        return $document;
    }

    public function getResolverForField(?FieldInterface $field): ?FieldResolverInterface
    {
        if ($field === null) {
            return new AttributeResolver();
        }

        $resolverMap = $this->_getResolverMap();
        $fieldClass = get_class($field);

        if (isset($resolverMap[$fieldClass])) {
            $resolverClass = $resolverMap[$fieldClass];
            return new $resolverClass();
        }

        // Check parent classes
        foreach ($resolverMap as $supportedClass => $resolverClass) {
            if (is_subclass_of($fieldClass, $supportedClass)) {
                return new $resolverClass();
            }
        }

        return new PlainTextResolver();
    }

    private function _resolveFieldValue(Element $element, FieldMapping $mapping): mixed
    {
        try {
            if ($mapping->isAttribute()) {
                $resolver = new AttributeResolver();
                return $resolver->resolve($element, null, $mapping);
            }

            // Sub-field: resolve via parent Matrix field
            if ($mapping->isSubField()) {
                return $this->_resolveSubFieldValue($element, $mapping);
            }

            $field = $this->_getFieldByUid($mapping->fieldUid);
            if (!$field) {
                return null;
            }

            $resolver = $this->getResolverForField($field);
            if (!$resolver) {
                return null;
            }

            return $resolver->resolve($element, $field, $mapping);
        } catch (\Throwable $e) {
            Craft::warning(
                "Failed to resolve field '{$mapping->indexFieldName}' for element #{$element->id}: " . $e->getMessage(),
                __METHOD__
            );
            return null;
        }
    }

    private function _resolveSubFieldValue(Element $element, FieldMapping $mapping): mixed
    {
        $parentField = $this->_getFieldByUid($mapping->parentFieldUid);
        if (!$parentField || !($parentField instanceof Matrix)) {
            return null;
        }

        $subField = $this->_getFieldByUid($mapping->fieldUid);
        if (!$subField) {
            return null;
        }

        $query = $element->getFieldValue($parentField->handle);
        if ($query === null) {
            return null;
        }

        $entries = $query->all();
        if (empty($entries)) {
            return null;
        }

        // Use the proper typed resolver for this sub-field (RelationResolver, AssetResolver, etc.)
        $resolver = $this->getResolverForField($subField);
        if (!$resolver) {
            return null;
        }

        $isArrayType = in_array($mapping->indexFieldType, [
            FieldMapping::TYPE_FACET,
            FieldMapping::TYPE_KEYWORD,
        ], true);

        $parts = [];

        foreach ($entries as $entry) {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                continue;
            }

            // Check if this entry type has the sub-field
            $hasField = false;
            foreach ($fieldLayout->getCustomFields() as $blockField) {
                if ($blockField->handle === $subField->handle) {
                    $hasField = true;
                    break;
                }
            }
            if (!$hasField) {
                continue;
            }

            // Resolve using the proper resolver, with the block entry as the element
            $value = $resolver->resolve($entry, $subField, $mapping);

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $parts = array_merge($parts, $value);
            } elseif ($value !== '') {
                $parts[] = $value;
            }
        }

        if (empty($parts)) {
            return null;
        }

        if ($isArrayType) {
            return $parts;
        }

        // Single value: preserve type (bool, int, etc.)
        if (count($parts) === 1) {
            return $parts[0];
        }

        // Multiple values: concatenate as text
        return implode(' ', array_map('strval', $parts));
    }

    private function _getFieldsForIndex(Index $index): array
    {
        $fields = [];
        $seenHandles = [];

        $entryTypeIds = $index->entryTypeIds ?? [];

        if (empty($entryTypeIds) && !empty($index->sectionIds)) {
            // Get all entry types for the selected sections
            foreach ($index->sectionIds as $sectionId) {
                $section = Craft::$app->getEntries()->getSectionById($sectionId);
                if ($section) {
                    foreach ($section->getEntryTypes() as $entryType) {
                        $entryTypeIds[] = $entryType->id;
                    }
                }
            }
        }

        foreach ($entryTypeIds as $entryTypeId) {
            $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
            if (!$entryType) {
                continue;
            }

            $fieldLayout = $entryType->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if (!isset($seenHandles[$field->handle])) {
                    $fields[] = $field;
                    $seenHandles[$field->handle] = true;
                }
            }
        }

        return $fields;
    }

    private function _getFieldByUid(?string $uid): ?FieldInterface
    {
        if (!$uid) {
            return null;
        }

        return Craft::$app->getFields()->getFieldByUid($uid);
    }

    private function _getResolverMap(): array
    {
        if ($this->_resolverMap !== null) {
            return $this->_resolverMap;
        }

        $this->_resolverMap = self::DEFAULT_RESOLVER_MAP;

        // Add CKEditor support if installed
        if (class_exists('craft\ckeditor\Field')) {
            $this->_resolverMap['craft\ckeditor\Field'] = RichTextResolver::class;
        }

        // Allow third-party plugins to register resolvers
        $event = new RegisterFieldResolversEvent([
            'resolvers' => $this->_resolverMap,
        ]);
        Event::trigger(self::class, self::EVENT_REGISTER_FIELD_RESOLVERS, $event);
        $this->_resolverMap = $event->resolvers;

        return $this->_resolverMap;
    }
}
