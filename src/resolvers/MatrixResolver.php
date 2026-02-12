<?php

/**
 * Matrix field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\fields\Matrix;

/**
 * Resolves Matrix fields by extracting text from all nested entries.
 *
 * Supports two modes via the `mode` resolver config option:
 * - "concatenate" (default): Joins all sub-field values into a single string,
 *   optionally truncated by `maxLength`.
 * - "structured": Returns an array of entry objects, each containing the
 *   entry type handle and all sub-field values.
 *
 * Also provides sub-field resolution for targeting a specific field
 * across all Matrix entries.
 *
 * @author cogapp
 * @since 1.0.0
 */
class MatrixResolver implements FieldResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null) {
            return null;
        }

        $query = $element->getFieldValue($field->handle);

        if ($query === null) {
            return null;
        }

        $entries = $query->all();

        if (empty($entries)) {
            return null;
        }

        $mode = $mapping->resolverConfig['mode'] ?? 'concatenate';
        $maxLength = $mapping->resolverConfig['maxLength'] ?? null;

        if ($mode === 'structured') {
            return $this->_resolveStructured($entries);
        }

        return $this->_resolveConcatenated($entries, $maxLength);
    }

    /**
     * Concatenate all sub-field values from Matrix entries into a single string.
     *
     * @param array $entries Array of Matrix entry elements.
     * @param int|null $maxLength Maximum character length for the result, or null for no limit.
     * @return string|null The concatenated text, or null if no values were found.
     */
    private function _resolveConcatenated(array $entries, ?int $maxLength): ?string
    {
        $parts = [];

        foreach ($entries as $entry) {
            $fieldLayout = $entry->getFieldLayout();

            if ($fieldLayout === null) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $blockField) {
                $value = $entry->getFieldValue($blockField->handle);

                if ($value === null || $value === '') {
                    continue;
                }

                $text = $this->resolveBlockFieldValue($value);

                if ($text !== null && $text !== '') {
                    $parts[] = $text;
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        $result = implode(' ', $parts);

        if ($maxLength !== null && mb_strlen($result) > (int) $maxLength) {
            $result = mb_substr($result, 0, (int) $maxLength);
        }

        return $result;
    }

    /**
     * Resolve Matrix entries to an array of structured objects with type and field data.
     *
     * @param array $entries Array of Matrix entry elements.
     * @return array|null Array of associative arrays keyed by field handle, or null if empty.
     */
    private function _resolveStructured(array $entries): ?array
    {
        $result = [];

        foreach ($entries as $entry) {
            $blockData = [
                'type' => $entry->type->handle ?? null,
            ];

            $fieldLayout = $entry->getFieldLayout();

            if ($fieldLayout === null) {
                $result[] = $blockData;
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $blockField) {
                $value = $entry->getFieldValue($blockField->handle);

                if ($value === null) {
                    $blockData[$blockField->handle] = null;
                    continue;
                }

                $blockData[$blockField->handle] = $this->resolveBlockFieldValue($value);
            }

            $result[] = $blockData;
        }

        return empty($result) ? null : $result;
    }

    /**
     * Resolve a single sub-field across all Matrix entries.
     *
     * For facet/keyword index types with relation fields, individual related
     * element titles are flattened into the result array. Otherwise, resolved
     * values are concatenated with spaces.
     *
     * @param Element $element The parent Craft element.
     * @param FieldInterface $matrixField The Matrix field instance.
     * @param FieldInterface $subField The target sub-field within the Matrix.
     * @param FieldMapping $mapping The field mapping configuration.
     * @return mixed Array of values for array types, concatenated string otherwise, or null.
     */
    public function resolveSubField(Element $element, FieldInterface $matrixField, FieldInterface $subField, FieldMapping $mapping): mixed
    {
        $query = $element->getFieldValue($matrixField->handle);
        if ($query === null) {
            return null;
        }

        $entries = $query->all();
        if (empty($entries)) {
            return null;
        }

        $isArrayType = in_array($mapping->indexFieldType, [
            FieldMapping::TYPE_FACET,
            FieldMapping::TYPE_KEYWORD,
        ], true);

        $parts = [];

        foreach ($entries as $entry) {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                continue;
            }

            // Check if this entry type has the sub-field
            $hasField = false;
            foreach ($fieldLayout->getCustomFields() as $blockField) {
                if ($blockField->handle === $subField->handle) {
                    $hasField = true;
                    break;
                }
            }
            if (!$hasField) {
                continue;
            }

            $value = $entry->getFieldValue($subField->handle);
            if ($value === null || $value === '') {
                continue;
            }

            // For array types with relation fields, flatten individual elements
            if ($isArrayType && $value instanceof ElementQuery) {
                foreach ($value->all() as $el) {
                    $title = ($el instanceof Asset) ? ($el->title ?? $el->filename) : ($el->title ?? (string)$el);
                    if ($title !== null && $title !== '') {
                        $parts[] = $title;
                    }
                }
                continue;
            }

            $resolved = $this->resolveBlockFieldValue($value);
            if ($resolved !== null && $resolved !== '') {
                $parts[] = $resolved;
            }
        }

        if (empty($parts)) {
            return null;
        }

        if ($isArrayType) {
            return $parts;
        }

        return implode(' ', $parts);
    }

    /**
     * Resolve a block field value to an indexable string.
     *
     * Handles ElementQuery (relations/assets), scalars, and __toString objects.
     * HTML tags are stripped from scalar and stringable values.
     *
     * @param mixed $value The raw sub-field value.
     * @return string|null The resolved string, or null if the value cannot be converted.
     */
    public function resolveBlockFieldValue(mixed $value): ?string
    {
        // Relation/Asset fields return ElementQuery - resolve to titles or URLs
        if ($value instanceof ElementQuery) {
            $elements = $value->all();
            if (empty($elements)) {
                return null;
            }

            $parts = [];
            foreach ($elements as $el) {
                if ($el instanceof Asset) {
                    $parts[] = $el->title ?? $el->filename;
                } else {
                    $parts[] = $el->title ?? (string) $el;
                }
            }
            return implode(', ', array_filter($parts));
        }

        // Scalar values
        if (is_scalar($value)) {
            return strip_tags((string) $value);
        }

        // Objects with __toString (e.g. RichText markup)
        if (is_object($value) && method_exists($value, '__toString')) {
            return strip_tags((string) $value);
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Matrix::class,
        ];
    }
}
