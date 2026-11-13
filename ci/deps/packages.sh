# shellcheck shell=bash
# The dependency test bot's package dependencies: what RunR ships or builds with besides the region
# charts and Knative (ci/deps/lib.sh, DEPS_BOT). Sourced by ci/deps/lib.sh, so it has no shebang.
#
# Detection (ci/check-package-releases.sh, daily in upstream-releases.yml) reads every row: the
# minor and patch updates of one group found in one check are ONE finding and ONE bump
# (ci/deps/bump-packages.sh); an update outside the manifest's constraint (a new major, a new 0.x
# minor of a caret range, an exact pin) is a finding of its own that goes to a person.
#
# A bump releases only when it changes what a RunR release ships: an image, the chart or the API's
# code (PATH below). One that changes no shipped bytes lands on next only and ships with the next
# full release, as the filex bot merges a change that touches no published bytes without a release.

# ── The groups ────────────────────────────────────────────────────────────────
# Format: NAME|KIND|DIR|SCOPE|LABEL|PATH|SUITES
#   NAME   the group, as the issue title names it: deps: NAME [<fingerprint>]: …
#   KIND   composer | npm | go | actions | docker | pins | pypi | cargo
#   DIR    the manifest's directory (composer.json, package.json, go.mod, pyproject.toml or
#          requirements.txt, Cargo.toml); - for actions, docker and pins
#   SCOPE  the scope of the commit subject: fix(SCOPE): …
#   LABEL  how the commit subject and the changelog name the group
#   PATH   release  every green bump is a patch release (its bytes ship: an image of the release)
#          next     every green bump lands on next only (nothing of it ships in a release's bytes)
#          auto     the bump decides from what it changed: composer.lock's packages (not
#                   packages-dev), an npm package the build or the bundle takes, a Dockerfile of a
#                   shipped image → release; anything else → next
#   SUITES what the bump must pass, in the order the comment lists them; a suite with a leading +
#          runs only when the bump ships. unit: PHPUnit, Pint, Larastan, the API reference
#          (api-tests.yml); a Kind suite: a command of ci/deps-bump.sh's write_suites; a check
#          (ci/deps-check.sh): caddy, actionlint, npm-test:DIR, py-test:DIR, go:DIR, image:FILE; the
#          console's ui (ui-tests.yml) and ui-stack (ui-stack-suite.yml)
#          (runtime/test and the reqs mirror check run on every bump)
DEPS_PKG=(
  "composer-api|composer|api|api|API Composer packages|auto|unit +e2e +upgrade"
  "npm-vue|npm|vue|ui|console npm packages|auto|ui +ui-stack"
  "npm-sdk-sandbox|npm|sdk/sandbox/node|sdk|Sandbox SDK (Node) npm packages|next|npm-test:sdk/sandbox/node"
  "npm-sdk-workflows|npm|sdk/node|sdk|Workflow SDK (Node) npm packages|next|npm-test:sdk/node"
  "go-agent|go|runtime/agent|regions|region agent Go modules|release|go:runtime/agent image:runtime/agent/Dockerfile e2e upgrade"
  "go-queue-consumer|go|runtime/queue-consumer|regions|queue consumer Go modules|release|go:runtime/queue-consumer image:runtime/queue-consumer/Dockerfile e2e upgrade"
  "go-gateway-auth|go|runtime/gateway-auth|api-product|gateway-auth Go modules|release|go:runtime/gateway-auth image:runtime/gateway-auth/Dockerfile api-product"
  "go-image-upload|go|runtime/image-upload|images|image-upload Go modules|release|go:runtime/image-upload image:runtime/image-upload/Dockerfile images org-transfer"
  "go-cli|go|cli|cli|CLI Go modules|next|go:cli"
  "go-terraform-provider|go|terraform-provider-runr|terraform|Terraform provider Go modules|next|go:terraform-provider-runr"
  "go-proxy|go|proxy|dev|Sentry tunnel Go modules|next|go:proxy"
  "actions|actions|-|ci|GitHub Actions pins|next|actionlint"
  "docker|docker|-|images|Dockerfile base images|auto|"
  # the version pins that are no manifest (DEPS_PINS below): the tools RunR's images download, and
  # the tools of the workflows (next only: a release never changes .github/)
  "pins-images|pins|-|images|image tool pins|auto|"
  "pins-ci|pins|-|ci|CI tool pins|next|actionlint"
  # the Python SDKs keep no lockfile (libraries): only a new major is a finding
  "pypi-sdk-workflows|pypi|sdk/python|sdk|Workflow SDK (Python) packages|next|py-test:sdk/python"
  "pypi-sdk-sandbox|pypi|sdk/sandbox/python|sdk|Sandbox SDK (Python) packages|next|py-test:sdk/sandbox/python"
  # examples/ (next only: no release image carries them). Without a lockfile only a new major is a
  # finding. What tests them: the image the example builds (its Dockerfile installs its packages),
  # the Kind suite that deploys it (test-wasm: examples/wasm/js and rust; test-runtimes: the Q
  # interpreter), ci.yml's go job (every go.mod but examples/wasm/). No test: the Node and Python
  # workflow samples (they need a RunR to run against; their only package is RunR's own SDK, held),
  # examples/wasm/go (TinyGo: ci.yml leaves examples/wasm/ out, test-wasm deploys Rust and
  # JavaScript) and the Hugging Face weights sample (PyTorch in a region)
  "npm-example-mcp|npm|examples/mcp/node|examples|MCP starter (Node) npm packages|next|image:examples/mcp/node/Dockerfile"
  "npm-example-wasm-js|npm|examples/wasm/js|examples|Wasm JavaScript example npm packages|next|wasm"
  "npm-example-workflows|npm|examples/workflows/node|examples|Workflow example (Node) npm packages|next|"
  "go-example-q|go|examples/runtimes/q/interpreter|examples|Q interpreter Go modules|next|go:examples/runtimes/q/interpreter image:examples/runtimes/q/interpreter/Dockerfile runtimes"
  "go-example-wasm|go|examples/wasm/go|examples|Wasm Go example modules|next|"
  "pypi-example-mcp|pypi|examples/mcp/python|examples|MCP starter (Python) packages|next|image:examples/mcp/python/Dockerfile"
  "pypi-example-workflows|pypi|examples/workflows/python|examples|Workflow example (Python) packages|next|"
  "pypi-example-hf-weights|pypi|examples/ai/hf-python/weights|examples|Hugging Face weights example packages|next|"
  "cargo-example-wasm-rust|cargo|examples/wasm/rust|examples|Wasm Rust example crates|next|wasm"
)

