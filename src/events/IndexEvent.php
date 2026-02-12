<?php

/**
 * Search Index plugin for Craft CMS -- IndexEvent.
 */

namespace cogapp\searchindex\events;

use cogapp\searchindex\models\Index;
use yii\base\Event;

/**
 * Event fired during index save/delete lifecycle operations.
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexEvent extends Event
{
    /** @var Index The index model involved in the event. */
    public Index $index;
    /** @var bool Whether the index is being created for the first time. */
    public bool $isNew = false;
}
