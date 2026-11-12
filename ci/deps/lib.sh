# shellcheck shell=bash
# The dependency test bot's shared functions (ci/deps-bump.sh, ci/deps-plan.sh and their tests).
# This file is sourced, so it has no shebang.
#
# The bot moves one upstream dependency of a region at a time (a Helm chart RunR installs, or the
# Knative minors RunR offers) to a newer version on the last release tag, runs that dependency's
# suites on the release it would make, and releases it as the next patch version only when they
# pass (.github/workflows/deps-test.yml, ci/deps-release.sh, docs/development.md → Dependency test
# bot). The charts and their pins come from the DEPS rows of ci/check-helm-releases.sh, the check
# that files the issues the bot works on; DEPS_BOT below adds what the bot needs on top of them.
#
# A new kind of dependency adds what tells it apart and nothing of the release: the issue title the
# plan reads (ci/deps-plan.sh), its DEPS_BOT row with its suites, and its bump in ci/deps-bump.sh
# (the pin moved, its CHANGELOG line under [Unreleased] through deps_changelog_add, its suites in
# suites.json). ci/deps-release.sh releases any bump that is one commit on the tag with a line
# under [Unreleased], whatever it moved. The package dependencies (composer, npm, Go modules,
# GitHub Actions pins, Dockerfile base images) are groups of ci/deps/packages.sh (DEPS_PKG), found
# by ci/check-package-releases.sh and moved by ci/deps/bump-packages.sh; a bump of theirs that
# ships nothing lands on next only (ci/deps-release.sh land).

DEPS_ROOT="${DEPS_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# ── What the bot knows of each dependency ────────────────────────────────────
# Format: NAME|SCOPE|LABEL|NAMES|IMAGE|RELEASE|SUITES
#   NAME     a DEPS row of ci/check-helm-releases.sh, or knative (ci/check-knative-releases.sh)
#   SCOPE    the scope of the commit subject: fix(SCOPE): …
#   LABEL    how the commit subject and the changelog name the dependency
#   NAMES    a lower-case ERE: a line names the dependency when it, or a line within three of it,
#            or its file's path matches (where a pin is "the current one", see deps_replace_version)
#   IMAGE    what follows the chart's appVersion:
#              reqs:REPO    RunR pins REPO:<appVersion> from the reqs mirror: a new appVersion
#                           needs the mirror first (BRF-Tech/runr-runtimes), so it is no
#                           mechanical bump
#              direct:REPO  RunR pins REPO:v<appVersion> (pulled from upstream): moved with the chart
#              same         the pin is the image's version too (kata-deploy)
#              none         RunR pins no image of the chart
#   RELEASE  NAMESPACE/RELEASE the chart is installed as in a region (- for none): the suites check
#            the region runs the new chart afterwards
#   SUITES   the suites a bump must pass, in the order the issue comment lists them
#            (deps_suite_command)
DEPS_BOT=(
  "traefik|regions|traefik chart|traefik|reqs:ghcr.io/brf-tech/runr/reqs/traefik|knative-serving/traefik|unit e2e network upgrade"
  "kube-prometheus-stack|regions|kube-prometheus-stack chart|prometheus|none|observability/knative|unit e2e observability upgrade-observability"
  "loki-stack|regions|loki-stack chart|loki|none|observability/loki-stack|unit e2e observability upgrade-observability"
  "zot|regions|zot chart|zot|direct:ghcr.io/project-zot/zot|runr-registry/runr-zot|unit e2e upgrade-zot"
  "kata-deploy|kata|kata-deploy|kata|same|-|unit sandbox-kata upgrade-kata"
  "knative|regions|Knative|knative|none|-|unit e2e-knative"
)

# Files a bump never edits: the release history (the changelog takes one new line, docs/upgrade.md
# is written at release time), the console and the landing page (their commits carry other
# authors: a pin named there sends the bump to a person), CI, lockfiles and vendored code, and the
# bot itself (its tests and examples name versions as data, not as RunR's pins)
DEPS_NEVER_EDIT=(
  ':(exclude)CHANGELOG.md'
  ':(exclude)docs/upgrade.md'
  ':(exclude)vue/**'
  ':(exclude)landing/**'
  ':(exclude).github/**'
  ':(exclude)**/vendor/**'
  ':(exclude)**/node_modules/**'
  ':(exclude)**/*.lock'
  ':(exclude)**/package-lock.json'
  ':(exclude)**/go.sum'
  ':(exclude)ci/deps/**'
  ':(exclude)ci/deps-*.sh'
  ':(exclude)runtime/test/deps_*_test.sh'
  ':(exclude)api/tests/**/Deps*Test.php'
)

