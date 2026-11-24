<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Where alerts go and what isn’t watched are on the settings page; the rest is for config/cron-guard.php.
 */
class Settings extends Model
{
    /** Email addresses alerts go to, one a line or separated by commas. Environment variables work. */
    public string $emails = '';

    /** A Slack, Discord or Microsoft Teams incoming webhook URL. */
    public string $chatWebhook = '';

    /** A URL alerts are posted to as JSON. */
    public string $webhookUrl = '';

    /** Opens the health URL to uptime monitors that send it; empty keeps the health URL off. */
    public string $healthToken = '';

    /**
     * Commands that aren’t watched, one a line, with * for any part (`resave/*`). Deploy and setup commands are left
     * out by default, as they run when someone deploys, not on a schedule.
     *
     * @var string[]
     */
    public array $ignore = [
        'help',
        'up',
        'on',
        'off',
        'install/*',
        'setup/*',
        'migrate/*',
        'project-config/*',
        'plugin/*',
        'clear-caches/*',
        'cache/*',
        'queue/listen',
        'cron-guard/*',
    ];

    // Config file only

    /**
     * Expected time between runs for commands whose schedule shouldn’t be learnt, by command or route, as a number
     * of seconds or a string like '1 day'. `['db/backup' => '1 day']`.
     *
     * @var array<string, int|string>
     */
    public array $schedules = [];

    /** Also watch commands run by hand in a terminal. */
    public bool $watchInteractive = false;

    /** Days of runs kept. */
    public int $keepDays = 30;

    /** Hours before a problem that hasn’t gone away is sent again; 0 sends it once. */
    public int $remindHours = 24;

    /** Minutes a run may take before it counts as stuck, unless it usually takes longer. */
    public int $stuckMinutes = 60;

    /**
     * Percent of PHP’s memory limit a run may use before its command is reported as near the limit, ahead of it being
     * killed for running out. 0 turns it off.
     */
    public int $memoryWarnPercent = 85;

    /**
     * Commands meant to run more than once at a time, which aren’t reported as overlapping. Craft’s `queue/run` takes
     * jobs one at a time, so several can run side by side.
     *
     * @var string[]
     */
    public array $allowOverlap = ['queue/run'];

    /**
     * Put the errors a failed run logged in alerts. Off keeps them in the control panel only, for alerts that go to a
     * shared channel. Secrets are masked either way, and email addresses in alerts.
     */
    public bool $logInAlerts = true;

    /**
     * More regular expressions to mask in error and log messages before they’re saved, such as `/ACME-[0-9A-F]{32}/`,
     * on top of passwords, tokens, keys and credentials in URLs.
     *
     * @var string[]
     */
    public array $redact = [];

    /** Run the check, at most every five minutes, after control panel and site requests too. */
    public bool $checkFromWeb = true;

    /**
     * A site template for the HTML of alert emails, such as `_emails/cron-guard` or `_emails/cron-guard.mjml`. A `.mjml`
     * template is compiled as it’s sent, which needs the spatie/mjml-php package. Empty uses Cron Guard’s own.
     */
    public string $emailTemplate = '';

    /**
     * @return string[]
     */
    public function emailList(): array
    {
        return array_values(array_filter($this->splitEmails(), fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @return string[]
     */
    private function splitEmails(): array
    {
        return preg_split('/[\s,;]+/', (string)App::parseEnv($this->emails), -1, PREG_SPLIT_NO_EMPTY);
    }

    protected function defineRules(): array
    {
        return [
            [['emails', 'chatWebhook', 'webhookUrl', 'healthToken'], 'string'],
            [['emails'], function($attribute) {
                foreach ($this->splitEmails() as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->addError($attribute, "“{$email}” isn’t an email address.");
                    }
                }
            }],
            [['chatWebhook', 'webhookUrl'], function($attribute) {
                $url = (string)App::parseEnv($this->$attribute);
                if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                    $this->addError($attribute, 'Enter a URL starting with https://.');
                }
            }],
            [['keepDays'], 'integer', 'min' => 1],
            [['remindHours', 'stuckMinutes'], 'integer', 'min' => 0],
            [['memoryWarnPercent'], 'integer', 'min' => 0, 'max' => 100],
        ];
    }
}
