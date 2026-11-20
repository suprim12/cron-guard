# Health URL

A URL for uptime monitors such as UptimeRobot, Better Stack or Oh Dear. It answers **200** while every command is fine and **503** when one isn't, so the monitor can alert you on the status code alone.

Calling it also runs the check, so a monitor that calls it every few minutes means missed commands are found even on a site with no visitors.

## Turn it on

1. Go to **Settings → Plugins → Cron Guard** and set a **Token**, ideally from an environment variable such as `$CRON_GUARD_TOKEN`. Generate one with `openssl rand -hex 24`.
2. Point your monitor at:

```
https://example.com/actions/cron-guard/health?cronGuardToken=YOUR_TOKEN
```

The token can also be sent as an `X-Cron-Guard-Token` header. Without a token set, or with the wrong one, the URL answers 404.

## What it returns

```json
{
  "status": "problem",
  "problems": [
    { "command": "db/backup", "state": "overdue", "message": "hasn’t run for 1.2 days; it usually runs every day" }
  ],
  "commands": [
    { "command": "db/backup", "state": "overdue", "lastStartedAt": "2026-11-20T00:01:00+00:00" },
    { "command": "queue/run", "state": "ok", "lastStartedAt": "2026-11-21T03:41:00+00:00" }
  ]
}
```

The response is never cached.
