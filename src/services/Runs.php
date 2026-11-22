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
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use suprim\cronguard\Plugin;
use Throwable;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Records each console command as it runs: when it started, how long it took, and how it ended.
 */
class Runs extends Component
{
    public const TABLE_COMMANDS = '{{%cronguard_commands}}';
    public const TABLE_RUNS = '{{%cronguard_runs}}';

    /**
     * Commands never watched, whatever the settings say: the process `queue/run` starts for each job (Yii’s queue
     * isolates jobs), named by the job’s ID, so each job would be a command of its own. Its time is the run’s.
     */
    public const NEVER_WATCHED = ['queue/exec'];

    /** The exit code of a run that never finished: the process was killed, or the server restarted. */
    public const EXIT_ABANDONED = -1;

    /** Options that don’t change what a command does, left out of its name. */
    private const NOISE_OPTIONS = '/^--?(interactive|color|verbose|silent-exit-on-exception|v)(=|$)/';

    /** @var array{runId: int, commandId: int, started: float}|null The run being recorded. */
    private ?array $current = null;

    private ?int $exitCode = null;

    /** Whether this request’s command has been looked at, so commands it runs inside it aren’t recorded again. */
    private bool $started = false;

    /**
     * Starts recording the command the console is about to run. Commands run inside it (`up` runs migrations) are
     * part of the same run.
     */
    public function start(ActionEvent $event): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        // As it’s typed: `gc`, not `gc/run`, when the action is the command’s default one.
        $controller = $event->action->controller;
        $route = $event->action->id === $controller->defaultAction ? $controller->getUniqueId() : $event->action->getUniqueId();
        $settings = Plugin::getInstance()->getSettings();
        if ($this->isIgnored([$route, $event->action->getUniqueId()], [...self::NEVER_WATCHED, ...$settings->ignore])) {
            return;
        }
        if (!$settings->watchInteractive && $this->isInteractive()) {
            return;
        }
        $command = $this->commandName($route, Craft::$app->getRequest()->getParams());

        try {
            $commandId = $this->commandId($command, $route);
            Db::insert(self::TABLE_RUNS, [
                'commandId' => $commandId,
                'startedAt' => Db::prepareDateForDb(new DateTime()),
            ] + ($this->hasProcessColumns() ? $this->process() : []));
            $this->current = [
                'runId' => (int)Craft::$app->getDb()->getLastInsertID(),
                'commandId' => $commandId,
                'started' => microtime(true),
            ];
        } catch (Throwable $e) {
            // Never stop the command because the database couldn’t be written: before install, or mid-migration.
            Craft::warning('Cron Guard couldn’t record a run: ' . $e->getMessage(), 'cron-guard');
            return;
        }

