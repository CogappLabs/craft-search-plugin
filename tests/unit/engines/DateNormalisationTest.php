<?php

namespace cogapp\searchindex\tests\unit\engines;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DateNormalisationTest extends TestCase
{
    private StubEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new StubEngine();
    }

    public function testNormaliseDateValueToEpochSecondsFromInteger(): void
    {
        $this->assertSame(
            1737376680,
            $this->engine->publicNormaliseDateValue(1737376680, 'epoch_seconds')
        );
    }

    public function testNormaliseDateValueToEpochSecondsFromMilliseconds(): void
    {
        $this->assertSame(
            1737376680,
            $this->engine->publicNormaliseDateValue(1737376680000, 'epoch_seconds')
        );
    }

    public function testNormaliseDateValueToEpochSecondsFromIsoString(): void
    {
        $this->assertSame(
            1737376680,
            $this->engine->publicNormaliseDateValue('2025-01-20T12:38:00+00:00', 'epoch_seconds')
        );
    }

    public function testNormaliseDateValueToIso8601FromTimestamp(): void
    {
        $this->assertSame(
            '2025-01-20T12:38:00+00:00',
            $this->engine->publicNormaliseDateValue(1737376680, 'iso8601')
        );
    }

    public function testNormaliseDateValueToIso8601FromDateTime(): void
    {
        $value = new DateTimeImmutable('2025-01-20T12:38:00+00:00');

        $this->assertSame(
            '2025-01-20T12:38:00+00:00',
            $this->engine->publicNormaliseDateValue($value, 'iso8601')
        );
    }

    public function testNormaliseDateFieldsOnlyTouchesMappedDateFields(): void
    {
        $dateMapping = new FieldMapping();
        $dateMapping->enabled = true;
        $dateMapping->indexFieldName = 'postDate';
        $dateMapping->indexFieldType = FieldMapping::TYPE_DATE;

        $textMapping = new FieldMapping();
        $textMapping->enabled = true;
        $textMapping->indexFieldName = 'title';
        $textMapping->indexFieldType = FieldMapping::TYPE_TEXT;

        $index = new Index();
        $index->setFieldMappings([$dateMapping, $textMapping]);

        $document = [
            'postDate' => '1737376680000',
            'title' => 'Arduaine Garden',
            'score' => 100,
        ];

        $normalised = $this->engine->publicNormaliseDateFields($index, $document, 'epoch_seconds');

        $this->assertSame(1737376680, $normalised['postDate']);
        $this->assertSame('Arduaine Garden', $normalised['title']);
        $this->assertSame(100, $normalised['score']);
    }

    public function testNormaliseDateValueReturnsNullForUnparseableValues(): void
    {
        $this->assertNull($this->engine->publicNormaliseDateValue('not-a-date', 'epoch_seconds'));
    }
}