# The image a Go module builds, for its own version (DEPS_DOCKERFILES): go-<image> of a row whose
# image has VERSION own moves that version with the bump.

# ── The Dockerfiles of the docker group ───────────────────────────────────────
# Format: FILE|IMAGE|VERSION|SUITES
#   IMAGE    the image's repository under ghcr.io/brf-tech/runr/
#   VERSION  release  tagged with RunR's version (helm/Chart.yaml appVersion): every release builds it
#            own      a version of its own (runtime/ci.sh release-images): a bump that changes the
#                     image moves it a patch (1.0.0 → 1.0.1, v0.26.0-runr.1 → v0.26.0-runr.2) so
#                     release-images.yml builds it; otherwise the registry's old tag stays
#            -        no image of a release (the dev stack, the e2e's test images): next only
#   SUITES   the suites when this Dockerfile's bases move (besides its image build)
DEPS_DOCKERFILES=(
  "api/Dockerfile.prod|api|release|unit e2e upgrade"
  "api/Dockerfile|api|-|e2e"
  "vue/Dockerfile|ui|release|caddy ui-stack"
  "runtime/agent/Dockerfile|agent|release|e2e upgrade"
  "runtime/queue-consumer/Dockerfile|queue-consumer|release|e2e upgrade"
  "runtime/gateway-auth/Dockerfile|gateway-auth|own|api-product"
  "runtime/image-upload/Dockerfile|image-upload|own|images org-transfer"
  "runtime/supply-chain/Dockerfile|supply-chain|own|supply-chain"
  "runtime/wasm-shim-spin/Dockerfile|containerd-shim-spin|own|wasm"
  "runtime/nginx-test/Dockerfile|nginx-test|-|e2e"
  "proxy/Dockerfile|proxy|-|"
  # examples/ (no image of a release): their build, and the Kind suite that builds the Q
  # interpreter's image in a region (test-runtimes). The GPU example's e2e case needs a GPU node
  "examples/ai/gpu-caas/Dockerfile|-|-|"
  "examples/mcp/node/Dockerfile|-|-|"
  "examples/mcp/python/Dockerfile|-|-|"
  "examples/runtimes/q/interpreter/Dockerfile|-|-|runtimes"
)

