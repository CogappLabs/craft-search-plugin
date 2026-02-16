<?php

namespace cogapp\searchindex\tests\unit\variables;

use cogapp\searchindex\variables\SearchIndexVariable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the stateInputs() and buildUrl() Twig helper methods.
 */
class SearchIndexVariableHelpersTest extends TestCase
{
    private SearchIndexVariable $variable;

    protected function setUp(): void
    {
        $this->variable = new SearchIndexVariable();
    }

    // -- stateInputs ----------------------------------------------------------

    public function testStateInputsScalarValues(): void
    {
        $html = (string)$this->variable->stateInputs([
            'query' => 'london',
            'sort' => 'relevance',
        ]);

        $this->assertStringContainsString('<input type="hidden" name="query" value="london">', $html);
        $this->assertStringContainsString('<input type="hidden" name="sort" value="relevance">', $html);
    }

    public function testStateInputsIntegerValue(): void
    {
        $html = (string)$this->variable->stateInputs(['page' => 3]);

        $this->assertStringContainsString('<input type="hidden" name="page" value="3">', $html);
    }

    public function testStateInputsIndexedArray(): void
    {
        $html = (string)$this->variable->stateInputs([
            'activeRegions' => ['Highland', 'Central'],
        ]);

        $this->assertStringContainsString('<input type="hidden" name="activeRegions[]" value="Highland">', $html);
        $this->assertStringContainsString('<input type="hidden" name="activeRegions[]" value="Central">', $html);
    }

    public function testStateInputsNestedAssociativeArray(): void
    {
        $html = (string)$this->variable->stateInputs([
            'filters' => [
                'region' => ['Highland'],
                'category' => ['Gardens', 'Castles'],
            ],
        ]);

        $this->assertStringContainsString('<input type="hidden" name="filters[region][]" value="Highland">', $html);
        $this->assertStringContainsString('<input type="hidden" name="filters[category][]" value="Gardens">', $html);
        $this->assertStringContainsString('<input type="hidden" name="filters[category][]" value="Castles">', $html);
    }

    public function testStateInputsNullSkipped(): void
    {
        $html = (string)$this->variable->stateInputs([
            'query' => 'london',
            'sort' => null,
        ]);

        $this->assertStringContainsString('name="query"', $html);
        $this->assertStringNotContainsString('name="sort"', $html);
    }

    public function testStateInputsEmptyStringSkipped(): void
    {
        $html = (string)$this->variable->stateInputs([
            'query' => '',
            'page' => 1,
        ]);

        $this->assertStringNotContainsString('name="query"', $html);
        $this->assertStringContainsString('name="page"', $html);
    }

    public function testStateInputsEmptyArrayGeneratesNothing(): void
    {
        $html = (string)$this->variable->stateInputs([
            'activeRegions' => [],
        ]);

        $this->assertSame('', $html);
    }

    public function testStateInputsExcludeString(): void
    {
        $html = (string)$this->variable->stateInputs(
            ['query' => 'london', 'sort' => 'relevance', 'page' => 1],
            ['exclude' => 'query'],
        );

        $this->assertStringNotContainsString('name="query"', $html);
        $this->assertStringContainsString('name="sort"', $html);
        $this->assertStringContainsString('name="page"', $html);
    }

    public function testStateInputsExcludeArray(): void
    {
        $html = (string)$this->variable->stateInputs(
            ['query' => 'london', 'sort' => 'relevance', 'page' => 1],
            ['exclude' => ['query', 'page']],
        );

        $this->assertStringNotContainsString('name="query"', $html);
        $this->assertStringNotContainsString('name="page"', $html);
        $this->assertStringContainsString('name="sort"', $html);
    }

    public function testStateInputsHtmlEscapesValues(): void
    {
        $html = (string)$this->variable->stateInputs([
            'query' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('value="&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testStateInputsReturnsTwigMarkup(): void
    {
        $result = $this->variable->stateInputs(['page' => 1]);

        $this->assertInstanceOf(\Twig\Markup::class, $result);
    }

    public function testStateInputsZeroValueIncluded(): void
    {
        $html = (string)$this->variable->stateInputs(['offset' => 0]);

        $this->assertStringContainsString('<input type="hidden" name="offset" value="0">', $html);
    }

    // -- buildUrl -------------------------------------------------------------

    public function testBuildUrlSimpleParams(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => 'london',
            'page' => 2,
        ]);

        $this->assertSame('/search?q=london&page=2', $url);
    }

    public function testBuildUrlArrayParams(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'region' => ['Highland', 'Central'],
        ]);

        $this->assertSame('/search?region[]=Highland&region[]=Central', $url);
    }

