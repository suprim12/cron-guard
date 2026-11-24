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
 * What a failed run logged, and PHP’s memory limit for each run, to warn before a command runs out.
 */
class m261124_000001_run_log_memory extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Runs::TABLE_RUNS, 'memoryLimit')) {
            $this->addColumn(Runs::TABLE_RUNS, 'memoryLimit', $this->bigInteger()->after('memory'));
        }
        if (!$this->db->columnExists(Runs::TABLE_RUNS, 'log')) {
            $this->addColumn(Runs::TABLE_RUNS, 'log', $this->text()->after('error'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Runs::TABLE_RUNS, 'log');
        $this->dropColumn(Runs::TABLE_RUNS, 'memoryLimit');

        return true;
    }
}
