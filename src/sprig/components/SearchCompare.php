<?php

/**
 * Search Index plugin for Craft CMS -- Sprig compare search component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\sprig\SprigBooleanTrait;
use cogapp\searchindex\variables\SearchIndexVariable;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component class for multi-index CP search comparison.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchCompare extends Component
{
    use SprigBooleanTrait;

    /**
     * @var array<int, array{label: string, value: string}> Index options for checkbox select.
     */
    public array $indexOptions = [];

    /** @var string */
    public string $query = '';

    /** @var int|string */
    public int|string $perPage = 20;

    /** @var string[] */
    public array $compareIndexes = [];

    /** @var bool|int|string */
    public bool|int|string $doSearch = false;

    /** @var bool|int|string */
    public bool|int|string $autoSearch = true;

    /** @var bool|int|string */
    public bool|int|string $hideSubmit = true;

    /** @var array<string, array<string, mixed>> */
    public array $resultsByIndex = [];

    /**
     * @var SearchIndexVariable|null Cached variable instance for CP search calls.
     */
    private ?SearchIndexVariable $searchVariable = null;

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/search-compare';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->shouldSearch() || trim($this->query) === '' || empty($this->compareIndexes)) {
            return;
        }

        $perPage = (int)$this->perPage;

        foreach ($this->compareIndexes as $handle) {
            $this->resultsByIndex[$handle] = $this->getSearchVariable()->cpSearch($handle, $this->query, ['perPage' => $perPage]);
        }
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