# The base images whose minor is a release line of its own: golang 1.26 → 1.27 moves the Go
# toolchain, which every go.mod's go line, ci.yml's setup-go and these images move together (a
# person); a new tag of the same line comes with the mirror's weekly copy. Python's minor is its
# release line too (3.12-slim → 3.13-slim)
# shellcheck disable=SC2034 # read by ci/check-package-releases.sh
DEPS_DOCKER_LINES="ghcr.io/brf-tech/runr/reqs/golang ghcr.io/brf-tech/runr/reqs/python"

# ── Manifests the bot does not follow, and why ────────────────────────────────
# Every tracked composer.json, package.json, go.mod and Dockerfile is a group's, a Dockerfile of
# DEPS_DOCKERFILES or here (runtime/test/deps_scope_test.sh): a new one cannot be missed.
# shellcheck disable=SC2034 # read by ci/check-package-releases.sh
DEPS_UNTRACKED=(
  "api/Modules/*/composer.json|the API's modules: wikimedia/composer-merge-plugin merges their require into api/composer.json, whose lock composer-api moves"
  "examples/runtime-sources/*|a copy of the runtimes/ of BRF-Tech/runr-sample-custom-executor (examples/runtime-sources/README.md): that repository moves its bases, and the copy is refreshed from it"
)

# The npm packages the console's build takes besides its dependencies: the bundle changes with them
# (vite.config.js, postcss.config.js, build/*.js import them; runtime/test/deps_scope_test.sh keeps
# the list equal to those imports)
# shellcheck disable=SC2034 # read by ci/deps/bump-packages.sh
DEPS_NPM_BUILD_ROOTS="vite @vitejs/plugin-vue @tailwindcss/postcss tailwindcss postcss esbuild"

