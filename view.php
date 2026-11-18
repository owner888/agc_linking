<?php
// Linking declarative UI ($this is LinkingApp): the screens of agc_raven in native views.
// What its drawer holds are tabs of one TabView, as SwiftUI's: the bar of a phone has the first four and More, which
// lists the others; a sidebar (a tablet on its side, the desktop) has them all, under their sections.
declare(strict_types=1);

$accentBlue = Color::rgb(0x2B65F1);
$okGreen = Color::rgb(0x16A34A);
$warnAmber = Color::rgb(0xD97706);
$errRed = Color::rgb(0xDC2626);
$ringIdle = Color::rgb(0x5E5CE6);
$upGreen = Color::rgb(0x4CAF50);
$downRed = Color::rgb(0xF28B82);

$updateShown = fn(): bool => $this->isInAppUpdateAllowed() && $this->updateVersion !== '' && in_array($this->updateStatus, ['available', 'downloading', 'installing', 'failed'], true);

// ---------------------------------------------------------------------------------------------------------------
// Pieces
// ---------------------------------------------------------------------------------------------------------------

$icon = fn(string $name, float $size = 22, $color = null) => Image(Icons::get($name))
    ->frame(width: $size, height: $size)
    ->foregroundStyle($color ?? Color::label);

/** A button of the navigation bar: its icon alone. */
$barButton = fn(string $name, Closure $action, string $label) => Button(action: $action, label: $icon($name, 22))->accessibilityLabel($label);

/** A page with a title in its navigation bar and its actions at the end of it. */
$bar = fn(View $page, string $title, array $actions = []) => $page
    ->navigationTitle($title)
    ->navigationBarTitleDisplayMode(TitleDisplayMode::inline)
    ->toolbar(...$actions);

/** What is measured, written as 'ms' where it is. */
$latencyColor = fn(?int $ms) => $ms === null ? Color::secondaryLabel : ($ms < 0 ? $errRed : ($ms < 200 ? $okGreen : $warnAmber));

// ---------------------------------------------------------------------------------------------------------------
// Home: Network (the connection)
// ---------------------------------------------------------------------------------------------------------------

$profilePicker = function () use ($icon): mixed {
    $active = $this->activeProfile();
    $title = $active['name'] ?? 'No profile';
    $items = [];
    foreach ($this->profiles as $p) {
        $items[] = Button(($p['id'] === $this->activeProfileId ? '✓ ' : '') . $p['name'], action: fn() => $this->selectProfile($p['id']));
    }
    $items[] = Divider();
    $items[] = Button('Add Profile', action: fn() => $this->openAddProfile(), systemImage: 'plus');
    if ($active !== null) {
        $items[] = Button('Remove “' . $active['name'] . '”', role: ButtonRole::destructive, action: fn() => $this->removeProfileAsked = true);
    }
    return HStack(spacing: 0)(
        Menu($title)(...$items),
    )->padding(Edge::horizontal, 16)->padding(Edge::top, 4);
};

$connectionButton = function () use ($ringIdle, $okGreen, $warnAmber): mixed {
    $connected = $this->connection === 'connected';
    $ring = $connected ? $okGreen : ($this->connection === 'connecting' ? $warnAmber : $ringIdle);
    return ZStack()(
        RadialGradient([$ring->opacity($connected ? 0.28 : 0.16), $ring->opacity(0.0)], UnitPoint::center, 0, 120)->frame(width: 240, height: 240),
        Circle()->stroke($ring, 1.5)->frame(width: 148, height: 148),
        Image(Icons::bird($connected))->resizable()->scaledToFit()->frame(width: 76, height: 63),
    )->frame(width: 240, height: 240)->onTapGesture(fn() => $this->toggleConnection());
};

$networkScreen = fn() => VStack(spacing: 0)(
    $profilePicker(),
    Spacer(),
    VStack(spacing: 14)(
        $connectionButton(),
        Text($this->connectionLabel())->font(Font::title3),
        HStack(spacing: 18)(
            Text('↑ ' . $this->upSpeed)->font(Font::subheadline)->foregroundStyle($upGreen),
            Text('↓ ' . $this->downSpeed)->font(Font::subheadline)->foregroundStyle($downRed),
        )->frame(maxWidth: INF),
    )->frame(maxWidth: INF),
    Spacer(),
    Spacer(),
);

