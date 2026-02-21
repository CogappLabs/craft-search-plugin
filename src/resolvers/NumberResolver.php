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
        if ($field === null || $field->handle === null) {
            return null;
        }

        $value = $element->getFieldValue($field->handle);

        if ($value === null) {
            return null;
        }

        // Money fields return a Money\Money object — extract the amount
        // Use currency-aware subunit conversion (e.g. JPY has 0 decimals, BHD has 3)
        if (is_object($value) && method_exists($value, 'getAmount')) {
            if (method_exists($value, 'getCurrency')) {
                $currency = $value->getCurrency();
                if (is_object($currency) && method_exists($currency, 'getDefaultFractionDigits')) {
                    $divisor = 10 ** $currency->getDefaultFractionDigits();
                    return $divisor > 0 ? (float) ((int) $value->getAmount() / $divisor) : (float) (int) $value->getAmount();
                }
            }
            return (float) ((int) $value->getAmount() / 100);
        }

        if (is_int($value)) {
            return $value;
        }

        return (float) $value;
    }

    /**
     * @inheritdoc
     * @return array<int, class-string>
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
