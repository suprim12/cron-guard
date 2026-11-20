# What's Watched

Every Craft console command that runs **without a terminal**, which is how cron runs commands, apart from those on the ignore list.

## Commands typed in a terminal

Commands run by hand are skipped, so trying a command or running a migration while you work never adds a command or changes a schedule. To watch them too, set `watchInteractive` to `true` in `config/cron-guard.php`.

Some deploy tools and container platforms run commands without a terminal. Their deploy commands are covered by the ignore list below.

## The ignore list

**Settings → Plugins → Cron Guard → Don't watch** lists commands to leave out, one a line, with `*` for any part. To begin with it holds commands that run when someone deploys or sets up a site, not on a schedule:

```
help
up
on
off
install/*
setup/*
migrate/*
project-config/*
plugin/*
clear-caches/*
cache/*
queue/listen
cron-guard/*
```

`queue/listen` is left out because it runs forever rather than on a schedule. `queue/run` is watched.

## Names and arguments

Commands are named as they're typed: `gc`, not `gc/run`. Arguments that change what a command does are part of its name, so each of these has its own schedule:

```
resave/entries --section=news
resave/entries --section=events
feed-me/feeds/queue --id=3
```

Options that only change the output, such as `--interactive`, `--color` and `--verbose`, are left out of the name. The ignore list matches the command without its arguments.
