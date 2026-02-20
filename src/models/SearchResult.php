<?php

namespace cogapp\searchindex\models;

/**
 * Normalised search result DTO returned by all engine search() methods.
 *
 * Implements ArrayAccess for backward compatibility with Twig templates
 * that accessed the old associative-array return values (e.g. results['hits']).
 * Implements Countable so results|length returns the hit count in Twig.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class SearchResult implements \ArrayAccess, \Countable
{
    /**
     * @param array  $hits            Normalised hit documents.
     * @param int    $totalHits       Total number of matching documents.
     * @param int    $page            Current page (1-based).
     * @param int    $perPage         Results per page.
     * @param int    $totalPages      Total number of pages.
     * @param array  $facets          Aggregation / facet data (engine-specific).
     * @param array  $stats           Numeric field statistics: `{ field: { min: float, max: float } }`.
     * @param array  $histograms      Histogram bucket distributions: `{ field: [{ key: float, count: int }, ...] }`.
     * @param array  $raw             The original, unmodified engine response.
     * @param array  $suggestions     Spelling/query suggestions ("did you mean?").
     * @param array  $geoClusters     Geo grid aggregation clusters: `[{ lat: float, lng: float, count: int, key: string }, ...]`.
     */
    public function __construct(
        public readonly array $hits = [],
        public readonly int $totalHits = 0,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly int $totalPages = 0,
        public readonly array $facets = [],
        public readonly array $stats = [],
        public readonly array $histograms = [],
        public readonly array $raw = [],
        public readonly array $suggestions = [],
        public readonly array $geoClusters = [],
    ) {
    }

    /**
     * Return an empty result set (e.g. when an index is not found).
     */
    public static function empty(): self
    {
        return new self();
    }

    // -- Countable ------------------------------------------------------------

    public function count(): int
    {
        return count($this->hits);
    }

    // -- Facet helpers --------------------------------------------------------

    /**
     * Return facets enriched with an `active` flag on each value.
     *
     * Compares each facet value against the provided active filters to set
     * `active => true|false`. This simplifies Twig templates — especially
     * generic facet loops — by eliminating manual `in` checks.
     *
     * Usage: {% set enriched = results.facetsWithActive({ region: activeRegions, category: activeCategories }) %}
     *        {% for facet in enriched.region %} … {{ facet.active ? 'checked' }} … {% endfor %}
     *
     * @param array<string, string|string[]> $activeFilters Map of field name → active value(s).
     * @return array<string, array<int, array{value: string, count: int, active: bool}>>
     */
    public function facetsWithActive(array $activeFilters = []): array
    {
        $enriched = [];

        foreach ($this->facets as $field => $values) {
            $active = $activeFilters[$field] ?? [];

            if (is_string($active)) {
                $active = [$active];
            }

            $enriched[$field] = array_map(
                static fn(array $item) => $item + ['active' => in_array($item['value'] ?? '', $active, true)],
                $values,
            );
        }

        return $enriched;
    }

    // -- ArrayAccess ----------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (property_exists($this, $offset)) {
            return $this->{$offset};
        }

        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('SearchResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('SearchResult is immutable.');
    }
}
