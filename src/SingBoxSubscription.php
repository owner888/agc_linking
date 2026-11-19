<?php
declare(strict_types=1);

/**
 * A sing-box subscription: a JSON file whose `outbounds` are proxies (type: vless, server, server_port, tls, transport).
 * Each is turned into the share link of its protocol (ShareLink), as ClashSubscription does; the outbounds that are no
 * proxy (selector, urltest, direct, block, dns) and the protocols this cannot run (TUIC, WireGuard, SSH, …) are left out.
 */
final class SingBoxSubscription
{
    public static function isSingBox(string $body): bool
    {
        return self::outbounds($body) !== null;
    }

    /** @return list<string> the links of the proxies it holds, in order */
    public static function links(string $body): array
    {
        $links = [];
        foreach (self::outbounds($body) ?? [] as $o) {
            $link = is_array($o) ? self::link($o) : null;
            if ($link !== null) {
                $links[] = $link;
            }
        }
        return $links;
    }

    /** @return ?list<mixed> */
    private static function outbounds(string $body): ?array
    {
        $text = trim($body);
        if ($text === '' || $text[0] !== '{') {
            return null;
        }
        $doc = json_decode($text, true);
        return is_array($doc) && is_array($doc['outbounds'] ?? null) ? array_values($doc['outbounds']) : null;
    }

    /** @param array<string, mixed> $o one outbound */
    public static function link(array $o): ?string
    {
        $server = self::str($o, 'server');
        $port = (int) ($o['server_port'] ?? 0);
        if ($server === '' || $port <= 0 || $port > 65535) {
            return null;
        }
        $base = ['name' => self::str($o, 'tag'), 'server' => $server, 'port' => $port];
        $tls = is_array($o['tls'] ?? null) ? $o['tls'] : [];
        $tlsOn = ($tls['enabled'] ?? false) === true;
        $reality = is_array($tls['reality'] ?? null) ? $tls['reality'] : [];
        $realityOn = ($reality['enabled'] ?? false) === true;
        $utls = is_array($tls['utls'] ?? null) ? $tls['utls'] : [];
        $common = [
            'sni' => self::str($tls, 'server_name'), 'alpn' => self::list($tls, 'alpn'),
            'fp' => ($utls['enabled'] ?? false) === true ? self::str($utls, 'fingerprint') : '',
        ];
        [$network, $path, $hostHeader, $service] = self::transport($o);
        switch (strtolower(self::str($o, 'type'))) {
            case 'shadowsocks':
                if (self::str($o, 'plugin') !== '' || self::str($o, 'method') === '' || self::str($o, 'password') === '') {
                    return null;
                }
                return ShareLink::ss($base + ['method' => self::str($o, 'method'), 'password' => self::str($o, 'password')]);
            case 'vmess':
                if (self::str($o, 'uuid') === '') {
                    return null;
                }
                return ShareLink::vmess($base + $common + [
                    'uuid' => self::str($o, 'uuid'), 'aid' => (int) ($o['alter_id'] ?? 0), 'cipher' => self::str($o, 'security'),
                    'network' => $network, 'tls' => $tlsOn, 'host' => $hostHeader, 'path' => $network === 'grpc' ? $service : $path,
                ]);
            case 'vless':
                if (self::str($o, 'uuid') === '') {
                    return null;
                }
                return ShareLink::vless($base + $common + [
                    'uuid' => self::str($o, 'uuid'), 'network' => $network, 'flow' => self::str($o, 'flow'),
                    'security' => $realityOn ? 'reality' : ($tlsOn ? 'tls' : 'none'),
                    'pbk' => self::str($reality, 'public_key'), 'sid' => self::str($reality, 'short_id'),
                    'path' => $path, 'host' => $hostHeader, 'service' => $service,
                ]);
            case 'trojan':
                if (self::str($o, 'password') === '') {
                    return null;
                }
                return ShareLink::trojan($base + $common + [
                    'password' => self::str($o, 'password'), 'network' => $network, 'path' => $path, 'host' => $hostHeader, 'service' => $service,
                ]);
            case 'hysteria2':
                if (self::str($o, 'password') === '') {
                    return null;
                }
                $obfs = is_array($o['obfs'] ?? null) ? $o['obfs'] : [];
                return ShareLink::hysteria2($base + $common + [
                    'password' => self::str($o, 'password'),
                    'obfs' => self::str($obfs, 'type'), 'obfsPassword' => self::str($obfs, 'password'),
                ]);
            default:
                return null; // selector, urltest, direct, block, dns, tuic, wireguard, hysteria (v1), …
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} the network, the path, the Host header and the gRPC service name */
    private static function transport(array $o): array
    {
        $t = is_array($o['transport'] ?? null) ? $o['transport'] : [];
        $type = strtolower(self::str($t, 'type'));
        $network = match ($type) { '' => 'tcp', 'http' => 'h2', default => $type };
        $hostHeader = '';
        $headers = is_array($t['headers'] ?? null) ? $t['headers'] : [];
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'host') {
                $hostHeader = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }
        if ($hostHeader === '') {
            $hostHeader = self::list($t, 'host'); // the http transport's hosts
        }
        return [$network, self::str($t, 'path'), $hostHeader, self::str($t, 'service_name')];
    }

    private static function str(array $a, string $key): string
    {
        $v = $a[$key] ?? '';
        return is_scalar($v) && !is_bool($v) ? trim((string) $v) : '';
    }

    private static function list(array $a, string $key): string
    {
        $v = $a[$key] ?? '';
        return is_array($v) ? implode(',', array_map('strval', array_filter($v, 'is_scalar'))) : (is_scalar($v) ? (string) $v : '');
    }
}
