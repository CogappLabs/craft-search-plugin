<?php

namespace cogapp\searchindex\resolvers;

use cogapp\searchindex\models\FieldMapping;
use craft\base\Element;
use craft\base\FieldInterface;

interface FieldResolverInterface
{
    public function resolve(Element $element, ?FieldInterface $field, FieldMapping $mapping): mixed;

    public static function supportedFieldTypes(): array;
}
