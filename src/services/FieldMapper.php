<?php

/**
 * Search Index plugin for Craft CMS -- FieldMapper service.
 */

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

/**
 * Maps Craft fields to search index field types and resolves element data into indexable documents.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FieldMapper extends Component
{
    /** Fired to allow third-party plugins to register custom field resolvers. */
    public const EVENT_REGISTER_FIELD_RESOLVERS = 'registerFieldResolvers';
    /** Fired before an element document is sent to the search engine, allowing modification. */
    public const EVENT_BEFORE_INDEX_ELEMENT = 'beforeIndexElement';

    /** @var array<string, string>|null Cached map of field class to resolver class. */
    private ?array $_resolverMap = null;

    /** @var array<string, FieldInterface|null> Cached field lookups keyed by UID. */
    private array $_fieldsByUid = [];

    /** @var array<string, FieldResolverInterface> Cached resolver instances keyed by class name. */
    private array $_resolverInstances = [];

    /** @var array<int, array<string, bool>> Cached parent-with-children sets keyed by index ID. */
    private array $_parentsWithChildren = [];

    /** Default mapping of Craft field classes to index field type constants. */
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
        EntriesField::class => FieldMapping::TYPE_FACET,
        Users::class => FieldMapping::TYPE_FACET,
        Assets::class => FieldMapping::TYPE_INTEGER,
        Matrix::class => FieldMapping::TYPE_TEXT,
        Table::class => FieldMapping::TYPE_TEXT,
        Addresses::class => FieldMapping::TYPE_TEXT,
    ];

    /** Default mapping of Craft field classes to their resolver implementations. */
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

    /** Default index field types for standard element attributes. */
    private const ATTRIBUTE_DEFAULTS = [
        'title' => FieldMapping::TYPE_TEXT,
        'slug' => FieldMapping::TYPE_KEYWORD,
        'postDate' => FieldMapping::TYPE_DATE,
        'dateCreated' => FieldMapping::TYPE_DATE,
        'dateUpdated' => FieldMapping::TYPE_DATE,
        'uri' => FieldMapping::TYPE_KEYWORD,
        'status' => FieldMapping::TYPE_KEYWORD,
    ];

    /**
     * Auto-detect field mappings for an index based on its section/entry-type configuration.
     *
     * Generates mappings for standard element attributes and all custom fields
     * found in the selected entry types, including Matrix sub-fields.
     *
     * @param Index $index
     * @return FieldMapping[]
     */
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

            // Default roles for common attributes
            $mapping->role = $this->defaultRoleForFieldName($attribute);

            $mappings[] = $mapping;
        }

        // Collect fields from selected entry types
        $fields = $this->_getFieldsForIndex($index);

        // Track which default roles have been assigned
        $assignedRoles = [];

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
                        $subMapping->enabled = $subField->searchable;
                        $subMapping->weight = 5;
                        $subMapping->sortOrder = $sortOrder++;
                        $subMapping->uid = StringHelper::UUID();

                        // Auto-assign roles to sub-fields
                        if (!isset($assignedRoles[FieldMapping::ROLE_IMAGE]) && $subFieldClass === Assets::class) {
                            $subMapping->role = FieldMapping::ROLE_IMAGE;
                            $subMapping->enabled = true;
                            $assignedRoles[FieldMapping::ROLE_IMAGE] = true;
                        }

                        // Matrix sub-fields aggregate across blocks: keyword/single-select
                        // become facet (multi-value) unless a role is assigned.
                        if ($subDefaultType === FieldMapping::TYPE_KEYWORD
                            && !$subMapping->role
                        ) {
                            $subDefaultType = FieldMapping::TYPE_FACET;
                        }

                        $subMapping->indexFieldType = $subDefaultType;
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
            $mapping->enabled = $field->searchable;
            $mapping->weight = 5;
            $mapping->sortOrder = $sortOrder++;
            $mapping->uid = StringHelper::UUID();

            // Default role for first Asset field → image
            if (!isset($assignedRoles[FieldMapping::ROLE_IMAGE]) && $fieldClass === Assets::class) {
                $mapping->role = FieldMapping::ROLE_IMAGE;
                $mapping->enabled = true;
                $assignedRoles[FieldMapping::ROLE_IMAGE] = true;
            }

            // Default role for first text-like field → summary
            if (!isset($assignedRoles[FieldMapping::ROLE_SUMMARY])) {
                $isCkEditor = class_exists('craft\ckeditor\Field') && $fieldClass === 'craft\ckeditor\Field';
                if ($fieldClass === PlainText::class || $isCkEditor) {
                    $mapping->role = FieldMapping::ROLE_SUMMARY;
                    $assignedRoles[FieldMapping::ROLE_SUMMARY] = true;
                }
            }

            $mappings[] = $mapping;
        }

        return $this->enforceUniqueRoles($mappings);
    }

    /**
     * Re-detect field mappings for an index, merging with existing settings.
     *
     * Preserves user customizations (enabled, role, weight, indexFieldType, resolverConfig)
     * from existing mappings while refreshing field UIDs to current values.
     * Matching is done by `indexFieldName` which is the stable identifier.
     * New fields are added with defaults; removed fields are dropped.
     *
     * @param Index $index
     * @return FieldMapping[]
     */
    public function redetectFieldMappings(Index $index): array
    {
        $freshMappings = $this->detectFieldMappings($index);
        $existingMappings = $index->getFieldMappings();

        // Index existing mappings by indexFieldName for fast lookup
        $existingByName = [];
        foreach ($existingMappings as $mapping) {
            $existingByName[$mapping->indexFieldName] = $mapping;
        }

        // Merge: use fresh UIDs and structure, but preserve user settings
        foreach ($freshMappings as $fresh) {
            $existing = $existingByName[$fresh->indexFieldName] ?? null;
            if (!$existing) {
                continue;
            }

            // Preserve user-customised settings
            $fresh->enabled = $existing->enabled;
            $fresh->weight = $existing->weight;
            $fresh->indexFieldType = $existing->indexFieldType;
            // Preserve explicit user role assignments. If no role is set,
            // keep detector defaults (e.g. postDate => date role).
            if ($existing->role !== null && $existing->role !== '') {
                $fresh->role = $existing->role;
            }
            $fresh->resolverConfig = $existing->resolverConfig;
        }

        return $this->enforceUniqueRoles($freshMappings);
    }

    /**
     * Detect field mappings for a read-only index by pulling the schema from the engine.
     *
     * Creates a lightweight FieldMapping for each field in the engine schema.
     * All mappings are enabled with no Craft field UIDs (since there's no Craft sync).
     *
     * @param Index $index
     * @return FieldMapping[]
     */
    public function detectSchemaFieldMappings(Index $index): array
    {
        $engine = $index->createEngine();
        $schemaFields = $engine->getSchemaFields($index);

        $mappings = [];
        $sortOrder = 0;

        foreach ($schemaFields as $field) {
            $mapping = new FieldMapping();
            $mapping->indexFieldName = $field['name'];
            $mapping->indexFieldType = $field['type'];
            $mapping->enabled = true;
            $mapping->weight = 5;
            $mapping->sortOrder = $sortOrder++;
            $mapping->uid = StringHelper::UUID();
            $mapping->role = $this->defaultRoleForFieldName($mapping->indexFieldName);
            $mappings[] = $mapping;
        }

        return $this->enforceUniqueRoles($mappings);
    }

    /**
     * Re-detect schema field mappings for a read-only index, preserving user role assignments.
     *
     * Refreshes the field list from the engine schema while keeping existing
     * role and type customisations (matched by indexFieldName).
     *
     * @param Index $index
     * @return FieldMapping[]
     */
    public function redetectSchemaFieldMappings(Index $index): array
    {
        $freshMappings = $this->detectSchemaFieldMappings($index);
        $existingMappings = $index->getFieldMappings();

        // Index existing mappings by indexFieldName for fast lookup
        $existingByName = [];
        foreach ($existingMappings as $mapping) {
            $existingByName[$mapping->indexFieldName] = $mapping;
        }

        // Merge: preserve user-assigned roles and type overrides
        foreach ($freshMappings as $fresh) {
            $existing = $existingByName[$fresh->indexFieldName] ?? null;
            if (!$existing) {
                continue;
            }

            $fresh->role = $existing->role;
            $fresh->indexFieldType = $existing->indexFieldType;
        }

        return $this->enforceUniqueRoles($freshMappings);
    }

    /**
     * Return the default index field type for a given Craft field.
     *
     * @param FieldInterface $field
     * @return string One of the FieldMapping::TYPE_* constants.
     */
    public function getDefaultIndexType(FieldInterface $field): string
    {
        return self::DEFAULT_FIELD_TYPE_MAP[get_class($field)] ?? FieldMapping::TYPE_TEXT;
    }

    /**
     * Return the default role for a field/attribute name where one is obvious.
     *
     * @param string $fieldName
     * @return string|null
     */
    private function defaultRoleForFieldName(string $fieldName): ?string
    {
        // Exact matches first (Craft attributes)
        $exactMatch = match ($fieldName) {
            'title' => FieldMapping::ROLE_TITLE,
            'uri', 'url' => FieldMapping::ROLE_URL,
            'postDate', 'dateCreated', 'dateUpdated' => FieldMapping::ROLE_DATE,
            default => null,
        };

        if ($exactMatch !== null) {
            return $exactMatch;
        }

        // Fuzzy matches for external/read-only indexes
        $lower = strtolower($fieldName);

        if (in_array($lower, ['description', 'summary', 'excerpt', 'body', 'content'], true)) {
            return FieldMapping::ROLE_SUMMARY;
        }
        if (in_array($lower, ['thumbnail', 'thumb', 'thumb_url', 'thumbnail_url', 'image_thumbnail'], true)) {
            return FieldMapping::ROLE_THUMBNAIL;
        }
        if (in_array($lower, ['image', 'image_url', 'hero_image'], true)) {
            return FieldMapping::ROLE_IMAGE;
        }
        if (in_array($lower, ['iiif_info_url', 'iiif_url', 'iiif_info', 'info_url'], true)) {
            return FieldMapping::ROLE_IIIF;
        }

        return null;
    }

    /**
     * Ensure each semantic role is assigned to at most one mapping.
     *
     * Keeps the first occurrence (by mapping order) and clears duplicates.
     *
     * @param FieldMapping[] $mappings
     * @return FieldMapping[]
     */
    private function enforceUniqueRoles(array $mappings): array
    {
        $chosenIndexByRole = [];

        foreach ($mappings as $i => $mapping) {
            if (!$mapping->role) {
                continue;
            }

            $role = $mapping->role;
            if (!isset($chosenIndexByRole[$role])) {
                $chosenIndexByRole[$role] = $i;
                continue;
            }

            $chosenIndex = $chosenIndexByRole[$role];
            $chosen = $mappings[$chosenIndex];

            // For Craft entry attributes, prefer postDate for the date role.
            if (
                $role === FieldMapping::ROLE_DATE
                && $mapping->attribute === 'postDate'
                && $chosen->attribute !== 'postDate'
            ) {
                $mappings[$chosenIndex]->role = null;
                $chosenIndexByRole[$role] = $i;
                continue;
            }

            $mapping->role = null;
        }

        // Role-mapped fields must be enabled so helper methods always have data.
        foreach ($mappings as $mapping) {
            if ($mapping->role) {
                $mapping->enabled = true;
            }
        }

        return $mappings;
    }

    /**
     * Resolve an element into an indexable document array using the index's field mappings.
     *
     * @param Element $element
     * @param Index   $index
     * @return array The document payload keyed by index field name.
     */
    public function resolveElement(Element $element, Index $index): array
    {
        $document = [
            'objectID' => $element->id,
        ];

        // Always include section and entry type handles for Entry elements
        if ($element instanceof Entry) {
            $document['sectionHandle'] = $element->getSection()?->handle;
            $document['entryTypeHandle'] = $element->getType()?->handle;
        }

        $mappings = $index->getFieldMappings();

        // Build a set of parent UIDs that have sub-field children (cached per index)
        $indexId = $index->id ?? 0;
        if (!isset($this->_parentsWithChildren[$indexId])) {
            $this->_parentsWithChildren[$indexId] = [];
            foreach ($mappings as $mapping) {
                if ($mapping->isSubField()) {
                    $this->_parentsWithChildren[$indexId][$mapping->parentFieldUid] = true;
                }
            }
        }
        $parentsWithChildren = $this->_parentsWithChildren[$indexId];

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

    /**
     * Return the appropriate field resolver for a given Craft field.
     *
     * Falls back to PlainTextResolver when no specific resolver is registered.
     * Returns AttributeResolver when the field is null (i.e. an element attribute).
     *
     * @param FieldInterface|null $field
     * @return FieldResolverInterface|null
     */
    public function getResolverForField(?FieldInterface $field): ?FieldResolverInterface
    {
        if ($field === null) {
            return $this->_getResolverInstance(AttributeResolver::class);
        }

        $resolverMap = $this->_getResolverMap();
        $fieldClass = get_class($field);

        if (isset($resolverMap[$fieldClass])) {
            return $this->_getResolverInstance($resolverMap[$fieldClass]);
        }

        // Check parent classes
        foreach ($resolverMap as $supportedClass => $resolverClass) {
            if (is_subclass_of($fieldClass, $supportedClass)) {
                return $this->_getResolverInstance($resolverClass);
            }
        }

        return $this->_getResolverInstance(PlainTextResolver::class);
    }

    /**
     * Resolve a single field mapping value for an element.
     *
     * @param Element      $element
     * @param FieldMapping $mapping
     * @return mixed The resolved value, or null on failure.
     */
    private function _resolveFieldValue(Element $element, FieldMapping $mapping): mixed
    {
        try {
            if ($mapping->isAttribute()) {
                $resolver = $this->_getResolverInstance(AttributeResolver::class);
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

    /**
     * Resolve a Matrix sub-field mapping by iterating over parent entries.
     *
     * @param Element      $element
     * @param FieldMapping $mapping
     * @return mixed Aggregated sub-field values, or null if empty.
     */
    private function _resolveSubFieldValue(Element $element, FieldMapping $mapping): mixed
    {
        $parentField = $this->_getFieldByUid($mapping->parentFieldUid);
        if (!$parentField || !($parentField instanceof Matrix)) {
            return null;
        }

        $subField = $this->_getFieldByUid($mapping->fieldUid);

        // Derive the expected sub-field handle from the indexFieldName
        // (format: "parentHandle_subHandle") for stale-UID fallback
        $expectedHandle = $this->_extractSubFieldHandle($mapping->indexFieldName, $parentField->handle);

        // If UID lookup failed entirely, try to find the field by handle in the block layouts
        if (!$subField && $expectedHandle) {
            $subField = $this->_findSubFieldByHandle($parentField, $expectedHandle);
        }

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

        // The handle to match in block layouts — prefer the actual block field handle
        // over the UID-resolved handle (which may be stale/renamed)
        $matchHandle = $expectedHandle ?: $subField->handle;

        $isArrayType = $mapping->indexFieldType === FieldMapping::TYPE_FACET;

        $parts = [];

        foreach ($entries as $entry) {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                continue;
            }

            // Find the matching field in this block's layout
            $blockField = null;
            foreach ($fieldLayout->getCustomFields() as $candidate) {
                if ($candidate->handle === $matchHandle) {
                    $blockField = $candidate;
                    break;
                }
            }

            // Fallback: if the UID-resolved handle didn't match, try expectedHandle
            if (!$blockField && $matchHandle !== $subField->handle) {
                foreach ($fieldLayout->getCustomFields() as $candidate) {
                    if ($candidate->handle === $subField->handle) {
                        $blockField = $candidate;
                        break;
                    }
                }
            }

            if (!$blockField) {
                continue;
            }

            // Use the actual block field for resolution (it may differ from the UID-looked-up field)
            $actualResolver = $this->getResolverForField($blockField);
            $value = ($actualResolver ?? $resolver)->resolve($entry, $blockField, $mapping);

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
            return reset($parts);
        }

        // Multiple values: concatenate as text
        return implode(' ', array_map('strval', $parts));
    }

    /**
     * Extract the expected sub-field handle from an indexFieldName like "parentHandle_subHandle".
     *
     * @param string $indexFieldName
     * @param string $parentHandle
     * @return string|null The sub-field handle, or null if the format doesn't match.
     */
    private function _extractSubFieldHandle(string $indexFieldName, string $parentHandle): ?string
    {
        $prefix = $parentHandle . '_';
        if (!str_starts_with($indexFieldName, $prefix)) {
            return null;
        }

        $handle = substr($indexFieldName, strlen($prefix));
        return $handle !== '' ? $handle : null;
    }

    /**
     * Find a sub-field by handle within a Matrix field's entry type layouts.
     *
     * @param Matrix $parentField
     * @param string $handle
     * @return FieldInterface|null
     */
    private function _findSubFieldByHandle(Matrix $parentField, string $handle): ?FieldInterface
    {
        foreach ($parentField->getEntryTypes() as $entryType) {
            $fieldLayout = $entryType->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }
            foreach ($fieldLayout->getCustomFields() as $field) {
                if ($field->handle === $handle) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Collect all custom fields from the entry types relevant to an index.
     *
     * @param Index $index
     * @return FieldInterface[]
     */
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

    /**
     * Look up a Craft field by its UID.
     *
     * @param string|null $uid
     * @return FieldInterface|null
     */
    private function _getFieldByUid(?string $uid): ?FieldInterface
    {
        if (!$uid) {
            return null;
        }

        if (!array_key_exists($uid, $this->_fieldsByUid)) {
            $this->_fieldsByUid[$uid] = Craft::$app->getFields()->getFieldByUid($uid);
        }

        return $this->_fieldsByUid[$uid];
    }

    /**
     * Build and cache the resolver map, including CKEditor and third-party resolvers.
     *
     * @return array<string, string> Map of field class name to resolver class name.
     */
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

    /**
     * Return a cached resolver instance for the given class name.
     *
     * Resolvers are stateless, so the same instance can be reused across
     * multiple field resolutions within a single request.
     *
     * @param string $class Fully qualified resolver class name.
     * @return FieldResolverInterface
     */
    private function _getResolverInstance(string $class): FieldResolverInterface
    {
        if (!isset($this->_resolverInstances[$class])) {
            $this->_resolverInstances[$class] = new $class();
        }

        return $this->_resolverInstances[$class];
    }
}
