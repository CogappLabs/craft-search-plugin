<?php

namespace cogapp\searchindex\tests\unit\engines;

use PHPUnit\Framework\TestCase;

/**
 * Tests for highlight extraction, suggest extraction, and highlight normalisation.
 *
 * Uses the StubEngine defined in HitNormalisationTest.php.
 */
class HighlightAndSuggestTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    // -- extractHighlightParams -----------------------------------------------

    public function testExtractHighlightParamsDefaultsToNull(): void
    {
        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams([]);

        $this->assertNull($highlight);
        $this->assertSame([], $remaining);
    }

    public function testExtractHighlightParamsTrue(): void
    {
        $options = ['highlight' => true, 'perPage' => 10];

        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams($options);

        $this->assertTrue($highlight);
        $this->assertSame(['perPage' => 10], $remaining);
        $this->assertArrayNotHasKey('highlight', $remaining);
    }

    public function testExtractHighlightParamsArray(): void
    {
        $options = ['highlight' => ['title', 'body']];

        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams($options);

        $this->assertSame(['title', 'body'], $highlight);
        $this->assertSame([], $remaining);
    }

    public function testExtractHighlightParamsFalseBecomesNull(): void
    {
        $options = ['highlight' => false];

        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams($options);

        $this->assertNull($highlight);
    }

    public function testExtractHighlightParamsNonArrayNonBoolBecomesNull(): void
    {
        $options = ['highlight' => 'title'];

        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams($options);

        $this->assertNull($highlight);
    }

    public function testExtractHighlightParamsPreservesOtherOptions(): void
    {
        $options = ['highlight' => true, 'page' => 2, 'sort' => ['title' => 'asc']];

        [$highlight, $remaining] = $this->engine->publicExtractHighlightParams($options);

        $this->assertArrayHasKey('page', $remaining);
        $this->assertArrayHasKey('sort', $remaining);
        $this->assertArrayNotHasKey('highlight', $remaining);
    }

    // -- extractSuggestParams -------------------------------------------------

    public function testExtractSuggestParamsDefaultsToFalse(): void
    {
        [$suggest, $remaining] = $this->engine->publicExtractSuggestParams([]);

        $this->assertFalse($suggest);
        $this->assertSame([], $remaining);
    }

    public function testExtractSuggestParamsTrue(): void
    {
        $options = ['suggest' => true, 'perPage' => 5];

        [$suggest, $remaining] = $this->engine->publicExtractSuggestParams($options);

        $this->assertTrue($suggest);
        $this->assertSame(['perPage' => 5], $remaining);
        $this->assertArrayNotHasKey('suggest', $remaining);
    }

    public function testExtractSuggestParamsFalse(): void
    {
        $options = ['suggest' => false];

        [$suggest, $remaining] = $this->engine->publicExtractSuggestParams($options);

        $this->assertFalse($suggest);
    }

    public function testExtractSuggestParamsTruthyValue(): void
    {
        $options = ['suggest' => 1];

        [$suggest, $remaining] = $this->engine->publicExtractSuggestParams($options);

        $this->assertTrue($suggest);
    }

    // -- normaliseHighlightData (base implementation) -------------------------

    public function testNormaliseHighlightDataEmptyInput(): void
    {
        $result = $this->engine->publicNormaliseHighlightData([]);

        $this->assertSame([], $result);
    }

    public function testNormaliseHighlightDataArrayOfStrings(): void
    {
        // ES format: { field: ['fragment1', 'fragment2'] }
        $data = [
            'title' => ['<em>London</em> Bridge'],
            'body' => ['The <em>London</em> Bridge is...', 'Visit <em>London</em>'],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<em>London</em> Bridge'], $result['title']);
        $this->assertCount(2, $result['body']);
    }

    public function testNormaliseHighlightDataStringValues(): void
    {
        // Meilisearch-style: { field: 'highlighted text' }
        $data = [
            'title' => '<em>London</em> Bridge',
            'body' => 'The <em>London</em> Bridge is...',
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<em>London</em> Bridge'], $result['title']);
        $this->assertSame(['The <em>London</em> Bridge is...'], $result['body']);
    }

    public function testNormaliseHighlightDataFiltersEmptyValues(): void
    {
        $data = [
            'title' => ['<em>Match</em>'],
            'body' => [],
            'empty' => '',
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('body', $result);
        $this->assertArrayNotHasKey('empty', $result);
    }

    public function testNormaliseHighlightDataFiltersNonStringArrayValues(): void
    {
        $data = [
            'title' => ['<em>Match</em>', 42, null, 'Other'],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        // Only string values should survive
        $this->assertSame(['<em>Match</em>', 'Other'], $result['title']);
    }

    public function testNormaliseHighlightDataMixedFormats(): void
    {
        $data = [
            'title' => '<em>Match</em>',
            'body' => ['Fragment 1', 'Fragment 2'],
        ];

        $result = $this->engine->publicNormaliseHighlightData($data);

        $this->assertSame(['<em>Match</em>'], $result['title']);
        $this->assertSame(['Fragment 1', 'Fragment 2'], $result['body']);
    }
}
