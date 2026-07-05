<?php

namespace gerry3010\mcp\migrations;

use craft\db\Migration;
use gerry3010\mcp\mcp\Auth;

/**
 * Creates the {{%mcp_tokens}} table: bearer tokens (stored as SHA-256 hashes)
 * bound to Craft users.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = Auth::TABLE;
        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'tokenHash' => $this->string(64)->notNull(),
            'label' => $this->string(255)->null(),
            'expiresAt' => $this->dateTime()->null(),
            'lastUsedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['tokenHash'], true);
        $this->createIndex(null, $table, ['userId'], false);
        $this->addForeignKey(null, $table, ['userId'], '{{%users}}', ['id'], 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Auth::TABLE);
        return true;
    }
}
