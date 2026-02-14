<?php

/**
 * Search Index plugin for Craft CMS -- SearchDocumentValue.
 */

namespace cogapp\searchindex\fields;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use craft\elements\Asset;
use craft\elements\Entry;

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
     * Cached map of role → index field name.
     *
     * @var array<string, string>|null
     */
    private ?array $_roleMap = null;

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
                // Leave _document as null
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

        return Asset::find()->id($assetIdInt)->one();
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
     * Return the URL of the Asset assigned the "image" role.
     *
     * @return string|null
     */
    public function getImageUrl(): ?string
    {
        return $this->getImage()?->getUrl();
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

        return $value !== null ? (string)$value : null;
    }

    /**
     * Build and cache the role → index field name map from the index's field mappings.
     *
     * @return array<string, string>
     */
    private function _getRoleMap(): array
    {
        if ($this->_roleMap !== null) {
            return $this->_roleMap;
        }

        $this->_roleMap = [];

        try {
            $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
            if ($index) {
                foreach ($index->getFieldMappings() as $mapping) {
                    if ($mapping->enabled && $mapping->role !== null) {
                        $this->_roleMap[$mapping->role] = $mapping->indexFieldName;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Leave empty
        }

        return $this->_roleMap;
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
