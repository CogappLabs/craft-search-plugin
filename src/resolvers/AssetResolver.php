<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Assets;

class AssetResolver implements FieldResolverInterface
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

        $mode = $mapping->resolverConfig['mode'] ?? 'first_url';

        if ($mode === 'first_url') {
            return $this->_resolveFirstUrl($query);
        }

        $assets = $query->all();

        if (empty($assets)) {
            return null;
        }

        if ($mode === 'all_urls') {
            return $this->_resolveAllUrls($assets);
        }

        if ($mode === 'object') {
            return $this->_resolveObjects($assets);
        }

        return $this->_resolveFirstUrl($query);
    }

    private function _resolveFirstUrl(mixed $query): ?string
    {
        $asset = $query->one();

        if ($asset === null) {
            return null;
        }

        return $asset->getUrl();
    }

    private function _resolveAllUrls(array $assets): ?array
    {
        $urls = [];

        foreach ($assets as $asset) {
            $url = $asset->getUrl();
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return empty($urls) ? null : $urls;
    }

    private function _resolveObjects(array $assets): ?array
    {
        $result = [];

        foreach ($assets as $asset) {
            $result[] = [
                'id' => $asset->id,
                'url' => $asset->getUrl(),
                'title' => $asset->title,
                'filename' => $asset->filename,
            ];
        }

        return empty($result) ? null : $result;
    }

    public static function supportedFieldTypes(): array
    {
        return [
            Assets::class,
        ];
    }
}
