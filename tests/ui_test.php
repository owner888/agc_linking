<?php
// Headless UI test for Linking (agc_linking)
// Run: php -d ffi.enable=1 tests/ui_test.php
declare(strict_types=1);

$framework = getenv('MYPHP_HOME') ?: json_decode((string) file_get_contents(__DIR__ . '/../myphp.json'), true)['framework'];
if (!str_starts_with($framework, '/')) {
    $framework = dirname(__DIR__) . '/' . $framework;
}
require "$framework/tests/Headless.php";
require __DIR__ . '/../src/Icons.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ProxyLink.php';
require __DIR__ . '/../src/MiniYaml.php';
require __DIR__ . '/../src/ShareLink.php';
require __DIR__ . '/../src/ClashSubscription.php';
require __DIR__ . '/../src/SingBoxSubscription.php';
require __DIR__ . '/../src/SurgeSubscription.php';
require __DIR__ . '/../src/Profiles.php';
require __DIR__ . '/../src/XrayConfig.php';
require __DIR__ . '/../src/Vpn.php';
require __DIR__ . '/../src/RoutingData.php';
require __DIR__ . '/../src/LinkingApp.php';

use MyPHP\Device;
use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use function MyPHP\Tests\{check, same};

$app = new LinkingApp(deterministicProbes: true);
$h = new Headless(Content::file(__DIR__ . '/../view.php', $app)->view, 402, 874);
$h->rt->strict = true;
$h->rt->touch = true; // a phone: its tab bar across the bottom
$h->theme = \MyPHP\UI\Theme::phone(false);
// A sheet comes and goes in a quarter of a second of the clock: what closes one lets it go before it looks
$settle = function () use ($h): void { $h->frame(3); usleep(450_000); $h->frame(3); };
$h->frame();

// 1. Home is the connection, with nothing to connect to yet: no sample data of any kind
check($h->byText('NetWork') === null, 'Home has no title over the connection');
check($h->byText('Tap To Connect') !== null, 'Connection button says Tap To Connect');
check($h->byText('Home') !== null && $h->byText('Proxies') !== null, 'Two tabs, Home and Proxies');
same([], $app->profiles, 'No profile before one is added');
same('disconnected', $app->connection, 'Disconnected at first');
same(0, $app->sentCount(), 'No probes before the first Start');
same('-', $app->ipCountry, 'No country before a target is probed');

// 2. Asking to connect with no profile asks for one
$app->toggleConnection();
$h->frame();
same(true, $app->addProfileOpen, 'Connecting with no profile opens Add Profile');
check($h->byText('Add Profile') !== null, 'Add Profile sheet renders');

// 3. A link of a proxy becomes a profile; one that is not is refused
$app->profileInput = 'not a link';
$app->addProfile();
check($app->profileError !== '', 'A text that is no link is refused');
same([], $app->profiles, 'Nothing added for it');
$app->profileInput = 'vless://11111111-2222-3333-4444-555555555555@edge.example.com:443?type=ws#Frankfurt';
$app->addProfile();
$settle();
same(1, count($app->profiles), 'A link is a profile');
same('Frankfurt', $app->profiles[0]['name'], 'The profile is named after the link');
same('VLESS', $app->profiles[0]['nodes'][0]['protocol'], 'The proxy is a VLESS');
same('edge.example.com', $app->profiles[0]['nodes'][0]['address'], 'The proxy has its address');
same(false, $app->addProfileOpen, 'The sheet closes once it is added');
same($app->profiles[0]['id'], $app->activeProfileId, 'The new profile is the active one');
check($h->byText('Frankfurt') !== null, 'Home shows the active profile');

// 4. Off Android there is no VPN: no connection is faked, and it says so
$app->toggleConnection();
$h->frame();
same(true, $app->engineAlertOpen, 'Connecting says it is the Android app that connects');
same('A connection is made by the Android app only.', $app->engineAlertMessage, 'The alert says why');
same('disconnected', $app->connection, 'No connection is made');
$app->engineAlertOpen = false;
$settle();

// 5. Proxies lists the active profile's proxies, none measured yet
$app->tab = 'proxies';
$h->frame();
check($h->byText('Frankfurt') !== null || $h->byText('Frankfurt (1)') !== null, 'Proxies shows the profile');
check($h->byText('VLESS · edge.example.com:443') !== null, 'Proxies shows the proxy');
check($h->byText('-') !== null, 'An untested proxy has no latency');
$app->tab = 'home';
$settle();

