<?php

namespace suprim\cronguard\tests\Http;

use Craft;
use GuzzleHttp\Client;
use suprim\cronguard\Plugin;
use suprim\cronguard\tests\TestCase;

/**
 * The health URL for uptime monitors. Needs CRON_GUARD_TEST_URL. Saves a token for the tests and puts exactly what
 * was saved before back afterwards.
 */
class HealthTest extends TestCase
{
    private const TOKEN = 'cron-guard-test-token-0123456789';

    private static string $base;
    private static mixed $savedSettings = null;

    public static function setUpBeforeClass(): void
    {
        $base = getenv('CRON_GUARD_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set CRON_GUARD_TEST_URL to run the http tests.');
        }
        self::$base = rtrim($base, '/');
        $config = Craft::$app->getProjectConfig();
        self::$savedSettings = $config->get('plugins.cron-guard.settings');
        $plugin = Plugin::getInstance();
        Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), ['healthToken' => self::TOKEN]));
        $config->saveModifiedConfigData();
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$base)) {
            return;
        }
        $config = Craft::$app->getProjectConfig();
        if (self::$savedSettings === null) {
            $config->remove('plugins.cron-guard.settings', 'Cron Guard tests: settings put back');
        } else {
            $config->set('plugins.cron-guard.settings', self::$savedSettings, 'Cron Guard tests: settings put back');
        }
        $config->saveModifiedConfigData();
        // Putting back what was there still moves the stored dateModified on: keep the YAML files in step with it.
        $config->writeYamlFiles(true);
    }

    public function testNeedsTheToken(): void
    {
        $this->assertSame(404, $this->get()->getStatusCode());
        $this->assertSame(404, $this->get('wrong')->getStatusCode());
    }

    public function testOkAndProblem(): void
    {
        $id = $this->command($this->every(300, 5));
        $response = $this->get(self::TOKEN);
        $body = json_decode((string)$response->getBody(), true);
        $mine = array_values(array_filter($body['commands'], fn($c) => str_starts_with($c['command'], 'cron-guard-test/')));
        $this->assertCount(1, $mine);
        $this->assertSame('ok', $mine[0]['state']);
        $this->assertSame($body['status'] === 'ok' ? 200 : 503, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        $this->addRun($id, ['ago' => 1, 'exitCode' => 3]);
        $response = $this->get(self::TOKEN);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('problem', $body['status']);
        $this->assertContains('failing', array_column($body['problems'], 'state'));
    }

    public function testTheHeaderWorksToo(): void
    {
        $response = (new Client(['http_errors' => false, 'verify' => false]))->get($this->url(), ['headers' => ['X-Cron-Guard-Token' => self::TOKEN]]);
        $this->assertContains($response->getStatusCode(), [200, 503]);
    }

    private function get(?string $token = null): \Psr\Http\Message\ResponseInterface
    {
        return (new Client(['http_errors' => false, 'verify' => false]))->get($this->url(), $token !== null ? ['query' => ['cronGuardToken' => $token]] : []);
    }

    private function url(): string
    {
        return self::$base . '/' . Craft::$app->getConfig()->getGeneral()->actionTrigger . '/cron-guard/health';
    }
}
