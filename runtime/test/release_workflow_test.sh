#!/usr/bin/env bash
# Tests the release workflow (.github/workflows/release-images.yml) and GitLab CI's part, without
# GitHub, Docker or a registry:
#   - the workflow runs on a release tag v* and by hand for a tag;
#   - it builds api (api/Dockerfile.prod), ui and ui-mt at the tag's version and runs
#     runtime/ci.sh release-images --push by digest, on a linux/amd64 and a linux/arm64 runner of
#     their own (no emulation), so every image of the release list is built (agent and
#     queue-consumer at appVersion among them; push_by_digest_test.sh runs that path), and
#     compose-index tags them all;
#   - its only secret is GITHUB_TOKEN, packages: write only in the jobs that push, every action
#     pinned to a full commit SHA;
#   - every image carries org.opencontainers.image.source = the repository as a label and every
#     index as an annotation, and the anonymous checks look for both;
#   - the image tars are the amd64 images it pushes (their own layouts, by digest), with the chart and
#     SHA256SUMS, as the local release rounds named them;
#   - .gitlab-ci.yml builds and pushes no image.
#
#   bash runtime/test/release_workflow_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/build.sh
source "$here/lib/build.sh"
# shellcheck source=../lib/build-wasm.sh
source "$here/lib/build-wasm.sh"
# shellcheck source=../lib/test-supply-chain.sh
source "$here/lib/test-supply-chain.sh"

wf="$root/.github/workflows/release-images.yml"
[ -f "$wf" ] || fail "no ${wf}"

# job NAME — the lines of one job of the workflow
job() {
  awk -v j="  $1:" '
    /^jobs:/ { injobs = 1; next }
    injobs && $0 == j { on = 1; print; next }
    on && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ { exit }
    on { print }
  ' "$wf"
}
has() { grep -qF -- "$2" <<<"$1" || fail "$3"; }
# fn NAME — the text of a function of runtime/lib/build.sh, as written
fn() { awk -v f="$1() {" '$0 == f { on = 1 } on { print } on && /^}$/ { exit }' "$here/lib/build.sh"; }

for j in release build merge verify; do
  [ -n "$(job "$j")" ] || fail "no job ${j}"
done
build="$(job build)"
merge="$(job merge)"
verify="$(job verify)"

# --- triggers ---------------------------------------------------------------------------------------
grep -qE "^    tags: \['v\*'\]$" "$wf" || fail "the workflow does not run on the release tags v*"
grep -qE '^  workflow_dispatch:$' "$wf" || fail "the workflow cannot be run by hand"
grep -qxF '        required: true' <<<"$(grep -A4 -E '^      tag:$' "$wf")" || fail "workflow_dispatch has no required tag input"
echo "ok - the workflow runs on v* tags and by hand for a tag"

# --- the images -------------------------------------------------------------------------------------
# (no `… | grep -q` below: pipefail would see the writer's SIGPIPE once grep -q has its match)
runners="$(grep -E '^ +runner: ' <<<"$build" | sed 's/^ *//' | sort | tr '\n' ' ')"
[ "$runners" = 'runner: ubuntu-24.04 runner: ubuntu-24.04-arm ' ] || fail "the build legs are not ubuntu-24.04 and ubuntu-24.04-arm: ${runners}"
has "$build" '--platform "linux/${ARCH}"' "the app images are not built for the runner's platform"
has "$build" 'PLATFORMS: linux/${{ matrix.arch }}' "the region images are not built for the runner's platform"
grep -q 'setup-qemu-action' "$wf" && fail "the workflow sets up emulation (QEMU): every platform has its own runner"
has "$build" 'app api api api "runr-api:${VERSION}" "${VERSION} latest"' "api:<version> + latest not built"
has "$build" '--file api/Dockerfile.prod --build-arg "APP_VERSION=v${VERSION}"' "the API image is not Dockerfile.prod with APP_VERSION=v<version>"
has "$build" 'mkdir -p api/resources/docs && cp docs/*.md api/resources/docs/' "the API image does not carry docs/ (runr://docs)"
has "$build" 'app ui vue ui "runr-ui:${VERSION}" "${VERSION} latest"' "ui:<version> + latest not built"
has "$build" 'app ui-mt vue ui "runr-ui:${VERSION}-mt" "${VERSION}-mt"' "ui:<version>-mt not built"
has "$(grep -A1 -F 'app ui vue ui ' <<<"$build")" '--build-arg VITE_TENANCY_ENABLED=false --build-arg VITE_AUTH_PROVIDER_ENABLED=false' \
  "ui is not the single-tenant build"
has "$(grep -A1 -F 'app ui-mt vue ui ' <<<"$build")" '--build-arg VITE_TENANCY_ENABLED=true --build-arg VITE_AUTH_PROVIDER_ENABLED=true' \
  "ui-mt is not the multi-tenant build"
for arg in VITE_WS_PROXY=true VITE_REVERB_KEY=runr-key VITE_TICKET_ENABLED=true VITE_BILLING_ENABLED=true; do
  has "$build" "--build-arg ${arg}" "the UI images miss --build-arg ${arg}"
done
has "$build" 'PUSH_BY_DIGEST="$RUNNER_TEMP/digests/region-${ARCH}.txt" bash runtime/ci.sh release-images --push' \
  "the region images are not pushed by digest through runtime/ci.sh release-images --push"
# every image of the release list goes through release-images --push, which builds it
list="$(release_images)"
version="$(release_version)"
for name in agent queue-consumer; do
  grep -qE " ${REGISTRY//./\\.}/${name}:${version//./\\.}\$" <<<"$list" || fail "${name}:${version} not in the release list"
