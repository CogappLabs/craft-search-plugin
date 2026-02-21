<?php

/**
 * ActiveRecord for the search index field mappings database table.
 */

namespace cogapp\searchindex\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Represents a row in the searchindex_field_mappings table.
 *
 * @property int $id
 * @property int $indexId
 * @property string|null $fieldUid
 * @property string|null $parentFieldUid
 * @property string|null $attribute
 * @property string $indexFieldName
 * @property string $indexFieldType
 * @property string|null $role
 * @property bool $enabled
 * @property int $weight
 * @property string|array|null $resolverConfig
 * @property int $sortOrder
 * @property string $uid
 *
 * @author cogapp
 * @since 1.0.0
 */
class FieldMappingRecord extends ActiveRecord
{
    /**
     * Returns the database table name for this record.
     *
     * @return string Table name with Craft table prefix placeholder
     */
    public static function tableName(): string
    {
        return '{{%searchindex_field_mappings}}';
    }

    /**
     * Returns the related index record that owns this field mapping.
     *
     * @return ActiveQueryInterface Has-one relation to IndexRecord
     */
    public function getIndex(): ActiveQueryInterface
    {
        return $this->hasOne(IndexRecord::class, ['id' => 'indexId']);
    }
}
