<?php

namespace cogapp\searchindex\tests\unit\fields;

use cogapp\searchindex\fields\SearchDocumentField;
use cogapp\searchindex\fields\SearchDocumentValue;
use PHPUnit\Framework\TestCase;
use yii\db\Schema;

class SearchDocumentFieldTest extends TestCase
{
    private SearchDocumentField $field;

    protected function setUp(): void
    {
        $this->field = new SearchDocumentField();
    }

    // -- Static metadata ------------------------------------------------------

    public function testDisplayName(): void
    {
        $this->assertSame('Search Document', SearchDocumentField::displayName());
    }

    public function testIcon(): void
    {
        $this->assertSame('magnifying-glass', SearchDocumentField::icon());
    }

    public function testDbTypeReturnsStringColumns(): void
    {
        $dbType = SearchDocumentField::dbType();

        $this->assertIsArray($dbType);
        $this->assertArrayHasKey('indexHandle', $dbType);
        $this->assertArrayHasKey('documentId', $dbType);
        $this->assertArrayHasKey('sectionHandle', $dbType);
        $this->assertArrayHasKey('entryTypeHandle', $dbType);
        $this->assertSame(Schema::TYPE_STRING, $dbType['indexHandle']);
        $this->assertSame(Schema::TYPE_STRING, $dbType['documentId']);
        $this->assertSame(Schema::TYPE_STRING, $dbType['sectionHandle']);
        $this->assertSame(Schema::TYPE_STRING, $dbType['entryTypeHandle']);
    }

    // -- Default settings -----------------------------------------------------

    public function testDefaultSettings(): void
    {
        $this->assertSame('', $this->field->indexHandle);
        $this->assertSame(10, $this->field->perPage);
    }

    // -- normalizeValue -------------------------------------------------------

    public function testNormalizeValueWithValueObject(): void
    {
        $value = new SearchDocumentValue('places', '42');
        $result = $this->field->normalizeValue($value);

        $this->assertSame($value, $result);
    }

    public function testNormalizeValueWithArray(): void
    {
        $result = $this->field->normalizeValue([
            'indexHandle' => 'places',
            'documentId' => '42',
        ]);

        $this->assertInstanceOf(SearchDocumentValue::class, $result);
        $this->assertSame('places', $result->indexHandle);
        $this->assertSame('42', $result->documentId);
    }

    public function testNormalizeValueWithEmptyArrayReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue([]));
    }

    public function testNormalizeValueWithMissingIndexHandleReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue([
            'documentId' => '42',
        ]));
    }

    public function testNormalizeValueWithMissingDocumentIdReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue([
            'indexHandle' => 'places',
        ]));
    }

    public function testNormalizeValueWithNullReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue(null));
    }

    public function testNormalizeValueWithStringReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue('not-valid'));
    }

    public function testNormalizeValueWithEmptyStringsReturnsNull(): void
    {
        $this->assertNull($this->field->normalizeValue([
            'indexHandle' => '',
            'documentId' => '',
        ]));
    }

    // -- serializeValue -------------------------------------------------------

    public function testSerializeValueWithValueObject(): void
    {
        $value = new SearchDocumentValue('events', '99');
        $result = $this->field->serializeValue($value);

        $this->assertSame([
            'indexHandle' => 'events',
            'documentId' => '99',
            'sectionHandle' => null,
            'entryTypeHandle' => null,
        ], $result);
    }

    public function testSerializeValueWithNullReturnsNull(): void
    {
        $this->assertNull($this->field->serializeValue(null));
    }

    public function testSerializeValueWithNonValueObjectReturnsNull(): void
    {
        $this->assertNull($this->field->serializeValue('string'));
    }

    // -- Round-trip ------------------------------------------------------------

    public function testNormalizeSerializeRoundTrip(): void
    {
        $original = new SearchDocumentValue('places', '123');

        $serialized = $this->field->serializeValue($original);
        $restored = $this->field->normalizeValue($serialized);

        $this->assertInstanceOf(SearchDocumentValue::class, $restored);
        $this->assertSame($original->indexHandle, $restored->indexHandle);
        $this->assertSame($original->documentId, $restored->documentId);
    }
}
