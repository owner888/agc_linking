<?php
declare(strict_types=1);

use MyPHP\{App, Device, Shell, URLRequest, URLSession, Window};

final class LinkingApp
{
    public ?Window $window = null;
    private int $apkWatchGen = 0;

    // App version + TestFlight-style build number & Update Capsule state
    public string $currentVersion = '2.0.0';
    public int $currentBuild = 7;
    public string $packageName = 'easy.net.tool';
    public string $playStoreUrl = 'https://play.google.com/store/apps/details?id=easy.net.tool';
    public string $appStoreUrl = 'https://apps.apple.com/app/id6463097701';
    public string $githubReleaseUrl = 'https://github.com/owner888/agc_linking/releases/latest';
    public string $updateStatus = 'idle'; // 'idle' | 'checking' | 'available' | 'downloading' | 'installing' | 'failed'
    public string $updateVersion = '';
    public string $updateNotes = '';
    public string $updateDownloadUrl = 'https://github.com/owner888/agc_linking/releases/latest/download/Linking.apk';
    public int $updateAssetSize = 0;
    public float $updateProgress = 0.0;
    public bool $updateForce = false;
    public string $skippedVersion = '';
    public string $lastOpenedExternalUrl = '';

    // The tab shown: 'home' | 'proxies' | 'ping' | 'httping' | 'tcping' | 'config' | 'logs' | 'settings'. The drawer of
    // agc_raven is the tabs: the bar of a phone has the first four and More, a sidebar has them all.
    public string $tab = 'home';
    public \NavigationPath $homePath;
    public \NavigationPath $proxiesPath;

    // Sheets
    public bool $addProfileOpen = false;
    public bool $quickSettingsOpen = false;
    public bool $favoritesOpen = false;
    public bool $engineAlertOpen = false;
    public string $engineAlertMessage = '';
    public bool $removeProfileAsked = false;
    public bool $skipUpdateAsked = false;

    // Tool mode: 'Ping' | 'Httping' | 'Tcping'
    public string $toolType = 'Ping';

    // Ping tool inputs & state
    public string $hostInput = '1.1.1.1';
    public string $selectedDns = 'Default DNS';
    public bool $isRunning = false;
    private int $runGeneration = 0;

    /** @var list<string> */
    public array $dnsOptions = [
        'Default DNS',
        '114.114.114.114',
        '223.5.5.5',
        '119.29.29.29',
        '8.8.8.8',
        '1.1.1.1',
    ];

    /** @var list<array{ip: string, country: string, isp: string}> */
    public array $nslookupResults = [];

    /**
     * @var list<array{
     *   id: int,
     *   seq: int,
     *   host: string,
     *   ip: string,
     *   mode: string,
     *   ttl: int,
     *   bytes: int,
     *   ms: ?int,
     *   status: string,
     *   ok: bool,
     *   time: string
     * }>
     */
    public array $pingRecords = [];
    private int $nextRecordId = 1;

    public string $ipCountry = '-';
    public string $ipLocationDetail = '';

    // Ping configuration (matches agc_raven defaults)
    public int $ttl = 64;
    public int $timeOut = 5;
    public int $interval = 1;
    public int $packetSize = 64;
    public string $ipLibrary = 'IP_2LOCATION';

    // Favorites & History
    public string $newFavHost = '';
    public string $newFavDesc = '';

    /** @var list<array{id: int, host: string, description: string, tag: string}> */
    public array $favorites = [];
    private int $nextFavId = 1;

    /** @var list<array{id: int, host: string, mode: string, time: string}> */
    public array $history = [];
    private int $nextHistId = 1;

    // Profiles: what a person added (a link of one proxy, or a subscription) and the proxies in it
    /** @var list<array{id: int, name: string, source: string, kind: string, nodes: list<array{id: int, name: string, protocol: string, address: string, port: int, latency: ?int}>}> */
    public array $profiles = [];
    public int $activeProfileId = 0;
    public int $selectedNodeId = 0;
    private int $nextProfileId = 1;
    private int $nextNodeId = 1;
    public string $profileInput = '';
    public string $profileError = '';
    public bool $profileBusy = false;
    public bool $testingLatency = false;
    public string $proxiesSort = 'default'; // 'default' | 'latency' | 'name'

    // The connection: 'disconnected' | 'connecting' | 'connected'. Made by the VPN service of the Android app (Vpn).
    public string $connection = 'disconnected';
    public string $upSpeed = '0B/s';
    public string $downSpeed = '0B/s';
    public string $trafficDetail = ''; // proxy and direct traffic since it connected, with $detailedTraffic
    private int $vpnWatchGen = 0;
    private float $vpnAskedAt = 0.0;
    private ?array $lastTraffic = null;
    private float $lastTrafficAt = 0.0;
    private const SOCKS_PORT = 10808;
    private const API_PORT = 10085;
    private const CONNECT_TIMEOUT = 45.0; // the permission dialog may take a while; a start that shows nothing after this is given up

    public string $routingMode = 'Rule'; // 'Rule' | 'Global' | 'Direct'
    public bool $tunEnabled = true;
    public bool $ipv6Enabled = false;
    public bool $detailedTraffic = false; // count the proxy's and the direct traffic apart: Xray is asked every second, so it is off by default
    public int $mixedPort = 7890;

    /** @var list<array{id: int, time: string, level: string, message: string}> */
    public array $logs = [];
    private int $nextLogId = 1;

    // When true (e.g. in headless tests or preview), probes complete synchronously with deterministic metrics
    public bool $deterministicProbes = false;

    public function __construct(bool $deterministicProbes = false, private readonly ?Store $store = null)
    {
        $this->deterministicProbes = $deterministicProbes;
        $cfgPath = dirname(__DIR__) . '/myphp.json';
        if (is_file($cfgPath)) {
            $cfg = json_decode((string) file_get_contents($cfgPath), true);
            if (is_array($cfg) && !empty($cfg['version'])) {
                [$ver, $build] = self::parseVersionAndBuild((string) $cfg['version'], (int) ($cfg['buildNumber'] ?? 0));
                $this->currentVersion = $ver;
                if ($build > 0) {
                    $this->currentBuild = $build;
                }
            }
        }
        $this->homePath = new \NavigationPath();
        $this->proxiesPath = new \NavigationPath();
        $this->seedDefaults();
        $this->restore();
    }

