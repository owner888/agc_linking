<?php
// Linking declarative UI ($this is LinkingApp).
// Uses native MyPHP SwiftUI views: TabView + NavigationStack + ListView + Section + LabeledContent + Picker + swipeActions.
declare(strict_types=1);

$accentBlue = Color::rgb(0x2B65F1);
$accentGold = Color::rgb(0xF7C35E);
$okGreen = Color::rgb(0x16A34A);
$warnAmber = Color::rgb(0xD97706);
$errRed = Color::rgb(0xDC2626);

$updateCapsuleBar = fn() => $this->isInAppUpdateAllowed() && $this->updateVersion !== '' && in_array($this->updateStatus, ['available', 'downloading', 'installing', 'failed'], true)
    ? UpdateCapsule(
        version: $this->updateVersion,
        status: $this->updateStatus,
        progress: $this->updateProgress,
        notes: $this->updateNotes,
        force: $this->updateForce,
        onAction: fn() => $this->performUpdate(),
        onDismiss: fn() => $this->skipUpdate(),
        tint: $accentGold,
        actionTitle: $this->updateActionButtonTitle(),
    )
    : null;

$pingView = fn() => ListView()(
    $updateCapsuleBar(),

    Section(
        header: 'Diagnostic Target',
        footer: "TTL {$this->ttl} · Timeout {$this->timeOut}s · {$this->packetSize}B · {$this->ipLibrary}"
    )(
        Picker('Mode', selection: $this->toolType)(
            Text('Ping')->tag('Ping'),
            Text('Httping')->tag('Httping'),
            Text('Tcping')->tag('Tcping'),
        )->pickerStyle(PickerStyle::segmented),
        Picker('DNS', selection: $this->selectedDns)(
            Each($this->dnsOptions)(fn(string $dns) => Text(verbatim: $dns)->tag($dns)),
        ),
        TextField('Target Host', text: $this->hostInput, prompt: '1.1.1.1 or github.com:443')
            ->onSubmit(fn() => $this->startPing()),
        HStack(spacing: 10)(
            Button($this->isRunning ? 'Stop' : 'Start', action: fn() => $this->togglePing())
                ->buttonStyle(ButtonStyle::borderedProminent)
                ->tint($this->isRunning ? $errRed : $accentBlue),
            Button('NSLookup', action: fn() => $this->runNslookup())
                ->buttonStyle(ButtonStyle::bordered),
        ),
    ),

    Section(header: 'Quick Targets')(
        FlowLayout(spacing: 8)(
            Button('1.1.1.1', action: fn() => $this->probeFavorite('1.1.1.1', $this->toolType))
                ->buttonStyle(ButtonStyle::bordered),
            Button('8.8.8.8', action: fn() => $this->probeFavorite('8.8.8.8', $this->toolType))
                ->buttonStyle(ButtonStyle::bordered),
            Button('cloudflare.com', action: fn() => $this->probeFavorite('cloudflare.com', $this->toolType))
                ->buttonStyle(ButtonStyle::bordered),
            Button('github.com:443', action: fn() => $this->probeFavorite('github.com:443', 'Tcping'))
                ->buttonStyle(ButtonStyle::bordered),
        ),
    ),

    $this->nslookupResults !== []
        ? Section(header: "DNS Resolution ({$this->selectedDns})")(
            Each($this->nslookupResults, id: 'ip')(fn(array $row) => HStack(alignment: VerticalAlignment::center, spacing: 8)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: $row['ip'])->font(Font::headline),
                    Text(verbatim: "{$row['country']} · {$row['isp']}")
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary)
                        ->lineLimit(1),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Button('Ping IP', action: fn() => $this->selectResolvedIp($row['ip']))
                    ->buttonStyle(ButtonStyle::bordered),
            )),
            Button('Dismiss DNS Results', action: function () { $this->nslookupResults = []; })
                ->buttonStyle(ButtonStyle::plain),
        )
        : null,

    Section(header: 'Live Statistics', footer: $this->ipLocationDetail)(
        LabeledContent('Packets (Sent / Received / Loss)', "{$this->sentCount()} / {$this->receivedCount()} / {$this->lossCount()}"),
        LabeledContent('Region / Country', $this->ipCountry),
        LabeledContent('Min / Avg / Max RTT', "{$this->minLatencyText()} / {$this->avgLatencyText()} / {$this->maxLatencyText()}"),
        LabeledContent('Stddev (Jitter)', $this->stddevText()),
    ),

    Section(header: "Live Probe Stream ({$this->toolType}) · " . count($this->pingRecords) . ' packets')(
        $this->pingRecords === []
            ? ContentUnavailableView(
                'No Probe Data Yet',
                systemImage: 'waveform.path.ecg',
                description: 'Enter an IP or domain above and tap Start.'
            )
            : Each($this->pingRecords, id: 'id')(fn(array $item) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: "#{$item['seq']} · {$item['ip']} ({$item['mode']})")
                        ->font(Font::subheadline),
                    Text(verbatim: "{$item['status']} · {$item['time']}")
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary)
                        ->lineLimit(1),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Text(verbatim: $item['ms'] !== null ? ($item['ms'] . ' ms') : 'TIMEOUT')
                    ->font(Font::headline)
                    ->foregroundStyle($item['ok'] ? ($item['ms'] < 80 ? $okGreen : $warnAmber) : $errRed),
            )),
    ),
)
    ->navigationTitle('Network Diagnostics')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

