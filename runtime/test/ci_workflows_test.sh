#!/usr/bin/env bash
# Tests the repository's CI, GitHub Actions alone, without GitHub, Docker or a network:
#   - there is no other CI (.gitlab-ci.yml) and no CI script or document needs one (GitLab
#     variables, tokens or pipeline schedules);
#   - ci.yml runs on every pull request, every push to main and by hand, and covers every check:
#     gitleaks, the reqs mirror, the Dockerfiles and runtime/test, the Helm chart, Pint, PHPUnit
#     with the coverage floor of docs/development.md, Larastan, the API reference with every module
#     (api/docker/api-docs.sh --check), the UI lint, unit tests with coverage, build and mocked e2e,
#     Go and the Node SDK;
#   - audit.yml audits the npm and Composer dependencies on main, every day and on pull requests
#     that touch a lockfile; production npm dependencies block, dev tooling and Composer report;
#   - e2e.yml runs the Kind suite and the UI suite against the dev stack every week and by hand,
#     never on a pull request;
#   - upstream-releases.yml runs the Knative and Helm chart release checks every day and files an
#     issue per finding;
#   - every workflow: contents: read by default, GITHUB_TOKEN as its only secret, every action
#     pinned to a full commit SHA with its version, every download checked against a pinned
#     SHA256, no image from Docker Hub, a concurrency group;
#   - docs/development.md lists every workflow in its CI jobs table.
#
#   bash runtime/test/ci_workflows_test.sh
# shellcheck disable=SC2016 # the workflows' text, not this shell's variables
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
wfdir="$root/.github/workflows"
fail() { echo "FAIL: $*" >&2; exit 1; }

# job FILE NAME — the lines of one job of a workflow
job() {
  awk -v j="  $2:" '
    /^jobs:/ { injobs = 1; next }
    injobs && $0 == j { on = 1; print; next }
    on && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ { exit }
    on { print }
  ' "$1"
}
# block FILE KEY — the lines under a top-level key (on:, permissions:, …), without comments and
# blank lines
block() {
  awk -v k="$2:" '$0 == k { on = 1; next } on && /^[[:space:]]*(#|$)/ { next } on && /^[^ ]/ { exit } on { print }' "$1"
}
# has TEXT NEEDLE MESSAGE
has() { grep -qF -- "$2" <<<"$1" || fail "$3"; }

# --- GitHub Actions is the only CI ------------------------------------------------------------------
[ -e "$root/.gitlab-ci.yml" ] && fail ".gitlab-ci.yml exists: GitHub Actions is the repository's only CI"
for f in ci.yml audit.yml e2e.yml upstream-releases.yml release-images.yml; do
  [ -f "$wfdir/$f" ] || fail "no .github/workflows/${f}"
done
gitlab="$(grep -rnE 'GITLAB_TOKEN|gitlab-ci-token|CI_SERVER_URL|CI_PROJECT_(ID|PATH|DIR)|CI_DEFAULT_BRANCH|CI_PIPELINE_SOURCE|SCHEDULED_JOB|\.gitlab-ci\.yml' \
  "$root/ci" "$root/.github" "$root/runtime/lib" "$root/runtime/ci.sh" "$root/scripts" \
  "$root/docs/development.md" "$root/CONTRIBUTING.md" "$root/.gitleaks.toml" || true)"
[ -z "$gitlab" ] || fail "the repository's CI still names GitLab CI: ${gitlab}"
echo "ok - no .gitlab-ci.yml; the CI scripts and docs name no GitLab pipeline, token or schedule"

