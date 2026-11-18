<?php
declare(strict_types=1);

/**
 * What Xray runs from: the outbound of a proxy link, and the whole configuration around it (a local SOCKS inbound that
 * the tunnel feeds, the stats API, DNS, and routing by the mode: Rule, Global, Direct).
 */
final class XrayConfig
{
    /**
     * The outbound a link names, null when it is a protocol this does not run (TUIC, and Hysteria2 until its transport is added).
     * @return array<string, mixed>|null
     */
    public static function outbound(string $link, string $tag = 'proxy'): ?array
    {
        $p = ProxyLink::parse($link);
        if ($p === null) {
            return null;
        }
        $q = $p['query'];
        $out = match ($p['scheme']) {
            'vless' => $p['user'] === '' ? null : ['protocol' => 'vless', 'settings' => ['vnext' => [[
                'address' => $p['host'], 'port' => $p['port'],
                'users' => [array_filter(['id' => $p['user'], 'encryption' => $q['encryption'] ?? 'none', 'flow' => $q['flow'] ?? ''], static fn($v) => $v !== '')],
            ]]]],
            'vmess' => self::vmess($p),
            'trojan' => $p['user'] === '' ? null : ['protocol' => 'trojan', 'settings' => ['servers' => [['address' => $p['host'], 'port' => $p['port'], 'password' => rawurldecode($p['user'])]]]],
            'ss' => self::shadowsocks($p),
            default => null,
        };
        if ($out === null) {
            return null;
        }
        $out['tag'] = $tag;
        $stream = $p['scheme'] === 'vmess' ? self::vmessStream($p['json']) : self::stream($p);
        if ($stream !== []) {
            $out['streamSettings'] = $stream;
        }
        return $out;
    }

    /**
     * The configuration to run, as JSON.
     * @param string $mode 'Rule' | 'Global' | 'Direct'
     * @return array<string, mixed>|null null when the link is not one this runs
     */
    public static function build(string $link, string $mode = 'Rule', int $socksPort = 10808, int $apiPort = 10085): ?array
    {
        $proxy = self::outbound($link);
        if ($proxy === null) {
            return null;
        }
        $rules = [['type' => 'field', 'inboundTag' => ['api'], 'outboundTag' => 'api']];
        $rules[] = ['type' => 'field', 'ip' => ['geoip:private'], 'outboundTag' => 'direct'];
        if ($mode === 'Rule') {
            $rules[] = ['type' => 'field', 'domain' => ['geosite:cn'], 'outboundTag' => 'direct'];
            $rules[] = ['type' => 'field', 'ip' => ['geoip:cn'], 'outboundTag' => 'direct'];
        }
        $rules[] = ['type' => 'field', 'network' => 'tcp,udp', 'outboundTag' => $mode === 'Direct' ? 'direct' : 'proxy'];
        return [
            'log' => ['loglevel' => 'warning'],
            'stats' => new stdClass(),
            'api' => ['tag' => 'api', 'services' => ['StatsService']],
            'policy' => ['system' => ['statsOutboundUplink' => true, 'statsOutboundDownlink' => true]],
            'dns' => ['servers' => $mode === 'Rule'
                ? [['address' => '223.5.5.5', 'domains' => ['geosite:cn'], 'expectIPs' => ['geoip:cn']], '1.1.1.1', '8.8.8.8']
                : ['1.1.1.1', '8.8.8.8']],
            'inbounds' => [
                ['tag' => 'socks', 'listen' => '127.0.0.1', 'port' => $socksPort, 'protocol' => 'socks',
                    'settings' => ['auth' => 'noauth', 'udp' => true],
                    'sniffing' => ['enabled' => true, 'destOverride' => ['http', 'tls', 'quic'], 'routeOnly' => true]],
                ['tag' => 'api', 'listen' => '127.0.0.1', 'port' => $apiPort, 'protocol' => 'dokodemo-door', 'settings' => ['address' => '127.0.0.1']],
            ],
            'outbounds' => [$proxy, ['tag' => 'direct', 'protocol' => 'freedom'], ['tag' => 'block', 'protocol' => 'blackhole']],
            'routing' => ['domainStrategy' => 'IPIfNonMatch', 'rules' => $rules],
        ];
    }

    /** @param array<string, mixed> $p */
    private static function vmess(array $p): ?array
    {
        $j = $p['json'];
        if (($j['id'] ?? '') === '') {
            return null;
        }
        return ['protocol' => 'vmess', 'settings' => ['vnext' => [[
            'address' => $p['host'], 'port' => $p['port'],
            'users' => [['id' => (string) $j['id'], 'alterId' => (int) ($j['aid'] ?? 0), 'security' => (string) ($j['scy'] ?? 'auto')]],
        ]]]];
    }

