<?php

namespace suprim\cronguard\tests\Integration;

use suprim\cronguard\helpers\Redact;
use suprim\cronguard\log\RunLogTarget;
use suprim\cronguard\tests\TestCase;
use yii\log\Logger;

/**
 * Secrets are masked before error and log messages are saved, and email addresses before alerts leave the server.
 */
class RedactTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secrets(): array
    {
        return [
            'password=' => ['Login failed: password=hunter2&user=jo', 'Login failed: password=••••&user=jo'],
            'JSON' => ['{"api_key": "abc123def", "page": 2}', '{"api_key": "••••", "page": 2}'],
            'header' => ['Authorization: Bearer eyJhbGciOi.payloadpart.signature99', 'Authorization: Bearer ••••'],
            'query key' => ['GET https://maps.example.com/geocode?address=x&key=AbCdEf123456', 'GET https://maps.example.com/geocode?address=x&key=••••'],
            'URL password' => ['Couldn’t connect to ftp://feeds:s3cr3t@ftp.example.com/in', 'Couldn’t connect to ftp://feeds:••••@ftp.example.com/in'],
            'Stripe' => ['Stripe said no to sk_live_51HxYzAbCdEfGhIj', 'Stripe said no to ••••'],
            'Slack' => ['Bad token xoxb-1234567890-abcdefghij', 'Bad token ••••'],
            'GitHub' => ['Clone with ghp_abcdefghijklmnopqrstuvwxyz0123', 'Clone with ••••'],
            'AWS' => ['Key AKIAIOSFODNN7EXAMPLE was denied', 'Key •••• was denied'],
            'client_secret' => ['client_secret: Zm9vYmFy', 'client_secret: ••••'],
        ];
    }

    /**
     * @dataProvider secrets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secrets')]
    public function testSecretsAreMasked(string $text, string $masked): void
    {
        $this->assertSame($masked, Redact::secrets($text));
    }

    public function testOrdinaryMessagesAreLeftAlone(): void
    {
        foreach ([
            'Supplier feed answered 500',
            'Invalid password for user 12',
            'Element 4512 failed validation: Title cannot be blank',
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
            'Resaving 340 entries (author: 3)',
        ] as $text) {
            $this->assertSame($text, Redact::secrets($text));
        }
    }

    public function testEmailsAreMaskedInWhatLeaves(): void
    {
        $this->assertSame('Order 55 for j••••@example.com failed', Redact::outgoing('Order 55 for jane.doe@example.com failed'));
        $this->assertSame('Order 55 for jane.doe@example.com failed', Redact::secrets('Order 55 for jane.doe@example.com failed'), 'Kept as it is in the control panel.');
    }

    public function testTheSitesOwnPatterns(): void
    {
        $this->settings()->redact = ['/ACME-[0-9A-F]{8}/', 'not a pattern ['];
        $this->assertSame('Licence •••• rejected', Redact::secrets('Licence ACME-0A1B2C3D rejected'));
    }

    public function testLogsAreMaskedBeforeTheyreKept(): void
    {
        $target = new RunLogTarget();
        $target->collect([['Feed login failed with password=hunter2', Logger::LEVEL_ERROR, 'feed', microtime(true), [], 0]], false);
        $this->assertSame('Feed login failed with password=••••', $target->entries()[0]['message']);
    }

    private function failingWithLog(): int
    {
        $runs = $this->every(300, 5);
        $runs[0] += ['exitCode' => 1, 'error' => 'Couldn’t sync order for jane@example.com', 'log' => json_encode([
            ['level' => 'error', 'category' => 'shop', 'message' => 'API answered 401 for token=abc123xyz and jane@example.com'],
        ])];

        return $this->command($runs, 'sync --api-token=abc123xyz-' . bin2hex(random_bytes(2)));
    }

    public function testAlertsAreMasked(): void
    {
        if (!\Craft::$app->getDb()->columnExists(\suprim\cronguard\services\Runs::TABLE_RUNS, 'log')) {
            $this->markTestSkipped('Run the 1.1.0 migrations first.');
        }
        $this->settings()->logInAlerts = true;
        $this->plugin()->checker->check([$this->failingWithLog()]);
        $alert = $this->alerts[0];

        $this->assertStringNotContainsString('abc123xyz', $alert->subject . $alert->text, 'Not in the command’s arguments, nor its log.');
        $this->assertStringNotContainsString('jane@example.com', $alert->text);
        $this->assertStringContainsString('j••••@example.com', $alert->text);
        $this->assertStringContainsString('Logged: API answered 401 for token=••••', $alert->text);
        $this->assertStringNotContainsString('abc123xyz', $this->plugin()->alerts->emailHtml($alert));
    }

    public function testLogStaysInTheControlPanelByDefault(): void
    {
        if (!\Craft::$app->getDb()->columnExists(\suprim\cronguard\services\Runs::TABLE_RUNS, 'log')) {
            $this->markTestSkipped('Run the 1.1.0 migrations first.');
        }
        $this->assertFalse((new \suprim\cronguard\models\Settings())->logInAlerts, 'Off unless it’s turned on.');
        $id = $this->failingWithLog();
        $this->plugin()->checker->check([$id]);

        $this->assertStringNotContainsString('Logged:', $this->alerts[0]->text);
        $this->assertSame([], $this->alerts[0]->problems[0]['log']);
        $this->assertNotEmpty($this->statusOf($id)['log'], 'Still on the command’s page.');
    }
}
