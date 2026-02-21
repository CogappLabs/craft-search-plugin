<?php

/**
 * ActiveRecord for the engine overrides database table.
 */

namespace cogapp\searchindex\records;

use craft\db\ActiveRecord;

/**
 * Represents a row in the searchindex_engine_overrides table.
 *
 * Single-row table storing a JSON blob of engine credential overrides
 * that bypass project config (allowAdminChanges).
 *
 * @property int $id
 * @property string|array|null $settings
 * @property string $uid
 *
 * @author cogapp
 * @since 1.0.0
 */
class EngineOverrideRecord extends ActiveRecord
{
    /**
     * Returns the database table name for this record.
     *
     * @return string Table name with Craft table prefix placeholder
     */
    public static function tableName(): string
    {
        return '{{%searchindex_engine_overrides}}';
    }
}
