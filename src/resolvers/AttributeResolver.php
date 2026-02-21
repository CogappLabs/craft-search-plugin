<?php

/**
 * Attribute resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use DateTime;

/**
 * Resolves built-in element attributes (not custom fields) for indexing.
 *
 * Handles title, slug, postDate, dateCreated, dateUpdated, uri, and status.
 * Date attributes are returned as Unix timestamps. This resolver does not
 * require a field instance -- it reads directly from element properties.
 *
 * @author cogapp
 * @since 1.0.0
 */
class AttributeResolver implements FieldResolverInterface
{
    /** @phpstan-var array<int, string> Element attribute names this resolver can handle. */
    private const SUPPORTED_ATTRIBUTES = [
        'title',
        'slug',
        'postDate',
        'dateCreated',
        'dateUpdated',
        'uri',
        'status',
    ];

    /** @phpstan-var array<int, string> Attribute names that contain DateTime values. */
    private const DATE_ATTRIBUTES = [
        'postDate',
        'dateCreated',
        'dateUpdated',
    ];

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     * @return array<int, class-string>
     */
    public static function supportedFieldTypes(): array
    {
        return [];
    }
}
