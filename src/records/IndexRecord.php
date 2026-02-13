<?php

/**
 * ActiveRecord for the search index indexes database table.
 */

namespace cogapp\searchindex\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Represents a row in the searchindex_indexes table.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $engineType
 * @property array|null $engineConfig
 * @property array|null $sectionIds
 * @property array|null $entryTypeIds
 * @property int|null $siteId
 * @property bool $enabled
 * @property string $mode
 * @property int $sortOrder
 * @property string $uid
 *
 * @author cogapp
 * @since 1.0.0
 */
class IndexRecord extends ActiveRecord
{
    /**
     * Returns the database table name for this record.
     *
     * @return string Table name with Craft table prefix placeholder
     */
    public static function tableName(): string
    {
        return '{{%searchindex_indexes}}';
    }

    /**
     * Returns the related field mapping records, ordered by sort order.
     *
     * @return ActiveQueryInterface Has-many relation to FieldMappingRecord
     */
    public function getFieldMappings(): ActiveQueryInterface
    {
        return $this->hasMany(FieldMappingRecord::class, ['indexId' => 'id'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }
}