# ── The version pins of the pins groups ───────────────────────────────────────
# The tools RunR's images download (Dockerfile ARGs) and the tools of the workflows (env and with:
# inputs): each row one pin, the same version at every place it is named.
# Format: GROUP|NAME|SOURCE|LINE|PATH|CHECKSUMS|PLACES|SUITES
#   GROUP      pins-images (a release image's bytes) or pins-ci (no shipped bytes: next only); one
#              group per path, as a bump is made on the tag or on next by its group's path, and
#              a release never changes .github/
#   NAME       the pin as the finding names it
#   SOURCE     where its releases are listed: github:OWNER/REPO (the tags, git ls-remote),
#              npm:PACKAGE, ghcr:PATH (the image's tags), goproxy:MODULE, crates:NAME, go.dev,
#              nodejs.org (its LTS lines), php.net (its active branches)
#   LINE       major     the bot moves it within its major (a 0.x version: its minor); a newer
#                        line is a finding a person decides
#              minor     within its minor; a newer minor is a finding a person decides (the newest
#                        kubectl of the API image: a Kubernetes minor newer than the set's)
#              patch     within its minor, and a newer minor is no finding of its own (an older
#                        kubectl of the API image's set: the newest one's row reports a new minor)
#              person    every newer release is a person's: RunR builds a patched upstream
#              toolchain-major, toolchain-minor   a toolchain the code is written for (go-version
#                        is every go.mod's go line): only a newer line is a finding, never a bump
#   PATH       release (a release image: the bump is a patch release), own (an image with a
#              version of its own, which moves a patch: DEPS_DOCKERFILES), next (CI, the dev stack)
#   CHECKSUMS  KIND:ARCHS:URL, {v} the version as pinned, {V} without its v, {arch} each of ARCHS
#              sum     the files pin the checksum: the published one (URL a checksums file, #NAME
#                      its line; a file of one hash without) of the old version is replaced with the
#                      new one's
#              build   the image's build checks the download against the release's checksums file
#                      (URL#NAME): the bump only requires the new release to publish it
#              digest  the files pin the sha256 GitHub publishes for the release asset URL
#              npm     the files pin the sha256 (hex) and sha384 (base64, a script's integrity) of
#                      the file URL of the npm package, whose tarball the registry's sha512 checks
#              crate   the files pin the crate's checksum of the crates.io index (Cargo.lock's)
#              -       none (an image by tag, a module go install checks against the sum database)
#   PLACES     FILE~SLOT~NAME, FILE a pathspec glob: SLOT ARG (a Dockerfile's ARG NAME=), YAML
#              (NAME: in a workflow), SH (a shell NAME=) or AT (the version follows the text NAME)
#   SUITES     besides the image build and the suites of each Dockerfile it moves (DEPS_DOCKERFILES)
#              and the group's (actionlint)
# The protoc, libseccomp and containerd-shim-wasm pins are held (holds.txt): they are upstream's
# build inputs of the Spin shim and move with containerd-shim-spin, whose every release is a
# person's. ci/check-package-releases.sh --known (runtime/test/deps_scope_test.sh, and before every
# check) keeps the table complete: every ARG *_VERSION= and *_SHA256= of a tracked Dockerfile (but
# the ARGs of a FROM, the docker group's) and every *_VERSION:, *_SHA256:, *_IMAGE: and *-version:
# of a workflow is a row's or DEPS_PINS_UNTRACKED's.
# shellcheck disable=SC2034 # read by ci/check-package-releases.sh and ci/deps/bump-packages.sh
DEPS_PINS=(
  "pins-images|helm|github:helm/helm|major|release|sum:amd64,arm64:https://get.helm.sh/helm-{v}-linux-{arch}.tar.gz.sha256sum|api/Dockerfile.prod~ARG~HELM_VERSION api/Dockerfile~ARG~HELM_VERSION|"
  # kubectl, one for every second Kubernetes minor (each within one minor of the clusters of the
  # minor below and the one above it: App\Services\Sandboxes\Exec\KubectlBinary picks a region's),
  # each moved within its minor; a Kubernetes minor newer than the newest one's is a finding of the
  # newest row (line minor): a person adds the next kubectl to the set (docs/development.md)
  "pins-images|kubectl-1.31|github:kubernetes/kubernetes|patch|release|sum:amd64,arm64:https://dl.k8s.io/release/{v}/bin/linux/{arch}/kubectl.sha256|api/Dockerfile.prod~ARG~KUBECTL_1_31_VERSION api/Dockerfile~ARG~KUBECTL_1_31_VERSION|"
  "pins-images|kubectl-1.33|github:kubernetes/kubernetes|patch|release|sum:amd64,arm64:https://dl.k8s.io/release/{v}/bin/linux/{arch}/kubectl.sha256|api/Dockerfile.prod~ARG~KUBECTL_1_33_VERSION api/Dockerfile~ARG~KUBECTL_1_33_VERSION|"
  "pins-images|kubectl-1.35|github:kubernetes/kubernetes|patch|release|sum:amd64,arm64:https://dl.k8s.io/release/{v}/bin/linux/{arch}/kubectl.sha256|api/Dockerfile.prod~ARG~KUBECTL_1_35_VERSION api/Dockerfile~ARG~KUBECTL_1_35_VERSION|"
  "pins-images|kubectl-1.37|github:kubernetes/kubernetes|minor|release|sum:amd64,arm64:https://dl.k8s.io/release/{v}/bin/linux/{arch}/kubectl.sha256|api/Dockerfile.prod~ARG~KUBECTL_1_37_VERSION api/Dockerfile~ARG~KUBECTL_1_37_VERSION|"
  "pins-images|scalar|npm:@scalar/api-reference|major|release|npm:-:dist/browser/standalone.js|api/Dockerfile.prod~ARG~SCALAR_VERSION api/resources/views/vendor/scramble/docs.blade.php~AT~@scalar/api-reference@|"
  "pins-images|syft|github:anchore/syft|major|own|build:amd64,arm64:https://github.com/anchore/syft/releases/download/v{V}/syft_{V}_checksums.txt#syft_{V}_linux_{arch}.tar.gz|runtime/supply-chain/Dockerfile~ARG~SYFT_VERSION|"
  "pins-images|cosign|github:sigstore/cosign|major|own|build:amd64,arm64:https://github.com/sigstore/cosign/releases/download/v{V}/cosign_checksums.txt#cosign-linux-{arch}|runtime/supply-chain/Dockerfile~ARG~COSIGN_VERSION|"
  "pins-images|oras|github:oras-project/oras|major|own|build:amd64,arm64:https://github.com/oras-project/oras/releases/download/v{V}/oras_{V}_checksums.txt#oras_{V}_linux_{arch}.tar.gz|runtime/supply-chain/Dockerfile~ARG~ORAS_VERSION|"
  "pins-images|containerd-shim-spin|github:spinframework/containerd-shim-spin|person|own|-|runtime/wasm-shim-spin/Dockerfile~ARG~VERSION|"
  "pins-images|protoc|github:protocolbuffers/protobuf|major|own|digest:-:https://github.com/protocolbuffers/protobuf/releases/download/v{V}/protoc-{V}-linux-x86_64.zip|runtime/wasm-shim-spin/Dockerfile~ARG~PROTOC_VERSION|"
  "pins-images|libseccomp|github:seccomp/libseccomp|major|own|digest:-:https://github.com/seccomp/libseccomp/releases/download/v{V}/libseccomp-{V}.tar.gz|runtime/wasm-shim-spin/Dockerfile~ARG~LIBSECCOMP_VERSION|"
  "pins-images|containerd-shim-wasm|crates:containerd-shim-wasm|major|own|crate:-:|runtime/wasm-shim-spin/Dockerfile~ARG~SHIM_WASM_VERSION|"
  "pins-ci|helm|github:helm/helm|major|next|sum:amd64:https://get.helm.sh/helm-{v}-linux-{arch}.tar.gz.sha256sum|.github/workflows/*.yml~YAML~HELM_VERSION|"
  "pins-ci|kubectl|github:kubernetes/kubernetes|minor|next|sum:amd64:https://dl.k8s.io/release/{v}/bin/linux/{arch}/kubectl.sha256|.github/workflows/*.yml~YAML~KUBECTL_VERSION|"
  "pins-ci|kind|github:kubernetes-sigs/kind|major|next|sum:amd64:https://github.com/kubernetes-sigs/kind/releases/download/{v}/kind-linux-{arch}.sha256sum|.github/workflows/*.yml~YAML~KIND_VERSION|"
  "pins-ci|cloud-provider-kind|github:kubernetes-sigs/cloud-provider-kind|major|next|sum:amd64:https://github.com/kubernetes-sigs/cloud-provider-kind/releases/download/{v}/cloud-provider-kind_{V}_checksums.txt#cloud-provider-kind_{V}_linux_{arch}.tar.gz|.github/workflows/*.yml~YAML~CLOUD_PROVIDER_KIND_VERSION compose/kind-setup.sh~SH~CPK_VERSION runtime/lib/test-runtimes.sh~SH~cpk_ver|"
  "pins-ci|kubeconform|github:yannh/kubeconform|major|next|sum:amd64:https://github.com/yannh/kubeconform/releases/download/{v}/CHECKSUMS#kubeconform-linux-{arch}.tar.gz|.github/workflows/*.yml~YAML~KUBECONFORM_VERSION ci/check-helm-chart.sh~AT~ghcr.io/yannh/kubeconform:|"
  "pins-ci|crane|github:google/go-containerregistry|major|next|sum:amd64:https://github.com/google/go-containerregistry/releases/download/{v}/checksums.txt#go-containerregistry_Linux_x86_64.tar.gz|.github/workflows/*.yml~YAML~CRANE_VERSION|"
  "pins-ci|actionlint|github:rhysd/actionlint|major|next|sum:amd64:https://github.com/rhysd/actionlint/releases/download/v{V}/actionlint_{V}_checksums.txt#actionlint_{V}_linux_{arch}.tar.gz|.github/workflows/*.yml~YAML~ACTIONLINT_VERSION|"
  "pins-ci|gitleaks|ghcr:gitleaks/gitleaks|major|next|-|.github/workflows/*.yml~AT~ghcr.io/gitleaks/gitleaks:|"
  "pins-ci|terraform|github:hashicorp/terraform|major|next|-|.github/workflows/*.yml~YAML~terraform_version|"
  "pins-ci|govulncheck|goproxy:golang.org/x/vuln|major|next|-|.github/workflows/*.yml~YAML~GOVULNCHECK_VERSION|"
  "pins-ci|go|go.dev|toolchain-minor|next|-|.github/workflows/*.yml~YAML~go-version|"
  "pins-ci|node|nodejs.org|toolchain-major|next|-|.github/workflows/*.yml~YAML~node-version|"
  "pins-ci|php|php.net|toolchain-minor|next|-|.github/workflows/*.yml~YAML~php-version|"
)

