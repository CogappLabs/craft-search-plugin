<?php

/**
 * Search Index plugin for Craft CMS -- Add engine overrides table.
 */

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Creates the engine_overrides table for storing environment-specific engine
 * credentials that bypass project config (allowAdminChanges).
 *
 * @author cogapp
 * @since 1.0.0
 */
class m260218_000000_add_engine_overrides extends Migration
{
    /**
     * @return bool
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%searchindex_engine_overrides}}')) {
            $this->createTable('{{%searchindex_engine_overrides}}', [
                'id' => $this->primaryKey(),
                'settings' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%searchindex_engine_overrides}}');

        return true;
    }
}
