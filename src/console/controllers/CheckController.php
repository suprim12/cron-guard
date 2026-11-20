<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Checker;
use yii\console\ExitCode;

/**
 * Shows the watched commands, and checks them now.
 */
class CheckController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Checks every watched command now and sends what’s new.
     */
    public function actionIndex(): int
    {
        $checker = Plugin::getInstance()->checker;
        $checker->resetDue();
        $alert = $checker->check();
        $this->stdout($alert
            ? sprintf("Sent an alert: %d problem(s), %d back to normal.\n", count($alert['problems']), count($alert['recovered']))
            : "Checked: nothing new to send.\n");

        return $this->actionStatus();
    }

    /**
     * Lists the watched commands, their schedule and how they’re doing. Exits with 1 when one has a problem.
     */
    public function actionStatus(): int
    {
        $checker = Plugin::getInstance()->checker;
        $statuses = $checker->statuses();
        if (!$statuses) {
            $this->stdout("No commands recorded yet. Cron Guard records commands run without a terminal, as cron runs them.\n");

            return ExitCode::OK;
        }
        $rows = array_map(fn($s) => [
            $s['command'],
            $s['interval'] !== null ? $checker->every($s['interval']) : ($s['state'] === Checker::LEARNING ? "learning ({$s['runs']} of " . Checker::RUNS_TO_LEARN . ')' : 'irregular'),
            $s['lastStart'] ? $checker->duration(time() - $s['lastStart']) . ' ago' : '',
            $s['state'] . ($s['message'] ? ": {$s['message']}" : ''),
        ], $statuses);
        $this->table(['Command', 'Runs', 'Last run', 'Status'], $rows);
        $problems = array_filter($statuses, fn($s) => in_array($s['state'], Checker::PROBLEMS, true));
        if ($problems) {
            $this->stdout(count($problems) . " command(s) need a look.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Sends a test alert to every channel on the settings page.
     */
    public function actionTestAlert(): int
    {
        $results = Plugin::getInstance()->alerts->sendTest();
        if (!$results) {
            $this->stderr("No email address or webhook is set on the settings page.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }
        foreach ($results as $channel => $result) {
            $this->stdout($result === true ? "$channel: sent.\n" : "$channel: $result\n", $result === true ? Console::FG_GREEN : Console::FG_RED);
        }

        return in_array(true, $results, true) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
