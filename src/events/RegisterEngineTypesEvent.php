<?php

namespace cogapp\searchindex\events;

use yii\base\Event;

class RegisterEngineTypesEvent extends Event
{
    /** @var string[] Engine class names */
    public array $types = [];
}
