<?php

/**
 * Search Index plugin for Craft CMS -- ElementIndexEvent.
 */

namespace cogapp\searchindex\events;

use cogapp\searchindex\models\Index;
use craft\base\Element;
use yii\base\Event;

/**
 * Event fired before an element document is sent to the search engine,
 * allowing listeners to modify the document payload.
 *
 * @author cogapp
 * @since 1.0.0
 */
class ElementIndexEvent extends Event
{
    /** @var Element The element being indexed. */
    public Element $element;
    /** @var Index The search index the element is being added to. */
    public Index $index;
    /** @var array The document payload that will be sent to the engine. */
    public array $document = [];
}
