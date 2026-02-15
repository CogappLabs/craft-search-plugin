<?php

/**
 * Search Index plugin for Craft CMS -- Sprig single search component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\sprig\SprigBooleanTrait;
use cogapp\searchindex\variables\SearchIndexVariable;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component class for single-index CP search.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchSingle extends Component
{
    use SprigBooleanTrait;

    /**
     * @var array<int, array{label: string, value: string}> Index options for select input.
     */
    public array $indexOptions = [];

    /**
     * @var array<string, string[]> Map of index handle => embedding fields.
     */
    public array $embeddingFields = [];

    /** @var string */
    public string $query = '';

    /** @var string */
    public string $selectedIndex = '';

    /** @var string */
    public string $searchMode = 'text';

    /** @var string */
    public string $embeddingField = '';

    /** @var int|string */
    public int|string $perPage = 20;

    /** @var bool|int|string */
    public bool|int|string $doSearch = true;

    /** @var bool|int|string */
    public bool|int|string $autoSearch = true;

    /** @var bool|int|string */
    public bool|int|string $hideSubmit = true;

    /** @var string[] */
    public array $indexEmbeddingFields = [];

    /** @var bool */
    public bool $hasEmbedding = false;

    /** @var array|null */
    public ?array $data = null;

    /**
     * @var SearchIndexVariable|null Cached variable instance for CP search calls.
     */
    private ?SearchIndexVariable $searchVariable = null;

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/search-single';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if ($this->selectedIndex === '' && !empty($this->indexOptions[0]['value'])) {
            $this->selectedIndex = (string)$this->indexOptions[0]['value'];
        }

        $this->indexEmbeddingFields = $this->embeddingFields[$this->selectedIndex] ?? [];
        $this->hasEmbedding = count($this->indexEmbeddingFields) > 0;

        if (!$this->shouldSearch()) {
            return;
        }

        $searchOptions = [
            'perPage' => (int)$this->perPage,
            'searchMode' => $this->searchMode,
        ];

        if ($this->embeddingField !== '') {
            $searchOptions['embeddingField'] = $this->embeddingField;
        }

        $this->data = $this->getSearchVariable()->cpSearch($this->selectedIndex, $this->query, $searchOptions);
    }

    /**
     * Returns whether search should run for this request.
     */
    private function shouldSearch(): bool
    {
        return $this->toBool($this->doSearch);
    }

    /**
     * Returns whether auto-search should be enabled.
     */
    public function shouldAutoSearch(): bool
    {
        return $this->toBool($this->autoSearch);
    }

    /**
     * Returns whether submit button should be hidden.
     */
    public function shouldHideSubmit(): bool
    {
        return $this->toBool($this->hideSubmit);
    }

    /**
     * Returns a shared SearchIndexVariable instance for this component request.
     */
    private function getSearchVariable(): SearchIndexVariable
    {
        if ($this->searchVariable === null) {
            $this->searchVariable = new SearchIndexVariable();
        }

        return $this->searchVariable;
    }
}
