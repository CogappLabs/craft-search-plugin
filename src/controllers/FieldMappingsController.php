<?php

/**
 * Search Index plugin for Craft CMS -- FieldMappingsController.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\models\Index;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\fields\Lightswitch;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP controller for managing field mappings on a per-index basis.
 *
 * @author cogapp
 * @since 1.0.0
 */
class FieldMappingsController extends Controller
{
    /**
     * Display the field mappings editor for a specific index.
     *
     * @param int $indexId
     * @return Response
     */
    public function actionEdit(int $indexId): Response
    {
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = $index->getFieldMappings();

        // Separate attributes, regular fields, and Matrix sub-fields
        $attributeMappings = [];
        $fieldMappings = [];

        // First pass: collect all mappings into a flat list with metadata
        $allFieldItems = [];
        foreach ($mappings as $mapping) {
            if ($mapping->isAttribute()) {
                $attributeMappings[] = $mapping;
                continue;
            }

            $field = $mapping->fieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;
            $allFieldItems[] = [
                'mapping' => $mapping,
                'field' => $field,
                'fieldName' => $field ? $field->name : '(Unknown field)',
                'fieldType' => $field ? (new \ReflectionClass($field))->getShortName() : 'Unknown',
            ];
        }

        // Second pass: build nested structure for Matrix parents with sub-fields
        $subFieldsByParent = [];
        foreach ($allFieldItems as $item) {
            if ($item['mapping']->isSubField()) {
                $parentUid = $item['mapping']->parentFieldUid;
                $subFieldsByParent[$parentUid][] = $item;
            }
        }

        foreach ($allFieldItems as $item) {
            if ($item['mapping']->isSubField()) {
                continue; // handled as children below
            }

            $fieldUid = $item['mapping']->fieldUid;
            if ($fieldUid && isset($subFieldsByParent[$fieldUid])) {
                // Matrix parent with sub-fields
                $fieldMappings[] = [
                    'mapping' => $item['mapping'],
                    'field' => $item['field'],
                    'fieldName' => $item['fieldName'],
                    'fieldType' => $item['fieldType'],
                    'isMatrixParent' => true,
                    'subFields' => $subFieldsByParent[$fieldUid],
                ];
            } else {
                $item['isMatrixParent'] = false;
                $item['subFields'] = [];
                $fieldMappings[] = $item;
            }
        }

        return $this->renderTemplate('search-index/indexes/fields', [
            'index' => $index,
            'attributeMappings' => $attributeMappings,
            'fieldMappings' => $fieldMappings,
            'fieldTypes' => array_combine(FieldMapping::FIELD_TYPES, FieldMapping::FIELD_TYPES),
        ]);
    }