done
release_fn="$(fn cmd_release_images)"
while read -r cmd ref; do
  grep -qE "^ +${cmd}\) +cmd_[a-z_]+ --push ;;" <<<"$release_fn" || fail "release-images --push does not build ${ref} (${cmd})"
done <<<"$list"
has "$merge" 'pattern: digests-*' "the merge job does not take every leg's digests"
has "$merge" 'bash runtime/ci.sh compose-index "${files[@]}"' "the merge job does not compose the tags with runtime/ci.sh compose-index"
echo "ok - api, ui, ui-mt and the $(grep -c . <<<"$list") images of release-images are built on an amd64 and an arm64 runner and composed"

# --- GITHUB_TOKEN only ------------------------------------------------------------------------------
secrets="$(grep -oE '\$\{\{ *secrets\.[A-Za-z0-9_]+ *\}\}' "$wf" | sed -E 's/[${} ]//g' | sort -u)"
[ "$secrets" = "secrets.GITHUB_TOKEN" ] || fail "the workflow uses other secrets than GITHUB_TOKEN: ${secrets}"
grep -qiE '(^|[^A-Z_])(PAT|GHCR_TOKEN|CR_PAT|GH_TOKEN|PERSONAL_ACCESS_TOKEN)([^A-Z_]|$)' "$wf" && fail "the workflow names another token"
[ "$(awk '/^permissions:/ { p = 1; next } p && /^[^ ]/ { exit } p { print }' "$wf")" = '  contents: read' ] \
  || fail "the workflow's default permissions are not contents: read alone"
for j in release verify; do
  grep -q 'packages: write' <<<"$(job "$j")" && fail "job ${j} can write packages"
done
for j in build merge; do
  grep -qxF '      packages: write' <<<"$(job "$j")" || fail "job ${j} cannot push (packages: write)"
done
bad="$(grep -E '^ *- uses: |^ *uses: ' "$wf" | grep -vE 'uses: [A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40} # v[0-9]' || true)"
[ -z "$bad" ] || fail "actions not pinned to a full commit SHA (with its version): ${bad}"
echo "ok - GITHUB_TOKEN is the only secret, packages: write only where the workflow pushes, every action pinned"

# --- source label and annotation --------------------------------------------------------------------
grep -qxF "  SOURCE_URL: ${RUNR_SOURCE_URL}" "$wf" || fail "the workflow's SOURCE_URL is not ${RUNR_SOURCE_URL}"
# shellcheck disable=SC2016 # the workflow's text, not this shell's variables
for label in 'source=${SOURCE_URL}' 'version=${VERSION}' 'revision=${REVISION}' 'created=${created}'; do
  has "$build" "--label \"org.opencontainers.image.${label}\"" "the app images miss the label org.opencontainers.image.${label}"
done
has "$(fn cmd_compose_index)" '--annotation=index:org.opencontainers.image.source=${RUNR_SOURCE_URL}' \
  "compose-index does not annotate the index with the source"
has "$(fn oci_label_args)" '--label=org.opencontainers.image.source=${RUNR_SOURCE_URL}' "a ci.sh push does not label the source"
has "$(fn do_build)" '--annotation "${annotation}:org.opencontainers.image.source=${RUNR_SOURCE_URL}"' \
  "a ci.sh push does not annotate its index with the source"
has "$verify" '.annotations["org.opencontainers.image.source"]' "the anonymous checks do not look at the index annotation"
has "$verify" '.config.Labels["org.opencontainers.image.source"]' "the anonymous checks do not look at the label"
echo "ok - the source ${RUNR_SOURCE_URL} as a label of every image and an annotation of every index"

# --- image tars and the anonymous checks ------------------------------------------------------------
has "$build" '--output "type=docker,dest=${tar},oci-mediatypes=true"' "the amd64 app images are not exported as docker save tars"
has "$build" 'crane push "$RUNNER_TEMP/layout-${key}" "${repo}@${digest}"' "the image tars' own layouts are not what is pushed"
for f in 'runr-api-${VERSION}.tar' 'runr-ui-${VERSION}.tar' 'runr-ui-${VERSION}-mt.tar' 'runr-${VERSION}.tgz' 'SHA256SUMS' 'DIGESTS'; do
  has "$build" "$f" "the image tars miss ${f}"
done
has "$build" 'name: runr-${{ needs.release.outputs.version }}-tars' "no artifact runr-<version>-tars"
has "$verify" "echo '{}' > \"\$anon/config.json\"" "the pull checks are not anonymous"
grep -qF 'docker/login-action' <<<"$verify" && fail "the verify job logs in: its checks must be anonymous"
has "$verify" 'mapfile -t region < <(bash runtime/ci.sh release-images)' "the anonymous checks do not cover the release list"
has "$verify" 'APP_VERSION=v${VERSION}' "the anonymous checks do not look at the API's APP_VERSION"
has "$verify" 'bash ci/check-reqs-mirror.sh' "the reqs mirror is not checked"
echo "ok - image tars from the pushed amd64 images, anonymous checks of every tag"

# --- GitLab CI pushes no image ----------------------------------------------------------------------
gl="$root/.gitlab-ci.yml"
pushes="$(grep -vE '^[[:space:]]*#' "$gl" | grep -nE 'docker (image )?push|push=true|--push|docker login|CI_REGISTRY|imagetools create|crane (push|copy|cp)|skopeo copy|buildah push|podman push|kaniko|stage: (build|export|deploy)' || true)"
[ -z "$pushes" ] || fail ".gitlab-ci.yml still builds or pushes an image: ${pushes}"
grep -qE '^(build:api|build:ui|export:openapi|deploy:production):' "$gl" && fail ".gitlab-ci.yml still has an image, export or deploy job"
echo "ok - .gitlab-ci.yml builds and pushes no image"
