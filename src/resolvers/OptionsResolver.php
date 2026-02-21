<?php

/**
 * Options field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\ButtonGroup;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\MultiSelect;
use craft\fields\RadioButtons;

/**
 * Resolves option-based field types to their selected value(s).
 *
 * Handles Dropdown, RadioButtons, ButtonGroup (single value) and
 * Checkboxes, MultiSelect (multiple values). Single-value fields return
 * a string; multi-value fields return an array of selected values.
 *
 * @author cogapp
 * @since 1.0.0
 */
class OptionsResolver implements FieldResolverInterface
{
    /** @phpstan-var array<int, class-string> Field types that allow multiple selected values. */
    private const MULTI_VALUE_TYPES = [
        Checkboxes::class,
        MultiSelect::class,
    ];

    /**
     * @inheritdoc
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null || $field->handle === null) {
            return null;
        }

        $value = $element->getFieldValue($field->handle);

        if ($value === null) {
            return null;
        }

        if ($this->_isMultiValueField($field)) {
            return $this->_resolveMultiValue($value);
        }

        return $this->_resolveSingleValue($value);
    }

    /**
     * Determine whether the given field supports multiple selected values.
     *
     * @param FieldInterface $field The field to check.
     * @return bool True if the field is a multi-value type.
     */
    private function _isMultiValueField(FieldInterface $field): bool
    {
        foreach (self::MULTI_VALUE_TYPES as $type) {
            if ($field instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a single-value option field to its string representation.
     *
     * @param mixed $value The raw field value.
     * @return string|null The selected value as a string, or null if empty.
     */
    private function _resolveSingleValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Resolve a multi-value option field to an array of selected values.
     *
     * @param mixed $value The raw field value (iterable of options).
     * @return array<int, string>|null Array of selected option values, or null if none selected.
     */
    private function _resolveMultiValue(mixed $value): ?array
    {
        if (!is_iterable($value)) {
            return null;
        }

        $selected = [];

        foreach ($value as $option) {
            if (!is_object($option) || !isset($option->selected, $option->value)) {
                continue;
            }
            if ($option->selected) {
                $selected[] = $option->value;
            }
        }

        return empty($selected) ? null : $selected;
    }

    /**
     * @inheritdoc
     * @return array<int, class-string>
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Dropdown::class,
            RadioButtons::class,
            Checkboxes::class,
            MultiSelect::class,
            ButtonGroup::class,
        ];
    }
}
