<?php

/**
 * Search Index plugin for Craft CMS -- frontend Sprig pagination component.
 */

namespace cogapp\searchindex\sprig\components\frontend;

/**
 * Pagination-only frontend starter component.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchPagination extends SearchBox
{
    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/frontend/search-pagination';
}
