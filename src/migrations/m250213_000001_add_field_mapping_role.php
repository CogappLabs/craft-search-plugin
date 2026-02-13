<?php

/**
 * Search Index plugin for Craft CMS -- Add role column to field mappings.
 */

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Adds a `role` column to the field_mappings table for semantic field roles.
 *
 * @author cogapp
 * @since 1.0.0
 */
class m250213_000001_add_field_mapping_role extends Migration
{
    /**
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->addColumn(
            '{{%searchindex_field_mappings}}',
            'role',
            $this->string(20)->null()->after('indexFieldType')
        );

        return true;
    }

    /**
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%searchindex_field_mappings}}', 'role');

        return true;
    }
}
