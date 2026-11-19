<?php
declare(strict_types=1);

/**
 * The share link of a proxy, from its parts, in the spellings ProxyLink reads: what a subscription of another format
 * (Clash's YAML, sing-box's JSON) is turned into, so that the app has one kind of proxy to read and run.
 * An empty part is left out.
 */
final class ShareLink
{
    /** @param array{name?: string, server: string, port: int} $n */
    private static function endpoint(array $n): string
    {
        $server = $n['server'];
        return (str_contains($server, ':') && !str_starts_with($server, '[') ? "[$server]" : $server) . ':' . $n['port']; // an IPv6 address in brackets
    }

    private static function fragment(array $n): string
    {
        return ($n['name'] ?? '') !== '' ? '#' . rawurlencode($n['name']) : '';
    }

    /** @param array<string, string> $q */
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

    /** @param array{name?: string, server: string, port: int, method: string, password: string} $n */
    public static function ss(array $n): string
    {
        return 'ss://' . rtrim(base64_encode($n['method'] . ':' . $n['password']), '=') . '@' . self::endpoint($n) . self::fragment($n);
    }

    /**
     * @param array{name?: string, server: string, port: int, uuid: string, aid?: int, cipher?: string, network?: string, tls?: bool,
     *     sni?: string, fp?: string, alpn?: string, host?: string, path?: string} $n
     */
    public static function vmess(array $n): string
    {
        $net = ($n['network'] ?? '') ?: 'tcp';
        $json = array_filter([
            'v' => '2', 'ps' => $n['name'] ?? '', 'add' => $n['server'], 'port' => (string) $n['port'], 'id' => $n['uuid'],
            'aid' => (string) ($n['aid'] ?? 0), 'scy' => ($n['cipher'] ?? '') ?: 'auto', 'net' => $net, 'type' => 'none',
            'host' => $n['host'] ?? '', 'path' => $n['path'] ?? '', 'tls' => ($n['tls'] ?? false) ? 'tls' : '',
            'sni' => $n['sni'] ?? '', 'fp' => $n['fp'] ?? '', 'alpn' => $n['alpn'] ?? '',
        ], static fn($v) => $v !== '');
        return 'vmess://' . base64_encode(json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array{name?: string, server: string, port: int, uuid: string, network?: string, security?: string, flow?: string,
     *     encryption?: string, sni?: string, fp?: string, alpn?: string, pbk?: string, sid?: string, pin?: string,
     *     path?: string, host?: string, service?: string} $n security: none, tls or reality
     */
    public static function vless(array $n): string
    {
        return 'vless://' . rawurlencode($n['uuid']) . '@' . self::endpoint($n) . '?' . self::query([
            'type' => ($n['network'] ?? '') ?: 'tcp', 'security' => ($n['security'] ?? '') ?: 'none', 'encryption' => ($n['encryption'] ?? '') ?: 'none',
            'flow' => $n['flow'] ?? '', 'sni' => $n['sni'] ?? '', 'fp' => $n['fp'] ?? '', 'alpn' => $n['alpn'] ?? '',
            'pbk' => $n['pbk'] ?? '', 'sid' => $n['sid'] ?? '', 'pinSHA256' => $n['pin'] ?? '',
            'path' => $n['path'] ?? '', 'host' => $n['host'] ?? '', 'serviceName' => $n['service'] ?? '',
        ]) . self::fragment($n);
    }

    /** @param array{name?: string, server: string, port: int, password: string, network?: string, sni?: string, fp?: string, alpn?: string, pin?: string, path?: string, host?: string, service?: string} $n */
    public static function trojan(array $n): string
    {
        return 'trojan://' . rawurlencode($n['password']) . '@' . self::endpoint($n) . '?' . self::query([
            'type' => ($n['network'] ?? '') ?: 'tcp', 'security' => 'tls', 'sni' => $n['sni'] ?? '', 'fp' => $n['fp'] ?? '',
            'alpn' => $n['alpn'] ?? '', 'pinSHA256' => $n['pin'] ?? '',
            'path' => $n['path'] ?? '', 'host' => $n['host'] ?? '', 'serviceName' => $n['service'] ?? '',
        ]) . self::fragment($n);
    }

    /** @param array{name?: string, server: string, port: int, password: string, sni?: string, alpn?: string, pin?: string, obfs?: string, obfsPassword?: string} $n */
    public static function hysteria2(array $n): string
    {
        return 'hysteria2://' . rawurlencode($n['password']) . '@' . self::endpoint($n) . '?' . self::query([
            'sni' => $n['sni'] ?? '', 'alpn' => $n['alpn'] ?? '', 'pinSHA256' => $n['pin'] ?? '',
            'obfs' => $n['obfs'] ?? '', 'obfs-password' => $n['obfsPassword'] ?? '',
        ]) . self::fragment($n);
    }
}
