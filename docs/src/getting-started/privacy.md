# Privacy and Data

Cron Guard keeps everything in your site's own database and sends nothing anywhere you haven't set up.

## What it stores

Two tables, `cronguard_commands` and `cronguard_runs`, with Craft's table prefix:

- each command's name, as cron ran it, including its arguments;
- each run's start and end time, duration, exit code, peak memory, and the message of an exception if it had one.

Runs older than 30 days (`keepDays`) are removed, and at most 1,000 runs a command are kept. Uninstalling the plugin removes both tables.

## What it sends

Only alerts, and only to the channels you set: email through Craft's mailer, your Slack, Discord or Teams webhook, and your webhook URL. An alert contains the site's name, the command names, what went wrong, and a link to the control panel.

Error messages can contain whatever the command put in them, such as a URL or a file path. Keep that in mind when choosing where alerts go.

## The health URL

The health URL is off until you set a token. With a token, it returns command names and their status to anyone who has the token.

## No tracking

Cron Guard doesn't contact suprim or anyone else, and collects no usage data.
