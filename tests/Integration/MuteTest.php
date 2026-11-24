<?php

namespace suprim\cronguard\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\tests\TestCase;

/**
 * Muting for a while, and `exec` not being watched.
 */
class MuteTest extends TestCase
{
    private function requireColumn(): void
    {
        if (!Craft::$app->getDb()->columnExists(Runs::TABLE_COMMANDS, 'mutedUntil')) {
            $this->markTestSkipped('Run the 1.1.0 migration first.');
        }
    }

    private function failing(): int
    {
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;

        return $this->command($runs);
    }

    public function testMutedForAWhileIsWatchedAgainAfterwards(): void
    {
        $this->requireColumn();
        $id = $this->failing();
        $until = time() + 3600;
        Db::update(Runs::TABLE_COMMANDS, ['muted' => true, 'mutedUntil' => Db::prepareDateForDb(new DateTime("@$until"))], ['id' => $id]);
        $checker = $this->plugin()->checker;

        $status = $this->statusOf($id);
        $this->assertSame(Checker::MUTED, $status['state']);
        $this->assertSame($until, $status['mutedUntil']);
        $this->assertNull($checker->check([$id]), 'Nothing is sent while it’s muted.');

        $later = $until + 60;
        $this->assertSame(Checker::FAILING, $this->statusOf($id, $later)['state'], 'Watched again once the time is up.');
        $alert = $checker->check([$id], $later);
        $this->assertNotNull($alert, 'And a problem still there is sent.');
        $row = (new Query())->from(Runs::TABLE_COMMANDS)->where(['id' => $id])->one();
        $this->assertFalse((bool)$row['muted'], 'The mute is turned off.');
        $this->assertNull($row['mutedUntil']);
    }

    public function testMutedUntilTurnedOffStaysMuted(): void
    {
        $id = $this->failing();
        Db::update(Runs::TABLE_COMMANDS, ['muted' => true], ['id' => $id]);
        $this->assertSame(Checker::MUTED, $this->statusOf($id, time() + 365 * 86400)['state']);
        $this->assertNull($this->statusOf($id)['mutedUntil']);
    }

    public function testExecIsNeverWatched(): void
    {
        $runs = $this->plugin()->runs;
        $this->assertTrue($runs->isIgnored(['exec', 'exec/exec'], Runs::NEVER_WATCHED));
        $this->assertFalse($runs->isIgnored(['execute-feeds', 'execute-feeds/index'], Runs::NEVER_WATCHED));
    }
}
