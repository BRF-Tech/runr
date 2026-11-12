#!/usr/bin/env bash
# shellcheck disable=SC2016 # the bash -c scripts expand in their own shell
set -uo pipefail

# ── Dependency test bot: the checks of a package bump ────────────────────────
# One check of a bump's suites.json (ci/deps/bump-packages.sh, job "check") in this checkout, run
# by deps-test.yml's check job. Each writes what its results say, not only its exit status (the
# filex bot's #1807): RESULT_DIR/failed (the failed tests its result file counts),
# RESULT_DIR/failures.md (what failed), RESULT_DIR/tail.txt (the log's end) and
# RESULT_DIR/command. The job's status is the caller's; the outcome needs both green.
#
#   bash ci/deps-check.sh CHECK RESULT_DIR
#
#   caddy         ci/check-caddyfiles.sh (the console's web servers in the reqs mirror's Caddy)
#   actionlint    every workflow (actionlint's JSON: no finding)
#   npm-test:DIR  an SDK's npm install (npm ci with a lockfile), typecheck and tests
#   py-test:DIR   a Python SDK's tests: a venv with DIR and its test extra (pip install DIR[test]),
#                 pytest (its JUnit report: no failed or erroring test, at least one test)
#   go:DIR        gofmt, go vet and go test -race of a module (go test -json: no failed test)
#   image:FILE    docker build of a Dockerfile, as its release builds it (no push)
#
# The console's suites (ui, ui-stack) are workflows of their own, ui-tests.yml and
# ui-stack-suite.yml, which ci.yml and e2e.yml run too; ci/ui-results.sh reads their reports.
#
# Needs bash, jq and what the check runs (node and npm, python3 with venv, go, docker, actionlint).
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
check="${1:?usage: ci/deps-check.sh CHECK RESULT_DIR}"
res="${2:?usage: ci/deps-check.sh CHECK RESULT_DIR}"
mkdir -p "$res"
# (absolute: a check runs its tools in the directory it tests)
res="$(cd "$res" && pwd)"
log="$res/log.txt"
: > "$log"
: > "$res/failures.md"
echo "ci/deps-check.sh ${check}" > "$res/command"
failed=0

# step NAME CMD… — one step, its output in the log; a failed step counts as a failure of its own
step() {
  local name="$1"
  shift
  echo "── ${name}" >> "$log"
  if ! "$@" >> "$log" 2>&1; then
    echo "- ${name} failed" >> "$res/failures.md"
    failed=$((failed + 1))
    return 1
  fi
}

# junit_report FILE PYTHON — the failed and erroring tests of a JUnit report (pytest --junitxml), read
# with PYTHON; a report without a test counts as a failure too
junit_report() {
  local n
  [ -s "$1" ] || { echo "- pytest wrote no report" >> "$res/failures.md"; failed=$((failed + 1)); return; }
  n="$("$2" - "$1" "$res/failures.md" <<'PY'
import sys
import xml.etree.ElementTree as ET

cases = list(ET.parse(sys.argv[1]).getroot().iter("testcase"))
bad = []
for case in cases:
    for kind in ("failure", "error"):
        el = case.find(kind)
        if el is not None:
            lines = (el.get("message") or kind).splitlines() or [kind]
            name = "::".join(p for p in (case.get("classname"), case.get("name")) if p)
            bad.append("- %s: %s" % (name, lines[0]))
            break
if not cases:
    bad.append("- pytest ran no test")
with open(sys.argv[2], "a") as out:
    out.writelines(line + "\n" for line in bad[:30])
print(len(bad))
PY
)" || n=""
  [[ "$n" =~ ^[0-9]+$ ]] || { echo "- pytest's report is no JUnit XML" >> "$res/failures.md"; n=1; }
  failed=$((failed + n))
}

