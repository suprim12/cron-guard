# Introduction

Cron Guard is a free Craft CMS plugin that watches the commands your server's cron runs and tells you when one goes wrong.

Most Craft sites have a few commands on a timer: `queue/run` every minute, a nightly product feed, `gc`, a database backup. They fail quietly. A crontab gets lost when a server moves, a PHP upgrade changes the path to `php`, or a feed starts timing out, and nothing on the site looks wrong until someone notices the content is weeks out of date.

## What it does

- **Records every run:** when it started, how long it took, how much memory it used, and how it ended, including the error message of an exception.
- **Learns each schedule** from the runs themselves. There's nothing to configure.
- **Reports six kinds of problem:** a command that **failed** (with what it logged), **missed** its usual time, is **stuck**, is **overlapping** itself, ran **slow**, or is near its **memory** limit. When everything is late at once, it tells you **cron has stopped**.
- **Sends one alert per problem** by email, to Slack, Discord or Microsoft Teams, or to a webhook, and tells you when it's back to normal.
- **Shows it all** under **Utilities → Cron Guard**, with a count of problems on the Utilities menu.
- **Answers uptime monitors** on a health URL.

## What it doesn't do

Cron Guard watches Craft console commands (`php craft …`). It doesn't run anything on a schedule itself, and it doesn't watch scripts outside Craft, such as a `mysqldump` line in the crontab.

Queue jobs aren't watched one by one, but `queue/run` is a command like any other: if it stops running, you'll hear about it.

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- MySQL, MariaDB or PostgreSQL, as Craft supports

Next: [Installation](./installation)