# --- every workflow ---------------------------------------------------------------------------------
for wf in "$wfdir"/*.yml; do
  name="$(basename "$wf")"
  [ "$(block "$wf" permissions)" = '  contents: read' ] || fail "${name}: the default permissions are not contents: read alone"
  grep -qE '^concurrency:' "$wf" || fail "${name}: no concurrency group"
  secrets="$(grep -oE '\$\{\{ *secrets\.[A-Za-z0-9_]+ *\}\}' "$wf" | sed -E 's/[${} ]//g' | sort -u || true)"
  [ -z "$secrets" ] || [ "$secrets" = "secrets.GITHUB_TOKEN" ] || fail "${name}: other secrets than GITHUB_TOKEN: ${secrets}"
  bad="$(grep -E '^ *(- )?uses: ' "$wf" | grep -vE 'uses: [A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40} # v?[0-9]' || true)"
  [ -z "$bad" ] || fail "${name}: actions not pinned to a full commit SHA with its version: ${bad}"
  # a download (curl -o) is checked against its pinned SHA256 (sha256sum -c) before it is used
  downloads="$(grep -cE 'curl [^|]*-o ' "$wf" || true)"
  checked="$(grep -cE 'sha256sum -c' "$wf" || true)"
  [ "$downloads" = "$checked" ] || fail "${name}: ${downloads} downloads, ${checked} checksum checks"
  # images: from a registry host (ghcr.io/…, the reqs mirror), never Docker Hub's anonymous limit
  while read -r ref; do
    [ -n "$ref" ] || continue
    first="${ref%%/*}"
    case "$ref" in
      */*) case "$first" in *.*|*:*|localhost) ;; *) fail "${name}: image ${ref} comes from Docker Hub" ;; esac ;;
      *) fail "${name}: image ${ref} comes from Docker Hub" ;;
    esac
  done < <(grep -E '^ *(image|container|[A-Z_]*IMAGE): ' "$wf" | sed -E 's/^ *[A-Za-z_]+: *//; s/["'\'']//g; s/ *#.*$//')
done
echo "ok - every workflow: contents: read, GITHUB_TOKEN only, actions pinned, downloads checked, no Docker Hub image"

# --- ci.yml: every check on every pull request and push to main -------------------------------------
ci="$wfdir/ci.yml"
on="$(block "$ci" on)"
has "$on" 'branches: [main]' "ci.yml does not run on pushes to main"
grep -qxE '  pull_request:' <<<"$on" || fail "ci.yml does not run on pull requests"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "ci.yml cannot be run by hand"
grep -qE 'paths(-ignore)?:' <<<"$on" && fail "ci.yml filters paths: every pull request runs every check"

check() { # JOB NEEDLE… — the job exists and runs each needle
  local j="$1" text n
  shift
  text="$(job "$ci" "$j")"
  [ -n "$text" ] || fail "ci.yml has no job ${j}"
  for n in "$@"; do has "$text" "$n" "ci.yml job ${j} does not run: ${n}"; done
}
check gitleaks 'git archive HEAD' 'dir . --config /gitleaks.toml --redact' '.gitleaks.toml'
check reqs-mirror 'bash ci/check-reqs-mirror.sh'
check build-scripts 'bash ci/check-dockerfiles.sh' 'for t in runtime/test/*_test.sh; do'
check helm 'bash ci/check-helm-chart.sh' 'kubeconform'
check api 'vendor/bin/pint --test' 'artisan test --compact --coverage --min=' 'vendor/bin/phpstan analyse' '"8.2.35"' 'coverage: pcov'   'sh docker/api-docs.sh "$RUNNER_TEMP/openapi.json" --check' 'vendor/bin/phpunit --no-coverage tests/Feature/ApiDocsTest.php'
check ui 'npm run lint' 'npm run test:coverage' 'npm run build'
check ui-e2e 'npx playwright install --with-deps chromium' 'npm run test:e2e:mock'
check go 'gofmt -l .' 'go vet ./...' 'go test -race -count=1' "-not -path './examples/wasm/*'"
check sdk-node 'npm install -g npm@11' 'npm run typecheck' 'npm test'
# the coverage floor of the API is the one docs/development.md gives
ci_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$ci" | grep -oE '[0-9]+$')"
doc_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$root/docs/development.md" | grep -oE '[0-9]+$' | sort -u)"
[ -n "$ci_min" ] && [ "$ci_min" = "$doc_min" ] || fail "the API coverage floor of ci.yml (${ci_min}) is not docs/development.md's (${doc_min})"
echo "ok - ci.yml: gitleaks, reqs mirror, Dockerfiles + runtime/test, Helm chart, Pint, PHPUnit (--min=${ci_min}), Larastan, API reference (every module), UI, UI e2e, Go, Node SDK"

# --- audit.yml --------------------------------------------------------------------------------------
audit="$wfdir/audit.yml"
on="$(block "$audit" on)"
has "$on" 'branches: [main]' "audit.yml does not run on pushes to main"
has "$on" "cron: '" "audit.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "audit.yml cannot be run by hand"
for f in vue/package-lock.json api/composer.lock; do
  has "$on" "- ${f}" "audit.yml does not run on pull requests that touch ${f}"
