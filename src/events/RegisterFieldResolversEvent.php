<?php

/**
 * Search Index plugin for Craft CMS -- RegisterFieldResolversEvent.
 */

namespace cogapp\searchindex\events;

use yii\base\Event;

/**
 * Event fired to allow registration of custom field resolvers for indexing.
 *
 * @author cogapp
 * @since 1.0.0
 */
class RegisterFieldResolversEvent extends Event
{
    /** @var array<string, string> Map of field class => resolver class. */
    public array $resolvers = [];
}
