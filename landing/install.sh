#!/bin/sh
# Installs the runr CLI on Linux and macOS (amd64, arm64).
#
#   curl -fsSL https://runr.sh/install.sh | sh
#
# RUNR_VERSION=v0.6.0     install this release instead of the latest one
# RUNR_INSTALL_DIR=DIR    install into DIR (default: /usr/local/bin when writable, else ~/.local/bin)
# RUNR_DOWNLOAD_URL=URL   download base (a mirror of https://runr.sh/dl/cli)
#
# The archive is checked against the release's checksums.txt (SHA-256) before it is unpacked.
# Windows: download runr_windows_amd64.zip or runr_windows_arm64.zip from https://runr.sh/dl/.

set -eu

say() { printf '%s\n' "$*" >&2; }
die() { say "runr install: $*"; exit 1; }

fetch() { # fetch URL FILE
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 -o "$2" "$1"
  elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$2" "$1"
  else
    die "curl or wget is required"
  fi
}

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  elif command -v openssl >/dev/null 2>&1; then
    openssl dgst -sha256 "$1" | awk '{print $NF}'
  else
    die "sha256sum, shasum or openssl is required to check the download"
  fi
}

main() {
  base="${RUNR_DOWNLOAD_URL:-https://runr.sh/dl/cli}"
  base="${base%/}"

  case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=darwin ;;
    MINGW* | MSYS* | CYGWIN* | Windows*)
      die "on Windows download runr_windows_amd64.zip or runr_windows_arm64.zip from https://runr.sh/dl/" ;;
    *) die "unsupported OS $(uname -s) (Linux and macOS are supported)" ;;
  esac

  case "$(uname -m)" in
    x86_64 | amd64) arch=amd64 ;;
    aarch64 | arm64) arch=arm64 ;;
    *) die "unsupported architecture $(uname -m) (amd64 and arm64 are supported)" ;;
  esac

  tmp="$(mktemp -d 2>/dev/null || mktemp -d -t runr)"
  trap 'rm -rf "$tmp"' EXIT
  trap 'exit 130' INT TERM

  version="${RUNR_VERSION:-}"
  if [ -z "$version" ]; then
    fetch "$base/latest.txt" "$tmp/latest.txt" || die "could not read $base/latest.txt"
    version="$(tr -d ' \t\r\n' < "$tmp/latest.txt")"
  fi
  case "$version" in v*) ;; *) version="v$version" ;; esac
  if ! printf '%s\n' "$version" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
    die "invalid version '$version'"
  fi

  archive="runr_${os}_${arch}.tar.gz"
  url="$base/$version"
  say "Downloading runr $version for $os/$arch"
  fetch "$url/$archive" "$tmp/$archive" || die "download failed: $url/$archive"
  fetch "$url/checksums.txt" "$tmp/checksums.txt" || die "download failed: $url/checksums.txt"

  want="$(awk -v f="$archive" '$2 == f || $2 == "*" f {print $1}' "$tmp/checksums.txt")"
  [ -n "$want" ] || die "$archive is not listed in checksums.txt of $version"
  got="$(sha256 "$tmp/$archive")"
  [ "$want" = "$got" ] || die "checksum mismatch for $archive (want $want, got $got)"

  tar -xzf "$tmp/$archive" -C "$tmp" runr || die "could not unpack $archive"

  dir="${RUNR_INSTALL_DIR:-}"
  if [ -z "$dir" ]; then
    if [ -d /usr/local/bin ] && [ -w /usr/local/bin ]; then
      dir=/usr/local/bin
    else
      [ -n "${HOME:-}" ] || die "HOME is not set; set RUNR_INSTALL_DIR"
      dir="$HOME/.local/bin"
    fi
  fi
  mkdir -p "$dir" || die "cannot create $dir"
  cp "$tmp/runr" "$dir/runr.tmp.$$" || die "cannot write to $dir (set RUNR_INSTALL_DIR)"
  chmod 0755 "$dir/runr.tmp.$$"
  mv -f "$dir/runr.tmp.$$" "$dir/runr"

  say "Installed $dir/runr"
  "$dir/runr" version --client
  case ":${PATH:-}:" in
    *":$dir:"*) ;;
    *) say "Add $dir to your PATH: export PATH=\"$dir:\$PATH\"" ;;
  esac
}

main "$@"
