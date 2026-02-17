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
    // --8<-- [start:methods]
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

    // -- Document retrieval ---------------------------------------------------

    /**
     * Retrieve a single document from the index by its ID.
     *
     * @param Index  $index      The index to query.
     * @param string $documentId The document ID to retrieve.
     * @return array|null The document as an associative array, or null if not found.
     */
    public function getDocument(Index $index, string $documentId): ?array;

    // -- Search --------------------------------------------------------------

    /**
     * Search within facet values for the given fields.
     *
     * Each engine should use its native facet search API where available
     * (e.g. Meilisearch facetSearch, Algolia searchForFacetValues, Typesense
     * facet_query). The AbstractEngine provides a fallback that searches with
     * the query and returns facets from matching documents.
     *
     * @param Index    $index       The index to search.
     * @param string[] $facetFields The facet field names to search within.
     * @param string   $query       The query to match against facet values.
     * @param int      $maxPerField Maximum values to return per field.
     * @param array    $filters     Optional filters to narrow the facet value context.
     * @return array<string, array<array{value: string, count: int}>> Grouped by field name.
     */
    public function searchFacetValues(Index $index, array $facetFields, string $query, int $maxPerField = 5, array $filters = []): array;

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

    /**
     * Execute multiple search queries in a single batch request.
     *
     * Each query is an array with 'index' (Index model), 'query' (string),
     * and optional 'options' (array). Returns one SearchResult per query
     * in the same order.
     *
     * @param array $queries Array of ['index' => Index, 'query' => string, 'options' => array]
     * @return SearchResult[] One result per query, in the same order.
     */
    public function multiSearch(array $queries): array;

    // -- Schema --------------------------------------------------------------

    /**
     * Retrieve the current schema/settings for the index from the engine.
     *
     * Returns engine-specific information about the index structure,
     * such as field mappings, searchable attributes, or collection schema.
     *
     * @param Index $index The index to inspect.
     * @return array Engine-specific schema/settings array.
     */
    public function getIndexSchema(Index $index): array;

    /**
     * Extract a normalised list of field names and types from the live engine schema.
     *
     * Returns an array of associative arrays, each with 'name' (string) and
     * 'type' (a FieldMapping::TYPE_* constant) keys.
     *
     * @param Index $index The index to inspect.
     * @return array<array{name: string, type: string}> Normalised field list.
     */
    public function getSchemaFields(Index $index): array;

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

    // -- Atomic swap ----------------------------------------------------------

    /**
     * Whether this engine supports atomic index swapping for zero-downtime refresh.
     *
     * @return bool
     */
    public function supportsAtomicSwap(): bool;

    /**
     * Return the handle to use for the temporary swap index.
     *
     * Alias-based engines (ES, OpenSearch, Typesense) alternate between
     * `{handle}_swap_a` and `{handle}_swap_b` so the production alias can be
     * atomically re-pointed. Direct-rename engines (Algolia, Meilisearch)
     * simply use `{handle}_swap`.
     *
     * @param Index $index The production index.
     * @return string The swap index handle.
     */
    public function buildSwapHandle(Index $index): string;

    /**
     * Perform an atomic swap between the production index and a temporary index.
     *
     * Only called when supportsAtomicSwap() returns true. The temporary index
     * has already been created and populated with documents.
     *
     * @param Index $index    The production index.
     * @param Index $swapIndex A cloned index with modified handle pointing to the temp index.
     * @return void
     */
    public function swapIndex(Index $index, Index $swapIndex): void;

    // -- Info ----------------------------------------------------------------

    /**
     * Return the human-readable display name of the engine (e.g. "Elasticsearch").
     *
     * @return string
     */
    public static function displayName(): string;

    /**
     * Return the Composer package name required by this engine.
     *
     * @return string e.g. "algolia/algoliasearch-client-php"
     */
    public static function requiredPackage(): string;

    /**
     * Whether the engine's required PHP client library is installed.
     *
     * @return bool
     */
    public static function isClientInstalled(): bool;

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
    // --8<-- [end:methods]
}
