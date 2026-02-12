<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Addresses;

class AddressResolver implements FieldResolverInterface
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

    private function _resolveGeoPoint(mixed $address): ?array
    {
        $latitude = $address->latitude ?? null;
        $longitude = $address->longitude ?? null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'lat' => (float) $latitude,
            'lng' => (float) $longitude,
        ];
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Addresses::class,
        ];
    }
}