$networkPage = fn() => $bar($networkScreen(), '', [
    $barButton('globe', fn() => Shell::openExternal('https://ipinfo.io/'), 'IP check'),
    $barButton('sliders', fn() => $this->quickSettingsOpen = true, 'Quick settings'),
    $barButton('plus', fn() => $this->openAddProfile(), 'Add profile'),
]);

// ---------------------------------------------------------------------------------------------------------------
// Home: Ping, Httping, Tcping
// ---------------------------------------------------------------------------------------------------------------

$quickTargets = [
    ['host' => '1.1.1.1', 'mode' => 'Ping'],
    ['host' => '8.8.8.8', 'mode' => 'Ping'],
    ['host' => 'cloudflare.com', 'mode' => 'Ping'],
    ['host' => 'github.com:443', 'mode' => 'Tcping'],
];

$pingList = fn() => ListView()(
    Section(
        header: 'Diagnostic Target',
        footer: "TTL {$this->ttl} · Timeout {$this->timeOut}s · {$this->packetSize}B · {$this->ipLibrary}"
    )(
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
);

$pingPage = fn(string $mode) => $bar($pingList(), $mode, [
    $barButton('star', fn() => $this->favoritesOpen = true, 'Favorites'),
]);

// ---------------------------------------------------------------------------------------------------------------
// Home: Config Options, Log, Setting
// ---------------------------------------------------------------------------------------------------------------

$configScreen = fn() => ListView()(
        Section(header: 'Connection')(
            Picker('Routing Mode', selection: $this->routingMode)(
                Text('Rule')->tag('Rule'),
                Text('Global')->tag('Global'),
                Text('Direct')->tag('Direct'),
            )->pickerStyle(PickerStyle::segmented),
            Toggle('Enable TUN Mode', isOn: $this->tunEnabled),
            Toggle('IPv6 Route', isOn: $this->ipv6Enabled),
            LabeledContent('Mixed Port', (string) $this->mixedPort),
        ),
        Section(header: 'Ping & Probe')(
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
        ),
        Section()(
            Button('Reset to Default', role: ButtonRole::destructive, action: fn() => $this->resetConfig()),
        ),
    );

$configScreenPage = fn() => $bar($configScreen(), 'Config Options');

$logsScreen = fn() => ListView()(
        Section(header: 'Runtime Logs (' . count($this->logs) . ')')(
            $this->logs === []
                ? Text('Nothing logged yet.')->font(Font::caption)->foregroundStyle(Color::secondary)
                : Each($this->logs, id: 'id')(fn(array $log) => VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: "[{$log['level']}] {$log['time']}")
                        ->font(Font::caption2)
                        ->foregroundStyle($log['level'] === 'ERROR' ? $errRed : ($log['level'] === 'WARNING' ? $warnAmber : $accentBlue)),
                    Text(verbatim: $log['message'])
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary),
                )),
            $this->logs !== []
                ? Button('Clear Logs', role: ButtonRole::destructive, action: fn() => $this->clearLogs())
                : null,
        ),
    );

$logsScreenPage = fn() => $bar($logsScreen(), 'Log');

$settingsScreen = fn() => ListView()(
        Section(
            header: 'Version & Updates',
            footer: "Package: {$this->packageName} · Channel: " . strtoupper($this->distributionChannel())
        )(
            LabeledContent('Version', 'v' . $this->fullVersionText()),
            $this->isInAppUpdateAllowed()
                ? Button('Check for Updates', action: fn() => $this->checkUpdate(true))
                : Button('Rate on Google Play', action: fn() => $this->checkUpdate(true)),
        ),
        Section()(
            Button('Contact Us', action: fn() => Shell::openExternal('https://t.me/agcplayer'), systemImage: 'paperplane')
                ->buttonStyle(ButtonStyle::borderless),
        ),
        Section(header: 'Profiles (' . count($this->profiles) . ')')(
            $this->profiles === []
                ? Button('Add Profile', action: fn() => $this->openAddProfile(), systemImage: 'plus')
                : Each($this->profiles, id: 'id')(fn(array $p) => Button(action: fn() => $this->selectProfile($p['id']), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                        Text(verbatim: $p['name'])->font(Font::subheadline)->lineLimit(1),
                        Text(verbatim: count($p['nodes']) . ($p['kind'] === 'subscription' ? ' proxies · subscription' : ' proxy · link'))
                            ->font(Font::caption)->foregroundStyle(Color::secondary),
                    )->frame(maxWidth: INF, alignment: Alignment::leading),
                    $p['id'] === $this->activeProfileId ? Image(Icons::get('chevron.right'))->frame(width: 16, height: 16)->foregroundStyle($accentBlue) : null,
                ))->buttonStyle(ButtonStyle::plain)),
        ),
    );

