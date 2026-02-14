<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\AlgoliaEngine;
use PHPUnit\Framework\TestCase;

/**
 * Stub that exposes AlgoliaEngine's normaliseHighlightData for testing.
 */
class AlgoliaStubEngine extends AlgoliaEngine
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
 * Tests for Algolia-specific highlight normalisation.
 */
class AlgoliaHighlightTest extends TestCase
{
    private AlgoliaStubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new AlgoliaStubEngine();
    }

    public function testSingleValueHighlight(): void
    {
        $data = [
            'title' => [
                'value' => '<em>London</em> Bridge',
                'matchLevel' => 'full',
                'fullyHighlighted' => false,
                'matchedWords' => ['london'],
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<em>London</em> Bridge'], $result['title']);
    }

    public function testMultipleFields(): void
    {
        $data = [
            'title' => [
                'value' => '<em>London</em> Bridge',
                'matchLevel' => 'full',
            ],
            'body' => [
                'value' => 'The <em>London</em> Bridge is...',
                'matchLevel' => 'partial',
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertCount(2, $result);
        $this->assertSame(['<em>London</em> Bridge'], $result['title']);
        $this->assertSame(['The <em>London</em> Bridge is...'], $result['body']);
    }

    public function testMatchLevelNoneExcluded(): void
    {
        $data = [
            'title' => [
                'value' => 'No match here',
                'matchLevel' => 'none',
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertEmpty($result);
    }

    public function testArrayFieldHighlight(): void
    {
        $data = [
            'tags' => [
                ['value' => '<em>tech</em>', 'matchLevel' => 'full'],
                ['value' => 'news', 'matchLevel' => 'none'],
                ['value' => '<em>tech</em>nology', 'matchLevel' => 'partial'],
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<em>tech</em>', '<em>tech</em>nology'], $result['tags']);
    }

    public function testEmptyInput(): void
    {
        $result = $this->engine->publicNormaliseHighlightData([]);

        $this->assertSame([], $result);
    }

    public function testMissingMatchLevelDefaultsToNone(): void
    {
        $data = [
            'title' => [
                'value' => 'Some text',
                // No matchLevel key
            ],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertEmpty($result);
    }
}
