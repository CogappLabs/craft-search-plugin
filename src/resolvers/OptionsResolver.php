<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\ButtonGroup;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\MultiSelect;
use craft\fields\RadioButtons;

class OptionsResolver implements FieldResolverInterface
{
    private const MULTI_VALUE_TYPES = [
        Checkboxes::class,
        MultiSelect::class,
    ];

    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null) {
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

    private function _isMultiValueField(FieldInterface $field): bool
    {
        foreach (self::MULTI_VALUE_TYPES as $type) {
            if ($field instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function _resolveSingleValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function _resolveMultiValue(mixed $value): ?array
    {
        if (!is_iterable($value)) {
            return null;
        }

        $selected = [];

        foreach ($value as $option) {
            if ($option->selected) {
                $selected[] = $option->value;
            }
        }

        return empty($selected) ? null : $selected;
    }

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
