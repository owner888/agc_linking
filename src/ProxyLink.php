<?php
declare(strict_types=1);

/**
 * A link of one proxy split into its parts, in the spellings that are met: vless://uuid@host:port?…#name, the same
 * with its user and host in base64 (Shadowrocket's vless://base64(none:uuid@host:port)?remarks=…), ss:// with all of
 * it in base64 or only its user, vmess:// as the base64 of a JSON.
 */
final class ProxyLink
{
    private const SCHEMES = ['vless', 'vmess', 'trojan', 'ss', 'hy2', 'hysteria2', 'tuic'];

    /**
     * @return array{scheme: string, user: string, password: string, host: string, port: int, query: array<string, string>, name: string, json: array<string, mixed>}|null
     */
    public static function parse(string $link): ?array
    {
        if (!preg_match('#^([a-z0-9]+)://(.*)$#is', trim($link), $m)) {
            return null;
        }
        $scheme = strtolower($m[1]);
        if (!in_array($scheme, self::SCHEMES, true)) {
            return null;
        }
        $rest = $m[2];
        $name = '';
        if (($hash = strpos($rest, '#')) !== false) {
            $name = urldecode(substr($rest, $hash + 1));
            $rest = substr($rest, 0, $hash);
        }
        $query = [];
        if (($q = strpos($rest, '?')) !== false) {
            parse_str(substr($rest, $q + 1), $query);
            $rest = substr($rest, 0, $q);
            $query = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $query);
        }
        $rest = rtrim($rest, '/');

        if ($scheme === 'vmess') { // the base64 of a JSON
            $json = json_decode((string) self::base64($rest), true);
            if (!is_array($json) || ($json['add'] ?? '') === '' || (int) ($json['port'] ?? 0) <= 0) {
                return null;
            }
            $host = (string) $json['add'];
            $port = (int) $json['port'];
            return ['scheme' => $scheme, 'user' => (string) ($json['id'] ?? ''), 'password' => '', 'host' => $host, 'port' => $port, 'query' => $query,
                'name' => (string) ($json['ps'] ?? '') !== '' ? (string) $json['ps'] : "$host:$port", 'json' => $json];
        }
        if (!str_contains($rest, '@')) { // all of it in base64
            $decoded = self::base64($rest);
            if ($decoded === null || !str_contains($decoded, '@')) {
                return null;
            }
            $rest = $decoded;
        }
        $at = strrpos($rest, '@');
        $userInfo = urldecode(substr($rest, 0, $at));
        $hostPort = substr($rest, $at + 1);
        if (preg_match('#^\[([^\]]+)\]:(\d+)$#', $hostPort, $h) || preg_match('#^([^:/]+):(\d+)$#', $hostPort, $h)) {
            [$host, $port] = [$h[1], (int) $h[2]];
        } else {
            return null;
        }
        if ($host === '' || $port <= 0 || $port > 65535) {
            return null;
        }
        // method:uuid (Shadowrocket writes none:uuid), method:password for ss, or only the id or the password
        $user = $userInfo;
        $password = '';
        if (str_contains($userInfo, ':')) {
            [$user, $password] = explode(':', $userInfo, 2);
        }
        if ($scheme === 'vless' && $password !== '') {
            [$user, $password] = [$password, $user]; // none:uuid → the uuid is the id
        }
        if ($name === '') {
            $name = $query['remarks'] ?? $query['remark'] ?? $query['ps'] ?? '';
        }
        return ['scheme' => $scheme, 'user' => $user, 'password' => $password, 'host' => $host, 'port' => $port, 'query' => $query,
            'name' => $name !== '' ? $name : "$host:$port", 'json' => []];
    }

    private static function base64(string $text): ?string
    {
        $decoded = base64_decode(strtr(urldecode($text), '-_', '+/'), false);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }
}
