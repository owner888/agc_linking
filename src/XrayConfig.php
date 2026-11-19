<?php
declare(strict_types=1);

/**
 * What Xray runs from: the outbound of a proxy link, and the whole configuration around it (a local SOCKS inbound that
 * the tunnel feeds, the stats API, DNS, and routing by the mode: Rule, Global, Direct).
 */
final class XrayConfig
{
    /**
     * The outbound a link names, null when it is a protocol this does not run (TUIC).
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
                'users' => [array_filter(['id' => $p['user'], 'encryption' => $q['encryption'] ?? 'none', 'flow' => $q['flow'] ?? (($q['xtls'] ?? '') === '2' ? 'xtls-rprx-vision' : '')], static fn($v) => $v !== '')],
            ]]]],
            'vmess' => self::vmess($p),
            'trojan' => $p['user'] === '' ? null : ['protocol' => 'trojan', 'settings' => ['servers' => [['address' => $p['host'], 'port' => $p['port'], 'password' => rawurldecode($p['user'])]]]],
            'ss' => self::shadowsocks($p),
            'hy2', 'hysteria2' => self::hysteria($p),
            default => null,
        };
        if ($out === null) {
            return null;
        }
        $out['tag'] = $tag;
        $stream = match ($p['scheme']) {
            'vmess' => self::vmessStream($p['json']),
            'hy2', 'hysteria2' => self::hysteriaStream($p),
            default => self::stream($p),
        };
        if ($stream !== []) {
            $out['streamSettings'] = $stream;
        }
        return $out;
    }

    /**
     * A configuration that holds every proxy of $links at once, each behind a SOCKS inbound of its own on 127.0.0.1
     * ($basePort, $basePort + 1, ...), to measure them through: a request to a port goes out by that proxy only.
     * @param list<string> $links
     * @return array{config: array<string, mixed>, ports: list<int>} ports in the order of $links, 0 for a link this does not run
     */
    public static function probe(array $links, int $basePort): array
    {
        $inbounds = $outbounds = $rules = $ports = [];
        foreach ($links as $i => $link) {
            $out = self::outbound($link, "p$i");
            if ($out === null) {
                $ports[] = 0;
                continue;
            }
            $port = $basePort + $i;
            $ports[] = $port;
            $outbounds[] = $out;
            $inbounds[] = ['tag' => "in$i", 'listen' => '127.0.0.1', 'port' => $port, 'protocol' => 'socks', 'settings' => ['auth' => 'noauth', 'udp' => false]];
            $rules[] = ['type' => 'field', 'inboundTag' => ["in$i"], 'outboundTag' => "p$i"];
        }
        return ['config' => [
            'log' => ['loglevel' => 'warning'],
            'dns' => ['servers' => ['1.1.1.1', '8.8.8.8']],
            'inbounds' => $inbounds,
            'outbounds' => $outbounds === [] ? [['tag' => 'direct', 'protocol' => 'freedom']] : $outbounds,
            'routing' => ['domainStrategy' => 'AsIs', 'rules' => $rules],
        ], 'ports' => $ports];
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

    /**
     * The certificate a link pins (pinSHA256 of a Hysteria2 link, Clash's fingerprint): its SHA-256 in hex, with or
     * without colons. Xray no longer has allowInsecure ("skip verifying the certificate"), so a link that asks for it
     * and pins nothing is verified like any other: a node whose certificate is good works, one that relies on being
     * unverified does not.
     * @param array<string, string> $q
     */
    private static function pin(array $q): string
    {
        $pin = strtolower(str_replace(':', '', $q['pinSHA256'] ?? $q['pcs'] ?? ''));
        return preg_match('/^[0-9a-f]{64}$/', $pin) === 1 ? $pin : '';
    }

    /** @param array<string, mixed> $p */
    private static function hysteria(array $p): ?array
    {
        if (self::hysteriaAuth($p) === '') {
            return null;
        }
        return ['protocol' => 'hysteria', 'settings' => ['version' => 2, 'address' => $p['host'], 'port' => $p['port']]];
    }

    /** The password of a Hysteria2 link: its user, with the part after a colon (user:password) when it has one. @param array<string, mixed> $p */
    private static function hysteriaAuth(array $p): string
    {
        return $p['password'] !== '' ? $p['user'] . ':' . $p['password'] : $p['user'];
    }

    /**
     * Hysteria2 is a transport of its own over QUIC: the password is its auth, TLS is always on (h3), and Salamander
     * obfuscation, when the link names it, is a mask over the UDP packets.
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function hysteriaStream(array $p): array
    {
        $q = $p['query'];
        $sni = $q['sni'] ?? $q['peer'] ?? '';
        $stream = [
            'network' => 'hysteria',
            'security' => 'tls',
            'tlsSettings' => array_filter([
                'serverName' => $sni !== '' ? $sni : $p['host'],
                'alpn' => isset($q['alpn']) && $q['alpn'] !== '' ? explode(',', $q['alpn']) : ['h3'],
                'pinnedPeerCertSha256' => self::pin($q),
            ], static fn($v) => $v !== null && $v !== ''),
            'hysteriaSettings' => ['version' => 2, 'auth' => self::hysteriaAuth($p)],
        ];
        if (($q['obfs'] ?? '') === 'salamander' && ($q['obfs-password'] ?? '') !== '') {
            $stream['finalmask'] = ['udp' => [['type' => 'salamander', 'settings' => ['password' => $q['obfs-password']]]]];
        }
        return $stream;
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
                'pinnedPeerCertSha256' => self::pin($q),
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
