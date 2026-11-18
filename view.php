<?php
// Linking declarative UI ($this is LinkingApp).
// One codebase for macOS, Windows, Linux, iOS, and Android.
declare(strict_types=1);

$accentBlue = Color::rgb(0x2B65F1);
$accentGold = Color::rgb(0xF7C35E);
$okGreen = Color::rgb(0x16A34A);
$warnAmber = Color::rgb(0xD97706);
$errRed = Color::rgb(0xDC2626);

$updateCapsuleBar = fn() => UpdateCapsule(
    version: $this->updateVersion,
    status: $this->updateStatus,
    progress: $this->updateProgress,
    notes: $this->updateNotes,
    force: $this->updateForce,
    onAction: fn() => $this->performUpdate(),
    onDismiss: fn() => $this->skipUpdate(),
    tint: $accentGold,
);

$statCard = fn(string $label, string $value, ?string $sub = null) => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
    Text($label)->font(Font::caption)->foregroundStyle(Color::secondary),
    Text(verbatim: $value)->font(Font::headline),
    $sub !== null ? Text(verbatim: $sub)->font(Font::caption2)->foregroundStyle(Color::secondary) : null,
)
    ->padding(12)
    ->frame(maxWidth: INF, alignment: Alignment::leading)
    ->background(Color::secondarySystemGroupedBackground)
    ->cornerRadius(10);

