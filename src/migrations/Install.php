<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\migrations;

use craft\db\Migration;
use suprim\cronguard\services\Runs;

/**
 * Two tables: the commands seen, and their runs.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Runs::TABLE_COMMANDS, [
            'id' => $this->primaryKey(),
            'command' => $this->string(255)->notNull(),
            'route' => $this->string(255)->notNull(),
            'muted' => $this->boolean()->notNull()->defaultValue(false),
            // When a mute for a while ends; null while muted until it’s turned off.
            'mutedUntil' => $this->dateTime(),
            // The problem last alerted, so it’s sent once, and “recovered” when it goes away.
            'alertState' => $this->string(20),
            'alertedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Runs::TABLE_COMMANDS, ['command'], true);

        $this->createTable(Runs::TABLE_RUNS, [
            'id' => $this->primaryKey(),
            'commandId' => $this->integer()->notNull(),
            'startedAt' => $this->dateTime()->notNull(),
            'finishedAt' => $this->dateTime(),
            'durationMs' => $this->integer(),
            // Null while running, and for a run killed before it could finish.
            'exitCode' => $this->smallInteger(),
            'error' => $this->text(),
            // The errors and warnings logged during a failed run, as JSON.
            'log' => $this->text(),
            'memory' => $this->bigInteger(),
            'memoryLimit' => $this->bigInteger(),
            // The process and server running it, to tell a run still going from one that was killed.
            'pid' => $this->integer(),
            'host' => $this->string(255),
        ]);
        $this->createIndex(null, Runs::TABLE_RUNS, ['commandId', 'startedAt']);
        $this->addForeignKey(null, Runs::TABLE_RUNS, ['commandId'], Runs::TABLE_COMMANDS, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Runs::TABLE_RUNS);
        $this->dropTableIfExists(Runs::TABLE_COMMANDS);

        return true;
    }
}
