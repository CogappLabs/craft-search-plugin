<?php

/**
 * Search Index plugin for Craft CMS -- frontend Sprig facets component.
 */

namespace cogapp\searchindex\sprig\components\frontend;

/**
 * Facets-only frontend starter component.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchFacets extends SearchBox
{
    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/frontend/search-facets';
}
