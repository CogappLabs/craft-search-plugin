<?php

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;

interface EngineInterface
{
    // Lifecycle
    public function createIndex(Index $index): void;
    public function updateIndexSettings(Index $index): void;
    public function deleteIndex(Index $index): void;
    public function indexExists(Index $index): bool;

    // Document CRUD
    public function indexDocument(Index $index, int $elementId, array $document): void;
    public function indexDocuments(Index $index, array $documents): void;
    public function deleteDocument(Index $index, int $elementId): void;
    public function deleteDocuments(Index $index, array $elementIds): void;
    public function flushIndex(Index $index): void;

    // Search
    public function search(Index $index, string $query, array $options = []): array;
    public function getDocumentCount(Index $index): int;

    /**
     * Returns all document IDs stored in the engine for this index.
     * Used by orphan cleanup to find stale documents.
     *
     * @return string[]
     */
    public function getAllDocumentIds(Index $index): array;

    // Schema
    public function mapFieldType(string $indexFieldType): mixed;
    public function buildSchema(array $fieldMappings): array;

    // Info
    public static function displayName(): string;
    public static function configFields(): array;
    public function testConnection(): bool;
}