    public function testBuildUrlNullOmitted(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => 'london',
            'sort' => null,
        ]);

        $this->assertSame('/search?q=london', $url);
    }

    public function testBuildUrlEmptyStringOmitted(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => '',
            'page' => 1,
        ]);

        $this->assertSame('/search?page=1', $url);
    }

    public function testBuildUrlEmptyArrayOmitted(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => 'london',
            'region' => [],
        ]);

        $this->assertSame('/search?q=london', $url);
    }

    public function testBuildUrlNoParams(): void
    {
        $url = $this->variable->buildUrl('/search', []);

        $this->assertSame('/search', $url);
    }

    public function testBuildUrlAllParamsFiltered(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => null,
            'sort' => '',
            'region' => [],
        ]);

        $this->assertSame('/search', $url);
    }

    public function testBuildUrlSpecialCharactersEncoded(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => 'foo bar & baz',
        ]);

        $this->assertSame('/search?q=foo%20bar%20%26%20baz', $url);
    }

    public function testBuildUrlMixedParams(): void
    {
        $url = $this->variable->buildUrl('/search-sprig', [
            'q' => 'london',
            'region' => ['Highland'],
            'sort' => 'dateAsc',
            'page' => null,
        ]);

        $this->assertSame('/search-sprig?q=london&region[]=Highland&sort=dateAsc', $url);
    }

    public function testBuildUrlFalseOmitted(): void
    {
        $url = $this->variable->buildUrl('/search', [
            'q' => 'london',
            'active' => false,
        ]);

        $this->assertSame('/search?q=london', $url);
    }

    // -- _normaliseFilters (range) -------------------------------------------

    private function callNormaliseFilters(array $filters): array
    {
        $ref = new \ReflectionMethod($this->variable, '_normaliseFilters');
        $ref->setAccessible(true);
        return $ref->invoke($this->variable, $filters);
    }

    public function testNormaliseFiltersRangeMinMax(): void
    {
        $result = $this->callNormaliseFilters([
            'population' => ['min' => '1000', 'max' => '50000'],
        ]);

        $this->assertSame(['population' => ['min' => 1000.0, 'max' => 50000.0]], $result);
    }

    public function testNormaliseFiltersRangeMinOnly(): void
    {
        $result = $this->callNormaliseFilters([
            'population' => ['min' => '500'],
        ]);

        $this->assertSame(['population' => ['min' => 500.0]], $result);
    }

    public function testNormaliseFiltersRangeMaxOnly(): void
    {
        $result = $this->callNormaliseFilters([
            'population' => ['max' => '99999'],
        ]);

        $this->assertSame(['population' => ['max' => 99999.0]], $result);
    }

    public function testNormaliseFiltersRangeEmptyValuesSkipped(): void
    {
        $result = $this->callNormaliseFilters([
            'population' => ['min' => '', 'max' => ''],
        ]);

        $this->assertArrayNotHasKey('population', $result);
    }

    public function testNormaliseFiltersRangeNullValuesSkipped(): void
    {
        $result = $this->callNormaliseFilters([
            'population' => ['min' => null, 'max' => null],
        ]);

        $this->assertArrayNotHasKey('population', $result);
    }

    public function testNormaliseFiltersMixedRangeAndEquality(): void
    {
        $result = $this->callNormaliseFilters([
            'country' => ['UK', 'FR'],
            'population' => ['min' => '1000', 'max' => '50000'],
        ]);

        $this->assertSame(['UK', 'FR'], $result['country']);
        $this->assertSame(['min' => 1000.0, 'max' => 50000.0], $result['population']);
    }

    public function testNormaliseFiltersEqualityUnchanged(): void
    {
        $result = $this->callNormaliseFilters([
            'country' => ['UK', 'FR', 'FR'],
        ]);

        // Deduplication
        $this->assertSame(['UK', 'FR'], $result['country']);
    }
}
