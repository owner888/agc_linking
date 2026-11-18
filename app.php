<?php
declare(strict_types=1);

use MyPHP\{App, Device, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/boot.php';
require __DIR__ . '/src/Icons.php';
require __DIR__ . '/src/Store.php';
require __DIR__ . '/src/Profiles.php';
require __DIR__ . '/src/LinkingApp.php';

$app = new LinkingApp(store: Store::inUserData());

App::setName('Linking');
// What a person set is kept when the app goes to the background or quits
App::onDidResignActive(fn() => $app->save());
App::whenReady(function () use ($app) {
    [$w, $h] = Device::isDesktop() ? [1120, 740] : [402, 874];
    $app->window = new Window(new WindowOptions(
        title: 'Linking — Network & Diagnostic Toolkit',
        width: $w,
        height: $h,
        stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $app),
    ));
    $app->checkUpdate(false);
});
App::run();
