<?php

namespace cogapp\searchindex\events;

use cogapp\searchindex\models\Index;
use craft\base\Element;
use yii\base\Event;

class ElementIndexEvent extends Event
{
    public Element $element;
    public Index $index;
    public array $document = [];
}
