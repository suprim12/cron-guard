<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\services;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\View;
use suprim\cronguard\events\AlertEvent;
use suprim\cronguard\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Sends alerts by email, to a Slack, Discord or Teams channel, and to a webhook.
 */
class Alerts extends Component
{
    /**
     * Before an alert is sent. Set `$event->handled` to send it your own way instead.
     */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    /** @var callable|null A Guzzle handler for the requests, for tests. */
    public mixed $httpHandler = null;

    /**
     * Sends an alert to every channel set up, and returns what each answered: true, or the error.
     *
     * @param array{problems: array<int, array<string, mixed>>, recovered: array<int, array<string, mixed>>, stopped: bool} $alert
     * @return array<string, true|string>
     */
    public function send(array $alert, bool $test = false): array
    {
        $event = new AlertEvent([
            'problems' => $alert['problems'],
            'recovered' => $alert['recovered'],
            'stopped' => $alert['stopped'],
            'test' => $test,
            'subject' => $this->subject($alert, $test),
            'text' => $this->text($alert, $test),
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);
        if ($event->handled) {
            return ['Your event handler' => true];
        }

        $settings = Plugin::getInstance()->getSettings();
        $results = [];
        if ($emails = $settings->emailList()) {
            $results['Email'] = $this->attempt(fn() => $this->email($emails, $event));
        }
        if ($url = (string)App::parseEnv($settings->chatWebhook)) {
            $results['Chat'] = $this->attempt(fn() => $this->post($url, $this->chatPayload($url, $event)));
        }
        if ($url = (string)App::parseEnv($settings->webhookUrl)) {
            $results['Webhook'] = $this->attempt(fn() => $this->post($url, $this->webhookPayload($event)));
        }
        if (!$results) {
            // Nowhere to send it: the log at least has it.
            Craft::warning($event->subject . "\n" . $event->text, 'cron-guard');
        }
        foreach ($results as $channel => $result) {
            if ($result !== true) {
                Craft::error("Cron Guard couldn’t send an alert by $channel: $result", 'cron-guard');
            }
        }

        return $results;
    }

    /**
     * A test alert, from the settings page.
     *
     * @return array<string, true|string>
     */
    public function sendTest(): array
    {
        return $this->send(['problems' => [], 'recovered' => [], 'stopped' => false], true);
    }

    /**
     * @param array{problems: array<int, array<string, mixed>>, recovered: array<int, array<string, mixed>>, stopped: bool} $alert
     */
    public function subject(array $alert, bool $test = false): string
    {
        $site = $this->siteName();
        if ($test) {
            return "Cron Guard test alert from $site";
        }
        if ($alert['stopped']) {
            return "Cron isn’t running on $site";
        }
        $count = count($alert['problems']);
        if ($count === 1) {
            $problem = $alert['problems'][0];

            return sprintf('%s %s on %s', $problem['command'], $this->stateWord($problem['state']), $site);
        }
        if ($count > 1) {
            return "$count scheduled commands need a look on $site";
        }

        return count($alert['recovered']) === 1
            ? sprintf('%s is back to normal on %s', $alert['recovered'][0]['command'], $site)
            : "Scheduled commands are back to normal on $site";
    }

    /**
     * @param array{problems: array<int, array<string, mixed>>, recovered: array<int, array<string, mixed>>, stopped: bool} $alert
     */
    public function text(array $alert, bool $test = false): string
    {
        if ($test) {
            return "This is a test from Cron Guard on {$this->siteName()}. Alerts about scheduled commands will arrive here.\n\n" . $this->link();
        }
        $checker = Plugin::getInstance()->checker;
        $lines = [];
        if ($alert['stopped']) {
            $lines[] = 'None of the scheduled commands have run when they usually do, so cron itself has most likely stopped: check the server’s crontab, or the scheduler on your host.';
            $lines[] = '';
        }
        foreach ($alert['problems'] as $problem) {
            $line = sprintf('✗ %s %s', $problem['command'], $problem['message']);
            if (!empty($problem['reminder'])) {
                $line .= ' (still)';
            }
            if ($problem['state'] !== Checker::OVERDUE && $problem['state'] !== Checker::SLOW && $problem['lastOk'] !== null) {
                $line .= sprintf('. Last ran fine %s ago.', $checker->duration(time() - $checker->timestamp($problem['lastOk']['startedAt'])));
            } elseif ($problem['state'] !== Checker::OVERDUE && $problem['state'] !== Checker::SLOW) {
                $line .= '.';
            }
            $lines[] = $line;
        }
        foreach ($alert['recovered'] as $recovered) {
            $lines[] = sprintf('✓ %s is running normally again.', $recovered['command']);
        }
        $lines[] = '';
        $lines[] = $this->link();

        return implode("\n", $lines);
    }

    /**
     * @param string[] $emails
     */
    private function email(array $emails, AlertEvent $event): void
    {
        $sent = Craft::$app->getMailer()->compose()
            ->setTo($emails)
            ->setSubject($event->subject)
            ->setTextBody($event->text)
            ->setHtmlBody($this->emailHtml($event))
            ->send();
        if (!$sent) {
            throw new \RuntimeException('Craft’s mailer didn’t send it; check Settings → Email.');
        }
    }

    /**
     * The email’s HTML: the site’s own template when `emailTemplate` is set, or Cron Guard’s. A site template that
     * fails falls back to Cron Guard’s, so the alert still goes out.
     */
    public function emailHtml(AlertEvent $event): string
    {
        $variables = $this->emailVariables($event);
        $view = Craft::$app->getView();
        $template = trim(Plugin::getInstance()->getSettings()->emailTemplate);
        if ($template !== '') {
            try {
                $html = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
                if (str_ends_with(strtolower($template), '.mjml')) {
                    $html = $this->compileMjml($html);
                }

                return $html;
            } catch (Throwable $e) {
                Craft::error("Cron Guard couldn’t use the email template “{$template}”, so sent its own: {$e->getMessage()}", 'cron-guard');
            }
        }

        return $view->renderTemplate('cron-guard/_email', $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * What email templates get: the alert, with each command’s status in words.
     *
     * @return array<string, mixed>
     */
    public function emailVariables(AlertEvent $event): array
    {
        $checker = Plugin::getInstance()->checker;
        $item = fn(array $status) => [
            'command' => $status['command'],
            'state' => $status['state'],
            'label' => match ($status['state']) {
                Checker::FAILING => 'Failed',
                Checker::STUCK => 'Stuck',
                Checker::OVERDUE => 'Missed',
                Checker::SLOW => 'Slow',
                default => 'OK',
            },
            'message' => $status['message'],
            'reminder' => !empty($status['reminder']),
            'every' => $status['interval'] !== null ? $checker->every($status['interval']) : null,
            'lastOkAgo' => !in_array($status['state'], [Checker::OVERDUE, Checker::SLOW], true) && !empty($status['lastOk'])
                ? $checker->duration(time() - $checker->timestamp($status['lastOk']['startedAt']))
                : null,
        ];
        $problems = array_map($item, $event->problems);

        return [
            'subject' => $event->subject,
            'text' => $event->text,
            'preview' => $event->test ? 'A test from Cron Guard.' : implode(' ', array_map(fn($p) => "{$p['command']} {$p['message']}.", $problems)),
            'problems' => $problems,
            'recovered' => array_map($item, $event->recovered),
            'stopped' => $event->stopped,
            'test' => $event->test,
            'siteName' => $this->siteName(),
            'url' => $this->link(),
        ];
    }

    /**
     * Turns MJML into HTML with spatie/mjml-php, which runs the mjml npm package with Node.
     */
    private function compileMjml(string $mjml): string
    {
        $class = 'Spatie\\Mjml\\Mjml';
        if (!class_exists($class)) {
            throw new \RuntimeException('A .mjml template needs spatie/mjml-php (composer require spatie/mjml-php, and npm install mjml). Or compile it to HTML and use that.');
        }

        return $class::new()->toHtml($mjml);
    }

    /**
     * Slack and Teams’ older connectors read `text`, Discord reads `content`, and Teams workflows read an Adaptive Card.
     *
     * @return array<string, mixed>
     */
    private function chatPayload(string $url, AlertEvent $event): array
    {
        $message = "*{$event->subject}*\n{$event->text}";
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (str_ends_with($host, 'discord.com') || str_ends_with($host, 'discordapp.com')) {
            return ['content' => mb_substr(str_replace('*', '**', $message), 0, 2000)];
        }
        if (str_ends_with($host, '.logic.azure.com') || str_ends_with($host, '.powerplatform.com') || str_ends_with($host, '.powerautomate.com')) {
            return [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        // A block a line: Teams joins lines within a block into one paragraph.
                        'body' => [
                            ['type' => 'TextBlock', 'text' => $event->subject, 'weight' => 'Bolder', 'wrap' => true],
                            ...array_map(
                                fn($line) => ['type' => 'TextBlock', 'text' => $line, 'wrap' => true, 'spacing' => 'Small'],
                                array_values(array_filter(explode("\n", $event->text), fn($line) => trim($line) !== '')),
                            ),
                        ],
                    ],
                ]],
            ];
        }

        return ['text' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(AlertEvent $event): array
    {
        $item = fn(array $status) => [
            'command' => $status['command'],
            'state' => $status['state'],
            'message' => $status['message'],
            'reminder' => !empty($status['reminder']),
            'lastStartedAt' => $status['lastStart'] ? gmdate('c', $status['lastStart']) : null,
            'intervalSeconds' => $status['interval'],
        ];

        return [
            'site' => $this->siteName(),
            'url' => $this->link(),
            'test' => $event->test,
            'stopped' => $event->stopped,
            'subject' => $event->subject,
            'text' => $event->text,
            'problems' => array_map($item, $event->problems),
            'recovered' => array_map($item, $event->recovered),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $url, array $payload): void
    {
        $config = ['timeout' => 10, 'connect_timeout' => 5];
        if ($this->httpHandler !== null) {
            $config['handler'] = $this->httpHandler;
        }
        Craft::createGuzzleClient($config)->post($url, ['json' => $payload]);
    }

    /**
     * @return true|string
     */
    private function attempt(callable $send): bool|string
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function stateWord(string $state): string
    {
        return match ($state) {
            Checker::FAILING => 'failed',
            Checker::STUCK => 'is stuck',
            Checker::OVERDUE => 'hasn’t run',
            Checker::SLOW => 'ran slow',
            default => $state,
        };
    }

    private function siteName(): string
    {
        return (string)(Craft::$app->getSystemName() ?: parse_url(UrlHelper::baseSiteUrl(), PHP_URL_HOST));
    }

    private function link(): string
    {
        return UrlHelper::cpUrl('utilities/cron-guard');
    }
}
