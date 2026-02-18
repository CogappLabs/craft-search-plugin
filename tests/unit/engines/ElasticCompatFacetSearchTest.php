<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\engines\ElasticCompatEngine;
use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that exposes the private regex builder via reflection
 * and stubs the client to capture the search body.
 */
class FacetSearchStubEngine extends ElasticCompatEngine
{
    /** @var array|null The last search body passed to the client. */
    public ?array $lastSearchBody = null;

    /** @var array The fake response to return from search. */
    public array $fakeResponse = [];

    public static function displayName(): string
    {
        return 'FacetSearchStub';
    }

    public static function requiredPackage(): string
    {
        return 'stub/elastic-compat';
    }

    public static function isClientInstalled(): bool
    {
        return true;
    }

    public function indexExists(Index $index): bool
    {
        return false;
    }

    public function deleteDocument(Index $index, int $elementId): void
    {
    }

    public function testConnection(): bool
    {
        return false;
    }

    protected function getClient(): mixed
    {
        $engine = $this;

        // Return an object with a search() method that captures the body
        return new class($engine) {
            private FacetSearchStubEngine $engine;

            public function __construct(FacetSearchStubEngine $engine)
            {
                $this->engine = $engine;
            }

            public function search(array $params): array
            {
                $this->engine->lastSearchBody = $params['body'] ?? null;
                return $this->engine->fakeResponse;
            }
        };
    }

    /**
     * Expose the private buildCaseInsensitiveRegex method for testing.
     */
    public function publicBuildCaseInsensitiveRegex(string $query): string
    {
        $ref = new \ReflectionMethod($this, 'buildCaseInsensitiveRegex');
        $ref->setAccessible(true);
        return $ref->invoke($this, $query);
    }
}

/**
 * Unit tests for ElasticCompatEngine facet value search.
 */
