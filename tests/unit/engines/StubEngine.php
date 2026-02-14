<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AbstractEngine;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\models\SearchResult;

/**
 * Minimal concrete subclass of AbstractEngine used to expose protected helpers.
 */
class StubEngine extends AbstractEngine
{
    public static function displayName(): string
    {
        return 'Stub';
    }

    public static function configFields(): array
    {
        return [];
    }

    public static function requiredPackage(): string
    {
        return 'stub/stub';
    }

    public static function isClientInstalled(): bool
    {
        return true;
    }

    public function createIndex(Index $index): void
    {
    }

    public function updateIndexSettings(Index $index): void
    {
    }

    public function deleteIndex(Index $index): void
    {
    }

    public function indexExists(Index $index): bool
    {
        return false;
    }

    public function indexDocument(Index $index, int $elementId, array $document): void
    {
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
    }

    public function flushIndex(Index $index): void
    {
    }

    public function search(Index $index, string $query, array $options = []): SearchResult
    {
        return SearchResult::empty();
    }

    public function getDocumentCount(Index $index): int
    {
        return 0;
    }

    public function getAllDocumentIds(Index $index): array
    {
        return [];
    }

    public function mapFieldType(string $indexFieldType): mixed
    {
        return 'text';
    }

    public function buildSchema(array $fieldMappings): array
    {
        return [];
    }

    public function testConnection(): bool
    {
        return false;
    }

    // -- Expose protected helpers for testing ---------------------------------

    public function publicNormaliseHits(array $hits, string $idKey, string $scoreKey, ?string $highlightKey): array
    {
        return $this->normaliseHits($hits, $idKey, $scoreKey, $highlightKey);
    }

    public function publicExtractPaginationParams(array $options, int $defaultPerPage = 20): array
    {
        return $this->extractPaginationParams($options, $defaultPerPage);
    }

    public function publicComputeTotalPages(int $totalHits, int $perPage): int
    {
        return $this->computeTotalPages($totalHits, $perPage);
    }

    public function publicExtractFacetParams(array $options): array
    {
        return $this->extractFacetParams($options);
    }

    public function publicNormaliseFacetCounts(array $valueCounts): array
    {
        return $this->normaliseFacetCounts($valueCounts);
    }

    public function publicExtractSortParams(array $options): array
    {
        return $this->extractSortParams($options);
    }

    public function publicIsUnifiedSort(array $sort): bool
    {
        return $this->isUnifiedSort($sort);
    }

    public function publicExtractAttributesToRetrieve(array $options): array
    {
        return $this->extractAttributesToRetrieve($options);
    }

    public function publicExtractHighlightParams(array $options): array
    {
        return $this->extractHighlightParams($options);
    }

    public function publicExtractSuggestParams(array $options): array
    {
        return $this->extractSuggestParams($options);
    }

    public function publicNormaliseHighlightData(array $highlightData): array
    {
        return $this->normaliseHighlightData($highlightData);
    }

    public function publicNormaliseDateFields(Index $index, array $document, string $targetFormat): array
    {
        return $this->normaliseDateFields($index, $document, $targetFormat);
    }

    public function publicNormaliseDateValue(mixed $value, string $targetFormat): int|string|null
    {
        return $this->normaliseDateValue($value, $targetFormat);
    }
}
