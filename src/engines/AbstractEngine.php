<?php

/**
 * Abstract base class for search engine implementations.
 */

namespace cogapp\searchindex\engines;

use cogapp\searchindex\models\Index;
use craft\helpers\App;

/**
 * Provides shared functionality for all search engine implementations.
 *
 * Handles per-index configuration, environment-variable resolution, and
 * fallback batch operations that concrete engines can override with native
 * bulk APIs.
 *
 * @author cogapp
 * @since 1.0.0
 */
abstract class AbstractEngine implements EngineInterface
{
    /**
     * Per-index engine configuration (e.g. index prefix).
     *
     * @var array
     */
    protected array $config;

    /**
     * @param array $config Per-index engine configuration values.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Return the resolved index name, applying any configured prefix.
     *
     * @param Index $index The index model.
     * @return string The prefixed index name.
     */
    protected function getIndexName(Index $index): string
    {
        $prefix = $this->config['indexPrefix'] ?? '';
        $prefix = App::parseEnv($prefix);

        return $prefix . $index->handle;
    }

    /**
     * Return a parsed config value, resolving environment variables.
     *
     * @param string $key     The configuration key to look up.
     * @param string $default Fallback value if the key is not set.
     * @return string The resolved value.
     */
    protected function parseSetting(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return App::parseEnv($value);
    }

    /**
     * Default batch index: loops single indexDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index     The target index.
     * @param array $documents Array of document bodies, each containing an 'objectID' key.
     * @return void
     */
    public function indexDocuments(Index $index, array $documents): void
    {
        foreach ($documents as $document) {
            $elementId = $document['objectID'] ?? 0;
            $this->indexDocument($index, (int)$elementId, $document);
        }
    }

    /**
     * Default batch delete: loops single deleteDocument calls.
     * Engine implementations should override with native bulk APIs.
     *
     * @param Index $index      The target index.
     * @param int[] $elementIds Array of Craft element IDs to remove.
     * @return void
     */
    public function deleteDocuments(Index $index, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            $this->deleteDocument($index, $elementId);
        }
    }

    /**
     * Default getDocument: searches for the document by ID.
     * Engine implementations should override with native document retrieval.
     *
     * @param Index  $index      The index to query.
     * @param string $documentId The document ID to retrieve.
     * @return array|null The document as an associative array, or null if not found.
     */
    public function getDocument(Index $index, string $documentId): ?array
    {
        try {
            $result = $this->search($index, $documentId, ['perPage' => 1]);
            foreach ($result->hits as $hit) {
                if (($hit['objectID'] ?? null) === $documentId) {
                    return $hit;
                }
            }
        } catch (\Throwable $e) {
            // Fallback failed
        }

        return null;
    }

    /**
     * Default getIndexSchema: returns empty array.
     * Engine implementations should override with native schema retrieval.
     *
     * @param Index $index The index to inspect.
     * @return array Engine-specific schema/settings array.
     */
    public function getIndexSchema(Index $index): array
    {
        return [];
    }

    /**
     * Normalise an array of engine-specific hit documents into a consistent shape.
     *
     * Every hit will contain at least `objectID` (string), `_score` (float|int|null),
     * and `_highlights` (array). All original engine-specific keys are preserved.
     *
     * @param array       $hits         Raw hits from the engine response.
     * @param string      $idKey        The key used by this engine for the document ID.
     * @param string      $scoreKey     The key used by this engine for the relevance score.
     * @param string|null $highlightKey The key used by this engine for highlight data.
     * @return array Normalised hits.
     */
    protected function normaliseHits(array $hits, string $idKey, string $scoreKey, ?string $highlightKey): array
    {
        return array_map(function(array $hit) use ($idKey, $scoreKey, $highlightKey): array {
            if (!isset($hit['objectID']) && isset($hit[$idKey])) {
                $hit['objectID'] = (string)$hit[$idKey];
            }

            if (!isset($hit['_score'])) {
                $hit['_score'] = $hit[$scoreKey] ?? null;
            }

            if (!isset($hit['_highlights'])) {
                $hit['_highlights'] = $highlightKey !== null ? ($hit[$highlightKey] ?? []) : [];
            }

            return $hit;
        }, $hits);
    }

    /**
     * Extract unified pagination parameters from the search options.
     *
     * Looks for `page` (1-based) and `perPage` in the options array, falling back
     * to defaults. The extracted keys are removed from the returned remaining options.
     *
     * @param array $options       The caller-provided search options.
     * @param int   $defaultPerPage Default results per page.
     * @return array{int, int, array} [$page, $perPage, $remainingOptions]
     */
    protected function extractPaginationParams(array $options, int $defaultPerPage = 20): array
    {
        $page = (int)($options['page'] ?? 1);
        $perPage = (int)($options['perPage'] ?? $defaultPerPage);

        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }

        $remaining = $options;
        unset($remaining['page'], $remaining['perPage']);

        return [$page, $perPage, $remaining];
    }

    /**
     * Sort weighted attributes by weight descending and return just the names.
     *
     * Expects each element to be `['name' => string, 'weight' => int]`.
     *
     * @param array $attributes Array of ['name' => ..., 'weight' => ...] items.
     * @return string[] Sorted field names.
     */
    protected function sortByWeight(array $attributes): array
    {
        usort($attributes, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return array_map(fn($attr) => $attr['name'], $attributes);
    }

    /**
     * Compute the total number of pages for the given totals.
     *
     * @param int $totalHits Total matching documents.
     * @param int $perPage   Results per page.
     * @return int Total pages (minimum 0).
     */
    protected function computeTotalPages(int $totalHits, int $perPage): int
    {
        if ($perPage <= 0) {
            return 0;
        }

        return (int)ceil($totalHits / $perPage);
    }
}
