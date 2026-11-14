#!/usr/bin/env bash
# Tests the dependency test bot's package groups (ci/deps/packages.sh) without a network or the
# registries: composer, npm, go, curl and govulncheck are scripts on PATH that answer from files,
# the GitHub repositories of the Actions pins are local bare repositories. Checks that:
#   - every tracked composer.json, package.json, go.mod, pyproject.toml, requirements*.txt,
#     Cargo.toml and Dockerfile is a group's, a Dockerfile of DEPS_DOCKERFILES or untracked with
#     its reason; every version pin of a Dockerfile and a workflow is a row of DEPS_PINS or untracked
#     with its reason, and each row is sound (its places pin one version, its path agrees with its
#     files, its suites exist); the console's build roots cover the build's imports; every hold has
#     a reason;
#   - the pins: a minor or patch release of a pin's line is one finding per group (the images' and
#     the workflows'), a newer line, every release of a patched upstream and a toolchain's newer
#     line are findings of their own, places that disagree fail the check; the bump moves the
#     version at every place and the published checksums with it (a sha256sum file, a checksums
#     file the image's build reads, an npm file's sha256 and sha384 integrity), a release download
#     that answers 504 comes through the GitHub API, a checksum that is not published sends the
#     bump to a person;
#   - the Python SDKs, the examples' requirements and Cargo.toml (no lockfile): only a release
#     outside the constraint is a finding; a go.mod without go.sum only its majors;
#   - detection: the minor and patch updates of a group are one finding with its marker and
#     fingerprint (the same set: the same title), an update outside the constraint (a major, a 0.x
#     minor, an exact pin, a new Go major path, a toolchain image line) is a finding of its own per
#     release line (with its marker), an advisory makes the finding urgent (security) and adds its
#     package, a hold is left with its reason (a major it keeps written for the weekly summary's held
#     row), the Go toolchain is one finding for every module, the groups read are listed;
#   - the plan takes a group's newest finding with its updates and supersedes the older ones; an
#     update outside its constraint only gets the label;
#   - the bumps: composer and npm move only their lockfile and decide release or next from what
#     moved (composer.lock's packages, the npm packages the bundle takes), a lockfile that does not
#     come out the same twice, a moved constraint or a held package goes to a person with nothing
#     left changed; go moves go.mod and go.sum and the image's own version; an Actions pin moves
#     its commit and comment on next only; a Dockerfile base moves its tag and digest and the own
#     version of its image;
#   - a bump of next only is made and tested on next and lands on the origin's next alone, a
#     fast-forward (not in a dry run, not on a next that moved), with the attribution rule.
#
#   bash runtime/test/deps_scope_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.test GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export DEPS_BOT_NAME="Burak Faruk ŞAHİN" DEPS_BOT_EMAIL="19393636+brkfun@users.noreply.github.com"
export DEPS_CHECK_DATE=2026-11-14

# --- the tables against this repository -------------------------------------------------------------
out="$(bash "$root/ci/check-package-releases.sh" --known)" || fail "a manifest no group knows: ${out}"
# shellcheck source=ci/deps/lib.sh
DEPS_ROOT="$root"
source "$root/ci/deps/lib.sh"
imports="$(cat "$root/vue/vite.config.js" "$root/vue/postcss.config.js" "$root"/vue/build/*.js \
  | sed -nE "s/.*(from +|import\\()['\"]([^.'\"/][^'\"]*)['\"].*/\\2/p; s/^ +['\"](@?[a-z][^'\"]*)['\"]: *\\{.*/\\1/p" \
  | grep -v '^node:' | sed -E 's#^(@[^/]+/[^/]+|[^@/][^/]*).*#\1#' | sort -u)"
[ -n "$imports" ] || fail "no import of the console's build read"
for i in $imports; do
  [[ " $DEPS_NPM_BUILD_ROOTS " == *" $i "* ]] || fail "the console's build imports ${i}, which DEPS_NPM_BUILD_ROOTS lacks (its bumps would land on next only)"
done
while read -r group name versions reason; do
  case "$group" in ''|'#'*) continue ;; esac
  [ -n "$reason" ] && [[ "$versions" =~ ^(\*|\>=.+)$ ]] || fail "holds.txt: ${group} ${name} without its versions or its reason"
  deps_pkg_row "$group" >/dev/null || fail "holds.txt: ${group} is no group"
done < "$root/ci/deps/holds.txt"
for row in "${DEPS_DOCKERFILES[@]}"; do [ -f "$root/${row%%|*}" ] || fail "DEPS_DOCKERFILES: ${row%%|*} is gone"; done
for row in "${DEPS_PKG[@]}"; do
  d="$(deps_field "$row" 3)"
  [ "$d" = - ] || [ -d "$root/$d" ] || fail "DEPS_PKG: ${row%%|*}'s ${d} is gone"
done
deps_held npm-vue cronstrue 9.9.9 >/dev/null || fail "a * hold"
deps_bump_patch 1.0.0 | grep -qx 1.0.1 && deps_bump_patch v0.26.0-runr.1 | grep -qx v0.26.0-runr.2 || fail "deps_bump_patch"
echo "ok - every manifest is a group's, a Dockerfile of the table or untracked with a reason; the console's build roots cover its imports; every hold has its reason"

# each pin of DEPS_PINS: its group, its line, its checksum kind, one version at all of its places,
# a path its files agree with (no .github/ in a release, no shipped file on next only), known suites
while read -r group name versions reason; do
  case "$group" in ''|'#'*) continue ;; esac
  [ "$(deps_field "$(deps_pkg_row "$group")" 2)" != pins ] || deps_pin_row "$group" "$name" >/dev/null || fail "holds.txt: ${group} has no pin ${name}"
done < "$root/ci/deps/holds.txt"
for row in "${DEPS_PINS[@]}"; do
  g="$(deps_field "$row" 1)"; name="$(deps_field "$row" 2)"; path="$(deps_field "$row" 5)"; spec="$(deps_field "$row" 6)"
  what="DEPS_PINS ${g} ${name}"
  [ "$(deps_field "$(deps_pkg_row "$g" || true)" 2)" = pins ] || fail "${what}: ${g} is no group of kind pins"
  [ "$(grep -c "^  \"${g}|${name}|" "$root/ci/deps/packages.sh")" = 1 ] || fail "${what}: not one row"
  [[ "$(deps_field "$row" 4)" =~ ^(major|minor|patch|person|toolchain-major|toolchain-minor)$ ]] || fail "${what}: no line $(deps_field "$row" 4)"
  [[ "$spec" =~ ^(-|(sum|build|digest|npm|crate):[a-z0-9,-]*:.*)$ ]] || fail "${what}: no checksum kind ${spec}"
  case "$g:$path" in pins-ci:next|pins-images:release|pins-images:own) ;; *) fail "${what}: path ${path} in ${g}" ;; esac
  vers=""
  kinds=""
  for place in $(deps_field "$row" 7); do
    IFS='~' read -r glob slot var <<<"$place"
    [[ "$slot" =~ ^(ARG|YAML|SH|AT)$ ]] || fail "${what}: no slot ${slot}"
    found=0
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      v="$(deps_pin_read "$f" "$slot" "$var" | sort -u)"
      [ -n "$v" ] || continue
      found=1
      vers="$(printf '%s\n%s\n' "$vers" "$v" | sed '/^$/d' | sort -u)"
      drow="$(deps_dockerfile_row "$f" || true)"
      case "$f" in
        .github/*) kinds="${kinds} gh" ;;
        api/tests/*) ;;
        api/*) if [ -n "$drow" ]; then kinds="${kinds} $(deps_field "$drow" 3)"; else kinds="${kinds} release"; fi ;;
        *) [ -z "$drow" ] || kinds="${kinds} $(deps_field "$drow" 3)" ;;
      esac
    done < <(deps_pin_files "$glob")
    [ "$found" = 1 ] || fail "${what}: no file of ${glob} pins it (${slot} ${var})"
  done
  [ "$(wc -l <<<"$vers")" = 1 ] || fail "${what}: its places pin ${vers//$'\n'/, }"
  case "$path" in
    next) [[ " $kinds " != *" release "* && " $kinds " != *" own "* ]] || fail "${what}: next only, and a file of it ships (${kinds})" ;;
    *) [[ " $kinds " != *" gh "* ]] && [[ " $kinds " == *" ${path} "* ]] || fail "${what}: ${path}, and its files are${kinds}" ;;
  esac
  for s in $(deps_field "$row" 8); do
    case "$s" in
      image:*|go:*|npm-test:*|unit|e2e|upgrade|ui|ui-stack|caddy|actionlint) ;;
      *) grep -qE "^ +([a-z|-]+\|)?${s}(\|[a-z|-]+)?\) suite_json" "$root/ci/deps/bump-packages.sh" || fail "${what}: no suite ${s}" ;;
    esac
  done
done
echo "ok - every version pin of the Dockerfiles and workflows is a row of DEPS_PINS or untracked with a reason, each row one version at its places, its path agreeing with its files"

# the API image's kubectl set (KubectlBinary): every second Kubernetes minor from the one above
# RunR's oldest (knative.min_k8s_minor), so each cluster RunR accepts has a kubectl within one minor;
# a row per kubectl, the same set in both API Dockerfiles, each pin of its directory's minor, and
# only the newest row's line minor (it reports a newer Kubernetes minor), the others' patch
min_k8s="$(sed -nE "s/.*'min_k8s_minor' => \(int\) env\('KNATIVE_MIN_K8S_MINOR', ([0-9]+)\).*/\1/p" "$root/api/config/knative.php")"
[ -n "$min_k8s" ] || fail "no knative.min_k8s_minor in api/config/knative.php"
set_prod="$(sed -nE 's/^ARG KUBECTL_1_([0-9]+)_VERSION=v1\.([0-9]+)\.[0-9]+$/\1 \2/p' "$root/api/Dockerfile.prod")"
set_dev="$(sed -nE 's/^ARG KUBECTL_1_([0-9]+)_VERSION=v1\.([0-9]+)\.[0-9]+$/\1 \2/p' "$root/api/Dockerfile")"
[ -n "$set_prod" ] && [ "$set_prod" = "$set_dev" ] || fail "the kubectl set of api/Dockerfile.prod (${set_prod//$'\n'/, }) and api/Dockerfile (${set_dev//$'\n'/, })"
grep -qE '^ARG KUBECTL_VERSION=' "$root/api/Dockerfile.prod" "$root/api/Dockerfile" && fail "an API Dockerfile pins a single KUBECTL_VERSION besides the set"
want=$((min_k8s + 1))
newest=""
while read -r dir pinned; do
  [ "$dir" = "$pinned" ] || fail "KUBECTL_1_${dir}_VERSION pins kubectl 1.${pinned}"
  [ "$dir" = "$want" ] || fail "the kubectl set: 1.${dir} where 1.${want} belongs (every second minor from 1.$((min_k8s + 1)))"
  row="$(deps_pin_row pins-images "kubectl-1.${dir}")" || fail "no DEPS_PINS row kubectl-1.${dir}"
  [ "$(deps_field "$row" 7)" = "api/Dockerfile.prod~ARG~KUBECTL_1_${dir}_VERSION api/Dockerfile~ARG~KUBECTL_1_${dir}_VERSION" ] || fail "kubectl-1.${dir}'s places: $(deps_field "$row" 7)"
  grep -qF "  1.${dir} \"\$KUBECTL_1_${dir}_VERSION\" " "$root/api/Dockerfile.prod" || fail "api/Dockerfile.prod installs no kubectl 1.${dir}"
  newest="$dir"
  want=$((dir + 2))
done <<<"$set_prod"
for row in "${DEPS_PINS[@]}"; do
  name="$(deps_field "$row" 2)"
  [ "$(deps_field "$row" 1)" = pins-images ] && [[ "$name" == kubectl* ]] || continue
  m="${name#kubectl-1.}"
  grep -qx "${m} ${m}" <<<"$set_prod" || fail "DEPS_PINS ${name}: no kubectl of the API image"
  if [ "$m" = "$newest" ]; then line=minor; else line=patch; fi
  [ "$(deps_field "$row" 4)" = "$line" ] || fail "DEPS_PINS ${name}: line $(deps_field "$row" 4), not ${line}"
done
grep -qF "'kubectl_dir' => '/usr/local/lib/runr/kubectl'" "$root/api/config/sandbox.php" && grep -qF 'mkdir -p "/usr/local/lib/runr/kubectl/$1"' "$root/api/Dockerfile.prod" \
  || fail "the API image's kubectl directory is not sandbox.exec.kubectl_dir"
echo "ok - the API image's kubectl set: every second minor from 1.$((min_k8s + 1)) to 1.${newest}, one row each (the newest reports a new minor), the same in both API Dockerfiles"

