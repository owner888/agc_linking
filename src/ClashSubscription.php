<?php
declare(strict_types=1);

/**
 * A Clash (Mihomo) subscription: a YAML file whose `proxies:` are maps (type: vless, server: …). Each is turned into
 * the share link of its own protocol (vless://, vmess://, trojan://, ss://, hysteria2://), which is what the rest of
 * the app reads and runs; the rest of the file (groups, rules) is of no use here and is not read. A proxy this cannot
 * run (a plugin of Shadowsocks, TUIC, WireGuard, …) is left out.
 */
final class ClashSubscription
{
    public static function isClash(string $body): bool
    {
        return preg_match('/^proxies:/m', $body) === 1;
    }

    /** @return list<string> the links of the proxies it holds, in order */
    public static function links(string $body): array
    {
        $links = [];
        foreach (self::proxies($body) as $proxy) {
            $link = is_array($proxy) ? self::link($proxy) : null;
            if ($link !== null) {
                $links[] = $link;
            }
        }
        return $links;
    }

    /** @return list<mixed> */
    private static function proxies(string $body): array
    {
        // Only the proxies' section is read: the groups and rules after it may use what MiniYaml does not.
        $section = [];
        $in = false;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!$in) {
                $in = preg_match('/^proxies:\s*(#.*)?$/', $line) === 1;
                if ($in) { $section[] = 'proxies:'; }
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_-]+:/', $line) === 1) {
                break;
            }
            $section[] = $line;
        }
        $doc = MiniYaml::parse(implode("\n", $section));
        return is_array($doc) && is_array($doc['proxies'] ?? null) ? array_values($doc['proxies']) : [];
    }

    /** @param array<string, mixed> $p one proxy of the file */
    public static function link(array $p): ?string
    {
        $server = self::str($p, 'server');
        $port = (int) ($p['port'] ?? 0);
        $name = self::str($p, 'name');
        if ($server === '' || $port <= 0 || $port > 65535) {
            return null;
        }
        $host = str_contains($server, ':') && !str_starts_with($server, '[') ? "[$server]" : $server; // an IPv6 address
        $frag = $name !== '' ? '#' . rawurlencode($name) : '';
        switch (strtolower(self::str($p, 'type'))) {
            case 'ss':
                if (self::str($p, 'plugin') !== '' || self::str($p, 'cipher') === '' || self::str($p, 'password') === '') {
                    return null; // a SIP003 plugin is not run
                }
                return 'ss://' . rtrim(base64_encode(self::str($p, 'cipher') . ':' . self::str($p, 'password')), '=') . "@$host:$port$frag";
            case 'vmess':
                if (self::str($p, 'uuid') === '') {
                    return null;
                }
                $net = self::str($p, 'network') ?: 'tcp';
                [$path, $hostHeader, $service] = self::transport($p, $net);
                $json = array_filter([
                    'v' => '2', 'ps' => $name, 'add' => $server, 'port' => (string) $port, 'id' => self::str($p, 'uuid'),
                    'aid' => (string) (int) ($p['alterId'] ?? 0), 'scy' => self::str($p, 'cipher') ?: 'auto', 'net' => $net, 'type' => 'none',
                    'host' => $hostHeader, 'path' => $net === 'grpc' ? $service : $path,
                    'tls' => self::flag($p, 'tls') ? 'tls' : '', 'sni' => self::str($p, 'servername'),
                    'fp' => self::str($p, 'client-fingerprint'), 'alpn' => self::list($p, 'alpn'),
                ], static fn($v) => $v !== '');
                return 'vmess://' . base64_encode(json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            case 'vless':
                if (self::str($p, 'uuid') === '') {
                    return null;
                }
                $net = self::str($p, 'network') ?: 'tcp';
                [$path, $hostHeader, $service] = self::transport($p, $net);
                $reality = is_array($p['reality-opts'] ?? null) ? $p['reality-opts'] : [];
                $security = $reality !== [] ? 'reality' : (self::flag($p, 'tls') ? 'tls' : 'none');
                return 'vless://' . rawurlencode(self::str($p, 'uuid')) . "@$host:$port?" . self::query([
                    'type' => $net, 'security' => $security, 'encryption' => self::str($p, 'encryption') ?: 'none',
                    'flow' => self::str($p, 'flow'), 'sni' => self::str($p, 'servername') ?: self::str($p, 'sni'),
                    'fp' => self::str($p, 'client-fingerprint'), 'alpn' => self::list($p, 'alpn'),
                    'pbk' => self::str($reality, 'public-key'), 'sid' => self::str($reality, 'short-id'),
                    'pinSHA256' => self::str($p, 'fingerprint'),
                    'path' => $path, 'host' => $hostHeader, 'serviceName' => $service,
                ]) . $frag;
            case 'trojan':
                if (self::str($p, 'password') === '') {
                    return null;
                }
                $net = self::str($p, 'network') ?: 'tcp';
                [$path, $hostHeader, $service] = self::transport($p, $net);
                return 'trojan://' . rawurlencode(self::str($p, 'password')) . "@$host:$port?" . self::query([
                    'type' => $net, 'security' => 'tls', 'sni' => self::str($p, 'sni') ?: self::str($p, 'servername'),
                    'fp' => self::str($p, 'client-fingerprint'), 'alpn' => self::list($p, 'alpn'),
                    'pinSHA256' => self::str($p, 'fingerprint'),
                    'path' => $path, 'host' => $hostHeader, 'serviceName' => $service,
                ]) . $frag;
            case 'hysteria2':
            case 'hy2':
                if (self::str($p, 'password') === '') {
                    return null;
                }
                return 'hysteria2://' . rawurlencode(self::str($p, 'password')) . "@$host:$port?" . self::query([
                    'sni' => self::str($p, 'sni') ?: self::str($p, 'servername'),
                    'pinSHA256' => self::str($p, 'fingerprint'), 'alpn' => self::list($p, 'alpn'),
                    'obfs' => self::str($p, 'obfs'), 'obfs-password' => self::str($p, 'obfs-password'),
                ]) . $frag;
            default:
                return null; // tuic, hysteria (v1), wireguard, ssr, snell, …
        }
    }

    /** @return array{0: string, 1: string, 2: string} the path, the Host header and the gRPC service name of the transport's options */
    private static function transport(array $p, string $net): array
    {
        $opts = $p[$net . '-opts'] ?? null;
        $opts = is_array($opts) ? $opts : [];
        if ($net === 'ws' || $net === 'httpupgrade') {
            $headers = is_array($opts['headers'] ?? null) ? $opts['headers'] : [];
            $hostHeader = '';
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === 'host') { $hostHeader = is_array($v) ? (string) ($v[0] ?? '') : (string) $v; }
            }
            return [self::str($opts, 'path'), $hostHeader, ''];
        }
        if ($net === 'h2') {
            return [self::str($opts, 'path'), self::list($opts, 'host'), ''];
        }
        if ($net === 'grpc') {
            return ['', '', self::str($opts, 'grpc-service-name')];
        }
        return ['', '', ''];
    }

    /** @param array<string, string> $q @return string the query, what is empty left out */
    private static function query(array $q): string
    {
        $parts = [];
        foreach ($q as $k => $v) {
            if ($v !== '') {
                $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
        }
        return implode('&', $parts);
    }

    private static function str(array $a, string $key): string
    {
        $v = $a[$key] ?? '';
        return is_scalar($v) && !is_bool($v) ? trim((string) $v) : '';
    }

    private static function flag(array $a, string $key): bool
    {
        return ($a[$key] ?? false) === true;
    }

    /** A list written as a list or as one string, as one comma-separated string. */
    private static function list(array $a, string $key): string
    {
        $v = $a[$key] ?? '';
        return is_array($v) ? implode(',', array_map('strval', array_filter($v, 'is_scalar'))) : (is_scalar($v) ? (string) $v : '');
    }
}
