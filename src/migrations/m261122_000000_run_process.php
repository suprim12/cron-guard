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
 * Each run’s process and server, so a run that’s still going isn’t taken for one that was killed.
 */
class m261122_000000_run_process extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Runs::TABLE_RUNS, 'pid')) {
            $this->addColumn(Runs::TABLE_RUNS, 'pid', $this->integer()->after('memory'));
        }
        if (!$this->db->columnExists(Runs::TABLE_RUNS, 'host')) {
            $this->addColumn(Runs::TABLE_RUNS, 'host', $this->string(255)->after('pid'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Runs::TABLE_RUNS, 'host');
        $this->dropColumn(Runs::TABLE_RUNS, 'pid');

        return true;
    }
}