        // Runs after Craft’s own fatal error handling, which waits for these, so a crash is recorded too.
        register_shutdown_function(fn() => $this->finish());
    }

    /**
     * Notes the exit code as the console request ends. `Craft::$app->end($status)`, and an `ExitException`, end the
     * request before their status reaches the response, so it’s read from the call itself.
     */
    public function afterRequest(): void
    {
        foreach (debug_backtrace(0, 12) as $frame) {
            if ($frame['function'] === 'end' && isset($frame['class']) && is_a($frame['class'], Application::class, true)) {
                $this->exitCode = (int)($frame['args'][0] ?? 0);
                return;
            }
        }
        $this->exitCode = (int)Craft::$app->getResponse()->exitStatus;
    }

    /**
     * Records how the run ended, then checks it straight away so a failure is sent at once.
     */
    public function finish(): void
    {
        if ($this->current === null) {
            return;
        }
        $current = $this->current;
        $this->current = null;

        $exitCode = $this->exitCode;
        $error = null;
        $exception = Craft::$app->getErrorHandler()->exception;
        if ($exception !== null) {
            $exitCode = 1;
            $error = $exception->getMessage() ?: get_class($exception);
        } elseif ($exitCode === null) {
            // exit() called in the command itself: it finished, and how isn’t known.
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $exitCode = 255;
                $error = $last['message'];
            } else {
                $exitCode = 0;
            }
        }

        try {
            Db::update(self::TABLE_RUNS, [
                'finishedAt' => Db::prepareDateForDb(new DateTime()),
                'durationMs' => (int)round((microtime(true) - $current['started']) * 1000),
                'exitCode' => $exitCode,
                'error' => $error !== null ? StringHelper::safeTruncate($error, 2000) : null,
                'memory' => memory_get_peak_usage(true),
            ], ['id' => $current['runId']]);
            Plugin::getInstance()->checker->afterRun($current['commandId']);
        } catch (Throwable $e) {
            Craft::warning('Cron Guard couldn’t finish recording a run: ' . $e->getMessage(), 'cron-guard');
        }
    }

    /**
     * This process and the server it’s on, recorded with each run.
     *
     * @return array{pid: int, host: string}
     */
    public function process(): array
    {
        return ['pid' => (int)getmypid(), 'host' => mb_substr((string)gethostname(), 0, 255)];
    }

    /**
     * Whether a run’s process is still going: true or false when it’s on this server and that can be told, null otherwise.
     */
    public function processRunning(?int $pid, ?string $host): ?bool
    {
        if (!$pid || $host === null || $host !== (string)gethostname()) {
            return null;
        }
        if (function_exists('posix_kill')) {
            // Signal 0 only asks. A process of another user answers “not permitted”, but it’s there.
            return @posix_kill($pid, 0) || (function_exists('posix_get_last_error') && posix_get_last_error() === 1);
        }
        if (is_dir('/proc/self')) {
            return is_dir("/proc/$pid");
        }

        return null;
    }

    /**
     * Whether the runs table has the process columns yet: not until the 1.0.1 migration has run.
     */
    private function hasProcessColumns(): bool
    {
        try {
            return Craft::$app->getDb()->getTableSchema(self::TABLE_RUNS)?->getColumn('pid') !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The command and the arguments that make it a different job (`resave/entries --section=news`).
     *
     * @param array<int|string, mixed> $params The console request’s params: the route, then its arguments.
     */
    public function commandName(string $route, array $params): string
    {
        $args = [];
        foreach (array_slice(array_values($params), 1) as $param) {
            if (!is_scalar($param) || preg_match(self::NOISE_OPTIONS, (string)$param)) {
                continue;
            }
            $args[] = (string)$param;
        }
        $name = trim($route . ' ' . implode(' ', $args));
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 240) . ' …' . substr(md5($name), 0, 8);
        }

        return $name;
    }

    /**
     * Whether a route matches the ignore list, as typed (`migrate`) or in full (`migrate/up`). `migrate/*` also
     * matches `migrate` on its own.
     *
     * @param string|string[] $routes
     * @param string[] $patterns
     */
    public function isIgnored(string|array $routes, array $patterns): bool
    {
        foreach ((array)$routes as $route) {
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if ($pattern !== '' && (fnmatch($pattern, $route) || (str_ends_with($pattern, '/*') && substr($pattern, 0, -2) === $route))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cron runs commands without a terminal; someone typing one has one.
     */
    private function isInteractive(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    private function commandId(string $command, string $route): int
    {
        $find = fn() => (new Query())->select('id')->from(self::TABLE_COMMANDS)->where(['command' => $command])->scalar();
        if ($id = $find()) {
            return (int)$id;
        }
        try {
            Db::insert(self::TABLE_COMMANDS, ['command' => $command, 'route' => $route]);
        } catch (IntegrityException $e) {
            // Its first run started on two servers at once, and the other one added it.
            if ($id = $find()) {
                return (int)$id;
            }
            throw $e;
        }

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Removes runs older than `keepDays`, keeps at most 1,000 a command, and forgets commands left with none. A command
     * with a schedule keeps its latest runs however old they are, so one that runs less often than `keepDays` isn’t
     * forgotten before it’s missed.
     */
    public function prune(): void
    {
        $plugin = Plugin::getInstance();
        $cutoff = Db::prepareDateForDb(new DateTime("-{$plugin->getSettings()->keepDays} days"));
        $scheduled = array_column(array_filter($plugin->checker->statuses(), fn($s) => $s['interval'] !== null), 'id');

        foreach ((new Query())->select('id')->from(self::TABLE_COMMANDS)->column() as $commandId) {
            $commandId = (int)$commandId;
            $old = ['and', ['commandId' => $commandId], ['<', 'startedAt', $cutoff]];
            if (in_array($commandId, $scheduled, true)) {
                $oldestKept = $this->nthNewestRunId($commandId, Checker::RUNS_LOOKED_AT);
                if ($oldestKept === null) {
                    continue;
                }
                $old[] = ['<', 'id', $oldestKept];
            }
            Db::delete(self::TABLE_RUNS, $old);

            $beyond = $this->nthNewestRunId($commandId, 1001);
            if ($beyond !== null) {
                Db::delete(self::TABLE_RUNS, ['and', ['commandId' => $commandId], ['<=', 'id', $beyond]]);
            }
        }

        $empty = (new Query())->select('c.id')->from(['c' => self::TABLE_COMMANDS])
            ->leftJoin(['r' => self::TABLE_RUNS], '[[r.commandId]] = [[c.id]]')
            ->where(['r.id' => null, 'c.muted' => false])->column();
        if ($empty) {
            Db::delete(self::TABLE_COMMANDS, ['id' => $empty]);
        }
    }

    /**
     * The id of a command’s nth newest run, if it has that many.
     */
    private function nthNewestRunId(int $commandId, int $n): ?int
    {
        $id = (new Query())->select('id')->from(self::TABLE_RUNS)->where(['commandId' => $commandId])
            ->orderBy(['id' => SORT_DESC])->offset($n - 1)->limit(1)->scalar();

        return $id ? (int)$id : null;
    }
}