    /**
     * Save field mappings from POST data for an index.
     *
     * @return Response|null Null when save fails and the form is re-rendered.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $indexId = $request->getRequiredBodyParam('indexId');

        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);
        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappingsData = $request->getBodyParam('mappings', []);
        $mappings = [];

        foreach ($mappingsData as $uid => $data) {
            $mapping = new FieldMapping();
            $mapping->uid = $uid;
            $mapping->indexId = $indexId;
            $mapping->fieldUid = $data['fieldUid'] ?? null;
            $mapping->parentFieldUid = $data['parentFieldUid'] ?? null;
            $mapping->attribute = $data['attribute'] ?? null;
            $mapping->indexFieldName = $data['indexFieldName'] ?? '';
            $mapping->indexFieldType = $data['indexFieldType'] ?? FieldMapping::TYPE_TEXT;
            $mapping->enabled = (bool)($data['enabled'] ?? false);
            $mapping->weight = (int)($data['weight'] ?? 5);
            $mapping->resolverConfig = !empty($data['resolverConfig']) ? $data['resolverConfig'] : null;
            $mapping->sortOrder = (int)($data['sortOrder'] ?? 0);
            $mappings[] = $mapping;
        }

        $index->setFieldMappings($mappings);

        if (!SearchIndex::$plugin->getIndexes()->saveIndex($index, false)) {
            Craft::$app->getSession()->setError('Couldn\'t save field mappings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Field mappings saved.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Re-detect field mappings for an index, replacing any existing mappings.
     *
     * @return Response
     */
    public function actionRedetect(): Response
    {
        $this->requirePostRequest();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = SearchIndex::$plugin->getFieldMapper()->detectFieldMappings($index);
        $index->setFieldMappings($mappings);
        SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

        Craft::$app->getSession()->setNotice('Field mappings re-detected.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Validate field mappings by finding entries with data for each field and reporting results.
     *
     * For each enabled field mapping, finds an entry where that field has a non-null value
     * (via Craft's :notempty: query param) and resolves it through the field mapper.
     *
     * @return Response JSON response with per-field resolved data and diagnostics.
     */
    public function actionValidate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            return $this->asJson(['success' => false, 'message' => 'Index not found.']);
        }

        $mappings = $index->getFieldMappings();
        $enabledMappings = array_filter($mappings, fn(FieldMapping $m) => $m->enabled);

        if (empty($enabledMappings)) {
            return $this->asJson(['success' => false, 'message' => 'No enabled field mappings to validate.']);
        }

        // Collect entry type names for the report
        $entryTypeNames = [];
        foreach ($index->entryTypeIds ?? [] as $etId) {
            $entryType = Craft::$app->getEntries()->getEntryTypeById($etId);
            if ($entryType) {
                $entryTypeNames[] = $entryType->name;
            }
        }

        $fieldMapper = SearchIndex::$plugin->getFieldMapper();
        $documentCache = []; // entryId => resolved document
        $results = [];

        foreach ($enabledMappings as $mapping) {
            $entry = $this->_findEntryWithData($index, $mapping);

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

            // Cache resolved documents so we don't re-resolve the same entry
            if (!isset($documentCache[$entry->id])) {
                $documentCache[$entry->id] = $fieldMapper->resolveElement($entry, $index);
            }

            $document = $documentCache[$entry->id];
            $value = $document[$mapping->indexFieldName] ?? null;
            $diagnostic = $this->_diagnoseValue($value, $mapping);

            $results[] = [
                'indexFieldName' => $mapping->indexFieldName,
                'indexFieldType' => $mapping->indexFieldType,
                'entryId' => $entry->id,
                'entryTitle' => $entry->title,
                'value' => $this->_formatValue($value),
                'phpType' => $this->_getPhpType($value),
                'status' => $diagnostic['status'],
                'warning' => $diagnostic['warning'],
            ];
        }

        return $this->asJson([
            'success' => true,
            'indexName' => $index->name,
            'indexHandle' => $index->handle,
            'entryTypeNames' => $entryTypeNames,
            'results' => $results,
        ]);
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
    private function _findEntryWithData(Index $index, FieldMapping $mapping): ?Entry
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

        // Attributes always have data — just return any entry
        if ($mapping->isAttribute()) {
            return $query->limit(1)->one();
        }

        $field = $mapping->fieldUid ? Craft::$app->getFields()->getFieldByUid($mapping->fieldUid) : null;
        if (!$field) {
            return $query->limit(1)->one();
        }

        // Matrix sub-fields: deep lookup through actual block data
        if ($mapping->isSubField()) {
            return $this->_findEntryWithSubFieldData($query, $mapping, $field);
        }

        // Lightswitch: Craft treats false as empty for :notempty:, so skip the filter
        if ($field instanceof Lightswitch) {
            return $query->limit(1)->one();
        }

        $query->{$field->handle}(':notempty:');
        return $query->limit(1)->one();
    }

    /**
     * Find an entry where a specific Matrix sub-field has actual data.
     *
     * Instead of just checking that the parent Matrix has blocks, this iterates
     * through candidates and inspects the block data to find an entry where the
     * target sub-field is populated (handles assets, lightswitches, etc.).
     *
     * @param \craft\elements\db\EntryQuery $query Base query scoped to the index.
     * @param FieldMapping $mapping The sub-field mapping.
     * @param \craft\base\FieldInterface $subField The sub-field instance.
     * @return Entry|null
     */
    private function _findEntryWithSubFieldData($query, FieldMapping $mapping, $subField): ?Entry
    {
        $parentField = $mapping->parentFieldUid
            ? Craft::$app->getFields()->getFieldByUid($mapping->parentFieldUid)
            : null;

        if (!$parentField) {
            return $query->limit(1)->one();
        }

        // Find entries where parent Matrix has blocks
        $query->{$parentField->handle}(':notempty:');
        $candidates = $query->limit(10)->all();

        // Check each candidate for actual sub-field data within its blocks
        foreach ($candidates as $candidate) {
            $matrixQuery = $candidate->getFieldValue($parentField->handle);
            if ($matrixQuery === null) {
                continue;
            }

            foreach ($matrixQuery->all() as $block) {
                $fieldLayout = $block->getFieldLayout();
                if ($fieldLayout === null) {
                    continue;
                }

                // Check if this block type has the sub-field
                $hasField = false;
                foreach ($fieldLayout->getCustomFields() as $blockField) {
                    if ($blockField->handle === $subField->handle) {
                        $hasField = true;
                        break;
                    }
                }
                if (!$hasField) {
                    continue;
                }

                $value = $block->getFieldValue($subField->handle);

                // Booleans (Lightswitch): any value counts as data
                if (is_bool($value)) {
                    return $candidate;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                // Relation/asset fields return ElementQuery — verify they have results
                if ($value instanceof ElementQuery) {
                    if ($value->count() > 0) {
                        return $candidate;
                    }
                    continue;
                }

                return $candidate;
            }
        }

        // Fall back to first candidate (has Matrix blocks but maybe not this sub-field)
        return $candidates[0] ?? null;
    }

    /**
     * Diagnose whether a resolved value matches the expected index field type.
     *
     * @param mixed        $value
     * @param FieldMapping $mapping
     * @return array{status: string, warning: string|null}
     */
    private function _diagnoseValue(mixed $value, FieldMapping $mapping): array
    {
        if ($value === null) {
            return ['status' => 'null', 'warning' => 'Resolved to null — field may be empty or resolver may not support this field.'];
        }

        $type = $mapping->indexFieldType;

        // Check type mismatches
        return match ($type) {
            FieldMapping::TYPE_TEXT, FieldMapping::TYPE_KEYWORD => $this->_checkStringLike($value),
            FieldMapping::TYPE_INTEGER => $this->_checkInteger($value),
            FieldMapping::TYPE_FLOAT => $this->_checkFloat($value),
            FieldMapping::TYPE_BOOLEAN => $this->_checkBoolean($value),
            FieldMapping::TYPE_DATE => $this->_checkDate($value),
            FieldMapping::TYPE_FACET => $this->_checkFacet($value),
            default => ['status' => 'ok', 'warning' => null],
        };
    }

    private function _checkStringLike(mixed $value): array
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

    private function _checkInteger(mixed $value): array
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

    private function _checkFloat(mixed $value): array
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

    private function _checkBoolean(mixed $value): array
    {
        if (is_bool($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        return ['status' => 'warning', 'warning' => 'Non-boolean value (' . gettype($value) . ') for boolean field.'];
    }

    private function _checkDate(mixed $value): array
    {
        if (is_string($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            return ['status' => 'warning', 'warning' => 'DateTime object — should be formatted as ISO-8601 string.'];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for date field.'];
    }

    private function _checkFacet(mixed $value): array
    {
        if (is_array($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        if (is_string($value)) {
            return ['status' => 'ok', 'warning' => null];
        }
        return ['status' => 'warning', 'warning' => 'Unexpected type ' . gettype($value) . ' for facet field.'];
    }

    /**
     * Format a value for JSON display, handling objects and long strings.
     *
     * @param mixed $value
     * @return mixed
     */
    private function _formatValue(mixed $value): mixed
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
    private function _getPhpType(mixed $value): string
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
}