# --- the fixture repository ----------------------------------------------------------------------------
mkdir -p "$tmp/bin"
export FAKE="$tmp/fake"
mkdir -p "$FAKE"
# composer: outdated answers $FAKE/composer-<mode>.json, audit $FAKE/composer-audit.json; update
# sets the versions of $FAKE/composer-versions ("name version") in composer.lock (and composer.json
# when $FAKE/composer-constraint exists, and a changing content-hash when $FAKE/flaky exists)
cat > "$tmp/bin/composer" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  outdated)
    case " $* " in *' --major-only '*) cat "$FAKE/composer-major.json" ;; *) cat "$FAKE/composer-minor.json" ;; esac ;;
  audit) cat "$FAKE/composer-audit.json"; exit 1 ;;
  update)
    lock="composer.lock"
    while read -r n v; do
      [[ " $* " == *" $n "* ]] || [ "$n" = transitive/prod ] || [ "$n" = transitive/dev ] || continue
      jq --arg n "$n" --arg v "$v" '(.packages[]?, ."packages-dev"[]?) |= (if .name == $n then .version = $v else . end)' "$lock" > "$lock.tmp" && mv "$lock.tmp" "$lock"
    done < "$FAKE/composer-versions"
    [ ! -e "$FAKE/composer-constraint" ] || sed -i 's/"\^12.0"/"^13.0"/' composer.json
    if [ -e "$FAKE/flaky" ]; then n=$(( $(cat "$FAKE/flaky") + 1 )); echo "$n" > "$FAKE/flaky"; jq --arg h "$n" '."content-hash" = $h' "$lock" > "$lock.tmp" && mv "$lock.tmp" "$lock"; fi ;;
  *) echo "composer: unexpected $*" >&2; exit 2 ;;
esac
EOF
# npm: view answers $FAKE/npm-latest ("name version": a name it lacks is not on the registry),
# $FAKE/npm-wanted ("name@range version") and $FAKE/npm-all ("name version", every release), audit
# $FAKE/npm-audit.json; update sets $FAKE/npm-versions ("path version") in package-lock.json
cat > "$tmp/bin/npm" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  --version) echo "${FAKE_NPM_VERSION:-11.21.2}" ;;
  view)
    if [ "$3" = dist-tags.latest ]; then
      grep -q "^$2 " "$FAKE/npm-latest" || { echo "npm error 404 '$2' is not in this registry" >&2; exit 1; }
      awk -v n="$2" '$1 == n { print $2 }' "$FAKE/npm-latest"
    elif [ "$3" = versions ]; then awk -v n="$2" '$1 == n { print $2 }' "$FAKE/npm-all" | jq -R -s -c 'split("\n") | map(select(. != ""))'
    else v="$(awk -v n="$2" '$1 == n { print $2 }' "$FAKE/npm-wanted")"; [ -z "$v" ] || printf '["%s"]\n' "$v"; fi ;;
  audit)
    [ "$2" = fix ] && exit 0
    cat "$FAKE/npm-audit.json" 2>/dev/null || echo '{}' ;;
  update)
    while read -r p v; do
      jq --arg p "$p" --arg v "$v" '.packages[$p].version = $v' package-lock.json > l.tmp && mv l.tmp package-lock.json
    done < "$FAKE/npm-versions" ;;
  *) echo "npm: unexpected $*" >&2; exit 2 ;;
esac
EOF
# go: list -m -u answers $FAKE/go-list.json (every module); get rewrites go.mod's requires and go.sum;
# mod edit -json reads go.mod
cat > "$tmp/bin/go" <<'EOF'
#!/usr/bin/env bash
case "$1 $2" in
  'list -m') cat "$FAKE/go-list.json" ;;
  'get '*|'get') shift
    for a in "$@"; do m="${a%@*}"; v="${a##*@}"; sed -i -E "s#^(\t${m//./\\.}) v[^ ]+#\1 ${v}#" go.mod; echo "${m} ${v} h1:x=" >> go.sum; done ;;
  'mod tidy') ;;
  'mod edit') awk 'BEGIN { printf "{\"Require\": [" } /^\t[^ ]+ v/ { printf "%s{\"Path\": \"%s\", \"Version\": \"%s\"}", (n++ ? "," : ""), $1, $2 } END { print "]}" }' go.mod ;;
  *) echo "go: unexpected $*" >&2; exit 2 ;;
esac
EOF
# curl: ghcr tokens, tags of $FAKE/tags-<repository with / as _>, the digest header; the module proxy's
# lists of $FAKE/proxy-<path with / as _>; any other URL is a file of $FAKE/web/<host>/<path> (-o
# and -w '%{http_code}' as curl has them), its status in <file>.status (504: a gateway timeout),
# 404 without either; every such URL is logged to $FAKE/curl.log
cat > "$tmp/bin/curl" <<'EOF'
#!/usr/bin/env bash
url="${*: -1}"
case "$url" in
  */token\?*) echo '{"token": "t"}' ;;
  */tags/list*) p="${url#*/v2/}"; p="${p%/tags/list*}"; f="$FAKE/tags-${p//\//_}"; [ -f "$f" ] || exit 22; jq -R -s -c '{tags: split("\n") | map(select(. != ""))}' "$f" ;;
  */manifests/*) printf 'HTTP/2 200\r\ndocker-content-digest: sha256:%064d\r\n\r\n' 7 ;;
  */@v/list) p="${url#*://*/}"; p="${p%/@v/list}"; f="$FAKE/proxy-${p//\//_}"; [ -f "$f" ] || exit 22; cat "$f" ;;
  *://*)
    echo "$url" >> "$FAKE/curl.log"
    f="$FAKE/web/${url#*://}" out="" fmt="" prev=""
    for a in "$@"; do case "$prev" in -o) out="$a" ;; -w) fmt="$a" ;; esac; prev="$a"; done
    code=404
    [ ! -f "$f" ] || code=200
    [ ! -f "$f.status" ] || code="$(cat "$f.status")"
    if [ "$code" = 200 ]; then if [ -n "$out" ]; then cp "$f" "$out"; else cat "$f"; fi; fi
    [ -z "$fmt" ] || printf '%s' "$code"
    [ "$code" = 200 ] || exit 22 ;;
  *) echo "curl: unexpected $*" >&2; exit 2 ;;
esac
EOF
chmod +x "$tmp/bin/"*
export PATH="$tmp/bin:$PATH"

