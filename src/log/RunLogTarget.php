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
use suprim\cronguard\helpers\Redact;
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

    /** Bytes a run’s log may take once saved, well inside MySQL’s 64 KB for a text column. */
    public const MAX_BYTES = 16384;

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
                // Masked before it’s kept, so a secret in a message is never saved.
                'message' => mb_substr(Redact::secrets($this->text($text)), 0, self::MESSAGE_LENGTH),
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

    /**
     * The entries as JSON, the oldest left out until it fits in MAX_BYTES, so a run with long messages in a script
     * that takes several bytes a character can still be saved.
     *
     * @param array<int, array{level: string, category: string, message: string}> $entries
     */
    public static function encode(array $entries): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string)json_encode($entries, $flags);
        while (strlen($json) > self::MAX_BYTES && count($entries) > 1) {
            array_shift($entries);
            $json = (string)json_encode($entries, $flags);
        }
        if (strlen($json) > self::MAX_BYTES) {
            $entries[0]['message'] = mb_strcut($entries[0]['message'], 0, self::MAX_BYTES / 2, 'UTF-8') . '…';
            $json = (string)json_encode($entries, $flags);
        }

        return $json;
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