# A line that records the past keeps its version: a RunR release named next to it, a date in prose
# (not a timestamp of a test's fixture, 2026-09-02T09:14:03Z), or a measurement (README's numbers
# were measured with the versions they name). An ERE for awk: no backslash escapes.
DEPS_HISTORY_RE='runr [0-9]+[.][0-9]+|20[0-9][0-9]-[01][0-9]-[0-3][0-9]([^t0-9]|$)|measured|median|ölçül|medyan|_since'

# deps_field ROW N — field N (1-based) of a |-separated row
deps_field() {
  local IFS='|'
  # shellcheck disable=SC2206 # the row's fields
  local f=($1)
  echo "${f[$(($2 - 1))]:-}"
}

# deps_helm_row NAME — the DEPS row of ci/check-helm-releases.sh for NAME
# ("NAME|REPO_URL|CHART_NAME|TYPE|PIN_FILE|PIN_CONSTANT"); nothing for an unknown name
deps_helm_row() {
  sed -n '/^DEPS=(/,/^)/p' "${DEPS_ROOT}/ci/check-helm-releases.sh" \
    | sed -n 's/^[[:space:]]*"\(.*\)"[[:space:]]*$/\1/p' \
    | awk -F'|' -v n="$1" '$1 == n { print; exit }'
}

# deps_bot_row NAME — the DEPS_BOT row of NAME; nothing for an unknown name
deps_bot_row() {
  local row
  for row in "${DEPS_BOT[@]}"; do
    [ "${row%%|*}" = "$1" ] && { echo "$row"; return 0; }
  done
  return 1
}

# deps_names — every dependency the bot can move: the charts, Knative and the package groups
deps_names() {
  local row
  for row in "${DEPS_BOT[@]}" "${DEPS_PKG[@]}"; do echo "${row%%|*}"; done
}

# deps_read_pin FILE CONSTANT — the version a constant of a PHP file holds (as
# ci/check-helm-releases.sh reads it)
deps_read_pin() {
  sed -nE "s/^[[:space:]]*((public|protected|private)[[:space:]]+)?const[[:space:]]+${2}[[:space:]]*=[[:space:]]*'([^']+)'.*/\3/p" "$1" 2>/dev/null \
    | head -n 1 || true
}

# deps_is_newer A B — is version A newer than version B? (a leading v counts for nothing)
deps_is_newer() {
  local a="${1#v}" b="${2#v}"
  [ "$a" != "$b" ] && [ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | tail -n 1)" = "$a" ]
}

# deps_major VERSION — the major of a version (v42.3.1 → 42)
deps_major() {
  local v="${1#v}"
  echo "${v%%.*}"
}

# deps_version_valid VERSION — a release version: digits and dots, an optional leading v and
# pre-release suffix; nothing a shell or sed would read as more
deps_version_valid() {
  [[ "$1" =~ ^v?[0-9]+(\.[0-9]+){1,3}(-[0-9A-Za-z.]+)?$ ]]
}

# deps_last_release_tag [REF] — the newest release tag v<x.y.z> REF descends from (default HEAD)
deps_last_release_tag() {
  git -C "$DEPS_ROOT" describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' "${1:-HEAD}" 2>/dev/null
}

# deps_replace_version OLD NEW NAMES [LOG] — every line of the tracked files (but DEPS_NEVER_EDIT)
# that names OLD as a whole version (not 142.3.0, 42.3.01 or 42.3.0.1; a leading v or a prefix
# like traefik- or :v is kept) and names the dependency (NAMES, see DEPS_BOT, on the line, within
# three lines of it, or in the file's path) gets NEW instead; a line of DEPS_HISTORY_RE keeps it.
# Prints "edit FILE:LINE" and "kept FILE:LINE: TEXT" lines (also to LOG when given).
deps_replace_version() {
  local old="$1" new="$2" names="$3" log="${4:-/dev/null}" file out report rc
  local -a files=()
  report="$(mktemp)"
  mapfile -t files < <(git -C "$DEPS_ROOT" grep -l -I -F -e "$old" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null || true)
  for file in ${files[@]+"${files[@]}"}; do
    out="$(mktemp)"
    rc=0
    awk -v old="$old" -v new="$new" -v names="$names" -v history="$DEPS_HISTORY_RE" -v path="$file" \
      -v report="$report" -f "${DEPS_ROOT}/ci/deps/replace.awk" "${DEPS_ROOT}/${file}" > "$out" || rc=$?
    # the file's mode stays (cat into it); a file without an edit is not written at all
    case "$rc" in
      0) cat "$out" > "${DEPS_ROOT}/${file}" ;;
      3) ;;
      *) rm -f -- "$out" "$report"; echo "deps_replace_version: awk failed on ${file}" >&2; return 1 ;;
    esac
    rm -f -- "$out"
  done
  cat "$report"
  cat "$report" >> "$log"
  rm -f -- "$report"
  return 0
}

