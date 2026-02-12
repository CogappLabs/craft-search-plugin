<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fields\Users;

class RelationResolver implements FieldResolverInterface
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
