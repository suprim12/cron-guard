# Configuration

Where alerts go, the health URL token and the list of commands not to watch are on the settings page. Everything can also be set in `config/cron-guard.php`, which wins over the settings page. A field set there shows a warning and can't be changed in the control panel.

```php
<?php
return [
    // Settings page
    'emails' => '$CRON_GUARD_EMAILS',
    'chatWebhook' => '$SLACK_WEBHOOK_URL',
    'webhookUrl' => '',
    'healthToken' => '$CRON_GUARD_TOKEN',
    'ignore' => ['help', 'up', 'on', 'off', 'install/*', 'setup/*', 'migrate/*', 'project-config/*', 'plugin/*', 'clear-caches/*', 'cache/*', 'queue/listen', 'cron-guard/*'],

    // Config file only
    'schedules' => [
        'db/backup' => '1 day',
        'feed-me/feeds/queue --id=3' => '6 hours',
    ],
    'watchInteractive' => false,
    'keepDays' => 30,
    'remindHours' => 24,
    'stuckMinutes' => 60,
    'memoryWarnPercent' => 85,
    'allowOverlap' => ['queue/run'],
    'checkFromWeb' => true,
    'emailTemplate' => '',
];
```

## Settings

| Setting | Default | What it does |
|---|---|---|
| `emails` | | Addresses alerts are emailed to, one a line or separated by commas. |
| `chatWebhook` | | A Slack, Discord or Microsoft Teams incoming webhook URL. |
| `webhookUrl` | | A URL alerts are posted to as JSON. |
| `healthToken` | | Turns on the [health URL](../guides/health-url). Empty keeps it off. |
| `ignore` | Deploy and setup commands | Commands not watched, with `*` for any part. See [What's watched](../guides/watched). |
| `schedules` | `[]` | Fixed schedules, by command or route, as seconds or a string like `'1 day'`, for commands whose schedule shouldn't be learnt. |
| `watchInteractive` | `false` | Also watch commands typed in a terminal. |
| `keepDays` | `30` | Days of runs kept. At most 1,000 runs a command are kept either way. |
| `remindHours` | `24` | Hours before a problem that's still there is sent again. `0` sends it once. |
| `stuckMinutes` | `60` | Minutes a run may take before it counts as stuck, unless it usually takes longer. |
| `memoryWarnPercent` | `85` | Percent of PHP's memory limit a run may use before its command is reported as near the limit. `0` turns it off. |
| `allowOverlap` | `['queue/run']` | Commands meant to run more than once at a time, with `*` for any part, which aren't reported as overlapping. |
| `checkFromWeb` | `true` | Run the check after control panel and site requests too, at most every five minutes. |
| `emailTemplate` | | A site template for alert emails, in Twig or MJML. See [Email Templates](../guides/email-templates). |

## When to set a schedule yourself

Learning works for anything that runs at regular times. Set a schedule in `schedules` when:

- a command runs rarely, such as weekly, and you don't want to wait four weeks for it to be learnt;
- a command's runs vary a lot, so it shows as **Irregular**, but it should still run at least this often.