$pingView = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
        $updateCapsuleBar(),

        // Top Header + Mode Switcher
        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text('Network Diagnostics')->font(Font::title2),
                Text('ICMP Ping · HTTP/2 Probe · TCP Handshake · Multi-DNS Lookup')
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary),
            ),
            Spacer(),
            HStack(spacing: 6)(
                Button('Ping', action: fn() => $this->setToolType('Ping'))
                    ->buttonStyle($this->toolType === 'Ping' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                Button('Httping', action: fn() => $this->setToolType('Httping'))
                    ->buttonStyle($this->toolType === 'Httping' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                Button('Tcping', action: fn() => $this->setToolType('Tcping'))
                    ->buttonStyle($this->toolType === 'Tcping' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
            ),
        ),

        // Target Input & DNS Bar
        VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
            HStack(alignment: VerticalAlignment::center, spacing: 8)(
                Picker('DNS', selection: $this->selectedDns)(
                    Each($this->dnsOptions)(fn(string $dns) => Text(verbatim: $dns)->tag($dns)),
                )->frame(width: 170),
                TextField('Enter host, IP or URL (e.g. 1.1.1.1, github.com:443)', text: $this->hostInput)
                    ->onSubmit(fn() => $this->startPing()),
                Button('NSLookup', action: fn() => $this->runNslookup())
                    ->buttonStyle(ButtonStyle::bordered),
                Button($this->isRunning ? 'Stop' : 'Start', action: fn() => $this->togglePing())
                    ->buttonStyle(ButtonStyle::borderedProminent)
                    ->tint($this->isRunning ? $errRed : $accentBlue),
            ),
            // Quick presets & active config summary
            HStack(alignment: VerticalAlignment::center, spacing: 8)(
                Text('Quick Targets:')->font(Font::caption)->foregroundStyle(Color::secondary),
                Button('1.1.1.1', action: fn() => $this->probeFavorite('1.1.1.1', $this->toolType))
                    ->buttonStyle(ButtonStyle::bordered),
                Button('8.8.8.8', action: fn() => $this->probeFavorite('8.8.8.8', $this->toolType))
                    ->buttonStyle(ButtonStyle::bordered),
                Button('cloudflare.com', action: fn() => $this->probeFavorite('cloudflare.com', $this->toolType))
                    ->buttonStyle(ButtonStyle::bordered),
                Button('github.com:443', action: fn() => $this->probeFavorite('github.com:443', 'Tcping'))
                    ->buttonStyle(ButtonStyle::bordered),
                Spacer(),
                Text(verbatim: "TTL {$this->ttl} · Timeout {$this->timeOut}s · {$this->packetSize}B · {$this->ipLibrary}")
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary),
            ),
        )
            ->padding(14)
            ->background(Color::secondarySystemGroupedBackground)
            ->cornerRadius(12),

        // NSLookup Results drawer (when populated)
        $this->nslookupResults !== []
            ? VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
                HStack(alignment: VerticalAlignment::center, spacing: 8)(
                    Text(verbatim: "DNS Resolution ({$this->selectedDns})")->font(Font::subheadline),
                    Spacer(),
                    Button('Dismiss', action: function () { $this->nslookupResults = []; })
                        ->buttonStyle(ButtonStyle::plain),
                ),
                HStack(spacing: 8)(
                    Each($this->nslookupResults, id: 'ip')(fn(array $row) => Button(
                        "{$row['ip']} ({$row['country']})",
                        action: fn() => $this->selectResolvedIp($row['ip'])
                    )->buttonStyle(ButtonStyle::bordered)),
                ),
            )
                ->padding(12)
                ->background(Color::secondarySystemGroupedBackground)
                ->cornerRadius(10)
            : null,

        // 8-Metric Statistics Panel (Sent, Received, Loss, Country, Min, Avg, Max, Stddev)
        VStack(spacing: 10)(
            HStack(spacing: 10)(
                $statCard('Sent', (string) $this->sentCount(), $this->toolType . ' mode'),
                $statCard('Received', (string) $this->receivedCount(), '100% delivery'),
                $statCard('Loss', (string) $this->lossCount(), '0% packet loss'),
                $statCard('Country', $this->ipCountry, $this->ipLocationDetail),
            ),
            HStack(spacing: 10)(
                $statCard('Min', $this->minLatencyText(), 'Best RTT'),
                $statCard('Avg', $this->avgLatencyText(), 'Mean RTT'),
                $statCard('Max', $this->maxLatencyText(), 'Peak RTT'),
                $statCard('Stddev', $this->stddevText(), 'Jitter'),
            ),
        ),

        // Live Sequence Stream List
        VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
            HStack(alignment: VerticalAlignment::center, spacing: 8)(
                Text(verbatim: "Live Probe Stream ({$this->toolType})")->font(Font::headline),
                Spacer(),
                Text(verbatim: count($this->pingRecords) . ' packets')
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary),
            ),
            $this->pingRecords === []
                ? ContentUnavailableView(
                    'No Probe Data Yet',
                    systemImage: 'waveform.path.ecg',
                    description: 'Enter an IP or domain above and click Start to begin real-time diagnostics.'
                )
                : VStack(spacing: 6)(
                    Each($this->pingRecords, id: 'id')(fn(array $item) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                        Text(verbatim: '#' . $item['seq'])
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary)
                            ->frame(width: 36, alignment: Alignment::leading),
                        Text(verbatim: $item['mode'])
                            ->font(Font::caption)
                            ->padding(Edge::horizontal, 8)
                            ->padding(Edge::vertical, 3)
                            ->background(Color::tertiarySystemGroupedBackground)
                            ->cornerRadius(6),
                        Text(verbatim: $item['status'])
                            ->font(Font::body)
                            ->frame(maxWidth: INF, alignment: Alignment::leading),
                        Text(verbatim: $item['time'])
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary),
                        Text(verbatim: $item['ms'] !== null ? ($item['ms'] . ' ms') : 'TIMEOUT')
                            ->font(Font::subheadline)
                            ->foregroundStyle($item['ok'] ? ($item['ms'] < 80 ? $okGreen : $warnAmber) : $errRed)
                            ->frame(width: 72, alignment: Alignment::trailing),
                    )
                        ->padding(Edge::horizontal, 12)
                        ->padding(Edge::vertical, 9)
                        ->background(Color::secondarySystemGroupedBackground)
                        ->cornerRadius(8)),
                ),
        ),
    )->padding(16),
);

