<?php

/**
 * Search Index plugin for Craft CMS -- ResponsiveImages service.
 */

namespace cogapp\searchindex\services;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use craft\elements\Asset;
use yii\base\Component;

/**
 * Builds responsive image metadata for API hits when role fields contain
 * Craft Asset IDs.
 *
 * @author cogapp
 * @since 1.0.0
 */
class ResponsiveImages extends Component
{
    private const DEFAULT_IMAGE_TRANSFORM = [
        'mode' => 'crop',
        'width' => 400,
        'height' => 250,
        'quality' => 65,
        'format' => 'webp',
    ];
    private const DEFAULT_IMAGE_SRCSET_WIDTHS = [220, 320, 440];
    private const DEFAULT_IMAGE_SIZES = '(min-width: 1024px) 220px, (min-width: 640px) 50vw, 100vw';

    private const THUMBNAIL_TRANSFORM = [
        'mode' => 'crop',
        'width' => 48,
        'height' => 48,
        'quality' => 78,
        'format' => 'webp',
    ];
    private const THUMBNAIL_SRCSET_WIDTHS = [48, 72, 96];
    private const THUMBNAIL_SIZES = '40px';

    /**
     * Inject responsive image metadata for image/thumbnail roles when the
     * underlying indexed role value is a numeric Craft Asset ID.
     *
     * Adds `_responsiveImages` to each hit, keyed by role name (`image`, `thumbnail`).
     *
     * @param array $rawHits The original raw hits from the engine.
     * @param array $hitsWithRoles Hits after role injection.
     * @param Index $index The index containing role mapping information.
     * @param array<int, Asset>|null $preloadedAssets Pre-loaded Asset objects (id → Asset) from SearchResolver::injectRoles(). When provided, skips the DB query for assets that are already loaded.
     * @return array Hits with `_responsiveImages` metadata injected where possible.
     */
    public function injectForHits(array $rawHits, array $hitsWithRoles, Index $index, ?array $preloadedAssets = null): array
    {
        $roleMap = $index->getRoleFieldMap();
        $assetRoleFields = [];

        foreach ([FieldMapping::ROLE_IMAGE, FieldMapping::ROLE_THUMBNAIL] as $role) {
            $field = $roleMap[$role] ?? null;
            if (is_string($field) && $field !== '') {
                $assetRoleFields[$role] = $field;
            }
        }

        if (empty($assetRoleFields)) {
            return $hitsWithRoles;
        }

        $assetIds = [];
        foreach ($rawHits as $rawHit) {
            if (!is_array($rawHit)) {
                continue;
            }
            foreach ($assetRoleFields as $fieldName) {
                $value = $rawHit[$fieldName] ?? null;
                if (is_numeric($value)) {
                    $assetIds[(int)$value] = true;
                }
            }
        }

        if (empty($assetIds)) {
            return $hitsWithRoles;
        }

        // Reuse preloaded assets from injectRoles() when available;
        // only query the DB for any IDs not already loaded.
        $assetsById = $preloadedAssets ?? [];
        $missingIds = array_diff(array_keys($assetIds), array_keys($assetsById));
        if (!empty($missingIds)) {
            $assets = Asset::find()->id($missingIds)->all();
            foreach ($assets as $asset) {
                $assetsById[$asset->id] = $asset;
            }
        }

        foreach ($hitsWithRoles as $i => &$hit) {
            $rawHit = $rawHits[$i] ?? null;
            if (!is_array($rawHit)) {
                continue;
            }

            $responsive = [];
            foreach ($assetRoleFields as $role => $fieldName) {
                $value = $rawHit[$fieldName] ?? null;
                if (!is_numeric($value)) {
                    continue;
                }

                $asset = $assetsById[(int)$value] ?? null;
                if (!$asset) {
                    continue;
                }

                $responsive[$role] = $role === FieldMapping::ROLE_THUMBNAIL
                    ? $this->buildMeta($asset, self::THUMBNAIL_TRANSFORM, self::THUMBNAIL_SRCSET_WIDTHS, self::THUMBNAIL_SIZES)
                    : $this->buildMeta($asset);
            }

            if (!empty($responsive)) {
                $hit['_responsiveImages'] = $responsive;
            }
        }
        unset($hit);

        return $hitsWithRoles;
    }

    /**
     * Build responsive image metadata for a Craft asset.
     *
     * @param array|null $transform  Image transform config (defaults to DEFAULT_IMAGE_TRANSFORM).
     * @param int[]|null $srcsetWidths  Widths for srcset candidates (defaults to DEFAULT_IMAGE_SRCSET_WIDTHS).
     * @param string|null $sizes  Sizes attribute value (defaults to DEFAULT_IMAGE_SIZES).
     * @return array{src:string,srcset:string,sizes:string,width:int,height:int,assetId:int,alt:?string,title:?string}
     */
    public function buildMeta(
        Asset $asset,
        ?array $transform = null,
        ?array $srcsetWidths = null,
        ?string $sizes = null,
    ): array {
        $transform ??= self::DEFAULT_IMAGE_TRANSFORM;
        $srcsetWidths ??= self::DEFAULT_IMAGE_SRCSET_WIDTHS;
        $sizes ??= self::DEFAULT_IMAGE_SIZES;

        $baseWidth = (int)$transform['width'];
        $baseHeight = (int)$transform['height'];
        $src = (string)$asset->getUrl($transform);

        $srcsetParts = [];
        foreach ($srcsetWidths as $width) {
            $candidate = $transform;
            $candidate['width'] = $width;
            $candidate['height'] = (int)round(($width * $baseHeight) / $baseWidth);
            $srcsetParts[] = $asset->getUrl($candidate) . ' ' . $width . 'w';
        }

        return [
            'src' => $src,
            'srcset' => implode(', ', $srcsetParts),
            'sizes' => $sizes,
            'width' => $baseWidth,
            'height' => $baseHeight,
            'assetId' => (int)$asset->id,
            // Preserve explicit empty alt ("") when editors intentionally mark decorative images.
            'alt' => $asset->alt,
            'title' => $asset->title,
        ];
    }
}
