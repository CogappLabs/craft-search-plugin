<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Lightswitch;

class BooleanResolver implements FieldResolverInterface
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

        return (bool) $value;
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Lightswitch::class,
        ];
    }
}
