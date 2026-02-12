<?php

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

class PlainTextResolver implements FieldResolverInterface
{
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