// 6. What agc_raven's drawer held are tabs: the bar of a phone has four and More, which lists the others
foreach (['Home', 'Proxies', 'Ping', 'Httping', 'More'] as $label) {
    check($h->byLabel($label) !== null, "The bar has {$label}");
}
check($h->byLabel('Tcping') === null && $h->byLabel('Setting') === null, 'The others are not in the bar');
$h->clickOn($h->byLabel('More'));
$settle();
foreach (['Tcping', 'Config Options', 'Log', 'Setting'] as $row) {
    check($h->byText($row) !== null, "More lists {$row}");
}
$app->go('tcping'); // the row's tap, which a headless window cannot always reach
$settle();
same('tcping', $app->tab, 'Tcping opens the ping tool');
same('Tcping', $app->toolType, 'in Tcping mode');
check($h->byText('No Probe Data Yet') !== null, 'Empty state shows until a probe is made');

$app->hostInput = 'github.com:443';
$h->clickText('Start');
$h->frame();
same(4, $app->sentCount(), 'Tcping deterministic probe generated 4 packets');
same('US', $app->ipCountry, 'GitHub IP country resolved to US');

$h->clickText('NSLookup');
$h->frame();
check(count($app->nslookupResults) >= 1, 'NSLookup populated DNS A records');

// A quick target is a row: a tap probes it in its mode
$h->clickText('8.8.8.8');
$h->frame();
same('8.8.8.8', $app->hostInput, 'A quick target fills the host');
same(4, $app->sentCount(), 'A quick target starts probing');
same('Ping', $app->toolType, 'A quick target keeps its mode');

// 7. Favorites are a sheet of the ping tool
$app->favoritesOpen = true;
$h->frame();
check($h->byText('Saved Hosts (4)') !== null, 'Favorites list the saved hosts');
$app->newFavHost = '9.9.9.9';
$app->newFavDesc = 'Quad9 Security DNS';
$app->addFavorite(); // the row's tap, which a headless window cannot reach inside a sheet
$h->frame();
same(5, count($app->favorites), 'Added Quad9 to favorites');
$app->favoritesOpen = false;
$settle();

// 8. What was set is kept: a second app on the same file finds it
$file = sys_get_temp_dir() . '/linking-test-' . bin2hex(random_bytes(4)) . '.json';
$first = new LinkingApp(deterministicProbes: true, store: new Store($file));
$first->profileInput = 'trojan://pw@1.2.3.4:8443#Tokyo';
$first->addProfile();
$first->newFavHost = '4.4.4.4';
$first->addFavorite();
$first->ttl = 99;
$first->save();
$second = new LinkingApp(deterministicProbes: true, store: new Store($file));
same(1, count($second->profiles), 'A profile is kept between runs');
same('Tokyo', $second->activeProfile()['name'] ?? '', 'The active profile is kept');
same('Trojan', $second->activeProfile()['nodes'][0]['protocol'] ?? '', 'with its proxies');
check(in_array('4.4.4.4', array_column($second->favorites, 'host'), true), 'A favorite is kept');
same(99, $second->ttl, 'A setting is kept');
@unlink($file);

// 9. What a link says
same(['name' => 'T', 'protocol' => 'Trojan', 'address' => '1.2.3.4', 'port' => 8443, 'link' => 'trojan://pw@1.2.3.4:8443#T'], Profiles::parseLink('trojan://pw@1.2.3.4:8443#T'), 'trojan link');
same('Shadowsocks', Profiles::parseLink('ss://' . base64_encode('aes-256-gcm:pw@host.com:8388') . '#S')['protocol'] ?? '', 'ss link in base64');
same('v.com', Profiles::parseLink('vmess://' . base64_encode(json_encode(['ps' => 'V', 'add' => 'v.com', 'port' => '443'])))['address'] ?? '', 'vmess link');
same(null, Profiles::parseLink('http://example.com'), 'a web address is not a link of a proxy');
same(2, count(Profiles::parseSubscription(base64_encode("vless://u@a.com:443#A\nhy2://p@b.com:8443#B\nnonsense"))), 'a subscription in base64');
same(true, Profiles::isSubscriptionUrl('https://example.com/sub'), 'a subscription address');

// 5b. Choosing another proxy ends a connection that is up; the same one leaves it alone
$app->profiles[0]['nodes'][] = ['id' => 99, 'name' => 'Other', 'protocol' => 'VLESS', 'address' => 'other.example.com', 'port' => 443, 'latency' => null, 'link' => 'vless://u@other.example.com:443#Other'];
$app->connection = 'connected';
$app->selectNode($app->selectedNodeId);
same('connected', $app->connection, 'The proxy already in use: nothing changes');
$app->selectNode(99);
same('disconnected', $app->connection, 'Another proxy ends the connection');
same(99, $app->selectedNodeId, 'and is the one chosen');
$app->selectNode($app->profiles[0]['nodes'][0]['id']);

