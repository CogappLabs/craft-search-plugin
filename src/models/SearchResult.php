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
     * @param int    $processingTimeMs Query processing time in milliseconds.
     * @param array  $facets          Aggregation / facet data (engine-specific).
     * @param array  $raw             The original, unmodified engine response.
     */
    public function __construct(
        public readonly array $hits = [],
        public readonly int $totalHits = 0,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly int $totalPages = 0,
        public readonly int $processingTimeMs = 0,
        public readonly array $facets = [],
        public readonly array $raw = [],
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
        // Immutable — silently ignored for Twig compatibility.
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable — silently ignored for Twig compatibility.
    }
}