    private function seedDefaults(): void
    {
        $this->favorites = [
            ['id' => $this->nextFavId++, 'host' => '1.1.1.1', 'description' => 'Cloudflare Anycast DNS', 'tag' => 'DNS'],
            ['id' => $this->nextFavId++, 'host' => '8.8.8.8', 'description' => 'Google Public DNS', 'tag' => 'DNS'],
            ['id' => $this->nextFavId++, 'host' => 'github.com', 'description' => 'GitHub Code Hosting (TCP 443)', 'tag' => 'HTTPS'],
            ['id' => $this->nextFavId++, 'host' => '223.5.5.5', 'description' => 'AliDNS Public Resolver', 'tag' => 'CN'],
        ];

        $this->appendLog('INFO', 'Linking started');
    }

    public function appendLog(string $level, string $message): void
    {
        array_unshift($this->logs, [
            'id' => $this->nextLogId++,
            'time' => date('H:i:s'),
            'level' => $level,
            'message' => $message,
        ]);
        if (count($this->logs) > 100) {
            array_pop($this->logs);
        }
    }

    public function clearLogs(): void
    {
        $this->logs = [];
    }

    // --- Ping / Httping / Tcping Actions ---

    public function setToolType(string $type): void
    {
        if (!in_array($type, ['Ping', 'Httping', 'Tcping'], true)) {
            return;
        }
        if ($this->isRunning) {
            $this->stopPing();
        }
        $this->toolType = $type;
        $this->appendLog('INFO', "Switched diagnostic tool to {$type}");
    }

    public function togglePing(): void
    {
        if ($this->isRunning) {
            $this->stopPing();
        } else {
            $this->startPing();
        }
    }

    public function startPing(): void
    {
        $target = trim($this->hostInput);
        if ($target === '') {
            return;
        }

        $this->stopPing();
        $this->isRunning = true;
        $this->pingRecords = [];
        $gen = ++$this->runGeneration;

        $this->recordHistory($target, $this->toolType);
        [$cleanHost, $port] = $this->parseHostPort($target);

        // Perform immediate first probe (and 3 more in deterministic mode)
        if ($this->deterministicProbes) {
            $resolvedIp = (string) $this->resolveHostIp($cleanHost);
            $this->showIpMeta($resolvedIp, $target);
            for ($seq = 1; $seq <= 4; $seq++) {
                $this->probeOnce($seq, $target, $cleanHost, $resolvedIp, $port);
            }
            $this->isRunning = false;
            return;
        }

        // Everything that waits (the name, each probe, the pause between them) runs in a fiber: the run loop, which
        // draws the UI, goes on meanwhile. A usleep() or a blocking connect here would freeze the app.
        if (class_exists(App::class)) {
            App::go(function () use ($gen, $target, $cleanHost, $port) {
                $resolvedIp = $this->resolveHostIp($cleanHost);
                if ($this->runGeneration !== $gen) {
                    return;
                }
                if ($resolvedIp === null) {
                    $this->appendLog('ERROR', "Could not resolve {$cleanHost}");
                    array_unshift($this->pingRecords, [
                        'id' => $this->nextRecordId++, 'seq' => 1, 'host' => $cleanHost, 'ip' => '-', 'mode' => $this->toolType,
                        'ttl' => $this->ttl, 'bytes' => $this->packetSize, 'ms' => null,
                        'status' => "Could not resolve {$cleanHost}", 'ok' => false, 'time' => date('H:i:s'),
                    ]);
                    $this->isRunning = false;
                    $this->window?->invalidate();
                    return;
                }
                $this->showIpMeta($resolvedIp, $target);
                $this->window?->invalidate();
                $seq = 1;
                while ($this->isRunning && $this->runGeneration === $gen && $seq <= 100) {
                    $this->probeOnce($seq++, $target, $cleanHost, $resolvedIp, $port);
                    $this->window?->invalidate(); // a probe ends outside an event: ask for a frame
                    if (!$this->isRunning || $this->runGeneration !== $gen) {
                        break;
                    }
                    App::sleep(max(1, $this->interval));
                }
                if ($this->runGeneration === $gen) {
                    $this->isRunning = false;
                    $this->window?->invalidate();
                }
            });
        }
    }

    private function showIpMeta(string $resolvedIp, string $target): void
    {
        [$country, $isp] = $this->lookupIpMeta($resolvedIp);
        $this->ipCountry = $country;
        $this->ipLocationDetail = "{$isp} ({$this->ipLibrary})";
        $this->appendLog('INFO', "Started {$this->toolType} -> {$target} [{$resolvedIp}] (TTL={$this->ttl}, Timeout={$this->timeOut}s, Size={$this->packetSize}B)");
    }

    public function stopPing(): void
    {
        if ($this->isRunning) {
            $this->isRunning = false;
            $this->runGeneration++;
            $this->appendLog('INFO', "Stopped {$this->toolType} session ({$this->sentCount()} packets sent)");
        }
    }

    public function runNslookup(): void
    {
        $target = trim($this->hostInput);
        if ($target === '') {
            return;
        }
        [$cleanHost] = $this->parseHostPort($target);
        $results = [];

        if ($this->deterministicProbes) {
            $ip = (string) $this->resolveHostIp($cleanHost);
            [$country, $isp] = $this->lookupIpMeta($ip);
            $results[] = ['ip' => $ip, 'country' => $country, 'isp' => $isp];
            if (!filter_var($cleanHost, FILTER_VALIDATE_IP)) {
                $results[] = ['ip' => '104.16.132.229', 'country' => 'US', 'isp' => 'Cloudflare Anycast Secondary'];
            }
        } else {
            $ips = @gethostbynamel($cleanHost);
            if (is_array($ips) && $ips !== []) {
                foreach (array_slice(array_unique($ips), 0, 6) as $ip) {
                    [$country, $isp] = $this->lookupIpMeta($ip);
                    $results[] = ['ip' => $ip, 'country' => $country, 'isp' => $isp];
                }
            }
        }

        $this->nslookupResults = $results;
        $this->appendLog('INFO', "NSLookup {$cleanHost} via {$this->selectedDns}: " . count($results) . ' A record(s)');
    }

    public function selectResolvedIp(string $ip): void
    {
        $this->hostInput = $ip;
        $this->nslookupResults = [];
        $this->startPing();
    }

