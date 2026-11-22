<?php

namespace suprim\cronguard\tests\Integration;

use craft\db\Query;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use suprim\cronguard\services\Alerts;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\tests\TestCase;
use yii\base\Event;

/**
 * Killed runs, runs still going, weekday schedules, flapping, alerts that couldn’t be delivered, and pruning.
 */
class ReviewFixesTest extends TestCase
{
    public function testKilledRunsCountAsFailed(): void
    {
        // Killed 20 minutes ago, and a newer run has started since.
        $id = $this->command([['ago' => 30, 'exitCode' => null], ['ago' => 1200, 'exitCode' => null], ...$this->every(60, 5, 1260)]);
        $this->plugin()->checker->closeAbandoned(time());

        $runs = $this->plugin()->checker->recentRuns($id);
        $this->assertNull($runs[0]['exitCode'], 'The newest run is left open.');
        $this->assertSame(Runs::EXIT_ABANDONED, (int)$runs[1]['exitCode']);
        $status = $this->statusOf($id);
        $this->assertSame(Checker::FAILING, $status['state']);
        $this->assertStringStartsWith('stopped before it finished', $status['message']);
    }

    public function testRecentOpenRunsAreLeftAlone(): void
    {
        // Open 3 minutes, overlapping a newer run: it may still be going.
        $id = $this->command([['ago' => 30, 'exitCode' => null], ['ago' => 180, 'exitCode' => null], ...$this->every(300, 5, 600)]);
        $this->plugin()->checker->closeAbandoned(time());
        $this->assertNull($this->plugin()->checker->recentRuns($id)[1]['exitCode']);
    }

    public function testALongRunStillGoingIsntCountedAsKilled(): void
    {
        // A queue run working through a long import for 20 minutes, while cron starts the next ones each minute. Its
        // process is still running here: this one, for the test.
        $id = $this->command([['ago' => 30, 'exitCode' => null], ['ago' => 1200, 'exitCode' => null, 'pid' => getmypid(), 'host' => (string)gethostname()], ...$this->every(60, 5, 1260)]);
        $this->plugin()->checker->closeAbandoned(time());

        $this->assertNull($this->plugin()->checker->recentRuns($id)[1]['exitCode'], 'Still running, so not killed.');
        $this->assertNotSame(Checker::FAILING, $this->statusOf($id)['state']);
    }

    public function testARunWhoseProcessHasGoneIsKilled(): void
    {
        // The same, but its process has gone without finishing the run.
        $id = $this->command([['ago' => 30, 'exitCode' => null], ['ago' => 1200, 'exitCode' => null, 'pid' => 2147483000, 'host' => (string)gethostname()], ...$this->every(60, 5, 1260)]);
        $this->plugin()->checker->closeAbandoned(time());

        $this->assertSame(Runs::EXIT_ABANDONED, (int)$this->plugin()->checker->recentRuns($id)[1]['exitCode']);
    }

    public function testRunsRecordTheirProcess(): void
    {
        $this->assertSame(['pid' => getmypid(), 'host' => (string)gethostname()], $this->plugin()->runs->process());
    }

