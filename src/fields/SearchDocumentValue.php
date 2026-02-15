<?php

/**
 * Search Index plugin for Craft CMS -- SearchDocumentValue.
 */

namespace cogapp\searchindex\fields;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use cogapp\searchindex\services\Indexes;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use yii\caching\TagDependency;

/**
 * Value object representing a reference to a document in a search index.
 *
 * Provides lazy document retrieval — the full document is only fetched
 * from the engine when accessed via getDocument().
 *
 * @property-read string $indexHandle
 * @property-read string $documentId
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchDocumentValue
{
    /**
     * The index handle this document belongs to.
     *
     * @var string
     */
    public string $indexHandle;

    /**
     * The document ID within the index.
     *
     * @var string
     */
    public string $documentId;

    /**
     * The section handle of the referenced entry (if known).
     *
     * @var string|null
     */
    public ?string $sectionHandle;

    /**
     * The entry type handle of the referenced entry (if known).
     *
     * @var string|null
     */
    public ?string $entryTypeHandle;

    /**
     * Whether the document has been fetched from the engine.
     *
     * @var bool
     */
    private bool $_fetched = false;

    /**
     * Cached document data.
     *
     * @var array|null
     */
    private ?array $_document = null;

    /**
     * Shared role map cache keyed by index handle.
     *
     * Role mappings are identical for all documents within the same index,
     * so this avoids rebuilding the map for every SearchDocumentValue instance
     * when looping search results in Twig templates.
     *
     * @var array<string, array<string, string>>
     */
    private static array $_roleMapCache = [];

    /** @var bool Whether the image asset lookup has been performed. */
    private bool $_imageFetched = false;

    /** @var Asset|null Cached image asset. */
    private ?Asset $_image = null;

    /**
     * @param string      $indexHandle     The index handle this document belongs to.
     * @param string      $documentId      The document ID within the index.
     * @param string|null $sectionHandle   The section handle of the referenced entry.
     * @param string|null $entryTypeHandle The entry type handle of the referenced entry.
     */
    public function __construct(
        string $indexHandle,
        string $documentId,
        ?string $sectionHandle = null,
        ?string $entryTypeHandle = null,
    ) {
        $this->indexHandle = $indexHandle;
        $this->documentId = $documentId;
        $this->sectionHandle = $sectionHandle;
        $this->entryTypeHandle = $entryTypeHandle;
    }

    /**
     * Lazy-load and return the full document from the search engine.
     *
     * @return array|null The document data, or null if not found.
     */
    public function getDocument(): ?array
    {
        if (!$this->_fetched) {
            $this->_fetched = true;

            try {
                $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
                if ($index) {
                    $engine = $index->createEngine();
                    $this->_document = $engine->getDocument($index, $this->documentId);

                    // Backfill sectionHandle/entryTypeHandle from the document
                    // if not already stored (e.g. values saved before this feature)
                    if ($this->_document) {
                        if ($this->sectionHandle === null && isset($this->_document['sectionHandle'])) {
                            $this->sectionHandle = (string)$this->_document['sectionHandle'];
                        }
                        if ($this->entryTypeHandle === null && isset($this->_document['entryTypeHandle'])) {
                            $this->entryTypeHandle = (string)$this->_document['entryTypeHandle'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists(Craft::class, false)) {
                    Craft::warning(
                        "Failed to fetch document \"{$this->documentId}\" from index \"{$this->indexHandle}\": " . $e->getMessage(),
                        __METHOD__,
                    );
                }
            }
        }

        return $this->_document;
    }

    /**
     * Return the Craft entry ID (the document ID cast to integer).
     *
     * @return int|null The entry ID, or null if the document ID is not numeric.
     */
    public function getEntryId(): ?int
    {
        return is_numeric($this->documentId) ? (int)$this->documentId : null;
    }

    /**
     * Return the Craft Entry element referenced by this document.
     *
     * @return Entry|null
     */
    public function getEntry(): ?Entry
    {
        $entryId = $this->getEntryId();
        if ($entryId === null) {
            return null;
        }

        return Entry::find()->id($entryId)->status(null)->one();
    }

    /**
     * Return the value of the field assigned the "title" role.
     *
     * @return string|null
     */
    public function getTitle(): ?string
    {
        return $this->_getFieldValueByRole(FieldMapping::ROLE_TITLE);
    }

    /**
     * Return the Craft Asset element assigned the "image" role.
     *
     * The image field stores an asset ID in the search index; this method
     * loads the full Asset element so templates can use transforms, alt text, etc.
     *
     * @return Asset|null
     */
    public function getImage(): ?Asset
    {
        if ($this->_imageFetched) {
            return $this->_image;
        }

        $this->_imageFetched = true;

        $roleMap = $this->_getRoleMap();
        if (!isset($roleMap[FieldMapping::ROLE_IMAGE])) {
            return null;
        }

        $document = $this->getDocument();
        if ($document === null) {
            return null;
        }

        $fieldName = $roleMap[FieldMapping::ROLE_IMAGE];
        $assetId = $document[$fieldName] ?? null;

        if ($assetId === null || (!is_int($assetId) && !is_numeric($assetId))) {
            return null;
        }

        $assetIdInt = (int)$assetId;
        if ($assetIdInt <= 0) {
            return null;
        }

        $this->_image = Asset::find()->id($assetIdInt)->one();

        return $this->_image;
    }

    /**
     * Alias for getImage() — returns the Craft Asset element assigned the "image" role.
     *
     * @return Asset|null
     */
    public function getAsset(): ?Asset
    {
        return $this->getImage();
    }

    /**
     * Return the URL of the image assigned the "image" role.
     *
     * For synced indexes this loads the Craft Asset and returns its URL.
     * For read-only indexes (or when the stored value is a URL string rather
     * than an asset ID) the raw value is returned directly.
     *
     * @return string|null
     */
    public function getImageUrl(): ?string
    {
        // Try the Craft Asset path first
        $asset = $this->getImage();
        if ($asset !== null) {
            return $asset->getUrl();
        }

        // Fall back to the raw value if it looks like a URL
        $raw = $this->_getFieldValueByRole(FieldMapping::ROLE_IMAGE);
        if ($raw !== null && str_starts_with($raw, 'http')) {
            return $raw;
        }

        return null;
    }

    /**
     * Return the value of the field assigned the "summary" role.
     *
     * @return string|null
     */
    public function getSummary(): ?string
    {
        return $this->_getFieldValueByRole(FieldMapping::ROLE_SUMMARY);
    }

    /**
     * Return the value of the field assigned the "url" role.
     *
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->_getFieldValueByRole(FieldMapping::ROLE_URL);
    }

    /**
     * Return the value of the field assigned the "date" role.
     *
     * Returns the raw value as a string (ISO-8601, epoch, etc.).
     * Templates can pipe through Craft's |date filter for formatting.
     *
     * @return string|null
     */
    public function getDate(): ?string
    {
        return $this->_getFieldValueByRole(FieldMapping::ROLE_DATE);
    }

    /**
     * Return the value of the field assigned the "iiif" role.
     *
     * Typically the IIIF Image API info.json URL for this document.
     *
     * @return string|null
     */
    public function getIiifInfoUrl(): ?string
    {
        return $this->_getFieldValueByRole(FieldMapping::ROLE_IIIF);
    }

    /**
     * Return a IIIF Image API URL for a specific size.
     *
     * Derives the base image URL from the info.json URL and appends
     * IIIF Image API parameters. Supports width-only, height-only,
     * or both dimensions.
     *
     * @param int|null $width  Desired width in pixels (null for proportional).
     * @param int|null $height Desired height in pixels (null for proportional).
     * @return string|null The IIIF image URL, or null if no IIIF role is assigned.
     * @see https://iiif.io/api/image/3.0/#4-image-requests
     */
    public function getIiifImageUrl(?int $width = null, ?int $height = null): ?string
    {
        $infoUrl = $this->getIiifInfoUrl();
        if ($infoUrl === null) {
            return null;
        }

        // Strip /info.json to get the base image URL
        $baseUrl = preg_replace('#/info\.json$#', '', $infoUrl);

        // Build IIIF size parameter: "w,", ",h", or "w,h"
        if ($width !== null && $height !== null) {
            $size = "{$width},{$height}";
        } elseif ($width !== null) {
            $size = "{$width},";
        } elseif ($height !== null) {
            $size = ",{$height}";
        } else {
            $size = 'max';
        }

        // IIIF Image API: {base}/{region}/{size}/{rotation}/{quality}.{format}
        return "{$baseUrl}/full/{$size}/0/default.jpg";
    }

    /**
     * Look up the index field name for a role and return its value from the document.
     *
     * @param string $role
     * @return string|null
     */
    private function _getFieldValueByRole(string $role): ?string
    {
        $roleMap = $this->_getRoleMap();
        if (!isset($roleMap[$role])) {
            return null;
        }

        $document = $this->getDocument();
        if ($document === null) {
            return null;
        }

        $fieldName = $roleMap[$role];
        $value = $document[$fieldName] ?? null;

        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * Build and cache the role → index field name map from the index's field mappings.
     *
     * Uses both a per-request static cache and a persistent data cache with
     * tag-based invalidation so the role map survives across requests.
     *
     * @return array<string, string>
     */
    private function _getRoleMap(): array
    {
        if (isset(self::$_roleMapCache[$this->indexHandle])) {
            return self::$_roleMapCache[$this->indexHandle];
        }

        // Try persistent cache
        $cache = Craft::$app->getCache();
        $cacheKey = 'searchIndex:roleMap:' . $this->indexHandle;
        $cached = $cache->get($cacheKey);

        if ($cached !== false && is_array($cached)) {
            self::$_roleMapCache[$this->indexHandle] = $cached;
            return $cached;
        }

        $roleMap = [];

        try {
            $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
            if ($index) {
                foreach ($index->getFieldMappings() as $mapping) {
                    if ($mapping->enabled && $mapping->role !== null) {
                        $roleMap[$mapping->role] = $mapping->indexFieldName;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (class_exists(Craft::class, false)) {
                Craft::warning(
                    "Failed to build role map for index \"{$this->indexHandle}\": " . $e->getMessage(),
                    __METHOD__,
                );
            }
        }

        self::$_roleMapCache[$this->indexHandle] = $roleMap;

        // Persist with tag dependency — invalidated when indexes change
        $cache->set(
            $cacheKey,
            $roleMap,
            0,
            new TagDependency(['tags' => [Indexes::CACHE_TAG]]),
        );

        return $roleMap;
    }

    /**
     * @return string The document ID.
     */
    public function __toString(): string
    {
        return $this->documentId;
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'indexHandle' => $this->indexHandle,
            'documentId' => $this->documentId,
            'sectionHandle' => $this->sectionHandle,
            'entryTypeHandle' => $this->entryTypeHandle,
        ];
    }

    /**
     * @param array $data
     */
    public function __unserialize(array $data): void
    {
        $this->indexHandle = $data['indexHandle'] ?? '';
        $this->documentId = $data['documentId'] ?? '';
        $this->sectionHandle = $data['sectionHandle'] ?? null;
        $this->entryTypeHandle = $data['entryTypeHandle'] ?? null;
    }
}
