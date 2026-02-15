<?php

/**
 * Search Index plugin for Craft CMS -- Sprig index structure component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\SearchIndex;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component class for fetching and rendering engine index schema in the CP.
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexStructure extends Component
{
    /**
     * @var int|string Index ID.
     */
    public int|string $indexId = 0;

    /**
     * @var array{success: bool, schema?: array, message?: string}
     */
    public array $result = [
        'success' => false,
        'message' => 'Index not found.',
    ];

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/index-structure';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $index = SearchIndex::$plugin->getIndexes()->getIndexById((int)$this->indexId);

        if (!$index) {
            $this->result = [
                'success' => false,
                'message' => 'Index not found.',
            ];

            return;
        }

        $this->result = SearchIndex::$plugin->getIndexes()->getIndexSchema($index);
    }
}
