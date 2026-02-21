<?php

/**
 * Search Index plugin for Craft CMS -- FieldMappingValidator service.
 */

namespace cogapp\searchindex\services;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\base\FieldInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use yii\base\Component;

/**
 * Validates field mappings by resolving real entries and diagnosing type mismatches.
 *
 * Shared by the CP FieldMappingsController and the console IndexController
 * so that both surfaces produce identical results from a single code path.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FieldMappingValidator extends Component
{
    /**
     * Validate every enabled field mapping on an index.
     *
     * @param Index               $index
     * @param \craft\elements\Entry|null $forceEntry Optional entry to validate all mappings against.
     * @return array<string, mixed>
     */
    public function validateIndex(Index $index, ?Entry $forceEntry = null): array
    {
        if ($index->isReadOnly()) {
            return $this->validateReadOnlyIndex($index);
        }

        $mappings = $index->getFieldMappings();
        $enabledMappings = array_filter($mappings, fn(FieldMapping $m) => $m->enabled);

        if (empty($enabledMappings)) {
            return [
                'success' => false,
                'indexName' => $index->name,
                'indexHandle' => $index->handle,
                'entryTypeNames' => [],
                'results' => [],
                'message' => 'No enabled field mappings to validate.',
            ];
        }

        $entryTypeNames = [];
        foreach ($index->entryTypeIds ?? [] as $etId) {
            $entryType = Craft::$app->getEntries()->getEntryTypeById($etId);
            if ($entryType) {
                $entryTypeNames[] = $entryType->name;
            }
        }

        $fieldMapper = SearchIndex::$plugin->getFieldMapper();
        $documentCache = [];
        $results = [];

        foreach ($enabledMappings as $mapping) {
            $entry = $forceEntry ?? $this->findEntryWithData($index, $mapping);

            if (!$entry) {
                $results[] = [
                    'indexFieldName' => $mapping->indexFieldName,
                    'indexFieldType' => $mapping->indexFieldType,
                    'entryId' => null,
                    'entryTitle' => null,
                    'value' => null,
                    'phpType' => 'null',
                    'status' => 'null',
                    'warning' => 'No entries found with data for this field.',
                ];
                continue;
            }

            if (!isset($documentCache[$entry->id])) {
                $documentCache[$entry->id] = $fieldMapper->resolveElement($entry, $index);
            }

            $document = $documentCache[$entry->id];
            $value = $document[$mapping->indexFieldName] ?? null;
            $diagnostic = $this->diagnoseValue($value, $mapping);

            $results[] = [
                'indexFieldName' => $mapping->indexFieldName,
                'indexFieldType' => $mapping->indexFieldType,
                'entryId' => $entry->id,
                'entryTitle' => $entry->title,
                'value' => $this->formatValue($value),
                'phpType' => $this->getPhpType($value),
                'status' => $diagnostic['status'],
                'warning' => $diagnostic['warning'],
            ];
        }

        return [
            'success' => true,
            'readOnly' => false,
            'indexName' => $index->name,
            'indexHandle' => $index->handle,
            'entryTypeNames' => $entryTypeNames,
            'results' => $results,
        ];
    }

    /**
     * Validate field mappings on a read-only index by fetching sample documents from the engine.
     *
     * @param Index $index
     * @return array<string, mixed>
     */
    public function validateReadOnlyIndex(Index $index): array
    {
        $mappings = $index->getFieldMappings();
        $enabledMappings = array_filter($mappings, fn(FieldMapping $m) => $m->enabled);

        if (empty($enabledMappings)) {
            return [
                'success' => false,
                'indexName' => $index->name,
                'indexHandle' => $index->handle,
                'entryTypeNames' => [],
                'results' => [],
                'message' => 'No enabled field mappings to validate.',
            ];
        }

        // Fetch sample documents in pages, expanding the sample if sparse fields remain
        $perPage = 10;
        $maxPages = 5;
        $sampleDocs = [];

        try {
            $engine = $index->createEngine();
            for ($page = 1; $page <= $maxPages; $page++) {
                $searchResult = $engine->search($index, '', ['perPage' => $perPage, 'page' => $page]);
                if (empty($searchResult->hits)) {
                    break;
                }
                array_push($sampleDocs, ...$searchResult->hits);

                // Check if every enabled field has been seen in at least one doc
                $allResolved = true;
                foreach ($enabledMappings as $mapping) {
                    $found = false;
                    foreach ($sampleDocs as $doc) {
                        if (isset($doc[$mapping->indexFieldName]) && $doc[$mapping->indexFieldName] !== null) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $allResolved = false;
                        break;
                    }
                }
                if ($allResolved || count($searchResult->hits) < $perPage) {
                    break;
                }
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'indexName' => $index->name,
                'indexHandle' => $index->handle,
                'entryTypeNames' => [],
                'results' => [],
                'message' => 'Engine error: ' . $e->getMessage(),
            ];
        }

        if (empty($sampleDocs)) {
            return [
                'success' => false,
                'indexName' => $index->name,
                'indexHandle' => $index->handle,
                'entryTypeNames' => [],
                'results' => [],
                'message' => 'No documents found in engine.',
            ];
        }

        $results = [];
        $assignedRoles = [];

        foreach ($enabledMappings as $mapping) {
            if ($mapping->role) {
                $assignedRoles[] = $mapping->role;
            }

            // Find first non-null value across sample docs
            $bestValue = null;
            $sourceDocId = null;
            foreach ($sampleDocs as $hit) {
                $fieldValue = $hit[$mapping->indexFieldName] ?? null;
                if ($fieldValue !== null) {
                    $bestValue = $fieldValue;
                    $sourceDocId = $hit['objectID'] ?? null;
                    break;
                }
            }

            if ($bestValue === null) {
                $sourceDocId = $sampleDocs[0]['objectID'] ?? null;
                $results[] = [
                    'indexFieldName' => $mapping->indexFieldName,
                    'indexFieldType' => $mapping->indexFieldType,
                    'entryId' => $sourceDocId,
                    'entryTitle' => 'Doc',
                    'value' => null,
                    'phpType' => 'null',
                    'status' => 'null',
                    'warning' => 'Field not present in ' . count($sampleDocs) . ' sampled documents.',
                ];
                continue;
            }

            $diagnostic = $this->diagnoseValue($bestValue, $mapping);

            $results[] = [
                'indexFieldName' => $mapping->indexFieldName,
                'indexFieldType' => $mapping->indexFieldType,
                'entryId' => $sourceDocId,
                'entryTitle' => 'Doc',
                'value' => $this->formatValue($bestValue),
                'phpType' => $this->getPhpType($bestValue),
                'status' => $diagnostic['status'],
                'warning' => $diagnostic['warning'],
            ];
        }

        // Add advisory rows for missing key roles
        foreach ([FieldMapping::ROLE_TITLE, FieldMapping::ROLE_URL] as $keyRole) {
            if (!in_array($keyRole, $assignedRoles, true)) {
                $results[] = [
                    'indexFieldName' => '—',
                    'indexFieldType' => '—',
                    'entryId' => null,
                    'entryTitle' => null,
                    'value' => null,
                    'phpType' => '—',
                    'status' => 'warning',
                    'warning' => "No field assigned the \"{$keyRole}\" role — Twig helpers like get" . ucfirst($keyRole) . '() will return null.',
                ];
            }
        }

        return [
            'success' => true,
            'readOnly' => true,
            'indexName' => $index->name,
            'indexHandle' => $index->handle,
            'entryTypeNames' => [],
            'results' => $results,
        ];
    }

    /**
     * Find an entry with non-empty data for the given field mapping.
     *
     * Uses Craft's :notempty: query param for custom fields, or simply returns
     * the first matching entry for attributes (which always have values).
     *
     * @param Index        $index
     * @param FieldMapping $mapping
     * @return Entry|null
     */
    public function findEntryWithData(Index $index, FieldMapping $mapping): ?Entry
    {
        $query = Entry::find()->status('live');

        if (!empty($index->sectionIds)) {
            $query->sectionId($index->sectionIds);
        }
        if (!empty($index->entryTypeIds)) {
            $query->typeId($index->entryTypeIds);
        }
        if ($index->siteId) {
            $query->siteId($index->siteId);
        }

        if ($mapping->isAttribute()) {
            /** @var Entry|null */
            return $query->limit(1)->one();
        }

        $field = $mapping->fieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;

        // For sub-fields, resolve with stale-UID fallback via parent Matrix handle
        if ($mapping->isSubField()) {
            $parentField = $mapping->parentFieldUid
                ? Craft::$app->getFields()->getFieldByUid($mapping->parentFieldUid)
                : null;

            // Try handle-based fallback if UID lookup failed or returned wrong field
            if ($parentField instanceof Matrix) {
                $parentHandle = $parentField->handle ?? '';
                $expectedHandle = $this->extractSubFieldHandle($mapping->indexFieldName, $parentHandle);
                if (!$field && $expectedHandle) {
                    $field = $this->findSubFieldByHandle($parentField, $expectedHandle);
                }
            }

            if (!$field) {
                /** @var Entry|null */
                return $query->limit(1)->one();
            }

            return $this->findEntryWithSubFieldData($query, $mapping, $field, $parentField);
        }

        if (!$field) {
            /** @var Entry|null */
            return $query->limit(1)->one();
        }

        if ($field instanceof Lightswitch) {
            /** @var Entry|null */
            return $query->limit(1)->one();
        }

        $fieldHandle = $field->handle ?? '';
        $query->{$fieldHandle}(':notempty:');
        /** @var Entry|null */
        return $query->limit(1)->one();
    }

    /**
     * Find an entry where a specific Matrix sub-field has actual data.
     *
     * Iterates through candidates and inspects block data to find an entry
     * where the target sub-field is populated (handles assets, lightswitches, etc.).
     * Falls back to handle derived from indexFieldName when UID-resolved handle doesn't match.
     *
     * @param ElementQuery<int, Entry> $query       Base query scoped to the index.
     * @param FieldMapping             $mapping     The sub-field mapping.
     * @param FieldInterface           $subField    The sub-field instance.
     * @param FieldInterface|null      $parentField The parent Matrix field (optional, looked up if null).
     * @return Entry|null
     */
    public function findEntryWithSubFieldData(ElementQuery $query, FieldMapping $mapping, FieldInterface $subField, ?FieldInterface $parentField = null): ?Entry
    {
        if (!$parentField) {
            $parentField = $mapping->parentFieldUid
                ? Craft::$app->getFields()->getFieldByUid($mapping->parentFieldUid)
                : null;
        }

        if (!$parentField) {
            /** @var Entry|null */
            return $query->limit(1)->one();
        }

        // Derive the expected sub-field handle from indexFieldName for stale-UID fallback
        $parentHandle = $parentField->handle ?? '';
        $expectedHandle = $this->extractSubFieldHandle($mapping->indexFieldName, $parentHandle);

        $query->{$parentHandle}(':notempty:');
        /** @var Entry[] $candidates */
        $candidates = $query->limit(20)->all();

        foreach ($candidates as $candidate) {
            $matrixQuery = $candidate->getFieldValue($parentHandle);
            if ($matrixQuery === null) {
                continue;
            }

            foreach ($matrixQuery->all() as $block) {
                $fieldLayout = $block->getFieldLayout();
                if ($fieldLayout === null) {
                    continue;
                }

                // Find the matching field in this block — try UID-resolved handle first,
                // then fall back to the handle derived from indexFieldName
                $matchedHandle = null;
                foreach ($fieldLayout->getCustomFields() as $blockField) {
                    if ($blockField->handle === $subField->handle) {
                        $matchedHandle = $subField->handle;
                        break;
                    }
                }
                if (!$matchedHandle && $expectedHandle && $expectedHandle !== $subField->handle) {
                    foreach ($fieldLayout->getCustomFields() as $blockField) {
                        if ($blockField->handle === $expectedHandle) {
                            $matchedHandle = $expectedHandle;
                            break;
                        }
                    }
                }

                if (!$matchedHandle) {
                    continue;
                }

                $value = $block->getFieldValue($matchedHandle);

                if (is_bool($value)) {
                    return $candidate;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                if ($value instanceof ElementQuery) {
                    if ($value->count() > 0) {
                        return $candidate;
                    }
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extract the expected sub-field handle from an indexFieldName like "parentHandle_subHandle".
     *
     * @param string $indexFieldName
     * @param string $parentHandle
     * @return string|null The sub-field handle, or null if the format doesn't match.
     */
    public function extractSubFieldHandle(string $indexFieldName, string $parentHandle): ?string
    {
        $prefix = $parentHandle . '_';
        if (!str_starts_with($indexFieldName, $prefix)) {
            return null;
        }

        $handle = substr($indexFieldName, strlen($prefix));
        return $handle !== '' ? $handle : null;
    }

    /**
     * Find a sub-field by handle within a Matrix field's entry type layouts.
     *
     * @param FieldInterface $parentField
     * @param string         $handle
     * @return FieldInterface|null
     */
    public function findSubFieldByHandle(FieldInterface $parentField, string $handle): ?FieldInterface
    {
        if (!($parentField instanceof Matrix)) {
            return null;
        }

        foreach ($parentField->getEntryTypes() as $entryType) {
            foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                if ($field->handle === $handle) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Build a markdown table from validation results.
     *
     * Centralises the formatting shared by the console controller, CP Sprig
     * component, and Twig variable so all three surfaces produce identical output.
     *
     * @param array<string, mixed> $data        The validation result array (from `validateIndex()`).
     * @param string|null          $filterMode  'issues' to include only warnings/errors/nulls, null for all.
     * @param string               $titleSuffix Appended to the markdown title.
     * @return string Markdown string.
     */
    public function buildValidationMarkdown(array $data, ?string $filterMode = null, string $titleSuffix = ''): string
    {
        $lines = [];
        $lines[] = "# Field Mapping Validation: {$data['indexName']} (`{$data['indexHandle']}`){$titleSuffix}";
        $lines[] = '';

        $isReadOnly = $data['readOnly'] ?? false;

        if ($isReadOnly) {
            $lines[] = '> **Read-only index** — values sampled from the search engine index.';
        } else {
            $lines[] = '> **Synced index** — values resolved from Craft entries using field mappings.';
        }

        if (!empty($data['entryTypeNames'])) {
            $lines[] = '';
            $lines[] = '**Entry types:** ' . implode(', ', $data['entryTypeNames']);
        }

        $sourceColumn = $isReadOnly ? 'Source Document' : 'Source Entry';

        $lines[] = '';
        $lines[] = "| Index Field | Index Type | {$sourceColumn} | PHP Type | Value | Status |";
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($data['results'] as $field) {
            if ($filterMode === 'issues' && $field['status'] === 'ok') {
                continue;
            }

            $entry = $field['entryId'] ? "{$field['entryTitle']} (#{$field['entryId']})" : '_no data_';
            $entry = str_replace('|', '\\|', $entry);

            if ($field['value'] === null) {
                $value = '_null_';
            } elseif (is_array($field['value']) || is_object($field['value'])) {
                $value = '`' . json_encode($field['value']) . '`';
            } else {
                $value = (string)$field['value'];
                if (mb_strlen($value) > 60) {
                    $value = mb_substr($value, 0, 60) . '...';
                }
            }
            $value = str_replace('|', '\\|', $value);

            $status = match ($field['status']) {
                'ok' => 'OK',
                'error' => 'ERROR',
                'null' => '--',
                default => 'WARN',
            };

            if (!empty($field['warning'])) {
                $status .= ' ' . str_replace('|', '\\|', $field['warning']);
            }

            $lines[] = "| `{$field['indexFieldName']}` | {$field['indexFieldType']} | {$entry} | `{$field['phpType']}` | {$value} | {$status} |";
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Diagnose whether a resolved value matches the expected index field type.
     *
     * @param mixed        $value
     * @param FieldMapping $mapping
     * @return array{status: string, warning: string|null}
     */
    public function diagnoseValue(mixed $value, FieldMapping $mapping): array
    {
        if ($value === null) {
            return ['status' => 'null', 'warning' => 'Resolved to null — field may be empty or resolver may not support this field.'];
        }

        $type = $mapping->indexFieldType;

        return match ($type) {
            FieldMapping::TYPE_TEXT, FieldMapping::TYPE_KEYWORD => $this->checkStringLike($value),
            FieldMapping::TYPE_INTEGER => $this->checkInteger($value),
            FieldMapping::TYPE_FLOAT => $this->checkFloat($value),
            FieldMapping::TYPE_BOOLEAN => $this->checkBoolean($value),
            FieldMapping::TYPE_DATE => $this->checkDate($value),
            FieldMapping::TYPE_FACET => $this->checkFacet($value),
            default => ['status' => 'ok', 'warning' => null],
        };
    }

    /**
     * Format a value for JSON display, handling objects and long strings.
     *
     * @param mixed $value
     * @return mixed
     */
    public function formatValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return '(object: ' . get_class($value) . ')';
        }

        if (is_string($value) && mb_strlen($value) > 200) {
            return mb_substr($value, 0, 200) . '…';
        }

        return $value;
    }

    /**
     * Get a human-readable PHP type for a value.
     *
     * @param mixed $value
     * @return string
     */
    public function getPhpType(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        return gettype($value);
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkStringLike(mixed $value): array
    {
        if (is_string($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_array($value)) {
            return ['status' => 'warning', 'warning' => 'Array value for text/keyword field — consider using facet type or changing resolver format.'];
        }
        if (is_object($value)) {
            return ['status' => 'error', 'warning' => 'Object value (' . get_class($value) . ') — resolver returned an unserializable object.'];
        }
        return ['status' => 'ok', 'warning' => null];
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkInteger(mixed $value): array
    {
        if (is_int($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_float($value)) {
            return ['status' => 'warning', 'warning' => 'Float value for integer field — value may be truncated.'];
        }
        if (is_object($value)) {
            return ['status' => 'error', 'warning' => 'Object value (' . get_class($value) . ') — resolver needs to extract a numeric value.'];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for integer field.'];
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkFloat(mixed $value): array
    {
        if (is_float($value) || is_int($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_object($value)) {
            return ['status' => 'error', 'warning' => 'Object value (' . get_class($value) . ') — resolver needs to extract a numeric value.'];
        }
        if (is_string($value) && is_numeric($value)) {
            return ['status' => 'warning', 'warning' => 'Numeric string for float field — should be cast to float.'];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for float field.'];
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkBoolean(mixed $value): array
    {
        if (is_bool($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        return ['status' => 'warning', 'warning' => 'Non-boolean value (' . gettype($value) . ') for boolean field.'];
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkDate(mixed $value): array
    {
        if (is_string($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return ['status' => 'ok', 'warning' => null];
        }
        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            return ['status' => 'warning', 'warning' => 'DateTime object — should be formatted as ISO-8601 string.'];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for date field.'];
    }

    /**
     * @return array{status: string, warning: string|null}
     */
    private function checkFacet(mixed $value): array
    {
        if (is_array($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_string($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for facet field.'];
    }
}
