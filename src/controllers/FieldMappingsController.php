<?php

/**
 * Search Index plugin for Craft CMS -- FieldMappingsController.
 */

namespace cogapp\searchindex\controllers;

use cogapp\searchindex\models\FieldMapping;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
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
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('searchIndex-manageIndexes');

        return true;
    }

    /**
     * Display the field mappings editor for a specific index.
     *
     * For synced indexes: full field mapping editor with attributes, types, weights, etc.
     * For read-only indexes: simplified view showing schema fields with role assignment.
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

        if ($index->isReadOnly()) {
            return $this->_editReadOnly($index);
        }

        return $this->_editSynced($index);
    }

    /**
     * Render the full field mappings editor for a synced index.
     */
    private function _editSynced(\cogapp\searchindex\models\Index $index): Response
    {
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

            // Synthetic geo_point mappings: show both source fields in the label
            $fieldName = $field ? $field->name : '(Unknown field)';
            if ($field && $mapping->indexFieldType === FieldMapping::TYPE_GEO_POINT && !empty($mapping->resolverConfig['lngFieldHandle'])) {
                $lngHandle = $mapping->resolverConfig['lngFieldHandle'];
                $allFields = Craft::$app->getFields()->getAllFields();
                foreach ($allFields as $f) {
                    if ($f->handle === $lngHandle) {
                        $fieldName = $field->name . ' + ' . $f->name;
                        break;
                    }
                }
            }

            $allFieldItems[] = [
                'mapping' => $mapping,
                'field' => $field,
                'fieldName' => $fieldName,
                'fieldType' => $field ? (new \ReflectionClass($field))->getShortName() : 'Unknown',
                'searchable' => $field ? $field->searchable : false,
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
                    'searchable' => $item['searchable'],
                    'isMatrixParent' => true,
                    'subFields' => $subFieldsByParent[$fieldUid],
                ];
            } else {
                $item['isMatrixParent'] = false;
                $item['subFields'] = [];
                $fieldMappings[] = $item;
            }
        }

        $response = $this->asCpScreen()
            ->title(Craft::t('search-index', 'Field Mappings: {name}', ['name' => $index->name]))
            ->selectedSubnavItem('indexes')
            ->addCrumb(Craft::t('search-index', 'Search Indexes'), 'search-index/indexes')
            ->addCrumb($index->name, "search-index/indexes/{$index->id}")
            ->action('search-index/field-mappings/save')
            ->redirectUrl("search-index/indexes/{$index->id}/fields")
            ->submitButtonLabel(Craft::t('search-index', 'Save Mappings'))
            ->addAltAction(Craft::t('search-index', 'Save and continue editing'), [
                'redirect' => "search-index/indexes/{$index->id}/fields",
                'shortcut' => true,
                'retainScroll' => true,
            ])
            ->addAltAction(Craft::t('search-index', 'Re-detect Fields'), [
                'action' => 'search-index/field-mappings/redetect',
                'confirm' => Craft::t('search-index', 'This will re-detect fields from entry types. Existing settings (roles, weights, types) will be preserved. Continue?'),
                'redirect' => "search-index/indexes/{$index->id}/fields",
            ])
            ->addAltAction(Craft::t('search-index', 'Re-detect Fields (Fresh)'), [
                'action' => 'search-index/field-mappings/redetect-fresh',
                'confirm' => Craft::t('search-index', 'This will reset all field mappings to defaults, discarding any customisations. Continue?'),
                'redirect' => "search-index/indexes/{$index->id}/fields",
                'destructive' => true,
            ])
            ->contentTemplate('search-index/indexes/_fields', [
                'index' => $index,
                'attributeMappings' => $attributeMappings,
                'fieldMappings' => $fieldMappings,
                'fieldTypes' => array_combine(FieldMapping::FIELD_TYPES, FieldMapping::FIELD_TYPES),
                'roleOptions' => array_merge(
                    ['' => "\u{2014}"],
                    array_combine(FieldMapping::ROLES, array_map('ucfirst', FieldMapping::ROLES)),
                ),
            ]);

        return $response;
    }

    /**
     * Render the simplified field roles editor for a read-only index.
     */
    private function _editReadOnly(\cogapp\searchindex\models\Index $index): Response
    {
        $mappings = $index->getFieldMappings();

        $response = $this->asCpScreen()
            ->title(Craft::t('search-index', 'Field Roles: {name}', ['name' => $index->name]))
            ->selectedSubnavItem('indexes')
            ->addCrumb(Craft::t('search-index', 'Search Indexes'), 'search-index/indexes')
            ->addCrumb($index->name, "search-index/indexes/{$index->id}")
            ->action('search-index/field-mappings/save')
            ->redirectUrl("search-index/indexes/{$index->id}/fields")
            ->submitButtonLabel(Craft::t('search-index', 'Save Roles'))
            ->addAltAction(Craft::t('search-index', 'Save and continue editing'), [
                'redirect' => "search-index/indexes/{$index->id}/fields",
                'shortcut' => true,
                'retainScroll' => true,
            ])
            ->addAltAction(Craft::t('search-index', 'Refresh from Schema'), [
                'action' => 'search-index/field-mappings/refresh-schema',
                'confirm' => Craft::t('search-index', 'This will refresh fields from the engine schema. Existing role assignments will be preserved. Continue?'),
                'redirect' => "search-index/indexes/{$index->id}/fields",
            ])
            ->addAltAction(Craft::t('search-index', 'Refresh from Schema (Fresh)'), [
                'action' => 'search-index/field-mappings/refresh-schema-fresh',
                'confirm' => Craft::t('search-index', 'This will reset all field roles from the engine schema, discarding any customisations. Continue?'),
                'redirect' => "search-index/indexes/{$index->id}/fields",
                'destructive' => true,
            ])
            ->contentTemplate('search-index/indexes/_fields_readonly', [
                'index' => $index,
                'mappings' => $mappings,
                'fieldTypes' => array_combine(FieldMapping::FIELD_TYPES, FieldMapping::FIELD_TYPES),
                'roleOptions' => array_merge(
                    ['' => "\u{2014}"],
                    array_combine(FieldMapping::ROLES, array_map('ucfirst', FieldMapping::ROLES)),
                ),
            ]);

        return $response;
    }

    /**
     * Save field mappings from POST data for an index.
     *
     * @return Response|null Null when save fails and the form is re-rendered.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

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
            $mapping->role = $data['role'] ?: null;
            $mapping->enabled = (bool)($data['enabled'] ?? false);
            $mapping->weight = (int)($data['weight'] ?? 5);
            $resolverConfig = $data['resolverConfig'] ?? null;
            if (is_string($resolverConfig) && $resolverConfig !== '') {
                $resolverConfig = json_decode($resolverConfig, true);
            }
            $mapping->resolverConfig = !empty($resolverConfig) ? $resolverConfig : null;
            $mapping->sortOrder = (int)($data['sortOrder'] ?? 0);

            // Role-mapped fields must always be enabled.
            if ($mapping->role) {
                $mapping->enabled = true;
            }

            $mappings[] = $mapping;
        }

        $duplicateRoleError = $this->validateUniqueRoles($mappings);
        if ($duplicateRoleError !== null) {
            Craft::$app->getSession()->setError($duplicateRoleError);
            $index->setFieldMappings($mappings);
            Craft::$app->getUrlManager()->setRouteParams([
                'index' => $index,
            ]);
            return null;
        }

        $index->setFieldMappings($mappings);

        if (!SearchIndex::$plugin->getIndexes()->saveIndex($index, false)) {
            Craft::$app->getSession()->setError('Couldn\'t save field mappings.');
            Craft::$app->getUrlManager()->setRouteParams(['index' => $index]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Field mappings saved.');

        return $this->redirectToPostedUrl($index);
    }

    /**
     * Ensure semantic roles are only assigned once per index.
     *
     * @param FieldMapping[] $mappings
     */
    private function validateUniqueRoles(array $mappings): ?string
    {
        $roleToField = [];
        foreach ($mappings as $mapping) {
            if (!$mapping->enabled || !$mapping->role) {
                continue;
            }

            $fieldLabel = $mapping->indexFieldName ?: ($mapping->attribute ?: 'unknown');
            if (isset($roleToField[$mapping->role])) {
                return Craft::t(
                    'search-index',
                    'Role "{role}" can only be assigned to one field. It is currently set on "{first}" and "{second}".',
                    [
                        'role' => $mapping->role,
                        'first' => $roleToField[$mapping->role],
                        'second' => $fieldLabel,
                    ]
                );
            }

            $roleToField[$mapping->role] = $fieldLabel;
        }

        return null;
    }

    /**
     * Re-detect field mappings for an index, preserving user customizations.
     *
     * Uses merge-based re-detection: refreshes field UIDs while keeping
     * existing enabled/disabled, roles, weights, and type settings.
     *
     * @return Response
     */
    public function actionRedetect(): Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = SearchIndex::$plugin->getFieldMapper()->redetectFieldMappings($index);
        $index->setFieldMappings($mappings);
        SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

        Craft::$app->getSession()->setNotice('Field mappings re-detected.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Re-detect field mappings from scratch, discarding all user customizations.
     *
     * @return Response
     */
    public function actionRedetectFresh(): Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = SearchIndex::$plugin->getFieldMapper()->detectFieldMappings($index);
        $index->setFieldMappings($mappings);
        SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

        Craft::$app->getSession()->setNotice('Field mappings reset to defaults.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Refresh field mappings for a read-only index from the engine schema, preserving roles.
     *
     * @return Response
     */
    public function actionRefreshSchema(): Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = SearchIndex::$plugin->getFieldMapper()->redetectSchemaFieldMappings($index);
        $index->setFieldMappings($mappings);
        SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

        Craft::$app->getSession()->setNotice('Fields refreshed from engine schema.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Refresh field mappings for a read-only index from scratch, discarding role assignments.
     *
     * @return Response
     */
    public function actionRefreshSchemaFresh(): Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are not allowed on this environment.');
        }

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');
        $index = SearchIndex::$plugin->getIndexes()->getIndexById($indexId);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        $mappings = SearchIndex::$plugin->getFieldMapper()->detectSchemaFieldMappings($index);
        $index->setFieldMappings($mappings);
        SearchIndex::$plugin->getIndexes()->saveIndex($index, false);

        Craft::$app->getSession()->setNotice('Fields reset from engine schema.');

        return $this->redirect("search-index/indexes/{$indexId}/fields");
    }

    /**
     * Validate field mappings by finding entries with data for each field and reporting results.
     *
     * Delegates to the FieldMappingValidator service for the heavy lifting.
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

        $result = SearchIndex::$plugin->getFieldMappingValidator()->validateIndex($index);

        if (!$result['success']) {
            return $this->asJson(['success' => false, 'message' => $result['message'] ?? 'Validation failed.']);
        }

        return $this->asJson($result);
    }
}
