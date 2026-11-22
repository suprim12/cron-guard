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
 * Forgets the `queue/exec …` commands recorded before 1.0.2, one for each queue job, with their runs.
 */
class m261122_000001_forget_queue_exec extends Migration
{
    public function safeUp(): bool
    {
        $this->delete(Runs::TABLE_COMMANDS, ['route' => Runs::NEVER_WATCHED]);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
