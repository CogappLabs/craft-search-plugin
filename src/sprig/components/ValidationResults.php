<?php

/**
 * Search Index plugin for Craft CMS -- Sprig validation results component.
 */

namespace cogapp\searchindex\sprig\components;

use cogapp\searchindex\SearchIndex;
use cogapp\searchindex\sprig\SprigBooleanTrait;
use Craft;
use putyourlightson\sprig\base\Component;

/**
 * Sprig component class for validating field mappings in the CP.
 *
 * @author cogapp
 * @since 1.0.0
 */
class ValidationResults extends Component
{
    use SprigBooleanTrait;

    /**
     * @var int The index ID to validate.
     */
    public int|string $indexId = 0;

    /**
     * @var bool Whether validation should run on this request.
     */
    public bool|int|string $run = false;

    /**
     * @var array<string, mixed>|null Validation payload.
     */
    public ?array $data = null;

    /**
     * @var int Count of successfully validated fields.
     */
    public int $totalOk = 0;

    /**
     * @var int Count of warning fields.
     */
    public int $totalWarnings = 0;

    /**
     * @var int Count of error fields.
     */
    public int $totalErrors = 0;

    /**
     * @var int Count of null fields.
     */
    public int $totalNull = 0;

    /**
     * @var string[] Human-readable summary parts.
     */
    public array $summaryParts = [];

    /**
     * @var string Markdown for all validation rows.
     */
    public string $fullMarkdown = '';

    /**
     * @var string Markdown for warnings/errors/nulls only.
     */
    public string $warningsMarkdown = '';

    /**
     * @inheritdoc
     */
    protected ?string $_template = 'search-index/_sprig/validation-results';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->shouldRun()) {
            return;
        }

        $indexId = (int)$this->indexId;
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            $this->data = [
                'success' => false,
                'message' => Craft::t('search-index', 'errors.indexNotFoundSentence'),
            ];

            return;
        }

        $this->data = SearchIndex::$plugin->getFieldMappingValidator()->validateIndex($index);

        if (!$this->data['success']) {
            return;
        }

        $results = $this->data['results'];
        foreach ($results as $field) {
            if ($field['status'] === 'ok') {
                $this->totalOk++;
            } elseif ($field['status'] === 'error') {
                $this->totalErrors++;
            } elseif ($field['status'] === 'null') {
                $this->totalNull++;
            } else {
                $this->totalWarnings++;
            }
        }

        $this->summaryParts = [Craft::t('search-index', 'help.countFieldsValidated', ['count' => count($results)])];

        if ($this->totalOk > 0) {
            $this->summaryParts[] = Craft::t('search-index', 'labels.countOk', ['count' => $this->totalOk]);
        }

        if ($this->totalWarnings > 0) {
            $this->summaryParts[] = Craft::t('search-index', 'labels.countWarnings', ['count' => $this->totalWarnings]);
        }

        if ($this->totalErrors > 0) {
            $this->summaryParts[] = Craft::t('search-index', 'errors.countErrors', ['count' => $this->totalErrors]);
        }

        if ($this->totalNull > 0) {
            $this->summaryParts[] = Craft::t('search-index', 'labels.countWithNoData', ['count' => $this->totalNull]);
        }

        $validator = SearchIndex::$plugin->getFieldMappingValidator();
        $this->fullMarkdown = $validator->buildValidationMarkdown($this->data);
        $this->warningsMarkdown = $validator->buildValidationMarkdown($this->data, 'issues', ' (Warnings, Errors & Nulls)');
    }

    /**
     * Returns whether validation should run for the current request.
     */
    private function shouldRun(): bool
    {
        return $this->toBool($this->run);
    }
}
