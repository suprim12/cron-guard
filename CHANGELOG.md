# Release Notes for Cron Guard

## 1.0.0 - Unreleased

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