case "$check" in
  caddy)
    step "ci/check-caddyfiles.sh" bash ci/check-caddyfiles.sh ;;
  actionlint)
    if step "actionlint" bash -c 'actionlint -format "{{json .}}" > "$0"' "$res/actionlint.json"; then :; fi
    n="$(jq 'length' "$res/actionlint.json" 2>/dev/null || echo 1)"
    failed=$((failed + n))
    jq -r '.[]? | "- \(.filepath):\(.line): \(.message)"' "$res/actionlint.json" 2>/dev/null | head -n 30 >> "$res/failures.md" ;;
  npm-test:*)
    dir="${check#npm-test:}"
    [ -f "$dir/package.json" ] || { echo "- no ${dir}/package.json" >> "$res/failures.md"; failed=1; }
    if [ -f "$dir/package-lock.json" ]; then step "npm ci" bash -c 'cd "$0" && npm ci --no-audit --no-fund' "$dir"
    else step "npm install" bash -c 'cd "$0" && npm install --no-audit --no-fund' "$dir"; fi \
      && step "typecheck" bash -c 'cd "$0" && npm run typecheck' "$dir" \
      && step "npm test" bash -c 'cd "$0" && npm test' "$dir" ;;
  py-test:*)
    dir="${check#py-test:}"
    [ -f "$dir/pyproject.toml" ] || { echo "- no ${dir}/pyproject.toml" >> "$res/failures.md"; failed=1; }
    # a venv outside the result (the artifact) and the checkout; the runner's python3 (no
    # setup-python: its python-version would be a pin of its own), as ci.yml's sdk-python job
    venvs="$(mktemp -d)"
    venv="$venvs/venv"
    step "python3 -m venv" python3 -m venv "$venv" \
      && step "pip install ${dir}[test]" "$venv/bin/pip" install --no-input --disable-pip-version-check "./${dir}[test]" \
      && { step "pytest" bash -c 'cd "$0" && "$1" -p no:cacheprovider --junitxml="$2"' "$dir" "$venv/bin/pytest" "$res/junit.xml"
           junit_report "$res/junit.xml" "$venv/bin/python"; }
    rm -rf -- "$venvs" ;;
  go:*)
    dir="${check#go:}"
    [ -f "$dir/go.mod" ] || { echo "- no ${dir}/go.mod" >> "$res/failures.md"; failed=1; }
    step "gofmt" bash -c 'cd "$0" && test -z "$(gofmt -l .)"' "$dir"
    step "go vet" bash -c 'cd "$0" && go vet ./...' "$dir"
    (cd "$dir" && CGO_ENABLED=1 go test -race -count=1 -json ./... > "$res/go-test.json" 2>> "$log")
    n="$(jq -s '[.[] | select(.Action == "fail" and .Test != null)] | length' "$res/go-test.json" 2>/dev/null || echo 1)"
    builds="$(jq -s '[.[] | select(.Action == "fail" and .Test == null)] | length' "$res/go-test.json" 2>/dev/null || echo 1)"
    failed=$((failed + n + builds))
    jq -rs '.[] | select(.Action == "fail") | "- \(.Package)\(if .Test then " " + .Test else " (package)" end)"' "$res/go-test.json" 2>/dev/null | head -n 30 >> "$res/failures.md" ;;
  image:*)
    file="${check#image:}"
    case "$file" in
      api/Dockerfile.prod)
        # as release-images.yml builds it: the docs as the MCP server's resources
        mkdir -p api/resources/docs && cp docs/*.md api/resources/docs/
        step "docker build ${file}" docker build -q -f "$file" -t deps-check/api api ;;
      api/Dockerfile) step "docker build ${file}" docker build -q -f "$file" -t deps-check/api-dev api ;;
      */Dockerfile) step "docker build ${file}" docker build -q -f "$file" -t "deps-check/$(basename "$(dirname "$file")")" "$(dirname "$file")" ;;
      *) echo "- ${file} is no Dockerfile" >> "$res/failures.md"; failed=1 ;;
    esac ;;
  *) echo "unknown check: ${check}" >&2; exit 2 ;;
esac

tail -n 60 "$log" > "$res/tail.txt"
echo "$failed" > "$res/failed"
echo "${check}: ${failed} failure(s)"
[ "$failed" = 0 ]
