<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\TypesenseEngine;
use PHPUnit\Framework\TestCase;

/**
 * Stub that exposes TypesenseEngine's normaliseHighlightData for testing.
 */
class TypesenseStubEngine extends TypesenseEngine
{
    public function __construct()
    {
        parent::__construct([]);
    }

    public function publicNormaliseHighlightData(array $highlightData): array
    {
        return $this->normaliseHighlightData($highlightData);
    }
}

/**
 * Tests for Typesense-specific highlight normalisation.
 */
class TypesenseHighlightTest extends TestCase
{
    private TypesenseStubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TypesenseStubEngine();
    }

    public function testObjectFormat(): void
    {
        // v26+ object format: { field: { snippet: 'text', matched_tokens: [...] } }
        $data = [
            'title' => [
                'snippet' => '<mark>London</mark> Bridge',
                'matched_tokens' => ['London'],
            ],
            'body' => [
                'snippet' => 'The <mark>London</mark> Bridge is...',
                'matched_tokens' => ['London'],
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<mark>London</mark> Bridge'], $result['title']);
        $this->assertSame(['The <mark>London</mark> Bridge is...'], $result['body']);
    }

    public function testObjectFormatEmptySnippetExcluded(): void
    {
        $data = [
            'title' => [
                'snippet' => '',
                'matched_tokens' => [],
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertEmpty($result);
    }

    public function testLegacyStringFormat(): void
    {
        // Legacy converted format: { field: 'snippet text' }
        $data = [
            'title' => '<mark>London</mark> Bridge',
            'body' => 'The <mark>London</mark> Bridge is...',
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<mark>London</mark> Bridge'], $result['title']);
        $this->assertSame(['The <mark>London</mark> Bridge is...'], $result['body']);
    }

    public function testEmptyInput(): void
    {
        $result = $this->engine->publicNormaliseHighlightData([]);

        $this->assertSame([], $result);
    }
}