# Version pins of the tracked Dockerfiles and workflows that are no row of DEPS_PINS, and why
# (FILE~NAME, FILE a glob). A Dockerfile of DEPS_UNTRACKED is left out with its reason.
# shellcheck disable=SC2034 # read by ci/check-package-releases.sh
DEPS_PINS_UNTRACKED=(
  "api/Dockerfile*~PHP_MIN_VERSION|a floor, not a download: the oldest PHP the API image may ship (the release that fixes an advisory); the build refuses a base below it or on another branch, the FrankenPHP base moves with the docker group, and a person raises the floor with the advisory that needs it"
  "api/Dockerfile*~APP_VERSION|RunR's own version: the release build sets it (--build-arg APP_VERSION=v<version>)"
)

# The holds: packages a person pinned on purpose, with the reason (detection and the bump leave them)
DEPS_HOLDS_FILE="${DEPS_ROOT}/ci/deps/holds.txt"

# deps_pkg_row NAME — the DEPS_PKG row of NAME; nothing for an unknown name
deps_pkg_row() {
  local row
  for row in "${DEPS_PKG[@]}"; do
    [ "${row%%|*}" = "$1" ] && { echo "$row"; return 0; }
  done
  return 1
}

# deps_pkg_names [KIND] — the groups (of one kind)
deps_pkg_names() {
  local row
  for row in "${DEPS_PKG[@]}"; do
    [ -z "${1:-}" ] || [ "$(deps_field "$row" 2)" = "$1" ] && echo "${row%%|*}"
  done
}

