#!/usr/bin/env bash
# Tests the repository's CI, GitHub Actions alone, without GitHub, Docker or a network:
#   - there is no other CI (.gitlab-ci.yml) and no CI script or document needs one (GitLab
#     variables, tokens or pipeline schedules);
#   - ci.yml runs on every pull request, every push to main and by hand, and covers every check:
#     gitleaks, the reqs mirror, the Dockerfiles, the Caddyfiles and runtime/test, the Helm chart, Pint, PHPUnit
#     with the coverage floor of docs/development.md, Larastan, the API reference with every module
#     (api/docker/api-docs.sh --check), the UI lint, unit tests with coverage, build and mocked e2e,
#     Go and the Node SDK;
#   - audit.yml audits the npm and Composer dependencies on main, every day and on pull requests
#     that touch a lockfile; production npm dependencies block, dev tooling and Composer report;
#   - e2e.yml runs the Kind suite (kind-suite.yml) and the UI suite against the dev stack every
#     week and by hand, never on a pull request; ci.yml's API checks and e2e.yml's Kind suite are
#     the reusable workflows api-tests.yml and kind-suite.yml (on: workflow_call only), which
#     deps-test.yml runs on a dependency bump too;
#   - upstream-releases.yml runs the Knative and Helm chart release checks every day and files an
#     issue per finding; each chart of the Helm check has a pin in the code to compare with
#     (helm_releases_check_test.sh tests the comparison);
#   - deps-test.yml (the dependency test bot) runs every week and by hand, never on a push or a
#     pull request; it never writes this repository (no contents: write, no pull request, no
#     push but ci/deps-land.sh's `git push REMOTE HEAD:refs/heads/next`: no other refspec, branch
#     or tag, nothing forced or deleted); the origin is the variable GITLAB_RUNR_REMOTE, an SSH URL
#     ci/deps/ssh-key.sh checks (no origin URL in this repository's files); the deploy key (secret
#     GITLAB_RUNR_PUSH_KEY, variable GITLAB_KNOWN_HOSTS) reaches only the clone and the landing,
#     through ci/deps/ssh-key.sh (a 0600 file, the host's key with its fingerprint pinned,
#     StrictHostKeyChecking=yes), and a
#     step that always runs removes it; every dependency it knows has a pin or a Knative table to
#     move, and every suite a command;
#   - every workflow: contents: read by default, GITHUB_TOKEN as its only secret (deps-test.yml:
#     and the deploy key), every action pinned to a full commit SHA with its version (a
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
for f in ci.yml audit.yml e2e.yml upstream-releases.yml release-images.yml deps-test.yml kind-suite.yml api-tests.yml; do
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
  [ "$name" = deps-test.yml ] && allowed="secrets.GITHUB_TOKEN secrets.GITLAB_RUNR_PUSH_KEY"
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
check ui 'npm run lint' 'npm run test:coverage' 'npm run build'
check ui-e2e 'npx playwright install --with-deps chromium' 'npm run test:e2e:mock'
check go 'gofmt -l .' 'go vet ./...' 'go test -race -count=1' "-not -path './examples/wasm/*'"
check sdk-node 'npm install -g npm@11' 'npm run typecheck' 'npm test'
# the coverage floor of the API is the one docs/development.md gives
ci_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$wfdir/api-tests.yml" | grep -oE '[0-9]+$')"
doc_min="$(grep -oE 'artisan test --compact --coverage --min=[0-9]+' "$root/docs/development.md" | grep -oE '[0-9]+$' | sort -u)"
[ -n "$ci_min" ] && [ "$ci_min" = "$doc_min" ] || fail "the API coverage floor of api-tests.yml (${ci_min}) is not docs/development.md's (${doc_min})"
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
for f in kind-suite.yml api-tests.yml; do reusable "$wfdir/$f" || fail "${f} is not a reusable workflow (on: workflow_call alone)"; done
stack="$(job "$e2e" ui-stack)"
has "$stack" 'docker compose up -d --build --wait api' "e2e.yml does not start the dev stack"
grep -qxE ' +run: npm run test:e2e' <<<"$stack" || fail "e2e.yml does not run the Playwright stack suite (npm run test:e2e)"
has "$stack" "E2E_SEED_DEMO: 'true'" "e2e.yml: the stack suite needs the demo data (E2E_SEED_DEMO)"
echo "ok - e2e.yml: the Kind suite (kind-suite.yml) and the UI stack suite, weekly and by hand"

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
for s in check-knative-releases.sh check-helm-releases.sh; do
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
# this repository's main moves only at releases: the bot cannot write it
grep -qE 'contents: *write' "$deps" && fail "deps-test.yml may write this repository's contents"
grep -qE 'gh pr (create|merge)|pull-requests: *write' "$deps" && fail "deps-test.yml opens or merges pull requests"
pushes="$(grep -nE '(^|[^-])git([^|]* )?push( |$)' "$deps" || true)"
[ -z "$pushes" ] || fail "deps-test.yml pushes by itself (only ci/deps-land.sh lands, on next): ${pushes}"
has "$(cat "$deps")" 'bash "$GITHUB_WORKSPACE/ci/deps-land.sh" "$ORIGIN_REMOTE"' "deps-test.yml does not land through ci/deps-land.sh on the origin"
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
# the deploy key: two steps (the clone, the landing) get it, through ci/deps/ssh-key.sh, and a step
# that always runs removes it after each; nothing prints it
[ "$(grep -cF 'PUSH_KEY: ${{ secrets.GITLAB_RUNR_PUSH_KEY }}' "$deps")" = 2 ] || fail "deps-test.yml: the deploy key in other steps than the clone and the landing"
[ "$(grep -cF 'KNOWN_HOSTS: ${{ vars.GITLAB_KNOWN_HOSTS }}' "$deps")" = 2 ] || fail "deps-test.yml: GitLab's host key (vars.GITLAB_KNOWN_HOSTS) in other steps than the clone and the landing"
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
# the landing: one fast-forward of next, never forced
land="$root/ci/deps-land.sh"
# (the deploy key can write every branch the origin does not protect: exactly HEAD:refs/heads/next)
gitcmd='^[^#]*(^|[^A-Za-z_-])git( -[cC] [^ ]+)*'
[ "$(grep -cE "${gitcmd} push" "$land")" = 1 ] || fail "ci/deps-land.sh has another push than the one of next"
grep -qE '^[^#]*git push "\$remote" HEAD:refs/heads/next 2>&1\)"; then$' "$land" || fail "ci/deps-land.sh does not push exactly HEAD:refs/heads/next"
grep -qE '^git checkout -q --detach "\$commit"$' "$land" || fail "ci/deps-land.sh does not push the bump it was given as HEAD"
grep -qE '^[^#]*(--force|--force-with-lease|--mirror|--all|--tags|--delete|--prune| -f( |$)| -d( |$)|\+[A-Za-z0-9/$"{]|"\+| :refs/)' "$land" \
  && fail "ci/deps-land.sh forces, deletes or pushes more than next"
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
echo "ok - deps-test.yml: weekly and by hand, never writes this repository, lands on origin's next (vars.GITLAB_RUNR_REMOTE, SSH) as a fast-forward only, the deploy key in two steps and removed, every dependency a pin and every suite a command"

# --- docs/development.md ----------------------------------------------------------------------------
dev="$root/docs/development.md"
table="$(awk '/^### CI jobs$/ { on = 1; next } on && /^##/ { exit } on { print }' "$dev")"
[ -n "$table" ] || fail "docs/development.md has no CI jobs section"
for f in "$wfdir"/*.yml; do
  has "$table" "$(basename "$f")" "docs/development.md → CI jobs does not name $(basename "$f")"
done
echo "ok - docs/development.md → CI jobs names every workflow"
