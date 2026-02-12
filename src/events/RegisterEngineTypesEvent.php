<?php

/**
 * Search Index plugin for Craft CMS -- RegisterEngineTypesEvent.
 */

namespace cogapp\searchindex\events;

use yii\base\Event;

/**
 * Event fired to allow registration of additional search engine types.
 *
 * @author cogapp
 * @since 1.0.0
 */
class RegisterEngineTypesEvent extends Event
{
    /** @var string[] Engine class names implementing EngineInterface. */
    public array $types = [];
}