$networkView = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
        $updateCapsuleBar(),

        // Connection Hero Card
        VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                    HStack(spacing: 8)(
                        Text($this->vpnConnected ? 'Tunnel Connected' : 'Tunnel Ready')
                            ->font(Font::title2),
                        Text($this->vpnConnected ? 'ACTIVE' : 'STANDBY')
                            ->font(Font::caption)
                            ->foregroundStyle($this->vpnConnected ? $okGreen : Color::secondary)
                            ->padding(Edge::horizontal, 8)
                            ->padding(Edge::vertical, 3)
                            ->background(Color::tertiarySystemGroupedBackground)
                            ->cornerRadius(6),
                    ),
                    Text(verbatim: 'Current Outbound: ' . $this->activeNode()['name'] . ' (' . $this->activeNode()['protocol'] . ')')
                        ->font(Font::subheadline)
                        ->foregroundStyle(Color::secondary),
                ),
                Spacer(),
                Button($this->vpnConnected ? 'Disconnect' : 'Connect Tunnel', action: fn() => $this->toggleVpn())
                    ->buttonStyle(ButtonStyle::borderedProminent)
                    ->tint($this->vpnConnected ? $okGreen : $accentBlue),
            ),
            Divider(),
            HStack(alignment: VerticalAlignment::center, spacing: 16)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text('Uplink Speed')->font(Font::caption)->foregroundStyle(Color::secondary),
                    Text(verbatim: '↑ ' . $this->upSpeed)->font(Font::headline),
                ),
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text('Downlink Speed')->font(Font::caption)->foregroundStyle(Color::secondary),
                    Text(verbatim: '↓ ' . $this->downSpeed)->font(Font::headline),
                ),
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text('Session Traffic')->font(Font::caption)->foregroundStyle(Color::secondary),
                    Text(verbatim: $this->sessionTraffic)->font(Font::headline),
                ),
                Spacer(),
                HStack(spacing: 6)(
                    Button('Rule', action: function () { $this->routingMode = 'Rule'; })
                        ->buttonStyle($this->routingMode === 'Rule' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                    Button('Global', action: function () { $this->routingMode = 'Global'; })
                        ->buttonStyle($this->routingMode === 'Global' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                    Button('Direct', action: function () { $this->routingMode = 'Direct'; })
                        ->buttonStyle($this->routingMode === 'Direct' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                ),
            ),
        )
            ->padding(16)
            ->background(Color::secondarySystemGroupedBackground)
            ->cornerRadius(12),

        // Subscription / URI Import Bar
        HStack(alignment: VerticalAlignment::center, spacing: 8)(
            TextField('Paste vless://, trojan://, hysteria2:// or subscription URL', text: $this->nodeImportInput)
                ->onSubmit(fn() => $this->importNodeUri()),
            Button('Import Profile', action: fn() => $this->importNodeUri())
                ->buttonStyle(ButtonStyle::borderedProminent),
            Button('Test All Latency', action: fn() => $this->testAllNodesLatency())
                ->buttonStyle(ButtonStyle::bordered),
        ),

        // Proxy Outbound Nodes List
        Text('Outbound Proxy Nodes')->font(Font::headline),
        VStack(spacing: 8)(
            Each($this->proxyNodes, id: 'id')(fn(array $node) => HStack(alignment: VerticalAlignment::center, spacing: 12)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 3)(
                    HStack(spacing: 8)(
                        Text(verbatim: $node['name'])->font(Font::headline),
                        Text(verbatim: $node['protocol'])
                            ->font(Font::caption2)
                            ->padding(Edge::horizontal, 6)
                            ->padding(Edge::vertical, 2)
                            ->background(Color::tertiarySystemGroupedBackground)
                            ->cornerRadius(4),
                    ),
                    Text(verbatim: "{$node['address']}:{$node['port']} · Region {$node['region']}")
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary),
                ),
                Spacer(),
                Text(verbatim: $node['latency'] . ' ms')
                    ->font(Font::subheadline)
                    ->foregroundStyle($node['latency'] < 80 ? $okGreen : $warnAmber),
                Button(
                    $this->activeNodeId === $node['id'] ? 'Selected' : 'Use Node',
                    action: fn() => $this->selectNode($node['id'])
                )->buttonStyle($this->activeNodeId === $node['id'] ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
            )
                ->padding(12)
                ->background(Color::secondarySystemGroupedBackground)
                ->cornerRadius(10)),
        ),
    )->padding(16),
);

