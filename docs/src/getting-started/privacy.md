# Privacy and Data

Cron Guard keeps everything in your site's own database and sends nothing anywhere you haven't set up.

## What it stores

Two tables, `cronguard_commands` and `cronguard_runs`, with Craft's table prefix:

- each command's name, as cron ran it, including its arguments;
- each run's start and end time, duration, exit code, peak memory and PHP's memory limit, and the message of an exception if it had one;
- for a failed run, the last 20 errors and warnings Craft logged while it ran, the same messages Craft writes to its own log files.

Runs older than 30 days (`keepDays`) are removed, and at most 1,000 runs a command are kept. Uninstalling the plugin removes both tables.

## What it sends

Only alerts, and only to the channels you set: email through Craft's mailer, your Slack, Discord or Teams webhook, and your webhook URL. An alert contains the site's name, the command names, what went wrong, and a link to the control panel.

Error messages, and the logged errors in an alert, can contain whatever the command or a plugin put in them, such as a URL, a file path or an email address. Keep that in mind when choosing where alerts go.

## The health URL

The health URL is off until you set a token. With a token, it returns command names and their status to anyone who has the token.

## No tracking

Cron Guard doesn't contact suprim or anyone else, and collects no usage data.