    private function probeOnce(int $seq, string $rawTarget, string $host, string $ip, int $port): void
    {
        $mode = $this->toolType;
        $ok = true;
        $ms = 18;
        $status = '';

        if ($this->deterministicProbes) {
            $base = (crc32($host . $mode) & 0x1F) + 12;
            $jitter = ($seq * 3) % 7;
            $ms = $base + $jitter;
            if ($mode === 'Httping') {
                $status = "HTTP/2 200 OK · {$ip} · {$ms} ms";
            } elseif ($mode === 'Tcping') {
                $status = "TCP {$ip}:{$port} SYN-ACK open · {$ms} ms";
            } else {
                $status = "{$this->packetSize} bytes from {$ip}: icmp_seq={$seq} ttl={$this->ttl}";
            }
        } else {
            $t0 = hrtime(true);
            if ($mode === 'Httping') {
                $url = str_starts_with($rawTarget, 'http://') || str_starts_with($rawTarget, 'https://')
                    ? $rawTarget
                    : 'https://' . $host;
                $code = 0;
                $err = '';
                try { // in a fiber: waits without holding the run loop
                    $req = new URLRequest($url, 'HEAD', [], null, (float) max(1, $this->timeOut));
                    [, $response] = URLSession::shared()->data($req);
                    $code = $response->statusCode;
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                }
                $elapsed = (int) round((hrtime(true) - $t0) / 1_000_000);
                if ($code > 0) {
                    $ms = max(1, $elapsed);
                    $status = "HTTP {$code} from {$url} ({$ip})";
                } else {
                    $ok = false;
                    $ms = null;
                    $status = 'HTTP timeout / error: ' . ($err !== '' ? $err : 'unreachable');
                }
            } else {
                $targetPort = $mode === 'Tcping' ? $port : 443;
                $ms = $this->tcpConnectMs($ip, $targetPort);
                if ($ms !== null) {
                    $status = $mode === 'Tcping'
                        ? "TCP {$ip}:{$targetPort} connected (ttl={$this->ttl})"
                        : "{$this->packetSize} bytes from {$ip}: seq={$seq} ttl={$this->ttl}";
                } else {
                    // Fallback to port 80 if Ping mode and 443 refused
                    $ms80 = $mode === 'Ping' ? $this->tcpConnectMs($ip, 80) : null;
                    if ($ms80 !== null) {
                        $ms = $ms80;
                        $status = "{$this->packetSize} bytes from {$ip}: seq={$seq} ttl={$this->ttl}";
                    } else {
                        $ok = false;
                        $ms = null;
                        $status = "Request timeout for seq {$seq} ({$ip}:{$targetPort})";
                    }
                }
            }
        }

        array_unshift($this->pingRecords, [
            'id' => $this->nextRecordId++,
            'seq' => $seq,
            'host' => $host,
            'ip' => $ip,
            'mode' => $mode,
            'ttl' => $this->ttl,
            'bytes' => $this->packetSize,
            'ms' => $ms,
            'status' => $status,
            'ok' => $ok,
            'time' => date('H:i:s'),
        ]);
    }

