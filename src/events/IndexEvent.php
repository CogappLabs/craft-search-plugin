<?php

namespace cogapp\searchindex\events;

use cogapp\searchindex\models\Index;
use yii\base\Event;

class IndexEvent extends Event
{
    public Index $index;
    public bool $isNew = false;
}
