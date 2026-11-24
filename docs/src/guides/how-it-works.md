# How It Works

## Recording

When Craft runs a console command, Cron Guard saves a run as it starts and finishes it as the command ends, with how long it took, its peak memory and its exit code. An uncaught exception is saved with its message. If PHP stops with a fatal error, Craft's own error handling finishes first and the run is still saved.

A run that never finishes, because the process was killed for using too much memory, hit a time limit, or the server restarted, stays open. While it's the newest run it's reported as **stuck**. Once a newer run has started and it's been open five times its usual time (and at least ten minutes), it's closed as **stopped before it finished** and counts as a failed run.

Commands run inside another command are part of the same run. For example, `up` runs the migrations itself.

## Learning a schedule

Cron Guard looks at the gaps between a command's last 20 runs. After four runs, if at least four in five gaps are within half of the usual gap, that usual gap is the command's schedule: every minute, every hour, every day.

It also remembers the longest gap the command usually has. A command that runs on weekdays only learns "every day" with a usual three-day gap over the weekend, so it isn't reported missing on Saturday, and is on Tuesday if Monday's run doesn't happen.

A command whose runs don't follow a pattern, such as one run on deploy, is **Irregular**. It's still reported when it fails, but never as missed.

You can also [set a schedule](../getting-started/configuration#when-to-set-a-schedule-yourself) in `config/cron-guard.php`.

## What counts as a problem

| Status | When |
|---|---|
| **Failed** | The last run ended with a non-zero exit code or an exception, or was stopped before it finished. |
| **Missed** | It hasn't started for longer than its schedule (or its longest usual gap) plus some slack: half the interval, at least 5 minutes and at most 2 hours. A command that runs every 5 minutes is missed after 10, a daily one after 26 hours. A command that's running right now is never missed. |
| **Stuck** | A run started more than 60 minutes ago (`stuckMinutes`), or five times its usual time if that's longer, and hasn't finished. |
| **Overlapping** | Two or more runs of the command have been going for a minute or more at once: each run takes longer than the time between them, so they pile up. Runs on other servers count until they finish or are closed as stopped. `queue/run` is left out, as Craft runs several side by side (`allowOverlap`). |
| **Slow** | The last run took three times its usual time, and over a minute longer. Needs five runs to compare with. |
| **Memory** | The last run used 85% or more of PHP's memory limit (`memoryWarnPercent`), so it's close to being killed for running out. Where PHP on the command line has no limit, as it often doesn't, there's nothing to warn about. |

A failed run keeps the errors and warnings Craft logged while it ran, the last 20. They're on its command's page, and in alerts too with `logInAlerts` on.

When every command with a schedule is missed at once, the alert says **cron isn't running** rather than listing each one.

## When checks run

- **As a command ends**, it's checked straight away, so a failure is reported within seconds.
- **Every five minutes at most**, all commands are checked for missed and stuck runs. The check is triggered by any command cron runs, by control panel and site requests (after the response has been sent, so nobody waits for it), and by the [health URL](./health-url).

There's no extra cron line to add. On a site with no visitors where cron has stopped completely, nothing would trigger the check, which is what the health URL is for.
