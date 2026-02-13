<?php

/**
 * Search Index plugin for Craft CMS -- SearchDocumentValue.
 */

namespace cogapp\searchindex\fields;

use cogapp\searchindex\SearchIndex;

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
     * Cached document data.
     *
     * @var array|null|false False means not yet fetched.
     */
    private array|null|false $_document = false;

    /**
     * @param string $indexHandle The index handle this document belongs to.
     * @param string $documentId  The document ID within the index.
     */
    public function __construct(string $indexHandle, string $documentId)
    {
        $this->indexHandle = $indexHandle;
        $this->documentId = $documentId;
    }

    /**
     * Lazy-load and return the full document from the search engine.
     *
     * @return array|null The document data, or null if not found.
     */
    public function getDocument(): ?array
    {
        if ($this->_document === false) {
            try {
                $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
                if ($index) {
                    $engineClass = $index->engineType;
                    $engine = new $engineClass($index->engineConfig ?? []);
                    $this->_document = $engine->getDocument($index, $this->documentId);
                } else {
                    $this->_document = null;
                }
            } catch (\Throwable $e) {
                $this->_document = null;
            }
        }

        return $this->_document;
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
        ];
    }

    /**
     * @param array $data
     */
    public function __unserialize(array $data): void
    {
        $this->indexHandle = $data['indexHandle'] ?? '';
        $this->documentId = $data['documentId'] ?? '';
        $this->_document = false;
    }
}
