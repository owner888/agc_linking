<?php
// Headless UI test for Linking (agc_linking)
// Run: php -d ffi.enable=1 tests/ui_test.php
declare(strict_types=1);

$framework = getenv('MYPHP_HOME') ?: json_decode((string) file_get_contents(__DIR__ . '/../myphp.json'), true)['framework'];
if (!str_starts_with($framework, '/')) {
    $framework = dirname(__DIR__) . '/' . $framework;
}
require "$framework/tests/Headless.php";
require __DIR__ . '/../src/LinkingApp.php';

use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use function MyPHP\Tests\{check, same};

$app = new LinkingApp(deterministicProbes: true);
$h = new Headless(Content::file(__DIR__ . '/../view.php', $app)->view, 1120, 740);
$h->rt->strict = true;
$h->frame();

// 1. Default Ping Tools tab renders 8 statistics & initial sample packets
check($h->byText('Network Diagnostics') !== null, 'Ping Tools header renders');
same(5, $app->sentCount(), 'Initial sample probe count is 5');
check($h->byText('17 ms') !== null, 'Initial Avg RTT renders');

// 2. Switch tool mode to Tcping and run deterministic probe
$h->clickText('Tcping');
$h->frame();
same('Tcping', $app->toolType, 'Switched to Tcping mode');

$app->hostInput = 'github.com:443';
$h->clickText('Start');
$h->frame();
same(4, $app->sentCount(), 'Tcping deterministic probe generated 4 packets');
same('US', $app->ipCountry, 'GitHub IP country resolved to US');

// 3. Test NSLookup drawer
$h->clickText('NSLookup');
$h->frame();
check(count($app->nslookupResults) >= 1, 'NSLookup populated DNS A records');

// 4. Switch to Network tab and test VPN connect & node import
$app->tab = 'network';
$h->frame();
check($h->byText('Tunnel Ready') !== null, 'Network tab renders Tunnel Ready state');

$h->clickText('Connect Tunnel');
$h->frame();
same(true, $app->vpnConnected, 'Tunnel connected after clicking Connect Tunnel');
check($h->byText('Tunnel Connected') !== null, 'Header updated to Tunnel Connected');

$app->nodeImportInput = 'hysteria2://tokyo-edge.linking.net:8443';
$h->clickText('Import Profile');
$h->frame();
same(5, count($app->proxyNodes), 'Imported new Hysteria2 proxy node');

// 5. Switch to Favorites tab and add a new favorite host
$app->tab = 'favorites';
$h->frame();
$app->newFavHost = '9.9.9.9';
$app->newFavDesc = 'Quad9 Security DNS';
$h->clickText('Add Host');
$h->frame();
same(5, count($app->favorites), 'Added Quad9 to favorites');

// 6. Switch to Settings tab and test Reset to Default
$app->tab = 'settings';
$app->ttl = 128;
$h->frame();
$h->clickText('Reset to Default');
$h->frame();
same(64, $app->ttl, 'Reset to Default restored TTL to 64');

echo "All Linking headless UI tests passed!\n";
exit(empty($GLOBALS['failures']) ? 0 : 1);
