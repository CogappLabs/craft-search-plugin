<?php

/**
 * Search Index plugin for Craft CMS -- Add database index on handle column.
 */

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Adds a database index on the `handle` column of the indexes table for faster lookups.
 *
 * @author cogapp
 * @since 1.0.0
 */
class m260214_000000_add_handle_index extends Migration
{
    /**
     * @return bool
     */
    public function safeUp(): bool
    {
        $tableName = '{{%searchindex_indexes}}';
        $indexName = 'idx_searchindex_indexes_handle';

        // Guard against duplicate index (Install.php may have already created it)
        try {
            $this->createIndex($indexName, $tableName, 'handle', true);
        } catch (\Throwable) {
            // Index already exists — nothing to do
        }

        return true;
    }

    /**
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropIndex('idx_searchindex_indexes_handle', '{{%searchindex_indexes}}');

        return true;
    }
}
