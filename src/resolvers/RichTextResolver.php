<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\ckeditor\Field;

class RichTextResolver implements FieldResolverInterface
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

        $text = strip_tags((string) $value);

        $maxLength = $mapping->resolverConfig['maxLength'] ?? null;
        if ($maxLength !== null && mb_strlen($text) > (int) $maxLength) {
            $text = mb_substr($text, 0, (int) $maxLength);
        }

        return $text;
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Field::class,
        ];
    }
}
