# Linking (agc_linking) 项目约定

## 发版与版本号

- Tag 格式 `v<版本>+<build>`，例如 `v2.1.4+12`；CI 从 Tag 读出版本号和 build 号写入 `myphp.json`，不用手改版本文件。
- 开发者没有特别说明时，一律只递增 build 号（`2.1.x` 的 patch 不动，除非明确说「升小版本」），不是每次都升 patch。
- 当前最新 Tag 以 `git ls-remote --tags origin` 为准，build 号在此基础上加 1。

## 签名与装机

- 线上正式包（GitHub Release 的 `Linking.apk`）之间证书相同，可直接 `adb install -r` 覆盖，不必卸载。
- 实测 `myphp run` 本地构建的包证书与线上正式包不同（SHA-256 不一致），二者互相覆盖会因签名冲突被系统拒绝。要在手机上测更新流程，应装线上正式包，不要用 `myphp run` 的包。
- 装包前先用 `apksigner verify --print-certs` 比对证书，再决定要不要卸载。
- 本机没有正式签名密钥（在 CI 的 secrets 里），所以 `myphp run` 的包无法覆盖线上正式包。要在手机上看 UI，临时把 `myphp.json` 的 `identifier` 改成 `easy.net.tool.dev` 再 `myphp run -d android`，它会与正式包并存（不卸载、不丢数据），看完把 `myphp.json` 还原。

## 测应用内更新

- 更新流程（下载、授权、安装）由手机上**已安装**的版本执行，所以修了更新代码后，要先装带修复的版本，再发一个更高的版本去升级它，才能测到修复。
- Android 上状态在定时器里变化（下载进度、检查更新结束）时，要调 `$this->window?->invalidate()` 请求重绘，否则要等下一次触摸才刷新。
