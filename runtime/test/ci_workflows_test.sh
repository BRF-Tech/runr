#!/usr/bin/env bash
# Tests the repository's CI, GitHub Actions alone, without GitHub, Docker or a network:
#   - there is no other CI (.gitlab-ci.yml) and no CI script or document needs one (GitLab
#     variables, tokens or pipeline schedules);
#   - ci.yml runs on every pull request, every push to main and by hand, and covers every check:
#     gitleaks, the reqs mirror, the Dockerfiles, the Caddyfiles and runtime/test, the Helm chart, Pint, PHPUnit
#     with the coverage floor of docs/development.md, Larastan, the API reference with every module
#     (api/docker/api-docs.sh --check), the UI lint, unit tests with coverage, build and mocked e2e,
#     Go and the SDKs (the Node ones, the Python ones);
#   - dependency-audit.yml audits the npm and Composer dependencies on main, every day and on pull requests
#     that touch a lockfile; production npm dependencies block, dev tooling and Composer report;
#   - e2e.yml runs the Kind suite (kind-suite.yml) and the UI suite against the dev stack every
#     week and by hand, never on a pull request; ci.yml's API and UI checks and e2e.yml's Kind and
#     UI stack suites are the reusable workflows api-tests.yml, ui-tests.yml, kind-suite.yml and
#     ui-stack-suite.yml (on: workflow_call only), which deps-test.yml runs on a dependency bump
#     too; the UI ones read their reports through ci/ui-results.sh (Vitest's: the tutorial's spec
#     ran and passed; Playwright's); the dev stack (docker-compose.yml) creates the mount points of
#     its volumes in the checkout, vue/node_modules and api/vendor, as the checkout's owner, so the
#     stack suite's npm ci on the runner can write there;
#   - upstream-releases.yml runs the Knative and Helm chart release checks every day and files an
#     issue per finding; each chart of the Helm check has a pin in the code to compare with
#     (helm_releases_check_test.sh tests the comparison);
#   - deps-test.yml (the dependency test bot) runs every week and by hand, never on a push or a
#     pull request; its GITHUB_TOKEN never writes contents and it opens no pull request; it pushes
#     only through ci/deps-release.sh: one atomic push of the origin's main, next and the release
#     tag, one of this repository's main and the tag, nothing forced or deleted, no other refspec;
#     the origin is the variable GITLAB_RUNR_REMOTE, an SSH URL ci/deps/ssh-key.sh checks (no origin
#     URL in this repository's files); the deploy key (secret GITLAB_RUNR_PUSH_KEY, variable
#     GITLAB_KNOWN_HOSTS) reaches only the clone and the release's push, through ci/deps/ssh-key.sh
#     (a 0600 file, the host's key with its fingerprint pinned, StrictHostKeyChecking=yes), and a
#     step that always runs removes it; the GitHub App's key and the signing key reach only the
#     release job's steps that need them; every dependency it knows has a pin or a Knative table to
#     move, and every suite a command (a Kind suite, a check of ci/deps-check.sh or the console's
#     workflows, in both test phases); its green bumps go together (the batch job, with no key)
#     and their combined tree is tested before the release job;
#   - every workflow: contents: read by default, GITHUB_TOKEN as its only secret (deps-test.yml:
#     and the deploy key, the App's and the signing key), every action pinned to a full commit SHA with its version (a
#     reusable workflow of this repository by its path), every download checked against a pinned
#     SHA256, no image from Docker Hub, a concurrency group (a reusable workflow runs in its
#     caller's);
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
for f in ci.yml dependency-audit.yml e2e.yml upstream-releases.yml release-images.yml deps-test.yml kind-suite.yml api-tests.yml ui-tests.yml ui-stack-suite.yml; do
  [ -f "$wfdir/$f" ] || fail "no .github/workflows/${f}"
done
gitlab="$(grep -rnE 'GITLAB_TOKEN|gitlab-ci-token|CI_SERVER_URL|CI_PROJECT_(ID|PATH|DIR)|CI_DEFAULT_BRANCH|CI_PIPELINE_SOURCE|SCHEDULED_JOB|\.gitlab-ci\.yml' \
  "$root/ci" "$root/.github" "$root/runtime/lib" "$root/runtime/ci.sh" "$root/scripts" \
  "$root/docs/development.md" "$root/CONTRIBUTING.md" "$root/.gitleaks.toml" || true)"
[ -z "$gitlab" ] || fail "the repository's CI still names GitLab CI: ${gitlab}"
echo "ok - no .gitlab-ci.yml; the CI scripts and docs name no GitLab pipeline, token or schedule"

