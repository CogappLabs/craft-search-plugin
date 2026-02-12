<?php

/**
 * Field resolver interface for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;

/**
 * Contract for all field resolvers that extract indexable values from Craft elements.
 *
 * Each implementation handles one or more Craft field types and converts
 * their values into a format suitable for search engine indexing.
 *
 * @author cogapp
 * @since 1.0.0
 */
interface FieldResolverInterface
{
    /**
     * Resolve the indexable value for a given element and field.
     *
     * @param Element $element The Craft element to extract data from.
     * @param FieldInterface|null $field The Craft field instance, or null for attribute-based resolvers.
     * @param FieldMapping $mapping The field mapping configuration.
     * @return mixed The resolved value suitable for indexing, or null if unavailable.
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed;

    /**
     * Return the list of Craft field type classes this resolver supports.
     *
     * @return array List of fully qualified field class names.
     */
    public static function supportedFieldTypes(): array;
}
