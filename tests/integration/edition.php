<?php
/**
 * Switches Penny's edition, for testing.
 *
 *     ddev exec php /var/www/craft-penny/tests/integration/edition.php pro
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$edition = $argv[1] ?? 'pro';

Craft::$app->getPlugins()->switchEdition('penny', $edition);

// Project config writes are buffered until the request ends, and a bare script has no request to
// end — so without this the change reports success and silently vanishes.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "Penny is now on the {$edition} edition.\n";
