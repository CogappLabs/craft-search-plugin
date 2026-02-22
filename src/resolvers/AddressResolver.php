<?php

/**
 * Address field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Addresses;

/**
 * Resolves Address fields to a text string or geo-point coordinates.
 *
 * Supports two modes via the `mode` resolver config option:
 * - "text" (default): Returns a comma-separated address string.
 * - "geo_point": Returns latitude/longitude as an associative array.
 *
 * @author cogapp
 * @since 1.0.0
 */
class AddressResolver implements FieldResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed
    {
        if ($field === null || $field->handle === null) {
            return null;
        }

        /** @var \craft\elements\db\AddressQuery<int, \craft\elements\Address>|null $query */
        $query = $element->getFieldValue($field->handle);

        if ($query === null) {
            return null;
        }

        $address = $query->one();

        if ($address === null) {
            return null;
        }

        $mode = $mapping->resolverConfig['mode'] ?? 'text';

        if ($mode === 'geo_point') {
            return $this->_resolveGeoPoint($address);
        }

        return $this->_resolveText($address);
    }

    /**
     * Resolve an address to a comma-separated text string.
     *
     * @param mixed $address The address element.
     * @return string|null The formatted address string, or null if all parts are empty.
     */
    private function _resolveText(mixed $address): ?string
    {
        $parts = array_filter([
            $address->addressLine1 ?? null,
            $address->addressLine2 ?? null,
            $address->locality ?? null,
            $address->administrativeArea ?? null,
            $address->postalCode ?? null,
            $address->countryCode ?? null,
        ]);

        if (empty($parts)) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * Resolve an address to a geo-point with latitude and longitude.
     *
     * @param mixed $address The address element.
     * @return array{lat: float, lon: float}|null Associative array with "lat" and "lon" keys, or null if coordinates are missing.
     */
    private function _resolveGeoPoint(mixed $address): ?array
    {
        $latitude = $address->latitude ?? null;
        $longitude = $address->longitude ?? null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'lat' => (float) $latitude,
            'lon' => (float) $longitude,
        ];
    }

    /**
     * @inheritdoc
     * @return array<int, class-string>
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Addresses::class,
        ];
    }
}
