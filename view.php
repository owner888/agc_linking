<?php
// Linking declarative UI ($this is LinkingApp).
// Uses native MyPHP SwiftUI views: TabView + NavigationStack + ListView + Section + LabeledContent + Picker + swipeActions.
declare(strict_types=1);

$accentBlue = Color::rgb(0x2B65F1);
$okGreen = Color::rgb(0x16A34A);
$warnAmber = Color::rgb(0xD97706);
$errRed = Color::rgb(0xDC2626);

$updateShown = fn(): bool => $this->isInAppUpdateAllowed() && $this->updateVersion !== '' && in_array($this->updateStatus, ['available', 'downloading', 'installing', 'failed'], true);

$quickTargets = [
    ['host' => '1.1.1.1', 'mode' => 'Ping'],
    ['host' => '8.8.8.8', 'mode' => 'Ping'],
    ['host' => 'cloudflare.com', 'mode' => 'Ping'],
    ['host' => 'github.com:443', 'mode' => 'Tcping'],
];

$pingView = fn() => ListView()(
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
        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            Text('Host'),
            TextField('Host', text: $this->hostInput, prompt: 'IP or domain, host:port')
                ->multilineTextAlignment(TextAlignment::trailing)
                ->textInputAutocapitalization(TextInputAutocapitalization::never)
                ->autocorrectionDisabled()
                ->onSubmit(fn() => $this->startPing()),
        ),
        HStack(spacing: 0)(
            Button($this->isRunning ? 'Stop' : 'Start', action: fn() => $this->togglePing(), systemImage: $this->isRunning ? 'pause.fill' : 'play.fill')
                ->buttonStyle(ButtonStyle::borderedProminent)
                ->tint($this->isRunning ? $errRed : $accentBlue)
                ->frame(maxWidth: INF),
        )->padding(Edge::vertical, 4)->padding(Edge::horizontal, 16),
        Button('NSLookup', action: fn() => $this->runNslookup(), systemImage: 'magnifyingglass')
            ->buttonStyle(ButtonStyle::borderless),
    ),

    Section(header: 'Quick Targets')(
        Each($quickTargets, id: 'host')(fn(array $q) => Button(action: fn() => $this->probeFavorite($q['host'], $q['mode']), label: HStack(alignment: VerticalAlignment::center, spacing: 8)(
            Text(verbatim: $q['host']),
            Spacer(),
            Text(verbatim: $q['mode'])->font(Font::caption)->foregroundStyle(Color::secondary),
            Image(systemName: 'chevron.right')->font(Font::caption)->foregroundStyle(Color::secondary),
        ))->buttonStyle(ButtonStyle::plain)),
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
    Section(
        header: $this->vpnConnected ? 'Tunnel Connected' : 'Tunnel Ready',
        footer: $this->vpnConnected ? "Session Traffic: {$this->sessionTraffic}" : ''
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
        HStack(spacing: 0)(
            Button($this->vpnConnected ? 'Disconnect' : 'Connect Tunnel', action: fn() => $this->toggleVpn(), systemImage: $this->vpnConnected ? 'xmark.circle.fill' : 'network')
                ->buttonStyle(ButtonStyle::borderedProminent)
                ->tint($this->vpnConnected ? $okGreen : $accentBlue)
                ->frame(maxWidth: INF),
        )->padding(Edge::vertical, 4)->padding(Edge::horizontal, 16),
    ),

    Section(header: 'Import Profile or Subscription')(
        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            Text('Link'),
            TextField('Link', text: $this->nodeImportInput, prompt: 'vless://, trojan://, hy2://, https://')
                ->multilineTextAlignment(TextAlignment::trailing)
                ->textInputAutocapitalization(TextInputAutocapitalization::never)
                ->autocorrectionDisabled()
                ->onSubmit(fn() => $this->importNodeUri()),
        ),
        Button('Import Profile', action: fn() => $this->importNodeUri(), systemImage: 'square.and.arrow.down')
            ->buttonStyle(ButtonStyle::borderless),
        Button('Test All Latency', action: fn() => $this->testAllNodesLatency(), systemImage: 'clock')
            ->buttonStyle(ButtonStyle::borderless),
    ),

    Section(header: 'Outbound Proxy Nodes (' . count($this->proxyNodes) . ')')(
        Each($this->proxyNodes, id: 'id')(fn(array $node) => Button(action: fn() => $this->selectNode($node['id']), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
            VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Text(verbatim: $node['name'])->font(Font::subheadline)->lineLimit(1),
                Text(verbatim: "{$node['protocol']} · {$node['address']}:{$node['port']}")
                    ->font(Font::caption)
                    ->foregroundStyle(Color::secondary)
                    ->lineLimit(1),
            )->frame(maxWidth: INF, alignment: Alignment::leading),
            Text(verbatim: $node['latency'] . ' ms')
                ->font(Font::subheadline)
                ->foregroundStyle($node['latency'] < 80 ? $okGreen : $warnAmber)
                ->lineLimit(1)
                ->fixedSize(),
            Image(systemName: 'checkmark')
                ->foregroundStyle($accentBlue)
                ->opacity($this->activeNodeId === $node['id'] ? 1.0 : 0.0),
        ))->buttonStyle(ButtonStyle::plain)),
    ),
)
    ->navigationTitle('Network')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

