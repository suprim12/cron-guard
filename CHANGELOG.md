# Release Notes for Cron Guard

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
