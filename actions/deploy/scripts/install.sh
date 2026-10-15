#!/usr/bin/env bash
# Downloads the runr CLI archive of a release, checks it against the release's checksums.txt
# and unpacks the binary into $RUNR_CLI_DIR.
set -euo pipefail

tag="${RUNR_CLI_TAG:?}"
os="${RUNR_CLI_OS:?}"
arch="${RUNR_CLI_ARCH:?}"
dir="${RUNR_CLI_DIR:?}"
base="${RUNR_CLI_BASE_URL:-https://github.com/runrsh/core/releases/download}"

ext=tar.gz
[ "$os" = windows ] && ext=zip
archive="runr_${os}_${arch}.${ext}"
url="${base%/}/${tag}"

work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT

echo "Downloading ${url}/${archive}"
curl -fsSL --retry 3 -o "$work/$archive" "${url}/${archive}"
curl -fsSL --retry 3 -o "$work/checksums.txt" "${url}/checksums.txt"

want="$(awk -v f="$archive" '$2 == f || $2 == "*" f {print $1}' "$work/checksums.txt")"
if [ -z "$want" ]; then
  echo "::error::$archive is not listed in checksums.txt of $tag"
  exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
  got="$(sha256sum "$work/$archive" | awk '{print $1}')"
else
  got="$(shasum -a 256 "$work/$archive" | awk '{print $1}')"
fi
if [ "$want" != "$got" ]; then
  echo "::error::checksum mismatch for $archive (want $want, got $got)"
  exit 1
fi

mkdir -p "$dir"
if [ "$ext" = zip ]; then
  if command -v unzip >/dev/null 2>&1; then
    unzip -o -q "$work/$archive" -d "$dir"
  else
    pwsh -NoProfile -Command "Expand-Archive -Force -Path '$work/$archive' -DestinationPath '$dir'"
  fi
else
  tar -xzf "$work/$archive" -C "$dir"
fi
chmod +x "$dir/runr" 2>/dev/null || true
"$dir/runr" version --client
