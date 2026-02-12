<?php

/**
 * Number field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Money;
use craft\fields\Number;
use craft\fields\Range;

/**
 * Resolves numeric field types to their integer or float representation.
 *
 * Handles Number, Range, and Money fields. Preserves integers as-is
 * and casts other values to float.
 *
 * @author cogapp
 * @since 1.0.0
 */
class NumberResolver implements FieldResolverInterface
{
    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Number::class,
            Range::class,
            Money::class,
        ];
    }
}