$favoritesView = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
        $updateCapsuleBar(),
        Text('Favorites & Probe History')->font(Font::title2),

        // Add New Favorite Host Card
        VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
            Text('Add New Host')->font(Font::headline),
            HStack(alignment: VerticalAlignment::center, spacing: 8)(
                TextField('Host or IP (e.g. 8.8.4.4)', text: $this->newFavHost),
                TextField('Description / Remark', text: $this->newFavDesc)
                    ->onSubmit(fn() => $this->addFavorite()),
                Button('Add Host', action: fn() => $this->addFavorite())
                    ->buttonStyle(ButtonStyle::borderedProminent),
            ),
        )
            ->padding(14)
            ->background(Color::secondarySystemGroupedBackground)
            ->cornerRadius(12),

        // Saved Favorites List
        Text(verbatim: 'Saved Hosts (' . count($this->favorites) . ')')->font(Font::headline),
        $this->favorites === []
            ? ContentUnavailableView('No Favorites Yet', systemImage: 'star.slash', description: 'Add frequently tested hosts or DNS servers above.')
            : VStack(spacing: 8)(
                Each($this->favorites, id: 'id')(fn(array $fav) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                        HStack(spacing: 6)(
                            Text(verbatim: $fav['host'])->font(Font::headline),
                            Text(verbatim: $fav['tag'])
                                ->font(Font::caption2)
                                ->padding(Edge::horizontal, 6)
                                ->padding(Edge::vertical, 2)
                                ->background(Color::tertiarySystemGroupedBackground)
                                ->cornerRadius(4),
                        ),
                        Text(verbatim: $fav['description'])->font(Font::caption)->foregroundStyle(Color::secondary),
                    ),
                    Spacer(),
                    Button('Ping', action: fn() => $this->probeFavorite($fav['host'], 'Ping'))
                        ->buttonStyle(ButtonStyle::bordered),
                    Button('Tcping', action: fn() => $this->probeFavorite($fav['host'], 'Tcping'))
                        ->buttonStyle(ButtonStyle::bordered),
                    Button('Httping', action: fn() => $this->probeFavorite($fav['host'], 'Httping'))
                        ->buttonStyle(ButtonStyle::bordered),
                    Button('Delete', role: ButtonRole::destructive, action: fn() => $this->removeFavorite($fav['id']))
                        ->buttonStyle(ButtonStyle::bordered),
                )
                    ->padding(12)
                    ->background(Color::secondarySystemGroupedBackground)
                    ->cornerRadius(10)),
            ),

        // Recent Probe History
        HStack(alignment: VerticalAlignment::center, spacing: 8)(
            Text(verbatim: 'Recent History (' . count($this->history) . ')')->font(Font::headline),
            Spacer(),
            $this->history !== []
                ? Button('Clear History', action: fn() => $this->clearHistory())->buttonStyle(ButtonStyle::bordered)
                : null,
        ),
        $this->history === []
            ? Text('No recent probe history.')->font(Font::caption)->foregroundStyle(Color::secondary)
            : VStack(spacing: 6)(
                Each($this->history, id: 'id')(fn(array $h) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                    Text(verbatim: $h['mode'])
                        ->font(Font::caption)
                        ->padding(Edge::horizontal, 8)
                        ->padding(Edge::vertical, 3)
                        ->background(Color::tertiarySystemGroupedBackground)
                        ->cornerRadius(6),
                    Text(verbatim: $h['host'])->font(Font::body),
                    Spacer(),
                    Text(verbatim: $h['time'])->font(Font::caption)->foregroundStyle(Color::secondary),
                    Button('Re-run', action: fn() => $this->probeFavorite($h['host'], $h['mode']))
                        ->buttonStyle(ButtonStyle::bordered),
                )
                    ->padding(10)
                    ->background(Color::secondarySystemGroupedBackground)
                    ->cornerRadius(8)),
            ),
    )->padding(16),
);