    public function testARunningCommandIsntMissed(): void
    {
        // Every 5 minutes, and the latest run started 11 minutes ago and is still going.
        $id = $this->command([['ago' => 660, 'exitCode' => null], ...$this->every(300, 5, 960)]);
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);
    }

    public function testWeekdaySchedules(): void
    {
        // Monday to Friday at 2am for four weeks, the last on Friday.
        $friday = strtotime('last friday 02:00');
        $starts = [];
        for ($day = 0; count($starts) < 20; $day++) {
            $time = $friday - $day * 86400;
            if ((int)date('N', $time) <= 5) {
                $starts[] = $time;
            }
        }
        $runs = array_map(fn($t) => ['ago' => time() - $t], $starts);
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;

        $sunday = $friday + 2 * 86400 + 12 * 3600;
        $this->assertSame(Checker::OK, $this->statusOf($id, $sunday)['state'], 'Not missed over the weekend.');
        $tuesday = $friday + 4 * 86400 + 5 * 3600;
        $this->assertSame(Checker::OVERDUE, $this->statusOf($id, $tuesday)['state'], 'Missed when Monday’s run doesn’t happen.');
        $this->assertSame(86400, $this->statusOf($id, $sunday)['interval']);
    }

    public function testFlappingIsntSentEachTime(): void
    {
        $runs = $this->every(60, 6);
        $runs[0]['exitCode'] = 1;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;

        $this->assertNotNull($checker->check([$id]), 'The first failure is sent.');
        $this->addRun($id, ['ago' => 0]);
        $this->assertCount(1, $checker->check([$id])['recovered'], 'So is the fix.');
        $this->addRun($id, ['ago' => -1, 'exitCode' => 1]);
        $this->assertNull($checker->check([$id], time() + 2), 'Failing again within the hour is flapping.');
        $this->addRun($id, ['ago' => -3]);
        $this->assertNull($checker->check([$id], time() + 4), 'And its fix isn’t sent either.');
        $this->assertCount(2, $this->alerts);

        $this->addRun($id, ['ago' => -2 * 3600, 'exitCode' => 1]);
        $this->assertNotNull($checker->check([$id], time() + 2 * 3600 + 5), 'An hour on, it’s sent again.');
    }

    public function testUndeliveredAlertsAreTriedAgain(): void
    {
        Event::off(Alerts::class, Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
        $alerts = $this->plugin()->alerts;
        $alerts->httpHandler = HandlerStack::create(new MockHandler([new Response(500), new Response(200)]));
        $settings = $this->settings();
        $settings->emails = '';
        $settings->chatWebhook = '';
        $settings->webhookUrl = 'https://example.com/hook';
        try {
            $runs = $this->every(300, 5);
            $runs[0]['exitCode'] = 1;
            $id = $this->command($runs);
            $checker = $this->plugin()->checker;

            $this->assertNotNull($checker->check([$id]));
            $this->assertNull($this->alertState($id), 'Not delivered, so not marked as sent.');
            $this->assertNotNull($checker->check([$id]), 'Tried again.');
            $this->assertSame('failing', $this->alertState($id));
        } finally {
            $alerts->httpHandler = null;
        }
    }

    public function testSlowAlertsDontSayItRanFine(): void
    {
        $runs = $this->every(3600, 7, 10, 20);
        $runs[0]['seconds'] = 300;
        $id = $this->command($runs);
        $this->plugin()->checker->check([$id]);
        $this->assertStringNotContainsString('ran fine', $this->alerts[0]->text);
    }

    public function testPruningKeepsCommandsWithLongSchedules(): void
    {
        $this->settings()->keepDays = 30;
        $monthly = $this->command($this->every(31 * 86400, 5, 40 * 86400), 'monthly-' . bin2hex(random_bytes(3)));
        $oneOff = $this->command([['ago' => 45 * 86400]]);
        $this->plugin()->runs->prune();

        $this->assertCount(5, $this->plugin()->checker->recentRuns($monthly), 'A command with a schedule keeps its latest runs.');
        $this->assertFalse((new Query())->from(Runs::TABLE_COMMANDS)->where(['id' => $oneOff])->exists(), 'A one-off with only old runs is forgotten.');
    }

    public function testIgnoreMatchesHowCommandsAreTyped(): void
    {
        $runs = $this->plugin()->runs;
        $this->assertTrue($runs->isIgnored(['migrate', 'migrate/up'], ['migrate/*']));
        $this->assertTrue($runs->isIgnored(['gc', 'gc/run'], ['gc/run']));
        $this->assertFalse($runs->isIgnored(['queue/run'], ['queue/listen', 'queue']));
    }

    private function alertState(int $id): ?string
    {
        $state = (new Query())->select('alertState')->from(Runs::TABLE_COMMANDS)->where(['id' => $id])->scalar();

        return $state === false ? null : $state;
    }
}
