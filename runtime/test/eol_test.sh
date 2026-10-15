#!/usr/bin/env bash
# Tests `ci.sh eol` without network: curl is a shell function that answers like GHCR (token
# service, paginated tags/list, the GitHub Packages API) and endoflife.date, and records every
# URL and header it is asked for. The EOL report reads the registry the exec-* images are pushed
# to (ghcr.io/runrsh), never Docker Hub.
#
#   bash runtime/test/eol_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/eol.sh
source "$here/lib/eol.sh"

REGISTRY="ghcr.io/runrsh"
RUNTIME_DEFS=(
  "python|library/python|-slim|3.10|major.minor"
  "go|library/golang|-alpine|1.22|major.minor"
)
urls="$tmp/urls"
sent="$tmp/headers"
: > "$urls"
: > "$sent"

curl() {
  local url="" headers="" method="GET"
  while [ $# -gt 0 ]; do
    case "$1" in
      -D) headers="$2"; shift ;;
      -H) echo "$2" >> "$sent"; shift ;;
      -X) method="$2"; shift ;;
      -o|-w|-u|-d) shift ;;
      http*) url="$1" ;;
    esac
    shift
  done
  echo "${method} ${url}" >> "$urls"

  case "${method} ${url}" in
    "GET https://ghcr.io/v2/")
      [ "$headers" = "-" ] && printf 'HTTP/2 401\r\nwww-authenticate: Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:user/image:pull"\r\n\r\n'
      return 0 ;;
    "GET https://ghcr.io/token?"*)
      echo '{"token":"anon-token"}' ;;
    "GET https://ghcr.io/v2/runrsh/exec-python/tags/list?n=1000")
      printf 'HTTP/2 200\r\nlink: </v2/runrsh/exec-python/tags/list?last=3.12&n=1000>; rel="next"\r\n\r\n' > "$headers"
      echo '{"name":"runrsh/exec-python","tags":["3.8","3.12","latest"]}' ;;
    "GET https://ghcr.io/v2/runrsh/exec-python/tags/list?last=3.12&n=1000")
      printf 'HTTP/2 200\r\n\r\n' > "$headers"
      echo '{"name":"runrsh/exec-python","tags":["3.13"]}' ;;
    "GET https://ghcr.io/v2/runrsh/exec-go/tags/list?n=1000")
      printf 'HTTP/2 200\r\n\r\n' > "$headers"
      echo '{"name":"runrsh/exec-go","tags":["1.22"]}' ;;
    "GET https://endoflife.date/api/python/3.8.json")
      echo '{"cycle":"3.8","eol":"2024-10-07"}' ;;
    "GET https://endoflife.date/api/python/3.12.json" | "GET https://endoflife.date/api/python/3.13.json")
      echo '{"eol":"2099-10-31"}' ;;
    "GET https://endoflife.date/api/go/1.22.json")
      echo '{"cycle":"1.22","eol":"2025-02-11"}' ;;
    "GET https://api.github.com/orgs/runrsh/packages/container/exec-python/versions?per_page=100&page=1")
      echo '[{"id":11,"metadata":{"container":{"tags":["3.8"]}}},{"id":12,"metadata":{"container":{"tags":["3.12","latest"]}}}]' ;;
    "GET https://api.github.com/orgs/runrsh/packages/container/exec-go/versions?per_page=100&page=1")
      echo '[{"id":21,"metadata":{"container":{"tags":["1.22","1.22.5"]}}}]' ;;
    "GET https://api.github.com/orgs/runrsh/packages/container/"*"&page=2")
      echo '[]' ;;
    "DELETE https://api.github.com/orgs/runrsh/packages/container/exec-python/versions/11")
      echo '204' ;;
    *)
      return 22 ;;
  esac
}

# --- report --------------------------------------------------------------------------------
out=$(cmd_eol 2>&1) || fail "cmd_eol failed: $out"

grep -q 'hub.docker.com' "$urls" && fail "the report queried Docker Hub"
grep -qx 'GET https://ghcr.io/v2/runrsh/exec-python/tags/list?n=1000' "$urls" || fail "exec-python tags not read from GHCR"
grep -qx 'GET https://ghcr.io/v2/runrsh/exec-python/tags/list?last=3.12&n=1000' "$urls" || fail "the next tags page was not followed"
grep -qx 'GET https://ghcr.io/v2/runrsh/exec-go/tags/list?n=1000' "$urls" || fail "exec-go tags not read from GHCR"
grep -q 'scope=repository:runrsh/exec-python:pull' "$urls" || fail "no pull token requested for exec-python"
grep -qx 'Authorization: Bearer anon-token' "$sent" || fail "tags/list was not sent the registry token"

echo "$out" | grep -q 'ghcr.io/runrsh/exec-python' || fail "report does not name the GHCR repository"
echo "$out" | grep -E '^  3\.8 ' | grep -q 'EOL' || fail "3.8 not reported EOL"
echo "$out" | grep -E '^  3\.13 ' | grep -q 'supported' || fail "3.13 (second page) not reported"
echo "$out" | grep -q 'latest' && fail "non-version tag reported"
echo "$out" | grep -q 'Supported: 2' || fail "supported count: $out"
echo "$out" | grep -q 'EOL: 2' || fail "EOL count: $out"

# --- prune ---------------------------------------------------------------------------------
if command -v jq >/dev/null 2>&1; then
  : > "$urls"
  out=$(GHCR_TOKEN=test-token cmd_eol --prune --yes 2>&1) || fail "prune failed: $out"

  grep -qx 'DELETE https://api.github.com/orgs/runrsh/packages/container/exec-python/versions/11' "$urls" || fail "EOL version 3.8 not deleted"
  grep -q 'DELETE .*/versions/12' "$urls" && fail "a supported version was deleted"
  grep -q 'DELETE .*/exec-go/' "$urls" && fail "a version that carries a non-EOL tag was deleted"
  echo "$out" | grep -q 'kept (the image is also tagged 1.22.5)' || fail "kept version not reported: $out"
  grep -qx 'Authorization: Bearer test-token' "$sent" || fail "GitHub API not called with GHCR_TOKEN"
else
  echo "skip: prune (jq not installed)"
fi

# --- prune needs GHCR -------------------------------------------------------------------------
REGISTRY="registry.example.com/runr"
out=$( (GHCR_TOKEN=x prune_eol_tags 1 "exec-python:3.8") 2>&1) && fail "prune outside GHCR did not fail"
echo "$out" | grep -q 'supports GHCR' || fail "prune outside GHCR: $out"

echo "ok: eol"
