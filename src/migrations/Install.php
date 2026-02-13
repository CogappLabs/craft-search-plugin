<?php

/**
 * Search Index plugin for Craft CMS -- Install migration.
 */

namespace cogapp\searchindex\migrations;

use craft\db\Migration;

/**
 * Creates the database tables required by the Search Index plugin.
 *
 * @author cogapp
 * @since 1.0.0
 */
class Install extends Migration
{
    /**
     * Create the indexes and field_mappings tables with foreign keys.
     *
     * @return bool
     */
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
            'mode' => $this->string(20)->notNull()->defaultValue('synced'),
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
            'role' => $this->string(20)->null(),
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

    /**
     * Drop plugin tables in reverse order to respect foreign key constraints.
     *
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%searchindex_field_mappings}}');
        $this->dropTableIfExists('{{%searchindex_indexes}}');

        return true;
    }
}
