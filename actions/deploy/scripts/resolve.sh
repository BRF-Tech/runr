#!/usr/bin/env bash
# Resolves the runr CLI release tag, OS and architecture of the runner and the install
# directory, and writes them to $GITHUB_OUTPUT (tag, os, arch, dir).
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
  auth=()
  if [ -n "${GH_TOKEN:-}" ]; then
    auth=(-H "Authorization: Bearer ${GH_TOKEN}")
  fi
  api="${GITHUB_API_URL:-https://api.github.com}/repos/brf-tech/runr/releases/latest"
  tag="$(curl -fsSL ${auth[@]+"${auth[@]}"} -H "Accept: application/vnd.github+json" "$api" |
    sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
  if [ -z "$tag" ]; then
    echo "::error::could not look up the latest runr release ($api); set the version input"
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
