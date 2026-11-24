<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\widgets;

use Craft;
use craft\base\Widget;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Checker;
use Throwable;

/**
 * A dashboard widget: whether every scheduled command is fine, and the ones that need a look.
 */
class CronGuardWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('cron-guard', 'Cron Guard');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@suprim/cronguard/icon-mask.svg');
    }

    /**
     * Only for people who can open the utility, as that’s where its links go.
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('utility:cron-guard');
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }

    public function getBodyHtml(): ?string
    {
        if (!static::isSelectable()) {
            return '<p class="light">' . Craft::t('cron-guard', 'You need access to the Cron Guard utility to see this.') . '</p>';
        }

        try {
            $checker = Plugin::getInstance()->checker;
            $statuses = $checker->statuses();
        } catch (Throwable $e) {
            // Such as an update whose migration hasn’t run yet: the dashboard still loads.
            Craft::warning('Cron Guard’s widget couldn’t read the commands: ' . $e->getMessage(), 'cron-guard');

            return '<p class="light">' . Craft::t('cron-guard', 'Cron Guard couldn’t read its commands. Check for pending updates.') . '</p>';
        }

        $now = time();
        $lastAny = $statuses ? max(array_map(fn($s) => $s['lastStart'] ?? 0, $statuses)) : null;

        return Craft::$app->getView()->renderTemplate('cron-guard/_widget', [
            'statuses' => $statuses,
            'problems' => array_values(array_filter($statuses, fn($s) => in_array($s['state'], Checker::PROBLEMS, true))),
            'checker' => $checker,
            'lastAny' => $lastAny,
            'now' => $now,
            // As on the utility: nothing has run for a day, though something should run more often than that.
            'stopped' => $lastAny && $now - $lastAny > 86400 && array_filter($statuses, fn($s) => $s['interval'] && $s['interval'] < 86400),
        ]);
    }
}