$networkView = fn() => ListView()(
    $updateCapsuleBar(),

    Section(
        header: $this->vpnConnected ? 'Tunnel Connected' : 'Tunnel Ready',
        footer: "Session Traffic: {$this->sessionTraffic}"
    )(
        LabeledContent('Status', $this->vpnConnected ? 'ACTIVE' : 'STANDBY'),
        LabeledContent('Current Outbound', $this->activeNode()['name']),
        LabeledContent('Protocol', $this->activeNode()['protocol']),
        LabeledContent('Live Speed', "↑ {$this->upSpeed}   ↓ {$this->downSpeed}"),
        Picker('Routing Mode', selection: $this->routingMode)(
            Text('Rule')->tag('Rule'),
            Text('Global')->tag('Global'),
            Text('Direct')->tag('Direct'),
        )->pickerStyle(PickerStyle::segmented),
        Button($this->vpnConnected ? 'Disconnect' : 'Connect Tunnel', action: fn() => $this->toggleVpn())
            ->buttonStyle(ButtonStyle::borderedProminent)
            ->tint($this->vpnConnected ? $okGreen : $accentBlue),
    ),

    Section(header: 'Import Profile or Subscription')(
        TextField('Profile URI', text: $this->nodeImportInput, prompt: 'vless://, trojan://, hy2:// or https://...')
            ->onSubmit(fn() => $this->importNodeUri()),
        HStack(spacing: 10)(
            Button('Import Profile', action: fn() => $this->importNodeUri())
                ->buttonStyle(ButtonStyle::borderedProminent),
            Button('Test All Latency', action: fn() => $this->testAllNodesLatency())
                ->buttonStyle(ButtonStyle::bordered),
        ),
    ),

    Section(header: 'Outbound Proxy Nodes')(
        Each($this->proxyNodes, id: 'id')(fn(array $node) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text(verbatim: $node['name'])->font(Font::subheadline),
                Text(verbatim: "{$node['protocol']} · {$node['address']}:{$node['port']} ({$node['region']})")
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary)
                    ->lineLimit(1),
            )->frame(maxWidth: INF, alignment: Alignment::leading),
            Text(verbatim: $node['latency'] . ' ms')
                ->font(Font::subheadline)
                ->foregroundStyle($node['latency'] < 80 ? $okGreen : $warnAmber),
            Button(
                $this->activeNodeId === $node['id'] ? 'Selected' : 'Use Node',
                action: fn() => $this->selectNode($node['id'])
            )->buttonStyle($this->activeNodeId === $node['id'] ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
        )),
    ),
)
    ->navigationTitle('Network')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

