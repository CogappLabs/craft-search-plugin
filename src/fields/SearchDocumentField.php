<?php

/**
 * Search Index plugin for Craft CMS -- SearchDocumentField.
 */

namespace cogapp\searchindex\fields;

use cogapp\searchindex\gql\types\SearchDocumentFieldType;
use cogapp\searchindex\SearchIndex;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use yii\db\Schema;

/**
 * Custom field type that lets editors pick a document from a search index.
 *
 * Stores the index handle and document ID, and provides lazy document
 * retrieval in Twig via the SearchDocumentValue value object.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchDocumentField extends Field
{
    /**
     * The search index handle to search within.
     *
     * @var string
     */
    public string $indexHandle = '';

    /**
     * Number of results per search request in the field input.
     *
     * @var int
     */
    public int $perPage = 10;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Search Document';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    /**
     * @inheritdoc
     */
    public static function dbType(): array
    {
        return [
            'indexHandle' => Schema::TYPE_STRING,
            'documentId' => Schema::TYPE_STRING,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        $indexes = SearchIndex::$plugin->getIndexes()->getAllIndexes();
        $indexOptions = [['label' => 'Select an index…', 'value' => '']];

        foreach ($indexes as $index) {
            $indexOptions[] = [
                'label' => $index->name . ' (' . $index->handle . ')',
                'value' => $index->handle,
            ];
        }

        return Craft::$app->getView()->renderTemplate('search-index/_field/settings', [
            'field' => $this,
            'indexOptions' => $indexOptions,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SearchDocumentValue) {
            return $value;
        }

        if (is_array($value)) {
            $indexHandle = $value['indexHandle'] ?? '';
            $documentId = $value['documentId'] ?? '';

            if ($indexHandle && $documentId) {
                return new SearchDocumentValue($indexHandle, $documentId);
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SearchDocumentValue) {
            return [
                'indexHandle' => $value->indexHandle,
                'documentId' => $value->documentId,
            ];
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        // Use the field's configured indexHandle, or allow the value to carry one
        $indexHandle = $this->indexHandle;
        $documentId = '';

        if ($value instanceof SearchDocumentValue) {
            $documentId = $value->documentId;
            if (!$indexHandle) {
                $indexHandle = $value->indexHandle;
            }
        }

        return Craft::$app->getView()->renderTemplate('search-index/_field/input', [
            'field' => $this,
            'namePrefix' => $this->handle,
            'indexHandle' => $indexHandle,
            'documentId' => $documentId,
            'perPage' => $this->perPage,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType(): array|\GraphQL\Type\Definition\Type
    {
        return SearchDocumentFieldType::getType();
    }
}