# deps_dockerfile_row FILE — the DEPS_DOCKERFILES row of FILE
deps_dockerfile_row() {
  local row
  for row in "${DEPS_DOCKERFILES[@]}"; do
    [ "${row%%|*}" = "$1" ] && { echo "$row"; return 0; }
  done
  return 1
}

# deps_held GROUP NAME VERSION — the reason when the holds keep NAME at VERSION (exit 1: not held)
# holds.txt: "GROUP NAME VERSIONS REASON…", VERSIONS * (never moved) or >=X (X and newer are left)
deps_held() {
  local group name versions reason min
  [ -f "$DEPS_HOLDS_FILE" ] || return 1
  while read -r group name versions reason; do
    case "$group" in ''|'#'*) continue ;; esac
    [ "$group" = "$1" ] && [ "$name" = "$2" ] || continue
    case "$versions" in
      '*') echo "$reason"; return 0 ;;
      '>='*)
        min="${versions#>=}"
        if [ "${3#v}" = "${min#v}" ] || deps_is_newer "$3" "$min"; then echo "$reason"; return 0; fi ;;
    esac
  done < "$DEPS_HOLDS_FILE"
  return 1
}

# deps_held_always GROUP NAME — the reason when the holds never move NAME (VERSIONS *): the check
# does not even look it up (RunR's own packages are on no registry)
deps_held_always() {
  local group name versions reason
  [ -f "$DEPS_HOLDS_FILE" ] || return 1
  while read -r group name versions reason; do
    [ "$group" = "$1" ] && [ "$name" = "$2" ] && [ "$versions" = '*' ] && { echo "$reason"; return 0; }
  done < "$DEPS_HOLDS_FILE"
  return 1
}

# deps_pin_row GROUP NAME — the DEPS_PINS row of NAME in GROUP
deps_pin_row() {
  local row
  for row in "${DEPS_PINS[@]}"; do
    [ "$(deps_field "$row" 1)" = "$1" ] && [ "$(deps_field "$row" 2)" = "$2" ] && { echo "$row"; return 0; }
  done
  return 1
}

# deps_pin_names GROUP — the pins of a group, in the table's order
deps_pin_names() {
  local row
  for row in "${DEPS_PINS[@]}"; do
    [ "$(deps_field "$row" 1)" != "$1" ] || deps_field "$row" 2
  done
}

