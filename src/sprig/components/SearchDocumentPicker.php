<?php

/**
 * Search Index plugin for Craft CMS -- Sprig search document picker component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\SearchIndex;
use cogapp\searchindex\sprig\SprigBooleanTrait;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component for the SearchDocumentField picker UI.
 *
 * Handles search-as-you-type, result rendering, and selection state.
 * Hidden form inputs for Craft's namespaced form live outside this
 * component in `_field/input.twig`; a thin JS bridge syncs data-*
 * attributes from the Sprig root to those hidden inputs after each swap.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchDocumentPicker extends Component
{
    use SprigBooleanTrait;

    /** @var string Search index handle. */
    public string $indexHandle = '';

    /** @var int Results per search request. */
    public int $perPage = 10;

    /** @var string Current search query. */
    public string $query = '';

    /** @var string Selected document ID (empty = no selection). */
    public string $documentId = '';

    /** @var string Display title for the selected document. */
    public string $documentTitle = '';

    /** @var string URI/slug of the selected document. */
    public string $documentUri = '';

    /** @var string Section handle from the selected document. */
    public string $sectionHandle = '';

    /** @var string Entry type handle from the selected document. */
    public string $entryTypeHandle = '';

    /** @var string Unique field ID for DOM targeting. */
    public string $fieldId = '';

    /** @var array|null Search results (null = no search performed). */
    public ?array $results = null;

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/search-document-picker';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // If a document is selected but we don't have its title, fetch it
        if ($this->documentId !== '' && $this->documentTitle === '') {
            $this->_fetchSelectedDocument();
        }

        // If no selection and query is non-empty, run search
        if ($this->documentId === '' && trim($this->query) !== '') {
            $this->_runSearch();
        }
    }

    /**
     * Fetch the selected document's display data from the engine.
     */
    private function _fetchSelectedDocument(): void
    {
        if ($this->indexHandle === '' || $this->documentId === '') {
            return;
        }

        try {
            $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
            if (!$index) {
                $this->documentTitle = $this->documentId;
                return;
            }

            $engine = $index->createEngine();
            $doc = $engine->getDocument($index, $this->documentId);
            if ($doc) {
                $this->documentTitle = $doc['title'] ?? $doc['name'] ?? $this->documentId;
                $this->documentUri = $doc['uri'] ?? '';
                $this->sectionHandle = $this->sectionHandle ?: ($doc['sectionHandle'] ?? '');
                $this->entryTypeHandle = $this->entryTypeHandle ?: ($doc['entryTypeHandle'] ?? '');
            } else {
                $this->documentTitle = $this->documentId;
            }
        } catch (\Throwable $e) {
            $this->documentTitle = $this->documentId;
        }
    }

    /**
     * Search the index for the current query.
     */
    private function _runSearch(): void
    {
        if ($this->indexHandle === '') {
            return;
        }

        try {
            $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
            if (!$index) {
                return;
            }

            $engine = $index->createEngine();
            $result = $engine->search($index, trim($this->query), [
                'perPage' => $this->perPage,
            ]);

            $this->results = $result->hits;
        } catch (\Throwable $e) {
            $this->results = [];
        }
    }
}
