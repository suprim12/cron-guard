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
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use suprim\cronguard\Plugin;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * Works out each command’s schedule from its runs, finds what’s wrong, and sends alerts once per problem.
 */
class Checker extends Component
{
    public const OK = 'ok';
    public const LEARNING = 'learning';
    public const IRREGULAR = 'irregular';
    public const FAILING = 'failing';
    public const STUCK = 'stuck';
    public const OVERDUE = 'overdue';
    public const SLOW = 'slow';
    public const MUTED = 'muted';

    /** States that are problems, worst first. */
    public const PROBLEMS = [self::FAILING, self::STUCK, self::OVERDUE, self::SLOW];

    /** Runs needed before a schedule is learnt. */
    public const RUNS_TO_LEARN = 4;

    /** Runs looked at to learn a schedule and a usual duration. */
    public const RUNS_LOOKED_AT = 21;

    /** Seconds after a command was reported fixed in which it failing again counts as flapping, and isn’t sent. */
    private const FLAP_SECONDS = 3600;

    /** Seconds between checks started by visits and other commands. */
    private const CHECK_EVERY = 300;

    /**
     * The status of every command, problems first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statuses(?int $now = null): array
    {
        $now ??= time();
        $commands = (new Query())->from(Runs::TABLE_COMMANDS)->orderBy(['command' => SORT_ASC])->all();
        $runs = $this->recentRunsByCommand();
        $statuses = array_map(fn($command) => $this->status($command, $runs[(int)$command['id']] ?? [], $now), $commands);
        usort($statuses, fn($a, $b) => [$this->rank($a['state']), $a['command']] <=> [$this->rank($b['state']), $b['command']]);

        return $statuses;
    }

    /**
     * What a command’s runs say about it.
     *
     * @param array<string, mixed> $command A row of the commands table.
     * @param array<int, array<string, mixed>> $runs Its latest runs, newest first.
     * @return array<string, mixed>
     */
    public function status(array $command, array $runs, int $now): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $starts = array_map(fn($run) => $this->timestamp($run['startedAt']), $runs);
        $durations = array_map(fn($run) => (int)$run['durationMs'] / 1000, array_filter($runs, fn($run) => $run['durationMs'] !== null));
        $last = $runs[0] ?? null;
        $lastFinished = null;
        foreach ($runs as $run) {
            if ($run['exitCode'] !== null) {
                $lastFinished = $run;
                break;
            }
        }

        $interval = $this->configuredInterval($command, $settings->schedules);
        $learnt = $interval === null;
        // How long it may go between runs: its interval, or the longest of its usual gaps when its schedule has some
        // (weekdays only: three days over a weekend).
        $longestGap = $interval;
        if ($interval === null && ($schedule = $this->learntSchedule($starts))) {
            [$interval, $longestGap] = $schedule;
        }
        // The usual duration leaves out the newest run, so a slow one is compared with the ones before it.
        $usualDuration = $this->median(array_slice($durations, $lastFinished === $last ? 1 : 0));

        $status = [
            'id' => (int)$command['id'],
            'command' => $command['command'],
            'route' => $command['route'],
            'muted' => (bool)$command['muted'],
            'alertState' => $command['alertState'],
            'alertedAt' => $command['alertedAt'],
            'interval' => $interval,
            'learnt' => $learnt,
            'runs' => count($runs),
            'lastStart' => $starts[0] ?? null,
            'lastRun' => $last,
            'lastOk' => $this->lastOk($runs),
            'usualDuration' => $usualDuration,
            'state' => self::OK,
            'message' => '',
        ];

        $state = self::OK;
        $message = '';
        $running = $last !== null && $last['exitCode'] === null;
        $stuckAfter = max($settings->stuckMinutes * 60, ($usualDuration ?? 0) * 5);

