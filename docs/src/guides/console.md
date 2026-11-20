# Console Commands

## `cron-guard/check/status`

Lists every command with its schedule, last run and status. Exits with `1` when a command needs a look, so it can be used in scripts.

```
$ php craft cron-guard/check/status
   Command                     Runs          Last run        Status
   --------------------------  ------------  --------------  ------------------------------------------
   db/backup                   every day     1.2 days ago    overdue: hasn’t run for 1.2 days; it usually runs every day
   gc                          every day     3 hours ago     ok
   queue/run                   every minute  20 seconds ago  ok
1 command(s) need a look.
```

## `cron-guard/check`

Checks every command now, sends anything new, then lists them as `status` does. You don't need to add it to your crontab: checks already run after every command.

## `cron-guard/check/test-alert`

Sends a test alert to every channel on the settings page and prints what each one answered.

`cron-guard/*` commands are never recorded themselves.
