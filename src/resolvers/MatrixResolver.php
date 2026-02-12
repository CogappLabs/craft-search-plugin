<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\fields\Matrix;

class MatrixResolver implements FieldResolverInterface
{
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
     * Handles ElementQuery (relations/assets), scalars, and __toString objects.
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

    public static function supportedFieldTypes(): array
    {
        return [
            Matrix::class,
        ];
    }
}