$favoritesView = fn() => ListView()(
    Section(header: 'Saved Hosts (' . count($this->favorites) . ')', footer: $this->favorites === [] ? '' : 'Tap to ping. Swipe a row for Tcping, Httping or Delete.')(
        $this->favorites === []
            ? ContentUnavailableView('No Favorites Yet', systemImage: 'star.fill', description: 'Add frequently tested hosts or DNS servers below.')
            : Each($this->favorites, id: 'id')(fn(array $fav) => Button(action: fn() => $this->probeFavorite($fav['host'], 'Ping'), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: $fav['host'])->font(Font::subheadline)->lineLimit(1),
                    Text(verbatim: $fav['description'])
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary)
                        ->lineLimit(1),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Text(verbatim: $fav['tag'])->font(Font::caption)->foregroundStyle(Color::secondary)->lineLimit(1)->fixedSize(),
                Image(systemName: 'chevron.right')->font(Font::caption)->foregroundStyle(Color::secondary),
            ))->buttonStyle(ButtonStyle::plain)->swipeActions(
                Button('Tcping', action: fn() => $this->probeFavorite($fav['host'], 'Tcping')),
                Button('Httping', action: fn() => $this->probeFavorite($fav['host'], 'Httping')),
                Button('Delete', role: ButtonRole::destructive, action: fn() => $this->removeFavorite($fav['id'])),
            )),
    ),

    Section(header: 'New Favorite')(
        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            Text('Host'),
            TextField('Host', text: $this->newFavHost, prompt: 'IP or domain')
                ->multilineTextAlignment(TextAlignment::trailing)
                ->textInputAutocapitalization(TextInputAutocapitalization::never)
                ->autocorrectionDisabled(),
        ),
        HStack(alignment: VerticalAlignment::center, spacing: 12)(
            Text('Remark'),
            TextField('Remark', text: $this->newFavDesc, prompt: 'Optional')
                ->multilineTextAlignment(TextAlignment::trailing)
                ->onSubmit(fn() => $this->addFavorite()),
        ),
        Button('Add Host', action: fn() => $this->addFavorite(), systemImage: 'plus')
            ->buttonStyle(ButtonStyle::borderless),
    ),

    $this->history !== []
        ? Section(header: 'Recent History (' . count($this->history) . ')')(
            Each($this->history, id: 'id')(fn(array $h) => Button(action: fn() => $this->probeFavorite($h['host'], $h['mode']), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: $h['host'])->font(Font::subheadline)->lineLimit(1),
                    Text(verbatim: "{$h['mode']} · {$h['time']}")->font(Font::caption)->foregroundStyle(Color::secondary),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Image(systemName: 'arrow.clockwise')->font(Font::caption)->foregroundStyle(Color::secondary),
            ))->buttonStyle(ButtonStyle::plain)),
            Button('Clear History', role: ButtonRole::destructive, action: fn() => $this->clearHistory()),
        )
        : null,
)
    ->navigationTitle('Favorites')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large);

$settingsView = fn() => ListView()(
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
        LabeledContent('Version', 'v' . $this->fullVersionText()),
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

return function () use ($pingView, $networkView, $favoritesView, $settingsView, $updateShown, $accentBlue) {
    $tabs = TabView(selection: $this->tab)(
        Tab('Home', systemImage: 'house.fill', value: 'ping')(
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
    // A small capsule floating over the page just above the tab bar, as Telegram's
    return $tabs->bottomAccessory(fn() => $updateShown() ? UpdateCapsule(
        version: $this->updateVersion,
        status: $this->updateStatus,
        progress: $this->updateProgress,
        onAction: fn() => $this->performUpdate(),
        tint: $accentBlue,
        size: $this->updateAssetSize,
        store: $this->updateStore(),
    ) : null);
};
