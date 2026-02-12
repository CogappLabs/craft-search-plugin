<?php

/**
 * Date field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Date;
use craft\fields\Time;
use DateTime;

/**
 * Resolves Date and Time fields to a timestamp or ISO-8601 string.
 *
 * Output format is controlled by the `format` resolver config option:
 * "iso" returns an ISO-8601 string, anything else returns a Unix timestamp.
 *
 * @author cogapp
 * @since 1.0.0
 */
class DateResolver implements FieldResolverInterface
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

        if (!$value instanceof DateTime) {
            return null;
        }

        $format = $mapping->resolverConfig['format'] ?? 'timestamp';

        if ($format === 'iso') {
            return $value->format('c');
        }

        return $value->getTimestamp();
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Date::class,
            Time::class,
        ];
    }
}
