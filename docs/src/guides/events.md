# Events

## `Alerts::EVENT_BEFORE_SEND`

Triggered before an alert is sent, with an `AlertEvent`. Use it to send alerts somewhere else, or to change their text. Set `$event->handled = true` to stop Cron Guard sending the alert itself.

```php
use suprim\cronguard\events\AlertEvent;
use suprim\cronguard\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_SEND, function(AlertEvent $event) {
    foreach ($event->problems as $problem) {
        // $problem['command'], $problem['state'], $problem['message'], $problem['reminder']
    }
});
```

| Property | |
|---|---|
| `problems` | Commands with a new problem, or one still there (`reminder` is `true`). Each has `command`, `state`, `message`, `interval` (seconds, or `null`), `lastStart` (a timestamp) and `reminder`. |
| `recovered` | Commands back to normal. |
| `stopped` | `true` when every scheduled command is late at once: cron has most likely stopped. |
| `subject`, `text` | The alert as emails and chat messages show it. Change them to change what's sent. |
| `test` | `true` for a test alert. |
| `handled` | Set to `true` to stop Cron Guard sending it. |
