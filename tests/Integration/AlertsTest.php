<?php

namespace suprim\cronguard\tests\Integration;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\cronguard\services\Alerts;
use suprim\cronguard\services\Checker;
use suprim\cronguard\tests\TestCase;

/**
 * A problem is sent once, again after `remindHours`, and “back to normal” when it goes away.
 */
class AlertsTest extends TestCase
{
    public function testSendsAProblemOnce(): void
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;

        $alert = $checker->check([$id]);
        $this->assertNotNull($alert);
        $this->assertCount(1, $this->alerts);
        $this->assertSame(Checker::FAILING, $this->alerts[0]->problems[0]['state']);
        $this->assertStringContainsString('failed on', $this->alerts[0]->subject);
        $this->assertStringContainsString('✗ cron-guard-test/', $this->alerts[0]->text);
        $this->assertStringContainsString('Last ran fine', $this->alerts[0]->text);

        $this->assertNull($checker->check([$id]), 'Not sent again.');
        $this->assertCount(1, $this->alerts);
    }

    public function testRemindsAfterRemindHours(): void
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;
        $checker->check([$id]);

        $this->assertNull($checker->check([$id], time() + 23 * 3600));
        // Runs every 5 minutes, so a day on it’s missed too; failing still wins.
        $alert = $checker->check([$id], time() + 25 * 3600);
        $this->assertNotNull($alert);
        $this->assertTrue($alert['problems'][0]['reminder']);
        $this->assertStringContainsString('(still)', $this->alerts[1]->text);

        $this->settings()->remindHours = 0;
        $this->assertNull($checker->check([$id], time() + 60 * 3600));
    }

    public function testRecovered(): void
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;
        $checker->check([$id]);

        $this->addRun($id, ['ago' => 1]);
        $alert = $checker->check([$id]);
        $this->assertNotNull($alert);
        $this->assertSame([], $alert['problems']);
        $this->assertCount(1, $alert['recovered']);
        $this->assertStringContainsString('back to normal', $this->alerts[1]->subject);
        $this->assertStringContainsString('✓ cron-guard-test/', $this->alerts[1]->text);
    }

    public function testOneAlertForSeveralProblems(): void
    {
        $failing = $this->every(300, 5);
        $failing[0]['exitCode'] = 2;
        $ids = [$this->command($failing), $this->command($this->every(300, 6, 900))];

        $alert = $this->plugin()->checker->check($ids);
        $this->assertCount(2, $alert['problems']);
        $this->assertCount(1, $this->alerts);
        $this->assertStringStartsWith('2 scheduled commands need a look', $this->alerts[0]->subject);
    }

    public function testCronStopped(): void
    {
        // The site’s own commands count too, so only when it has none of its own with a schedule.
        $others = array_filter($this->plugin()->checker->statuses(), fn($s) => $s['interval'] !== null);
        if ($others) {
            $this->markTestSkipped('This site has commands of its own with a schedule.');
        }
        $ids = [$this->command($this->every(300, 6, 3600)), $this->command($this->every(3600, 5, 3 * 3600))];
        $alert = $this->plugin()->checker->check($ids);
        $this->assertTrue($alert['stopped']);
        $this->assertStringStartsWith('Cron isn’t running on', $this->alerts[0]->subject);
        $this->assertStringContainsString('cron itself has most likely stopped', $this->alerts[0]->text);
    }

    public function testNotStoppedWhenOneStillRuns(): void
    {
        $ids = [$this->command($this->every(300, 6, 3600)), $this->command($this->every(3600, 5, 60))];
        $alert = $this->plugin()->checker->check($ids);
        $this->assertFalse($alert['stopped']);
    }

    public function testSlowIsNotFollowedByBackToNormal(): void
    {
        $runs = $this->every(3600, 7, 10, 20);
        $runs[0]['seconds'] = 300;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;
        $this->assertSame(Checker::SLOW, $checker->check([$id])['problems'][0]['state']);

        $this->addRun($id, ['ago' => 1, 'seconds' => 20]);
        $this->assertNull($checker->check([$id]));
    }

    public function testNoChannelsWritesTheLog(): void
    {
        $this->settings()->emails = '';
        $this->settings()->chatWebhook = '';
        $this->settings()->webhookUrl = '';
        // Without a listener handling it, nothing is set up to send to, so it goes to the log.
        \yii\base\Event::off(Alerts::class, Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
        $results = $this->plugin()->alerts->sendTest();
        $this->assertSame([], $results);
    }

    public function testChatAndWebhookPayloads(): void
    {
        \yii\base\Event::off(Alerts::class, Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200), new Response(500), new Response(200), new Response(202)]));
        $stack->push(Middleware::history($requests));
        $alerts = $this->plugin()->alerts;
        $alerts->httpHandler = $stack;
        $settings = $this->settings();
        $settings->emails = '';
        $settings->chatWebhook = 'https://hooks.slack.com/services/T0/B0/x';
        $settings->webhookUrl = 'https://example.com/hook';

        try {
            $runs = $this->every(300, 5);
            $runs[0]['exitCode'] = 1;
            $runs[0]['error'] = 'Feed said <!channel> & <https://evil.example|click>';
            $id = $this->command($runs);
            $this->plugin()->checker->check([$id]);

            $this->assertCount(2, $requests);
            $slack = json_decode((string)$requests[0]['request']->getBody(), true);
            $this->assertStringContainsString('failed on', $slack['text']);
            $this->assertStringContainsString('Feed said &lt;!channel&gt; &amp; &lt;https://evil.example|click&gt;', $slack['text'], 'Can’t ping the channel or add a link.');
            $hook = json_decode((string)$requests[1]['request']->getBody(), true);
            $this->assertSame('failing', $hook['problems'][0]['state']);
            $this->assertSame(300, $hook['problems'][0]['intervalSeconds']);
            $this->assertFalse($hook['test']);

            // Discord reads content, and a channel that answers with an error is reported.
            $settings->chatWebhook = 'https://discord.com/api/webhooks/1/x';
            $results = $alerts->sendTest();
            $discord = json_decode((string)$requests[2]['request']->getBody(), true);
            $this->assertArrayHasKey('content', $discord);
            $this->assertSame(['parse' => []], $discord['allowed_mentions'], 'Can’t ping @everyone.');
            $this->assertNotTrue($results['Chat']);
            $this->assertTrue($results['Webhook']);

            // Teams workflows read an Adaptive Card.
            $settings->chatWebhook = 'https://prod-01.westus.logic.azure.com/workflows/x/triggers/manual/paths/invoke';
            $settings->webhookUrl = '';
            $alerts->sendTest();
            $card = json_decode((string)$requests[4]['request']->getBody(), true);
            $this->assertSame('application/vnd.microsoft.card.adaptive', $card['attachments'][0]['contentType']);
            $this->assertStringContainsString('test alert', $card['attachments'][0]['content']['body'][0]['text']);
        } finally {
            $alerts->httpHandler = null;
        }
    }
}
