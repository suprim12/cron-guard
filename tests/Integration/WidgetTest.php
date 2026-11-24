<?php

namespace suprim\cronguard\tests\Integration;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\View;
use suprim\cronguard\Plugin;
use suprim\cronguard\services\Checker;
use suprim\cronguard\services\Runs;
use suprim\cronguard\tests\TestCase;
use suprim\cronguard\widgets\CronGuardWidget;

/**
 * The dashboard widget, and the fixes that came with it: a failure after a slow run, and a killed run’s usual time.
 */
class WidgetTest extends TestCase
{
    private mixed $identity;

    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $this->identity = Craft::$app->getUser()->getIdentity();
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity($this->identity);
        parent::tearDown();
    }

    private function signInAdmin(): void
    {
        $admin = User::find()->admin()->status(null)->one();
        if (!$admin) {
            $this->markTestSkipped('No admin user to sign in as.');
        }
        Craft::$app->getUser()->setIdentity($admin);
    }

    public function testRegistered(): void
    {
        $this->assertContains(CronGuardWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes());
    }

    public function testListsCommandsThatNeedALook(): void
    {
        $this->signInAdmin();
        $runs = $this->every(300, 5);
        $runs[0]['exitCode'] = 1;
        $runs[0]['error'] = 'Feed timed out <script>';
        $id = $this->command($runs);
        $this->command($this->every(300, 5));

        $html = (string)(new CronGuardWidget())->getBodyHtml();
        $this->assertMatchesRegularExpression('/(One command needs|\d+ commands need) a look\./', $html);
        $this->assertStringContainsString('utilities/cron-guard?command=' . $id, $html);
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('Feed timed out &lt;script&gt;', $html);
        $this->assertStringContainsString('commands watched', $html);
        $this->assertTrue(CronGuardWidget::isSelectable());
    }

    public function testAllFine(): void
    {
        $this->signInAdmin();
        $ids = array_column(array_filter(Plugin::getInstance()->checker->statuses(), fn($s) => in_array($s['state'], Checker::PROBLEMS, true)), 'id');
        if ($ids) {
            $this->markTestSkipped('This site has commands with problems of its own.');
        }
        $this->command($this->every(300, 5));
        $html = (string)(new CronGuardWidget())->getBodyHtml();
        $this->assertStringContainsString('Every command is running as it should.', $html);
    }

    public function testNotShownWithoutTheUtility(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        $this->assertFalse(CronGuardWidget::isSelectable());
        $this->assertStringContainsString('You need access', (string)(new CronGuardWidget())->getBodyHtml());
    }

    public function testAFailureAfterASlowRunIsSent(): void
    {
        $runs = $this->every(3600, 7, 10, 20);
        $runs[0]['seconds'] = 300;
        $id = $this->command($runs);
        $checker = $this->plugin()->checker;
        $this->assertSame(Checker::SLOW, $checker->check([$id])['problems'][0]['state']);

        $this->addRun($id, ['ago' => 1, 'seconds' => 20]);
        $this->assertNull($checker->check([$id]), 'The next normal run just clears it.');
        $this->addRun($id, ['ago' => -1, 'exitCode' => 1]);
        $alert = $checker->check([$id], time() + 2);
        $this->assertNotNull($alert, 'Failing within the hour of a slow alert isn’t flapping.');
        $this->assertSame(Checker::FAILING, $alert['problems'][0]['state']);
    }

    public function testAKilledRunKeepsTheUsualTime(): void
    {
        // Runs of 20 seconds, two of them 200, and the newest was killed.
        $runs = $this->every(300, 6, 10, 20);
        $runs[2]['seconds'] = 200;
        $runs[3]['seconds'] = 200;
        $runs[0]['exitCode'] = null;
        $id = $this->command($runs);
        Db::update(Runs::TABLE_RUNS, ['exitCode' => Runs::EXIT_ABANDONED], ['commandId' => $id, 'exitCode' => null]);

        // Durations 20, 200, 200, 20, 20: all count, as the killed run has none (median 20, not 110 without the first).
        $this->assertEquals(20, $this->statusOf($id)['usualDuration']);
    }
}
