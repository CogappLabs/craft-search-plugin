<?php

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%searchindex_indexes}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull()->unique(),
            'engineType' => $this->string(255)->notNull(),
            'engineConfig' => $this->json(),
            'sectionIds' => $this->json(),
            'entryTypeIds' => $this->json(),
            'siteId' => $this->integer(),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            'sortOrder' => $this->smallInteger()->defaultValue(0)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%searchindex_field_mappings}}', [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'fieldUid' => $this->string(36),
            'parentFieldUid' => $this->string(36),
            'attribute' => $this->string(255),
            'indexFieldName' => $this->string(255)->notNull(),
            'indexFieldType' => $this->string(50)->notNull()->defaultValue('text'),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            'weight' => $this->integer()->defaultValue(5)->notNull(),
            'resolverConfig' => $this->json(),
            'sortOrder' => $this->smallInteger()->defaultValue(0)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%searchindex_field_mappings}}',
            'indexId',
            '{{%searchindex_indexes}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%searchindex_indexes}}',
            'siteId',
            '{{%sites}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%searchindex_field_mappings}}');
        $this->dropTableIfExists('{{%searchindex_indexes}}');

        return true;
    }
}
