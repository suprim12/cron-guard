<?php

namespace suprim\cronguard\tests;

use Craft;
use craft\helpers\Db;
use DateTime;
use PHPUnit\Framework\TestCase as BaseTestCase;
use suprim\cronguard\events\AlertEvent;
use suprim\cronguard\models\Settings;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Alerts;
use suprim\cronguard\services\Runs;
use yii\base\Event;

/**
 * Commands made by a test are named `cron-guard-test/…` and removed afterwards, with their runs; alerts are caught
 * instead of sent, and the settings are put back.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var AlertEvent[] */
    protected array $alerts = [];

    /** @var int[] */
    protected array $commandIds = [];

    private Settings $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = clone $this->settings();
        Event::on(Alerts::class, Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
    }

    protected function tearDown(): void
    {
        Event::off(Alerts::class, Alerts::EVENT_BEFORE_SEND, [$this, 'catchAlert']);
        $settings = $this->settings();
        foreach (get_object_vars($this->original) as $name => $value) {
            $settings->$name = $value;
        }
        // Only what this test made.
        Db::delete(Runs::TABLE_COMMANDS, ['id' => $this->commandIds]);
        Craft::$app->getCache()->delete('cron-guard:badge');
        parent::tearDown();
    }

    public function catchAlert(AlertEvent $event): void
    {
        $this->alerts[] = $event;
        $event->handled = true;
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * A command with runs started at the given times (seconds before $now), each lasting $seconds and ending with
     * $exitCode; null exit code leaves it unfinished.
     *
     * @param array<int, array{ago: int, seconds?: int, exitCode?: int|null, error?: string}> $runs
     */
    protected function command(array $runs, string $name = ''): int
    {
        $name = 'cron-guard-test/' . ($name ?: bin2hex(random_bytes(4)));
        Db::insert(Runs::TABLE_COMMANDS, ['command' => $name, 'route' => $name]);
        $id = (int)Craft::$app->getDb()->getLastInsertID();
        $this->commandIds[] = $id;
        foreach ($runs as $run) {
            $this->addRun($id, $run);
        }

        return $id;
    }

    /**
     * @param array{ago: int, seconds?: int, exitCode?: int|null, error?: string, pid?: int, host?: string} $run
     */
    protected function addRun(int $commandId, array $run, ?int $now = null): void
    {
        $now ??= time();
        $started = $now - $run['ago'];
        $seconds = $run['seconds'] ?? 2;
        $exitCode = array_key_exists('exitCode', $run) ? $run['exitCode'] : 0;
        Db::insert(Runs::TABLE_RUNS, [
            'commandId' => $commandId,
            'startedAt' => Db::prepareDateForDb(new DateTime("@$started")),
            'finishedAt' => $exitCode === null ? null : Db::prepareDateForDb(new DateTime('@' . ($started + $seconds))),
            'durationMs' => $exitCode === null ? null : $seconds * 1000,
            'exitCode' => $exitCode,
            'error' => $run['error'] ?? null,
        ] + array_intersect_key($run, ['pid' => true, 'host' => true]));
    }

    /**
     * Runs every $every seconds, the latest $latest seconds ago.
     *
     * @return array<int, array{ago: int, seconds?: int}>
     */
    protected function every(int $every, int $count, int $latest = 10, int $seconds = 2): array
    {
        return array_map(fn($i) => ['ago' => $latest + $i * $every, 'seconds' => $seconds], range(0, $count - 1));
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusOf(int $commandId, ?int $now = null): array
    {
        foreach ($this->plugin()->checker->statuses($now) as $status) {
            if ($status['id'] === $commandId) {
                return $status;
            }
        }
        $this->fail("No status for command $commandId.");
    }
}
