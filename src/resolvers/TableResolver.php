<?php

/**
 * Table field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Table;

/**
 * Resolves Table fields to a single concatenated text string.
 *
 * Iterates all rows and cells, strips HTML tags, and joins
 * non-empty cell values with spaces.
 *
 * @author cogapp
 * @since 1.0.0
 */
class TableResolver implements FieldResolverInterface
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

        if ($value === null || !is_array($value) || empty($value)) {
            return null;
        }

        $parts = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }

                $text = strip_tags((string) $cell);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return implode(' ', $parts);
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Table::class,
        ];
    }
}
