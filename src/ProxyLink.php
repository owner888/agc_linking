<?php
declare(strict_types=1);

/**
 * A link of one proxy split into its parts, in the spellings that are met: vless://uuid@host:port?…#name, the same
 * with its user and host in base64 (Shadowrocket's vless://base64(none:uuid@host:port)?remarks=…), ss:// with all of
 * it in base64 or only its user, vmess:// as the base64 of a JSON or Shadowrocket's base64 of method:uuid@host:port.
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

        if ($scheme === 'vmess') {
            return self::vmess($rest, $query);
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

    /**
     * vmess:// in its two spellings: the base64 of a JSON (v2rayN's), or Shadowrocket's, the base64 of
     * method:uuid@host:port with the rest in the query (remarks, obfs, obfsParam, path, tls, peer, alterId).
     * Either way the answer carries the JSON a v2rayN link would, which is what an outbound is made from.
     * @param array<string, string> $query
     * @return array{scheme: string, user: string, password: string, host: string, port: int, query: array<string, string>, name: string, json: array<string, mixed>}|null
     */
    private static function vmess(string $rest, array $query): ?array
    {
        $decoded = self::base64($rest);
        $json = json_decode((string) $decoded, true);
        if (!is_array($json)) {
            if ($decoded === null || preg_match('/^(?:([^:@]*):)?([^:@]+)@(\[[^\]]+\]|[^:\/]+):(\d+)$/', $decoded, $m) !== 1) {
                return null;
            }
            $net = match (strtolower($query['obfs'] ?? '')) {
                'websocket', 'ws' => 'ws', 'grpc' => 'grpc', 'h2', 'http2' => 'h2', 'httpupgrade' => 'httpupgrade', 'xhttp', 'splithttp' => 'xhttp', default => 'tcp',
            };
            $json = array_filter([
                'v' => '2', 'ps' => $query['remarks'] ?? $query['remark'] ?? '', 'add' => trim($m[3], '[]'), 'port' => $m[4], 'id' => $m[2],
                'aid' => $query['alterId'] ?? $query['aid'] ?? '0', 'scy' => $m[1] !== '' ? $m[1] : 'auto', 'net' => $net,
                'host' => $query['obfsParam'] ?? $query['host'] ?? '', 'path' => $query['path'] ?? '',
                'type' => strtolower($query['obfs'] ?? '') === 'http' ? 'http' : '', // obfs=http is HTTP obfuscation of a plain TCP stream
                'tls' => ($query['tls'] ?? '') === '1' || ($query['tls'] ?? '') === 'true' ? 'tls' : '', 'sni' => $query['peer'] ?? $query['sni'] ?? '',
            ], static fn($v) => $v !== '');
        }
        if (($json['add'] ?? '') === '' || (int) ($json['port'] ?? 0) <= 0) {
            return null;
        }
        $host = (string) $json['add'];
        $port = (int) $json['port'];
        return ['scheme' => 'vmess', 'user' => (string) ($json['id'] ?? ''), 'password' => '', 'host' => $host, 'port' => $port, 'query' => $query,
            'name' => (string) ($json['ps'] ?? '') !== '' ? (string) $json['ps'] : "$host:$port", 'json' => $json];
    }

    private static function base64(string $text): ?string
    {
        $decoded = base64_decode(strtr(urldecode($text), '-_', '+/'), false);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }
}