    /** @param array<string, mixed> $p */
    private static function shadowsocks(array $p): ?array
    {
        [$method, $password] = [$p['user'], $p['password']];
        if ($password === '') { // SIP002: the user is the base64 of method:password
            $decoded = base64_decode(strtr($p['user'], '-_', '+/'), false);
            if (!is_string($decoded) || !str_contains($decoded, ':')) {
                return null;
            }
            [$method, $password] = explode(':', $decoded, 2);
        }
        if ($method === '' || $password === '') {
            return null;
        }
        return ['protocol' => 'shadowsocks', 'settings' => ['servers' => [['address' => $p['host'], 'port' => $p['port'], 'method' => $method, 'password' => $password]]]];
    }

    /**
     * The transport and security of a vless or trojan link, from its query: standard names (type, security, sni, fp,
     * pbk, sid) and Shadowrocket's (peer, fingerprint, tls=1, xtls=2).
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function stream(array $p): array
    {
        $q = $p['query'];
        $network = strtolower($q['type'] ?? $q['obfs'] ?? 'tcp');
        $network = match ($network) { 'h2' => 'http', 'splithttp' => 'xhttp', '' => 'tcp', default => $network };
        $security = strtolower($q['security'] ?? '');
        if ($security === '') {
            $security = isset($q['pbk']) ? 'reality' : (($q['tls'] ?? '') === '1' || ($q['xtls'] ?? '') !== '' || $p['scheme'] === 'trojan' ? 'tls' : 'none');
        }
        $stream = ['network' => $network, 'security' => $security];
        $sni = $q['sni'] ?? $q['peer'] ?? $q['serverName'] ?? '';
        $fingerprint = $q['fp'] ?? $q['fingerprint'] ?? '';
        if ($security === 'tls') {
            $stream['tlsSettings'] = array_filter([
                'serverName' => $sni !== '' ? $sni : $p['host'],
                'fingerprint' => $fingerprint,
                'alpn' => isset($q['alpn']) && $q['alpn'] !== '' ? explode(',', $q['alpn']) : null,
                'allowInsecure' => in_array($q['allowInsecure'] ?? $q['insecure'] ?? '', ['1', 'true'], true) ? true : null,
            ], static fn($v) => $v !== '' && $v !== null);
        } elseif ($security === 'reality') {
            $stream['realitySettings'] = array_filter([
                'serverName' => $sni !== '' ? $sni : $p['host'],
                'fingerprint' => $fingerprint !== '' ? $fingerprint : 'chrome',
                'publicKey' => $q['pbk'] ?? '',
                'shortId' => $q['sid'] ?? '',
                'spiderX' => $q['spx'] ?? '',
            ], static fn($v) => $v !== '');
        }
        $path = $q['path'] ?? '';
        $host = $q['host'] ?? '';
        switch ($network) {
            case 'ws':
                $stream['wsSettings'] = array_filter(['path' => $path !== '' ? $path : '/', 'headers' => $host !== '' ? ['Host' => $host] : null], static fn($v) => $v !== null);
                break;
            case 'httpupgrade':
                $stream['httpupgradeSettings'] = array_filter(['path' => $path !== '' ? $path : '/', 'host' => $host], static fn($v) => $v !== '');
                break;
            case 'xhttp':
                $stream['xhttpSettings'] = array_filter(['path' => $path !== '' ? $path : '/', 'host' => $host, 'mode' => $q['mode'] ?? ''], static fn($v) => $v !== '');
                break;
            case 'grpc':
                $stream['grpcSettings'] = array_filter(['serviceName' => $q['serviceName'] ?? '', 'multiMode' => ($q['mode'] ?? '') === 'multi' ? true : null], static fn($v) => $v !== '' && $v !== null);
                break;
            case 'http':
                $stream['httpSettings'] = array_filter(['path' => $path !== '' ? $path : '/', 'host' => $host !== '' ? explode(',', $host) : null], static fn($v) => $v !== null);
                break;
            case 'kcp':
                $stream['kcpSettings'] = array_filter(['header' => ['type' => $q['headerType'] ?? 'none'], 'seed' => $q['seed'] ?? ''], static fn($v) => $v !== '');
                break;
        }
        return $stream;
    }

    /**
     * @param array<string, mixed> $j the JSON of a vmess link
     * @return array<string, mixed>
     */
    private static function vmessStream(array $j): array
    {
        $network = (string) ($j['net'] ?? 'tcp');
        $q = ['type' => $network, 'path' => (string) ($j['path'] ?? ''), 'host' => (string) ($j['host'] ?? ''),
            'sni' => (string) ($j['sni'] ?? ''), 'fp' => (string) ($j['fp'] ?? ''), 'alpn' => (string) ($j['alpn'] ?? ''),
            'security' => ($j['tls'] ?? '') === 'tls' ? 'tls' : 'none', 'serviceName' => (string) ($j['path'] ?? '')];
        return self::stream(['scheme' => 'vmess', 'host' => (string) ($j['add'] ?? ''), 'query' => $q]);
    }
}
