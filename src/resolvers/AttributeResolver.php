<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use DateTime;

class AttributeResolver implements FieldResolverInterface
{
    private const SUPPORTED_ATTRIBUTES = [
        'title',
        'slug',
        'postDate',
        'dateCreated',
        'dateUpdated',
        'uri',
        'status',
    ];

    private const DATE_ATTRIBUTES = [
        'postDate',
        'dateCreated',
        'dateUpdated',
    ];

    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        $attribute = $mapping->attribute;

        if ($attribute === null || !in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)) {
            return null;
        }

        $value = $element->$attribute ?? null;

        if ($value === null) {
            return null;
        }

        if (in_array($attribute, self::DATE_ATTRIBUTES, true) && $value instanceof DateTime) {
            return $value->getTimestamp();
        }

        return $value;
    }

    public static function supportedFieldTypes(): array
    {
        return [];
    }
}
