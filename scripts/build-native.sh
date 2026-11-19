#!/bin/bash
# Builds the VPN's native libraries into android/jniLibs/<abi>/ (not in git; CI runs this before the APK build):
#   libxray.so               Xray, run as a child process (CGO off on arm64; arm needs cgo, so the NDK's clang)
#   libhev-socks5-tunnel.so  hev-socks5-tunnel, loaded by easy.net.tool.vpn.Tun2Socks (its JNI class name is set here)
# Needs: git, go, the Android NDK ($ANDROID_NDK, $ANDROID_NDK_HOME or the SDK's ndk/ folder).
set -euo pipefail

XRAY_VERSION="v26.11.9"
HEV_VERSION="2.20.0"
ABIS="${ABIS:-arm64-v8a armeabi-v7a}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/android/jniLibs"
WORK="${NATIVE_WORK:-${ROOT}/.native}"

NDK="${ANDROID_NDK:-${ANDROID_NDK_HOME:-}}"
if [ -z "$NDK" ]; then
    for sdk in "${ANDROID_HOME:-}" "${ANDROID_SDK_ROOT:-}" "$HOME/Library/Android/sdk" "$HOME/Android/Sdk"; do
        [ -n "$sdk" ] && [ -d "$sdk/ndk" ] && NDK="$(ls -1d "$sdk/ndk/"* | sort -V | tail -n 1)" && break
    done
fi
[ -d "${NDK:-}" ] || { echo "Android NDK not found: set ANDROID_NDK" >&2; exit 1; }
BIN="$(ls -1d "$NDK/toolchains/llvm/prebuilt/"*/bin | head -n 1)"
echo "==> NDK: $NDK"

mkdir -p "$WORK"
fetch() { # repo tag dir
    if [ ! -d "$WORK/$3/.git" ]; then
        git clone --quiet --recursive --depth 1 --branch "$2" "$1" "$WORK/$3"
    fi
}

# --- hev-socks5-tunnel (JNI class easy.net.tool.vpn.Tun2Socks) ---
fetch https://github.com/heiher/hev-socks5-tunnel.git "$HEV_VERSION" hev
"$NDK/ndk-build" -C "$WORK/hev" NDK_PROJECT_PATH=. APP_BUILD_SCRIPT=Android.mk NDK_APPLICATION_MK=Application.mk \
    APP_ABI="$ABIS" APP_CFLAGS="-O3 -DPKGNAME=easy/net/tool/vpn -DCLSNAME=Tun2Socks" \
    NDK_OUT="$WORK/hev-obj" NDK_LIBS_OUT="$WORK/hev-libs" -j"$(getconf _NPROCESSORS_ONLN)" >/dev/null

# --- Xray ---
fetch https://github.com/XTLS/Xray-core.git "$XRAY_VERSION" xray
LDFLAGS="-s -w -buildid= -checklinkname=0"   # -checklinkname: a dependency (anet) links net.zoneCache
for abi in $ABIS; do
    mkdir -p "$OUT/$abi"
    case "$abi" in
        arm64-v8a)
            (cd "$WORK/xray" && CGO_ENABLED=0 GOOS=android GOARCH=arm64 go build -trimpath -ldflags "$LDFLAGS" -o "$OUT/$abi/libxray.so" ./main) ;;
        armeabi-v7a)
            (cd "$WORK/xray" && CGO_ENABLED=1 CC="$BIN/armv7a-linux-androideabi31-clang" GOOS=android GOARCH=arm GOARM=7 \
                go build -trimpath -ldflags "$LDFLAGS -extldflags=-Wl,-z,max-page-size=16384" -o "$OUT/$abi/libxray.so" ./main) ;;
        *) echo "unknown ABI $abi" >&2; exit 1 ;;
    esac
    cp "$WORK/hev-libs/$abi/libhev-socks5-tunnel.so" "$OUT/$abi/"
    echo "==> $abi:"; ls -l "$OUT/$abi"
done
