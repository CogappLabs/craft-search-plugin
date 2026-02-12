<?php

namespace cogapp\searchindex\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $indexId
 * @property string|null $fieldUid
 * @property string|null $parentFieldUid
 * @property string|null $attribute
 * @property string $indexFieldName
 * @property string $indexFieldType
 * @property bool $enabled
 * @property int $weight
 * @property array|null $resolverConfig
 * @property int $sortOrder
 * @property string $uid
 */
class FieldMappingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%searchindex_field_mappings}}';
    }

    public function getIndex(): ActiveQueryInterface
    {
        return $this->hasOne(IndexRecord::class, ['id' => 'indexId']);
    }
}
