#!/bin/bash
# Fetches the routing data Xray's Rule mode needs into resources/geo/ (not in git; CI runs this before the APK build);
# the APK carries them as assets/geo/, and the service puts them where Xray reads them.
set -euo pipefail
DIR="$(cd "$(dirname "$0")/.." && pwd)/resources/geo"
mkdir -p "$DIR"
curl -fsSL -o "$DIR/geoip.dat" https://github.com/v2fly/geoip/releases/latest/download/geoip.dat
curl -fsSL -o "$DIR/geosite.dat" https://github.com/v2fly/domain-list-community/releases/latest/download/dlc.dat
ls -l "$DIR"
