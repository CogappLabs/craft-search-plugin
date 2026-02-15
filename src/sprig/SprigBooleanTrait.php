<?php

/**
 * Search Index plugin for Craft CMS.
 */

namespace cogapp\searchindex\sprig;

/**
 * Shared boolean coercion for Sprig component properties.
 *
 * Sprig serialises all component state as strings, so boolean properties
 * arrive as mixed types ('1', 'true', 'on', true, 1, etc.) across requests.
 * This trait centralises the coercion logic used by every component.
 */
trait SprigBooleanTrait
{
    /**
     * Coerce a Sprig property value to a strict boolean.
     *
     * Accepts native booleans, integers, and the string values
     * '1', 'true', 'yes', 'on' (case-sensitive) as truthy.
     */
    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    }
}
