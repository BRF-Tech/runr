#!/usr/bin/env bash
# Resolves the runr CLI release tag, OS and architecture of the runner and the install
# directory, and writes them to $GITHUB_OUTPUT (tag, os, arch, dir). "latest" is the tag in
# $RUNR_CLI_BASE_URL/latest.txt.
set -euo pipefail

version="${RUNR_CLI_VERSION:-latest}"

case "${RUNNER_OS:-$(uname -s)}" in
  Linux) os=linux ;;
  macOS | Darwin) os=darwin ;;
  Windows | MINGW* | MSYS* | CYGWIN*) os=windows ;;
  *) echo "::error::unsupported runner OS ${RUNNER_OS:-$(uname -s)}"; exit 1 ;;
esac

case "${RUNNER_ARCH:-$(uname -m)}" in
  X64 | x86_64 | amd64) arch=amd64 ;;
  ARM64 | arm64 | aarch64) arch=arm64 ;;
  *) echo "::error::unsupported runner architecture ${RUNNER_ARCH:-$(uname -m)}"; exit 1 ;;
esac

if [ "$version" = "latest" ]; then
  base="${RUNR_CLI_BASE_URL:-https://runr.sh/dl/cli}"
  latest="${base%/}/latest.txt"
  tag="$(curl -fsSL --retry 3 --max-filesize 256 "$latest" 2>/dev/null | tr -d '[:space:]' || true)"
  if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "::error::could not look up the latest runr release ($latest holds no release tag); set the version input"
    exit 1
  fi
else
  tag="$version"
  case "$tag" in v*) ;; *) tag="v$tag" ;; esac
fi

base="${RUNNER_TOOL_CACHE:-${HOME}/.cache}"
dir="${base}/runr-cli/${tag}/${os}-${arch}"

{
  echo "tag=$tag"
  echo "os=$os"
  echo "arch=$arch"
  echo "dir=$dir"
} >> "$GITHUB_OUTPUT"
echo "runr CLI $tag for $os/$arch"
