<?php

namespace cogapp\searchindex\tests\unit\services;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\resolvers\AttributeResolver;
use cogapp\searchindex\resolvers\PlainTextResolver;
use cogapp\searchindex\services\FieldMapper;
use craft\base\FieldInterface;
use craft\fields\Date;
use craft\fields\PlainText;
use PHPUnit\Framework\TestCase;

class PlainTextChildField extends PlainText
{
}

class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\Yii::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
        }
    }

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper();
    }

    public function testGetDefaultIndexTypeForKnownFieldClass(): void
    {
        $field = new Date(['handle' => 'publishDate', 'name' => 'Publish Date']);

        $this->assertSame(FieldMapping::TYPE_DATE, $this->mapper->getDefaultIndexType($field));
    }

    public function testGetDefaultIndexTypeFallsBackToTextForUnknownFieldClass(): void
    {
        $field = $this->createMock(FieldInterface::class);

        $this->assertSame(FieldMapping::TYPE_TEXT, $this->mapper->getDefaultIndexType($field));
    }

    public function testGetResolverForFieldNullReturnsCachedAttributeResolver(): void
    {
        $resolverA = $this->mapper->getResolverForField(null);
        $resolverB = $this->mapper->getResolverForField(null);

        $this->assertInstanceOf(AttributeResolver::class, $resolverA);
        $this->assertSame($resolverA, $resolverB);
    }

    public function testGetResolverForUnknownFieldFallsBackToPlainTextResolver(): void
    {
        $field = $this->createMock(FieldInterface::class);

        $this->assertInstanceOf(PlainTextResolver::class, $this->mapper->getResolverForField($field));
    }

    public function testGetResolverForSubclassUsesParentResolverMapping(): void
    {
        $field = new PlainTextChildField(['handle' => 'summary', 'name' => 'Summary']);

        $this->assertInstanceOf(PlainTextResolver::class, $this->mapper->getResolverForField($field));
    }
}
