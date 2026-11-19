<?php
// Routing data updates: both files or none. Run: php tests/routing_data_test.php
declare(strict_types=1);

require __DIR__ . '/../src/RoutingData.php';

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want) . ', got ' . json_encode($got) . "\n"; }
}

$dir = sys_get_temp_dir() . '/linking-routing-' . bin2hex(random_bytes(4));
$data = new RoutingData($dir);
$good = fn(string $url): string => str_repeat($url === RoutingData::SOURCES['geoip.dat'] ? 'a' : 'b', 150_000);

same(null, $data->updatedOn(), 'nothing updated yet: the app\'s own copy');
same(null, $data->update($good, '2026-11-19'), 'an update with good files is done');
same(str_repeat('a', 150_000), file_get_contents("$dir/geoip.dat"), 'geoip is in place');
same(str_repeat('b', 150_000), file_get_contents("$dir/geosite.dat"), 'geosite is in place');
same('2026-11-19', $data->updatedOn(), 'the day is kept');
same([], glob("$dir/*.new") ?: [], 'no half-written file is left');

// A file that is not the data (an error page) is refused, and what was there stays
$error = $data->update(fn(string $url): string => '<html>rate limited</html>', '2026-11-20');
same(true, str_contains((string) $error, 'is not the routing data'), 'a page that is not the data is refused: ' . $error);
same('2026-11-19', $data->updatedOn(), 'the day stays');
same(str_repeat('a', 150_000), file_get_contents("$dir/geoip.dat"), 'and so do the files');

// One of the two that cannot be had: neither is replaced
$error = $data->update(function (string $url) use ($good): string {
    if ($url === RoutingData::SOURCES['geosite.dat']) { throw new RuntimeException('answered 503'); }
    return str_repeat('c', 150_000);
}, '2026-11-21');
same(true, str_contains((string) $error, 'geosite.dat could not be fetched: answered 503'), 'a failed fetch says which: ' . $error);
same(str_repeat('a', 150_000), file_get_contents("$dir/geoip.dat"), 'the one that came is not put in place alone');
same('2026-11-19', $data->updatedOn(), 'the day is still the last update\'s');

foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) { unlink("$dir/$f"); }
@rmdir($dir);
echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