# deps_pin_files GLOB — the tracked files of a place. A tree without .git (a release archive,
# git archive's export) has no index: there its files that match GLOB, matched as git's pathspec
# matches them (* also matches /)
deps_pin_files() {
  if [ -e "${DEPS_ROOT}/.git" ]; then
    git -C "$DEPS_ROOT" ls-files -- "$1" 2>/dev/null
  else
    (cd "$DEPS_ROOT" && find . -type f -path "./$1" -print 2>/dev/null | sed 's#^\./##' | LC_ALL=C sort)
  fi
}

# deps_pin_read FILE SLOT NAME — the versions FILE pins at a place, one per line: an expression or
# a variable is none, and RunR's own build of an upstream version (v0.26.0-runr.1) is upstream's
deps_pin_read() {
  [ -f "${DEPS_ROOT}/$1" ] || return 0
  SLOT="$2" NAME="$3" perl -ne '
    my ($s, $n) = ($ENV{SLOT}, $ENV{NAME});
    if ($s eq "ARG") { print "$1\n" if /^\s*ARG\s+\Q$n\E=(\S+)/ }
    elsif ($s eq "YAML") { print "$1\n" if /^\s*(?:-\s+)?\Q$n\E:\s*["\x27]?([^"\x27\s#]+)["\x27]?\s*(?:#.*)?$/ }
    elsif ($s eq "SH") { print "$1\n" if /(?:^|[^A-Za-z0-9_])\Q$n\E=["\x27]?([^"\x27\s;]+)["\x27]?/ }
    elsif ($s eq "AT") { while (/\Q$n\E([0-9A-Za-z][0-9A-Za-z.+_-]*)/g) { (my $v = $1) =~ s/\.+$//; print "$v\n" } }' "${DEPS_ROOT}/$1" \
    | grep -v '[$]' | sed -E 's/-runr\.[0-9]+$//' || true
}

# deps_pin_write FILE SLOT NAME OLD NEW — OLD becomes NEW at the place; prints the count
deps_pin_write() {
  SLOT="$2" NAME="$3" OLD="$4" NEW="$5" perl -i -ne '
    BEGIN { $c = 0 }
    my ($s, $n, $o, $w) = @ENV{qw(SLOT NAME OLD NEW)};
    if ($s eq "ARG") { $c += s/^(\s*ARG\s+\Q$n\E=)\Q$o\E(?=\s|$)/$1$w/ }
    elsif ($s eq "YAML") { $c += s/^(\s*(?:-\s+)?\Q$n\E:\s*["\x27]?)\Q$o\E(?=["\x27]?\s*(?:#.*)?$)/$1$w/ }
    elsif ($s eq "SH") { $c += s/((?:^|[^A-Za-z0-9_])\Q$n\E=["\x27]?)\Q$o\E(?=["\x27]?(?:[\s;]|$))/$1$w/ }
    elsif ($s eq "AT") { $c += s/(\Q$n\E)\Q$o\E(?![0-9A-Za-z+_-]|\.[0-9A-Za-z])/$1$w/g }
    print; END { print STDERR "$c\n" }' "${DEPS_ROOT}/$1" 2>&1 >/dev/null | tail -n 1
}

# deps_crate_path NAME — the path of a crate on the crates.io index (its sparse protocol)
deps_crate_path() {
  local n="${1,,}"
  case "${#n}" in
    1) echo "1/$n" ;;
    2) echo "2/$n" ;;
    3) echo "3/${n:0:1}/$n" ;;
    *) echo "${n:0:2}/${n:2:2}/$n" ;;
  esac
}

# deps_fingerprint — 8 hex of the sorted "name@to" lines on stdin: a group's finding is filed once
deps_fingerprint() {
  LC_ALL=C sort -u | sha256sum | cut -c1-8
}

# deps_bump_patch VERSION — the next patch of a version of its own (1.0.0 → 1.0.1,
# v0.26.0-runr.1 → v0.26.0-runr.2)
deps_bump_patch() {
  local v="$1"
  if [[ "$v" =~ ^(.*-runr\.)([0-9]+)$ ]]; then
    echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
  elif [[ "$v" =~ ^(v?[0-9]+\.[0-9]+\.)([0-9]+)$ ]]; then
    echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
  else
    return 1
  fi
}