$favoritesView = fn() => ListView()(
    $updateCapsuleBar(),

    Section(header: 'Add New Host')(
        TextField('Host or IP', text: $this->newFavHost, prompt: 'e.g. 8.8.4.4 or github.com'),
        TextField('Remark', text: $this->newFavDesc, prompt: 'Description / Remark')
            ->onSubmit(fn() => $this->addFavorite()),
        Button('Add Host', action: fn() => $this->addFavorite())
            ->buttonStyle(ButtonStyle::borderedProminent),
    ),

    Section(header: 'Saved Hosts (' . count($this->favorites) . ')')(
        $this->favorites === []
            ? ContentUnavailableView('No Favorites Yet', systemImage: 'star.slash', description: 'Add frequently tested hosts or DNS servers above.')
            : Each($this->favorites, id: 'id')(fn(array $fav) => HStack(alignment: VerticalAlignment::center, spacing: 8)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: "{$fav['host']} · {$fav['tag']}")->font(Font::subheadline),
                    Text(verbatim: $fav['description'])
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary)
                        ->lineLimit(1),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Button('Ping', action: fn() => $this->probeFavorite($fav['host'], 'Ping'))
                    ->buttonStyle(ButtonStyle::bordered),
                Button('Tcping', action: fn() => $this->probeFavorite($fav['host'], 'Tcping'))
                    ->buttonStyle(ButtonStyle::bordered),
            )->swipeActions(
                Button('Httping', action: fn() => $this->probeFavorite($fav['host'], 'Httping')),
                Button('Delete', role: ButtonRole::destructive, action: fn() => $this->removeFavorite($fav['id'])),
            )),
    ),

    Section(header: 'Recent History (' . count($this->history) . ')')(
        $this->history === []
            ? Text('No recent probe history.')->font(Font::caption)->foregroundStyle(Color::secondary)
            : Each($this->history, id: 'id')(fn(array $h) => HStack(alignment: VerticalAlignment::center, spacing: 8)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: $h['host'])->font(Font::subheadline)->lineLimit(1),
                    Text(verbatim: "{$h['mode']} · {$h['time']}")->font(Font::caption)->foregroundStyle(Color::secondary),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Button('Re-run', action: fn() => $this->probeFavorite($h['host'], $h['mode']))
                    ->buttonStyle(ButtonStyle::bordered),
            )),
        $this->history !== []
            ? Button('Clear History', role: ButtonRole::destructive, action: fn() => $this->clearHistory())
            : null,
    ),
)
    ->navigationTitle('Favorites')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

$settingsView = fn() => ListView()(
    $updateCapsuleBar(),

    Section(header: 'Ping & Probe Configuration')(
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            LabeledContent('TTL', (string) $this->ttl),
            Slider(value: $this->ttl, in: [1, 255], step: 1),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            LabeledContent('Timeout', "{$this->timeOut} s"),
            Slider(value: $this->timeOut, in: [1, 30], step: 1),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            LabeledContent('Interval', "{$this->interval} s"),
            Slider(value: $this->interval, in: [1, 30], step: 1),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            LabeledContent('Packet Size', "{$this->packetSize} B"),
            Slider(value: $this->packetSize, in: [16, 1472], step: 16),
        ),
        Picker('IP Geo Library', selection: $this->ipLibrary)(
            Text('IP_2LOCATION')->tag('IP_2LOCATION'),
            Text('IP_138')->tag('IP_138'),
        )->pickerStyle(PickerStyle::segmented),
        Toggle('Enable TUN Mode', isOn: $this->tunEnabled),
        Toggle('IPv6 Route', isOn: $this->ipv6Enabled),
        Button('Reset to Default', action: fn() => $this->resetConfig()),
    ),

    Section(
        header: 'Version & Updates',
        footer: "Package: {$this->packageName} · Channel: " . strtoupper($this->distributionChannel())
    )(
        LabeledContent('Version', 'v' . $this->currentVersion),
        $this->isInAppUpdateAllowed()
            ? Button('Check for Updates', action: fn() => $this->checkUpdate(true))
            : Button('Rate on Google Play', action: fn() => $this->checkUpdate(true)),
        $this->isInAppUpdateAllowed()
            ? Button('Preview Update Capsule', action: fn() => $this->simulateUpdateAvailable())
            : null,
    ),

    Section(header: 'Runtime Logs (' . count($this->logs) . ')')(
        Each($this->logs, id: 'id')(fn(array $log) => VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text(verbatim: "[{$log['level']}] {$log['time']}")
                ->font(Font::caption2)
                ->foregroundStyle($log['level'] === 'ERROR' ? $errRed : $accentBlue),
            Text(verbatim: $log['message'])
                ->font(Font::caption)
                ->foregroundStyle(Color::secondary),
        )),
        $this->logs !== []
            ? Button('Clear Logs', action: fn() => $this->clearLogs())
            : null,
    ),
)
    ->navigationTitle('Settings')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

return fn() => TabView(selection: $this->tab)(
    Tab('Ping Tools', systemImage: 'waveform.path.ecg', value: 'ping')(
        NavigationStack(path: $this->pingPath)($pingView()),
    ),
    Tab('Network', systemImage: 'network', value: 'network')(
        NavigationStack(path: $this->netPath)($networkView()),
    ),
    Tab('Favorites', systemImage: 'star.fill', value: 'favorites')(
        NavigationStack(path: $this->favPath)($favoritesView()),
    ),
    Tab('Settings', systemImage: 'gearshape.fill', value: 'settings')(
        NavigationStack(path: $this->settingsPath)($settingsView()),
    ),
)
    ->tabViewStyle(TabViewStyle::sidebarAdaptable)
    ->tint($accentBlue);
