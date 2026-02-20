<?php

/**
 * Geo-point field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Number;

/**
 * Combines separate latitude and longitude fields into a single geo-point value.
 *
 * The mapping's `fieldUid` should point to the latitude field. The longitude
 * field handle is read from `resolverConfig['lngFieldHandle']`, which is
 * auto-detected during field mapping detection by scanning for sibling fields
 * with matching naming patterns (e.g. placeLatitude → placeLongitude).
 *
 * @author cogapp
 * @since 1.0.0
 */
class GeoPointResolver implements FieldResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null) {
            return null;
        }

        $lat = $element->getFieldValue($field->handle);
        if ($lat === null) {
            return null;
        }

        $lngHandle = $mapping->resolverConfig['lngFieldHandle'] ?? null;
        if ($lngHandle === null) {
            Craft::warning(
                "GeoPointResolver: missing 'lngFieldHandle' in resolverConfig for mapping '{$mapping->indexFieldName}'",
                __METHOD__
            );
            return null;
        }

        $lng = $element->getFieldValue($lngHandle);
        if ($lng === null) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lng,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Number::class,
        ];
    }
}
