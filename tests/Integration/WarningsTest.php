<?php

namespace suprim\cronguard\tests\Integration;

use Craft;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\tests\TestCase;

/**
 * Commands near their memory limit, overlapping runs, what a failed run logged in its alert, and slow runs judged
 * while the next one runs.
 */
class WarningsTest extends TestCase
{
    private const MB = 1048576;

    private function requireColumns(): void
    {
        if (!Craft::$app->getDb()->columnExists(Runs::TABLE_RUNS, 'memoryLimit')) {
            $this->markTestSkipped('Run the 1.1.0 migrations first.');
        }
    }

    public function testNearItsMemoryLimit(): void
    {
        $this->requireColumns();
        $runs = $this->every(3600, 5);
        $runs[0] += ['memory' => 470 * self::MB, 'memoryLimit' => 512 * self::MB];
        $id = $this->command($runs);

        $status = $this->statusOf($id);
        $this->assertSame(Checker::MEMORY, $status['state']);
        $this->assertSame('used 470 MB of its 512 MB memory limit: it’ll be killed if it needs much more', $status['message']);

        $checker = $this->plugin()->checker;
        $alert = $checker->check([$id]);
        $this->assertSame('cron-guard-test/' . substr($status['command'], 16) . ' is near its memory limit on ' . Craft::$app->getSystemName(), $this->alerts[0]->subject);
        $this->assertStringNotContainsString('ran fine', $this->alerts[0]->text);

        // A run well within it clears it, without a “back to normal”.
        $this->addRun($id, ['ago' => 1, 'memory' => 100 * self::MB, 'memoryLimit' => 512 * self::MB]);
        $this->assertNull($checker->check([$id]));
        $this->assertNotNull($alert);
    }

    public function testMemoryWarningsCanBeTurnedOff(): void
    {
        $this->requireColumns();
        $this->settings()->memoryWarnPercent = 0;
        $runs = $this->every(3600, 5);
        $runs[0] += ['memory' => 500 * self::MB, 'memoryLimit' => 512 * self::MB];
        $this->assertSame(Checker::OK, $this->statusOf($this->command($runs))['state']);
    }

    public function testNoLimitNoWarning(): void
    {
        $this->requireColumns();
        $runs = $this->every(3600, 5);
        $runs[0] += ['memory' => 900 * self::MB];
        $this->assertSame(Checker::OK, $this->statusOf($this->command($runs))['state']);
    }

    public function testMemoryLimitInBytes(): void
    {
        $old = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '512M');
            $this->assertSame(512 * self::MB, $this->plugin()->runs->memoryLimit());
            ini_set('memory_limit', '-1');
            $this->assertNull($this->plugin()->runs->memoryLimit());
        } finally {
            ini_set('memory_limit', $old);
        }
    }

    /**
     * Two runs still going, both on this server: this process, for the test.
     *
     * @return array<int, array<string, mixed>>
     */
    private function twoGoing(int $every = 60): array
    {
        $alive = ['exitCode' => null, 'pid' => getmypid(), 'host' => (string)gethostname()];

        return [['ago' => 90] + $alive, ['ago' => 150] + $alive, ...$this->every($every, 5, 210)];
    }

    public function testOverlapping(): void
    {
        $id = $this->command($this->twoGoing());
        $status = $this->statusOf($id);
        $this->assertSame(Checker::OVERLAPPING, $status['state']);
        $this->assertSame(2, $status['copies']);
        $this->assertStringStartsWith('is running 2 times at once, the oldest started 2.5 minutes ago', $status['message']);

        $checker = $this->plugin()->checker;
        $this->assertSame(Checker::OVERLAPPING, $checker->check([$id])['problems'][0]['state']);
        Craft::$app->getDb()->createCommand()->update(Runs::TABLE_RUNS, ['exitCode' => 0, 'durationMs' => 2000], ['commandId' => $id, 'exitCode' => null])->execute();
        $this->assertCount(1, $checker->check([$id])['recovered'], 'Back to normal once one is left.');
    }

    public function testQueueRunsMayOverlap(): void
    {
        $id = $this->command($this->twoGoing());
        $this->settings()->allowOverlap = ['cron-guard-test/*'];
        $this->assertNotSame(Checker::OVERLAPPING, $this->statusOf($id)['state']);
    }

    public function testRunsThatHaveStoppedDontOverlap(): void
    {
        $runs = $this->twoGoing();
        $runs[1]['pid'] = 2147483000;
        $this->assertNotSame(Checker::OVERLAPPING, $this->statusOf($this->command($runs))['state']);
    }

    public function testJustStartedIsntOverlapping(): void
    {
        // The next run started seconds ago, while the last one finishes: normal for a command every minute.
        $runs = $this->twoGoing();
        $runs[0]['ago'] = 10;
        $this->assertNotSame(Checker::OVERLAPPING, $this->statusOf($this->command($runs))['state']);
    }

    public function testAlertsSayWhatWasLogged(): void
    {
        $this->requireColumns();
        $runs = $this->every(300, 5);
        $runs[0] += ['exitCode' => 1, 'error' => 'Import failed', 'log' => json_encode([
            ['level' => 'warning', 'category' => 'feed', 'message' => 'Retrying'],
            ['level' => 'error', 'category' => 'feed', 'message' => "Element 4512 failed validation:\n Title cannot be blank"],
        ])];
        $id = $this->command($runs);
        $this->plugin()->checker->check([$id]);

        $this->assertStringContainsString('    Logged: Element 4512 failed validation: Title cannot be blank', $this->alerts[0]->text);
        $this->assertStringNotContainsString('Retrying', $this->alerts[0]->text, 'Errors, when there are some, rather than warnings.');
        $html = $this->plugin()->alerts->emailHtml($this->alerts[0]);
        $this->assertStringContainsString('Element 4512 failed validation', $html);
    }

    public function testALongLogStillFitsItsColumn(): void
    {
        // 20 messages of 1,000 characters, four bytes each: 80 KB, over MySQL’s 64 KB for a text column.
        $entries = array_fill(0, 20, ['level' => 'error', 'category' => 'feed', 'message' => str_repeat('😀', 1000)]);
        $json = \suprim\cronguard\log\RunLogTarget::encode($entries);
        $this->assertLessThanOrEqual(\suprim\cronguard\log\RunLogTarget::MAX_BYTES, strlen($json));
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'The latest are kept.');

        // One message too long on its own is cut, still valid UTF-8.
        $one = \suprim\cronguard\log\RunLogTarget::encode([['level' => 'error', 'category' => 'x', 'message' => str_repeat('😀', 9000)]]);
        $this->assertLessThanOrEqual(\suprim\cronguard\log\RunLogTarget::MAX_BYTES, strlen($one));
        $this->assertIsArray(json_decode($one, true));
    }

    public function testSlowStaysSlowWhileTheNextRunRuns(): void
    {
        $runs = $this->every(3600, 7, 10, 20);
        $runs[0]['seconds'] = 300;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;
        $this->assertSame(Checker::SLOW, $checker->check([$id])['problems'][0]['state']);

        // The next run starts; a check while it runs doesn’t clear it, so its finishing slow again isn’t a new alert.
        $this->addRun($id, ['ago' => 1, 'exitCode' => null]);
        $this->assertSame(Checker::SLOW, $this->statusOf($id)['state']);
        $this->assertNull($checker->check([$id]));
    }
}
