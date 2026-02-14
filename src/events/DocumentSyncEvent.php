<?php

/**
 * Search Index plugin for Craft CMS -- DocumentSyncEvent.
 */

namespace cogapp\searchindex\events;

use cogapp\searchindex\models\Index;
use yii\base\Event;

/**
 * Event fired after a document has been synced (indexed or deleted) in the search engine.
 *
 * @author cogapp
 * @since 1.0.0
 */
class DocumentSyncEvent extends Event
{
    /** @var Index The search index the document was synced to. */
    public Index $index;
    /** @var int The Craft element ID of the document. */
    public int $elementId;
    /** @var string The action performed: 'upsert' or 'delete'. */
    public string $action;
}
