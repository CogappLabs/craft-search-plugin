<?php

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

class m250213_000000_add_parent_field_uid extends Migration
{
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

    public function safeDown(): bool
    {
        $this->dropColumn('{{%searchindex_field_mappings}}', 'parentFieldUid');

        return true;
    }
}