repo="$tmp/repo"
mkdir -p "$repo/ci/deps"
cp "$root/ci/check-package-releases.sh" "$root/ci/deps-bump.sh" "$root/ci/deps-plan.sh" "$repo/ci/"
cp "$root"/ci/deps/*.sh "$root"/ci/deps/*.awk "$root"/ci/deps/*.mjs "$root"/ci/deps/*.py "$root/ci/deps/holds.txt" "$repo/ci/deps/"
for row in "${DEPS_DOCKERFILES[@]}"; do
  f="$repo/${row%%|*}"; mkdir -p "$(dirname "$f")"
  printf 'FROM ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine AS build\nFROM ghcr.io/brf-tech/runr/reqs/alpine:3.21\n' > "$f"
done
printf 'ARG VERSION=1.0.0\nFROM ghcr.io/brf-tech/runr/reqs/alpine:3.21\nARG VERSION=1.0.0\n' > "$repo/runtime/gateway-auth/Dockerfile"
printf 'ARG TRIVY_VERSION=0.58.1\nFROM ghcr.io/aquasecurity/trivy:${TRIVY_VERSION}\n' > "$repo/runtime/supply-chain/Dockerfile"
printf 'ARG RUST_IMAGE=ghcr.io/brf-tech/runr/reqs/rust:1.95.0-slim-bookworm@sha256:%064d\nFROM ${RUST_IMAGE} AS rust\nFROM ghcr.io/brf-tech/runr/reqs/alpine:3.21\n' 1 > "$repo/runtime/wasm-shim-spin/Dockerfile"
# the release's images, as runtime/ci.sh release-images lists them (gateway-auth's own version)
mkdir -p "$repo/runtime" "$repo/api/config" "$repo/docs"
cat > "$repo/runtime/ci.sh" <<'EOF'
#!/usr/bin/env bash
d="$(dirname "$0")"
[ "$1" = release-images ] || exit 0
echo "ghcr.io/brf-tech/runr/gateway-auth:$(sed -n 's/^ARG VERSION=//p' "$d/gateway-auth/Dockerfile" | head -n 1)"
source "$d/lib/test-supply-chain.sh"
supply_chain_image_tag
EOF
mkdir -p "$repo/runtime/lib"
echo 'supply_chain_image_tag() { echo "ghcr.io/brf-tech/runr/supply-chain:${SUPPLY_CHAIN_VERSION:-1.0.0}"; }' > "$repo/runtime/lib/test-supply-chain.sh"
echo "'gateway_auth_image' => 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0'," > "$repo/api/config/api_product.php"
mkdir -p "$repo/api/tests"
echo "'ghcr.io/brf-tech/runr-gateway-auth:1.0.0' => 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0'," > "$repo/api/tests/RegistryMoveTest.php"
printf '%s\n' 'The gateway runs ghcr.io/brf-tech/runr/gateway-auth:1.0.0.' 'Measured on RunR 0.6.8 with ghcr.io/brf-tech/runr/gateway-auth:1.0.0.' 'The legacy name ghcr.io/brf-tech/runr-gateway-auth:1.0.0 maps to it.' > "$repo/docs/api-product.md"
for d in runtime/agent runtime/queue-consumer runtime/gateway-auth runtime/image-upload cli terraform-provider-runr proxy examples/runtimes/q/interpreter; do
  mkdir -p "$repo/$d"
  printf 'module example.com/%s\n\ngo 1.26.9\n\nrequire (\n\tgithub.com/a/lib v1.2.0\n\tgithub.com/b/old v1.0.0 // indirect\n)\n' "${d//\//-}" > "$repo/$d/go.mod"
  echo "github.com/a/lib v1.2.0 h1:x=" > "$repo/$d/go.sum"
done
# a module without go.sum (the TinyGo example: the region's build resolves it)
mkdir -p "$repo/examples/wasm/go"
printf 'module runr-function\n\ngo 1.22\n\nrequire github.com/a/lib v1.2.0\n' > "$repo/examples/wasm/go/go.mod"
# the Python SDKs and the examples' manifests, none with a lockfile
mkdir -p "$repo/sdk/python" "$repo/sdk/sandbox/python" "$repo/examples/mcp/python" "$repo/examples/workflows/python" \
  "$repo/examples/ai/hf-python/weights" "$repo/examples/wasm/rust" "$repo/examples/wasm/js" "$repo/examples/mcp/node" "$repo/examples/workflows/node"
printf '[build-system]\nrequires = ["setuptools>=68", "wheel"]\n\n[project]\nname = "runr-workflows"\ndependencies = []\n\n[project.optional-dependencies]\ntest = ["pytest>=7"]\n' > "$repo/sdk/python/pyproject.toml"
printf '[project]\nname = "runr-sandbox"\ndependencies = ["httpx>=0.25", "websockets>=13"]\n' > "$repo/sdk/sandbox/python/pyproject.toml"
printf '%s\n' 'mcp>=1.12,<2' 'uvicorn>=0.30  # the server' > "$repo/examples/mcp/python/requirements.txt"
echo 'runr-workflows>=0.1.0' > "$repo/examples/workflows/python/requirements.txt"
echo 'transformers==5.20.0' > "$repo/examples/ai/hf-python/weights/requirements.txt"
printf '[package]\nname = "runr-function"\n\n[dependencies]\nanyhow = "1"\nspin-sdk = "5.2.0"\n' > "$repo/examples/wasm/rust/Cargo.toml"
echo '{"devDependencies": {"@spinframework/build-tools": "^2.1.3"}}' > "$repo/examples/wasm/js/package.json"
echo '{"dependencies": {}}' > "$repo/examples/mcp/node/package.json"
echo '{"dependencies": {"@runr/workflows": "^0.1.0"}}' > "$repo/examples/workflows/node/package.json"

# the pins (DEPS_PINS): every place of every row, at the version the tests below take or the one
# this repository pins (.github/workflows/*.yml: tools.yml); its upstream's releases: that version
# (more for the tests, below). Checksums of the tested pins: placeholders, the npm file's real ones
sha() { printf '%s%063d' "$1" "$2"; }
declare -A fixv=([pins-images/helm]=v3.16.0 [pins-ci/helm]=v3.16.0 [pins-images/kubectl-1.35]=v1.35.9 [pins-images/scalar]=1.75.1
  [pins-images/syft]=1.18.1 [pins-images/cosign]=2.4.1 [pins-images/containerd-shim-spin]=v0.26.0 [pins-ci/kind]=v0.31.0
  [pins-ci/cloud-provider-kind]=v0.10.0 [pins-ci/go]=1.26.9 [pins-ci/node]=22)
gh="$tmp/gh"
# uptag REPO TAG… — the tags of an upstream's local repository (made when missing)
uptag() {
  local r="$gh/$1" t
  [ -d "$r/.git" ] || git init -q "$r"
  shift
  for t in "$@"; do
    git -C "$r" rev-parse -q --verify "refs/tags/$t" >/dev/null && continue
    git -C "$r" commit -q --allow-empty -m "$t"
    git -C "$r" tag -a -m "$t" "$t"
  done
}
for row in "${DEPS_PINS[@]}"; do
  g="$(deps_field "$row" 1)"; name="$(deps_field "$row" 2)"; src="$(deps_field "$row" 3)"
  v="${fixv[$g/$name]:-}"
  if [ -z "$v" ]; then
    for place in $(deps_field "$row" 7); do
      IFS='~' read -r glob slot var <<<"$place"
      for f in $(deps_pin_files "$glob"); do v="$(deps_pin_read "$f" "$slot" "$var" | head -n 1)"; [ -z "$v" ] || break 2; done
    done
  fi
  [ -n "$v" ] || fail "no version of ${g} ${name} in this repository"
  for place in $(deps_field "$row" 7); do
    IFS='~' read -r glob slot var <<<"$place"
    f="${glob/\*/tools}"
    mkdir -p "$repo/$(dirname "$f")"
    case "$slot" in
      ARG) echo "ARG ${var}=${v}" ;;
      YAML) echo "      ${var}: ${v}" ;;
      SH) echo "${var}=\"${v}\"" ;;
      AT) echo "x ${var}${v}\"" ;;
    esac >> "$repo/$f"
  done
  case "$src" in
    github:*) uptag "${src#github:}" "v${v#v}" ;;
    npm:*) echo "${src#npm:} ${v}" >> "$FAKE/npm-all" ;;
    ghcr:*) echo "$v" >> "$FAKE/tags-$(tr / _ <<<"${src#ghcr:}")" ;;
    goproxy:*) echo "$v" >> "$FAKE/proxy-$(tr / _ <<<"${src#goproxy:}")" ;;
  esac
done
# shellcheck disable=SC2016 # the Dockerfile's shell, not this one's
printf 'RUN case "$a" in amd64) HELM_SHA=%s ;; arm64) HELM_SHA=%s ;; esac\n' "$(sha a 1)" "$(sha a 2)" | tee -a "$repo/api/Dockerfile.prod" >> "$repo/api/Dockerfile"
# shellcheck disable=SC2016 # the Dockerfile's shell: the kubectl set's checksums, as api/Dockerfile*
printf 'RUN case "$a" in amd64) set -- 1.35 "$KUBECTL_1_35_VERSION" %s 1.37 "$KUBECTL_1_37_VERSION" %s ;; arm64) set -- 1.35 "$KUBECTL_1_35_VERSION" %s 1.37 "$KUBECTL_1_37_VERSION" %s ;; esac\n' \
  "$(sha 7 1)" "$(sha 7 3)" "$(sha 7 2)" "$(sha 7 4)" | tee -a "$repo/api/Dockerfile.prod" >> "$repo/api/Dockerfile"
printf '      %s\n' "HELM_SHA256: $(sha a 1)" "KIND_SHA256: $(sha c 1)" "CLOUD_PROVIDER_KIND_SHA256: $(sha e 1)" >> "$repo/.github/workflows/tools.yml"
for v in 1.75.1 1.78.0; do mkdir -p "$tmp/npm/$v/package/dist/browser"; echo "Scalar ${v}" > "$tmp/npm/$v/package/dist/browser/standalone.js"; done
echo "ARG SCALAR_SHA256=$(sha256sum < "$tmp/npm/1.75.1/package/dist/browser/standalone.js" | cut -d' ' -f1)" >> "$repo/api/Dockerfile.prod"
echo "  integrity=\"sha384-$(openssl dgst -sha384 -binary "$tmp/npm/1.75.1/package/dist/browser/standalone.js" | base64 -w0)\"" \
  >> "$repo/api/resources/views/vendor/scramble/docs.blade.php"
mkdir -p "$repo/api" "$repo/vue" "$repo/sdk/node" "$repo/sdk/sandbox/node" "$repo/.github/workflows" "$repo/actions/deploy"
cat > "$repo/api/composer.json" <<'EOF'
{"require": {"php": "^8.2", "laravel/framework": "^12.0", "dedoc/scramble": "^0.12"}, "require-dev": {"phpunit/phpunit": "^11.5"}}
EOF
cat > "$repo/api/composer.lock" <<'EOF'
{"content-hash": "h", "packages": [{"name": "laravel/framework", "version": "v12.3.1"}, {"name": "dedoc/scramble", "version": "v0.12.1"}, {"name": "transitive/prod", "version": "1.0.0"}],
 "packages-dev": [{"name": "phpunit/phpunit", "version": "11.5.3"}, {"name": "transitive/dev", "version": "2.0.0"}]}
EOF
cat > "$repo/vue/package.json" <<'EOF'
{"name": "runr-ui", "dependencies": {"axios": "^1.7", "cronstrue": "3.30.0", "lucide-vue-next": "^0.577.0"}, "devDependencies": {"eslint": "^10.0.0", "vite": "^6.4.5"}}
EOF
cat > "$repo/vue/package-lock.json" <<'EOF'
{"name": "runr-ui", "lockfileVersion": 3, "packages": {
  "": {"dependencies": {"axios": "^1.7", "cronstrue": "3.30.0", "lucide-vue-next": "^0.577.0"}, "devDependencies": {"eslint": "^10.0.0", "vite": "^6.4.5"}},
  "node_modules/axios": {"version": "1.7.0", "dependencies": {"follow-redirects": "^1.15"}},
  "node_modules/follow-redirects": {"version": "1.15.0"},
  "node_modules/cronstrue": {"version": "3.30.0"},
  "node_modules/lucide-vue-next": {"version": "0.577.0"},
  "node_modules/eslint": {"version": "10.0.0", "dev": true, "dependencies": {"minimatch": "^9"}},
  "node_modules/minimatch": {"version": "9.0.0", "dev": true},
  "node_modules/vite": {"version": "6.4.5", "dev": true, "dependencies": {"esbuild": "^0.25"}},
  "node_modules/esbuild": {"version": "0.25.0", "dev": true}}}
EOF
echo '{"name": "@runr/workflows", "devDependencies": {"typescript": "^5.5.0"}}' > "$repo/sdk/node/package.json"
echo '{"name": "@runr/sandbox", "devDependencies": {"typescript": "^5.6.3"}}' > "$repo/sdk/sandbox/node/package.json"
echo '{"name": "@runr/sandbox", "lockfileVersion": 3, "packages": {"": {}, "node_modules/typescript": {"version": "5.6.3", "dev": true}}}' > "$repo/sdk/sandbox/node/package-lock.json"
cat > "$repo/.github/workflows/ci.yml" <<'EOF'
jobs:
  a:
    steps:
      - uses: actions/checkout@1111111111111111111111111111111111111111 # v7.0.1
      - uses: actions/setup-node@2222222222222222222222222222222222222222 # v7.1.0
EOF
printf 'runs:\n  steps:\n    - uses: actions/cache@v4\n' > "$repo/actions/deploy/action.yml"
cat > "$repo/CHANGELOG.md" <<'EOF'
# Changelog

## [Unreleased]

## [1.2.3] - 2026-01-02

- Something.
EOF
(cd "$repo" && git init -q -b main && git add -A && git commit -q -m base && git tag -a -m "RunR 1.2.3" v1.2.3)

# the GitHub repositories of the pins: local repositories with their tags
gh="$tmp/gh"
mkrepo() {
  local r="$gh/$1" t
  git init -q "$r"
  shift
  for t in "$@"; do git -C "$r" commit -q --allow-empty -m "$t"; git -C "$r" tag -a -m "$t" "$t"; done
}
mkrepo actions/checkout v7.0.1 v7.0.2 v8.0.0
mkrepo actions/setup-node v7.1.0
mkrepo actions/cache v4.2.0 v5.0.0
export DEPS_GITHUB_URL="file://${gh}"

# --- detection -------------------------------------------------------------------------------------
cat > "$FAKE/composer-minor.json" <<'EOF'
{"locked": [{"name": "laravel/framework", "direct-dependency": true, "version": "v12.3.1", "latest": "v12.3.2"},
            {"name": "phpunit/phpunit", "direct-dependency": true, "version": "11.5.3", "latest": "11.5.9"},
            {"name": "transitive/prod", "direct-dependency": false, "version": "1.0.0", "latest": "1.0.4"},
            {"name": "transitive/other", "direct-dependency": false, "version": "3.0.0", "latest": "3.1.0"}]}
EOF
cat > "$FAKE/composer-major.json" <<'EOF'
{"locked": [{"name": "laravel/framework", "version": "v12.3.1", "latest": "v13.42.0"}, {"name": "dedoc/scramble", "version": "v0.12.1", "latest": "v0.13.53"}]}
EOF
echo '{"advisories": {"transitive/prod": [{"advisoryId": "PKSA-1", "cve": "CVE-2026-1"}]}}' > "$FAKE/composer-audit.json"
printf '%s\n' 'axios 1.21.1' 'cronstrue 3.39.0' 'lucide-vue-next 1.0.0' 'eslint 10.5.0' 'vite 8.3.8' 'typescript 7.0.2' > "$FAKE/npm-latest"
printf '%s\n' 'axios@^1.7 1.21.1' 'cronstrue@3.30.0 3.30.0' 'lucide-vue-next@^0.577.0 0.577.0' 'eslint@^10.0.0 10.5.0' 'vite@^6.4.5 6.4.9' 'typescript@^5.6.3 5.9.3' 'typescript@^5.5.0 5.9.3' > "$FAKE/npm-wanted"
echo '{"vulnerabilities": {"follow-redirects": {"fixAvailable": true, "via": [{"url": "https://github.com/advisories/GHSA-aaaa-bbbb-cccc"}]}}}' > "$FAKE/npm-audit.json"
cat > "$FAKE/go-list.json" <<'EOF'
{"Path": "example.com/m", "Main": true}
{"Path": "github.com/a/lib", "Version": "v1.2.0", "Update": {"Version": "v1.3.0"}}
{"Path": "github.com/b/old", "Version": "v1.0.0", "Indirect": true, "Update": {"Version": "v1.1.0"}}
EOF
echo v2.0.1 > "$FAKE/proxy-github.com_a_lib_v2"
printf '%s\n' 1.26-alpine 1.27-alpine > "$FAKE/tags-brf-tech_runr_reqs_golang"
printf '%s\n' 3.20 3.21 3.22 4.0 > "$FAKE/tags-brf-tech_runr_reqs_alpine"
printf '%s\n' 0.58.1 0.58.3 0.60.0 > "$FAKE/tags-aquasecurity_trivy"
printf '%s\n' 1.95.0-slim-bookworm 1.99.1-slim-bookworm > "$FAKE/tags-brf-tech_runr_reqs_rust"
cat > "$tmp/bin/govulncheck" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' '{"finding": {"osv": "GO-2026-1", "fixed_version": "v1.26.11", "trace": [{"module": "stdlib", "function": "Parse"}]}}'
EOF
chmod +x "$tmp/bin/govulncheck"
# the pins' newer releases: a patch of helm's major and a new major, the next patch of a kubectl of
# the set and a Kubernetes minor newer than the set's newest, syft's minor, a new Scalar, a new
# release of the patched Spin shim, kind's patch and next 0.x line; Go's next line and its patch,
# Node's LTS lines
uptag helm/helm v3.16.4 v4.0.1
uptag kubernetes/kubernetes v1.35.10 v1.36.2 v1.35.11-rc.0 v1.38.1
uptag anchore/syft v1.19.0
uptag spinframework/containerd-shim-spin v0.27.0
uptag kubernetes-sigs/kind v0.31.1 v0.32.0
echo '@scalar/api-reference 1.78.0' >> "$FAKE/npm-all"
# web PATH — a file of the curl stub ($FAKE/web/<host>/<path>), from stdin
web() { mkdir -p "$(dirname "$FAKE/web/$1")"; cat > "$FAKE/web/$1"; }
web 'go.dev/dl/?mode=json' <<<'[{"version": "go1.27.4", "stable": true}, {"version": "go1.26.11", "stable": true}, {"version": "go1.28rc1", "stable": false}]'
web nodejs.org/dist/index.json <<<'[{"version": "v26.13.0", "lts": "Lithium"}, {"version": "v25.1.0", "lts": false}, {"version": "v24.22.2", "lts": "Krypton"}, {"version": "v22.21.0", "lts": "Jod"}]'
# PyPI and the crates.io index: a new major above a floor, one an upper bound or an exact pin leaves
# out, ones within (a yanked release and one without files do not count)
web pypi.org/pypi/pytest/json <<<'{"releases": {"7.4.4": [{"yanked": false}], "8.4.2": [{}], "9.0.1": [{}], "9.1.0rc1": [{}]}}'
web pypi.org/pypi/setuptools/json <<<'{"releases": {"68.0.0": [{}], "68.2.2": [{}]}}'
web pypi.org/pypi/httpx/json <<<'{"releases": {"0.25.0": [{}], "0.28.1": [{}]}}'
web pypi.org/pypi/websockets/json <<<'{"releases": {"13.0": [{}], "15.0.1": [{}], "16.0": [{"yanked": true}], "17.0": []}}'
web pypi.org/pypi/mcp/json <<<'{"releases": {"1.12.0": [{}], "1.20.0": [{}], "2.1.0": [{}]}}'
web pypi.org/pypi/uvicorn/json <<<'{"releases": {"0.30.0": [{}], "0.38.0": [{}]}}'
web pypi.org/pypi/transformers/json <<<'{"releases": {"5.20.0": [{}], "5.21.0": [{}]}}'
web index.crates.io/sp/in/spin-sdk <<<$'{"vers": "5.2.0", "yanked": false}\n{"vers": "6.0.0", "yanked": false}\n{"vers": "7.0.0", "yanked": true}'
web index.crates.io/an/yh/anyhow <<<'{"vers": "1.0.99", "yanked": false}'
printf '%s\n' '@spinframework/build-tools 3.0.0' >> "$FAKE/npm-latest"
printf '%s\n' '@spinframework/build-tools@^2.1.3 2.1.5' >> "$FAKE/npm-wanted"

out="$(bash "$repo/ci/check-package-releases.sh" --known 2>&1)" || fail "the fixture's manifests and pins are not known: ${out}"
rd="$tmp/releases"
RELEASES_REPORT_DIR="$rd" bash "$repo/ci/check-package-releases.sh" > "$tmp/detect.log" 2>&1 || fail "detection: $(tail -n 20 "$tmp/detect.log")"
titles="$(for f in "$rd"/*.md; do head -n 1 "$f"; done | sort)"
marker() { sed -n 's/.*<!-- deps-bot \(.*\) -->.*/\1/p' "$(grep -l -F -- "$1" "$rd"/*.md | head -n 1)"; }
c="$(marker 'deps: composer-api [')"
[ "$(jq -c '[.updates[] | [.name, .to]]' <<<"$c")" = '[["laravel/framework","v12.3.2"],["phpunit/phpunit","11.5.9"],["transitive/prod","1.0.4"]]' ] \
  && [ "$(jq -r .urgent <<<"$c")" = true ] || fail "composer's finding: ${c}"
grep -qxF 'deps: composer-api laravel/framework v13.x released (outside ^12.0)' <<<"$titles" \
  && grep -qxF 'deps: composer-api dedoc/scramble v0.13.x released (outside ^0.12)' <<<"$titles" || fail "composer's majors: ${titles}"
grep -qE '^deps: composer-api \[[0-9a-f]{8}\]: 3 updates: laravel/framework v12\.3\.2, phpunit/phpunit 11\.5\.9 and 1 more \(security\)$' <<<"$titles" || fail "composer's title: ${titles}"
n="$(marker 'deps: npm-vue [')"
[ "$(jq -c '[.updates[] | [.name, .to]]' <<<"$n")" = '[["axios","1.21.1"],["eslint","10.5.0"],["vite","6.4.9"],["follow-redirects","fixed"]]' ] || fail "npm's finding: ${n}"
grep -qxF 'deps: npm-vue lucide-vue-next 1.x released (outside ^0.577.0)' <<<"$titles" && grep -qxF 'deps: npm-vue vite 8.x released (outside ^6.4.5)' <<<"$titles" \
  || fail "npm's majors: ${titles}"
grep -q 'cronstrue' <<<"$titles" && fail "a held package was filed: ${titles}"
grep -qF 'held: cronstrue 3.39.0 (pinned exactly' "$tmp/detect.log" || fail "the hold is not named"
grep -qxF 'deps: npm-sdk-workflows typescript 7.x released (outside ^5.5.0)' <<<"$titles" || fail "the SDK without a lockfile: ${titles}"
g="$(marker 'deps: go-agent [')"
[ "$(jq -c '[.updates[] | [.name, .from, .to]]' <<<"$g")" = '[["github.com/a/lib","v1.2.0","v1.3.0"]]' ] || fail "go's finding (no indirect): ${g}"
grep -qxF 'deps: go-agent github.com/a/lib/v2 v2.x released (outside github.com/a/lib)' <<<"$titles" || fail "go's new major path: ${titles}"
[ "$(grep -c '^deps: go-toolchain go v1.26.11 released (outside go 1.26.9)$' <<<"$titles")" = 1 ] && ! grep -q '^deps: go-agent go ' <<<"$titles" \
  || fail "the Go toolchain: one finding for every module: ${titles}"
a="$(marker 'deps: actions [')"
[ "$(jq -c '[.updates[] | [.name, .from, .to, .sha, .old_sha]]' <<<"$a")" = "[[\"actions/checkout\",\"v7.0.1\",\"v7.0.2\",\"$(git -C "$gh/actions/checkout" rev-parse 'v7.0.2^{commit}')\",\"1111111111111111111111111111111111111111\"]]" ] \
  || fail "actions' finding: ${a}"
grep -qxF 'deps: actions actions/checkout v8.x released (outside v7)' <<<"$titles" && grep -qxF 'deps: actions actions/cache v5.x released (outside v4)' <<<"$titles" \
  || fail "actions' majors (a pin and a floating major): ${titles}"
d="$(marker 'deps: docker [')"
[ "$(jq -c '[.updates[] | [.name, .from, .to]] | sort' <<<"$d")" = '[["ghcr.io/aquasecurity/trivy","0.58.1","0.58.3"],["ghcr.io/brf-tech/runr/reqs/alpine","3.21","3.22"]]' ] \
  || fail "docker's finding: ${d}"
grep -qxF 'deps: docker ghcr.io/brf-tech/runr/reqs/golang 1.27-alpine released (outside 1.26-alpine)' <<<"$titles" \
  && grep -qxF 'deps: docker ghcr.io/brf-tech/runr/reqs/alpine 4.0 released (outside 3)' <<<"$titles" \
  && grep -qxF 'deps: docker ghcr.io/aquasecurity/trivy 0.60.x released (outside 0.58)' <<<"$titles" || fail "docker's lines: ${titles}"
grep -q 'reqs/rust' <<<"$titles" && fail "the held Rust image was filed"
# the weekly summary's inputs: a held release outside the constraint (a row "held (<reason>)"), a
# release outside the constraint's marker (its group's major versions issue), the groups read
jq -e '.group == "npm-vue" and .name == "cronstrue" and .version == "3.39.0" and .line == "3.x" and (.reason | test("pinned exactly"))' \
  "$rd/held/npm-vue--cronstrue.json" >/dev/null || fail "the held release outside the constraint: $(cat "$rd"/held/*.json 2>/dev/null)"
o="$(sed -n 's/.*<!-- deps-bot \(.*\) -->.*/\1/p' "$(grep -l -F 'deps: npm-vue vite 8.x released' "$rd"/*.md)")"
[ "$(jq -c '[.kind, .group, .name, .line, .constraint]' <<<"$o")" = '["outside","npm-vue","vite","8.x","^6.4.5"]' ] || fail "the release outside the constraint's marker: ${o}"
grep -qx npm-vue "$rd/checked" && grep -qx composer-api "$rd/checked" && grep -qx go-toolchain "$rd/checked" || fail "the groups read: $(cat "$rd/checked" 2>/dev/null)"
# the same set again: the same titles (filed once)
RELEASES_REPORT_DIR="$tmp/releases2" bash "$repo/ci/check-package-releases.sh" composer >/dev/null 2>&1 || true
[ "$(head -n 1 "$tmp/releases2"/deps-composer-api-[0-9a-f]*.md)" = "$(grep -F 'deps: composer-api [' <<<"$titles")" ] || fail "the same set has another title"
echo "ok - detection: one finding per group with its marker, an urgent one with its advisory's package, one per release line outside the constraint, holds left with their reasons, the Go toolchain once"

# the pins: one finding per group, a newer line, every release of the patched shim and a
# toolchain's newer line on their own; holds named
p="$(marker 'deps: pins-images [')"
[ "$(jq -c '[.updates[] | [.name, .from, .to]]' <<<"$p")" = '[["helm","v3.16.0","v3.16.4"],["kubectl-1.35","v1.35.9","v1.35.10"],["scalar","1.75.1","1.78.0"],["syft","1.18.1","1.19.0"]]' ] \
  && [ "$(jq -c '.updates[0].files' <<<"$p")" = '["api/Dockerfile","api/Dockerfile.prod"]' ] || fail "the images' pins: ${p}"
grep -qxF 'deps: pins-images helm v4.x released (outside v3)' <<<"$titles" && grep -qxF 'deps: pins-images kubectl-1.37 v1.38.x released (outside v1.37)' <<<"$titles" \
  && grep -qxF 'deps: pins-images containerd-shim-spin v0.27.x released (outside v0.26.0)' <<<"$titles" || fail "the images' pins outside their line: ${titles}"
# the older kubectls of the set: their patches only (Kubernetes 1.36 and 1.38 are no finding of theirs)
! grep -qE '^deps: pins-images kubectl-1\.3[135] ' <<<"$titles" || fail "an older kubectl of the set reported a newer minor: ${titles}"
grep -qF 'a person adds the next minor to its set' "$(grep -l -F 'deps: pins-images kubectl-1.37 v1.38.x' "$rd"/*.md)" || fail "the newest kubectl's finding does not say what to do"
p="$(marker 'deps: pins-ci [')"
[ "$(jq -c '[.updates[] | [.name, .from, .to]]' <<<"$p")" = '[["helm","v3.16.0","v3.16.4"],["kind","v0.31.0","v0.31.1"]]' ] \
  && [ "$(jq -c '.updates[1].files' <<<"$p")" = '[".github/workflows/tools.yml"]' ] || fail "the workflows' pins: ${p}"
grep -qxF 'deps: pins-ci kind v0.32.x released (outside v0.31)' <<<"$titles" && grep -qxF 'deps: go-toolchain go 1.27.x released (outside go 1.26.9)' <<<"$titles" \
  && grep -qxF 'deps: pins-ci node 26.x released (outside node-version 22)' <<<"$titles" && ! grep -qF 'go 1.26.x' <<<"$titles" \
  || fail "the workflows' pins and toolchains outside their line: ${titles}"
grep -qF 'held: protoc (upstream' "$tmp/detect.log" && grep -qF 'held: php (' "$tmp/detect.log" || fail "the pins' holds are not named"
# the places of a pin disagree: the check fails and says so
echo '      HELM_VERSION: v3.15.0' >> "$repo/.github/workflows/ci.yml"
rc=0
RELEASES_REPORT_DIR="$tmp/releases3" bash "$repo/ci/check-package-releases.sh" pins > "$tmp/pins.log" 2>&1 || rc=$?
git -C "$repo" checkout -q -- .github/workflows/ci.yml
[ "$rc" = 1 ] && grep -qF 'pins-ci: helm: its places pin different versions' "$tmp/pins.log" || fail "places that disagree: rc ${rc}, $(tail -n 5 "$tmp/pins.log")"
# a version pin no row knows: --known names it
echo 'ARG FOO_VERSION=1.0' >> "$repo/proxy/Dockerfile"
echo '      NEW_TOOL_VERSION: 1.2.3' >> "$repo/.github/workflows/ci.yml"
rc=0
out="$(bash "$repo/ci/check-package-releases.sh" --known 2>&1)" || rc=$?
git -C "$repo" checkout -q -- proxy/Dockerfile .github/workflows/ci.yml
[ "$rc" = 1 ] && grep -qF 'proxy/Dockerfile: FOO_VERSION (1.0) is a version pin' <<<"$out" && grep -qF '.github/workflows/ci.yml: NEW_TOOL_VERSION (1.2.3) is a version pin' <<<"$out" \
  || fail "an unknown pin: rc ${rc}, ${out}"
echo "ok - the pins: one finding per group (the images', the workflows'), a newer line on its own, every release of the patched shim, a toolchain's line (Go's as go-toolchain); places that disagree and an unknown pin fail the check"

# the Python SDKs and the examples: without a lockfile only what the constraint leaves out
grep -qxF 'deps: pypi-sdk-workflows pytest 9.x released (outside >=7)' <<<"$titles" && ! grep -qF setuptools <<<"$titles" \
  && grep -qxF 'deps: pypi-sdk-sandbox websockets 15.x released (outside >=13)' <<<"$titles" && ! grep -qF httpx <<<"$titles" \
  && grep -qxF 'deps: pypi-example-mcp mcp 2.x released (outside >=1.12,<2)' <<<"$titles" && ! grep -qF uvicorn <<<"$titles" \
  && grep -qxF 'deps: pypi-example-hf-weights transformers 5.x released (outside ==5.20.0)' <<<"$titles" \
  && grep -qxF 'deps: cargo-example-wasm-rust spin-sdk 6.x released (outside 5.2.0)' <<<"$titles" && ! grep -qF anyhow <<<"$titles" \
  && grep -qxF 'deps: npm-example-wasm-js @spinframework/build-tools 3.x released (outside ^2.1.3)' <<<"$titles" \
  && ! grep -qE '^deps: (pypi|cargo|npm-example)-[a-z-]+ \[' <<<"$titles" || fail "the Python and example findings: ${titles}"
! grep -qF 'pypi.org/pypi/runr-workflows' "$FAKE/curl.log" && grep -qF 'held: runr-workflows (' "$tmp/detect.log" && grep -qF 'held: @runr/workflows (' "$tmp/detect.log" \
  || fail "RunR's own SDKs in the examples are looked up or not named"
grep -q '^deps: go-example-q \[' <<<"$titles" && ! grep -q '^deps: go-example-wasm \[' <<<"$titles" \
  && grep -qxF 'deps: go-example-wasm github.com/a/lib/v2 v2.x released (outside github.com/a/lib)' <<<"$titles" && [ ! -e "$repo/examples/wasm/go/go.sum" ] \
  || fail "a go.mod without go.sum: only its majors, read-only: ${titles}"
echo "ok - the Python SDKs, the examples' requirements, Cargo.toml and package.json without a lockfile: only a release outside the constraint (a floor's next major); a go.mod without go.sum: only its majors"

# --- the plan ----------------------------------------------------------------------------------------
older="$(sed 's/"to": "v12.3.2"/"to": "v12.3.0"/' <<<"$c")"
jq -n --arg t1 "$(grep -F 'deps: composer-api [' <<<"$titles")" --arg b1 "x <!-- deps-bot ${c} -->" --arg b2 "<!-- deps-bot ${older} -->" '[
  {number: 30, title: "deps: composer-api [00000000]: 3 updates: …", labels: [], body: $b2},
  {number: 31, title: $t1, labels: [], body: $b1},
  {number: 32, title: "deps: composer-api laravel/framework v13.x released (outside ^12.0)", labels: [], body: ""},
  {number: 33, title: "deps: npm-vue [11111111]: 1 update: axios 1.21.1", labels: [], body: "no marker"}]' > "$tmp/issues.json"
plan="$(bash "$repo/ci/deps-plan.sh" "$tmp/issues.json")"
[ "$(jq -c '[.tests[] | [.issue, .dep, (.updates | length), .urgent]]' <<<"$plan")" = '[[31,"composer-api",3,true]]' ] || fail "the plan's tests: ${plan}"
[ "$(jq -c '[.superseded[] | [.issue, .by]]' <<<"$plan")" = '[[30,31]]' ] && [ "$(jq -c '[.attention[].issue]' <<<"$plan")" = '[32]' ] \
  && jq -e '.skipped[] | select(.issue == 33) | .reason | test("no deps-bot marker")' >/dev/null <<<"$plan" || fail "the plan: ${plan}"
echo "ok - the plan: a group's newest finding with its updates (the older ones superseded), one outside its constraint labelled, one without a marker skipped"

# --- the bumps ----------------------------------------------------------------------------------------
# bump GROUP UPDATES_JSON — ci/deps-bump.sh on a clean fixture; the report in $tmp/r-GROUP
bump() {
  git -C "$repo" checkout -q -f main && git -C "$repo" clean -qfd
  echo "{\"updates\": $2, \"urgent\": false}" > "$tmp/u.json"
  rm -rf "$tmp/r-$1"
  rc=0
  (cd "$repo" && bash ci/deps-bump.sh "$1" 0a1b2c3d --updates="$tmp/u.json" --report="$tmp/r-$1") > "$tmp/bump.log" 2>&1 || rc=$?
}
# composer: a prod package moves → release; only dev ones → next
printf '%s\n' 'laravel/framework v12.3.2' 'phpunit/phpunit 11.5.9' 'transitive/prod 1.0.4' > "$FAKE/composer-versions"
bump composer-api '[{"name": "laravel/framework", "from": "v12.3.1", "to": "v12.3.2"}, {"name": "phpunit/phpunit", "from": "11.5.3", "to": "11.5.9"}]'
[ "$rc" = 0 ] || fail "composer bump: $(tail -n 20 "$tmp/bump.log")"
[ "$(cat "$tmp/r-composer-api/path")" = release ] && [ "$(git -C "$repo" diff --name-only | sort | paste -sd ' ')" = "CHANGELOG.md api/composer.lock" ] \
  || fail "composer: path $(cat "$tmp/r-composer-api/path"), $(git -C "$repo" diff --name-only)"
[ "$(jq -c '[.[] | [.suite, .job, .command]]' "$tmp/r-composer-api/suites.json")" = '[["e2e","kind","test"],["upgrade","kind","test-helm-upgrade --from=v1.2.3"]]' ] \
  && [ "$(cat "$tmp/r-composer-api/unit")" = 1 ] || fail "composer's suites: $(cat "$tmp/r-composer-api/suites.json")"
grep -qF -- '- **API Composer packages moved** (minor and patch updates): ' "$repo/CHANGELOG.md" && grep -qF 'laravel/framework v12.3.1 → v12.3.2' "$repo/CHANGELOG.md" \
  || fail "composer's changelog line: $(sed -n '3,9p' "$repo/CHANGELOG.md")"
head -n 1 "$tmp/r-composer-api/commit-msg.txt" | grep -qx 'fix(api): API Composer packages (3 updates)' || fail "composer's subject: $(head -n 1 "$tmp/r-composer-api/commit-msg.txt")"
printf '%s\n' 'phpunit/phpunit 11.5.9' 'transitive/dev 2.0.1' > "$FAKE/composer-versions"
bump composer-api '[{"name": "phpunit/phpunit", "from": "11.5.3", "to": "11.5.9"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-composer-api/path")" = next ] && [ "$(jq length "$tmp/r-composer-api/suites.json")" = 0 ] \
  || fail "a dev-only composer bump: rc ${rc}, path $(cat "$tmp/r-composer-api/path" 2>/dev/null), $(tail -n 5 "$tmp/bump.log")"
# not reproducible, a moved constraint, a held package: a person, nothing left changed
echo 0 > "$FAKE/flaky"
bump composer-api '[{"name": "phpunit/phpunit", "from": "11.5.3", "to": "11.5.9"}]'
rm -f "$FAKE/flaky"
[ "$rc" = 2 ] && grep -qF 'does not come out the same' "$tmp/r-composer-api/summary.md" && [ -z "$(git -C "$repo" status --porcelain --untracked-files=no)" ] \
  || fail "a lock that does not regenerate: rc ${rc}, $(cat "$tmp/r-composer-api/summary.md" 2>/dev/null), $(git -C "$repo" status --porcelain)"
touch "$FAKE/composer-constraint"
bump composer-api '[{"name": "laravel/framework", "from": "v12.3.1", "to": "v12.3.2"}]'
rm -f "$FAKE/composer-constraint"
[ "$rc" = 2 ] && grep -qF 'a constraint moved' "$tmp/r-composer-api/summary.md" && [ -z "$(git -C "$repo" status --porcelain --untracked-files=no)" ] || fail "a moved constraint: rc ${rc}"
bump npm-vue '[{"name": "cronstrue", "from": "3.30.0", "to": "3.39.0"}]'
[ "$rc" = 2 ] && grep -qF 'is held' "$tmp/r-npm-vue/summary.md" || fail "a held package moved: rc ${rc}"
FAKE_NPM_VERSION=10.9.4 bump npm-vue '[{"name": "axios", "from": "1.7.0", "to": "1.21.1"}]'
[ "$rc" = 2 ] && grep -qF 'npm 11 or newer' "$tmp/r-npm-vue/summary.md" || fail "npm 10 made the bump: rc ${rc}"
echo "ok - composer: only composer.lock moves, a prod package releases and a dev one lands on next; a lock that does not regenerate, a moved constraint or a held package goes to a person, nothing left changed"

# npm: a package of the bundle or the build → release; test tooling only → next
printf '%s\n' 'node_modules/eslint 10.5.0' 'node_modules/minimatch 9.0.5' > "$FAKE/npm-versions"
bump npm-vue '[{"name": "eslint", "from": "10.0.0", "to": "10.5.0"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-npm-vue/path")" = next ] && [ "$(git -C "$repo" diff --name-only | sort | paste -sd ' ')" = "CHANGELOG.md vue/package-lock.json" ] \
  && [ "$(jq -c '[.[].command]' "$tmp/r-npm-vue/suites.json")" = '["ui"]' ] && [ ! -s "$tmp/r-npm-vue/unit" ] \
  || fail "npm test tooling: rc ${rc}, path $(cat "$tmp/r-npm-vue/path" 2>/dev/null), $(cat "$tmp/r-npm-vue/suites.json" 2>/dev/null), $(tail -n 5 "$tmp/bump.log")"
printf '%s\n' 'node_modules/esbuild 0.25.9' > "$FAKE/npm-versions"
bump npm-vue '[{"name": "vite", "from": "6.4.5", "to": "6.4.9"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-npm-vue/path")" = release ] && [ "$(jq -c '[.[].command]' "$tmp/r-npm-vue/suites.json")" = '["ui","ui-stack"]' ] \
  || fail "npm build tooling (vite's esbuild): rc ${rc}, path $(cat "$tmp/r-npm-vue/path" 2>/dev/null)"
printf '%s\n' 'node_modules/follow-redirects 1.15.9' > "$FAKE/npm-versions"
bump npm-vue '[{"name": "axios", "from": "1.7.0", "to": "1.21.1"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-npm-vue/path")" = release ] || fail "npm bundle (axios' follow-redirects): rc ${rc}"
echo "ok - npm: only package-lock.json moves; the bundle's and the build's packages release, test tooling lands on next"

# go: go.mod and go.sum; gateway-auth's own version moves with it, not a line of the past or the legacy name
bump go-gateway-auth '[{"name": "github.com/a/lib", "from": "v1.2.0", "to": "v1.3.0"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-go-gateway-auth/path")" = release ] || fail "go bump: rc ${rc}, $(tail -n 10 "$tmp/bump.log")"
grep -qF $'\tgithub.com/a/lib v1.3.0' "$repo/runtime/gateway-auth/go.mod" && [ "$(grep -c 'ARG VERSION=1.0.1' "$repo/runtime/gateway-auth/Dockerfile")" = 2 ] \
  && grep -qF 'gateway-auth:1.0.1' "$repo/api/config/api_product.php" && grep -qxF 'The gateway runs ghcr.io/brf-tech/runr/gateway-auth:1.0.1.' "$repo/docs/api-product.md" \
  && grep -qF 'Measured on RunR 0.6.8 with ghcr.io/brf-tech/runr/gateway-auth:1.0.0.' "$repo/docs/api-product.md" && grep -qF 'runr-gateway-auth:1.0.0 maps' "$repo/docs/api-product.md" \
  && grep -qF "=> 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0'" "$repo/api/tests/RegistryMoveTest.php" \
  || fail "gateway-auth's own version: $(git -C "$repo" diff)"
[ "$(jq -c '[.[].command]' "$tmp/r-go-gateway-auth/suites.json")" = '["go:runtime/gateway-auth","image:runtime/gateway-auth/Dockerfile","test-api-product"]' ] \
  || fail "go's suites: $(cat "$tmp/r-go-gateway-auth/suites.json")"
bump go-cli '[{"name": "github.com/a/lib", "from": "v1.2.0", "to": "v1.3.0"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-go-cli/path")" = next ] && [ "$(git -C "$repo" diff --name-only | sort | paste -sd ' ')" = "CHANGELOG.md cli/go.mod cli/go.sum" ] \
  || fail "the CLI's modules: next only: rc ${rc}"
echo "ok - go: go.mod and go.sum; a region image's module releases and moves the image's own version (not the past, not the legacy name); the CLI's lands on next"

# actions: the commit and its comment, on next only
sha="$(git -C "$gh/actions/checkout" rev-parse 'v7.0.2^{commit}')"
bump actions "[{\"name\": \"actions/checkout\", \"from\": \"v7.0.1\", \"to\": \"v7.0.2\", \"sha\": \"${sha}\", \"old_sha\": \"1111111111111111111111111111111111111111\"}]"
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-actions/path")" = next ] && grep -qF "actions/checkout@${sha} # v7.0.2" "$repo/.github/workflows/ci.yml" \
  && [ "$(jq -c '[.[].command]' "$tmp/r-actions/suites.json")" = '["actionlint"]' ] || fail "actions bump: rc ${rc}, $(tail -n 5 "$tmp/bump.log")"
# docker: the tag (and the digest of a pinned one) in the Dockerfiles; a shipped image's own version
bump docker '[{"name": "ghcr.io/brf-tech/runr/reqs/alpine", "from": "3.21", "to": "3.22", "files": ["runtime/gateway-auth/Dockerfile", "proxy/Dockerfile"]}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-docker/path")" = release ] && grep -qF 'reqs/alpine:3.22' "$repo/proxy/Dockerfile" && grep -qF 'ARG VERSION=1.0.1' "$repo/runtime/gateway-auth/Dockerfile" \
  || fail "docker bump: rc ${rc}, $(tail -n 5 "$tmp/bump.log")"
[ "$(jq -c '[.[].command]' "$tmp/r-docker/suites.json")" = '["image:runtime/gateway-auth/Dockerfile","test-api-product","image:proxy/Dockerfile"]' ] || fail "docker's suites: $(cat "$tmp/r-docker/suites.json")"
bump docker '[{"name": "ghcr.io/brf-tech/runr/reqs/alpine", "from": "3.21", "to": "3.22", "files": ["proxy/Dockerfile"]}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-docker/path")" = next ] || fail "the dev stack's Dockerfile: next only: rc ${rc}"
# a tag an ARG holds (FROM …/trivy:${TRIVY_VERSION}): the ARG's default; supply-chain's own version
bump docker '[{"name": "ghcr.io/aquasecurity/trivy", "from": "0.58.1", "to": "0.58.3", "files": ["runtime/supply-chain/Dockerfile"]}]'
[ "$rc" = 0 ] && grep -qx 'ARG TRIVY_VERSION=0.58.3' "$repo/runtime/supply-chain/Dockerfile" && grep -qF 'SUPPLY_CHAIN_VERSION:-1.0.1}' "$repo/runtime/lib/test-supply-chain.sh" \
  || fail "an ARG's base: rc ${rc}, $(cat "$tmp/r-docker/summary.md" 2>/dev/null)"
bump docker '[{"name": "ghcr.io/brf-tech/runr/reqs/alpine", "from": "3.21", "to": "3.22; rm -rf /", "files": ["proxy/Dockerfile"]}]'
[ "$rc" = 1 ] || fail "an update the bot does not take as is"
echo "ok - actions: the pin's commit and comment, on next only; docker: the Dockerfiles' tags, a shipped image's own version, the dev stack's on next; a malformed update refused"

# pins: the version at every place, and the published checksums of the old version replaced with
# the new one's (helm's .sha256sum per arch; Scalar's file in the npm tarball the registry's
# integrity checks: its sha256 in the Dockerfile, its sha384 in the page's integrity)
for a in amd64:1 arm64:2; do
  web "get.helm.sh/helm-v3.16.0-linux-${a%:*}.tar.gz.sha256sum" <<<"$(sha a "${a#*:}")  helm-v3.16.0-linux-${a%:*}.tar.gz"
  web "get.helm.sh/helm-v3.16.4-linux-${a%:*}.tar.gz.sha256sum" <<<"$(sha b "${a#*:}")  helm-v3.16.4-linux-${a%:*}.tar.gz"
done
for v in 1.75.1 1.78.0; do
  t="registry.npmjs.org/@scalar/api-reference/-/api-reference-${v}.tgz"
  mkdir -p "$(dirname "$FAKE/web/$t")"
  tar -czf "$FAKE/web/$t" -C "$tmp/npm/$v" package
  web "registry.npmjs.org/@scalar/api-reference/${v}" <<<"{\"dist\": {\"tarball\": \"https://${t}\", \"integrity\": \"sha512-$(openssl dgst -sha512 -binary "$FAKE/web/$t" | base64 -w0)\"}}"
done
new_js="$tmp/npm/1.78.0/package/dist/browser/standalone.js"
bump pins-images '[{"name": "helm", "from": "v3.16.0", "to": "v3.16.4", "files": ["api/Dockerfile", "api/Dockerfile.prod"]}, {"name": "scalar", "from": "1.75.1", "to": "1.78.0"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-pins-images/path")" = release ] || fail "the images' pins: rc ${rc}, $(cat "$tmp/r-pins-images/summary.md" 2>/dev/null) $(tail -n 5 "$tmp/bump.log")"
for f in api/Dockerfile.prod api/Dockerfile; do
  grep -qx 'ARG HELM_VERSION=v3.16.4' "$repo/$f" && grep -qF "amd64) HELM_SHA=$(sha b 1) ;; arm64) HELM_SHA=$(sha b 2) ;;" "$repo/$f" || fail "helm in ${f}: $(git -C "$repo" diff -- "$f")"
done
grep -qx 'ARG SCALAR_VERSION=1.78.0' "$repo/api/Dockerfile.prod" && grep -qx "ARG SCALAR_SHA256=$(sha256sum < "$new_js" | cut -d' ' -f1)" "$repo/api/Dockerfile.prod" \
  && grep -qF '@scalar/api-reference@1.78.0"' "$repo/api/resources/views/vendor/scramble/docs.blade.php" \
  && grep -qF "integrity=\"sha384-$(openssl dgst -sha384 -binary "$new_js" | base64 -w0)\"" "$repo/api/resources/views/vendor/scramble/docs.blade.php" \
  || fail "Scalar: $(git -C "$repo" diff -- api/Dockerfile.prod api/resources)"
grep -qx '      HELM_VERSION: v3.16.0' "$repo/.github/workflows/tools.yml" || fail "the images' helm moved the workflows' too"
[ "$(jq -c '[.[].command]' "$tmp/r-pins-images/suites.json")" = '["image:api/Dockerfile.prod","test","test-helm-upgrade --from=v1.2.3","image:api/Dockerfile"]' ] \
  && [ "$(cat "$tmp/r-pins-images/unit")" = 1 ] || fail "the images' pins' suites: $(cat "$tmp/r-pins-images/suites.json")"
tr -s ' \n' ' ' < "$repo/CHANGELOG.md" | grep -qF -- '- **Image tool pins moved** (minor and patch updates): helm v3.16.0 → v3.16.4, scalar 1.75.1 → 1.78.0.' \
  || fail "the pins' changelog line: $(sed -n '3,9p' "$repo/CHANGELOG.md")"
# one kubectl of the set: its version and its published checksums (dl.k8s.io's .sha256 per arch);
# the others of the set stay
for a in amd64:1 arm64:2; do
  web "dl.k8s.io/release/v1.35.9/bin/linux/${a%:*}/kubectl.sha256" <<<"$(sha 7 "${a#*:}")"
  web "dl.k8s.io/release/v1.35.10/bin/linux/${a%:*}/kubectl.sha256" <<<"$(sha 8 "${a#*:}")"
done
bump pins-images '[{"name": "kubectl-1.35", "from": "v1.35.9", "to": "v1.35.10", "files": ["api/Dockerfile", "api/Dockerfile.prod"]}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-pins-images/path")" = release ] || fail "a kubectl of the set: rc ${rc}, $(cat "$tmp/r-pins-images/summary.md" 2>/dev/null) $(tail -n 5 "$tmp/bump.log")"
kv37="$(git -C "$repo" show main:api/Dockerfile.prod | sed -n 's/^ARG KUBECTL_1_37_VERSION=//p')"
[ -n "$kv37" ] || fail "the fixture pins no kubectl 1.37"
for f in api/Dockerfile.prod api/Dockerfile; do
  grep -qx 'ARG KUBECTL_1_35_VERSION=v1.35.10' "$repo/$f" \
    && grep -qF "amd64) set -- 1.35 \"\$KUBECTL_1_35_VERSION\" $(sha 8 1) 1.37 \"\$KUBECTL_1_37_VERSION\" $(sha 7 3) ;; arm64) set -- 1.35 \"\$KUBECTL_1_35_VERSION\" $(sha 8 2) 1.37" "$repo/$f" \
    && grep -qx "ARG KUBECTL_1_37_VERSION=${kv37}" "$repo/$f" || fail "kubectl 1.35 in ${f}: $(git -C "$repo" diff -- "$f")"
done
# a pin the image's build checks against the release's checksums file: the file must be published;
# supply-chain's own version moves a patch
web github.com/anchore/syft/releases/download/v1.19.0/syft_1.19.0_checksums.txt <<<"$(sha f 1)  syft_1.19.0_linux_amd64.tar.gz
$(sha f 2)  syft_1.19.0_linux_arm64.tar.gz"
bump pins-images '[{"name": "syft", "from": "1.18.1", "to": "1.19.0"}]'
[ "$rc" = 0 ] && grep -qx 'ARG SYFT_VERSION=1.19.0' "$repo/runtime/supply-chain/Dockerfile" && grep -qF 'SUPPLY_CHAIN_VERSION:-1.0.1}' "$repo/runtime/lib/test-supply-chain.sh" \
  && [ "$(jq -c '[.[].command]' "$tmp/r-pins-images/suites.json")" = '["image:runtime/supply-chain/Dockerfile","test-supply-chain"]' ] \
  || fail "syft: rc ${rc}, $(cat "$tmp/r-pins-images/summary.md" 2>/dev/null) $(cat "$tmp/r-pins-images/suites.json" 2>/dev/null)"
# a checksums file that is not published (a 404, at once): a person, nothing left changed
: > "$FAKE/curl.log"
bump pins-images '[{"name": "cosign", "from": "2.4.1", "to": "2.4.2"}]'
[ "$rc" = 2 ] && grep -qF 'no published checksum' "$tmp/r-pins-images/summary.md" && [ -z "$(git -C "$repo" status --porcelain --untracked-files=no)" ] \
  && [ "$(grep -c 'cosign/releases/download/v2.4.2/cosign_checksums.txt' "$FAKE/curl.log")" = 1 ] && ! grep -qF api.github.com "$FAKE/curl.log" \
  || fail "a missing checksums file: rc ${rc}, $(cat "$tmp/r-pins-images/summary.md" 2>/dev/null), $(git -C "$repo" status --porcelain)"
# every release of the patched shim is a person's
bump pins-images '[{"name": "containerd-shim-spin", "from": "v0.26.0", "to": "v0.27.0"}]'
[ "$rc" = 2 ] && grep -qF "a person's" "$tmp/r-pins-images/summary.md" || fail "the patched shim moved: rc ${rc}"
# a workflow pin: next only, actionlint; its new checksum's download answers 504, so it comes through
# the GitHub API's asset endpoint
web github.com/kubernetes-sigs/kind/releases/download/v0.31.0/kind-linux-amd64.sha256sum <<<"$(sha c 1)  kind-linux-amd64"
mkdir -p "$FAKE/web/github.com/kubernetes-sigs/kind/releases/download/v0.31.1"
echo 504 > "$FAKE/web/github.com/kubernetes-sigs/kind/releases/download/v0.31.1/kind-linux-amd64.sha256sum.status"
web api.github.com/repos/kubernetes-sigs/kind/releases/tags/v0.31.1 <<<'{"assets": [{"name": "kind-linux-amd64", "url": "https://api.github.com/repos/kubernetes-sigs/kind/releases/assets/6"}, {"name": "kind-linux-amd64.sha256sum", "url": "https://api.github.com/repos/kubernetes-sigs/kind/releases/assets/7"}]}'
web api.github.com/repos/kubernetes-sigs/kind/releases/assets/7 <<<"$(sha d 1)  kind-linux-amd64"
: > "$FAKE/curl.log"
bump pins-ci '[{"name": "kind", "from": "v0.31.0", "to": "v0.31.1"}]'
[ "$rc" = 0 ] && [ "$(cat "$tmp/r-pins-ci/path")" = next ] && grep -qx '      KIND_VERSION: v0.31.1' "$repo/.github/workflows/tools.yml" \
  && grep -qx "      KIND_SHA256: $(sha d 1)" "$repo/.github/workflows/tools.yml" && [ "$(jq -c '[.[].command]' "$tmp/r-pins-ci/suites.json")" = '["actionlint"]' ] \
  || fail "kind: rc ${rc}, $(cat "$tmp/r-pins-ci/summary.md" 2>/dev/null) $(tail -n 5 "$tmp/bump.log")"
[ "$(grep -n -e 'download/v0.31.1/kind-linux-amd64.sha256sum$' -e 'releases/tags/v0.31.1$' -e 'releases/assets/7$' "$FAKE/curl.log" | cut -d: -f2- | paste -sd ' ')" \
  = "https://github.com/kubernetes-sigs/kind/releases/download/v0.31.1/kind-linux-amd64.sha256sum https://api.github.com/repos/kubernetes-sigs/kind/releases/tags/v0.31.1 https://api.github.com/repos/kubernetes-sigs/kind/releases/assets/7" ] \
  || fail "the 504 did not go through the API's asset endpoint: $(cat "$FAKE/curl.log")"
# the new release publishes no checksum of the pinned file: a person, nothing left changed
web github.com/kubernetes-sigs/cloud-provider-kind/releases/download/v0.10.0/cloud-provider-kind_0.10.0_checksums.txt <<<"$(sha e 1)  cloud-provider-kind_0.10.0_linux_amd64.tar.gz"
web github.com/kubernetes-sigs/cloud-provider-kind/releases/download/v0.10.1/cloud-provider-kind_0.10.1_checksums.txt <<<"$(sha e 2)  cloud-provider-kind_0.10.1_darwin_amd64.tar.gz"
bump pins-ci '[{"name": "cloud-provider-kind", "from": "v0.10.0", "to": "v0.10.1"}]'
[ "$rc" = 2 ] && grep -qF 'has no sha256 of cloud-provider-kind_0.10.1_linux_amd64.tar.gz' "$tmp/r-pins-ci/summary.md" \
  && [ -z "$(git -C "$repo" status --porcelain --untracked-files=no)" ] || fail "a checksum not published: rc ${rc}, $(cat "$tmp/r-pins-ci/summary.md" 2>/dev/null)"
# a group without a lockfile has nothing to bump
bump pypi-sdk-workflows '[{"name": "pytest", "from": "7.4.4", "to": "9.0.1"}]'
[ "$rc" = 2 ] && grep -qF 'keeps no lockfile' "$tmp/r-pypi-sdk-workflows/summary.md" || fail "a Python bump: rc ${rc}"
echo "ok - pins: the version at every place and its published checksums (a sha256sum per arch, an npm file's sha256 and sha384), a checksums file the build reads, the image's own version, next only for a workflow's; a 504 through the API's asset endpoint; a checksum not published or the patched shim: a person, nothing left changed"

# --- a bump of next only: made on next, landed on the origin's next -----------------------------------
src="$tmp/src"
git clone -q "$repo" "$src"
git -C "$src" checkout -q -f main
git -C "$src" checkout -q -b next
echo "next only" > "$src/next.txt"
(cd "$src" && git add -A && git commit -q -m "feat: next")
origin="$tmp/origin.git"
git clone -q --bare "$src" "$origin"
public="$tmp/public.git"
git clone -q --bare "$origin" "$public"
clone="$tmp/clone"
git clone -q --no-checkout "$origin" "$clone"
dir="$tmp/deps"
mkdir -p "$dir"
sha="$(git -C "$gh/actions/checkout" rev-parse 'v7.0.2^{commit}')"
jq -n --arg sha "$sha" '[{issue: 40, dep: "actions", version: "0a1b2c3d", updates: [{name: "actions/checkout", from: "v7.0.1", to: "v7.0.2", sha: $sha, old_sha: "1111111111111111111111111111111111111111"}], urgent: false}]' > "$tmp/tests.json"
bash "$root/ci/deps-run.sh" bumps "$tmp/tests.json" "$clone" "$dir" "$public" https://example.test/run/2 > "$tmp/bumps.log" 2>&1 || fail "bumps: $(tail -n 20 "$tmp/bumps.log")"
next_sha="$(git -C "$clone" rev-parse refs/remotes/origin/next)"
head="$(cat "$dir/bump/actions/head")"
[ "$(cat "$dir/bump/actions/path")" = next ] && [ "$(git -C "$clone" rev-parse "${head}^")" = "$next_sha" ] && [ "$(git -C "$clone" rev-parse deps/actions)" = "$head" ] \
  || fail "the bump of next only is not one commit on next: $(tail -n 10 "$tmp/bumps.log")"
[ "$(git -C "$clone" log -1 --format='%an <%ae>|%(trailers:key=RunR-Deps-Bot,valueonly)' "$head" | sed '/^$/d')" = "${DEPS_BOT_NAME} <${DEPS_BOT_EMAIL}>|actions 0a1b2c3d" ] \
  || fail "the bump's identity and trailer: $(git -C "$clone" log -1 --format='%an <%ae>%n%B' "$head")"
[ "$(jq -c '[.[].dep]' "$dir/matrix-checks.json")|$(jq -c . "$dir/matrix-unit.json")|$(jq -c '[.[].dep]' "$dir/matrix-shell.json")|$(jq -c . "$dir/matrix-suites.json")" = '["actions"]|[]|["actions"]|[]' ] \
  || fail "the jobs: $(cat "$dir"/matrix-*.json)"
# green: its check and runtime/test passed by their jobs and their results
for s in shell actionlint; do mkdir -p "$dir/results/deps-result-actions-$s"; echo success > "$dir/results/deps-result-actions-$s/status"; echo 0 > "$dir/results/deps-result-actions-$s/failed"; done
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/actions")" = green ] || fail "outcome: $(cat "$dir/outcome/actions")"
# the run's green bumps of next only together: one here, its tree tested alone, no second phase
bash "$root/ci/deps-run.sh" batch "$dir" "$clone" > "$tmp/batch.log" 2>&1 || fail "batch: $(tail -n 20 "$tmp/batch.log")"
[ "$(cat "$dir/batch/next/members")" = actions ] && [ ! -e "$dir/batch/next/tested" ] && [ "$(cat "$dir/batch/matrix-shell.json")" = '[]' ] || fail "the batch of next: $(cat "$tmp/batch.log")"
[ "$(bash "$root/ci/deps-run.sh" pick "$dir" "$clone")" = "$(printf 'release=false\nland=true')" ] && grep -qx "LAND=${head}" "$dir/land/release.env" \
  && grep -qx "NEXT_BASE=${next_sha}" "$dir/land/release.env" && grep -qx 'DEPS=actions:0a1b2c3d' "$dir/land/release.env" || fail "pick: $(cat "$dir/land/release.env")"
export ORIGIN_REMOTE="$origin"
bash "$root/ci/deps-release.sh" land "$dir/land" --dry-run > /dev/null
[ "$(git --git-dir="$origin" rev-parse refs/heads/next)" = "$next_sha" ] && grep -q '^land .*dry run' "$dir/land/steps" || fail "a dry run landed"
: > "$dir/land/steps"
bash "$root/ci/deps-release.sh" land "$dir/land" > /dev/null
[ "$(git --git-dir="$origin" rev-parse refs/heads/next)" = "$head" ] && [ "$(git --git-dir="$origin" rev-parse refs/heads/main)" = "$(git -C "$repo" rev-parse main)" ] \
  && grep -q '^OK: 1 commit' "$dir/land/attribution.txt" || fail "the landing: next $(git --git-dir="$origin" rev-parse refs/heads/next), $(cat "$dir/land/failed" "$dir/land/attribution.txt" 2>/dev/null)"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/2 1
grep -qF 'Landed on next' "$dir/comments/40.md" && [ "$(cut -f1 "$dir/actions" | paste -sd ' ')" = "label comment close" ] || fail "the landing's actions: $(cat "$dir/actions")"
# a next that moved: the bump is made again on it (the run's dispatch, attempt + 1)
git --git-dir="$origin" update-ref refs/heads/next "$next_sha"
(cd "$src" && echo more >> next.txt && git commit -q -am more && git push -q -f "$origin" next)
rm -f "$dir/land/steps" "$dir/land/moved" "$dir/land/failed"
bash "$root/ci/deps-release.sh" land "$dir/land" > /dev/null
grep -qF "origin's next moved" "$dir/land/moved" && [ "$(git --git-dir="$origin" rev-parse refs/heads/next)" = "$(git -C "$src" rev-parse next)" ] || fail "a moved next: $(cat "$dir/land/moved" 2>/dev/null)"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/3 1
grep -qxF "$(printf 'dispatch\t40\t2')" "$dir/actions" && grep -qF 'next moved meanwhile' "$dir/comments/40.md" || fail "the moved landing's actions: $(cat "$dir/actions")"
echo "ok - a bump of next only: one commit of the bot (its trailer) on next, its check and runtime/test, landed on the origin's next as a fast-forward (not in a dry run, not on a next that moved: tested again), the issue closed"

# --- the release takes a console npm bump: vue/package.json and package-lock.json, nothing else of vue/ ---
pr="$tmp/prep"
git init -q -b main "$pr"
mkdir -p "$pr/helm" "$pr/docs" "$pr/vue/src"
printf 'apiVersion: v2\nname: runr\nversion: 1.2.3\nappVersion: "1.2.3"\n' > "$pr/helm/Chart.yaml"
printf '# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-01-02\n\n### Changed\n\n- Something.\n\n[Unreleased]: https://github.com/brf-tech/runr/compare/v1.2.3...main\n[1.2.3]: https://github.com/brf-tech/runr/releases/tag/v1.2.3\n' > "$pr/CHANGELOG.md"
printf '# Upgrading\n\n## Procedure\n\nBack up.\n\n## 1.2.2 → 1.2.3\n\nNothing.\n' > "$pr/docs/upgrade.md"
echo '{"lockfileVersion": 3}' > "$pr/vue/package-lock.json"
# the docs the release's pin rules read (as runtime/test/deps_release_test.sh has them)
mkdir -p "$pr/actions/deploy" "$pr/compose" "$pr/landing"
echo 'To install a specific release, pin the chart version (`--version 1.2.3`); the images follow it.' > "$pr/README.md"
printf '%s\n' "<!-- README.md'nin 1.2.3 sürümüne göre çevirisi -->" 'Bir sürümü sabitlemek için (`--version 1.2.3`).' > "$pr/README.tr.md"
printf '%s\n' '      - uses: brf-tech/runr/actions/deploy@v1.2.3' '| `version` | `latest` | runr CLI release (`latest` or a tag such as `v1.2.3`) |' > "$pr/actions/deploy/README.md"
echo 'git clone --branch v1.2.3 https://github.com/BRF-Tech/runr.git' > "$pr/compose/README.md"
echo 'curl -fsSL https://runr.sh/install.sh | RUNR_VERSION=v1.2.3 sh             # a specific release' > "$pr/docs/cli.md"
printf '%s\n' '      - uses: brf-tech/runr/actions/deploy@v1.2.3' '    RUNR_VERSION: v1.2.3' > "$pr/docs/github-action.md"
echo 'Every value of the Helm chart `runr` 1.2.3.' > "$pr/docs/helm-values.md"
echo 'helm upgrade runr brf-tech/runr --version 1.2.3 -n runr --reuse-values' > "$pr/docs/install.md"
echo 'docker build -t registry.example.com/runr/ui:1.2.3-mt .' > "$pr/docs/modules.md"
echo 'with `ui.image.tag: "1.2.3"` it runs ui:1.2.3-mt; Kata 11.2.3 and 1.2.30 stay.' > "$pr/docs/tenancy.md"
echo '<span>v1.2.3</span> "softwareVersion": "1.2.3"' > "$pr/landing/index.html"
echo '# RUNR_VERSION=v1.2.3     install this release instead of the latest one' > "$pr/landing/install.sh"
echo 'x' > "$pr/vue/src/app.js"
(cd "$pr" && git add -A && git commit -q -m base && git tag -a -m "RunR 1.2.3" v1.2.3)
prep_bump() {
  git -C "$pr" checkout -q -f --detach v1.2.3
  echo "$RANDOM" >> "$pr/$1"
  (cd "$pr" && deps_changelog_add CHANGELOG.md '- **Console npm packages ' '- **Console npm packages moved** (minor and patch updates): axios 1.7.0 → 1.21.1.')
  (cd "$pr" && git add -A && git -c user.name="$DEPS_BOT_NAME" -c user.email="$DEPS_BOT_EMAIL" commit -q -m "$(printf 'fix(ui): console npm packages (1 update)\n\nRunR-Deps-Bot: npm-vue 0a1b2c3d')")
  rm -rf "$tmp/prep-report"; mkdir -p "$tmp/prep-report"; echo '[]' > "$tmp/prep-report/suites.json"
  rc=0
  bash "$root/ci/deps-release.sh" prepare "$pr" v1.2.3 1.2.4 "$tmp/prep-report" > "$tmp/prep.log" 2>&1 || rc=$?
}
prep_bump vue/package-lock.json
[ "$rc" = 0 ] && [ "$(git -C "$pr" log -1 --format='%s|%(trailers:key=RunR-Deps-Bot,valueonly)' | sed '/^$/d')" = "chore(release): 1.2.4|npm-vue 0a1b2c3d" ] \
  && git -C "$pr" diff --name-only v1.2.3 HEAD | grep -qx vue/package-lock.json || fail "the release of a console npm bump: rc ${rc}, $(tail -n 5 "$tmp/prep.log") $(cat "$tmp/prep-report/release-reasons.md" 2>/dev/null)"
prep_bump vue/src/app.js
[ "$rc" = 2 ] && grep -qF 'vue/src/app.js, which the bot does not release' "$tmp/prep-report/release-reasons.md" || fail "a release that changes vue/src: rc ${rc}"
echo "ok - the release takes a console npm bump (vue/package-lock.json) with the bump's trailer, and refuses anything else of vue/"

# --- the checks read their result files --------------------------------------------------------------
ck="$tmp/ck"
mkdir -p "$ck/ci" "$ck/mod" "$ck/sdk/py" "$tmp/ckbin"
cp "$root/ci/deps-check.sh" "$root/ci/ui-results.sh" "$ck/ci/"
echo 'module example.com/m' > "$ck/mod/go.mod"
cat > "$tmp/ckbin/go" <<'EOS'
#!/usr/bin/env bash
case "$1" in
  vet) exit 0 ;;
  test) printf '%s\n' '{"Action":"pass","Package":"example.com/m","Test":"TestA"}' '{"Action":"fail","Package":"example.com/m","Test":"TestB"}' '{"Action":"fail","Package":"example.com/m"}'; exit 0 ;;
esac
EOS
printf '#!/usr/bin/env bash\nexit 0\n' > "$tmp/ckbin/gofmt"
# python3 -m venv DIR: a venv whose pip installs nothing and whose pytest writes $FAKE/junit.xml
# (exit FAKE_PYTEST_RC); its python is the real one, which reads the report
REAL_PY="$(command -v python3)"
export REAL_PY
cat > "$tmp/ckbin/python3" <<'EOS'
#!/usr/bin/env bash
[ "$1" = -m ] && [ "$2" = venv ] || exec "$REAL_PY" "$@"
mkdir -p "$3/bin"
printf '#!/usr/bin/env bash\nexit 0\n' > "$3/bin/pip"
cat > "$3/bin/pytest" <<'PYT'
#!/usr/bin/env bash
for a in "$@"; do case "$a" in --junitxml=*) cp "$FAKE/junit.xml" "${a#--junitxml=}" ;; esac; done
exit "${FAKE_PYTEST_RC:-0}"
PYT
ln -s "$REAL_PY" "$3/bin/python"
chmod +x "$3/bin/pip" "$3/bin/pytest"
EOS
chmod +x "$tmp/ckbin/"*
rc=0
PATH="$tmp/ckbin:$PATH" bash "$ck/ci/deps-check.sh" go:mod "$tmp/res-go" >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] && [ "$(cat "$tmp/res-go/failed")" = 2 ] && grep -qF 'example.com/m TestB' "$tmp/res-go/failures.md" || fail "go's results: rc ${rc}, $(cat "$tmp/res-go/failed" "$tmp/res-go/failures.md")"
# py-test: pytest's JUnit report counts the failed and erroring tests (a collection error too), and a
# report without a test fails
echo '[project]' > "$ck/sdk/py/pyproject.toml"
printf '%s' '<?xml version="1.0" encoding="utf-8"?><testsuites name="pytest tests"><testsuite name="pytest" errors="1" failures="1" skipped="1" tests="4">' \
  '<testcase classname="tests.test_a" name="test_ok" time="0.001" />' \
  '<testcase classname="tests.test_a" name="test_bad" time="0.001"><failure message="assert 1 == 2">tests/test_a.py:3: AssertionError</failure></testcase>' \
  '<testcase classname="tests.test_a" name="test_skip" time="0.000"><skipped type="pytest.skip" message="later" /></testcase>' \
  '<testcase classname="" name="tests.test_b" time="0.000"><error message="collection failure">ImportError</error></testcase>' \
  '</testsuite></testsuites>' > "$FAKE/junit.xml"
rc=0
FAKE_PYTEST_RC=1 PATH="$tmp/ckbin:$PATH" bash "$ck/ci/deps-check.sh" py-test:sdk/py "$tmp/res-py" >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] && [ "$(cat "$tmp/res-py/failed")" = 3 ] && grep -qxF -- '- tests.test_a::test_bad: assert 1 == 2' "$tmp/res-py/failures.md" \
  && grep -qxF -- '- tests.test_b: collection failure' "$tmp/res-py/failures.md" && grep -qxF -- '- pytest failed' "$tmp/res-py/failures.md" \
  && ! grep -qF test_skip "$tmp/res-py/failures.md" || fail "pytest's failures: rc ${rc}, $(cat "$tmp/res-py/failed" "$tmp/res-py/failures.md")"
printf '%s' '<?xml version="1.0" encoding="utf-8"?><testsuites name="pytest tests"><testsuite name="pytest" errors="0" failures="0" skipped="0" tests="0"></testsuite></testsuites>' > "$FAKE/junit.xml"
rc=0
PATH="$tmp/ckbin:$PATH" bash "$ck/ci/deps-check.sh" py-test:sdk/py "$tmp/res-py0" >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] && grep -qxF -- '- pytest ran no test' "$tmp/res-py0/failures.md" || fail "a pytest run without a test passed: rc ${rc}"
printf '%s' '<?xml version="1.0" encoding="utf-8"?><testsuites name="pytest tests"><testsuite name="pytest" errors="0" failures="0" skipped="0" tests="2">' \
  '<testcase classname="tests.test_a" name="test_ok" time="0.001" /><testcase classname="tests.test_a" name="test_also" time="0.001" /></testsuite></testsuites>' > "$FAKE/junit.xml"
rc=0
PATH="$tmp/ckbin:$PATH" bash "$ck/ci/deps-check.sh" py-test:sdk/py "$tmp/res-py2" >/dev/null 2>&1 || rc=$?
[ "$rc" = 0 ] && [ "$(cat "$tmp/res-py2/failed")" = 0 ] && [ ! -s "$tmp/res-py2/failures.md" ] || fail "a green py-test check: rc ${rc}, $(cat "$tmp/res-py2/failures.md")"
# the console's (ci/ui-results.sh, ui-tests.yml and ui-stack-suite.yml): Vitest's report must have run
# the tutorial's spec, counts the failed tests and the test files that did not load; Playwright's its
# unexpected results and errors; a failed step counts too, with its log's end
ur() { local rc=0; bash "$ck/ci/ui-results.sh" "$@" >/dev/null 2>&1 || rc=$?; echo "$rc"; }
spec='{"name": "/w/vue/tests/composables/tutorialSteps.spec.js", "status": "passed", "message": "", "assertionResults": [{"fullName": "tutorial steps have targets", "status": "passed"}]}'
echo '{"numFailedTests": 0, "testResults": [{"name": "/w/vue/tests/a.spec.js", "status": "passed", "assertionResults": []}]}' > "$tmp/vitest-a.json"
[ "$(ur vitest "$tmp/vitest-a.json" "$tmp/res-ui")" = 1 ] && grep -qF 'tests/composables/tutorialSteps.spec.js did not run and pass' "$tmp/res-ui/failures.md" \
  || fail "Vitest without the tutorial's spec passed: $(cat "$tmp/res-ui/failures.md")"
jq -n --argjson spec "$spec" '{numFailedTests: 1, testResults: [$spec,
  {name: "/w/vue/tests/b.spec.js", status: "failed", message: "", assertionResults: [{fullName: "b works", status: "failed"}, {fullName: "b loads", status: "passed"}]},
  {name: "/w/vue/tests/c.spec.js", status: "failed", message: "ENOENT: no such file\nat c.spec.js:3", assertionResults: []}]}' > "$tmp/vitest-b.json"
echo 'lint output' > "$tmp/lint.log"
[ "$(ur vitest "$tmp/vitest-b.json" "$tmp/res-ui3" "npm ci=success" "npm run lint=failure=${tmp}/lint.log" "npm run build=skipped")" = 1 ] \
  && [ "$(cat "$tmp/res-ui3/failed")" = 3 ] && grep -qxF -- '- vue/tests/b.spec.js: b works' "$tmp/res-ui3/failures.md" \
  && grep -qxF -- '- vue/tests/c.spec.js: ENOENT: no such file' "$tmp/res-ui3/failures.md" && grep -qxF -- '- npm run lint failed' "$tmp/res-ui3/failures.md" \
  && grep -qxF -- '── npm run lint' "$tmp/res-ui3/tail.txt" && grep -qxF 'lint output' "$tmp/res-ui3/tail.txt" \
  || fail "Vitest's failures and a failed step: $(cat "$tmp/res-ui3/failed" "$tmp/res-ui3/failures.md" "$tmp/res-ui3/tail.txt")"
[ "$(ur vitest "$tmp/none.json" "$tmp/res-ui4" "npm ci=failure")" = 1 ] && grep -qxF -- '- Vitest wrote no report' "$tmp/res-ui4/failures.md" \
  || fail "no Vitest report passed"
echo '{"testResults": [{"status": "failed"}]}' > "$tmp/vitest-x.json"
[ "$(ur vitest "$tmp/vitest-x.json" "$tmp/res-ui5")" = 1 ] && grep -qF 'report could not be read' "$tmp/res-ui5/failures.md" && [ "$(cat "$tmp/res-ui5/failed")" -ge 1 ] \
  || fail "a Vitest report of another shape passed: $(cat "$tmp/res-ui5/failures.md")"
jq -n --argjson spec "$spec" '{numFailedTests: 0, testResults: [$spec]}' > "$tmp/vitest-c.json"
echo 'Test Files 1 passed' > "$tmp/vitest.log"
[ "$(ur vitest "$tmp/vitest-c.json" "$tmp/res-ui2" "npm ci=success" "npm run test:coverage=success=${tmp}/vitest.log")" = 0 ] && [ "$(cat "$tmp/res-ui2/failed")" = 0 ] \
  && [ ! -s "$tmp/res-ui2/failures.md" ] && grep -qxF 'Test Files 1 passed' "$tmp/res-ui2/tail.txt" || fail "a green Vitest report: $(cat "$tmp/res-ui2/failures.md")"
pw_spec() { jq -nc --arg t "$1" --arg s "$2" '{title: $t, file: "login.spec.ts", line: 5, ok: ($s != "unexpected"), tests: [{status: $s, projectName: "stack"}]}'; }
jq -n --argjson a "$(pw_spec 'signs in' unexpected)" --argjson b "$(pw_spec 'signs out' expected)" --argjson c "$(pw_spec 'needs a cluster' skipped)" \
  '{stats: {expected: 1, skipped: 1, unexpected: 1, flaky: 0}, errors: [{message: "Error: a worker crashed\nstack"}], suites: [{title: "login.spec.ts", specs: [], suites: [{title: "login", specs: [$a, $b, $c]}]}]}' > "$tmp/pw.json"
[ "$(ur playwright "$tmp/pw.json" "$tmp/res-pw")" = 1 ] && [ "$(cat "$tmp/res-pw/failed")" = 2 ] \
  && grep -qxF -- '- vue/e2e/login.spec.ts:5 signs in (stack)' "$tmp/res-pw/failures.md" && grep -qxF -- '- Error: a worker crashed' "$tmp/res-pw/failures.md" \
  && ! grep -qF 'signs out' "$tmp/res-pw/failures.md" || fail "Playwright's unexpected results: $(cat "$tmp/res-pw/failed" "$tmp/res-pw/failures.md")"
jq -n --argjson b "$(pw_spec 'signs out' expected)" '{stats: {expected: 1, skipped: 0, unexpected: 0, flaky: 1}, errors: [], suites: [{title: "login.spec.ts", specs: [$b]}]}' > "$tmp/pw2.json"
[ "$(ur playwright "$tmp/pw2.json" "$tmp/res-pw2" "the dev stack=success")" = 0 ] && [ "$(cat "$tmp/res-pw2/failed")" = 0 ] && [ ! -s "$tmp/res-pw2/failures.md" ] \
  || fail "a green Playwright report: $(cat "$tmp/res-pw2/failures.md")"
[ "$(ur playwright "$tmp/none.json" "$tmp/res-pw3" "the dev stack=failure")" = 1 ] && grep -qxF -- '- the dev stack failed' "$tmp/res-pw3/failures.md" \
  && grep -qxF -- '- Playwright wrote no report' "$tmp/res-pw3/failures.md" || fail "a dev stack that did not start: $(cat "$tmp/res-pw3/failures.md")"
echo "ok - the checks: go test's JSON counts the failed tests and packages, pytest's JUnit report the failed and erroring tests (none ran: a failure); the console's: Vitest's report must have run the tutorial's spec and counts failed tests and files, Playwright's its unexpected results and errors, a failed step counts with its log; a green one is 0"
