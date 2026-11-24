# Privacy and Data

Cron Guard keeps everything in your site's own database and sends nothing anywhere you haven't set up.

## What it stores

Two tables, `cronguard_commands` and `cronguard_runs`, with Craft's table prefix:

- each command's name, as cron ran it, including its arguments;
- each run's start and end time, duration, exit code, peak memory and PHP's memory limit, and the message of an exception if it had one;
- for a failed run, the last 20 errors and warnings Craft logged while it ran, the same messages Craft writes to its own log files.

Before an error or log message is saved, Cron Guard masks what looks like a secret: values named like a password, token, secret or key (`password=…`, `"api_key": "…"`), `?key=` in URLs, `Authorization: Bearer …`, the password in `ftp://user:password@host`, and well-known key formats such as Stripe, Slack, GitHub, AWS and Google keys, JWTs and private keys. They're saved as `••••`. Add your own patterns with [`redact`](./configuration).

Runs older than 30 days (`keepDays`) are removed, and at most 1,000 runs a command are kept. Uninstalling the plugin removes both tables.

## What it sends

Only alerts, and only to the channels you set: email through Craft's mailer, your Slack, Discord or Teams webhook, and your webhook URL. An alert contains the site's name, the command names, what went wrong, and a link to the control panel.

Before an alert goes out, secrets are masked in it as well, in command names too (`sync --api-token=••••`), and email addresses are shortened to their first letter and domain (`j••••@example.com`). The control panel shows them in full.

Error messages and logged errors can still hold other details, such as a file path, an order number or a customer's name. To keep logged errors out of alerts, for alerts that go to a shared channel, set `logInAlerts` to `false`: they stay on each command's page.

## The health URL

The health URL is off until you set a token. With a token, it returns command names and their status to anyone who has the token, masked as alerts are.

## No tracking

Cron Guard doesn't contact suprim or anyone else, and collects no usage data.
