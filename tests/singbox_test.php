<?php
// A sing-box subscription read into proxies. Run: php tests/singbox_test.php
declare(strict_types=1);

require __DIR__ . '/../src/ShareLink.php';
require __DIR__ . '/../src/SingBoxSubscription.php';
require __DIR__ . '/../src/MiniYaml.php';
require __DIR__ . '/../src/ClashSubscription.php';
require __DIR__ . '/../src/ProxyLink.php';
require __DIR__ . '/../src/Profiles.php';
require __DIR__ . '/../src/XrayConfig.php';

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want, JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($got, JSON_UNESCAPED_SLASHES) . "\n"; }
}

$uuid = '11111111-2222-3333-4444-555555555555';
$doc = [
    'log' => ['level' => 'warn'],
    'outbounds' => [
        ['type' => 'selector', 'tag' => 'proxy', 'outbounds' => ['A', 'B']],
        ['type' => 'vless', 'tag' => 'Reality', 'server' => 'edge.example.com', 'server_port' => 443, 'uuid' => $uuid, 'flow' => 'xtls-rprx-vision',
            'tls' => ['enabled' => true, 'server_name' => 'www.example.org', 'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'reality' => ['enabled' => true, 'public_key' => 'PUBKEY', 'short_id' => 'ab12']]],
        ['type' => 'vless', 'tag' => 'VLESS WS', 'server' => 'ws.example.com', 'server_port' => 443, 'uuid' => $uuid,
            'tls' => ['enabled' => true, 'server_name' => 'ws.example.com', 'alpn' => ['h2', 'http/1.1']],
            'transport' => ['type' => 'ws', 'path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']]],
        ['type' => 'vmess', 'tag' => 'VMess gRPC', 'server' => 'vm.example.com', 'server_port' => 443, 'uuid' => $uuid, 'security' => 'auto', 'alter_id' => 0,
            'tls' => ['enabled' => true, 'server_name' => 'vm.example.com'], 'transport' => ['type' => 'grpc', 'service_name' => 'svc']],
        ['type' => 'trojan', 'tag' => 'Trojan', 'server' => 'tr.example.com', 'server_port' => 443, 'password' => 'secret', 'tls' => ['enabled' => true, 'server_name' => 'tr.example.com']],
        ['type' => 'shadowsocks', 'tag' => 'SS', 'server' => '203.0.113.1', 'server_port' => 8388, 'method' => 'aes-256-gcm', 'password' => 'pw'],
        ['type' => 'shadowsocks', 'tag' => 'SS Plugin', 'server' => '203.0.113.2', 'server_port' => 8388, 'method' => 'aes-256-gcm', 'password' => 'pw', 'plugin' => 'obfs-local'],
        ['type' => 'hysteria2', 'tag' => 'HY2', 'server' => 'hy.example.com', 'server_port' => 8443, 'password' => 'hypass',
            'obfs' => ['type' => 'salamander', 'password' => 'obfspw'], 'tls' => ['enabled' => true, 'server_name' => 'front.example.org', 'alpn' => ['h3']]],
        ['type' => 'tuic', 'tag' => 'TUIC', 'server' => '203.0.113.9', 'server_port' => 443, 'uuid' => $uuid, 'password' => 'x'],
        ['type' => 'direct', 'tag' => 'direct'],
        ['type' => 'block', 'tag' => 'block'],
    ],
];
$json = json_encode($doc, JSON_UNESCAPED_SLASHES);

same(true, SingBoxSubscription::isSingBox($json), 'a sing-box file is recognized');
same(false, SingBoxSubscription::isSingBox('{"proxies": []}'), 'other JSON is not');
same(false, SingBoxSubscription::isSingBox("proxies:\n  - {name: a}"), 'nor is Clash');
same(false, SingBoxSubscription::isSingBox('vless://a@b.com:443#x'), 'nor a link');
$links = SingBoxSubscription::links($json);
same(6, count($links), 'six proxies this runs: the selector, direct, block, TUIC and an SS with a plugin are left out');

$nodes = Profiles::parseSubscription($json);
same(['Reality', 'VLESS WS', 'VMess gRPC', 'Trojan', 'SS', 'HY2'], array_column($nodes, 'name'), 'the tags are the names, in order');
same(['VLESS', 'VLESS', 'VMess', 'Trojan', 'Shadowsocks', 'Hysteria2'], array_column($nodes, 'protocol'), 'and the protocols');

$o = fn(int $i) => XrayConfig::outbound($links[$i]);
$r = $o(0);
same(['vless', $uuid, 'xtls-rprx-vision', 'reality', 'PUBKEY', 'ab12', 'www.example.org', 'chrome'],
    [$r['protocol'], $r['settings']['vnext'][0]['users'][0]['id'], $r['settings']['vnext'][0]['users'][0]['flow'], $r['streamSettings']['security'],
        $r['streamSettings']['realitySettings']['publicKey'], $r['streamSettings']['realitySettings']['shortId'], $r['streamSettings']['realitySettings']['serverName'], $r['streamSettings']['realitySettings']['fingerprint']], 'vless reality');
$w = $o(1);
same(['ws', '/ws', 'cdn.example.com', 'tls', ['h2', 'http/1.1']], [$w['streamSettings']['network'], $w['streamSettings']['wsSettings']['path'], $w['streamSettings']['wsSettings']['headers']['Host'], $w['streamSettings']['security'], $w['streamSettings']['tlsSettings']['alpn']], 'vless ws with tls');
$m = $o(2);
same(['vmess', 'grpc', 'svc', 'tls'], [$m['protocol'], $m['streamSettings']['network'], $m['streamSettings']['grpcSettings']['serviceName'], $m['streamSettings']['security']], 'vmess grpc');
$t = $o(3);
same(['trojan', 'secret', 'tr.example.com'], [$t['protocol'], $t['settings']['servers'][0]['password'], $t['streamSettings']['tlsSettings']['serverName']], 'trojan');
$s = $o(4);
same(['shadowsocks', 'aes-256-gcm', 'pw', '203.0.113.1'], [$s['protocol'], $s['settings']['servers'][0]['method'], $s['settings']['servers'][0]['password'], $s['settings']['servers'][0]['address']], 'ss');
$h = $o(5);
same(['hysteria', 'hypass', 'front.example.org', [['type' => 'salamander', 'settings' => ['password' => 'obfspw']]]],
    [$h['protocol'], $h['streamSettings']['hysteriaSettings']['auth'], $h['streamSettings']['tlsSettings']['serverName'], $h['streamSettings']['finalmask']['udp']], 'hysteria2 with Salamander');

// an IPv6 server is written in brackets and read back
$v6 = SingBoxSubscription::link(['type' => 'trojan', 'tag' => 'v6', 'server' => '2001:db8::1', 'server_port' => 443, 'password' => 'p', 'tls' => ['enabled' => true]]);
same('2001:db8::1', ProxyLink::parse((string) $v6)['host'] ?? null, 'an IPv6 server survives the link');
same(null, SingBoxSubscription::link(['type' => 'vless', 'tag' => 'x', 'server' => 'a.com', 'server_port' => 0, 'uuid' => $uuid]), 'no port, no proxy');

echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
