<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Dashboard;
use craft\services\Utilities;
use suprim\cronguard\models\Settings;
use suprim\cronguard\services\Alerts;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\utilities\CronGuardUtility;
use suprim\cronguard\widgets\CronGuardWidget;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Event;
use yii\web\Response;

/**
 * Cron Guard: tells you when scheduled commands stop running, fail, get stuck or run slow.
 *
 * @property-read Runs $runs
 * @property-read Checker $checker
 * @property-read Alerts $alerts
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;

    /** Whether alerts had somewhere to go before the settings being saved. */
    private bool $hadChannels = true;

    public static function config(): array
    {
        return [
            'components' => [
                'runs' => Runs::class,
                'checker' => Checker::class,
                'alerts' => Alerts::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = CronGuardUtility::class;
        });
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = CronGuardWidget::class;
        });

        $app = Craft::$app;
        if ($app instanceof ConsoleApplication) {
            $app->on(Application::EVENT_BEFORE_ACTION, fn(ActionEvent $event) => $this->runs->start($event));
            $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->runs->afterRequest());
        } elseif ($this->getSettings()->checkFromWeb) {
            // Catches cron stopping altogether, when no command is left to notice it. After the page has gone out and
            // the connection is closed, so the visitor never waits for an alert to send.
            $app->on(Application::EVENT_AFTER_REQUEST, function() use ($app) {
                if ($app->getIsInstalled() && !$app->getRequest()->getIsActionRequest()) {
                    $app->getResponse()->on(Response::EVENT_AFTER_SEND, fn() => $this->checker->checkIfDue(function() {
                        if (function_exists('fastcgi_finish_request')) {
                            fastcgi_finish_request();
                        }
                    }));
                }
            });
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Notes whether alerts had somewhere to go, from the settings as they were saved before (the project config
     * isn’t updated yet), with the config file’s on top.
     */
    public function beforeSaveSettings(): bool
    {
        $saved = Craft::$app->getProjectConfig()->get('plugins.' . $this->handle . '.settings') ?? [];
        $old = new Settings();
        $old->setAttributes(array_merge(is_array($saved) ? $saved : [], Craft::$app->getConfig()->getConfigFromFile($this->handle)), false);
        $this->hadChannels = $old->hasChannels();

        return parent::beforeSaveSettings();
    }

    /**
     * When alerts first get somewhere to go, the problems found before then (which only went to the log) are sent
     * with the next check, rather than a day later as reminders.
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        if (!$this->hadChannels && $this->getSettings()->hasChannels()) {
            $this->checker->resendAll();
        }
        $this->hadChannels = true;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cron-guard/_settings', [
            'settings' => $this->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('cron-guard')),
            // The site’s address, not the control panel’s: that may be on its own domain, or closed to all but some IPs.
            'healthUrl' => \craft\helpers\UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/cron-guard/health'),
        ]);
    }
}
