<?php
// Where the store lives, and the move from the folder an earlier version used. Run: php -d ffi.enable=1 tests/store_test.php
declare(strict_types=1);

$framework = getenv('MYPHP_HOME') ?: json_decode((string) file_get_contents(__DIR__ . '/../myphp.json'), true)['framework'];
if (!str_starts_with($framework, '/')) {
    $framework = dirname(__DIR__) . '/' . $framework;
}
require "$framework/src/autoload.php";
require __DIR__ . '/../src/Store.php';

use MyPHP\{App, PathName};

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want) . ', got ' . json_encode($got) . "\n"; }
}

$root = sys_get_temp_dir() . '/linking-store-test-' . bin2hex(random_bytes(4));
mkdir("$root/earlier", 0755, true);
file_put_contents("$root/earlier/linking.json", '{"routingMode":"Global"}');
App::setPath(PathName::AppData, $root);
App::setPath(PathName::UserData, "$root/Linking");
mkdir("$root/Linking");

// An earlier version's store, none in the app's own folder yet: moved over
$store = Store::inUserData('earlier');
same(['routingMode' => 'Global'], $store->load(), 'the earlier store is read');
same(true, is_file("$root/Linking/linking.json"), 'it is in the app folder now');
same(false, is_dir("$root/earlier"), 'the folder it left is removed');

// A store already in the app's folder is never replaced by an older one
mkdir("$root/earlier");
file_put_contents("$root/earlier/linking.json", '{"routingMode":"Direct"}');
same(['routingMode' => 'Global'], Store::inUserData('earlier')->load(), 'the store of the app folder wins');
same(true, is_file("$root/earlier/linking.json"), 'and the older one is left alone');

// Nothing earlier: nothing made up
unlink("$root/Linking/linking.json");
unlink("$root/earlier/linking.json");
same([], Store::inUserData('earlier')->load(), 'no store, no data');

array_map('unlink', glob("$root/Linking/*") ?: []);
@rmdir("$root/Linking"); @rmdir("$root/earlier"); @rmdir($root);
echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
