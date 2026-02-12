<?php

/**
 * Search Index plugin for Craft CMS -- Migration to add parentFieldUid column.
 */

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Adds the parentFieldUid column to the field_mappings table to support Matrix sub-field tracking.
 *
 * @author cogapp
 * @since 1.1.0
 */
class m250213_000000_add_parent_field_uid extends Migration
{
    /**
     * Add the parentFieldUid column and create an index on it.
     *
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->addColumn(
            '{{%searchindex_field_mappings}}',
            'parentFieldUid',
            $this->string(36)->null()->after('fieldUid')
        );

        $this->createIndex(
            null,
            '{{%searchindex_field_mappings}}',
            'parentFieldUid'
        );

        return true;
    }

    /**
     * Remove the parentFieldUid column.
     *
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%searchindex_field_mappings}}', 'parentFieldUid');

        return true;
    }
}