$settingsView = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
        $updateCapsuleBar(),

        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text('Settings & Diagnostic Logs')->font(Font::title2),
                Text(verbatim: "Linking v{$this->currentVersion} · Package easy.net.tool")
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary),
            ),
            Spacer(),
            Button('Check for Updates', action: fn() => $this->checkUpdate(true))
                ->buttonStyle(ButtonStyle::borderedProminent),
            Button('Preview Update Capsule', action: fn() => $this->simulateUpdateAvailable())
                ->buttonStyle(ButtonStyle::bordered),
            Button('Reset to Default', action: fn() => $this->resetConfig())
                ->buttonStyle(ButtonStyle::bordered),
        ),

        // Ping Configuration (TTL, Timeout, Interval, Packet Size, IP Library)
        VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            Text('Ping & Probe Configuration')->font(Font::headline),
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                Text(verbatim: "TTL: {$this->ttl}")->frame(width: 140, alignment: Alignment::leading),
                Slider(value: $this->ttl, in: [1, 255], step: 1),
            ),
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                Text(verbatim: "Timeout: {$this->timeOut} s")->frame(width: 140, alignment: Alignment::leading),
                Slider(value: $this->timeOut, in: [1, 30], step: 1),
            ),
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                Text(verbatim: "Interval: {$this->interval} s")->frame(width: 140, alignment: Alignment::leading),
                Slider(value: $this->interval, in: [1, 30], step: 1),
            ),
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                Text(verbatim: "Packet Size: {$this->packetSize} B")->frame(width: 140, alignment: Alignment::leading),
                Slider(value: $this->packetSize, in: [16, 1472], step: 16),
            ),
            Divider(),
            HStack(alignment: VerticalAlignment::center, spacing: 12)(
                Text('IP Geo Library')->frame(width: 140, alignment: Alignment::leading),
                Button('IP_2LOCATION', action: function () { $this->ipLibrary = 'IP_2LOCATION'; })
                    ->buttonStyle($this->ipLibrary === 'IP_2LOCATION' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                Button('IP_138', action: function () { $this->ipLibrary = 'IP_138'; })
                    ->buttonStyle($this->ipLibrary === 'IP_138' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
                Spacer(),
                Toggle('Enable TUN Mode', isOn: $this->tunEnabled),
                Toggle('IPv6 Route', isOn: $this->ipv6Enabled),
            ),
        )
            ->padding(16)
            ->background(Color::secondarySystemGroupedBackground)
            ->cornerRadius(12),

        // Runtime Logs
        HStack(alignment: VerticalAlignment::center, spacing: 8)(
            Text(verbatim: 'Runtime Logs (' . count($this->logs) . ')')->font(Font::headline),
            Spacer(),
            Button('Clear Logs', action: fn() => $this->clearLogs())
                ->buttonStyle(ButtonStyle::bordered),
        ),
        VStack(spacing: 6)(
            Each($this->logs, id: 'id')(fn(array $log) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                Text(verbatim: $log['time'])->font(Font::caption)->foregroundStyle(Color::secondary),
                Text(verbatim: $log['level'])
                    ->font(Font::caption2)
                    ->foregroundStyle($log['level'] === 'ERROR' ? $errRed : $accentBlue)
                    ->padding(Edge::horizontal, 6)
                    ->padding(Edge::vertical, 2)
                    ->background(Color::tertiarySystemGroupedBackground)
                    ->cornerRadius(4),
                Text(verbatim: $log['message'])->font(Font::caption)->frame(maxWidth: INF, alignment: Alignment::leading),
            )
                ->padding(8)
                ->background(Color::secondarySystemGroupedBackground)
                ->cornerRadius(6)),
        ),
    )->padding(16),
);

return fn() => TabView(selection: $this->tab)(
    Tab('Ping Tools', systemImage: 'waveform.path.ecg', value: 'ping')(
        $pingView(),
    ),
    Tab('Network', systemImage: 'network', value: 'network')(
        $networkView(),
    ),
    Tab('Favorites', systemImage: 'star.fill', value: 'favorites')(
        $favoritesView(),
    ),
    Tab('Settings', systemImage: 'gearshape.fill', value: 'settings')(
        $settingsView(),
    ),
)
    ->tabViewStyle(TabViewStyle::sidebarAdaptable)
    ->tint($accentBlue);
