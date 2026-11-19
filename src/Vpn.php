<?php
declare(strict_types=1);

use MyPHP\App;
use MyPHP\Native\Jni;

/**
 * The VPN of the Android app: easy.net.tool.vpn.VpnApi over JNI. The tunnel itself lives in the :service process;
 * this only asks it to start or stop and reads what it keeps in files (state, bytes), so every call is a quick one.
 * Off Android there is no VPN: available() is false and the rest is not called.
 */
final class Vpn
{
    public const STOPPED = 'stopped';
    public const CONNECTING = 'connecting';
    public const CONNECTED = 'connected';
    public const ERROR = 'error';

    private const API = 'easy.net.tool.vpn.VpnApi';
    private const CONTEXT = 'Landroid/content/Context;';

    public static function available(): bool
    {
        return class_exists(App::class) && App::isAndroid();
    }

    /**
     * Starts the tunnel over an Xray config (XrayConfig::build). The system's permission dialog comes first when the
     * app has none; the outcome is read with state(). $detailedTraffic: traffic() also tells what went by the proxy and
     * what directly, which has Xray asked every second (a process each time), so it is the person's choice.
     */
    public static function start(array $config, int $socksPort = 10808, int $apiPort = 10085, bool $detailedTraffic = false, string $title = '', string $text = '', string $stopLabel = ''): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Jni::callStatic(self::API, 'start',
            '(' . self::CONTEXT . 'Ljava/lang/String;IIZLjava/lang/String;Ljava/lang/String;Ljava/lang/String;)V',
            Jni::activity(), $json, $socksPort, $apiPort, $detailedTraffic, $title, $text, $stopLabel);
    }

    public static function stop(): void
    {
        Jni::callStatic(self::API, 'stop', '(' . self::CONTEXT . ')V', Jni::activity());
    }

    /** One of the constants. */
    public static function state(): string
    {
        $s = (string) Jni::callStatic(self::API, 'state', '(' . self::CONTEXT . ')Ljava/lang/String;', Jni::activity());
        return in_array($s, [self::CONNECTING, self::CONNECTED, self::ERROR], true) ? $s : self::STOPPED;
    }

    /** What went wrong, after state() said error; empty otherwise. */
    public static function error(): string
    {
        return (string) Jni::callStatic(self::API, 'error', '(' . self::CONTEXT . ')Ljava/lang/String;', Jni::activity());
    }

    /** @return ?array{up: int, down: int, proxyUp?: int, proxyDown?: int, directUp?: int, directDown?: int} the bytes carried since the tunnel came up; null when none is up */
    public static function traffic(): ?array
    {
        return self::parseTraffic((string) Jni::callStatic(self::API, 'traffic', '(' . self::CONTEXT . ')Ljava/lang/String;', Jni::activity()));
    }

    /** @return ?array{up: int, down: int, proxyUp?: int, proxyDown?: int, directUp?: int, directDown?: int} the service's text: "up down" and, when detailed, "proxyUp proxyDown directUp directDown" */
    public static function parseTraffic(string $text): ?array
    {
        if (preg_match('/^\d+ \d+( \d+ \d+ \d+ \d+)?$/', trim($text)) !== 1) {
            return null;
        }
        $n = array_map('intval', explode(' ', trim($text)));
        $t = ['up' => $n[0], 'down' => $n[1]];
        if (count($n) === 6) {
            $t += ['proxyUp' => $n[2], 'proxyDown' => $n[3], 'directUp' => $n[4], 'directDown' => $n[5]];
        }
        return $t;
    }

    private const LATENCY = 'easy.net.tool.vpn.LatencyTest';
    public const LATENCY_PENDING = -2;
    public const LATENCY_FAILED = -1;

    /**
     * Measures proxies by a request for $url through each (Shadowrocket's CONNECT test); poll with latency(). $ports:
     * the SOCKS port of each proxy in $config (XrayConfig::probe), 0 for one that has none.
     * @param list<int> $ports
     */
    public static function startUrlTest(array $config, array $ports, string $url, int $timeoutMs): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Jni::callStatic(self::LATENCY, 'startUrl', '(' . self::CONTEXT . 'Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;I)V',
            Jni::activity(), $json, implode(',', $ports), $url, $timeoutMs);
    }

    /** Measures hosts by the system's ping; poll with latency(). @param list<string> $hosts */
    public static function startPingTest(array $hosts, int $timeoutMs): void
    {
        Jni::callStatic(self::LATENCY, 'startPing', '(Ljava/lang/String;I)V', implode(',', $hosts), $timeoutMs);
    }

    /** @return list<int> a number for each proxy: milliseconds, LATENCY_FAILED or LATENCY_PENDING */
    public static function latency(): array
    {
        return self::parseLatency((string) Jni::callStatic(self::LATENCY, 'poll', '()Ljava/lang/String;'));
    }

    public static function cancelLatencyTest(): void
    {
        Jni::callStatic(self::LATENCY, 'cancel', '()V');
    }

    /** @return list<int> */
    public static function parseLatency(string $text): array
    {
        return $text === '' ? [] : array_map('intval', explode(',', $text));
    }

    /** A rate for the screen: 0B/s, 512B/s, 1.2KB/s, 3.4MB/s. */
    public static function formatSpeed(float $bytesPerSecond): string
    {
        return self::formatBytes($bytesPerSecond) . '/s';
    }

    /** An amount of bytes for the screen: 0B, 512B, 1.2KB, 3.4MB, 1.5GB. */
    public static function formatBytes(float $bytes): string
    {
        foreach (['B' => 1024, 'KB' => 1024 ** 2, 'MB' => 1024 ** 3] as $unit => $limit) {
            if ($bytes < $limit) {
                $divisor = $limit / 1024;
                return $unit === 'B' ? (int) $bytes . 'B' : number_format($bytes / $divisor, 1, '.', '') . $unit;
            }
        }
        return number_format($bytes / 1024 ** 3, 1, '.', '') . 'GB';
    }
}
