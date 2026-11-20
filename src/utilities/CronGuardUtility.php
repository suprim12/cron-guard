<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use Throwable;

/**
 * Utilities → Cron Guard: every watched command and how it’s doing, and a command’s runs.
 */
class CronGuardUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('cron-guard', 'Cron Guard');
    }

    public static function id(): string
    {
        return 'cron-guard';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@suprim/cronguard/icon-mask.svg');
    }

    /**
     * The number of commands with a problem.
     */
    public static function badgeCount(): int
    {
        try {
            // Shown in the nav on every control panel page, so worked out once a minute.
            return (int)Craft::$app->getCache()->getOrSet('cron-guard:badge', fn() => count(array_filter(
                Plugin::getInstance()->checker->statuses(),
                fn($s) => in_array($s['state'], Checker::PROBLEMS, true),
            )), 60);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function contentHtml(): string
    {
        return self::html((int)Craft::$app->getRequest()->getQueryParam('command'));
    }

    /**
     * Every command, or one command’s runs.
     */
    public static function html(int $id = 0): string
    {
        $checker = Plugin::getInstance()->checker;
        if ($id) {
            $command = (new Query())->from(Runs::TABLE_COMMANDS)->where(['id' => $id])->one();
            if ($command) {
                $runs = $checker->recentRuns($id, 100);
                foreach ($runs as &$run) {
                    $run['started'] = DateTimeHelper::toDateTime($run['startedAt']);
                }
                unset($run);

                return Craft::$app->getView()->renderTemplate('cron-guard/_command', [
                    'status' => $checker->status($command, $checker->recentRuns($id), time()),
                    'runs' => $runs,
                    'checker' => $checker,
                ]);
            }
        }

        $statuses = $checker->statuses();
        $lastAny = $statuses ? max(array_map(fn($s) => $s['lastStart'] ?? 0, $statuses)) : null;

        return Craft::$app->getView()->renderTemplate('cron-guard/_utility', [
            'statuses' => $statuses,
            'checker' => $checker,
            'lastAny' => $lastAny,
            'problems' => Checker::PROBLEMS,
            'runsToLearn' => Checker::RUNS_TO_LEARN,
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }
}