        if ($running && $settings->stuckMinutes > 0 && $now - $status['lastStart'] > $stuckAfter) {
            $state = self::STUCK;
            $message = sprintf('started %s ago and hasn’t finished: it’s stuck, or was stopped before it could (out of memory, a time limit or a restart)', $this->duration($now - $status['lastStart']));
        } elseif ($lastFinished !== null && (int)$lastFinished['exitCode'] === Runs::EXIT_ABANDONED) {
            $state = self::FAILING;
            $message = 'stopped before it finished: killed for using too much memory or time, or the server restarted';
        } elseif ($lastFinished !== null && (int)$lastFinished['exitCode'] !== 0) {
            $state = self::FAILING;
            $message = sprintf('failed with exit code %d%s', $lastFinished['exitCode'], $lastFinished['error'] ? ': ' . $lastFinished['error'] : '');
        } elseif (!$running && $interval !== null && $status['lastStart'] !== null && $now - $status['lastStart'] > $longestGap + $this->grace($interval)) {
            $state = self::OVERDUE;
            $message = sprintf('hasn’t run for %s; it usually runs %s', $this->duration($now - $status['lastStart']), $this->every($interval));
        } elseif (!$running && $lastFinished !== null && $usualDuration !== null && count($durations) > self::RUNS_TO_LEARN
            && $lastFinished['durationMs'] / 1000 > $usualDuration * 3 && $lastFinished['durationMs'] / 1000 - $usualDuration > 60) {
            $state = self::SLOW;
            $message = sprintf('took %s; it usually takes %s', $this->duration($lastFinished['durationMs'] / 1000), $this->duration($usualDuration));
        } elseif ($interval === null) {
            $state = count($starts) < self::RUNS_TO_LEARN ? self::LEARNING : self::IRREGULAR;
        }

        if ($status['muted']) {
            $state = self::MUTED;
        }
        $status['state'] = $state;
        $status['message'] = $message;

