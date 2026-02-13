<?php

namespace cogapp\searchindex\tests\unit\fields;

use cogapp\searchindex\fields\SearchDocumentValue;
use PHPUnit\Framework\TestCase;

class SearchDocumentValueTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $value = new SearchDocumentValue('places', '12345');

        $this->assertSame('places', $value->indexHandle);
        $this->assertSame('12345', $value->documentId);
    }

    public function testToStringReturnsDocumentId(): void
    {
        $value = new SearchDocumentValue('places', '42');

        $this->assertSame('42', (string)$value);
    }

    public function testSerializeRoundTrip(): void
    {
        $value = new SearchDocumentValue('events', '999');

        $serialized = serialize($value);
        /** @var SearchDocumentValue $restored */
        $restored = unserialize($serialized);

        $this->assertInstanceOf(SearchDocumentValue::class, $restored);
        $this->assertSame('events', $restored->indexHandle);
        $this->assertSame('999', $restored->documentId);
    }

    public function testMagicSerializeReturnsArray(): void
    {
        $value = new SearchDocumentValue('places', '55');

        $data = $value->__serialize();

        $this->assertSame([
            'indexHandle' => 'places',
            'documentId' => '55',
        ], $data);
    }

    public function testMagicUnserializeRestoresProperties(): void
    {
        // Create via unserialize roundtrip
        $original = new SearchDocumentValue('my_index', 'doc_42');
        $serialized = serialize($original);

        /** @var SearchDocumentValue $restored */
        $restored = unserialize($serialized);

        $this->assertSame('my_index', $restored->indexHandle);
        $this->assertSame('doc_42', $restored->documentId);
    }

    public function testMagicUnserializeHandlesMissingKeys(): void
    {
        $value = new SearchDocumentValue('temp', 'temp');
        $value->__unserialize([]);

        $this->assertSame('', $value->indexHandle);
        $this->assertSame('', $value->documentId);
    }

    public function testGetDocumentReturnsNullWithoutPlugin(): void
    {
        // Without the Craft plugin bootstrapped, getDocument() should
        // gracefully return null rather than throwing.
        $value = new SearchDocumentValue('nonexistent', '1');

        $this->assertNull($value->getDocument());
    }

    public function testGetDocumentCachesResult(): void
    {
        // Call twice — both should return null (no Craft), but shouldn't error.
        $value = new SearchDocumentValue('nonexistent', '1');

        $first = $value->getDocument();
        $second = $value->getDocument();

        $this->assertNull($first);
        $this->assertNull($second);
    }

    public function testToStringOnDifferentIds(): void
    {
        $this->assertSame('abc-123', (string)new SearchDocumentValue('idx', 'abc-123'));
        $this->assertSame('0', (string)new SearchDocumentValue('idx', '0'));
        $this->assertSame('', (string)new SearchDocumentValue('idx', ''));
    }
}
