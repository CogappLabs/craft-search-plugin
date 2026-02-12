<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Money;
use craft\fields\Number;
use craft\fields\Range;

class NumberResolver implements FieldResolverInterface
{
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null) {
            return null;
        }

        $value = $element->getFieldValue($field->handle);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return (float) $value;
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Number::class,
            Range::class,
            Money::class,
        ];
    }
}