        return $status;
    }

    /**
     * Checks every command and sends what’s new: problems that started, ones still there after `remindHours`, and
     * ones that went away. Returns the alert sent, null when there was nothing to send, or false when another check
     * was already running.
     *
     * A command that fails again within an hour of being reported fixed is flapping: it isn’t sent again until its
     * reminder is due, and nor is its next recovery. What wasn’t delivered anywhere is tried again next time.
     *
     * @param int[]|null $commandIds Only these commands.
     * @return array{problems: array<int, array<string, mixed>>, recovered: array<int, array<string, mixed>>, stopped: bool}|false|null
     */
    public function check(?array $commandIds = null, ?int $now = null): array|false|null
    {
        $now ??= time();
        $mutex = Craft::$app->getMutex();
        $lock = 'cron-guard:check';
        if (!$mutex->acquire($lock, 5)) {
            return false;
        }

        try {
            $settings = Plugin::getInstance()->getSettings();
            $this->closeAbandoned($now);
            $statuses = $this->statuses($now);
            $problems = [];
            $recovered = [];
            // What to remember for each command: [alertState, alertedAt], and whether it waits on the alert going out.
            $changes = [];
            foreach ($statuses as $status) {
                if ($commandIds !== null && !in_array($status['id'], $commandIds, true)) {
                    continue;
                }
                $was = $status['alertState'];
                $wasState = $was !== null ? explode(':', $was)[0] : null;
                $quiet = $was !== null && str_ends_with($was, ':quiet');
                $lastAlert = $status['alertedAt'] !== null ? $this->timestamp($status['alertedAt']) : null;

                if (in_array($status['state'], self::PROBLEMS, true)) {
                    if ($wasState === $status['state']) {
                        if ($settings->remindHours > 0 && $lastAlert !== null && $now - $lastAlert > $settings->remindHours * 3600) {
                            $status['reminder'] = true;
                            $problems[] = $status;
                            $changes[$status['id']] = [$status['state'], $now, true];
                        }
                    } elseif ($was === null && $lastAlert !== null && $now - $lastAlert < self::FLAP_SECONDS) {
                        $changes[$status['id']] = [$status['state'] . ':quiet', $lastAlert, false];
                    } else {
                        $status['reminder'] = false;
                        $problems[] = $status;
                        $changes[$status['id']] = [$status['state'], $now, true];
                    }
                } elseif ($was !== null && $status['state'] !== self::MUTED) {
                    // A slow run isn’t something to recover from: the next normal one just clears it.
                    if (!$quiet && $wasState !== self::SLOW) {
                        $recovered[] = $status;
                        $changes[$status['id']] = [null, $now, true];
                    } else {
                        $changes[$status['id']] = [null, $lastAlert, false];
                    }
                }
            }

            $alert = null;
            $delivered = true;
            if ($problems || $recovered) {
                // When everything with a schedule is overdue, cron itself has most likely stopped.
                $scheduled = array_filter($statuses, fn($s) => $s['interval'] !== null && $s['state'] !== self::MUTED);
                $overdue = array_filter($scheduled, fn($s) => $s['state'] === self::OVERDUE);
                $alert = [
                    'problems' => $problems,
                    'recovered' => $recovered,
                    'stopped' => count($scheduled) > 1 && count($overdue) === count($scheduled),
                ];
                $results = Plugin::getInstance()->alerts->send($alert);
                // No channels means it went to the log; otherwise one channel taking it is enough.
                $delivered = !$results || in_array(true, $results, true);
            }

            foreach ($changes as $id => [$state, $at, $needsDelivery]) {
                if ($delivered || !$needsDelivery) {
                    $this->remember($id, $state, $at);
                }
            }

            return $alert;
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Closes runs that were killed before they could finish (out of memory, a time limit, a restart), once a newer run
     * of the same command has started and they’ve been open five times their usual time, and at least ten minutes.
     * They count as failed. The newest run is left open: it’s reported as stuck instead.
     */
    public function closeAbandoned(int $now): void
    {
        $open = (new Query())->select(['id', 'commandId', 'startedAt'])->from(Runs::TABLE_RUNS)->where(['exitCode' => null])->all();
        foreach ($open as $run) {
            $newer = (new Query())->from(Runs::TABLE_RUNS)
                ->where(['commandId' => $run['commandId']])
                ->andWhere(['>', 'id', $run['id']])
                ->exists();
            if (!$newer) {
                continue;
            }
            $usual = (new Query())->from(Runs::TABLE_RUNS)
                ->where(['commandId' => $run['commandId']])
                ->andWhere(['not', ['durationMs' => null]])
                ->orderBy(['id' => SORT_DESC])->limit(self::RUNS_LOOKED_AT)->column();
            $usual = $usual ? $this->median(array_map('intval', $usual)) / 1000 : 0;
            if ($now - $this->timestamp($run['startedAt']) > max(600, $usual * 5)) {
                Db::update(Runs::TABLE_RUNS, [
                    'exitCode' => Runs::EXIT_ABANDONED,
                    'error' => 'Stopped before it finished.',
                ], ['id' => $run['id']]);
            }
        }
    }

    /**
     * The check after a command finishes: that command at once, everything else every few minutes.
     */
    public function afterRun(int $commandId): void
    {
        $this->check($this->claimDue() ? null : [$commandId]);
    }

    /**
     * The check from visits and the health URL, at most every few minutes. `$before` runs first when it’s due.
     */
    public function checkIfDue(?callable $before = null): void
    {
        try {
            if (!$this->claimDue()) {
                return;
            }
            if ($before !== null) {
                $before();
            }
            $this->check();
        } catch (Throwable $e) {
            Craft::warning('Cron Guard’s check failed: ' . $e->getMessage(), 'cron-guard');
        }
    }

    /**
     * Whether it’s time for a full check, claiming it when it is. Old runs are pruned once an hour as well.
     */
    private function claimDue(): bool
    {
        $cache = Craft::$app->getCache();
        if (!$cache->add('cron-guard:checked', time(), self::CHECK_EVERY)) {
            return false;
        }
        if ($cache->add('cron-guard:pruned', time(), 3600)) {
            Plugin::getInstance()->runs->prune();
        }

        return true;
    }

    /**
     * Forgets when a full check last ran, so the next one isn’t skipped (tests, and Run check now).
     */
    public function resetDue(): void
    {
        Craft::$app->getCache()->delete('cron-guard:checked');
    }

    /**
     * The time between runs, when it’s regular: at least four runs, and most gaps within half of the usual one.
     * Deploy and one-off commands come out irregular, so they’re never reported missing.
     *
     * @param int[] $starts Newest first.
     */
    public function learntInterval(array $starts): ?int
    {
        return $this->learntSchedule($starts)[0] ?? null;
    }

    /**
     * The usual time between runs and the longest gap it usually has (the 85th percentile, so a weekend’s gap in a
     * weekday schedule counts, and one past outage doesn’t), when its runs are regular.
     *
     * @param int[] $starts Newest first.
     * @return array{0: int, 1: int}|null
     */
    public function learntSchedule(array $starts): ?array
    {
        if (count($starts) < self::RUNS_TO_LEARN) {
            return null;
        }
        $gaps = [];
        for ($i = 0; $i < count($starts) - 1; $i++) {
            $gaps[] = $starts[$i] - $starts[$i + 1];
        }
        $usual = $this->median($gaps);
        if ($usual === null || $usual < 30) {
            return null;
        }
        $regular = count(array_filter($gaps, fn($gap) => $gap >= $usual * 0.5 && $gap <= $usual * 1.5));
        if ($regular / count($gaps) < 0.8) {
            return null;
        }
        sort($gaps);
        $longest = $gaps[(int)ceil(count($gaps) * 0.85) - 1];

        return [(int)round($usual), (int)max($usual, $longest)];
    }

    /**
     * How late a run may be before it’s reported: half its interval, at least 5 minutes and at most 2 hours.
     */
    public function grace(int $interval): int
    {
        return (int)min(max($interval * 0.5, 300), 7200);
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, int|string> $schedules
     */
    private function configuredInterval(array $command, array $schedules): ?int
    {
        $value = $schedules[$command['command']] ?? $schedules[$command['route']] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(60, (int)$value);
        }
        $seconds = strtotime('+' . ltrim((string)$value, '+'), 0);

        return $seconds ? max(60, $seconds) : null;
    }

    /**
     * Every command’s latest runs, newest first, by command id, in one query.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function recentRunsByCommand(): array
    {
        $ranked = (new Query())
            ->select(['*', 'rn' => new Expression('ROW_NUMBER() OVER (PARTITION BY [[commandId]] ORDER BY [[startedAt]] DESC, [[id]] DESC)')])
            ->from(Runs::TABLE_RUNS);
        $byCommand = [];
        foreach ((new Query())->from(['r' => $ranked])->where(['<=', 'rn', self::RUNS_LOOKED_AT])->orderBy(['commandId' => SORT_ASC, 'rn' => SORT_ASC])->all() as $run) {
            unset($run['rn']);
            $byCommand[(int)$run['commandId']][] = $run;
        }

        return $byCommand;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentRuns(int $commandId, int $limit = self::RUNS_LOOKED_AT): array
    {
        return (new Query())->from(Runs::TABLE_RUNS)->where(['commandId' => $commandId])
            ->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all();
    }

    /**
     * @param array<int, array<string, mixed>> $runs
     * @return array<string, mixed>|null
     */
    private function lastOk(array $runs): ?array
    {
        foreach ($runs as $run) {
            if ($run['exitCode'] !== null && (int)$run['exitCode'] === 0) {
                return $run;
            }
        }

        return null;
    }

    private function remember(int $commandId, ?string $state, ?int $at): void
    {
        Db::update(Runs::TABLE_COMMANDS, [
            'alertState' => $state,
            'alertedAt' => $at !== null ? Db::prepareDateForDb(new DateTime("@$at")) : null,
        ], ['id' => $commandId]);
        Craft::$app->getCache()->delete('cron-guard:badge');
    }

    private function rank(string $state): int
    {
        $index = array_search($state, self::PROBLEMS, true);

        return $index === false ? 10 + (int)array_search($state, [self::OK, self::LEARNING, self::IRREGULAR, self::MUTED], true) : $index;
    }

    /**
     * @param array<int, int|float> $values
     */
    private function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        $values = array_values($values);
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float)$values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    public function timestamp(mixed $date): int
    {
        $dateTime = DateTimeHelper::toDateTime($date);

        return $dateTime ? $dateTime->getTimestamp() : 0;
    }

    /**
     * How often, in words: “every 5 minutes”, “every hour”, “every day”.
     */
    public function every(int $seconds): string
    {
        $duration = $this->duration($seconds);

        return 'every ' . (str_starts_with($duration, '1 ') ? substr($duration, 2) : $duration);
    }

    /**
     * A length of time in words: “45 seconds”, “5 minutes”, “3 hours”, “2 days”.
     */
    public function duration(float|int $seconds): string
    {
        $seconds = (int)round($seconds);
        [$value, $unit] = match (true) {
            $seconds < 60 => [$seconds, 'second'],
            $seconds < 3600 => [$seconds / 60, 'minute'],
            $seconds < 82800 => [$seconds / 3600, 'hour'],
            default => [$seconds / 86400, 'day'],
        };
        $value = round($value, $value < 10 && $unit !== 'second' ? 1 : 0);
        $value = $value == (int)$value ? (int)$value : $value;

        return $value . ' ' . $unit . ($value == 1 ? '' : 's');
    }
}
