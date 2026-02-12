<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Date;
use craft\fields\Time;
use DateTime;

class DateResolver implements FieldResolverInterface
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

        if (!$value instanceof DateTime) {
            return null;
        }

        $format = $mapping->resolverConfig['format'] ?? 'timestamp';

        if ($format === 'iso') {
            return $value->format('c');
        }

        return $value->getTimestamp();
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Date::class,
            Time::class,
        ];
    }
}
