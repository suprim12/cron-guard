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
 * When a command’s mute ends, for commands muted for a while, and forgets the `exec …` commands recorded before 1.1.0,
 * one for each piece of code run.
 */
class m261124_000000_muted_until extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Runs::TABLE_COMMANDS, 'mutedUntil')) {
            $this->addColumn(Runs::TABLE_COMMANDS, 'mutedUntil', $this->dateTime()->after('muted'));
        }
        $this->delete(Runs::TABLE_COMMANDS, ['route' => 'exec']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Runs::TABLE_COMMANDS, 'mutedUntil');

        return true;
    }
}
