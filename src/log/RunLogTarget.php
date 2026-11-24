<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\log;

use Craft;
use Throwable;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

/**
 * Keeps the errors and warnings logged while a command runs, so a failed run says what went wrong. Nothing is written
 * anywhere by this target: the run saves what it kept.
 */
class RunLogTarget extends Target
{
    /** Entries kept, the latest. */
    public const KEEP = 20;

    /** Characters kept of each message. */
    public const MESSAGE_LENGTH = 1000;

    /** @var array<int, array{level: string, category: string, message: string}> */
    private array $entries = [];

    public function init(): void
    {
        parent::init();
        $this->setLevels(['error', 'warning']);
        $this->except = ['cron-guard', 'yii\\db\\*'];
        // No log vars or context: only what was logged.
        $this->logVars = [];
        $this->exportInterval = PHP_INT_MAX;
    }

    public function collect($messages, $final): void
    {
        // The exception the run ended with is its error already. Craft’s error handler has it before it logs it.
        $exception = Craft::$app->getErrorHandler()->exception;
        foreach (static::filterMessages($messages, $this->getLevels(), $this->categories, $this->except) as $message) {
            [$text, $level, $category] = $message;
            if ($text instanceof Throwable && $text === $exception) {
                continue;
            }
            $this->entries[] = [
                'level' => $level === Logger::LEVEL_ERROR ? 'error' : 'warning',
                'category' => mb_substr((string)$category, 0, 120),
                'message' => mb_substr($this->text($text), 0, self::MESSAGE_LENGTH),
            ];
        }
        if (count($this->entries) > self::KEEP) {
            $this->entries = array_slice($this->entries, -self::KEEP);
        }
    }

    public function export(): void
    {
    }

    /**
     * @return array<int, array{level: string, category: string, message: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    private function text(mixed $message): string
    {
        if (is_string($message)) {
            return trim($message);
        }
        if ($message instanceof Throwable) {
            return get_class($message) . ': ' . $message->getMessage() . ' in ' . $message->getFile() . ':' . $message->getLine();
        }

        return VarDumper::export($message);
    }
}
