<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Table;

class TableResolver implements FieldResolverInterface
{
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

    public static function supportedFieldTypes(): array
    {
        return [
            Table::class,
        ];
    }
}
