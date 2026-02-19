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
        'quality' => 82,
    ];
    private const DEFAULT_IMAGE_SRCSET_WIDTHS = [240, 320, 400, 560, 720];
    private const DEFAULT_IMAGE_SIZES = '(min-width: 1024px) 220px, (min-width: 640px) 50vw, 100vw';

    /**
     * Inject responsive image metadata for image/thumbnail roles when the
     * underlying indexed role value is a numeric Craft Asset ID.
     *
     * Adds `_responsiveImages` to each hit, keyed by role name (`image`, `thumbnail`).
     *
     * @param array $rawHits The original raw hits from the engine.
     * @param array $hitsWithRoles Hits after role injection.
     * @param Index $index The index containing role mapping information.
     * @return array Hits with `_responsiveImages` metadata injected where possible.
     */
    public function injectForHits(array $rawHits, array $hitsWithRoles, Index $index): array
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

        $assets = Asset::find()->id(array_keys($assetIds))->all();
        $assetsById = [];
        foreach ($assets as $asset) {
            $assetsById[$asset->id] = $asset;
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

                $responsive[$role] = $this->buildMeta($asset);
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
     * @return array{src:string,srcset:string,sizes:string,width:int,height:int,assetId:int,alt:?string,title:?string}
     */
    public function buildMeta(Asset $asset): array
    {
        $transform = self::DEFAULT_IMAGE_TRANSFORM;
        $baseWidth = (int)$transform['width'];
        $baseHeight = (int)$transform['height'];
        $src = (string)$asset->getUrl($transform);

        $srcsetParts = [];
        foreach (self::DEFAULT_IMAGE_SRCSET_WIDTHS as $width) {
            $candidate = $transform;
            $candidate['width'] = $width;
            $candidate['height'] = (int)round(($width * $baseHeight) / $baseWidth);
            $srcsetParts[] = $asset->getUrl($candidate) . ' ' . $width . 'w';
        }

        return [
            'src' => $src,
            'srcset' => implode(', ', $srcsetParts),
            'sizes' => self::DEFAULT_IMAGE_SIZES,
            'width' => $baseWidth,
            'height' => $baseHeight,
            'assetId' => (int)$asset->id,
            // Preserve explicit empty alt ("") when editors intentionally mark decorative images.
            'alt' => $asset->alt,
            'title' => $asset->title,
        ];
    }
}
