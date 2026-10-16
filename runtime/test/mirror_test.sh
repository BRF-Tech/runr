#!/usr/bin/env bash
# Tests `ci.sh mirror-reqs` without a registry: crane is a shell function that answers like Docker
# Hub (digests by HEAD, copies refused with 429 TOOMANYREQUESTS for the images listed in $limited)
# and like the pull-through mirrors of REQS_UPSTREAMS (each holds what $tmp/mirrors lists), and
# records what lands in the target registry. A mirror's copy is taken only by Docker Hub's digest.
#
#   bash runtime/test/mirror_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/mirror.sh
source "$here/lib/mirror.sh"
repository_images=("${REQS_IMAGES[@]}")

REGISTRY="registry.test/brf-tech"
log="$tmp/crane.log"
hub="$tmp/hub"            # <docker hub path> <digest>
limited="$tmp/limited"    # Docker Hub paths whose copy answers 429
mirrors="$tmp/mirrors"    # <mirror>/<repository>@<digest> a mirror holds
registry="$tmp/registry"  # <target ref> <digest> of every copy
hub_down=0

cat > "$hub" <<'EOF'
library/alpine:3.21 sha256:a1
tonistiigi/binfmt:latest sha256:b1
library/node:22-bookworm-slim sha256:c1
grafana/tempo:3.1.0 sha256:d1
EOF

crane() {
  echo "crane $*" >> "$log"
  local ref path digest
  case "$1" in
    digest)
      ref="$2"
      case "$ref" in
        docker.io/*)
          [ "$hub_down" = "0" ] || { echo "Error: HEAD https://index.docker.io/v2/: 503 Service Unavailable" >&2; return 1; }
          path="${ref#docker.io/}"
          awk -v p="$path" '$1 == p { print $2; found = 1 } END { exit !found }' "$hub" ;;
        *)
          awk -v r="$ref" '$1 == r { d = $2 } END { if (d == "") exit 1; print d }' "$registry" ;;
      esac ;;
    copy)
      ref="$2"
      case "$ref" in
        docker.io/*)
          path="${ref#docker.io/}"
          if grep -qxF "$path" "$limited"; then
            echo "Error: GET https://index.docker.io/v2/${path%%:*}/manifests/${path#*:}: TOOMANYREQUESTS: You have reached your unauthenticated pull rate limit." >&2
            return 1
          fi
          digest=$(awk -v p="${path%@*}" '$1 == p { print $2 }' "$hub")
          [ -n "$digest" ] || digest="${path##*@}"
          echo "$3 ${digest}" >> "$registry" ;;
        *@sha256:*)
          if ! grep -qxF "$ref" "$mirrors"; then
            # crane's form: the reference, the request, then the registry's code and message
            echo "Error: fetching \"${ref}\": GET https://${ref%%/*}/v2/${ref#*/}/manifests/${ref##*@}: MANIFEST_UNKNOWN: manifest unknown; unknown tag=${ref##*@}" >&2
            return 1
          fi
          echo "$3 ${ref##*@}" >> "$registry" ;;
        *) echo "Error: copied by tag from a mirror: $ref" >&2; return 1 ;;
      esac ;;
    *) return 1 ;;
  esac
}

REQS_IMAGES=(
  "alpine:3.21|alpine:3.21"
  "binfmt:latest|tonistiigi/binfmt:latest"
  "node:22-bookworm-slim|node:22-bookworm-slim"
  "rust:1.95.0-slim-bookworm|rust:1.95.0-slim-bookworm@sha256:e1"
)

# reset LIMITED MIRRORS — Docker Hub paths that answer 429, what the mirrors hold
reset() {
  : > "$log"
  : > "$registry"
  tr ' ' '\n' <<< "$1" > "$limited"
  tr ' ' '\n' <<< "$2" > "$mirrors"
  hub_down=0
}

copied() { awk -v r="${REGISTRY}/reqs/$1" '$1 == r { print $2 }' "$registry"; }

# --- Docker Hub answers: everything from docker.io, no mirror asked ---------------------------
reset "" ""
cmd_mirror_reqs > "$tmp/out" 2>&1 || { cat "$tmp/out"; fail "mirror-reqs failed with Docker Hub answering"; }
[ "$(copied alpine:3.21)" = "sha256:a1" ] || fail "alpine:3.21 not copied from Docker Hub"
[ "$(copied rust:1.95.0-slim-bookworm)" = "sha256:e1" ] || fail "the digest-pinned rust not copied at its digest"
grep -q 'mirror.gcr.io\|public.ecr.aws' "$log" && fail "a mirror was asked although Docker Hub answered"
grep -q '^crane digest docker.io' "$log" && fail "Docker Hub's digest was read although Docker Hub answered"
echo "ok - Docker Hub answering: every image from docker.io"

# --- Docker Hub's pull limit: the mirrors, by Docker Hub's digest -----------------------------
reset "library/alpine:3.21 tonistiigi/binfmt:latest library/node:22-bookworm-slim library/rust:1.95.0-slim-bookworm@sha256:e1" \
      "mirror.gcr.io/library/alpine@sha256:a1 mirror.gcr.io/tonistiigi/binfmt@sha256:b1 public.ecr.aws/docker/library/node@sha256:c1 public.ecr.aws/docker/library/rust@sha256:e1"
