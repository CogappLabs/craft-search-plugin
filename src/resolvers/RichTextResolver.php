<?php

/**
 * Rich text field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\ckeditor\Field;

/**
 * Resolves CKEditor rich text fields to plain text for indexing.
 *
 * Strips HTML tags and optionally truncates the output based on
 * the `maxLength` resolver config option.
 *
 * @author cogapp
 * @since 1.0.0
 */
class RichTextResolver implements FieldResolverInterface
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

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $maxLength = $mapping->resolverConfig['maxLength'] ?? null;
        if ($maxLength !== null && mb_strlen($text) > (int) $maxLength) {
            $text = mb_substr($text, 0, (int) $maxLength);
        }

        return $text;
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Field::class,
        ];
    }
}