$settingsScreenPage = fn() => $bar($settingsScreen(), 'Setting');



// ---------------------------------------------------------------------------------------------------------------
// Proxies
// ---------------------------------------------------------------------------------------------------------------

$proxiesScreen = fn() => ListView()(
    $this->nodes() === []
        ? Section()(
            ContentUnavailableView(
                'No Proxies',
                systemImage: 'star.fill',
                description: $this->activeProfile() === null
                    ? 'Add a profile on the Home tab to see its proxies here.'
                    : 'This profile has no proxies.'
            ),
            $this->activeProfile() === null
                ? Button('Add Profile', action: fn() => $this->openAddProfile(), systemImage: 'plus')->buttonStyle(ButtonStyle::borderless)
                : null,
        )
        : Section(
            header: ($this->activeProfile()['name'] ?? '') . ' (' . count($this->nodes()) . ')',
            footer: $this->testingLatency ? 'Measuring the latency…' : 'Tap a proxy to use it.'
        )(
            Each($this->nodes(), id: 'id')(fn(array $node) => Button(action: fn() => $this->selectNode($node['id']), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                    Text(verbatim: $node['name'])->font(Font::subheadline)->lineLimit(1),
                    Text(verbatim: "{$node['protocol']} · {$node['address']}:{$node['port']}")
                        ->font(Font::caption)
                        ->foregroundStyle(Color::secondary)
                        ->lineLimit(1),
                )->frame(maxWidth: INF, alignment: Alignment::leading),
                Text(verbatim: $this->latencyText($node['latency']))
                    ->font(Font::subheadline)
                    ->foregroundStyle($latencyColor($node['latency']))
                    ->lineLimit(1)
                    ->fixedSize(),
                Image(systemName: 'checkmark')
                    ->foregroundStyle($accentBlue)
                    ->opacity($this->selectedNodeId === $node['id'] ? 1.0 : 0.0),
            ))->buttonStyle(ButtonStyle::plain)),
        ),
)
    ->navigationTitle('Proxies')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large)
    ->toolbar(
        Menu('Sort')(
            Button(($this->proxiesSort === 'default' ? '✓ ' : '') . 'Default', action: function () { $this->proxiesSort = 'default'; }),
            Button(($this->proxiesSort === 'latency' ? '✓ ' : '') . 'Latency', action: function () { $this->proxiesSort = 'latency'; }),
            Button(($this->proxiesSort === 'name' ? '✓ ' : '') . 'Name', action: function () { $this->proxiesSort = 'name'; }),
        ),
        Button('Test latency', action: fn() => $this->testLatency(), systemImage: 'clock')->disabled($this->testingLatency || $this->nodes() === []),
    );

// ---------------------------------------------------------------------------------------------------------------
// Sheets
// ---------------------------------------------------------------------------------------------------------------

$addProfileSheet = fn() => NavigationStack()(
    ListView()(
        Section(
            header: 'Link or subscription',
            footer: $this->profileError !== '' ? $this->profileError : 'A link of a proxy (vless://, vmess://, trojan://, ss://, hy2://, tuic://), or the address of a subscription.'
        )(
            TextField('Link', text: $this->profileInput, prompt: 'vless://… or https://…')
                ->textInputAutocapitalization(TextInputAutocapitalization::never)
                ->autocorrectionDisabled()
                ->onSubmit(fn() => $this->addProfile()),
        ),
        Section()(
            Button($this->profileBusy ? 'Fetching…' : 'Add', action: fn() => $this->addProfile(), systemImage: 'plus')
                ->disabled($this->profileBusy || trim($this->profileInput) === ''),
        ),
    )
        ->navigationTitle('Add Profile')
        ->navigationBarTitleDisplayMode(TitleDisplayMode::inline),
)->presentationDetents([PresentationDetent::large()]); // a field in it: the keyboard takes the bottom, the sheet is high enough to keep it in view

$quickSettingsSheet = fn() => NavigationStack()(
    ListView()(
        Section(header: 'Connection')(
            Picker('Routing Mode', selection: $this->routingMode)(
                Text('Rule')->tag('Rule'),
                Text('Global')->tag('Global'),
                Text('Direct')->tag('Direct'),
            )->pickerStyle(PickerStyle::segmented),
            Toggle('Enable TUN Mode', isOn: $this->tunEnabled),
            Toggle('IPv6 Route', isOn: $this->ipv6Enabled),
        ),
    )
        ->navigationTitle('Quick Settings')
        ->navigationBarTitleDisplayMode(TitleDisplayMode::inline),
)->presentationDetents([PresentationDetent::medium()]);