# --- every workflow ---------------------------------------------------------------------------------
# reusable FILE — a workflow other workflows call (on: workflow_call alone)
reusable() { [ "$(block "$1" on | grep -E '^  [a-z_]+:' | sed 's/:.*//; s/^ *//')" = workflow_call ]; }
for wf in "$wfdir"/*.yml; do
  name="$(basename "$wf")"
  [ "$(block "$wf" permissions)" = '  contents: read' ] || fail "${name}: the default permissions are not contents: read alone"
  reusable "$wf" || grep -qE '^concurrency:' "$wf" || fail "${name}: no concurrency group"
  secrets="$(grep -oE '\$\{\{ *secrets\.[A-Za-z0-9_]+ *\}\}' "$wf" | sed -E 's/[${} ]//g' | sort -u || true)"
  allowed="secrets.GITHUB_TOKEN"
  [ "$name" = deps-test.yml ] && allowed="secrets.GITHUB_TOKEN secrets.GITLAB_RUNR_PUSH_KEY secrets.RUNR_BOT_APP_ID secrets.RUNR_BOT_APP_PRIVATE_KEY secrets.RUNR_BOT_GPG_PRIVATE_KEY secrets.RUNR_BOT_GPG_PASSPHRASE secrets.RUNR_SCRUB_PATTERN"
  for secret in $secrets; do
    [[ " ${allowed} " == *" ${secret} "* ]] || fail "${name}: a secret other than ${allowed// / and }: ${secret}"
  done
  # an action by its commit; a reusable workflow of this repository by its path (it is pinned with its caller)
  bad="$(grep -E '^ *(- )?uses: ' "$wf" | grep -vE 'uses: [A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40} # v?[0-9]' \
    | grep -vE '^    uses: \./\.github/workflows/[a-z0-9-]+\.yml$' || true)"
  [ -z "$bad" ] || fail "${name}: actions not pinned to a full commit SHA with its version: ${bad}"
  while read -r called; do
    [ -n "$called" ] || continue
    [ -f "$root/$called" ] && reusable "$root/$called" || fail "${name} calls ${called}, which is no reusable workflow (on: workflow_call)"
  done < <(sed -n 's#^    uses: \./\(\.github/workflows/[a-z0-9-]*\.yml\)$#\1#p' "$wf")
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
check build-scripts 'bash ci/check-dockerfiles.sh' 'bash ci/check-caddyfiles.sh' 'for t in runtime/test/*_test.sh; do'
check helm 'bash ci/check-helm-chart.sh' 'kubeconform'
check api 'uses: ./.github/workflows/api-tests.yml'
api_tests="$(job "$wfdir/api-tests.yml" api)"
for n in 'vendor/bin/pint --test' 'artisan test --compact --coverage --min=' 'vendor/bin/phpstan analyse' '"8.2.35"' 'coverage: pcov' \
  'sh docker/api-docs.sh "$RUNNER_TEMP/openapi.json" --check' 'vendor/bin/phpunit --no-coverage tests/Feature/ApiDocsTest.php'; do
  has "$api_tests" "$n" "api-tests.yml (ci.yml's api job) does not run: ${n}"
done
check ui 'uses: ./.github/workflows/ui-tests.yml'
ui_tests="$(job "$wfdir/ui-tests.yml" ui)"
for n in 'npm ci --no-audit --no-fund' 'npm run lint' 'npm run test:coverage -- ' '--reporter=json --outputFile.json="$RUNNER_TEMP/vitest.json"' 'npm run build' \
  'bash ci/ui-results.sh vitest "$RUNNER_TEMP/vitest.json" "$RUNNER_TEMP/result"'; do
  has "$ui_tests" "$n" "ui-tests.yml (ci.yml's ui job) does not run: ${n}"
done
# the report is read in ci.yml as for the bot: a run without the tutorial's spec fails there too
grep -A1 -F 'name: Result' <<<"$ui_tests" | grep -qxF '        if: always()' \
  || fail "ui-tests.yml reads Vitest's report only for some callers (its Result step: if: always())"
check ui-e2e 'npx playwright install --with-deps chromium' 'npm run test:e2e:mock'
check go 'gofmt -l .' 'go vet ./...' 'go test -race -count=1' "-not -path './examples/wasm/*'"
check sdk-node 'npm install -g npm@11' 'npm run typecheck' 'npm test'
check sdk-sandbox-node 'working-directory: sdk/sandbox/node' 'npm ci --no-audit --no-fund' 'npm run typecheck' 'npm test'
check sdk-python 'for dir in sdk/python sdk/sandbox/python; do' 'python3 -m venv' '"./${dir}[test]"' '"$venv/bin/pytest"'
# the coverage floor of the API is the one docs/development.md gives
ci_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$wfdir/api-tests.yml" | grep -oE '[0-9]+$')"
doc_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$root/docs/development.md" | grep -oE '[0-9]+$' | sort -u)"
[ -n "$ci_min" ] && [ "$ci_min" = "$doc_min" ] || fail "the API coverage floor of api-tests.yml (${ci_min}) is not docs/development.md's (${doc_min})"
echo "ok - ci.yml: gitleaks, reqs mirror, Dockerfiles + runtime/test, Helm chart, Pint, PHPUnit (--min=${ci_min}), Larastan, API reference (every module), UI (ui-tests.yml, Vitest's report), UI e2e, Go, the Node and Python SDKs"

# --- dependency-audit.yml --------------------------------------------------------------------------------------
audit="$wfdir/dependency-audit.yml"
on="$(block "$audit" on)"
has "$on" 'branches: [main]' "dependency-audit.yml does not run on pushes to main"
has "$on" "cron: '" "dependency-audit.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "dependency-audit.yml cannot be run by hand"
for f in vue/package-lock.json api/composer.lock; do
  has "$on" "- ${f}" "dependency-audit.yml does not run on pull requests that touch ${f}"
done
npm="$(job "$audit" npm)"
has "$npm" 'npm audit --omit=dev --audit-level=high' "dependency-audit.yml does not audit the production npm dependencies"
grep -B2 -F 'run: npm audit --audit-level=high' <<<"$npm" | grep -qF 'continue-on-error: true' \
  || fail "dependency-audit.yml: the dev tooling audit must report without failing"
grep -B1 -F 'run: npm audit --omit=dev' <<<"$npm" | grep -qF 'continue-on-error' \
  && fail "dependency-audit.yml: the production npm audit must fail the run"
composer="$(job "$audit" composer)"
has "$composer" 'composer audit --locked' "dependency-audit.yml does not audit composer.lock"
has "$composer" 'continue-on-error: true' "dependency-audit.yml: the Composer audit must report without failing"
echo "ok - dependency-audit.yml: npm (production blocking, dev tooling reported) and Composer (reported) on main, daily and on lockfile pull requests"

# --- e2e.yml ----------------------------------------------------------------------------------------
e2e="$wfdir/e2e.yml"
on="$(block "$e2e" on)"
has "$on" "cron: '" "e2e.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "e2e.yml cannot be run by hand"
grep -qxE '  (push|pull_request|pull_request_target):' <<<"$on" && fail "e2e.yml runs on pushes or pull requests: it is scheduled or by hand"
kind="$(job "$e2e" kind)"
has "$kind" 'uses: ./.github/workflows/kind-suite.yml' "e2e.yml's kind job does not run kind-suite.yml"
has "$kind" 'command: test' "e2e.yml does not run runtime/ci.sh test"
# the weekly suite needs no hardware virtualization: Kata is the dependency test bot's kata-deploy suite
grep -qE -- '--sandbox-kata|kvm: true' <<<"$(grep -vE '^ *#' <<<"$kind")" && fail "e2e.yml's weekly suite runs Kata"
default_k8s="$(sed -n 's/^  local k8s_ver="\${K8S_VERSION:-\([0-9.]*\)}"$/\1/p' "$here/lib/test.sh")"
has "$kind" "k8s: \${{ inputs.k8s || '${default_k8s}' }}" "e2e.yml's Kubernetes version is not ci.sh test's default ${default_k8s}"
suite="$(job "$wfdir/kind-suite.yml" suite)"
has "$suite" 'bash runtime/ci.sh "${args[@]}"' "kind-suite.yml does not run runtime/ci.sh"
has "$suite" 'cloud-provider-kind' "kind-suite.yml does not install cloud-provider-kind"
has "$(block "$wfdir/kind-suite.yml" on)" "default: '${default_k8s}'" "kind-suite.yml's Kubernetes version is not ci.sh test's default ${default_k8s}"
# Kubernetes 1.35 node images come with Kind v0.31.0, and `kind load` needs a Kind that knows them
kind_version="$(sed -n 's/^ *KIND_VERSION: v//p' <<<"$suite")"
[ -n "$kind_version" ] || fail "kind-suite.yml pins no Kind version"
[ "$(printf '%s\n' 0.31.0 "$kind_version" | sort -V | head -n 1)" = 0.31.0 ] \
  || fail "kind-suite.yml's Kind ${kind_version} cannot run the node images of Kubernetes 1.35: v0.31.0 or later"
# the bot's Kata suites ask for /dev/kvm and say plainly when the runner has none
has "$suite" 'Kata cannot run' "kind-suite.yml does not report a runner without hardware virtualization"
for f in kind-suite.yml api-tests.yml ui-tests.yml ui-stack-suite.yml; do reusable "$wfdir/$f" || fail "${f} is not a reusable workflow (on: workflow_call alone)"; done
has "$(job "$e2e" ui-stack)" 'uses: ./.github/workflows/ui-stack-suite.yml' "e2e.yml's ui-stack job does not run ui-stack-suite.yml"
stack="$(job "$wfdir/ui-stack-suite.yml" ui-stack)"
has "$stack" 'docker compose up -d --build --wait api' "ui-stack-suite.yml does not start the dev stack"
grep -qE '^ +npm run test:e2e -- --reporter=list,html,junit,json ' <<<"$stack" || fail "ui-stack-suite.yml does not run the Playwright stack suite (npm run test:e2e) with its JSON report"
has "$stack" 'PLAYWRIGHT_JSON_OUTPUT_FILE: ${{ runner.temp }}/playwright.json' "ui-stack-suite.yml writes no Playwright JSON report"
has "$stack" 'bash ci/ui-results.sh playwright "$RUNNER_TEMP/playwright.json" "$RUNNER_TEMP/result"' "ui-stack-suite.yml does not read Playwright's report"
has "$stack" "E2E_SEED_DEMO: 'true'" "ui-stack-suite.yml: the stack suite needs the demo data (E2E_SEED_DEMO)"
echo "ok - e2e.yml: the Kind suite (kind-suite.yml) and the UI stack suite (ui-stack-suite.yml, Playwright's report), weekly and by hand"

# The stack suite's npm ci runs on the runner in vue/, where the compose vue service mounts a
# node_modules volume of its own. The Docker daemon creates a missing mount point as root, and npm ci
# then fails with EACCES: vue-init creates it first, owned like vue/ (vendor-init: api/vendor).
# compose_service NAME — the lines of one service of docker-compose.yml, without comments
compose_service() {
  awk -v s="  $1:" '
    /^services:/ { in_services = 1; next }
    in_services && /^[^ #]/ { exit }
    in_services && $0 == s { on = 1; next }
    on && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ { exit }
    on && /^[[:space:]]*#/ { next }
    on { print }
  ' "$root/docker-compose.yml"
}
vue="$(compose_service vue)"
has "$vue" '- vue_modules:/app/node_modules' "docker-compose.yml: the vue service has no node_modules volume of its own"
grep -qxF '        condition: service_completed_successfully' <<<"$(grep -A1 -xF '      vue-init:' <<<"$vue")" \
  || fail "docker-compose.yml: the vue service does not wait for vue-init, which creates vue/node_modules as the checkout's owner"
vue_init="$(compose_service vue-init)"
has "$vue_init" '- ./vue:/app' "docker-compose.yml: vue-init does not mount vue/"
grep -qF 'vue_modules' <<<"$vue_init" && fail "docker-compose.yml: vue-init mounts the vue_modules volume (its mount point in vue/ would be root's)"
has "$vue_init" 'mkdir -p /app/node_modules && { chown "$$(stat -c %u:%g /app)" /app/node_modules' \
  "docker-compose.yml: vue-init does not create vue/node_modules owned like vue/"
vendor_init="$(compose_service vendor-init)"
has "$vendor_init" '- ./api:/api' "docker-compose.yml: vendor-init does not mount api/"
has "$vendor_init" 'mkdir -p /api/vendor && { chown "$$(stat -c %u:%g /api)" /api/vendor' \
  "docker-compose.yml: vendor-init does not create api/vendor owned like api/"
grep -qxF '        condition: service_completed_successfully' <<<"$(grep -A1 -xF '      vendor-init:' <<<"$(compose_service migrator)")" \
  || fail "docker-compose.yml: the migrator (the first API container) does not wait for vendor-init"
# --no-deps would start the vue service without vue-init
grep -qE '^ +docker compose (up|run) [^#]*--no-deps' <<<"$stack" \
  && fail "ui-stack-suite.yml starts the dev stack with --no-deps: vue-init would not create vue/node_modules"
echo "ok - docker-compose.yml: vue-init and vendor-init create vue/node_modules and api/vendor as the checkout's owner (the stack suite's npm ci on the runner)"

# --- upstream-releases.yml --------------------------------------------------------------------------
up="$wfdir/upstream-releases.yml"
on="$(block "$up" on)"
has "$on" "cron: '" "upstream-releases.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "upstream-releases.yml cannot be run by hand"
has "$(cat "$up")" 'bash ci/check-knative-releases.sh' "upstream-releases.yml does not check the Knative releases"
has "$(cat "$up")" 'bash ci/check-helm-releases.sh' "upstream-releases.yml does not check the Helm chart releases"
has "$(cat "$up")" 'gh issue create' "upstream-releases.yml files no issue"
for wf in "$wfdir"/*.yml; do
  [ "$wf" = "$up" ] || [ "$(basename "$wf")" = deps-test.yml ] && continue
  grep -qE 'issues: write' "$wf" && fail "$(basename "$wf") can write issues"
done
has "$(cat "$up")" 'bash ci/check-package-releases.sh' "upstream-releases.yml does not check the package releases"
for s in check-knative-releases.sh check-helm-releases.sh check-package-releases.sh; do
  grep -qF 'RELEASES_REPORT_DIR' "$root/ci/$s" || fail "ci/${s} writes no report for the workflow"
  grep -qE '^[^#]*git (push|commit)' "$root/ci/$s" && fail "ci/${s} commits or pushes"
done
# DEPS rows: "NAME|REPO|CHART|TYPE|PIN_FILE|PIN_CONSTANT"
charts="$(sed -n '/^DEPS=(/,/^)/p' "$root/ci/check-helm-releases.sh" | grep -F '|' || true)"
[ -n "$charts" ] || fail "ci/check-helm-releases.sh follows no chart"
while IFS='|' read -r name _ _ _ pin_file pin_constant; do
  name="${name#*\"}"
  pin_constant="${pin_constant%\"}"
  [ -n "$pin_constant" ] || fail "ci/check-helm-releases.sh: ${name} has no pin to compare with"
  grep -qE "const +${pin_constant} *= *'[^']+'" "$root/$pin_file" \
    || fail "ci/check-helm-releases.sh: no ${pin_constant} in ${pin_file} for ${name}"
done <<<"$charts"
echo "ok - upstream-releases.yml: Knative and Helm chart releases, daily, an issue per finding; every chart has its pin"

# --- deps-test.yml: the dependency test bot -------------------------------------------------------
deps="$wfdir/deps-test.yml"
on="$(block "$deps" on)"
has "$on" "cron: '" "deps-test.yml has no schedule"
grep -qxE '  workflow_dispatch:' <<<"$on" || fail "deps-test.yml cannot be run by hand"
has "$on" 'issue:' "deps-test.yml cannot be run for one issue"
grep -qxE '  (push|pull_request|pull_request_target):' <<<"$on" && fail "deps-test.yml runs on pushes or pull requests"
# its GITHUB_TOKEN never writes contents; the pushes are ci/deps-release.sh's alone
grep -qE '^ *contents: *write' "$deps" && fail "deps-test.yml's GITHUB_TOKEN may write this repository's contents"
grep -qE 'gh pr (create|merge)|pull-requests: *write' "$deps" && fail "deps-test.yml opens or merges pull requests"
pushes="$(grep -nE '(^|[^-])git([^|]* )?push( |$)' "$deps" || true)"
[ -z "$pushes" ] || fail "deps-test.yml pushes by itself (only ci/deps-release.sh publish pushes): ${pushes}"
has "$(cat "$deps")" 'bash ci/deps-release.sh publish "$RELEASE_DIR" $dry' "deps-test.yml does not release through ci/deps-release.sh publish"
# the origin: the repository variable alone, its URL in none of this repository's files
[ "$(grep -cF 'ORIGIN_REMOTE: ${{ vars.GITLAB_RUNR_REMOTE }}' "$deps")" = 1 ] || fail "deps-test.yml does not take the origin from vars.GITLAB_RUNR_REMOTE"
grep -nE 'ORIGIN_REMOTE' "$deps" | grep -vE 'ORIGIN_REMOTE: \$\{\{ vars\.GITLAB_RUNR_REMOTE \}\}$|"\$ORIGIN_REMOTE"' && fail "deps-test.yml sets the origin otherwise"
# (a real host has a dot; HOST, GROUP and ${host} are the placeholders of the docs and the check)
urls="$(grep -rnE 'git@[a-z0-9-]+\.[a-z0-9.-]+:[a-z0-9._-]+/|ssh://git@[a-z0-9-]+\.[a-z]|gitlab\.com[:/][a-z0-9]|brftech/' \
  "$wfdir" "$root/ci" "$root/docs/development.md" || true)"
[ -z "$urls" ] || fail "an origin URL in this repository's files (the variable GITLAB_RUNR_REMOTE holds it): ${urls}"
grep -qF '^git@${host}:' "$root/ci/deps/ssh-key.sh" && grep -qF 'is no SSH URL of' "$root/ci/deps/ssh-key.sh" \
  || fail "ci/deps/ssh-key.sh does not check that the origin is an SSH URL of the pinned host"
grep -qF 'GIT_SSH_COMMAND="$(bash ci/deps/ssh-key.sh' "$deps" || fail "deps-test.yml does not check the origin"
# the bot's secrets are secrets of the environment release, which only main deploys to (a workflow
# pushed to another branch cannot read them): exactly the bump and release jobs are in it, and every
# secret but the GITHUB_TOKEN is in one of those two
for j in bump release; do
  grep -qxF '    environment: release' <<<"$(job "$deps" "$j")" || fail "deps-test.yml: the ${j} job is not in the environment release"
done
[ "$(grep -cE '^ +environment:' "$deps")" = 2 ] || fail "deps-test.yml: another job than bump and release in an environment"
nontoken() { { grep -oE 'secrets\.[A-Za-z0-9_]+' || true; } | { grep -vxF secrets.GITHUB_TOKEN || true; } | wc -l; }
[ "$(nontoken < "$deps")" = "$(( $(job "$deps" bump | nontoken) + $(job "$deps" release | nontoken) ))" ]   || fail "deps-test.yml: a secret other than the GITHUB_TOKEN outside the bump and release jobs (the environment release)"
for wf in "$wfdir"/*.yml; do
  [ "$wf" = "$deps" ] || ! grep -qE '^ +environment:' "$wf" || fail "$(basename "$wf"): a job in an environment (only deps-test.yml's bump and release)"
done
echo "ok - deps-test.yml: the bot's secrets only in the bump and release jobs, both in the environment release"
# the deploy key: two steps (the clone, the release's push) get it, through ci/deps/ssh-key.sh, and
# a step that always runs removes it after each; nothing prints it
[ "$(grep -cF 'PUSH_KEY: ${{ secrets.GITLAB_RUNR_PUSH_KEY }}' "$deps")" = 2 ] || fail "deps-test.yml: the deploy key in other steps than the clone and the release's push"
[ "$(grep -cF 'KNOWN_HOSTS: ${{ vars.GITLAB_KNOWN_HOSTS }}' "$deps")" = 2 ] || fail "deps-test.yml: GitLab's host key (vars.GITLAB_KNOWN_HOSTS) in other steps than the clone and the release's push"
grep -nE '(secrets|vars)\.GITLAB_(RUNR_PUSH_KEY|KNOWN_HOSTS)' "$deps" \
  | grep -vE ': +(PUSH_KEY: \$\{\{ secrets\.GITLAB_RUNR_PUSH_KEY|KNOWN_HOSTS: \$\{\{ vars\.GITLAB_KNOWN_HOSTS) \}\}$' \
  && fail "deps-test.yml uses the deploy key or GitLab's host key other than as a step's PUSH_KEY / KNOWN_HOSTS"
[ "$(grep -c 'GIT_SSH_COMMAND="$(bash ci/deps/ssh-key.sh "$RUNNER_TEMP/ssh")"' "$deps")" = 2 ] || fail "deps-test.yml writes the deploy key other than through ci/deps/ssh-key.sh"
[ "$(grep -B1 -F 'run: rm -rf "$RUNNER_TEMP/ssh"' "$deps" | grep -c 'if: always()')" = 2 ] || fail "deps-test.yml does not remove the deploy key in a step that always runs"
grep -nE '\$\{?(PUSH_KEY|KNOWN_HOSTS)\b' "$deps" && fail "deps-test.yml reads the deploy key in a run script"
grep -qE 'StrictHostKeyChecking=(no|accept-new)|ssh-keyscan' "$deps" "$root/ci/deps/ssh-key.sh" && fail "the bot trusts another host key than the pinned one"
for o in 'IdentitiesOnly=yes' 'StrictHostKeyChecking=yes' 'UserKnownHostsFile=' 'umask 077' 'chmod 600' 'ORIGIN_HOST:-gitlab.com' \
  'SHA256:eUXGGm1YGsMAS7vkcx6JOJdOGHPem5gQp4taiCfCLB8' 'ssh-keygen -l -E sha256'; do
  grep -qF -- "$o" "$root/ci/deps/ssh-key.sh" || fail "ci/deps/ssh-key.sh: no ${o}"
done
grep -nE '^[^#]*(echo|printf|cat)[^>]*\$\{?PUSH_KEY' "$root/ci/deps/ssh-key.sh" | grep -vE '> "\$dir/key"$' \
  && fail "ci/deps/ssh-key.sh prints the key"
# the GitHub App's key: only create-github-app-token's inputs in the release job, a token of contents:
# write alone for this repository (or BRF-Tech/helm-charts' dispatch); the signing key: only the
# release's push; neither is read by a script but to give git the token
release_job="$(job "$deps" release)"
[ "$(grep -cE 'secrets\.RUNR_BOT_APP_(ID|PRIVATE_KEY)' "$deps")" = "$(grep -cE 'secrets\.RUNR_BOT_APP_(ID|PRIVATE_KEY)' <<<"$release_job")" ] \
  || fail "deps-test.yml: the GitHub App's key outside the release job"
grep -nE 'secrets\.RUNR_BOT_APP_(ID|PRIVATE_KEY)' "$deps" | grep -vE '^[0-9]+: +(app-id: \$\{\{ secrets\.RUNR_BOT_APP_ID|private-key: \$\{\{ secrets\.RUNR_BOT_APP_PRIVATE_KEY) \}\}$' \
  && fail "deps-test.yml uses the GitHub App's key other than as create-github-app-token's input"
[ "$(grep -cE '^ +permission-[a-z-]+: ' "$deps")" = "$(grep -cE '^ +permission-contents: write$' "$deps")" ] || fail "deps-test.yml: an App token with another permission than contents: write"
grep -nE '^ +repositories: ' "$deps" | grep -vE 'repositories: (\$\{\{ github\.event\.repository\.name \}\}|helm-charts)$' \
  && fail "deps-test.yml: an App token for another repository than this one and helm-charts"
[ "$(grep -cE 'secrets\.RUNR_BOT_GPG_(PRIVATE_KEY|PASSPHRASE)' "$deps")" = 2 ] && [ "$(grep -cE 'secrets\.RUNR_BOT_GPG_' <<<"$release_job")" = 2 ] \
  || fail "deps-test.yml: the signing key in other steps than the release's push"
grep -nE '\$\{?(DEPS_SIGN_KEY|DEPS_SIGN_PASSPHRASE)\b' "$deps" && fail "deps-test.yml reads the signing key in a run script"
grep -nE '\$\{?APP_TOKEN\b' "$deps" | grep -vE 'if \[ -n "\$APP_TOKEN" \]; then$|x-access-token:%s'"'"' "\$APP_TOKEN"' \
  && fail "deps-test.yml reads the App's token in a run script other than to give it to git"
# the release's pushes (ci/deps-release.sh): one of the origin (exactly its main, next and the
# release tag) and one of this repository (exactly its main and the tag), atomic, never forced
rel="$root/ci/deps-release.sh"
gitcmd='^[^#]*(^|[^A-Za-z_-])git( -[cC] [^ ]+)*'
[ "$(grep -cE "${gitcmd} push" "$rel")" = 3 ] || fail "ci/deps-release.sh has other pushes than the origin's, this repository's and the landing on next"
# a bump of next only (ci/deps-release.sh land): the origin's next alone, never forced
grep -qF 'git -C "$repo" push --porcelain "$ORIGIN_REMOTE" "${land}:refs/heads/next" 2>&1)"; then' "$rel" \
  || fail "ci/deps-release.sh land does not push exactly the origin's next"
has "$(cat "$deps")" 'bash ci/deps-release.sh land "$LAND_DIR" $dry' "deps-test.yml does not land the bumps of next only through ci/deps-release.sh land"
grep -qE 'git -C "\$REPO" push --atomic --porcelain "\$ORIGIN_REMOTE" \\$' "$rel" \
  && grep -qF '"${RELEASE}:refs/heads/main" "${m}:refs/heads/next" "refs/tags/${NEW_TAG}:refs/tags/${NEW_TAG}" 2>&1)"; then' "$rel" \
  || fail "ci/deps-release.sh does not push exactly the origin's main, next and the tag, atomically"
grep -qF 'git -C "$pub" push --atomic --porcelain "$PUBLIC_REMOTE" "${p}:refs/heads/main" "refs/tags/${NEW_TAG}:refs/tags/${NEW_TAG}" 2>&1)"; then' "$rel" \
  || fail "ci/deps-release.sh does not push exactly this repository's main and the tag, atomically"
grep -E -A1 "${gitcmd} push" "$rel" | grep -E -- '--force|--force-with-lease|--mirror|--all|--tags|--delete|--prune| -f( |$)| -d( |$)|\+[A-Za-z0-9/$"{]|"\+| :refs/' \
  && fail "ci/deps-release.sh forces, deletes or pushes more than the release"
grep -nE 'update-ref -d ' "$rel" | grep -vF 'update-ref -d "refs/tags/${NEW_TAG}"' | grep -vF 'update-ref -d refs/deps-release/release' \
  && fail "ci/deps-release.sh deletes other refs than its own unpushed tag and its temporary ref"
grep -qF 'bash "$ROOT/ci/deps/check-attribution.sh" "$pub" "$PUBLIC_BASE" "$p"' "$rel" || fail "ci/deps-release.sh does not check the attribution rule before the push"
for s in deps-bump.sh deps-plan.sh deps-run.sh; do
  grep -qE "${gitcmd} (push|merge|rebase|reset --hard)( |\$)" "$root/ci/$s" && fail "ci/${s} pushes or rewrites history"
done
# every dependency of the bot: a chart of the release check with its pin, or Knative's table; every
# suite a command of ci/deps-bump.sh
while IFS='|' read -r name _ _ _ _ _ dep_suites; do
  name="${name#*\"}"
  dep_suites="${dep_suites%\"}"
  [ -n "$name" ] || continue
  if [ "$name" != knative ]; then
    grep -qF "\"${name}|" <<<"$charts" || fail "ci/deps/lib.sh: ${name} is no chart of ci/check-helm-releases.sh"
  fi
  for s in $dep_suites; do
    [ "$s" = unit ] && continue
    grep -qE "^ +${s}\)" "$root/ci/deps-bump.sh" || fail "ci/deps-bump.sh has no command for ${name}'s suite ${s}"
  done
done < <(sed -n '/^DEPS_BOT=(/,/^)/p' "$root/ci/deps/lib.sh" | grep -F '|')
while IFS='|' read -r name _; do
  name="${name#*\"}"
  grep -qE "^ *\"${name}\|" "$root/ci/deps/lib.sh" || fail "ci/deps/lib.sh does not know ${name} of ci/check-helm-releases.sh"
done <<<"$charts"
has "$(job "$deps" unit)" 'uses: ./.github/workflows/api-tests.yml' "deps-test.yml does not run the API checks on a bump"
has "$(job "$deps" kind)" 'uses: ./.github/workflows/kind-suite.yml' "deps-test.yml does not run the Kind suites on a bump"
# a bump job that failed (the origin's clone): no release on missing artifacts, the issues hear why
has "$(job "$deps" release)" "needs.bump.result == 'success'" "deps-test.yml releases after a bump job that failed"
has "$(job "$deps" bump-failed)" "needs.bump.result != 'success'" "deps-test.yml says nothing on the issues when the bump job failed"
has "$(job "$deps" check)" 'bash ci/deps-check.sh "$CHECK" "$RUNNER_TEMP/result"' "deps-test.yml does not run a package bump's checks through ci/deps-check.sh"
# the console's suites: the workflows of ci.yml and e2e.yml, on the matrices ci/deps-run.sh routes to
# them (SUITE_JOBS), in both test phases, their results named as the outcome reads them
grep -qE '^SUITE_JOBS=".*ui:ui ui-stack:ui-stack' "$root/ci/deps-run.sh" || fail "ci/deps-run.sh routes no suite to the console's jobs (SUITE_JOBS)"
for p in ui:ui-tests.yml ui-stack:ui-stack-suite.yml; do
  j="${p%%:*}"
  for phase in bump:bumps:deps-tree batch:batch:deps-batch-tree; do
    need="${phase%%:*}" step_id="${phase#*:}" step_id="${step_id%%:*}"
    name="$j"
    [ "$need" = bump ] || name="batch-${j}"
    text="$(job "$deps" "$name")"
    has "$text" "uses: ./.github/workflows/${p#*:}" "deps-test.yml's ${name} job does not run ${p#*:}"
    has "$text" "include: \${{ fromJSON(needs.${need}.outputs.${j}) }}" "deps-test.yml's ${name} job does not take the ${j} matrix of the ${need} job"
    has "$text" "tree-artifact: ${phase##*:}" "deps-test.yml's ${name} job does not test ${phase##*:}"
    has "$text" 'result-name: deps-result-${{ matrix.dep }}-${{ matrix.suite }}' "deps-test.yml's ${name} job does not name its result as the outcome reads it"
    has "$(job "$deps" "$need")" "${j}: \${{ steps.${step_id}.outputs.${j} }}" "deps-test.yml's ${need} job has no ${j} matrix"
  done
done
# one release per run: the green bumps together (ci/deps-run.sh batch, from the bumps' bundle, no
# key), their combined tree tested with the same jobs, and the release job after both phases
has "$(job "$deps" batch)" 'bash ci/deps-run.sh batch "$RUNNER_TEMP/deps" "$RUNNER_TEMP/origin"' "deps-test.yml does not make the run's green bumps together (ci/deps-run.sh batch)"
has "$(job "$deps" batch-unit)" 'uses: ./.github/workflows/api-tests.yml' "deps-test.yml does not run the API checks on the combined tree"
has "$(job "$deps" batch-kind)" 'uses: ./.github/workflows/kind-suite.yml' "deps-test.yml does not run the Kind suites on the combined tree"
has "$(job "$deps" batch-check)" 'bash ci/deps-check.sh "$CHECK" "$RUNNER_TEMP/result"' "deps-test.yml does not run the checks on the combined tree"
has "$(job "$deps" batch-shell)" 'bash "$t" || exit 1' "deps-test.yml does not run runtime/test on the combined tree"
for j in batch-unit batch-shell batch-check batch-kind; do
  has "$(job "$deps" "$j")" 'deps-batch-tree' "deps-test.yml: ${j} does not test the combined tree (deps-batch-tree)"
done
has "$(job "$deps" batch)" 'needs: [plan, bump, unit, shell, check, ui, ui-stack, kind]' "deps-test.yml puts the bumps together before each is tested"
has "$release_job" 'needs: [plan, bump, batch, batch-unit, batch-shell, batch-check, batch-ui, batch-ui-stack, batch-kind]' "deps-test.yml releases before the combined tree is tested"
has "$release_job" "bash ci/deps-run.sh pick \"\$RUNNER_TEMP/deps\" \"\$RUNNER_TEMP/repo\"" "deps-test.yml does not pick the run's release through ci/deps-run.sh pick"
# every suite of a package group: a Kind suite ci/deps/bump-packages.sh knows, a check of
# ci/deps-check.sh, or the console's (routed to their jobs above)
pkgs="$(sed -n '/^DEPS_PKG=(/,/^)/p;/^DEPS_DOCKERFILES=(/,/^)/p' "$root/ci/deps/packages.sh" | grep -F '|' || true)"
[ -n "$pkgs" ] || fail "ci/deps/packages.sh has no package group"
for s in $(awk -F'|' '{ sub(/"[[:space:]]*$/, "", $NF); print $NF }' <<<"$pkgs"); do
  s="${s#+}"
  case "$s" in
    unit) ;;
    npm-test:*|py-test:*|go:*|image:*) grep -qF "  ${s%%:*}:*)" "$root/ci/deps-check.sh" || fail "ci/deps-check.sh has no check ${s%%:*}" ;;
    caddy|actionlint) grep -qE "^  ${s}\)" "$root/ci/deps-check.sh" || fail "ci/deps-check.sh has no check ${s}" ;;
    ui|ui-stack) grep -qF "ui|ui-stack) jq -nc --arg s \"\$s\" '{suite: \$s, job: \$s, command: \$s}' ;;" "$root/ci/deps/bump-packages.sh" \
      || fail "ci/deps/bump-packages.sh does not route ${s} to a job of its own" ;;
    *) grep -qE "^ +([a-z|-]+\|)?${s}(\|[a-z|-]+)?\) suite_json" "$root/ci/deps/bump-packages.sh" || fail "ci/deps/bump-packages.sh has no Kind suite ${s}" ;;
  esac
done
# the bot's own pushes start release-images.yml and nothing else: every other workflow that runs on
# a push skips one whose head commit carries the bot's trailer, in every job
for wf in "$wfdir"/*.yml; do
  name="$(basename "$wf")"
  grep -qxE '  push:' <<<"$(block "$wf" on)" || continue
  [ "$name" = release-images.yml ] && continue
  njobs="$(sed -n '/^jobs:/,$p' "$wf" | grep -cE '^  [a-z][a-z0-9-]*:$')"
  nguard="$(grep -cF "if: \${{ github.event_name != 'push' || !contains(github.event.head_commit.message, 'RunR-Deps-Bot:') }}" "$wf")"
  [ "$njobs" = "$nguard" ] || fail "${name} runs on a push and ${nguard} of its ${njobs} jobs skip the bot's own push (the RunR-Deps-Bot trailer)"
done
grep -qxE '  (push|pull_request|pull_request_target):' <<<"$(block "$wfdir/upstream-releases.yml" on)" && fail "upstream-releases.yml runs on pushes or pull requests"
grep -qxE '  workflow_run:' <<<"$(cat "$wfdir"/*.yml)" && fail "a workflow runs on another's run: the bot's release could start it"
echo "ok - deps-test.yml: weekly and by hand, its GITHUB_TOKEN read-only on contents, releases only through ci/deps-release.sh (the origin's main, next and tag atomically over SSH, this repository's main and tag with the App's token, never forced), the deploy key in two steps and removed, the App's and the signing key in the release job alone, every dependency a pin and every suite a command, the console's through ui-tests.yml and ui-stack-suite.yml in both phases"

# --- docs/development.md ----------------------------------------------------------------------------
dev="$root/docs/development.md"
table="$(awk '/^### CI jobs$/ { on = 1; next } on && /^##/ { exit } on { print }' "$dev")"
[ -n "$table" ] || fail "docs/development.md has no CI jobs section"
for f in "$wfdir"/*.yml; do
  has "$table" "$(basename "$f")" "docs/development.md → CI jobs does not name $(basename "$f")"
done
echo "ok - docs/development.md → CI jobs names every workflow"
