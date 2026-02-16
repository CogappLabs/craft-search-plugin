<?php

namespace cogapp\searchindex\tests\unit\services;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\services\FieldMappingValidator;
use PHPUnit\Framework\TestCase;

class FieldMappingValidatorTest extends TestCase
{
    private FieldMappingValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FieldMappingValidator();
    }

    public function testExtractSubFieldHandleReturnsSubHandle(): void
    {
        $this->assertSame('body', $this->validator->extractSubFieldHandle('content_body', 'content'));
    }

    public function testExtractSubFieldHandleReturnsNullForMismatch(): void
    {
        $this->assertNull($this->validator->extractSubFieldHandle('other_body', 'content'));
    }

    public function testDiagnoseValueReturnsNullStatusForNull(): void
    {
        $mapping = $this->mapping(FieldMapping::TYPE_TEXT);

        $diagnostic = $this->validator->diagnoseValue(null, $mapping);

        $this->assertSame('null', $diagnostic['status']);
        $this->assertStringContainsString('Resolved to null', (string)$diagnostic['warning']);
    }

    public function testDiagnoseValueCatchesTypeMismatches(): void
    {
        $text = $this->validator->diagnoseValue(['x'], $this->mapping(FieldMapping::TYPE_TEXT));
        $integer = $this->validator->diagnoseValue(1.5, $this->mapping(FieldMapping::TYPE_INTEGER));
        $float = $this->validator->diagnoseValue('1.25', $this->mapping(FieldMapping::TYPE_FLOAT));
        $boolean = $this->validator->diagnoseValue(1, $this->mapping(FieldMapping::TYPE_BOOLEAN));

        $this->assertSame('warning', $text['status']);
        $this->assertSame('warning', $integer['status']);
        $this->assertSame('warning', $float['status']);
        $this->assertSame('warning', $boolean['status']);
    }

    public function testDiagnoseValueHandlesDateAndFacetTypes(): void
    {
        $dateWarning = $this->validator->diagnoseValue(new \DateTimeImmutable(), $this->mapping(FieldMapping::TYPE_DATE));
        $facetOk = $this->validator->diagnoseValue(['tag-a', 'tag-b'], $this->mapping(FieldMapping::TYPE_FACET));

        $this->assertSame('warning', $dateWarning['status']);
        $this->assertStringContainsString('DateTime object', (string)$dateWarning['warning']);
        $this->assertSame('ok', $facetOk['status']);
    }

    public function testFormatValueFormatsObjectsAndLongStrings(): void
    {
        $objectValue = $this->validator->formatValue(new \stdClass());
        $longString = str_repeat('a', 220);
        $formattedString = $this->validator->formatValue($longString);

        $this->assertSame('(object: stdClass)', $objectValue);
        $this->assertIsString($formattedString);
        $this->assertStringEndsWith('…', $formattedString);
        $this->assertSame(201, mb_strlen($formattedString));
    }

    public function testGetPhpTypeReturnsReadableTypes(): void
    {
        $this->assertSame('null', $this->validator->getPhpType(null));
        $this->assertSame('array(2)', $this->validator->getPhpType([1, 2]));
        $this->assertSame('stdClass', $this->validator->getPhpType(new \stdClass()));
        $this->assertSame('integer', $this->validator->getPhpType(7));
    }

    public function testBuildValidationMarkdownCanFilterIssues(): void
    {
        $data = [
            'indexName' => 'Articles',
            'indexHandle' => 'articles',
            'entryTypeNames' => ['News'],
            'results' => [
                [
                    'indexFieldName' => 'title',
                    'indexFieldType' => 'text',
                    'entryId' => 1,
                    'entryTitle' => 'A|B',
                    'value' => 'Short value',
                    'phpType' => 'string',
                    'status' => 'ok',
                    'warning' => null,
                ],
                [
                    'indexFieldName' => 'summary',
                    'indexFieldType' => 'text',
                    'entryId' => null,
                    'entryTitle' => null,
                    'value' => str_repeat('b', 80),
                    'phpType' => 'null',
                    'status' => 'warning',
                    'warning' => 'Missing value',
                ],
            ],
        ];

        $markdown = $this->validator->buildValidationMarkdown($data, 'issues', ' (filtered)');

        $this->assertStringContainsString('# Field Mapping Validation: Articles (`articles`) (filtered)', $markdown);
        $this->assertStringContainsString('**Entry types:** News', $markdown);
        $this->assertStringContainsString('`summary`', $markdown);
        $this->assertStringNotContainsString('`title`', $markdown);
        $this->assertStringContainsString('WARN Missing value', $markdown);
        $this->assertStringContainsString('...', $markdown);
    }

    private function mapping(string $type): FieldMapping
    {
        $mapping = new FieldMapping();
        $mapping->indexFieldType = $type;

        return $mapping;
    }
}
