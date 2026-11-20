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
use craft\services\Utilities;
use suprim\cronguard\models\Settings;
use suprim\cronguard\services\Alerts;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\utilities\CronGuardUtility;
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
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

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

        $app = Craft::$app;
        if ($app instanceof ConsoleApplication) {
            $app->on(Application::EVENT_BEFORE_ACTION, fn(ActionEvent $event) => $this->runs->start($event));
            $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->runs->setExitCode((int)$app->getResponse()->exitStatus));
        } elseif ($this->getSettings()->checkFromWeb) {
            // Catches cron stopping altogether, when no command is left to notice it. After the page has gone out, so
            // the visitor never waits for an alert to send.
            $app->on(Application::EVENT_AFTER_REQUEST, function() use ($app) {
                if ($app->getIsInstalled() && !$app->getRequest()->getIsActionRequest()) {
                    $app->getResponse()->on(Response::EVENT_AFTER_SEND, fn() => $this->checker->checkIfDue());
                }
            });
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The ignore list comes from the settings page as text, one a line.
     */
    public function setSettings(array $settings): void
    {
        if (isset($settings['ignore']) && is_string($settings['ignore'])) {
            $settings['ignore'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $settings['ignore']))));
        }
        parent::setSettings($settings);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cron-guard/_settings', [
            'settings' => $this->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('cron-guard')),
            'healthUrl' => \craft\helpers\UrlHelper::actionUrl('cron-guard/health'),
        ]);
    }
}
