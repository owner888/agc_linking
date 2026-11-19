<?php
// A Clash subscription read into proxies, and Hysteria2. Run: php tests/clash_test.php
declare(strict_types=1);

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

// --- MiniYaml
same(['a' => 1, 'b' => ['c', 'd'], 'e' => ['f' => true, 'g' => null]], MiniYaml::parse("a: 1\nb:\n  - c\n  - d\ne:\n  f: true\n  g:\n"), 'block mappings and sequences');
same(['k' => ['x' => 'a: b', 'y' => "it's", 'z' => [1, 'two']]], MiniYaml::parse("k: {x: \"a: b\", y: 'it''s', z: [1, two]}  # comment\n"), 'flow collections and quotes');
same([['n' => 'A', 'o' => ['p' => 'q']], ['n' => 'B']], MiniYaml::parse("- n: A\n  o:\n    p: q\n- n: B\n"), 'a sequence of mappings');
same(['host' => '1.2.3.4:80', 'url' => 'https://example.com/x#frag'], MiniYaml::parse("host: 1.2.3.4:80\nurl: https://example.com/x#frag\n"), 'colons and # inside plain scalars');
same(null, MiniYaml::parse("a: 1\n  b: 2\n"), 'what it cannot read is not read wrongly');
same(null, MiniYaml::parse(''), 'nothing');

// --- A Clash file: block and flow styles, a section after the proxies that this need not read
$uuid = '11111111-2222-3333-4444-555555555555';
$yaml = <<<YAML
port: 7890
mixed-port: 7891
proxies:
  - {name: "SS 1", type: ss, server: 203.0.113.1, port: 8388, cipher: aes-256-gcm, password: "pw:1"}
  - {name: SS Plugin, type: ss, server: 203.0.113.2, port: 8388, cipher: aes-256-gcm, password: x, plugin: obfs, plugin-opts: {mode: tls}}
  - name: VLESS Reality
    type: vless
    server: edge.example.com
    port: 443
    uuid: $uuid
    network: tcp
    tls: true
    flow: xtls-rprx-vision
    servername: www.example.org
    client-fingerprint: chrome
    reality-opts:
      public-key: PUBKEY
      short-id: ab12
  - name: VMess WS
    type: vmess
    server: vm.example.com
    port: 443
    uuid: $uuid
    alterId: 0
    cipher: auto
    network: ws
    tls: true
    servername: vm.example.com
    ws-opts:
      path: /ws
      headers:
        Host: cdn.example.com
  - name: Trojan gRPC
    type: trojan
    server: tr.example.com
    port: 443
    password: secret
    sni: tr.example.com
    network: grpc
    grpc-opts:
      grpc-service-name: svc
  - name: HY2
    type: hysteria2
    server: hy.example.com
    port: 8443
    password: hypass
    sni: front.example.org
    obfs: salamander
    obfs-password: obfspw
  - {name: TUIC, type: tuic, server: 203.0.113.9, port: 443, uuid: $uuid, password: x}
  - {name: Wg, type: wireguard, server: 203.0.113.10, port: 51820}
proxy-groups:
  - name: Auto
    type: url-test
    proxies: [SS 1, HY2]
    <<: *anchor-this-is-not-read
rules:
  - MATCH,Auto
YAML;
same(true, ClashSubscription::isClash($yaml), 'a Clash file is recognized');
same(false, ClashSubscription::isClash("vless://a@b.com:443#x\n"), 'a list of links is not');
$links = ClashSubscription::links($yaml);
same(5, count($links), 'five proxies this runs: SS with a plugin, TUIC and WireGuard are left out');

$nodes = Profiles::parseSubscription($yaml);
same(['SS 1', 'VLESS Reality', 'VMess WS', 'Trojan gRPC', 'HY2'], array_column($nodes, 'name'), 'the names are kept, in order');
same(['Shadowsocks', 'VLESS', 'VMess', 'Trojan', 'Hysteria2'], array_column($nodes, 'protocol'), 'and the protocols');

