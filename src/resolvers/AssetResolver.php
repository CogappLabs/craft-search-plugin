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
 * Resolves Asset fields to asset IDs, URLs, or structured data.
 *
 * Supports four modes via the `mode` resolver config option:
 * - "first_id" (default): Returns the ID of the first asset (integer).
 * - "first_url": Returns the URL of the first asset.
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
        if ($field === null || $field->handle === null) {
            return null;
        }

        /** @var \craft\elements\db\AssetQuery<int, \craft\elements\Asset>|null $query */
        $query = $element->getFieldValue($field->handle);

        if ($query === null) {
            return null;
        }

        $mode = $mapping->resolverConfig['mode'] ?? 'first_id';

        if ($mode === 'first_id') {
            return $this->_resolveFirstId($query);
        }

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

        return $this->_resolveFirstId($query);
    }

    /**
     * Resolve the ID of the first asset in the query.
     *
     * @param mixed $query The asset element query.
     * @return int|null The asset ID, or null if no asset exists.
     */
    private function _resolveFirstId(mixed $query): ?int
    {
        $asset = $query->one();

        return $asset?->id;
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
     * @param array<int, \craft\elements\Asset> $assets Array of asset elements.
     * @return array<int, string>|null Array of URL strings, or null if none have URLs.
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
     * @param array<int, \craft\elements\Asset> $assets Array of asset elements.
     * @return array<int, array{id: int|null, url: string|null, title: string|null, filename: string|null}>|null Array of associative arrays with id, url, title, and filename.
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
     * @return array<int, class-string>
     */
    public static function supportedFieldTypes(): array
    {
        return [
            Assets::class,
        ];
    }
}
