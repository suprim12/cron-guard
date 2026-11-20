# Troubleshooting

## A command doesn't appear

- **It hasn't run yet** since Cron Guard was installed. Commands appear after their first run.
- **It was typed in a terminal.** Cron Guard ignores commands run with a terminal attached, so testing by hand doesn't add noise. Run it as cron would, with no terminal (`php craft my/command < /dev/null`), or set `watchInteractive` to `true`.
- **It's on the ignore list.** Check **Don't watch** on the settings page.
- **It isn't a Craft command.** Only `php craft …` commands are watched.

## A command stays on "Learning"

It needs four runs. A daily command takes four days. To skip the wait, give it a schedule in `config/cron-guard.php`:

```php
'schedules' => ['db/backup' => '1 day'],
```

## A command shows as "Irregular"

Its runs don't follow a pattern: fewer than four out of five gaps between runs are within half of the usual gap. That's expected for commands run on deploy. Irregular commands are still reported when they fail, but never as missed. If it should run at least every so often, give it a schedule.

## No alerts arrive

- Click **Send a test alert** on the settings page. It shows what each channel answered.
- Email goes through Craft's email settings: check **Settings → Email** and send Craft's own test email.
- With no channels set, alerts go to Craft's log (`storage/logs/`).
- Each problem is sent once. **Utilities → Cron Guard** shows what's wrong right now.

## A missed command was reported late

Missed runs are found by a check that runs after any command, after control panel and site requests, and when an uptime monitor calls the health URL, at most every five minutes. On a site with no visitors and no commands running, nothing triggers the check. Set up the [health URL](../guides/health-url) in an uptime monitor.

## Renamed or removed commands

Open the command under **Utilities → Cron Guard** and click **Forget**. Commands with no runs left after `keepDays` are forgotten on their own.