# deps_outside_mentions OLD NAMES — the lines of the console (vue/) and the landing page
# (landing/) that name OLD as a whole version and the dependency (NAMES, on the line or in the
# path): "outside FILE:LINE: TEXT". A bump never edits them: their commits carry other authors.
deps_outside_mentions() {
  git -C "$DEPS_ROOT" grep -n -I -F -e "$1" -- 'vue/**' 'landing/**' ':(exclude)**/node_modules/**' ':(exclude)**/package-lock.json' 2>/dev/null \
    | awk -v old="$1" -v names="$2" -f "${DEPS_ROOT}/ci/deps/outside.awk" || true
}

# deps_revert LOG — puts back the files the "edit FILE…" lines of LOG name (a bump that gives up)
deps_revert() {
  local -a files=()
  mapfile -t files < <(sed -n 's/^edit \([^:]*\).*/\1/p' "$1" | sort -u)
  [ "${#files[@]}" -eq 0 ] || git -C "$DEPS_ROOT" checkout -q -- "${files[@]}"
}

# deps_changelog_line NAME OLD NEW APP_OLD APP_NEW — the CHANGELOG line of a bump, in the words of
# the changelog's own lines: what moves, what an installed region does
deps_changelog_line() {
  local name="$1" old="$2" new="$3" app_old="$4" app_new="$5" app=""
  if [ -n "$app_new" ] && [ "$app_new" != "$app_old" ]; then
    app="${app_old:+${app_old} → }${app_new}"
  elif [ -n "$app_new" ]; then
    app="${app_new}, as before"
  fi
  case "$name" in
    traefik)
      echo "- **Region Traefik chart ${new}** (from ${old}${app:+; Traefik ${app}}). New regions install it; \`region:reconcile\` moves the Traefik of an existing region to it (step \`traefik-chart\`), one pod at a time." ;;
    kube-prometheus-stack)
      echo "- **kube-prometheus-stack ${new}** (from ${old}${app:+; Prometheus Operator ${app}}). Regions installed from now on run it; an installed region keeps its chart until \`php artisan cluster:patch-observability\`, a change back to RunR's own monitoring or a new Serving install moves it." ;;
    loki-stack)
      echo "- **loki-stack ${new}** (from ${old}${app:+; Loki ${app}}). Regions installed from now on run it; an installed region keeps its chart until \`php artisan cluster:patch-observability\` or a new Serving install moves it." ;;
    zot)
      echo "- **Zot chart ${new}** (from ${old}${app:+; Zot ${app}}). Registries set up from now on run it; an installed Zot keeps its chart and version until \`php artisan registry:upgrade-zot <region>\`, which restarts the registry." ;;
    kata-deploy)
      echo "- **kata-deploy ${new}** (from ${old}). \`region:reconcile\` runs kata-deploy again on the nodes of a region with Kata (step \`kata-sandbox\`): containerd restarts twice per node, one node at a time, and the node's Kata pods stop once." ;;
    knative)
      echo "- **Knative ${new}** is offered where the cluster's Kubernetes supports it (\`KNATIVE_K8S_COMPAT\`, \`FALLBACK_CHART_VERSIONS\`); a running Serving moves to it one minor at a time." ;;
  esac
}

# deps_changelog_add FILE KEY LINE — LINE into the [Unreleased] section's "### Changed" of FILE
# (a Keep a Changelog file): the subsection is created after "### Added" (or first) when missing.
# A bullet of that subsection that starts with KEY (an earlier bump of the same dependency before
# the release) is replaced, so the changelog names the version the release ships.
deps_changelog_add() {
  local file="$1" key="$2" line="$3" out
  out="$(mktemp)"
  awk -v key="$key" -v line="$line" -f "${DEPS_ROOT}/ci/deps/changelog.awk" "$file" > "$out" || { rm -f -- "$out"; return 1; }
  cat "$out" > "$file"
  rm -f -- "$out"
}

# shellcheck source=ci/deps/packages.sh
source "${DEPS_ROOT}/ci/deps/packages.sh"
