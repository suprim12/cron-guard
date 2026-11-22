<?php

namespace suprim\cronguard\tests\Integration;

use Craft;
use craft\web\View;
use suprim\cronguard\tests\TestCase;
use suprim\cronguard\utilities\CronGuardUtility;

/**
 * The utility, a command’s runs and the settings page render.
 */
class PagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        Craft::$app->getCache()->delete('cron-guard:badge');
        // The pages’ forms carry a CSRF token, which needs a web request and response.
        $this->request = Craft::$app->getRequest();
        $this->response = Craft::$app->getResponse();
        Craft::$app->set('request', new class() extends \craft\console\Request {
            public string $csrfParam = 'CRAFT_CSRF_TOKEN';

            public function getCsrfToken(): string
            {
                return 'test';
            }
        });
        Craft::$app->set('response', new \craft\web\Response());
    }

    private mixed $request;
    private mixed $response;

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->request);
        Craft::$app->set('response', $this->response);
        parent::tearDown();
    }

    public function testUtilityAndCommandPage(): void
    {
        $failing = $this->every(300, 5);
        $failing[0]['exitCode'] = 1;
        $failing[0]['error'] = 'Feed timed out <script>';
        $id = $this->command($failing);
        $this->command([['ago' => 30]]);
        $this->command([['ago' => 30, 'exitCode' => null]]);

        $html = CronGuardUtility::html();
        $this->assertStringContainsString('One command needs a look.', $html);
        $this->assertStringContainsString('every 5 minutes', $html);
        $this->assertStringContainsString('Learning: 1 of 4 runs', $html);
        $this->assertStringContainsString('Failed', $html);

        $html = CronGuardUtility::html($id);
        $this->assertStringContainsString('Failed (exit code 1)', $html);
        $this->assertStringContainsString('Feed timed out &lt;script&gt;', $html);
        $this->assertStringContainsString('Runs every 5 minutes.', $html);
        $this->assertGreaterThanOrEqual(1, CronGuardUtility::badgeCount());
    }

    public function testSettingsPage(): void
    {
        $method = new \ReflectionMethod($this->plugin(), 'settingsHtml');
        try {
            $html = (string)$method->invoke($this->plugin());
        } catch (\Twig\Error\RuntimeError $e) {
            // Craft before 5.7 can’t render its env-variable fields outside a web request.
            if (str_contains($e->getMessage(), 'The directory does not exist')) {
                $this->markTestSkipped('This Craft version renders the settings page only in a web request.');
            }
            throw $e;
        }
        $this->assertStringContainsString('Slack, Discord or Teams', $html);
        $this->assertStringContainsString('chatWebhook', $html);
        $this->assertStringContainsString('migrate/*', $html);
        $this->assertStringContainsString('cron-guard/commands/test-alert', $html);

        $this->settings()->healthToken = 'cron-guard-test-token';
        $html = (string)$method->invoke($this->plugin());
        $url = \craft\helpers\UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/cron-guard/health');
        $this->assertStringContainsString(htmlspecialchars($url), $html, 'The health URL on the site, not in the control panel.');
    }
}
