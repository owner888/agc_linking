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
same(null, Vpn::parseTraffic(''), 'no tunnel, no traffic');
same(null, Vpn::parseTraffic('12'), 'one number');
same(null, Vpn::parseTraffic('a b'), 'not numbers');
same(false, Vpn::available(), 'no VPN off Android');

echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
