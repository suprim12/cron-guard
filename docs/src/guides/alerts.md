# Alerts

## When they're sent

- **A new problem**, once.
- **The same problem again** after 24 hours if it's still there (`remindHours`; `0` sends it once).
- **Back to normal** when a failed, missed or stuck command runs fine again. Slow runs don't get one: the next normal run just clears them.

Problems found in the same check go out together as one alert. A **muted** command never sends one.

## Email

Sent with Craft's email settings to every address under **Email**. The subject says what happened, such as `db/backup hasn't run on Green Grocer`, and the body lists each problem with a link to **Utilities → Cron Guard**:

```
✗ feed-me/feeds/queue --id=3 failed with exit code 1: cURL error 28: Operation timed out after 30001 milliseconds. Last ran fine 1 day ago.
✓ db/backup is running normally again.

https://example.com/admin/utilities/cron-guard
```

## Slack, Discord and Microsoft Teams

Paste the channel's incoming webhook URL under **Slack, Discord or Teams**. Cron Guard sends the subject in bold and the same lines as the email, in the format each service expects, which it works out from the URL.

- **Slack:** create an app with *Incoming Webhooks* turned on and add a webhook to the channel.
- **Discord:** in the channel's settings, choose **Integrations → Webhooks → New Webhook** and copy its URL.
- **Teams:** in the channel, choose **Workflows** and the *Post to a channel when a webhook request is received* template, then copy its URL. Alerts arrive as a card. Older Office 365 connector URLs work too.

## Webhook

Alerts are posted to the **Webhook** URL as JSON:

```json
{
  "site": "Green Grocer",
  "url": "https://example.com/admin/utilities/cron-guard",
  "test": false,
  "stopped": false,
  "subject": "feed-me/feeds/queue --id=3 failed on Green Grocer",
  "text": "✗ feed-me/feeds/queue --id=3 failed with exit code 1: …",
  "problems": [
    {
      "command": "feed-me/feeds/queue --id=3",
      "state": "failing",
      "message": "failed with exit code 1: cURL error 28: …",
      "reminder": false,
      "lastStartedAt": "2026-11-21T02:00:03+00:00",
      "intervalSeconds": 86400
    }
  ],
  "recovered": []
}
```

`state` is one of `failing`, `overdue`, `stuck` or `slow`. `stopped` is `true` when every scheduled command is late at once.

## Test alerts

**Send a test alert** on the settings page, or `php craft cron-guard/check/test-alert`, sends a test to every channel that's saved and shows what each one answered.

## Sending them somewhere else

See [Events](./events) to send alerts your own way, such as to a ticketing system.
