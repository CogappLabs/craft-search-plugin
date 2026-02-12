<?php

/**
 * Asset field resolver for the Search Index plugin.
 */

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\fields\Assets;

/**
 * Resolves Asset fields to URLs or structured asset data.
 *
 * Supports three modes via the `mode` resolver config option:
 * - "first_url" (default): Returns the URL of the first asset.
 * - "all_urls": Returns an array of all asset URLs.
 * - "object": Returns an array of asset objects with id, url, title, and filename.
 *
 * @author cogapp
 * @since 1.0.0
 */
class AssetResolver implements FieldResolverInterface
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

    /**
     * Resolve the URL of the first asset in the query.
     *
     * @param mixed $query The asset element query.
     * @return string|null The asset URL, or null if no asset exists.
     */
    private function _resolveFirstUrl(mixed $query): ?string
    {
        $asset = $query->one();

        if ($asset === null) {
            return null;
        }

        return $asset->getUrl();
    }

    /**
     * Resolve all assets to an array of their URLs.
     *
     * @param array $assets Array of asset elements.
     * @return array|null Array of URL strings, or null if none have URLs.
     */
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

    /**
     * Resolve all assets to an array of structured objects.
     *
     * @param array $assets Array of asset elements.
     * @return array|null Array of associative arrays with id, url, title, and filename.
     */
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

    /**
     * @inheritdoc
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Assets::class,
        ];
    }
}
