<?php

namespace suprim\cronguard\tests\Integration;

use suprim\cronguard\services\Checker;
use suprim\cronguard\tests\TestCase;

/**
 * Schedules are learnt from runs, and a command is reported missed only when it has one.
 */
class ScheduleTest extends TestCase
{
    public function testLearnsAfterFourRuns(): void
    {
        $id = $this->command($this->every(300, 3));
        $this->assertSame(Checker::LEARNING, $this->statusOf($id)['state']);

        $id = $this->command($this->every(300, 4));
        $status = $this->statusOf($id);
        $this->assertSame(Checker::OK, $status['state']);
        $this->assertSame(300, $status['interval']);
        $this->assertTrue($status['learnt']);
    }

    public function testToleratesJitter(): void
    {
        $id = $this->command([['ago' => 10], ['ago' => 3640], ['ago' => 7180], ['ago' => 10850], ['ago' => 14400]]);
        $this->assertEqualsWithDelta(3600, $this->statusOf($id)['interval'], 120);
    }

    public function testIrregularCommandsAreNeverMissed(): void
    {
        // Deploy-style: runs now and then, with no pattern.
        $id = $this->command([['ago' => 86400 * 9], ['ago' => 86400 * 5], ['ago' => 86400 * 4.9], ['ago' => 3600 * 3], ['ago' => 3600 * 2.9]]);
        $status = $this->statusOf($id);
        $this->assertNull($status['interval']);
        $this->assertSame(Checker::IRREGULAR, $status['state']);
    }

    public function testMissedAfterGrace(): void
    {
        // Every 5 minutes: grace is the 5 minute minimum, so it’s missed after 10 minutes.
        $id = $this->command($this->every(300, 6, 590));
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);

        $id = $this->command($this->every(300, 6, 610));
        $status = $this->statusOf($id);
        $this->assertSame(Checker::OVERDUE, $status['state']);
        $this->assertSame('hasn’t run for 10 minutes; it usually runs every 5 minutes', $status['message']);
    }

    public function testDailyGraceIsTwoHours(): void
    {
        $id = $this->command($this->every(86400, 5, 86400 + 7000));
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);

        $id = $this->command($this->every(86400, 5, 86400 + 7300));
        $this->assertSame(Checker::OVERDUE, $this->statusOf($id)['state']);
        $this->assertStringEndsWith('it usually runs every day', $this->statusOf($id)['message']);
    }

    public function testScheduleFromConfig(): void
    {
        $id = $this->command([['ago' => 3 * 86400]], 'weekly-' . bin2hex(random_bytes(3)));
        $name = $this->statusOf($id)['command'];
        $this->assertSame(Checker::LEARNING, $this->statusOf($id)['state']);

        $this->settings()->schedules = [$name => '1 day'];
        $status = $this->statusOf($id);
        $this->assertSame(86400, $status['interval']);
        $this->assertFalse($status['learnt']);
        $this->assertSame(Checker::OVERDUE, $status['state']);

        $this->settings()->schedules = [$name => '1 week'];
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);
    }

    public function testFailing(): void
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $runs[0]['error'] = 'Couldn’t reach the feed.';
        $id = $this->command($runs);
        $status = $this->statusOf($id);
        $this->assertSame(Checker::FAILING, $status['state']);
        $this->assertSame('failed with exit code 1: Couldn’t reach the feed', $status['message'], 'The error’s own full stop is left out: the message gets one where it’s shown.');
        $text = $this->plugin()->alerts->text(['problems' => [$status], 'recovered' => [], 'stopped' => false]);
        $this->assertStringContainsString('Couldn’t reach the feed. Last ran fine', $text);
        $this->assertStringNotContainsString('..', $text);
    }

    public function testStuck(): void
    {
        $id = $this->command([['ago' => 3605, 'exitCode' => null], ...$this->every(3600, 4, 7200)]);
        $this->assertSame(Checker::STUCK, $this->statusOf($id)['state']);

        // Still within an hour: just running.
        $id = $this->command([['ago' => 1800, 'exitCode' => null], ...$this->every(3600, 4, 5400)]);
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);
    }

    public function testLongCommandsArentStuckUntilFiveTimesTheirUsualTime(): void
    {
        // Usually takes 30 minutes: stuck after 2.5 hours, not after 1.
        $id = $this->command([['ago' => 3700, 'exitCode' => null], ...$this->every(86400, 5, 86400, 1800)]);
        $this->assertSame(Checker::OK, $this->statusOf($id)['state']);
        $id = $this->command([['ago' => 9100, 'exitCode' => null], ...$this->every(86400, 5, 86400, 1800)]);
        $this->assertSame(Checker::STUCK, $this->statusOf($id)['state']);
    }

    public function testSlow(): void
    {
        $runs = $this->every(3600, 7, 10, 20);
        $runs[0]['seconds'] = 200;
        $id = $this->command($runs);
        $status = $this->statusOf($id);
        $this->assertSame(Checker::SLOW, $status['state']);
        $this->assertSame('took 3.3 minutes; it usually takes 20 seconds', $status['message']);

        // Three times slower, but only by seconds: fine.
        $runs = $this->every(3600, 7, 10, 2);
        $runs[0]['seconds'] = 9;
        $this->assertSame(Checker::OK, $this->statusOf($this->command($runs))['state']);
    }

    public function testMuted(): void
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $id = $this->command($runs);
        \craft\helpers\Db::update('{{%cronguard_commands}}', ['muted' => true], ['id' => $id]);
        $this->assertSame(Checker::MUTED, $this->statusOf($id)['state']);
        $this->assertNull($this->plugin()->checker->check([$id]));
    }

    public function testWording(): void
    {
        $checker = $this->plugin()->checker;
        $this->assertSame('45 seconds', $checker->duration(45));
        $this->assertSame('5 minutes', $checker->duration(300));
        $this->assertSame('1 hour', $checker->duration(3600));
        $this->assertSame('1.5 hours', $checker->duration(5400));
        $this->assertSame('1 day', $checker->duration(86400));
        $this->assertSame('every day', $checker->every(86400));
        $this->assertSame('every minute', $checker->every(60));
        $this->assertSame('every 15 minutes', $checker->every(900));
        $this->assertSame('every 7 days', $checker->every(604800));
    }
}
