<?php
/**
 * Boots the Craft project Cron Guard is installed in, so the tests run against real Craft.
 *
 *   php vendor/bin/phpunit -c plugins/cron-guard/phpunit.xml.dist          (from the project root)
 *
 * CRAFT_PROJECT_ROOT points at another project; by default it’s three folders up (project/plugins/cron-guard/tests).
 * The http suite needs CRON_GUARD_TEST_URL, the site’s base URL as seen from where the tests run.
 */
$root = getenv('CRAFT_PROJECT_ROOT') ?: dirname(__DIR__, 3);
require $root . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

define('CRON_GUARD_PROJECT_ROOT', $root);

spl_autoload_register(function(string $class) {
    $prefix = 'suprim\\cronguard\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!\suprim\cronguard\Plugin::getInstance()) {
    fwrite(STDERR, "Cron Guard isn’t installed in the project at $root.\n");
    exit(1);
}
