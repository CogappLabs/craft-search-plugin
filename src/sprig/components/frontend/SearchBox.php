<?php

/**
 * Search Index plugin for Craft CMS -- frontend Sprig search box component.
 */

namespace cogapp\searchindex\sprig\components\frontend;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use cogapp\searchindex\sprig\SprigBooleanTrait;
use cogapp\searchindex\variables\SearchIndexVariable;
use Craft;
use putyourlightson\sprig\base\Component;

/**
 * Minimal unstyled frontend search component for quick testing.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchBox extends Component
{
    use SprigBooleanTrait;

    /** @var string Index handle to query. */
    public string $indexHandle = '';

    /** @var string Query string. */
    public string $query = '';

    /** @var int|string Number of results per page. */
    public int|string $perPage = 10;

    /** @var int|string Current page. */
    public int|string $page = 1;

    /** @var string Sort field name. */
    public string $sortField = '';

    /** @var string Sort direction (`asc` or `desc`). */
    public string $sortDirection = 'desc';

    /** @var string[] Facet field names to request/display. */
    public array $facetFields = [];

    /** @var array<string, mixed> Active filters by field (facet: string[], range: {min, max}). */
    public array $filters = [];

    /**
     * @var string Active facet field name for dedup (set by facet checkbox forms).
     *
     * Sprig re-sends component state (including filters) via `data-hx-vals`
     * alongside form inputs, causing duplicates. Facet forms use `siFacetField`
     * + `siFacetValues` so the checkbox state is authoritative for one field.
     */
    public string $siFacetField = '';

    /** @var string[] Authoritative facet values for the active facet field. */
    public array $siFacetValues = [];

    /** @var bool|int|string Clear all filters on this request. */
    public bool|int|string $clearFilters = false;

    /** @var bool|int|string Whether to run the search. */
    public bool|int|string $doSearch = false;

    /** @var bool|int|string Whether to auto-trigger search while typing/changing controls. */
    public bool|int|string $autoSearch = false;

    /** @var bool|int|string Whether to hide the submit button. */
    public bool|int|string $hideSubmit = false;

    /** @var bool|int|string Whether to show raw response JSON block. */
    public bool|int|string $showRaw = false;

    /** @var bool|int|string Whether to show per-hit role debug values. */
    public bool|int|string $showRoleDebug = false;

    /** @var string Stable ID prefix for form controls (helps preserve focus across swaps). */
    public string $idPrefix = 'si-search';

    /** @var array|null Search response payload. */
    public ?array $data = null;

    /** @var array<int, array{label: string, value: string}> Basic sort options for starter UI. */
    public array $sortOptions = [];

    /** @var string[] Numeric field names (integer/float, no role) for range filters. */
    public array $numericFields = [];

    /**
     * @var SearchIndexVariable|null Cached variable instance for CP/frontend search calls.
     */
    private ?SearchIndexVariable $searchVariable = null;

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/frontend/search-box';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->hydrateFromQueryParams();

        if ($this->indexHandle === '') {
            return;
        }

        if ($this->shouldClearFilters()) {
            $this->filters = [];
            $this->page = 1;
        }

        // Merge authoritative facet checkbox state into filters.
        if ($this->siFacetField !== '') {
            if (!empty($this->siFacetValues)) {
                $this->filters[$this->siFacetField] = $this->siFacetValues;
            } else {
                unset($this->filters[$this->siFacetField]);
            }
        }

        $this->filters = $this->normaliseFilters($this->filters);
        $this->facetFields = $this->resolveFacetFields();
        $this->sortOptions = $this->resolveSortOptions();
        $this->numericFields = $this->resolveNumericFields();

        if (!$this->shouldSearch()) {
            return;
        }

        $options = [
            'perPage' => max(1, (int)$this->perPage),
            'page' => max(1, (int)$this->page),
        ];

        if (!empty($this->filters)) {
            $options['filters'] = $this->filters;
        }

        if (!empty($this->facetFields)) {
            $options['facets'] = $this->facetFields;
        }

        if (!empty($this->numericFields)) {
            $options['stats'] = $this->numericFields;
        }

        if ($this->sortField !== '') {
            $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';
            $options['sort'] = [$this->sortField => $direction];
        }

        $this->data = $this->getSearchVariable()->cpSearch($this->indexHandle, $this->query, $options);

        // Auto-histogram: calculate nice intervals from stats, fetch in lightweight follow-up.
        if (!empty($this->numericFields) && $this->data['success'] && !empty($this->data['stats'])) {
            $histogramConfig = [];
            $variable = $this->getSearchVariable();

            foreach ($this->data['stats'] as $field => $stat) {
                $interval = $variable->niceInterval($stat['min'] ?? 0, $stat['max'] ?? 0);
                if ($interval > 0) {
                    $histogramConfig[$field] = $interval;
                }
            }

            if (!empty($histogramConfig)) {
                $histogramOptions = [
                    'perPage' => 0,
                    'histogram' => $histogramConfig,
                ];
                if (!empty($this->filters)) {
                    $histogramOptions['filters'] = $this->filters;
                }
                $histogramResult = $variable->cpSearch($this->indexHandle, $this->query, $histogramOptions);
                if (!empty($histogramResult['histograms'])) {
                    $this->data['histograms'] = $histogramResult['histograms'];
                }
            }
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
     * Returns whether raw JSON panel should be shown.
     */
    public function shouldShowRaw(): bool
    {
        return $this->toBool($this->showRaw);
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

    /**
     * Returns whether filters should be cleared for this request.
     */
    private function shouldClearFilters(): bool
    {
        return $this->toBool($this->clearFilters);
    }

    /**
     * Populate component state from query params for first-page load and URL sync.
     */
    private function hydrateFromQueryParams(): void
    {
        $request = Craft::$app->getRequest();

        $query = $this->getFirstQueryParamValue('query');
        if ($query !== null) {
            $this->query = $query;
        } elseif ($this->query === '') {
            $this->query = (string)$request->getQueryParam('query', '');
        }

        $indexHandle = $this->getFirstQueryParamValue('indexHandle');
        if ($indexHandle !== null && $indexHandle !== '') {
            $this->indexHandle = $indexHandle;
        } elseif ($this->indexHandle === '') {
            $this->indexHandle = (string)$request->getQueryParam('indexHandle', '');
        }

        $perPage = $this->getFirstQueryParamValue('perPage');
        if ($perPage !== null && $perPage !== '') {
            $this->perPage = (int)$perPage;
        } elseif ((int)$this->perPage === 10) {
            $this->perPage = (int)$request->getQueryParam('perPage', $this->perPage);
        }

        $page = $this->getFirstQueryParamValue('page');
        if ($page !== null && $page !== '') {
            $this->page = (int)$page;
        } elseif ((int)$this->page === 1) {
            $this->page = (int)$request->getQueryParam('page', $this->page);
        }

        $sortField = $this->getFirstQueryParamValue('sortField');
        if ($sortField !== null) {
            $this->sortField = $sortField;
        } elseif ($this->sortField === '') {
            $this->sortField = (string)$request->getQueryParam('sortField', '');
        }

        $sortDirection = $this->getFirstQueryParamValue('sortDirection');
        if ($sortDirection !== null && $sortDirection !== '') {
            $this->sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';
        } elseif ($this->sortDirection === 'desc') {
            $fallbackSortDirection = (string)$request->getQueryParam('sortDirection', $this->sortDirection);
            $this->sortDirection = $fallbackSortDirection === 'asc' ? 'asc' : 'desc';
        }

        if (empty($this->facetFields)) {
            $facetFields = $request->getQueryParam('facetFields', []);
            if (is_array($facetFields)) {
                $this->facetFields = array_values(array_filter(array_map('strval', $facetFields), fn(string $value) => $value !== ''));
            }
        }

        if (empty($this->filters)) {
            $filters = $request->getQueryParam('filters', []);
            if (is_array($filters)) {
                $this->filters = $this->normaliseFilters($filters);
            }
        }

        if (!$this->shouldSearch()) {
            $doSearch = $this->getFirstQueryParamValue('doSearch');
            if ($doSearch !== null) {
                $this->doSearch = in_array($doSearch, ['1', 'true', 'yes', 'on'], true);
            } else {
                $this->doSearch = (string)$request->getQueryParam('doSearch', '') === '1';
            }
        }
    }

    /**
     * Returns the first scalar query parameter value for a key.
     *
     * Sprig requests can include duplicate scalar params where later values are stale
     * defaults from `sprig:config`. We prefer the first value, which is the submitted value.
     */
    private function getFirstQueryParamValue(string $name): ?string
    {
        $queryString = Craft::$app->getRequest()->getQueryStringWithoutPath();
        if ($queryString === null || $queryString === '') {
            return null;
        }

        foreach (explode('&', $queryString) as $chunk) {
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }

            [$rawKey, $rawValue] = explode('=', $chunk, 2);
            if (urldecode($rawKey) !== $name) {
                continue;
            }

            return urldecode($rawValue);
        }

        return null;
    }

    /**
     * Resolve facet fields from config or index mappings.
     *
     * @return string[]
     */
    private function resolveFacetFields(): array
    {
        if (!empty($this->facetFields)) {
            return array_values(array_unique(array_filter($this->facetFields, fn(string $field) => $field !== '')));
        }

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
        if (!$index) {
            return [];
        }

        $facetFields = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if ($mapping->enabled && $mapping->indexFieldType === FieldMapping::TYPE_FACET) {
                $facetFields[] = $mapping->indexFieldName;
            }
        }

        return array_values(array_unique($facetFields));
    }

    /**
     * Resolve starter sort options from enabled index mappings.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function resolveSortOptions(): array
    {
        $options = [
            ['label' => 'Relevance', 'value' => ''],
        ];

        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
        if (!$index) {
            return $options;
        }

        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
            }

            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT, FieldMapping::TYPE_DATE], true)) {
                $options[] = [
                    'label' => $mapping->indexFieldName,
                    'value' => $mapping->indexFieldName,
                ];
            }
        }

        return $options;
    }

    /**
     * Resolve numeric (non-role) field names for range filters.
     *
     * @return string[]
     */
    private function resolveNumericFields(): array
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexByHandle($this->indexHandle);
        if (!$index) {
            return [];
        }

        $numericFields = [];
        foreach ($index->getFieldMappings() as $mapping) {
            if (!$mapping->enabled || $mapping->indexFieldName === '') {
                continue;
            }
            if (in_array($mapping->indexFieldType, [FieldMapping::TYPE_INTEGER, FieldMapping::TYPE_FLOAT], true)
                && $mapping->role === null
            ) {
                $numericFields[] = $mapping->indexFieldName;
            }
        }

        return array_values(array_unique($numericFields));
    }

    /**
     * Normalise filters: facet filters to `field => [value, ...]`, range filters to `field => {min, max}`.
     *
     * @param array $filters
     * @return array<string, mixed>
     */
    private function normaliseFilters(array $filters): array
    {
        $normalised = [];

        foreach ($filters as $field => $values) {
            $fieldName = (string)$field;
            if ($fieldName === '') {
                continue;
            }

            // Range filter: { min: ..., max: ... }
            if (is_array($values) && (array_key_exists('min', $values) || array_key_exists('max', $values))) {
                $range = [];
                if (isset($values['min']) && $values['min'] !== '') {
                    $range['min'] = $values['min'];
                }
                if (isset($values['max']) && $values['max'] !== '') {
                    $range['max'] = $values['max'];
                }
                if (!empty($range)) {
                    $normalised[$fieldName] = $range;
                }
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            $filteredValues = array_values(array_unique(array_filter(array_map('strval', $values), fn(string $value) => $value !== '')));
            if (!empty($filteredValues)) {
                $normalised[$fieldName] = $filteredValues;
            }
        }

        return $normalised;
    }
}