done
npm="$(job "$audit" npm)"
has "$npm" 'npm audit --omit=dev --audit-level=high' "audit.yml does not audit the production npm dependencies"
grep -B2 -F 'run: npm audit --audit-level=high' <<<"$npm" | grep -qF 'continue-on-error: true' \
  || fail "audit.yml: the dev tooling audit must report without failing"
grep -B1 -F 'run: npm audit --omit=dev' <<<"$npm" | grep -qF 'continue-on-error' \
  && fail "audit.yml: the production npm audit must fail the run"
composer="$(job "$audit" composer)"
has "$composer" 'composer audit --locked' "audit.yml does not audit composer.lock"
has "$composer" 'continue-on-error: true' "audit.yml: the Composer audit must report without failing"
echo "ok - audit.yml: npm (production blocking, dev tooling reported) and Composer (reported) on main, daily and on lockfile pull requests"

# --- e2e.yml ----------------------------------------------------------------------------------------
e2e="$wfdir/e2e.yml"
on="$(block "$e2e" on)"
has "$on" "cron: '" "e2e.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "e2e.yml cannot be run by hand"
grep -qxE '  (push|pull_request|pull_request_target):' <<<"$on" && fail "e2e.yml runs on pushes or pull requests: it is scheduled or by hand"
kind="$(job "$e2e" kind)"
has "$kind" 'bash runtime/ci.sh test --k8s="$K8S_VERSION"' "e2e.yml does not run runtime/ci.sh test"
has "$kind" 'cloud-provider-kind' "e2e.yml does not install cloud-provider-kind"
grep -qF -- '--sandbox-kata' <<<"$(grep -vE '^ *#' <<<"$kind")" && fail "e2e.yml runs Kata: GitHub-hosted runners give the Kind nodes no /dev/kvm"
default_k8s="$(sed -n 's/^  local k8s_ver="\${K8S_VERSION:-\([0-9.]*\)}"$/\1/p' "$here/lib/test.sh")"
has "$kind" "K8S_VERSION: \${{ inputs.k8s || '${default_k8s}' }}" "e2e.yml's Kubernetes version is not ci.sh test's default ${default_k8s}"
stack="$(job "$e2e" ui-stack)"
has "$stack" 'docker compose up -d --build --wait api' "e2e.yml does not start the dev stack"
grep -qxE ' +run: npm run test:e2e' <<<"$stack" || fail "e2e.yml does not run the Playwright stack suite (npm run test:e2e)"
has "$stack" "E2E_SEED_DEMO: 'true'" "e2e.yml: the stack suite needs the demo data (E2E_SEED_DEMO)"
echo "ok - e2e.yml: the Kind suite and the UI stack suite, weekly and by hand"

# --- upstream-releases.yml --------------------------------------------------------------------------
up="$wfdir/upstream-releases.yml"
on="$(block "$up" on)"
has "$on" "cron: '" "upstream-releases.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "upstream-releases.yml cannot be run by hand"
has "$(cat "$up")" 'bash ci/check-knative-releases.sh' "upstream-releases.yml does not check the Knative releases"
has "$(cat "$up")" 'bash ci/check-helm-releases.sh' "upstream-releases.yml does not check the Helm chart releases"
has "$(cat "$up")" 'gh issue create' "upstream-releases.yml files no issue"
for wf in "$wfdir"/*.yml; do
  [ "$wf" = "$up" ] && continue
  grep -qE 'issues: write' "$wf" && fail "$(basename "$wf") can write issues"
done
for s in check-knative-releases.sh check-helm-releases.sh; do
  grep -qF 'RELEASES_REPORT_DIR' "$root/ci/$s" || fail "ci/${s} writes no report for the workflow"
  grep -qE '^[^#]*git (push|commit)' "$root/ci/$s" && fail "ci/${s} commits or pushes"
done
echo "ok - upstream-releases.yml: Knative and Helm chart releases, daily, an issue per finding"

# --- docs/development.md ----------------------------------------------------------------------------
dev="$root/docs/development.md"
table="$(awk '/^### CI jobs$/ { on = 1; next } on && /^##/ { exit } on { print }' "$dev")"
[ -n "$table" ] || fail "docs/development.md has no CI jobs section"
for f in ci.yml audit.yml e2e.yml upstream-releases.yml release-images.yml; do
  has "$table" "$f" "docs/development.md → CI jobs does not name ${f}"
done
echo "ok - docs/development.md → CI jobs names every workflow"
