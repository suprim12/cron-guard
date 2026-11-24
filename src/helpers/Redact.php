<?php
/**
 * Cron Guard plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\cronguard\helpers;

use suprim\cronguard\Plugin;

/**
 * Masks secrets in error and log messages before they’re saved or sent: passwords, tokens and keys, credentials in
 * URLs, and well-known key formats. Email addresses are masked in what leaves the server.
 */
class Redact
{
    public const MASK = '••••';

    /** Names whose values are secrets, as `name=value`, `name: value` or `"name": "value"`. */
    private const SECRET_NAMES = '[\w-]*(?:password|passwd|passphrase|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|signing[_-]?key|credentials?|signature|session[_-]?id|cookie)[\w-]*';

    /**
     * Patterns masked whole: [pattern, replacement].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PATTERNS = [
        // password=…, "api_key": "…", token: …
        ['/(?<![\w-])(' . self::SECRET_NAMES . ')(["\']?\s*[:=]\s*["\']?)(?!' . self::MASK . ')[^\s"\'&,;<>)}\]]+/i', '$1$2' . self::MASK],
        // ?key=… and &key=… in URLs, such as map and feed APIs.
        ['/([?&](?:key|sig|auth|code)=)[^\s"\'&#<>]+/i', '$1' . self::MASK],
        // Authorization: Bearer …, Basic …
        ['/\b(Bearer|Basic|Token|Digest)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::MASK],
        // scheme://user:password@host
        ['#(\b[a-z][a-z0-9+.-]*://[^/\s:@]+:)[^@\s/]+@#i', '$1' . self::MASK . '@'],
        // Well-known key formats: Stripe, Slack, GitHub, GitLab, AWS, Google, JWTs, PEM private keys.
        ['/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/', self::MASK],
        ['/\bxox[abprs]-[A-Za-z0-9-]{10,}/', self::MASK],
        ['/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{20,})/', self::MASK],
        ['/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', self::MASK],
        ['/\bAIza[0-9A-Za-z_-]{35}\b/', self::MASK],
        ['/\beyJ[\w-]{10,}\.[\w-]{10,}\.[\w-]{10,}/', self::MASK],
        ['/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', self::MASK],
    ];

    /**
     * Masks secrets, with the site’s own patterns from `redact` as well.
     */
    public static function secrets(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        foreach (Plugin::getInstance()?->getSettings()->redact ?? [] as $pattern) {
            // A pattern that isn’t valid is skipped rather than stopping the run being recorded.
            $masked = @preg_replace((string)$pattern, self::MASK, $text);
            if (is_string($masked)) {
                $text = $masked;
            }
        }

        return $text;
    }

    /**
     * Masks email addresses but their first letter and domain: `j••••@example.com`.
     */
    public static function emails(string $text): string
    {
        return preg_replace('/\b([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '$1' . self::MASK . '@$2', $text) ?? $text;
    }

    /**
     * What may leave the server: secrets and email addresses masked.
     */
    public static function outgoing(string $text): string
    {
        return self::emails(self::secrets($text));
    }
}