$favoritesSheet = fn() => NavigationStack()(
    ListView()(
        Section(header: 'Saved Hosts (' . count($this->favorites) . ')', footer: $this->favorites === [] ? '' : 'Tap to ping. Swipe a row for Tcping, Httping or Delete.')(
            $this->favorites === []
                ? ContentUnavailableView('No Favorites Yet', systemImage: 'star.fill', description: 'Add frequently tested hosts or DNS servers below.')
                : Each($this->favorites, id: 'id')(fn(array $fav) => Button(action: fn() => $this->probeFavorite($fav['host'], $this->toolType), label: HStack(alignment: VerticalAlignment::center, spacing: 10)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                        Text(verbatim: $fav['host'])->font(Font::subheadline)->lineLimit(1),
                        Text(verbatim: $fav['description'])->font(Font::caption)->foregroundStyle(Color::secondary)->lineLimit(1),
                    )->frame(maxWidth: INF, alignment: Alignment::leading),
                    Text(verbatim: $fav['tag'])->font(Font::caption)->foregroundStyle(Color::secondary)->lineLimit(1)->fixedSize(),
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
        ->navigationBarTitleDisplayMode(TitleDisplayMode::inline),
)->presentationDetents([PresentationDetent::large()]);

// ---------------------------------------------------------------------------------------------------------------
// The app
// ---------------------------------------------------------------------------------------------------------------

return function () use ($networkPage, $pingPage, $configScreenPage, $logsScreenPage, $settingsScreenPage, $proxiesScreen, $addProfileSheet, $quickSettingsSheet, $favoritesSheet, $updateShown, $accentBlue) {
    // A tool's tab is its mode: another tab of one stops what ran in the one before
    $mode = LinkingApp::toolOfTab($this->tab);
    if ($mode !== null && $mode !== $this->toolType) {
        $this->setToolType($mode);
    }
    $tabs = TabView(selection: $this->tab)(
        Tab('Home', image: Icons::get('power'), value: 'home')(
            NavigationStack(path: $this->homePath)($networkPage()),
        ),
        Tab('Proxies', image: Icons::get('filter'), value: 'proxies')(
            NavigationStack(path: $this->proxiesPath)($proxiesScreen()),
        ),
        TabSection('Tools')(
            Tab('Ping', image: Icons::get('pulse'), value: 'ping')(NavigationStack()($pingPage('Ping'))),
            Tab('Httping', image: Icons::get('globe'), value: 'httping')(NavigationStack()($pingPage('Httping'))),
            Tab('Tcping', image: Icons::get('plug'), value: 'tcping')(NavigationStack()($pingPage('Tcping'))),
        ),
        TabSection('Settings')(
            Tab('Config Options', image: Icons::get('sliders'), value: 'config')(NavigationStack()($configScreenPage())),
            Tab('Log', image: Icons::get('doc'), value: 'logs')(NavigationStack()($logsScreenPage())),
            Tab('Setting', image: Icons::get('gear'), value: 'settings')(NavigationStack()($settingsScreenPage())),
        ),
    )
        ->tabViewStyle(TabViewStyle::sidebarAdaptable)
        ->tint($accentBlue)
        // A small capsule floating over the page just above the tab bar, as Telegram's
        ->bottomAccessory(fn() => $updateShown() ? UpdateCapsule(
            version: $this->updateVersion,
            status: $this->updateStatus,
            progress: $this->updateProgress,
            onAction: fn() => $this->performUpdate(),
            tint: $accentBlue,
            size: $this->updateAssetSize,
            store: $this->updateStore(),
        ) : null);

    return $tabs
        ->sheet($this->addProfileOpen, content: $addProfileSheet)
        ->sheet($this->quickSettingsOpen, content: $quickSettingsSheet)
        ->sheet($this->favoritesOpen, content: $favoritesSheet)
        ->alert('Not available yet', $this->engineAlertOpen, [Button('OK')], 'The connection engine is not part of this build, so no connection is made.')
        ->confirmationDialog('Remove this profile?', $this->removeProfileAsked, [
            Button('Remove', role: ButtonRole::destructive, action: fn() => $this->removeActiveProfile()),
            Button('Cancel', role: ButtonRole::cancel),
        ]);
};
