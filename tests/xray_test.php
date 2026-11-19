<?php
// Xray configuration from links, no UI. Run: php tests/xray_test.php
declare(strict_types=1);

require __DIR__ . '/../src/ProxyLink.php';
require __DIR__ . '/../src/Profiles.php';
require __DIR__ . '/../src/XrayConfig.php';

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want) . ', got ' . json_encode($got) . "\n"; }
}

$uuid = '11111111-2222-3333-4444-555555555555';

// vless, the standard spelling, with Reality
$o = XrayConfig::outbound("vless://$uuid@example.com:443?type=tcp&security=reality&sni=www.example.org&fp=firefox&pbk=KEY&sid=ab12&flow=xtls-rprx-vision#N");
same('vless', $o['protocol'], 'vless protocol');
same($uuid, $o['settings']['vnext'][0]['users'][0]['id'], 'vless id');
same('xtls-rprx-vision', $o['settings']['vnext'][0]['users'][0]['flow'], 'vless flow');
same('reality', $o['streamSettings']['security'], 'reality');
same(['serverName' => 'www.example.org', 'fingerprint' => 'firefox', 'publicKey' => 'KEY', 'shortId' => 'ab12'], $o['streamSettings']['realitySettings'], 'reality settings');

// Shadowrocket's spelling: user and host in base64, peer, tls=1, xtls=2
$b = rtrim(base64_encode("none:$uuid@203.0.113.7:443"), '=');
$link = "vless://$b?remarks=Node-A&tls=1&peer=www.example.org&udp=1&xtls=2&pbk=KEY&sid=ab12&fingerprint=chrome";
$p = ProxyLink::parse($link);
same(['203.0.113.7', 443, $uuid, 'Node-A'], [$p['host'], $p['port'], $p['user'], $p['name']], 'base64 vless: host, port, id, name');
same(['name' => 'Node-A', 'protocol' => 'VLESS', 'address' => '203.0.113.7', 'port' => 443, 'link' => $link], Profiles::parseLink($link), 'base64 vless as a profile');
$o = XrayConfig::outbound($link);
same('reality', $o['streamSettings']['security'], 'pbk means reality');
same('www.example.org', $o['streamSettings']['realitySettings']['serverName'], 'peer is the server name');
same('chrome', $o['streamSettings']['realitySettings']['fingerprint'], 'fingerprint');
same($uuid, $o['settings']['vnext'][0]['users'][0]['id'], 'the id is not "none"');
same('xtls-rprx-vision', $o['settings']['vnext'][0]['users'][0]['flow'], 'Shadowrocket\'s xtls=2 is the Vision flow');

// ws over tls
$o = XrayConfig::outbound("vless://$uuid@example.com:443?type=ws&security=tls&sni=s.example.com&path=%2Fws&host=h.example.com");
same(['path' => '/ws', 'headers' => ['Host' => 'h.example.com']], $o['streamSettings']['wsSettings'], 'ws settings');
same('s.example.com', $o['streamSettings']['tlsSettings']['serverName'], 'tls server name');

// trojan: tls by default; shadowsocks in both spellings; vmess
$o = XrayConfig::outbound('trojan://pw@example.com:8443?sni=t.example.com#T');
same(['trojan', 'pw', 'tls'], [$o['protocol'], $o['settings']['servers'][0]['password'], $o['streamSettings']['security']], 'trojan');
$o = XrayConfig::outbound('ss://' . base64_encode('aes-256-gcm:pw') . '@example.com:8388#S');
same(['aes-256-gcm', 'pw'], [$o['settings']['servers'][0]['method'], $o['settings']['servers'][0]['password']], 'ss, the user in base64');
$o = XrayConfig::outbound('ss://' . base64_encode('chacha20-ietf-poly1305:pw@example.com:8388') . '#S');
same(['example.com', 8388], [$o['settings']['servers'][0]['address'], $o['settings']['servers'][0]['port']], 'ss, all of it in base64');
$o = XrayConfig::outbound('vmess://' . base64_encode(json_encode(['ps' => 'V', 'add' => 'v.example.com', 'port' => '443', 'id' => $uuid, 'aid' => '0', 'net' => 'ws', 'path' => '/p', 'host' => 'h.example.com', 'tls' => 'tls'])));
same(['vmess', 'ws', 'tls', '/p'], [$o['protocol'], $o['streamSettings']['network'], $o['streamSettings']['security'], $o['streamSettings']['wsSettings']['path']], 'vmess over ws and tls');
same(null, XrayConfig::outbound('tuic://u:p@example.com:443'), 'tuic is not run');
same(null, XrayConfig::outbound('nonsense'), 'not a link');

// the whole configuration
$c = XrayConfig::build("vless://$uuid@example.com:443?security=none", 'Rule', 10808, 10085);
same(['socks', 10808], [$c['inbounds'][0]['tag'], $c['inbounds'][0]['port']], 'socks inbound');
same(['proxy', 'direct', 'block'], array_column($c['outbounds'], 'tag'), 'outbounds');
$last = end($c['routing']['rules']);
same('proxy', $last['outboundTag'], 'rule mode ends on the proxy');
same(true, in_array('geoip:cn', array_merge(...array_map(static fn($r) => $r['ip'] ?? [], $c['routing']['rules'])), true), 'rule mode sends cn direct');
$g = XrayConfig::build("vless://$uuid@example.com:443", 'Global');
same(false, in_array('geoip:cn', array_merge(...array_map(static fn($r) => $r['ip'] ?? [], $g['routing']['rules'])), true), 'global mode does not');
$d = XrayConfig::build("vless://$uuid@example.com:443", 'Direct');
same('direct', end($d['routing']['rules'])['outboundTag'] ?? '', 'direct mode ends direct');
$d['routing']['rules'][] = 1; // stays valid JSON
same(true, json_encode($c) !== false && str_contains((string) json_encode($c), '"stats":{}'), 'stats is an empty object');

// The probe: every proxy behind a SOCKS inbound of its own, one that is not run left out with port 0
$probe = XrayConfig::probe(["vless://$uuid@a.example.com:443#A", 'tuic://x@b.example.com:443#B', "trojan://pw@c.example.com:443#C"], 10900);
same([10900, 0, 10902], $probe['ports'], 'probe ports, 0 for a proxy that is not run');
same(['in0', 'in2'], array_column($probe['config']['inbounds'], 'tag'), 'probe inbounds');
same(['p0', 'p2'], array_column($probe['config']['outbounds'], 'tag'), 'probe outbounds');
same(['in2'], $probe['config']['routing']['rules'][1]['inboundTag'], 'probe routes an inbound');
same('p2', $probe['config']['routing']['rules'][1]['outboundTag'], 'to its own outbound');
same(false, $probe['config']['inbounds'][0]['settings']['udp'], 'probe inbounds take no UDP');
same('freedom', XrayConfig::probe(['nonsense'], 10900)['config']['outbounds'][0]['protocol'], 'probe with nothing to run still has an outbound');

echo "\n$count checks, $failed failed\n";
exit($failed > 0 ? 1 : 0);