cmd_mirror_reqs > "$tmp/out" 2>&1 || { cat "$tmp/out"; fail "mirror-reqs failed although the mirrors have every image"; }
[ "$(copied alpine:3.21)" = "sha256:a1" ] || fail "alpine:3.21 not copied from mirror.gcr.io"
[ "$(copied binfmt:latest)" = "sha256:b1" ] || fail "binfmt:latest not copied from mirror.gcr.io"
[ "$(copied node:22-bookworm-slim)" = "sha256:c1" ] || fail "node not copied from public.ecr.aws (mirror.gcr.io lacks it)"
[ "$(copied rust:1.95.0-slim-bookworm)" = "sha256:e1" ] || fail "the digest-pinned rust not copied from a mirror"
grep -qxF 'crane copy mirror.gcr.io/library/alpine@sha256:a1 registry.test/brf-tech/reqs/alpine:3.21' "$log" \
  || fail "alpine:3.21 not copied by Docker Hub's digest"
grep -qF 'crane digest docker.io/library/rust' "$log" && fail "the digest of a pinned image was read from Docker Hub"
grep -qF 'TOOMANYREQUESTS' "$tmp/out" || fail "Docker Hub's refusal is not reported"
grep -qF "from mirror.gcr.io, Docker Hub's digest sha256:a1" "$tmp/out" || fail "the mirror copy is not reported"
echo "ok - Docker Hub's pull limit: the images from mirror.gcr.io / public.ecr.aws by Docker Hub's digest"

# --- a mirror with another image under the tag: never copied ------------------------------------
reset "library/alpine:3.21" "mirror.gcr.io/library/alpine@sha256:old public.ecr.aws/docker/library/alpine@sha256:old"
status=0
cmd_mirror_reqs alpine:3.21 > "$tmp/out" 2>&1 || status=$?
[ "$status" = "1" ] || { cat "$tmp/out"; fail "mirror-reqs did not fail when no mirror has Docker Hub's digest"; }
[ -z "$(copied alpine:3.21)" ] || fail "alpine:3.21 copied without Docker Hub's digest"
grep -qF 'mirror.gcr.io: MANIFEST_UNKNOWN: manifest unknown' "$tmp/out" || fail "the mirrors' answers are not reported: $(cat "$tmp/out")"
echo "ok - a mirror without Docker Hub's digest is not copied, mirror-reqs exits 1"

# --- Docker Hub down (no digest to check against): no mirror copy ------------------------------
reset "library/alpine:3.21" "mirror.gcr.io/library/alpine@sha256:a1"
hub_down=1
status=0
cmd_mirror_reqs alpine:3.21 > "$tmp/out" 2>&1 || status=$?
[ "$status" = "1" ] || fail "mirror-reqs copied from a mirror without Docker Hub's digest"
grep -q '^crane copy mirror' "$log" && fail "a mirror was copied without Docker Hub's digest"
grep -qF 'no Docker Hub digest' "$tmp/out" || fail "the missing digest is not reported"
echo "ok - without Docker Hub's digest nothing is copied from a mirror"

# --- REQS_UPSTREAMS without Docker Hub, a selection of images, a dry run -------------------------
reset "" "mirror.gcr.io/library/alpine@sha256:a1 mirror.gcr.io/tonistiigi/binfmt@sha256:b1"
REQS_UPSTREAMS="mirror.gcr.io" cmd_mirror_reqs alpine:3.21 binfmt > "$tmp/out" 2>&1 || { cat "$tmp/out"; fail "mirror-reqs from mirror.gcr.io failed"; }
grep -q '^crane copy docker.io' "$log" && fail "Docker Hub was pulled although REQS_UPSTREAMS leaves it out"
if [ "$(copied alpine:3.21)" != "sha256:a1" ] || [ "$(copied binfmt:latest)" != "sha256:b1" ]; then
  fail "the two images selected were not copied"
fi
[ "$(wc -l < "$registry")" = "2" ] || fail "more than the two images selected were copied"
reset "" ""
cmd_mirror_reqs --dry-run > "$tmp/out" 2>&1
[ ! -s "$log" ] || fail "the dry run called crane: $(cat "$log")"
grep -qF '[dry-run] docker.io/library/rust:1.95.0-slim-bookworm@sha256:e1 → registry.test/brf-tech/reqs/rust:1.95.0-slim-bookworm' "$tmp/out" \
  || fail "the dry run does not list the Docker Hub source"
echo "ok - REQS_UPSTREAMS, image selection and --dry-run"

# --- the repository's list: every source parses to a Docker Hub path ---------------------------
for entry in "${repository_images[@]}"; do
  path="$(dockerhub_path "${entry#*|}")"
  [[ "$path" =~ ^[a-z0-9._-]+/[a-z0-9._/-]+(:[A-Za-z0-9._-]+)?(@sha256:[0-9a-f]{64})?$ ]] || fail "REQS_IMAGES ${entry}: ${path} is no Docker Hub path"
done
echo "ok - every REQS_IMAGES source is a Docker Hub repository path"
