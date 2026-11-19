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
}
