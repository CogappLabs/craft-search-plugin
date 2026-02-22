<?php

/**
 * Relation field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fields\Users;

/**
 * Resolves relational field types to arrays of related element data.
 *
 * Handles Categories, Tags, Entries, and Users fields. Output format
 * is controlled by the `format` resolver config option:
 * - "titles" (default): Array of element titles.
 * - "ids": Array of element IDs.
 * - "slugs": Array of element slugs.
 * - "objects": Array of associative arrays with id, title, and slug.
 *
 * @author cogapp
 * @since 1.0.0
 */
class RelationResolver implements FieldResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null || $field->handle === null) {
            return null;
        }

        /** @var \craft\elements\db\ElementQuery<int, \craft\base\Element>|null $query */
        $query = $element->getFieldValue($field->handle);

        if ($query === null) {
            return null;
        }

        $relatedElements = $query->all();

        if (empty($relatedElements)) {
            return null;
        }

        $format = $mapping->resolverConfig['format'] ?? 'titles';

        return match ($format) {
            'ids' => array_map(fn($el) => $el->id, $relatedElements),
            'slugs' => array_filter(array_map(fn($el) => $el->slug, $relatedElements)),
            'objects' => array_map(fn($el) => [
                'id' => $el->id,
                'title' => $el->title,
                'slug' => $el->slug,
            ], $relatedElements),
            default => array_values(array_filter(array_map(fn($el) => $el->title, $relatedElements))),
        };
    }

    /**
     * @inheritdoc
     * @return array<int, class-string>
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Categories::class,
            Tags::class,
            Entries::class,
            Users::class,
        ];
    }
}
