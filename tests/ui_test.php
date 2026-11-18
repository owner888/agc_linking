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

use MyPHP\Device;
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

// 6. Switch to Settings tab and test Reset to Default & Standalone DMG/APK In-App Update Capsule
if (PHP_OS_FAMILY === 'Darwin') {
    same('dmg', Device::channel(), 'macOS standalone build defaults to dmg channel');
}
Device::setChannel('apk');
$app->tab = 'settings';
$app->ttl = 128;
$h->frame();
$h->clickText('Reset to Default');
$h->frame();
same(64, $app->ttl, 'Reset to Default restored TTL to 64');

$h->clickText('Check for Updates');
$h->frame();
same('available', $app->updateStatus, 'Check for Updates detected newer build');
same('2.0.0 (7)', $app->updateVersion, 'TestFlight-style version+build detected as 2.0.0 (7)');
check($h->byText('Update Available') !== null, 'Update Capsule banner is rendered');
check($h->byText('UPDATE') !== null, 'UPDATE action pill is rendered');

$h->clickText('UPDATE');
$h->frame();
same('installing', $app->updateStatus, 'Clicking UPDATE downloaded and entered installing state');
check($h->byText('Installing...') !== null, 'Update Capsule displays Installing...');

// Test dismissing / skipping update capsule
$app->simulateUpdateAvailable();
$h->frame();
$h->clickText('✕');
$h->frame();
same('idle', $app->updateStatus, 'Clicking dismiss ✕ hides the Update Capsule');
same('2.0.0 (7)', $app->skippedVersion, 'Skipped version recorded as 2.0.0 (7)');

// 7. Test iOS App Store channel: jumps to App Store instead of downloading APK
Device::setChannel('appstore');
$app->skippedVersion = '';
$app->checkUpdate(true);
$h->frame();
same('available', $app->updateStatus, 'App Store channel shows update notification');
check($h->byText('APP STORE') !== null, 'App Store channel displays APP STORE button');
$h->clickText('APP STORE');
$h->frame();
same($app->appStoreUrl, $app->lastOpenedExternalUrl, 'Clicking APP STORE jumps to Apple App Store URL');

// 8. Test Google Play Store channel: in-app update capsule completely removed/disabled!
Device::setChannel('play');
$app->checkUpdate(false);
$h->frame();
same('idle', $app->updateStatus, 'Play Store channel disables automatic in-app update check');
check($h->byText('Check for Updates') === null, 'Play Store channel hides Check for Updates button');
check($h->byText('Preview Update Capsule') === null, 'Play Store channel hides Preview Update Capsule button');
$app->performUpdate();
same($app->playStoreUrl, $app->lastOpenedExternalUrl, 'Play Store performUpdate redirects to Google Play Store URL');
Device::setChannel(null);

echo "All Linking headless UI tests passed!\n";
exit(empty($GLOBALS['failures']) ? 0 : 1);
