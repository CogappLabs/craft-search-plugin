<?php

/**
 * Plain text field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Color;
use craft\fields\Country;
use craft\fields\Email;
use craft\fields\Link;
use craft\fields\PlainText;
use craft\fields\Url;

/**
 * Resolves simple text-based field types to their string representation.
 *
 * Handles PlainText, Email, Url, Link, Color, and Country fields.
 * Returns the value cast to a string, or null if empty.
 *
 * @author cogapp
 * @since 1.0.0
 */
class PlainTextResolver implements FieldResolverInterface
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

        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            PlainText::class,
            Email::class,
            Url::class,
            Link::class,
            Color::class,
            Country::class,
        ];
    }
}
