<?php

/**
 * Search Index plugin for Craft CMS -- FieldMappingsController.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use Craft;
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
}
