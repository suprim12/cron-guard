# Cron Guard for Craft CMS

**Know when your scheduled commands stop running.** Cron Guard records every Craft command your server's cron runs, learns how often each one runs, and tells you when one fails, gets stuck, runs slow or stops running altogether. There's nothing to set up: install it and it starts watching.

Free, and MIT licensed. **[Documentation](https://plugins.cronguard.suprim.com.au)**

## Why

Most Craft sites have a few commands on a timer, such as `queue/run`, a nightly `feed-me/feeds/queue`, `gc` and a database backup. They fail quietly. A crontab gets lost when a server moves, a PHP upgrade changes the path to `php`, or a feed starts timing out, and nothing on the site looks wrong until a client asks why the products haven't updated for three weeks.

## What it does

- **Records every run.** Each command cron runs is saved with when it started, how long it took, how much memory it used, and how it ended, including the error message of an exception. Your crontab stays as it is.
- **Learns each schedule.** After four runs it knows a command runs every minute, every hour or every day. Commands run now and then, such as on deploy, are recognised as irregular and never reported missing.
- **Tells you what's wrong.**
  - **Failed:** a run ended with an error.
  - **Missed:** it hasn't run when it usually does. It allows half its interval for lateness, at least 5 minutes and at most 2 hours.
  - **Stuck:** a run started over an hour ago (or five times its usual time) and hasn't finished. This also catches runs killed for running out of memory or time.
  - **Overlapping:** runs of the command are piling up, each taking longer than the time between them.
  - **Slow:** a run took three times longer than usual.
  - **Memory:** a run used 85% of PHP's memory limit, so it's close to being killed for running out.
  - With a failure, the errors and warnings Craft logged while it ran, so you can see why without opening log files.
  - **Cron isn't running:** every command with a schedule is overdue at once, so the crontab itself has most likely stopped.
- **Sends each problem once.** Alerts go by email (HTML, with your own Twig or MJML template if you like), to Slack, Discord or Microsoft Teams, and to any webhook as JSON. A problem that's still there is sent again after 24 hours, and you get a message when it's back to normal.
- **Health URL for uptime monitors.** It answers 200 while every command is fine and 503 when one isn't, and runs the check as well, so alerts go out even on a site nobody visits.

## Requirements

Craft CMS 5.6 or later, and PHP 8.2 or later.

## Install

```
composer require suprim/cron-guard
php craft plugin/install cron-guard
```

Then go to **Settings → Plugins → Cron Guard** and add where alerts go. **Send a test alert** checks each channel.

## Where to see it

**Utilities → Cron Guard** lists every command with its status, schedule, last run and usual run time, and the Utilities menu shows how many need a look. Click a command to see its last 100 runs, or to mute or forget it. The **Cron Guard** dashboard widget shows the same at a glance: whether everything's fine, and the commands that need a look.

From the command line:

```
php craft cron-guard/check/status       # the same list; exits with 1 when a command needs a look
php craft cron-guard/check              # check now and send what's new
php craft cron-guard/check/test-alert   # send a test alert to every channel
```

## How checks run

A command that fails is reported as it ends. Missed and stuck commands are found by a check that runs at most every five minutes, after any command cron runs, after control panel and site requests, and when an uptime monitor calls the health URL. You don't need an extra cron line for it.

## What isn't watched

- Commands typed in a terminal. Cron runs commands without one, so testing by hand never adds noise.
- Deploy and setup commands: `up`, `migrate/*`, `project-config/*`, `plugin/*`, `install/*`, `setup/*`, `clear-caches/*`, `cache/*`, `on`, `off`, `help` and `queue/listen`. Change the list under **Don't watch** on the settings page.
- `queue/exec`, the process each queue job runs in, and `exec`, which runs a piece of PHP: never watched.

Commands with different arguments are watched separately, so `resave/entries --section=news` and `resave/entries --section=events` each have their own schedule.

## Health URL

Set a **Token** on the settings page (an environment variable such as `$CRON_GUARD_TOKEN` works), then point your uptime monitor at:

```
https://example.com/actions/cron-guard/health?cronGuardToken=YOUR_TOKEN
```

The token can also be sent as an `X-Cron-Guard-Token` header. Without a token, the URL is off and answers 404.

## Config file

Settings on the settings page can also be set in `config/cron-guard.php`, which wins over the page. A few options are only there:

```php
<?php
return [
    // Fixed schedules for commands whose schedule shouldn't be learnt, by command or route.
    'schedules' => [
        'db/backup' => '1 day',
        'feed-me/feeds/queue --id=3' => '6 hours',
    ],
    // Also watch commands typed in a terminal.
    'watchInteractive' => false,
    // Days of runs kept (at most 1,000 runs a command are kept either way).
    'keepDays' => 30,
    // Hours before a problem that hasn't gone away is sent again; 0 sends it once.
    'remindHours' => 24,
    // Minutes a run may take before it counts as stuck, unless it usually takes longer.
    'stuckMinutes' => 60,
    // Run the check after control panel and site requests too.
    'checkFromWeb' => true,
];
```

## Sending alerts your own way

Listen for `Alerts::EVENT_BEFORE_SEND` to send alerts somewhere else. Set `$event->handled = true` to stop Cron Guard sending them itself.

```php
use suprim\cronguard\events\AlertEvent;
use suprim\cronguard\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_SEND, function(AlertEvent $event) {
    // $event->subject, $event->text, $event->problems, $event->recovered, $event->stopped
});
```

## Support

Email [support@suprim.com.au](mailto:support@suprim.com.au) or [open an issue](https://github.com/suprim12/cron-guard/issues).