$out = fn(string $link) => XrayConfig::outbound($link);
$ss = $out($links[0]);
same(['shadowsocks', 'aes-256-gcm', 'pw:1', 8388], [$ss['protocol'], $ss['settings']['servers'][0]['method'], $ss['settings']['servers'][0]['password'], $ss['settings']['servers'][0]['port']], 'ss');
$vless = $out($links[1]);
same(['vless', $uuid, 'xtls-rprx-vision', 'reality'], [$vless['protocol'], $vless['settings']['vnext'][0]['users'][0]['id'], $vless['settings']['vnext'][0]['users'][0]['flow'], $vless['streamSettings']['security']], 'vless reality');
same(['PUBKEY', 'ab12', 'www.example.org', 'chrome'], [$vless['streamSettings']['realitySettings']['publicKey'], $vless['streamSettings']['realitySettings']['shortId'], $vless['streamSettings']['realitySettings']['serverName'], $vless['streamSettings']['realitySettings']['fingerprint']], 'its reality settings');
$vmess = $out($links[2]);
same(['vmess', 'ws', '/ws', 'cdn.example.com', 'tls'], [$vmess['protocol'], $vmess['streamSettings']['network'], $vmess['streamSettings']['wsSettings']['path'], $vmess['streamSettings']['wsSettings']['headers']['Host'], $vmess['streamSettings']['security']], 'vmess ws');
$trojan = $out($links[3]);
same(['trojan', 'grpc', 'svc'], [$trojan['protocol'], $trojan['streamSettings']['network'], $trojan['streamSettings']['grpcSettings']['serviceName']], 'trojan grpc');
$hy = $out($links[4]);
same(['hysteria', 2, 'hy.example.com', 8443], [$hy['protocol'], $hy['settings']['version'], $hy['settings']['address'], $hy['settings']['port']], 'hysteria2 outbound');
same(['hysteria', 'tls', 'hypass', 'front.example.org', ['h3']], [$hy['streamSettings']['network'], $hy['streamSettings']['security'], $hy['streamSettings']['hysteriaSettings']['auth'], $hy['streamSettings']['tlsSettings']['serverName'], $hy['streamSettings']['tlsSettings']['alpn']], 'its stream');
same([['type' => 'salamander', 'settings' => ['password' => 'obfspw']]], $hy['streamSettings']['finalmask']['udp'], 'Salamander is a mask over its UDP');

// --- Hysteria2 links
$pin = str_repeat('ab', 32);
$h = XrayConfig::outbound('hysteria2://user%3Apass@h.example.com:443?pinSHA256=' . implode(':', str_split($pin, 2)) . '#H');
same('user:pass', $h['streamSettings']['hysteriaSettings']['auth'], 'user:password is the auth as a whole');
same($pin, $h['streamSettings']['tlsSettings']['pinnedPeerCertSha256'], 'a pinned certificate, as hex');
same('h.example.com', $h['streamSettings']['tlsSettings']['serverName'], 'the server name is the host without an sni');
check_no_insecure($h, 'hysteria2');
$h2 = XrayConfig::outbound('hy2://pw@h.example.com:443?insecure=1');
check_no_insecure($h2, 'hy2 insecure=1');
$t = XrayConfig::outbound('trojan://pw@t.example.com:443?allowInsecure=1&sni=a.example.org');
check_no_insecure($t, 'trojan allowInsecure=1');
same(null, XrayConfig::outbound('hysteria2://@h.example.com:443'), 'no password, no outbound');
function check_no_insecure(array $o, string $what): void
{
    same(false, str_contains(json_encode($o), 'allowInsecure'), "$what: Xray refuses allowInsecure, so none is written");
}

echo $failed === 0 ? "ok: $count checks\n" : "FAILED: $failed of $count\n";
exit($failed === 0 ? 0 : 1);
