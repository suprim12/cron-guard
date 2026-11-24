# Release Notes for Cron Guard

## Unreleased

### Added
- A **Cron Guard** dashboard widget: whether every command is running as it should, the ones that need a look with what’s wrong, and when cron itself has most likely stopped. It’s there for people who can use the Cron Guard utility.
- A failed run keeps the errors and warnings Craft logged while it ran, so you can see why it failed without opening log files. They’re on its command’s page, and with `logInAlerts` on, the last few errors are in the alert and all of them in the webhook’s `log`.
- Commands near their memory limit: a run that used 85% or more of PHP’s memory limit is reported before the command is killed for running out (`memoryWarnPercent`). Each run’s memory shows with the limit.
- Overlapping runs: two or more runs of a command going at once, as each takes longer than the time between them, are reported. `queue/run` is left out, as Craft runs several side by side (`allowOverlap`).
- Secrets are masked before error and log messages are saved: passwords, tokens and keys, credentials in URLs, and well-known key formats, with your own patterns in `redact`. Alerts and the health URL mask them in command names too, and shorten email addresses.
- Mute a command for an hour, a day or a week, as well as until you turn it off. It’s watched again on its own when the time is up, and a problem still there is sent then.

### Fixed
- Problems found before an email address or webhook was saved only went to the log, and weren’t sent once one was until their reminder a day later. They’re now sent with the next check.
- A command that failed within an hour of a slow-run alert wasn’t reported until a day later, as if it were flapping. It’s now sent straight away.
- A command’s usual run time left out one of its runs after a run was killed, so its stuck time and the slow-run check could be off.
- Each piece of code run with `php craft exec` was listed as a command of its own, such as `exec echo 1;`. `exec` is no longer watched, and the ones recorded are removed.
- A long run still going showed as “Didn’t finish” on its command’s page once a newer run had started. It now reads “Running, or stopped before it finished”, as the newest does.
- An error message with `<!channel>` or `@everyone` in it, such as one quoting a feed’s data, could ping a whole Slack or Discord channel, or add a link to a Slack alert. They’re now shown as text.
- `cron-guard/check/status` showed a muted command that hadn’t had four runs yet as irregular, rather than learning.

## 1.0.3 - 2026-11-24

### Fixed
- Commands on the Don’t watch list, such as `migrate/*` and `plugin/*`, were watched once the settings page had been saved: the list was saved as one line. It’s split into its lines again, including a list already saved that way.

## 1.0.2 - 2026-11-22

### Fixed
- Each queue job was listed as a command of its own, such as `queue/exec 672202 300 1 2761154`: `queue/run` runs each job in a process of its own. Those are no longer watched, whatever the Don’t watch list says, and the ones recorded are removed. A job’s time still counts in its `queue/run`.
- Runs shorter than half a second showed as taking “0 seconds”; they now take “under a second”.

## 1.0.1 - 2026-11-22

### Fixed
- A long run still going, such as a queue run working through an import while cron starts the next ones each minute, was reported as killed after ten minutes, then as back to normal once it finished. A run whose process is still going on the same server is now left alone.
- The health URL on the settings page was the control panel’s address, which uptime monitors can’t reach where the control panel is on its own domain or limited to some IPs. It’s now the site’s own address.
- Failed runs whose error ends with a full stop were reported with two, such as “Couldn’t connect.. Last ran fine”.

## 1.0.0 - 2026-11-21

### Added
- Records every Craft command cron runs: when it started, how long it took, its memory use, and how it ended, including the error of an exception or a crash.
- Learns each command's schedule from its runs, and recognises commands that run now and then, such as on deploy, so they're never reported missing.
- Reports commands that failed, were missed, are stuck or ran slow, and when cron itself has stopped.
- Alerts by email, to Slack, Discord or Microsoft Teams, and to a webhook, each sent once, again after 24 hours while still there, and with a message when it's back to normal.
- **Utilities → Cron Guard**: every command with its status, schedule, last run and usual run time, and each command's runs, with Mute and Forget.
- A health URL for uptime monitors: 200 while every command is fine, 503 when one isn't.
- `cron-guard/check`, `cron-guard/check/status` and `cron-guard/check/test-alert` commands.
- `Alerts::EVENT_BEFORE_SEND`, to send alerts your own way.
- HTML alert emails, and `emailTemplate` to use a site template of your own, written in Twig or MJML.
