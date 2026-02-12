<?php

namespace cogapp\searchindex\events;

use yii\base\Event;

class RegisterFieldResolversEvent extends Event
{
    /** @var array<string, string> Map of field class => resolver class */
    public array $resolvers = [];
}