class ElasticCompatFacetSearchTest extends TestCase
{
    private FacetSearchStubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new FacetSearchStubEngine();
        $this->engine->fakeResponse = [
            'hits' => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => [],
        ];
    }

    private function createIndexWithFacetField(string $fieldName, string $type = FieldMapping::TYPE_FACET): Index
    {
        $index = new Index();
        $index->handle = 'test';

        $mapping = new FieldMapping();
        $mapping->indexFieldName = $fieldName;
        $mapping->indexFieldType = $type;
        $mapping->enabled = true;

        $ref = new \ReflectionProperty(Index::class, '_fieldMappings');
        $ref->setAccessible(true);
        $ref->setValue($index, [$mapping]);

        return $index;
    }

    private function createIndexWithMappings(array $fields): Index
    {
        $index = new Index();
        $index->handle = 'test';

        $mappings = [];
        foreach ($fields as $name => $type) {
            $mapping = new FieldMapping();
            $mapping->indexFieldName = $name;
            $mapping->indexFieldType = $type;
            $mapping->enabled = true;
            $mappings[] = $mapping;
        }

        $ref = new \ReflectionProperty(Index::class, '_fieldMappings');
        $ref->setAccessible(true);
        $ref->setValue($index, $mappings);

        return $index;
    }

    // -- buildCaseInsensitiveRegex --------------------------------------------

    public function testRegexSimpleAlpha(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('rock');
        $this->assertSame('.*[rR][oO][cC][kK].*', $regex);
    }

    public function testRegexMixedCase(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('Rock');
        // Same output regardless of input case
        $this->assertSame('.*[rR][oO][cC][kK].*', $regex);
    }

    public function testRegexWithNumbers(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('v2');
        // 'v' is a letter, '2' is not
        $this->assertSame('.*[vV]2.*', $regex);
    }

    public function testRegexWithSpaces(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('new york');
        $this->assertSame('.*[nN][eE][wW] [yY][oO][rR][kK].*', $regex);
    }

    public function testRegexEscapesSpecialCharacters(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('a.b');
        $this->assertSame('.*[aA]\\.[bB].*', $regex);
    }

    public function testRegexEscapesParentheses(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('(test)');
        $this->assertSame('.*\\([tT][eE][sS][tT]\\).*', $regex);
    }

    public function testRegexEscapesPipe(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('a|b');
        $this->assertSame('.*[aA]\\|[bB].*', $regex);
    }

    public function testRegexEmptyString(): void
    {
        $regex = $this->engine->publicBuildCaseInsensitiveRegex('');
        $this->assertSame('.*.*', $regex);
    }

    // -- searchFacetValues body construction ----------------------------------

    public function testSearchFacetValuesNoQueryOmitsInclude(): void
    {
        $index = $this->createIndexWithFacetField('category');
        $this->engine->searchFacetValues($index, ['category'], '', 10);

        $aggs = $this->engine->lastSearchBody['aggs'] ?? [];
        $this->assertArrayHasKey('category', $aggs);
        $this->assertArrayNotHasKey('include', $aggs['category']['terms']);
        $this->assertSame(10, $aggs['category']['terms']['size']);
    }

    public function testSearchFacetValuesWithQueryAddsInclude(): void
    {
        $index = $this->createIndexWithFacetField('category');
        $this->engine->searchFacetValues($index, ['category'], 'rock', 5);

        $aggs = $this->engine->lastSearchBody['aggs'] ?? [];
        $this->assertArrayHasKey('include', $aggs['category']['terms']);
        $this->assertSame('.*[rR][oO][cC][kK].*', $aggs['category']['terms']['include']);
    }

    public function testSearchFacetValuesZeroHits(): void
    {
        $index = $this->createIndexWithFacetField('category');
        $this->engine->searchFacetValues($index, ['category'], 'rock');

        $this->assertSame(0, $this->engine->lastSearchBody['size']);
    }

    public function testSearchFacetValuesWithFilters(): void
    {
        $index = $this->createIndexWithFacetField('category');
        $this->engine->searchFacetValues($index, ['category'], '', 5, ['status' => 'active']);

        $body = $this->engine->lastSearchBody;
        $this->assertArrayHasKey('query', $body);
        $this->assertArrayHasKey('bool', $body['query']);
        $this->assertArrayHasKey('filter', $body['query']['bool']);
    }

    public function testSearchFacetValuesWithoutFiltersNoQuery(): void
    {
        $index = $this->createIndexWithFacetField('category');
        $this->engine->searchFacetValues($index, ['category'], '');

        $body = $this->engine->lastSearchBody;
        $this->assertArrayNotHasKey('query', $body);
    }

    public function testSearchFacetValuesTextField(): void
    {
        $index = $this->createIndexWithFacetField('description', FieldMapping::TYPE_TEXT);
        $this->engine->searchFacetValues($index, ['description'], 'test');

        $aggs = $this->engine->lastSearchBody['aggs'] ?? [];
        // Text fields should use .keyword sub-field
        $this->assertSame('description.keyword', $aggs['description']['terms']['field']);
    }

    public function testSearchFacetValuesKeywordField(): void
    {
        $index = $this->createIndexWithFacetField('status', FieldMapping::TYPE_KEYWORD);
        $this->engine->searchFacetValues($index, ['status'], 'act');

        $aggs = $this->engine->lastSearchBody['aggs'] ?? [];
        // Keyword fields use bare field name
        $this->assertSame('status', $aggs['status']['terms']['field']);
    }

    public function testSearchFacetValuesMultipleFields(): void
    {
        $index = $this->createIndexWithMappings([
            'category' => FieldMapping::TYPE_FACET,
            'status' => FieldMapping::TYPE_KEYWORD,
        ]);

        $this->engine->searchFacetValues($index, ['category', 'status'], 'act', 5);

        $aggs = $this->engine->lastSearchBody['aggs'] ?? [];
        $this->assertArrayHasKey('category', $aggs);
        $this->assertArrayHasKey('status', $aggs);
        // Both should have the include regex
        $this->assertArrayHasKey('include', $aggs['category']['terms']);
        $this->assertArrayHasKey('include', $aggs['status']['terms']);
    }

    public function testSearchFacetValuesNormalisesResponse(): void
    {
        $this->engine->fakeResponse = [
            'hits' => ['total' => ['value' => 100], 'hits' => []],
            'aggregations' => [
                'category' => [
                    'buckets' => [
                        ['key' => 'Rock', 'doc_count' => 42],
                        ['key' => 'Classic Rock', 'doc_count' => 15],
                    ],
                ],
            ],
        ];

        $index = $this->createIndexWithFacetField('category');
        $result = $this->engine->searchFacetValues($index, ['category'], 'rock', 5);

        $this->assertArrayHasKey('category', $result);
        $this->assertCount(2, $result['category']);
        $this->assertSame('Rock', $result['category'][0]['value']);
        $this->assertSame(42, $result['category'][0]['count']);
    }

    public function testSearchFacetValuesStripsEmptyFields(): void
    {
        $this->engine->fakeResponse = [
            'hits' => ['total' => ['value' => 100], 'hits' => []],
            'aggregations' => [
                'category' => [
                    'buckets' => [
                        ['key' => 'Rock', 'doc_count' => 42],
                    ],
                ],
                'status' => [
                    'buckets' => [],
                ],
            ],
        ];

        $index = $this->createIndexWithMappings([
            'category' => FieldMapping::TYPE_FACET,
            'status' => FieldMapping::TYPE_KEYWORD,
        ]);
        $result = $this->engine->searchFacetValues($index, ['category', 'status'], 'rock', 5);

        $this->assertArrayHasKey('category', $result);
        $this->assertArrayNotHasKey('status', $result);
    }
}
