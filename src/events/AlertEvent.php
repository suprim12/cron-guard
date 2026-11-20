<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\events;

use yii\base\Event;

/**
 * An alert about to be sent. Set `$handled` to true to stop Cron Guard sending it itself, after sending it your own way.
 */
class AlertEvent extends Event
{
    /** @var array<int, array<string, mixed>> Commands with a new problem (or one still there, when `reminder` is set). */
    public array $problems = [];

    /** @var array<int, array<string, mixed>> Commands whose problem went away. */
    public array $recovered = [];

    /** Whether every command with a schedule is overdue: cron itself has most likely stopped. */
    public bool $stopped = false;

    /** The alert as text, as emails and chat messages show it. */
    public string $subject = '';
    public string $text = '';

    /** Whether it’s a test sent from the settings page. */
    public bool $test = false;
}
