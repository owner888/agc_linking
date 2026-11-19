<?php
declare(strict_types=1);

/**
 * A configuration in the Surge family's spelling, which Shadowrocket reads and writes: a `[Proxy]` section of lines
 * `Name = type, server, port, key=value, …` (ss, vmess, trojan, hysteria2 here). Each proxy is turned into the share
 * link of its protocol (ShareLink); the other sections and the proxies this cannot run (snell, tuic, socks5, http, a
 * Shadowsocks with an obfs plugin) are left out.
 */
final class SurgeSubscription
{
    public static function isSurge(string $body): bool
    {
        return preg_match('/^\s*\[Proxy\]\s*$/mi', $body) === 1;
    }

    /** @return list<string> the links of the proxies the section holds, in order */
    public static function links(string $body): array
    {
        $links = [];
        $in = false;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
                $in = strcasecmp($m[1], 'Proxy') === 0;
                continue;
            }
            if (!$in || $line === '' || $line[0] === '#' || $line[0] === ';' || str_starts_with($line, '//')) {
                continue;
            }
            $link = self::link($line);
            if ($link !== null) {
                $links[] = $link;
            }
        }
        return $links;
    }

    /** One line of the section: "Name = type, server, port, key=value, …". */
    public static function link(string $line): ?string
    {
        $eq = strpos($line, '=');
        if ($eq === false) {
            return null;
        }
        $name = trim(substr($line, 0, $eq));
        $fields = array_map('trim', explode(',', substr($line, $eq + 1)));
        if (count($fields) < 3) {
            return null;
        }
        [$type, $server, $port] = [strtolower($fields[0]), $fields[1], (int) $fields[2]];
        if ($server === '' || $port <= 0 || $port > 65535) {
            return null;
        }
        $o = [];
        foreach (array_slice($fields, 3) as $f) {
            if (($p = strpos($f, '=')) !== false) {
                $o[strtolower(trim(substr($f, 0, $p)))] = trim(substr($f, $p + 1), " \t\"");
            }
        }
        $base = ['name' => $name, 'server' => $server, 'port' => $port];
        $on = static fn(string $k): bool => in_array(strtolower($o[$k] ?? ''), ['true', '1', 'yes'], true);
        $ws = $on('ws');
        $path = $ws ? ($o['ws-path'] ?? '') : '';
        $hostHeader = '';
        if ($ws) { // ws-headers=Host:example.com|User-Agent:x
            foreach (explode('|', $o['ws-headers'] ?? '') as $h) {
                if (strncasecmp(trim($h), 'host:', 5) === 0) { $hostHeader = trim(substr(trim($h), 5)); }
            }
        }
        $sni = $o['sni'] ?? $o['tls-host'] ?? '';
        switch ($type) {
            case 'ss':
            case 'shadowsocks':
                if (($o['obfs'] ?? '') !== '' || ($o['encrypt-method'] ?? '') === '' || ($o['password'] ?? '') === '') {
                    return null; // an obfs plugin is not run
                }
                return ShareLink::ss($base + ['method' => $o['encrypt-method'], 'password' => $o['password']]);
            case 'vmess':
                if (($o['username'] ?? '') === '') {
                    return null;
                }
                return ShareLink::vmess($base + [
                    'uuid' => $o['username'], 'aid' => (int) ($o['alterid'] ?? 0), 'cipher' => $o['encrypt-method'] ?? '',
                    'network' => $ws ? 'ws' : 'tcp', 'tls' => $on('tls') || $on('over-tls'), 'sni' => $sni, 'host' => $hostHeader, 'path' => $path,
                ]);
            case 'trojan':
                if (($o['password'] ?? '') === '') {
                    return null;
                }
                return ShareLink::trojan($base + ['password' => $o['password'], 'network' => $ws ? 'ws' : 'tcp', 'sni' => $sni, 'host' => $hostHeader, 'path' => $path]);
            case 'hysteria2':
            case 'hy2':
                if (($o['password'] ?? '') === '') {
                    return null;
                }
                return ShareLink::hysteria2($base + ['password' => $o['password'], 'sni' => $sni, 'obfs' => $o['obfs'] ?? '', 'obfsPassword' => $o['obfs-password'] ?? '']);
            default:
                return null; // snell, tuic, socks5, http, direct, …
        }
    }
}
