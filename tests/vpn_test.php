<?php
// The VPN's PHP side, what runs without a phone. Run: php tests/vpn_test.php
declare(strict_types=1);

require __DIR__ . '/../src/Vpn.php';

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want) . ', got ' . json_encode($got) . "\n"; }
}

same(['up' => 1200, 'down' => 3400000], Vpn::parseTraffic('1200 3400000'), 'traffic');
same(['up' => 0, 'down' => 0], Vpn::parseTraffic("0 0\n"), 'traffic with a newline');
same(['up' => 1, 'down' => 2, 'proxyUp' => 3, 'proxyDown' => 4, 'directUp' => 5, 'directDown' => 6], Vpn::parseTraffic('1 2 3 4 5 6'), 'traffic by outbound');
same(null, Vpn::parseTraffic('1 2 3'), 'a half split');
same(null, Vpn::parseTraffic(''), 'no tunnel, no traffic');
same(null, Vpn::parseTraffic('12'), 'one number');
same(null, Vpn::parseTraffic('a b'), 'not numbers');
same([12, -1, -2], Vpn::parseLatency('12,-1,-2'), 'latency answers');
same([], Vpn::parseLatency(''), 'no test, no answers');
same([['package' => 'org.example.a', 'label' => 'Alpha'], ['package' => 'org.example.b', 'label' => 'org.example.b']], Vpn::parseApps("org.example.a\tAlpha\norg.example.b\t\n\n"), 'installed apps, a missing name is the package');
same([], Vpn::parseApps(''), 'no apps');
same('0B/s', Vpn::formatSpeed(0), 'no speed');
same('512B/s', Vpn::formatSpeed(512.9), 'bytes a second');
same('1.5KB/s', Vpn::formatSpeed(1536), 'KB a second');
same('3.0MB/s', Vpn::formatSpeed(3 * 1024 * 1024), 'MB a second');
same('2.5GB', Vpn::formatBytes(2.5 * 1024 ** 3), 'GB');
same(false, Vpn::available(), 'no VPN off Android');

echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
