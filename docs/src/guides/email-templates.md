# Email Templates

Alert emails are sent as HTML with a plain-text copy. Cron Guard's own email is built with [MJML](https://mjml.io), so it looks right in Gmail, Outlook and Apple Mail. To match your agency's or client's branding, use a template of your own.

## Use your own template

Put a template in your site's `templates/` folder and point `emailTemplate` at it in `config/cron-guard.php`:

```php
<?php
return [
    'emailTemplate' => '_emails/cron-guard',
];
```

It's rendered like any site template, with these variables:

| Variable | |
|---|---|
| `subject` | The email's subject, such as `db/backup hasn't run on Green Grocer`. |
| `preview` | A one-line summary, for the inbox preview. |
| `problems` | Commands with a problem. Each has `command`, `state` (`failing`, `stuck`, `overlapping`, `overdue`, `slow` or `memory`), `label` (Failed, Stuck, Overlapping, Missed, Slow or Memory), `warning` (`true` for slow and memory), `message`, `reminder` (`true` when it was sent before and is still there), `every` (such as “every day”, or `null`) `lastOkAgo` (such as “1 day”, or `null`) and `log` (the last few errors a failed run logged, a line each). |
| `recovered` | Commands back to normal, with the same keys. |
| `stopped` | `true` when every scheduled command is late at once: cron has most likely stopped. |
| `test` | `true` for a test alert. |
| `siteName` | The site's name. |
| `url` | The address of **Utilities → Cron Guard**. |
| `text` | The plain-text version of the alert. |

A minimal template:

```twig
<h1>{{ subject }}</h1>
{% for item in problems %}
  <p><strong>{{ item.label }}</strong> <code>{{ item.command }}</code><br>{{ item.message|ucfirst }}.</p>
{% endfor %}
{% for item in recovered %}
  <p>Back to normal: <code>{{ item.command }}</code></p>
{% endfor %}
<p><a href="{{ url }}">See every command</a></p>
```

If your template has an error, the alert is still sent with Cron Guard's own email, and the error is written to Craft's log.

## Templates written in MJML

There are two ways to use MJML.

**Compile it when you build the site (recommended).** Write the template in MJML with Twig inside it, compile it to HTML with the MJML CLI as part of your front-end build, and point `emailTemplate` at the result. Put Twig tags that sit between MJML elements, such as `{% for %}` and `{% if %}`, in `<mj-raw>`:

```xml
<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-text font-size="20px">{{ subject }}</mj-text>
        <mj-raw>{% for item in problems %}</mj-raw>
        <mj-text><b>{{ item.label }}</b> {{ item.command }}: {{ item.message }}</mj-text>
        <mj-raw>{% endfor %}</mj-raw>
        <mj-button href="{{ url }}">See every command</mj-button>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
```

```bash
npx mjml templates/_emails/cron-guard.mjml -o templates/_emails/cron-guard.twig
```

**Or compile it as each alert is sent.** Point `emailTemplate` at the `.mjml` file itself (`'_emails/cron-guard.mjml'`). Cron Guard renders the Twig, then compiles the MJML with [spatie/mjml-php](https://github.com/spatie/mjml-php), which runs MJML with Node. The server needs both:

```bash
composer require spatie/mjml-php
npm install mjml
```

Without them, alerts fall back to Cron Guard's own email and the reason is logged.

## Sending through Craft

Emails go through Craft's mailer and its settings under **Settings → Email**, so the transport, the From address and any mailer plugins all apply. Test with **Send a test alert** on the settings page.