// 5c. Per-App Proxy: the chosen apps are kept, searched, and a mode that needs some refuses to connect with none
$app->installedApps = [['package' => 'org.example.mail', 'label' => 'Mail'], ['package' => 'org.example.maps', 'label' => 'Maps'], ['package' => 'org.example.zed', 'label' => 'Notes']];
$app->toggleApp('org.example.maps');
same(['org.example.maps'], $app->appPackages, 'an app is chosen');
$app->toggleApp('org.example.mail');
$app->toggleApp('org.example.maps');
same(['org.example.mail'], $app->appPackages, 'and chosen no more');
same('1 selected', $app->appsSummary(), 'the summary counts them');
$app->appSearch = 'ma';
same(['org.example.mail', 'org.example.maps'], array_column($app->matchingApps(), 'package'), 'the search matches the name');
$app->appSearch = 'ZED';
same(['org.example.zed'], array_column($app->matchingApps(), 'package'), 'and the package, whatever the case');
$app->appSearch = '';
$app->appPackages = [];
same('None', $app->appsSummary(), 'none chosen');

// 5d. The automation switches are kept (and, on Android, given at once to what acts with no screen)
$app->setAutomation('autoConnect', true);
same(true, $app->autoConnect, 'Connect at Boot is on');
same(false, $app->broadcastControl, 'and the broadcasts are still off');
$app->setAutomation('broadcastControl', true);
$app->setAutomation('autoConnect', false);
same([false, true], [$app->autoConnect, $app->broadcastControl], 'each switches by itself');
$app->setAutomation('broadcastControl', false);

// 6. Switch to Settings tab and test Reset to Default & Standalone DMG/APK In-App Update Capsule
if (PHP_OS_FAMILY === 'Darwin') {
    same('dmg', Device::channel(), 'macOS standalone build defaults to dmg channel');
}
Device::setChannel('apk');
$app->ttl = 128;
$app->go('config');
$h->frame();
$h->wheel(200, 400, 0, -3000); // the settings list has grown past the screen: scroll to its end
$h->frame();
$h->clickText('Reset to Default');
$h->frame();
same(64, $app->ttl, 'Reset to Default restored TTL to 64');
$app->go('settings');
$h->frame();

// The build CI makes takes its version from the tag, so the newer one is by the app's own
$nextVersion = "{$app->currentVersion} (" . ($app->currentBuild + 1) . ')';
$h->clickText('Check for Updates');
$h->frame();
same('available', $app->updateStatus, 'Check for Updates detected newer build');
same($nextVersion, $app->updateVersion, "TestFlight-style version+build detected as {$nextVersion}");
check($h->byText('Update ' . MyPHP\App::name()) !== null, 'Update Capsule is rendered with its title');
check($h->byText($nextVersion) !== null, 'Update Capsule shows the version');

$h->clickText('Update ' . MyPHP\App::name());
$h->frame();
same('installing', $app->updateStatus, 'Tapping the capsule downloaded and entered installing state');
check($h->byText('Update Now') !== null, 'Update Capsule displays Update Now');

// Test dismissing / skipping update capsule: a long press on it asks, as Telegram's
$app->simulateUpdateAvailable();
$h->frame(3);
$capsule = $h->byLabel('Update ' . MyPHP\App::name()) ?? $h->byText('Update ' . MyPHP\App::name());
check($capsule !== null, 'The capsule shows before it is skipped');
// the long press itself is the phone's to try (the headless clock does not hold a press): what it sets, the dialog it opens
$app->skipUpdateAsked = true; $settle();
check($h->byText('Skip This Version') !== null, 'A long press on the capsule offers to skip the version');
$app->skipUpdateAsked = false; $settle();
$app->skipUpdate();
$h->frame();
same('idle', $app->updateStatus, 'Skipping the update hides the Update Capsule');
check($h->byText('Update ' . MyPHP\App::name()) === null, 'No capsule once skipped');
same($nextVersion, $app->skippedVersion, "Skipped version recorded as {$nextVersion}");

// 7. Test iOS App Store channel: jumps to App Store instead of downloading APK
Device::setChannel('appstore');
$app->skippedVersion = '';
$app->checkUpdate(true);
$h->frame();
same('available', $app->updateStatus, 'App Store channel shows update notification');
check($h->byText('Update on the App Store') !== null, 'App Store channel names the store in the capsule');
$h->clickText('Update on the App Store');
$h->frame();
same($app->appStoreUrl, $app->lastOpenedExternalUrl, 'Tapping the capsule jumps to Apple App Store URL');

// 8. Test Google Play Store channel: in-app update capsule completely removed/disabled!
Device::setChannel('play');
$app->checkUpdate(false);
$h->frame();
same('idle', $app->updateStatus, 'Play Store channel disables automatic in-app update check');
check($h->byText('Check for Updates') === null, 'Play Store channel hides Check for Updates button');
$app->performUpdate();
same($app->playStoreUrl, $app->lastOpenedExternalUrl, 'Play Store performUpdate redirects to Google Play Store URL');
Device::setChannel(null);

echo "All Linking headless UI tests passed!\n";
exit(empty($GLOBALS['failures']) ? 0 : 1);
