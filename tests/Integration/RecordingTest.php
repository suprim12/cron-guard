<?php

namespace suprim\cronguard\tests\Integration;

use craft\db\Query;
use craft\helpers\Db;
use suprim\cronguard\services\Runs;
use suprim\cronguard\tests\TestCase;

/**
 * Real `php craft` runs, without a terminal as cron runs them, are recorded with how they ended, and recording
 * never changes a command’s exit code.
 */
class RecordingTest extends TestCase
{
    /** @var string[] */
    private array $names = [];

    protected function tearDown(): void
    {
        if ($this->names) {
            Db::delete(Runs::TABLE_COMMANDS, ['command' => $this->names]);
        }
        parent::tearDown();
    }

    public function testRecordsASuccessfulRun(): void
    {
        $this->names[] = 'graphql/print-schema';
        $this->skipIfRecorded('graphql/print-schema');
        $this->assertSame(0, $this->craft('graphql/print-schema'));

        $run = $this->lastRun('graphql/print-schema');
        $this->assertSame(0, (int)$run['exitCode']);
        $this->assertNotNull($run['finishedAt']);
        $this->assertGreaterThan(0, (int)$run['memory']);
    }

    public function testRecordsAFailedRun(): void
    {
        $name = 'users/activation-url cron-guard-test-nobody@example.com';
        $this->names[] = $name;
        $this->assertSame(1, $this->craft($name), 'The exit code is the command’s own, even when an alert is sent as it ends.');

        $run = $this->lastRun($name);
        $this->assertSame(1, (int)$run['exitCode']);
        $command = (new Query())->from(Runs::TABLE_COMMANDS)->where(['command' => $name])->one();
        $this->assertSame('users/activation-url', $command['route']);
        $this->assertSame('failing', $command['alertState'], 'Checked as it ended.');
    }

    public function testRecordsAnException(): void
    {
        // Run without a category group and without a terminal, it throws.
        $name = 'entrify/categories';
        $this->names[] = $name;
        $this->skipIfRecorded($name);
        $this->assertSame(1, $this->craft('entrify/categories --interactive=0'));

        $run = $this->lastRun($name);
        $this->assertSame(1, (int)$run['exitCode']);
        // Its message, which Craft versions word differently.
        $this->assertMatchesRegularExpression('/category ?group/i', (string)$run['error']);
    }

    public function testExitCodeFromEnd(): void
    {
        // A command ending with Craft::$app->end(4), run as `php craft` runs it, with a controller only this test has
        // (`exec` would do, but isn’t watched).
        $name = 'cron-guard-test/end';
        $this->names[] = $name;
        $script = CRON_GUARD_PROJECT_ROOT . '/storage/runtime/cron-guard-test-end.php';
        file_put_contents($script, '<?php
$_SERVER["argv"] = ["craft", "cron-guard-test/end"];
$_SERVER["argc"] = 2;
require ' . var_export(CRON_GUARD_PROJECT_ROOT . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
class CronGuardTestEndController extends craft\\console\\Controller
{
    public function actionEnd(): int
    {
        Craft::$app->end(4);
    }
}
$app->controllerMap["cron-guard-test"] = CronGuardTestEndController::class;
exit($app->run());
');
        try {
            $process = proc_open(['php', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, CRON_GUARD_PROJECT_ROOT);
            $this->assertSame(4, proc_close($process));
        } finally {
            @unlink($script);
        }
        $this->assertSame(4, (int)$this->lastRun($name)['exitCode']);
    }

    public function testExecIsntRecorded(): void
    {
        $this->assertSame(0, $this->craft("exec 'echo 1;'"));
        $this->assertFalse((new Query())->from(Runs::TABLE_COMMANDS)->where(['command' => 'exec echo 1;'])->exists());
    }

    public function testDefaultActionsAreNamedAsTyped(): void
    {
        $this->names[] = 'graphql/print-schema';
        $this->craft('help');
        $this->assertFalse((new Query())->from(Runs::TABLE_COMMANDS)->where(['like', 'command', 'help%', false])->exists(), '`help` is ignored as typed.');
    }

    public function testIgnoredCommandsArentRecorded(): void
    {
        $this->craft('plugin/enable cron-guard-test-nothing');
        $this->assertFalse((new Query())->from(Runs::TABLE_COMMANDS)->where(['like', 'command', 'plugin/enable%', false])->exists());
    }

    public function testQueueJobProcessesArentCommands(): void
    {
        // The process `queue/run` starts for each job, even when the saved settings don’t list it.
        $this->settings()->ignore = ['help'];
        $this->craft('queue/exec 999999999 300 1 1');
        $this->assertFalse((new Query())->from(Runs::TABLE_COMMANDS)->where(['like', 'command', 'queue/exec%', false])->exists());
    }

    public function testCommandNames(): void
    {
        $runs = $this->plugin()->runs;
        $this->assertSame('resave/entries --section=news', $runs->commandName('resave/entries', ['resave/entries', '--section=news', '--interactive=0', '--color=0']));
        $this->assertSame('gc', $runs->commandName('gc', ['gc']));
        $this->assertLessThanOrEqual(255, mb_strlen($runs->commandName('x', ['x', str_repeat('a', 400)])));
        $this->assertTrue($runs->isIgnored('migrate/all', ['migrate/*']));
        $this->assertFalse($runs->isIgnored('queue/run', ['queue/listen']));
    }

    /**
     * Runs a command as cron would: no terminal.
     */
    private function craft(string $command): int
    {
        $process = proc_open('php craft ' . $command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, CRON_GUARD_PROJECT_ROOT);

        return proc_close($process);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRun(string $command): array
    {
        $run = (new Query())->select('r.*')->from(['r' => Runs::TABLE_RUNS])
            ->innerJoin(['c' => Runs::TABLE_COMMANDS], '[[c.id]] = [[r.commandId]]')
            ->where(['c.command' => $command])->orderBy(['r.id' => SORT_DESC])->one();
        $this->assertIsArray($run, "No run of $command recorded.");

        return $run;
    }

    /**
     * The test removes the command afterwards: skip it when the site already watches it.
     */
    private function skipIfRecorded(string $command): void
    {
        if ((new Query())->from(Runs::TABLE_COMMANDS)->where(['command' => $command])->exists()) {
            $this->names = array_diff($this->names, [$command]);
            $this->markTestSkipped("This site already watches $command.");
        }
    }
}
