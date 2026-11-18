<?php
declare(strict_types=1);

/**
 * Reads what a person pastes to add a profile: one link of a proxy (vless://, vmess://, trojan://, ss://, hy2://,
 * hysteria2://, tuic://), or what a subscription's address answers, a list of them, as it is or in base64.
 */
final class Profiles
{
    /** Whether $text is the address of a subscription, not a link of one proxy. */
    public static function isSubscriptionUrl(string $text): bool
    {
        return (bool) preg_match('#^https?://\S+$#i', trim($text));
    }

    /**
     * The proxy a link names, null when it is not one this reads.
     * @return array{name: string, protocol: string, address: string, port: int}|null
     */
    public static function parseLink(string $link): ?array
    {
        $link = trim($link);
        if (!preg_match('#^([a-z0-9]+)://(.*)$#is', $link, $m)) {
            return null;
        }
        $scheme = strtolower($m[1]);
        $protocols = ['vless' => 'VLESS', 'trojan' => 'Trojan', 'ss' => 'Shadowsocks', 'hy2' => 'Hysteria2', 'hysteria2' => 'Hysteria2', 'tuic' => 'TUIC', 'vmess' => 'VMess'];
        if (!isset($protocols[$scheme])) {
            return null;
        }
        $protocol = $protocols[$scheme];
        $name = '';
        $rest = $m[2];
        if (($hash = strpos($rest, '#')) !== false) {
            $name = urldecode(substr($rest, $hash + 1));
            $rest = substr($rest, 0, $hash);
        }
        if ($scheme === 'vmess') { // base64 of a JSON
            $json = json_decode((string) base64_decode(strtr(explode('?', $rest)[0], '-_', '+/'), false), true);
            if (!is_array($json) || ($json['add'] ?? '') === '' || (int) ($json['port'] ?? 0) <= 0) {
                return null;
            }
            return ['name' => (string) ($json['ps'] ?? '') !== '' ? (string) $json['ps'] : "{$json['add']}:{$json['port']}", 'protocol' => $protocol, 'address' => (string) $json['add'], 'port' => (int) $json['port']];
        }
        $rest = explode('?', $rest)[0];
        $rest = explode('/', $rest)[0];
        if ($scheme === 'ss' && !str_contains($rest, '@')) { // the whole of it in base64: method:password@host:port
            $decoded = (string) base64_decode(strtr($rest, '-_', '+/'), false);
            $rest = $decoded;
        }
        $at = strrpos($rest, '@');
        $hostPort = $at === false ? $rest : substr($rest, $at + 1);
        if (preg_match('#^\[([^\]]+)\]:(\d+)$#', $hostPort, $h)) {
            [$address, $port] = [$h[1], (int) $h[2]];
        } elseif (preg_match('#^([^:]+):(\d+)$#', $hostPort, $h)) {
            [$address, $port] = [$h[1], (int) $h[2]];
        } else {
            return null;
        }
        if ($address === '' || $port <= 0 || $port > 65535) {
            return null;
        }
        return ['name' => $name !== '' ? $name : "{$address}:{$port}", 'protocol' => $protocol, 'address' => $address, 'port' => $port];
    }

    /**
     * The proxies a subscription's answer holds: its lines, which are links, or all of it in base64.
     * @return list<array{name: string, protocol: string, address: string, port: int}>
     */
    public static function parseSubscription(string $body): array
    {
        $text = trim($body);
        if (!str_contains($text, '://')) {
            $decoded = base64_decode(strtr(preg_replace('/\s+/', '', $text) ?? '', '-_', '+/'), false);
            if (is_string($decoded) && str_contains($decoded, '://')) {
                $text = $decoded;
            }
        }
        $nodes = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $node = self::parseLink($line);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }
}
