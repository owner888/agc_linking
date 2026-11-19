<?php
// Shadowrocket's own spellings: its vmess:// link, and the [Proxy] lines of its (Surge-style) configuration. Run: php tests/shadowrocket_test.php
declare(strict_types=1);

foreach (['ShareLink', 'MiniYaml', 'ClashSubscription', 'SingBoxSubscription', 'SurgeSubscription', 'ProxyLink', 'Profiles', 'XrayConfig'] as $f) {
    require __DIR__ . "/../src/$f.php";
}

$failed = 0; $count = 0;
function same(mixed $want, mixed $got, string $what): void
{
    global $failed, $count;
    $count++;
    if ($want !== $got) { $failed++; echo "  FAIL: $what: expected " . json_encode($want, JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($got, JSON_UNESCAPED_SLASHES) . "\n"; }
}

$uuid = '11111111-2222-3333-4444-555555555555';

// --- vmess://base64(method:uuid@host:port)?remarks=…&obfs=websocket&obfsParam=…&path=…&tls=1&peer=…
$b = rtrim(base64_encode("chacha20-poly1305:$uuid@vm.example.com:443"), '=');
$link = "vmess://$b?remarks=SR%20Node&obfs=websocket&obfsParam=cdn.example.com&path=%2Fws&tls=1&peer=sni.example.org&alterId=0";
$p = ProxyLink::parse($link);
same(['vmess', 'vm.example.com', 443, 'SR Node', $uuid], [$p['scheme'], $p['host'], $p['port'], $p['name'], $p['user']], 'Shadowrocket vmess link');
$o = XrayConfig::outbound($link);
same(['vmess', $uuid, 'chacha20-poly1305', 'ws', '/ws', 'cdn.example.com', 'tls', 'sni.example.org'],
    [$o['protocol'], $o['settings']['vnext'][0]['users'][0]['id'], $o['settings']['vnext'][0]['users'][0]['security'], $o['streamSettings']['network'],
        $o['streamSettings']['wsSettings']['path'], $o['streamSettings']['wsSettings']['headers']['Host'], $o['streamSettings']['security'], $o['streamSettings']['tlsSettings']['serverName']], 'it runs as vmess over ws and tls');
$plain = XrayConfig::outbound('vmess://' . rtrim(base64_encode("auto:$uuid@203.0.113.5:8443"), '=') . '?remarks=Plain');
same(['tcp', 'none', 8443], [$plain['streamSettings']['network'] ?? 'tcp', $plain['streamSettings']['security'] ?? 'none', $plain['settings']['vnext'][0]['port']], 'one with no obfs and no tls is plain tcp');
$grpc = XrayConfig::outbound('vmess://' . rtrim(base64_encode("auto:$uuid@vm.example.com:443"), '=') . '?obfs=grpc&path=svc&tls=1');
same(['grpc', 'svc'], [$grpc['streamSettings']['network'], $grpc['streamSettings']['grpcSettings']['serviceName']], 'obfs=grpc takes the path as its service name');
// obfs=http: HTTP obfuscation of a plain TCP stream (a request for path to host), not a websocket
$http = XrayConfig::outbound('vmess://' . rtrim(base64_encode("auto:$uuid@vm.example.com:63711"), '=') . '?path=/download/updates&remarks=H&obfsParam=update.example.org&obfs=http&udp=1&alterId=0');
same(['tcp', 'none', 'http', ['/download/updates'], ['update.example.org']],
    [$http['streamSettings']['network'], $http['streamSettings']['security'], $http['streamSettings']['tcpSettings']['header']['type'],
        $http['streamSettings']['tcpSettings']['header']['request']['path'], $http['streamSettings']['tcpSettings']['header']['request']['headers']['Host']], 'obfs=http is dressed as an HTTP request');
$jsonHttp = XrayConfig::outbound('vmess://' . base64_encode(json_encode(['add' => 'vm.example.com', 'port' => '80', 'id' => $uuid, 'net' => 'tcp', 'type' => 'http', 'host' => 'h.example.org', 'path' => '/p'])));
same('http', $jsonHttp['streamSettings']['tcpSettings']['header']['type'] ?? null, 'so is v2rayN\'s type=http');
same('none', XrayConfig::outbound('vmess://' . rtrim(base64_encode("auto:$uuid@vm.example.com:443"), '=') . '?obfs=none')['streamSettings']['tcpSettings']['header']['type'] ?? 'none', 'obfs=none is not');

// vless with the user part ":uuid" (no method before it), tls=1 and xtls=2
$v = 'vless://' . rtrim(base64_encode(":$uuid@edge.example.com:443"), '=') . '?remarks=X&tls=1&peer=www.example.org&udp=1&xtls=2&pbk=KEY&sid=ab12';
$vo = XrayConfig::outbound($v);
same([$uuid, 'xtls-rprx-vision', 'reality', 'www.example.org'], [$vo['settings']['vnext'][0]['users'][0]['id'], $vo['settings']['vnext'][0]['users'][0]['flow'], $vo['streamSettings']['security'], $vo['streamSettings']['realitySettings']['serverName']], 'vless with an empty method before the uuid');

same('vm.example.com', ProxyLink::parse('vmess://' . rtrim(base64_encode(json_encode(['add' => 'vm.example.com', 'port' => '443', 'id' => $uuid, 'ps' => 'J'])), '=')) ['host'] ?? null, 'v2rayN\'s JSON form still reads');
same(null, ProxyLink::parse('vmess://' . rtrim(base64_encode('nonsense'), '=')), 'what is neither is not read');

// --- a configuration with [Proxy] lines
$conf = <<<CONF
[General]
loglevel = notify
dns-server = 1.1.1.1

[Proxy]
DIRECT = direct
// a comment
SS Node = ss, 203.0.113.1, 8388, encrypt-method=aes-256-gcm, password=pw1, udp-relay=true
SS Obfs = ss, 203.0.113.2, 8388, encrypt-method=aes-256-gcm, password=pw1, obfs=http, obfs-host=example.com
VMess WS = vmess, vm.example.com, 443, username=$uuid, ws=true, ws-path=/ws, ws-headers=Host:cdn.example.com|User-Agent:x, tls=true, sni=sni.example.org, skip-cert-verify=true
Trojan = trojan, tr.example.com, 443, password=secret, sni=tr.example.com
HY2 = hysteria2, hy.example.com, 8443, password=hypass, sni=front.example.org
Snell = snell, 203.0.113.9, 443, psk=x, version=4
Tuic = tuic, 203.0.113.10, 443, token=x

[Proxy Group]
Auto = url-test, SS Node, Trojan, url = http://www.gstatic.com/generate_204

[Rule]
FINAL, Auto
CONF;
same(true, SurgeSubscription::isSurge($conf), 'a [Proxy] section is recognized');
same(false, SurgeSubscription::isSurge("proxies:\n  - {name: a}"), 'Clash is not');
$links = SurgeSubscription::links($conf);
same(4, count($links), 'four proxies this runs: direct, an obfs plugin, snell and tuic are left out; other sections are not read');
$nodes = Profiles::parseSubscription($conf);
same(['SS Node', 'VMess WS', 'Trojan', 'HY2'], array_column($nodes, 'name'), 'the names, in order');
same(['Shadowsocks', 'VMess', 'Trojan', 'Hysteria2'], array_column($nodes, 'protocol'), 'and the protocols');
$ss = XrayConfig::outbound($links[0]);
same(['aes-256-gcm', 'pw1'], [$ss['settings']['servers'][0]['method'], $ss['settings']['servers'][0]['password']], 'ss');
$vm = XrayConfig::outbound($links[1]);
same(['ws', '/ws', 'cdn.example.com', 'tls', 'sni.example.org'], [$vm['streamSettings']['network'], $vm['streamSettings']['wsSettings']['path'], $vm['streamSettings']['wsSettings']['headers']['Host'], $vm['streamSettings']['security'], $vm['streamSettings']['tlsSettings']['serverName']], 'vmess ws, the Host header taken from ws-headers');
$tr = XrayConfig::outbound($links[2]);
same(['trojan', 'secret'], [$tr['protocol'], $tr['settings']['servers'][0]['password']], 'trojan');
$hy = XrayConfig::outbound($links[3]);
same(['hysteria', 'hypass', 'front.example.org'], [$hy['protocol'], $hy['streamSettings']['hysteriaSettings']['auth'], $hy['streamSettings']['tlsSettings']['serverName']], 'hysteria2');
same(null, SurgeSubscription::link('Bad = ss, host'), 'a line with no port is not a proxy');

echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
