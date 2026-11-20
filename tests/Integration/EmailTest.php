<?php

namespace suprim\cronguard\tests\Integration;

use Craft;
use craft\helpers\FileHelper;
use suprim\cronguard\events\AlertEvent;
use suprim\cronguard\tests\TestCase;

/**
 * Alert emails: Cron Guard’s own HTML, a site’s Twig or MJML template, and falling back when a site template fails.
 * Site templates are written to templates/cron-guard-test-…/ and removed afterwards.
 */
class EmailTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir && is_dir($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function testOwnHtml(): void
    {
        $html = $this->plugin()->alerts->emailHtml($this->event());
        $this->assertStringContainsString('feed-me/feeds/queue --id=3', $html);
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('Last ran fine', $html);
        $this->assertStringContainsString('See every command', $html);
        $this->assertStringContainsString('<!doctype html>', strtolower($html));
    }

    public function testSiteTwigTemplate(): void
    {
        $name = $this->template('alert.twig', '<p>{{ siteName }}: {% for p in problems %}{{ p.label }} {{ p.command }} {{ p.every }}{% endfor %}</p>');
        $this->settings()->emailTemplate = $name;
        $html = $this->plugin()->alerts->emailHtml($this->event());
        $this->assertMatchesRegularExpression('/<p>.+: Failed feed-me\/feeds\/queue --id=3 every day<\/p>/', $html);
    }

    public function testBrokenTemplateFallsBack(): void
    {
        $this->settings()->emailTemplate = $this->template('broken.twig', '{{ nope(');
        $html = $this->plugin()->alerts->emailHtml($this->event());
        $this->assertStringContainsString('See every command', $html, 'Cron Guard’s own email instead.');

        $this->settings()->emailTemplate = 'cron-guard-test-missing/nothing';
        $this->assertStringContainsString('See every command', $this->plugin()->alerts->emailHtml($this->event()));
    }

    public function testMjmlTemplate(): void
    {
        $name = $this->template('alert.mjml', '<mjml><mj-body><mj-section><mj-column><mj-text>{{ problems[0].command }}</mj-text></mj-column></mj-section></mj-body></mjml>');
        $this->settings()->emailTemplate = $name;
        $html = $this->plugin()->alerts->emailHtml($this->event());
        if (!class_exists('Spatie\Mjml\Mjml')) {
            $this->assertStringContainsString('See every command', $html, 'Without spatie/mjml-php, Cron Guard’s own email.');
            $this->markTestSkipped('spatie/mjml-php isn’t installed here, so only the fallback was tested.');
        }
        $this->assertStringContainsString('feed-me/feeds/queue --id=3', $html);
        $this->assertStringNotContainsString('<mj-text>', $html, 'Compiled to HTML.');
    }

    public function testSentWithHtmlAndText(): void
    {
        $messages = [];
        $handler = function(\yii\mail\MailEvent $e) use (&$messages) {
            $messages[] = $e->message;
            $e->isValid = false;
        };
        $mailer = Craft::$app->getMailer();
        $mailer->on(\yii\mail\BaseMailer::EVENT_BEFORE_SEND, $handler);
        \yii\base\Event::off(\suprim\cronguard\services\Alerts::class, \suprim\cronguard\services\Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
        $this->settings()->emails = 'cron-guard-test@example.com';
        $this->settings()->chatWebhook = $this->settings()->webhookUrl = '';
        try {
            $this->plugin()->alerts->sendTest();
        } finally {
            $mailer->off(\yii\mail\BaseMailer::EVENT_BEFORE_SEND, $handler);
        }
        $this->assertCount(1, $messages);
        $source = $messages[0]->toString();
        $this->assertStringContainsString('text/plain', $source);
        $this->assertStringContainsString('text/html', $source);
    }

    private function event(): AlertEvent
    {
        $runs = $this->every(86400, 5);
        $runs[0]['exitCode'] = 1;
        $runs[0]['error'] = 'Timed out';
        $id = $this->command($runs, 'x');
        \craft\helpers\Db::update('{{%cronguard_commands}}', ['command' => 'feed-me/feeds/queue --id=3 ' . $id], ['id' => $id]);
        $status = $this->statusOf($id);
        $status['command'] = 'feed-me/feeds/queue --id=3';
        $status['reminder'] = false;

        return new AlertEvent(['problems' => [$status], 'subject' => 'Subject', 'text' => 'Text']);
    }

    private function template(string $file, string $contents): string
    {
        $folder = 'cron-guard-test-' . bin2hex(random_bytes(3));
        $this->dir = Craft::$app->getPath()->getSiteTemplatesPath() . "/$folder";
        FileHelper::writeToFile("$this->dir/$file", $contents);

        return "$folder/$file";
    }
}