    /**
     * Milliseconds a TCP connection to $ip:$port takes, null when it is refused or not made within the timeout. The
     * connect is not blocking: the wait goes through App::sleep, so the app draws and takes touches meanwhile.
     */
    private function tcpConnectMs(string $ip, int $port): ?int
    {
        $t0 = hrtime(true);
        $errno = 0;
        $errstr = '';
        $addr = (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ":{$port}";
        $sock = @stream_socket_client("tcp://{$addr}", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (!is_resource($sock)) {
            return null;
        }
        stream_set_blocking($sock, false);
        $deadline = microtime(true) + max(1, $this->timeOut);
        try {
            while (microtime(true) < $deadline) {
                $read = null;
                $write = [$sock];
                $except = [$sock];
                if (@stream_select($read, $write, $except, 0, 0) > 0) {
                    return $write !== [] && @stream_socket_get_name($sock, true) !== false
                        ? max(1, (int) round((hrtime(true) - $t0) / 1_000_000))
                        : null;
                }
                App::sleep(0.01);
            }
            return null;
        } finally {
            fclose($sock);
        }
    }

    /** @return array{0: string, 1: int} */
    private function parseHostPort(string $input): array
    {
        $clean = preg_replace('#^https?://#i', '', trim($input)) ?? trim($input);
        $clean = explode('/', $clean)[0];
        if (str_contains($clean, ':')) {
            [$h, $p] = explode(':', $clean, 2);
            $port = (int) $p;
            return [$h !== '' ? $h : $clean, $port > 0 ? $port : 443];
        }
        return [$clean, 443];
    }

    /** The address of $host, null when it does not resolve. The deterministic probes (the tests) use a fixed table, never the network. */
    private function resolveHostIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if ($this->deterministicProbes) {
            $known = [
                'cloudflare.com' => '104.16.132.229',
                'cp.cloudflare.com' => '104.16.132.229',
                'google.com' => '142.250.190.78',
                'github.com' => '140.82.113.4',
                'baidu.com' => '39.156.66.10',
            ];
            return $known[strtolower($host)] ?? '104.21.48.1';
        }
        $resolved = @gethostbyname($host);
        return $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : null;
    }

    /** @return array{0: string, 1: string} */
    private function lookupIpMeta(string $ip): array
    {
        if (str_starts_with($ip, '114.') || str_starts_with($ip, '223.') || str_starts_with($ip, '119.') || str_starts_with($ip, '39.')) {
            return ['CN', 'China Backbone / AliDNS'];
        }
        if (str_starts_with($ip, '1.1.') || str_starts_with($ip, '104.')) {
            return ['US', 'Cloudflare, Inc. (AS13335)'];
        }
        if (str_starts_with($ip, '8.8.') || str_starts_with($ip, '142.')) {
            return ['US', 'Google LLC (AS15169)'];
        }
        if (str_starts_with($ip, '140.82.')) {
            return ['US', 'GitHub, Inc. (AS36459)'];
        }
        return ['-', '-']; // no database here knows it: nothing is shown, not a guess
    }

    // --- 8-Metric Statistics (Exact match to agc_raven) ---

    public function sentCount(): int
    {
        return count($this->pingRecords);
    }

    public function receivedCount(): int
    {
        return count(array_filter($this->pingRecords, fn(array $r) => $r['ok']));
    }

    public function lossCount(): int
    {
        return $this->sentCount() - $this->receivedCount();
    }

    /** @return list<int> */
    private function validLatencies(): array
    {
        $out = [];
        foreach ($this->pingRecords as $r) {
            if ($r['ok'] && is_int($r['ms'])) {
                $out[] = $r['ms'];
            }
        }
        return $out;
    }

    public function minLatencyText(): string
    {
        $vals = $this->validLatencies();
        return $vals === [] ? '-' : min($vals) . ' ms';
    }

    public function avgLatencyText(): string
    {
        $vals = $this->validLatencies();
        return $vals === [] ? '-' : (int) round(array_sum($vals) / count($vals)) . ' ms';
    }

    public function maxLatencyText(): string
    {
        $vals = $this->validLatencies();
        return $vals === [] ? '-' : max($vals) . ' ms';
    }

    public function stddevText(): string
    {
        $vals = $this->validLatencies();
        if ($vals === []) {
            return '-';
        }
        $mean = array_sum($vals) / count($vals);
        $variance = 0.0;
        foreach ($vals as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= count($vals);
        return number_format(sqrt($variance), 2) . ' ms';
    }

    // --- Favorites & History Management ---

    public function addFavorite(): void
    {
        $host = trim($this->newFavHost);
        if ($host === '') {
            return;
        }
        $desc = trim($this->newFavDesc);
        foreach ($this->favorites as $idx => $fav) {
            if (strcasecmp($fav['host'], $host) === 0) {
                $this->favorites[$idx]['description'] = $desc !== '' ? $desc : $fav['description'];
                $this->newFavHost = '';
                $this->newFavDesc = '';
                return;
            }
        }
        $this->favorites[] = [
            'id' => $this->nextFavId++,
            'host' => $host,
            'description' => $desc !== '' ? $desc : 'Custom host endpoint',
            'tag' => str_contains($host, ':') ? 'TCP' : 'HOST',
        ];
        $this->newFavHost = '';
        $this->newFavDesc = '';
        $this->appendLog('INFO', "Added favorite host: {$host}");
        $this->save();
    }

    public function removeFavorite(int $id): void
    {
        $this->favorites = array_values(array_filter(
            $this->favorites,
            fn(array $f) => $f['id'] !== $id
        ));
        $this->save();
    }

    public function probeFavorite(string $host, string $mode = 'Ping'): void
    {
        $this->toolType = $mode;
        $this->hostInput = $host;
        $this->favoritesOpen = false;
        $this->startPing();
        $this->go(self::toolTab($mode));
    }

    private function recordHistory(string $host, string $mode): void
    {
        $this->history = array_values(array_filter(
            $this->history,
            fn(array $h) => !(strcasecmp($h['host'], $host) === 0 && $h['mode'] === $mode)
        ));
        array_unshift($this->history, [
            'id' => $this->nextHistId++,
            'host' => $host,
            'mode' => $mode,
            'time' => date('H:i:s'),
        ]);
        $this->save();
    }

    public function clearHistory(): void
    {
        $this->history = [];
        $this->appendLog('INFO', 'Cleared diagnostic history');
        $this->save();
    }

    // --- Smart Network / Proxy Actions ---

    public function toggleConnection(): void
    {
        if ($this->activeProfile() === null) { // nothing to connect to: add a profile first
            $this->openAddProfile();
            return;
        }
        if ($this->connection !== 'disconnected') {
            $this->disconnect();
            return;
        }
        if (!Vpn::available()) {
            $this->refuseConnection('A connection is made by the Android app only.');
            return;
        }
        $node = $this->selectedNode();
        if ($node !== null && !isset($node['link'])) { // a profile kept by an earlier version, which did not keep the link
            $this->refuseConnection('This profile was added by an earlier version: remove it and add it again.');
            return;
        }
        $config = $node === null ? null : XrayConfig::build($node['link'] ?? '', $this->routingMode, self::SOCKS_PORT, self::API_PORT);
        if ($node === null || $config === null) {
            $this->refuseConnection(($node['protocol'] ?? 'This') . ' proxies are not supported yet.');
            return;
        }
        try {
            Vpn::start($config, self::SOCKS_PORT, self::API_PORT, $this->detailedTraffic, 'Linking', $node['name'], 'Disconnect');
        } catch (\Throwable $e) {
            $this->refuseConnection('The connection could not be started: ' . $e->getMessage());
            return;
        }
        $this->connection = 'connecting';
        $this->vpnAskedAt = microtime(true);
        $this->appendLog('INFO', "Connecting through {$node['name']} ({$this->routingMode})");
        $this->watchVpn();
    }

    public function disconnect(): void
    {
        $this->vpnWatchGen++;
        if (Vpn::available()) {
            try {
                Vpn::stop();
            } catch (\Throwable $e) {
                $this->appendLog('ERROR', 'Could not stop the connection: ' . $e->getMessage());
            }
        }
        $this->setDisconnected();
        $this->appendLog('INFO', 'Disconnected');
    }

    /** Picks up a tunnel that is already up (the app's screen was started again while the service went on). */
    public function resumeConnection(): void
    {
        if (!Vpn::available() || $this->connection !== 'disconnected') {
            return;
        }
        $state = Vpn::state();
        if ($state === Vpn::CONNECTED || $state === Vpn::CONNECTING) {
            $this->connection = $state;
            $this->vpnAskedAt = microtime(true);
            $this->watchVpn();
        }
    }

    /**
     * The app is in front again. Asked for a connection, it has nothing to tell when the person turned the system's
     * permission down, so a little after the screen is back, a service that is still not there ends the wait.
     */
    public function vpnScreenBack(): void
    {
        if ($this->connection !== 'connecting' || !Vpn::available()) {
            return;
        }
        $gen = $this->vpnWatchGen;
        App::after(1.5, function () use ($gen): void {
            if ($gen === $this->vpnWatchGen && $this->connection === 'connecting' && Vpn::state() === Vpn::STOPPED) {
                $this->vpnWatchGen++;
                $this->setDisconnected();
                $this->appendLog('INFO', 'The connection was not allowed');
                $this->window?->invalidate();
            }
        });
    }

    private function refuseConnection(string $message): void
    {
        $this->engineAlertMessage = $message;
        $this->engineAlertOpen = true;
        $this->appendLog('WARNING', $message);
    }

    private function setDisconnected(): void
    {
        $this->connection = 'disconnected';
        $this->upSpeed = $this->downSpeed = '0B/s';
        $this->trafficDetail = '';
        $this->lastTraffic = null;
    }

    /** Follows the service once a second: connected, the speed, an error, or a tunnel that went away. */
    private function watchVpn(): void
    {
        $gen = ++$this->vpnWatchGen;
        $poll = null;
        $poll = function () use (&$poll, $gen): void {
            if ($gen !== $this->vpnWatchGen) {
                return;
            }
            $this->pollVpn();
            if ($gen === $this->vpnWatchGen && $this->connection !== 'disconnected') {
                App::after(1.0, $poll);
            }
        };
        App::after(0.5, $poll);
    }

    private function pollVpn(): void
    {
        $state = Vpn::state();
        $waited = microtime(true) - $this->vpnAskedAt;
        if ($state === Vpn::ERROR) {
            $message = Vpn::error();
            $this->setDisconnected();
            $this->refuseConnection($message !== '' ? $message : 'The connection failed.');
        } elseif ($state === Vpn::CONNECTED) {
            if ($this->connection !== 'connected') {
                $this->connection = 'connected';
                $this->appendLog('INFO', 'Connected');
            }
            $this->updateTraffic();
        } elseif ($state === Vpn::STOPPED) { // nothing yet while the permission is asked for; gone once it was up
            if ($this->connection === 'connected' || $waited > self::CONNECT_TIMEOUT) {
                $this->setDisconnected();
                $this->appendLog('WARNING', $this->connection === 'connected' ? 'The connection was ended' : 'The connection was not made');
            }
        }
        $this->window?->invalidate();
    }

    /** The speeds from the bytes carried since the last look; with the split, what went by the proxy and what directly. */
    private function updateTraffic(): void
    {
        $t = Vpn::traffic();
        if ($t === null) {
            return;
        }
        $now = microtime(true);
        if ($this->lastTraffic !== null && $now > $this->lastTrafficAt) {
            $dt = $now - $this->lastTrafficAt;
            $this->upSpeed = Vpn::formatSpeed(max(0, $t['up'] - $this->lastTraffic['up']) / $dt);
            $this->downSpeed = Vpn::formatSpeed(max(0, $t['down'] - $this->lastTraffic['down']) / $dt);
        }
        $this->lastTraffic = $t;
        $this->lastTrafficAt = $now;
        $this->trafficDetail = isset($t['proxyUp'])
            ? 'Proxy ↑ ' . Vpn::formatBytes($t['proxyUp']) . ' ↓ ' . Vpn::formatBytes($t['proxyDown'])
                . '   Direct ↑ ' . Vpn::formatBytes($t['directUp']) . ' ↓ ' . Vpn::formatBytes($t['directDown'])
            : '';
    }

    public function connectionLabel(): string
    {
        return match ($this->connection) {
            'connecting' => 'Connecting',
            'connected' => 'Connected',
            default => 'Tap To Connect',
        };
    }

    // --- Profiles and their proxies ---

    /** @return ?array{id: int, name: string, source: string, kind: string, nodes: list<array{id: int, name: string, protocol: string, address: string, port: int, latency: ?int}>} */
    public function activeProfile(): ?array
    {
        foreach ($this->profiles as $profile) {
            if ($profile['id'] === $this->activeProfileId) {
                return $profile;
            }
        }
        return null;
    }

    /** The proxies of the active profile, sorted as the Proxies page says. @return list<array{id: int, name: string, protocol: string, address: string, port: int, latency: ?int}> */
    public function nodes(): array
    {
        $nodes = $this->activeProfile()['nodes'] ?? [];
        if ($this->proxiesSort === 'name') {
            usort($nodes, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
        } elseif ($this->proxiesSort === 'latency') { // the untested last, the unreachable after the reachable
            usort($nodes, fn(array $a, array $b) => self::latencyRank($a['latency']) <=> self::latencyRank($b['latency']));
        }
        return $nodes;
    }

    private static function latencyRank(?int $ms): int
    {
        return $ms === null ? PHP_INT_MAX : ($ms < 0 ? PHP_INT_MAX - 1 : $ms);
    }

    public function latencyText(?int $ms): string
    {
        return $ms === null ? '-' : ($ms < 0 ? 'Timeout' : "{$ms} ms");
    }

    public function openAddProfile(): void
    {
        $this->profileInput = '';
        $this->profileError = '';
        $this->addProfileOpen = true;
    }

    /** Adds what was pasted: a link of one proxy at once, a subscription's address once it is fetched. */
    public function addProfile(): void
    {
        $text = trim($this->profileInput);
        if ($text === '' || $this->profileBusy) {
            return;
        }
        $this->profileError = '';
        if (!Profiles::isSubscriptionUrl($text)) {
            $node = Profiles::parseLink($text);
            if ($node === null) {
                $this->profileError = 'This is not a link of a proxy or the address of a subscription.';
                return;
            }
            $this->storeProfile($node['name'], $text, 'link', [$node]);
            return;
        }
        if ($this->deterministicProbes || !class_exists(App::class)) {
            $this->profileError = 'A subscription cannot be fetched here.';
            return;
        }
        $this->profileBusy = true;
        App::go(function () use ($text): void {
            try {
                $req = new URLRequest($text, 'GET', ['User-Agent' => 'Linking/' . $this->currentVersion], null, 20.0);
                [$body, $response] = URLSession::shared()->data($req);
                $nodes = $response->statusCode >= 200 && $response->statusCode < 300 ? Profiles::parseSubscription($body) : [];
                if ($nodes === []) {
                    $this->profileError = $response->statusCode >= 200 && $response->statusCode < 300
                        ? 'No proxy was found in the subscription.'
                        : "The subscription answered {$response->statusCode}.";
                } else {
                    $title = (string) $response->value('profile-title');
                    if (str_starts_with($title, 'base64:')) {
                        $title = (string) base64_decode(substr($title, 7), false);
                    }
                    $this->storeProfile($title !== '' ? $title : (string) (parse_url($text, PHP_URL_HOST) ?: $text), $text, 'subscription', $nodes);
                }
            } catch (\Throwable $e) {
                $this->profileError = 'The subscription could not be fetched: ' . $e->getMessage();
            } finally {
                $this->profileBusy = false;
                $this->window?->invalidate();
            }
        });
    }

    /** @param list<array{name: string, protocol: string, address: string, port: int, link: string}> $nodes */
    private function storeProfile(string $name, string $source, string $kind, array $nodes): void
    {
        $id = $this->nextProfileId++;
        $this->profiles[] = [
            'id' => $id,
            'name' => $name,
            'source' => $source,
            'kind' => $kind,
            'nodes' => array_map(fn(array $n) => $n + ['id' => $this->nextNodeId++, 'latency' => null], $nodes),
        ];
        $this->selectProfile($id);
        $this->addProfileOpen = false;
        $this->profileInput = '';
        $this->appendLog('INFO', "Added profile {$name} (" . count($nodes) . ' proxies)');
        $this->save();
    }

    public function selectProfile(int $id): void
    {
        $this->activeProfileId = $id;
        $first = $this->activeProfile()['nodes'][0]['id'] ?? 0;
        $this->selectedNodeId = $first;
        $this->save();
    }

    public function removeActiveProfile(): void
    {
        $this->profiles = array_values(array_filter($this->profiles, fn(array $p) => $p['id'] !== $this->activeProfileId));
        $this->activeProfileId = 0;
        $this->selectedNodeId = 0;
        if ($this->profiles !== []) {
            $this->selectProfile($this->profiles[0]['id']);
        }
        $this->save();
    }

    public function selectNode(int $id): void
    {
        $this->selectedNodeId = $id;
        $this->save();
    }

    public function selectedNode(): ?array
    {
        foreach ($this->activeProfile()['nodes'] ?? [] as $node) {
            if ($node['id'] === $this->selectedNodeId) {
                return $node;
            }
        }
        return null;
    }

    /** Measures how long a TCP connection to each proxy of the active profile takes, one after the other. */
    public function testLatency(): void
    {
        if ($this->testingLatency || $this->activeProfile() === null || $this->deterministicProbes || !class_exists(App::class)) {
            return;
        }
        $this->testingLatency = true;
        $profileId = $this->activeProfileId;
        foreach ($this->profiles as $pi => $profile) {
            if ($profile['id'] === $profileId) {
                foreach ($profile['nodes'] as $ni => $_) {
                    $this->profiles[$pi]['nodes'][$ni]['latency'] = null;
                }
            }
        }
        App::go(function () use ($profileId): void {
            try {
                foreach ($this->profiles as $pi => $profile) {
                    if ($profile['id'] !== $profileId) {
                        continue;
                    }
                    foreach ($profile['nodes'] as $ni => $node) {
                        $ms = $this->tcpConnectMs($node['address'], $node['port']);
                        $this->profiles[$pi]['nodes'][$ni]['latency'] = $ms ?? -1;
                        $this->window?->invalidate();
                    }
                }
            } finally {
                $this->testingLatency = false;
                $this->appendLog('INFO', 'Measured the latency of the proxies');
                $this->save();
                $this->window?->invalidate();
            }
        });
    }

    // --- Where the menu goes ---

    /** Shows the tab $tab. */
    public function go(string $tab): void
    {
        $this->tab = $tab;
    }

    /** The tab of a tool: Ping, Httping or Tcping. */
    public static function toolTab(string $mode): string
    {
        return strtolower($mode);
    }

    /** The mode of a tool's tab, null for the other tabs. */
    public static function toolOfTab(string $tab): ?string
    {
        return match ($tab) { 'ping' => 'Ping', 'httping' => 'Httping', 'tcping' => 'Tcping', default => null };
    }

    // --- What is kept between runs ---

    private function restore(): void
    {
        $data = $this->store?->load() ?? [];
        if ($data === []) {
            return;
        }
        $this->favorites = is_array($data['favorites'] ?? null) ? $data['favorites'] : $this->favorites;
        $this->history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $this->profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $this->activeProfileId = (int) ($data['activeProfileId'] ?? 0);
        $this->selectedNodeId = (int) ($data['selectedNodeId'] ?? 0);
        foreach (['ttl', 'timeOut', 'interval', 'packetSize', 'mixedPort'] as $key) {
            if (isset($data[$key])) { $this->$key = (int) $data[$key]; }
        }
        foreach (['ipLibrary', 'routingMode', 'skippedVersion', 'selectedDns', 'toolType', 'hostInput'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) { $this->$key = $data[$key]; }
        }
        foreach (['tunEnabled', 'ipv6Enabled', 'detailedTraffic'] as $key) {
            if (isset($data[$key])) { $this->$key = (bool) $data[$key]; }
        }
        $this->nextFavId = max(array_column($this->favorites, 'id') ?: [0]) + 1;
        $this->nextHistId = max(array_column($this->history, 'id') ?: [0]) + 1;
        $this->nextProfileId = max(array_column($this->profiles, 'id') ?: [0]) + 1;
        $ids = [0];
        foreach ($this->profiles as $profile) {
            foreach ($profile['nodes'] as $node) {
                $ids[] = $node['id'];
            }
        }
        $this->nextNodeId = max($ids) + 1;
    }

    public function save(): void
    {
        $this->store?->save([
            'favorites' => $this->favorites,
            'history' => $this->history,
            'profiles' => $this->profiles,
            'activeProfileId' => $this->activeProfileId,
            'selectedNodeId' => $this->selectedNodeId,
            'ttl' => $this->ttl, 'timeOut' => $this->timeOut, 'interval' => $this->interval, 'packetSize' => $this->packetSize,
            'mixedPort' => $this->mixedPort, 'ipLibrary' => $this->ipLibrary, 'routingMode' => $this->routingMode,
            'skippedVersion' => $this->skippedVersion, 'selectedDns' => $this->selectedDns, 'toolType' => $this->toolType,
            'hostInput' => $this->hostInput, 'tunEnabled' => $this->tunEnabled, 'ipv6Enabled' => $this->ipv6Enabled, 'detailedTraffic' => $this->detailedTraffic,
        ]);
    }

    // --- Channel-Aware Update Actions (App Store / Play Store / Standalone APK) ---

    /**
     * Current distribution channel:
     * - 'play': Google Play Store build (in-app self-update disabled per Google Play policy; jumps to Play Store)
     * - 'appstore': Apple App Store build (iOS, iPadOS, tvOS, visionOS, and Mac App Store; jumps to App Store)
     * - 'dmg': Standalone macOS .dmg build (supports in-app self-update)
     * - 'apk': Standalone Android .apk build (supports in-app APK download + PackageInstaller self-upgrade)
     * - 'desktop': Windows / Linux standalone build
     */
    public function distributionChannel(): string
    {
        return Device::channel();
    }

    /** Whether in-app update capsule is allowed on this build (disabled on Play Store per policy). */
    public function isInAppUpdateAllowed(): bool
    {
        return !Device::isPlayStore();
    }

    /** Parses "2.0.0+5", "v2.0.0_b5", or "Linking v2.0.0 (5)" into [version, build]. @return array{0: string, 1: int} */
    public static function parseVersionAndBuild(string $raw, int $defaultBuild = 0, string $releaseTitle = ''): array
    {
        $clean = ltrim(trim($raw), 'vV');
        $build = $defaultBuild;
        if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:[+_b-]+([0-9]+))?/i', $clean, $m)) {
            $ver = $m[1];
            if (!empty($m[2])) {
                $build = (int) $m[2];
            }
        } else {
            $ver = explode('+', $clean, 2)[0];
        }
        if ($build <= 0 && $releaseTitle !== '' && preg_match('/\(([0-9]+)\)/', $releaseTitle, $tm)) {
            $build = (int) $tm[1];
        }
        return [$ver, $build];
    }

    /** Formats version + build in TestFlight style, e.g. "2.0.0 (5)". */
    public function fullVersionText(): string
    {
        return $this->currentBuild > 0 ? "{$this->currentVersion} ({$this->currentBuild})" : $this->currentVersion;
    }

    /** The store an update goes through, for the capsule's words: 'appstore', 'play', or null for the app's own download. */
    public function updateStore(): ?string
    {
        $channel = $this->distributionChannel();
        return $channel === 'appstore' || $channel === 'play' ? $channel : null;
    }

    public function checkUpdate(bool $manual = false): void
    {
        // Google Play policy strictly forbids in-app self-update prompts/downloads;
        // on Play Store builds, manual check jumps directly to Google Play Store, automatic check is a no-op.
        if (Device::isPlayStore()) {
            $this->updateStatus = 'idle';
            $this->updateVersion = '';
            if ($manual) {
                $this->openExternalUrl($this->playStoreUrl, 'Redirecting to Google Play Store for update...');
            }
            return;
        }

        if ($this->updateStatus === 'downloading' || $this->updateStatus === 'installing') {
            return;
        }
        $this->updateStatus = 'checking';
        if ($manual) {
            $this->appendLog('INFO', "Checking GitHub Releases (owner888/agc_linking) for updates (current v{$this->currentVersion})...");
        }

        if ($this->deterministicProbes) {
            $nextBuild = $this->currentBuild + 1;
            $this->applyReleasePayload([
                'tag_name' => "v{$this->currentVersion}+{$nextBuild}",
                'name' => "Linking v{$this->currentVersion} ({$nextBuild})",
                'body' => 'Native GPU UpdateCapsule & faster multi-DNS probing',
                'assets' => [
                    [
                        'name' => 'Linking.apk',
                        'browser_download_url' => 'https://github.com/owner888/agc_linking/releases/latest/download/Linking.apk',
                    ],
                ],
            ], $manual);
            return;
        }

        if (class_exists(App::class)) {
            App::go(function () use ($manual) {
                try {
                    $req = new URLRequest(
                        'https://api.github.com/repos/owner888/agc_linking/releases/latest',
                        headers: ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Linking-MyPHP/' . $this->currentVersion]
                    );
                    $data = URLSession::shared()->json($req);
                    if (is_array($data)) {
                        $this->applyReleasePayload($data, $manual);
                    } else {
                        $this->updateStatus = 'idle';
                    }
                } catch (\Throwable $e) {
                    $this->updateStatus = 'idle';
                    if ($manual) {
                        $this->appendLog('ERROR', 'Update check failed: ' . $e->getMessage());
                    }
                }
            });
        }
    }

    /** @param array<string, mixed> $data */
    private function applyReleasePayload(array $data, bool $manual): void
    {
        $tag = (string) ($data['tag_name'] ?? '');
        $relName = (string) ($data['name'] ?? '');
        [$latestVer, $latestBuild] = self::parseVersionAndBuild($tag, 0, $relName);
        if ($latestVer === '') {
            $this->updateStatus = 'idle';
            return;
        }
        $body = trim((string) ($data['body'] ?? ''));
        $firstLine = strtok($body, "\r\n") ?: 'New build available on GitHub Releases';
        $wantExt = $this->distributionChannel() === 'dmg' ? '.dmg' : '.apk';
        $downloadUrl = $wantExt === '.dmg'
            ? $this->githubReleaseUrl
            : 'https://github.com/owner888/agc_linking/releases/latest/download/Linking.apk';
        $assetSize = 0;
        foreach (($data['assets'] ?? []) as $asset) {
            if (is_array($asset) && str_ends_with((string) ($asset['name'] ?? ''), $wantExt)) {
                $downloadUrl = (string) ($asset['browser_download_url'] ?? $downloadUrl);
                $assetSize = (int) ($asset['size'] ?? 0);
                break;
            }
        }
        $this->updateAssetSize = $assetSize;

        $displayVer = $latestBuild > 0 ? "{$latestVer} ({$latestBuild})" : $latestVer;
        $verCmp = version_compare($latestVer, $this->currentVersion);
        $isNewer = $verCmp > 0 || ($verCmp === 0 && $latestBuild > $this->currentBuild);

        if ($isNewer) {
            if (!$manual && $this->skippedVersion === $displayVer) {
                $this->updateStatus = 'idle';
                return;
            }
            $this->updateVersion = $displayVer;
            $this->updateNotes = $firstLine;
            $this->updateDownloadUrl = $downloadUrl;
            $this->updateProgress = 0.0;
            $this->updateStatus = 'available';
            $this->appendLog('INFO', "New build available: v{$displayVer} ({$firstLine})");
        } else {
            $this->updateStatus = 'idle';
            if ($manual) {
                $this->appendLog('INFO', "Already up to date (v{$this->fullVersionText()})");
            }
        }
        $this->window?->invalidate(); // a check ends outside an event: ask for a frame
    }

    public function simulateUpdateAvailable(): void
    {
        if (Device::isPlayStore()) {
            $this->appendLog('INFO', 'Play Store build: in-app update capsule is disabled per Google Play policy');
            return;
        }
        $nextBuild = $this->currentBuild + 1;
        $this->updateVersion = "{$this->currentVersion} ({$nextBuild})";
        $this->updateNotes = 'Telegram-style Update Capsule & instant APK self-upgrade';
        $this->updateDownloadUrl = 'https://github.com/owner888/agc_linking/releases/latest/download/Linking.apk';
        $this->updateAssetSize = 0; // a preview has no download: the capsule shows the version
        $this->updateProgress = 0.0;
        $this->updateStatus = 'available';
        $this->appendLog('INFO', "Update capsule activated: v{$this->updateVersion} ready to install");
    }

    private function openExternalUrl(string $url, string $logMessage): void
    {
        $this->lastOpenedExternalUrl = $url;
        $this->appendLog('INFO', "{$logMessage} ({$url})");
        if (!$this->deterministicProbes && class_exists(Shell::class)) {
            try {
                Shell::openExternal($url);
            } catch (\Throwable) {
            }
        }
    }

    public function performUpdate(): void
    {
        $channel = $this->distributionChannel();

        // 1. iOS / iPadOS App Store build -> jump to Apple App Store
        if ($channel === 'appstore') {
            $this->openExternalUrl($this->appStoreUrl, 'Opening Apple App Store to update Linking');
            $this->updateStatus = 'idle';
            $this->updateVersion = '';
            return;
        }

        // 2. Android Google Play Store build -> jump to Google Play Store (never download APK in-app)
        if ($channel === 'play') {
            $this->openExternalUrl($this->playStoreUrl, 'Opening Google Play Store to update Linking');
            $this->updateStatus = 'idle';
            $this->updateVersion = '';
            return;
        }

        // 3. Standalone APK / Desktop build -> in-app download & install
        if ($this->updateStatus === 'installing' && !$this->deterministicProbes && class_exists(App::class) && App::isAndroid()) {
            // downloaded: "Update Now" installs it again, once the permission to install is given or the installer was dismissed, as Telegram's
            try {
                \MyPHP\Native\Jni::callStatic('com.owner888.myphp.MyphpActivity', 'installDownloadedApk', '()V');
                $this->watchApkUpgrade();
            } catch (\Throwable $e) {
                $this->appendLog('ERROR', 'Could not start the installer: ' . $e->getMessage());
            }
            return;
        }
        if ($this->updateVersion === '' || $this->updateStatus === 'downloading' || $this->updateStatus === 'installing') {
            return;
        }
        $this->updateStatus = 'downloading';
        $this->updateProgress = 0.0;
        $this->appendLog('INFO', "Downloading update v{$this->updateVersion} from {$this->updateDownloadUrl}...");

        if ($this->deterministicProbes) {
            $this->updateProgress = 1.0;
            $this->updateStatus = 'installing';
            $this->appendLog('INFO', "Update v{$this->updateVersion} downloaded; launching system package installer");
            return;
        }

        if (class_exists(App::class)) {
            $url = $this->updateDownloadUrl;
            if (App::isAndroid()) {
                try {
                    $cls = 'com.owner888.myphp.MyphpActivity'; // callStatic() takes the class's name
                    \MyPHP\Native\Jni::callStatic($cls, 'startApkUpgrade', '(Ljava/lang/String;)V', $url);
                    $this->watchApkUpgrade();
                    return;
                } catch (\Throwable $e) {
                    $this->appendLog('ERROR', 'Native APK updater fallback: ' . $e->getMessage());
                }
            }

            // Desktop / fallback: defer out of frame build pass and open download URL
            App::after(0.05, function () use ($url) {
                $this->updateProgress = 1.0;
                $this->updateStatus = 'installing';
                try {
                    Shell::openExternal($url);
                } catch (\Throwable) {}
            });
        }
    }

    /** Follows the Android updater until it has installed, failed or been refused: its progress shows in the capsule, and what the installer says when it refuses. */
    private function watchApkUpgrade(): void
    {
        $cls = 'com.owner888.myphp.MyphpActivity';
        $poll = null;
        $gen = ++$this->apkWatchGen; // a tap that starts it again ends the one before
        $poll = function () use (&$poll, $cls, $gen): void {
            if ($gen !== $this->apkWatchGen || ($this->updateStatus !== 'downloading' && $this->updateStatus !== 'installing')) {
                return;
            }
            $p = (int) \MyPHP\Native\Jni::callStatic($cls, 'getApkUpgradeProgress', '()I');
            if ($p >= 0 && $p <= 100) {
                $this->updateStatus = 'downloading';
                $this->updateProgress = $p / 100.0;
                App::after(0.06, $poll);
            } elseif ($p === 101) { // downloaded, the system installer has it: this goes on in case it refuses
                if ($this->updateStatus !== 'installing') {
                    $this->updateProgress = 1.0;
                    $this->updateStatus = 'installing';
                    $this->appendLog('INFO', 'APK downloaded; system package installer launched');
                }
                App::after(0.5, $poll);
            } elseif ($p === -3) {
                $this->updateStatus = 'failed';
                $why = (string) \MyPHP\Native\Jni::callStatic($cls, 'getApkInstallMessage', '()Ljava/lang/String;');
                $this->appendLog('ERROR', 'The system installer refused the update' . ($why !== '' ? ": {$why}" : ''));
            } elseif ($p < 0) {
                $this->updateStatus = 'failed';
                $this->appendLog('ERROR', 'APK background download failed');
            }
            $this->window?->invalidate(); // changed outside an event: ask for a frame, or it shows with the next touch
        };
        App::after(0.06, $poll);
    }

    public function skipUpdate(): void
    {
        if ($this->updateVersion !== '') {
            $this->skippedVersion = $this->updateVersion;
            $this->appendLog('INFO', "Skipped update v{$this->skippedVersion}");
        }
        $this->updateStatus = 'idle';
        $this->updateVersion = '';
        $this->save();
    }

    // --- Settings Actions ---

    public function resetConfig(): void
    {
        $this->ttl = 64;
        $this->timeOut = 5;
        $this->interval = 1;
        $this->packetSize = 64;
        $this->ipLibrary = 'IP_2LOCATION';
        $this->appendLog('INFO', 'Reset Ping configuration to default values (TTL=64, Timeout=5s, Interval=1s, PacketSize=64B)');
        $this->save();
    }
}
