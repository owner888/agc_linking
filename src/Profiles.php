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
     * @return array{name: string, protocol: string, address: string, port: int, link: string}|null
     */
    public static function parseLink(string $link): ?array
    {
        $parts = ProxyLink::parse($link);
        if ($parts === null) {
            return null;
        }
        $protocols = ['vless' => 'VLESS', 'trojan' => 'Trojan', 'ss' => 'Shadowsocks', 'hy2' => 'Hysteria2', 'hysteria2' => 'Hysteria2', 'tuic' => 'TUIC', 'vmess' => 'VMess'];
        return ['name' => $parts['name'], 'protocol' => $protocols[$parts['scheme']], 'address' => $parts['host'], 'port' => $parts['port'], 'link' => trim($link)];
    }

    /**
     * The proxies a subscription's answer holds: its lines, which are links, or all of it in base64.
     * @return list<array{name: string, protocol: string, address: string, port: int, link: string}>
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
        if (ClashSubscription::isClash($text)) { // Clash's YAML: each proxy as the link of its protocol
            $text = implode("\n", ClashSubscription::links($text));
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
