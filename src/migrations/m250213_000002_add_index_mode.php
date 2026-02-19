<?php

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Adds the `mode` column to the searchindex_indexes table.
 */
class m250213_000002_add_index_mode extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(
            '{{%searchindex_indexes}}',
            'mode',
            $this->string(20)->notNull()->defaultValue('synced')->after('enabled')
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%searchindex_indexes}}', 'mode');

        return true;
    }
}
