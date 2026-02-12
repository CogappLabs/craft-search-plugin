<?php

namespace cogapp\searchindex\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $engineType
 * @property array|null $engineConfig
 * @property array|null $sectionIds
 * @property array|null $entryTypeIds
 * @property int|null $siteId
 * @property bool $enabled
 * @property int $sortOrder
 * @property string $uid
 */
class IndexRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%searchindex_indexes}}';
    }

    public function getFieldMappings(): ActiveQueryInterface
    {
        return $this->hasMany(FieldMappingRecord::class, ['indexId' => 'id'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }
}
