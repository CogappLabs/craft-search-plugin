<?php

/**
 * Search engine interface for the craft-search-index plugin.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;

/**
 * Contract that all search engine implementations must fulfil.
 *
 * Defines the lifecycle, document CRUD, search, schema, and informational
 * operations required to integrate a search back-end with the plugin.
 *
 * @author cogapp
 * @since 1.0.0
 */
interface EngineInterface
{
    // -- Lifecycle -----------------------------------------------------------

    /**
     * Create a new index (collection/table) in the search engine.
     *
     * @param Index $index The index model containing handle and field mappings.
     * @return void
     */
    public function createIndex(Index $index): void;

    /**
     * Push updated settings or mappings to an existing index.
     *
     * @param Index $index The index model whose settings should be synced.
     * @return void
     */
    public function updateIndexSettings(Index $index): void;

    /**
     * Delete an index and all of its documents from the search engine.
     *
     * @param Index $index The index to delete.
     * @return void
     */
    public function deleteIndex(Index $index): void;

    /**
     * Check whether the index already exists in the search engine.
     *
     * @param Index $index The index to check.
     * @return bool True if the index exists.
     */
    public function indexExists(Index $index): bool;

    // -- Document CRUD -------------------------------------------------------

    /**
     * Add or update a single document in the index.
     *
     * @param Index $index      The target index.
     * @param int   $elementId  The Craft element ID used as the document key.
     * @param array $document   The document body as an associative array.
     * @return void
     */
    public function indexDocument(Index $index, int $elementId, array $document): void;

    /**
     * Add or update multiple documents in a single batch operation.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void;

    /**
     * Remove a single document from the index by element ID.
     *
     * @param Index $index     The target index.
     * @param int   $elementId The Craft element ID of the document to remove.
     * @return void
     */
    public function deleteDocument(Index $index, int $elementId): void;

    /**
     * Remove multiple documents from the index in a single batch operation.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void;

    /**
     * Remove all documents from the index without deleting the index itself.
     *
     * @param Index $index The index to flush.
     * @return void
     */
    public function flushIndex(Index $index): void;

    // -- Search --------------------------------------------------------------

    /**
     * Execute a search query against the index.
     *
     * Unified pagination options (`page` and `perPage`) are extracted automatically.
     * Engine-native pagination keys (e.g. `from`/`size`, `offset`/`limit`) still
     * work and take precedence when provided.
     *
     * @param Index  $index   The index to search.
     * @param string $query   The search query string.
     * @param array  $options Search options — supports unified `page`/`perPage` plus engine-specific keys.
     * @return SearchResult Normalised result with hits, pagination, facets, and raw response.
     */
    public function search(Index $index, string $query, array $options = []): SearchResult;

    /**
     * Return the total number of documents stored in the index.
     *
     * @param Index $index The index to count.
     * @return int Document count.
     */
    public function getDocumentCount(Index $index): int;

    /**
     * Return all document IDs stored in the engine for the given index.
     * Used by orphan cleanup to find stale documents.
     *
     * @param Index $index The index to query.
     * @return string[] Array of document ID strings.
     */
    public function getAllDocumentIds(Index $index): array;

    // -- Schema --------------------------------------------------------------

    /**
     * Map a generic plugin field type constant to the engine-native field type.
     *
     * @param string $indexFieldType A FieldMapping::TYPE_* constant.
     * @return mixed The engine-specific type representation.
     */
    public function mapFieldType(string $indexFieldType): mixed;

    /**
     * Build a complete schema definition from the given field mappings.
     *
     * @param array $fieldMappings Array of FieldMapping models.
     * @return array Engine-specific schema/settings array.
     */
    public function buildSchema(array $fieldMappings): array;

    // -- Info ----------------------------------------------------------------

    /**
     * Return the human-readable display name of the engine (e.g. "Elasticsearch").
     *
     * @return string
     */
    public static function displayName(): string;

    /**
     * Return the per-index configuration field definitions for the CP UI.
     *
     * @return array Associative array of field handles to field config arrays.
     */
    public static function configFields(): array;

    /**
     * Test whether the plugin can reach the search engine with the current settings.
     *
     * @return bool True if the connection is healthy.
     */
    public function testConnection(): bool;
}
