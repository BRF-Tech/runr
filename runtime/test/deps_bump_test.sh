#!/usr/bin/env bash
# Tests ci/deps-bump.sh and ci/deps/lib.sh, the bump of the dependency test bot, without a network,
# Helm repositories or PHP: each case runs on a throwaway git repository with the files a bump
# reads and edits, and helm and php are scripts on PATH that answer from files. Checks that:
#   - a pin moves with every line that names it as the current one: the constant, a test whose
#     path names the dependency, a doc line, a line within three of one that names it, a prefixed
#     or v-led version; not a longer version (142.3.0, 42.3.01, 42.3.0.1), not a line far from any
#     mention of the dependency, not the changelog's past, docs/upgrade.md or a line that records
#     the past (a RunR release, a date, a measurement); a file without an edit keeps its bytes;
#   - the changelog gets one line under [Unreleased] → Changed, created when missing, after
#     Added, and an earlier bump's line of the same dependency is replaced;
#   - the report: result, the commit subject and body, the suites with their ci.sh commands and
#     the last release tag, the edits;
#   - nothing to do for a version at or below the pin; a dirty checkout and an unknown
#     dependency are refused; a pin the console or the landing page names goes to a person;
#   - the chart checks send a new major, another kubeVersion, a new app version of a reqs-mirror
#     image, other objects, changed CRDs, a value RunR sets that the chart dropped, and values the
#     new chart refuses to a person, and put the checkout back; a mechanical bump of Zot moves its
#     image with it;
#   - Knative: the unreleased row is turned on through ci/check-knative-releases.sh, the docs
#     table gets the row, the e2e gets a Kind node of a Kubernetes the minor supports, and a minor
#     without its row or without such a node goes to a person;
#   - ci/deps/after-e2e.sh fails when the region does not run the version under test.
#
#   bash runtime/test/deps_bump_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.test GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

# --- fake helm and php ----------------------------------------------------------------------------
# helm: pull unpacks the chart of $FAKE/charts/<version>/ (Chart.yaml, values.yaml); template
# prints the chart's directory name, or fails when $FAKE/refuse-<version> exists; the Knative
# operator repository holds the versions of $FAKE/knative-versions
mkdir -p "$tmp/bin"
export FAKE="$tmp/fake"
cat > "$tmp/bin/helm" <<'EOF'
#!/usr/bin/env bash
case "$1 $2" in
  'repo add'|'repo update') exit 0 ;;
  'search repo')
    jq -R -s -c 'split("\n") | map(select(. != "")) | map({name: "knative-operator/knative-operator", version: ("v" + .), app_version: .})' "$FAKE/knative-versions"
    exit 0 ;;
esac
if [ "$1" = pull ]; then
  version="" dir=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --version) version="$2"; shift ;;
      --untardir) dir="$2"; shift ;;
      oci://*|deps-*) chart="${1##*/}" ;;
    esac
    shift
  done
  [ -d "$FAKE/charts/$version" ] || { echo "no chart $version" >&2; exit 1; }
  mkdir -p "$dir/$chart"
  cp "$FAKE/charts/$version/"* "$dir/$chart/"
  exit 0
fi
if [ "$1" = template ]; then
  dir="$3"
  version="$(sed -n 's/^version: //p' "$dir/Chart.yaml")"
  if [ -e "$FAKE/refuse-$version" ]; then echo "Error: values don't meet the specifications of the schema" >&2; exit 1; fi
  echo "# rendered $version"
  exit 0
fi
echo "helm: unexpected call: $*" >&2
exit 2
EOF
# php: helm-values.php prints a values file; render-diff.php prints $FAKE/<mode>.json, dead-values.php
# $FAKE/dead-old.json or dead-new.json (the pinned chart's directory is under old/)
cat > "$tmp/bin/php" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  ci/deps/helm-values.php) [ "${3:-}" = --set-json ] || echo "deployment: {replicas: 3}" ;;
  ci/deps/render-diff.php) cat "$FAKE/$2.json" ;;
  ci/deps/dead-values.php) if [[ "$2" == */old/* ]]; then cat "$FAKE/dead-old.json"; else cat "$FAKE/dead-new.json"; fi ;;
  *) echo "php: unexpected call: $*" >&2; exit 2 ;;
esac
EOF
chmod +x "$tmp/bin/helm" "$tmp/bin/php"
export PATH="$tmp/bin:$PATH"

# chart VERSION APP [KUBE] — a chart of the fake repository
chart() {
  mkdir -p "$FAKE/charts/$1"
  printf 'apiVersion: v2\nname: c\nversion: %s\nappVersion: "%s"\nkubeVersion: "%s"\n' "$1" "$2" "${3:->=1.30.0-0}" > "$FAKE/charts/$1/Chart.yaml"
  echo "deployment: {}" > "$FAKE/charts/$1/values.yaml"
}
# diffs OBJECTS CRDS KEYS [DEAD_OLD DEAD_NEW] — what render-diff.php and dead-values.php answer
diffs() {
  echo "$1" > "$FAKE/objects.json"
  echo "$2" > "$FAKE/crds.json"
  echo "$3" > "$FAKE/keys.json"
  local none='{"values": 3, "dead": [], "refused": []}'
  echo "${4:-$none}" > "$FAKE/dead-old.json"
  echo "${5:-$none}" > "$FAKE/dead-new.json"
}
same='{"old": 9, "new": 9, "added": [], "removed": [], "changed": [{"id": "Deployment knative-serving/traefik", "paths": ["spec.template.spec.containers.0.args.3"]}], "crds": []}'
nocrds='{"added": [], "removed": [], "changed": []}'
nokeys='{"dropped": []}'

# --- the repository a bump runs on ----------------------------------------------------------------
repo="$tmp/repo"
new_repo() {
  rm -rf "$repo"
  mkdir -p "$repo/ci/deps" "$repo/api/app/Services/Contracts" "$repo/api/app/Services/Helpers" "$repo/api/tests/Feature" \
    "$repo/api/tests/Unit" "$repo/docs" "$repo/runtime/lib" "$repo/vue/src" "$repo/api/app/Services"
  cp "$root/ci/check-helm-releases.sh" "$root/ci/check-knative-releases.sh" "$root/ci/deps-bump.sh" "$repo/ci/"
  cp "$root"/ci/deps/*.sh "$root"/ci/deps/*.awk "$repo/ci/deps/"
  cat > "$repo/api/app/Services/Contracts/KnativeConstants.php" <<'EOF'
<?php
interface KnativeConstants
{
    // Pinned: 42.3.0 = Traefik v3.7.17, no bundled Gateway API CRDs.
    const TRAEFIK_CHART_VERSION = '42.3.0';

    // The first Traefik that takes it as a global value
    const TRAEFIK_ALIAS_HEADERS_SINCE = 'v3.7.17';

    const TRAEFIK_IMAGE = 'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17';
}
EOF
  cat > "$repo/api/tests/Feature/TraefikImageReconcileStepTest.php" <<'EOF'
<?php
        $this->assertSame('42.3.0', $argv[array_search('--version', $argv, true) + 1]);
        $this->assertSame(['chart' => 'traefik-42.3.0', 'app_version' => 'v3.7.17'], $release);
        // not versions of the chart: 142.3.0, 42.3.01, 42.3.0.1
        ['revision' => 1, 'updated' => '2026-09-02T09:14:03.402156871Z', 'chart' => 'traefik-42.3.0'],
EOF
  cat > "$repo/api/tests/Unit/MixedTest.php" <<'EOF'
<?php
        $this->assertSame('42.3.0', $traefikChart);
        $this->assertSame(['v42.3.0'], $x);
        // three lines below the mention:
        $this->assertSame('42.3.0', $y);
        // and far below it, a version of something else:
        //
        //
        //
        //
        $this->assertSame('42.3.0', $prometheus);
EOF
  printf 'No dependency named here, and a file without a last line end: 42.3.0' > "$repo/api/tests/Unit/NoEndTest.php"
  cat > "$repo/docs/regions.md" <<'EOF'
The Serving step installs **Traefik** as Knative's ingress (chart `traefik/traefik` 42.3.0, image
`ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17`).

| Knative | Kubernetes, at least |
|---|---|
| 1.24 | 1.35 |
| 1.23 | 1.34 |

Existing regions moved from chart 40.3.0.
EOF
  cat > "$repo/README.md" <<'EOF'
Cold starts with Traefik 42.3.0, measured on 2026-11-06 (the same VM).
Since RunR 0.6.6 the region Traefik runs chart 42.3.0.
The region Traefik runs chart 42.3.0.
EOF
  echo "### Region Traefik chart 42.3.0 (the upgrade notes of a release)" > "$repo/docs/upgrade.md"
  echo '_UPG_TRAEFIK_CHART="traefik-42.3.0"' > "$repo/runtime/lib/test-helm-upgrade.sh"
  cat > "$repo/CHANGELOG.md" <<'EOF'
# Changelog

## [Unreleased]

## [0.6.7] - 2026-11-06

### Changed

- **Region Traefik chart 42.3.0.** New regions install the chart 42.3.0.
EOF
  echo "export default { title: 'RunR' }" > "$repo/vue/src/app.js"
  # the other pins of ci/check-helm-releases.sh
  printf "<?php\ntrait KnativePrometheusTrait\n{\n    public const PROMETHEUS_CHART_VERSION = '92.21.2';\n}\n" > "$repo/api/app/Services/KnativePrometheusTrait.php"
  printf "<?php\nclass ZotRegistryService\n{\n    private const ZOT_CHART_VERSION = '0.1.130';\n}\n" > "$repo/api/app/Services/ZotRegistryService.php"
  printf "        // Pinned chart (ZotRegistryService::ZOT_CHART_VERSION) = zot v2.1.23\n        private const MANIFEST_CHECK_INTERVAL_SINCE = '2.1.23';\n" > "$repo/api/app/Services/ZotManifests.php"
  printf '    # the Zot of the pinned chart (ZotRegistryService::ZOT_CHART_VERSION 0.1.130)\n    "ghcr.io/project-zot/zot:v2.1.23"\n' > "$repo/runtime/lib/test.sh"
  cat > "$repo/api/app/Services/Helpers/KnativeVersionHelper.php" <<'EOF'
<?php
class KnativeVersionHelper
{
    public const KNATIVE_K8S_COMPAT = [
        // 25 => 36, // unreleased
        24 => 35,
        23 => 34,
    ];

    public const FALLBACK_CHART_VERSIONS = [
        '1.24' => '1.24.0',
        '1.23' => '1.23.1',
    ];
}
EOF
  sed -i "s#api/app/Services/Concerns/KnativePrometheusTrait.php#api/app/Services/KnativePrometheusTrait.php#" "$repo/ci/check-helm-releases.sh"
  (cd "$repo" && git init -q -b next && git add -A && git commit -q -m "next" && git tag v0.6.7)
}

# bump DEP VERSION [FLAG...] — the bump's exit status in $rc, its report in $report, output in $out
report="$tmp/report"
out="$tmp/out"
bump() {
  rm -rf "$report"
  rc=0
  (cd "$repo" && bash ci/deps-bump.sh "$@" --report="$report") > "$out" 2>&1 || rc=$?
}
changed() { git -C "$repo" diff --name-only | sort | tr '\n' ' '; }

# --- a pin moves with what names it as the current one ---------------------------------------------
new_repo
bump traefik 42.3.1 --no-render
[ "$rc" = 0 ] || fail "traefik 42.3.1: exit ${rc}: $(cat "$out")"
[ "$(cat "$report/result")" = bumped ] || fail "result: $(cat "$report/result")"
grep -qF "const TRAEFIK_CHART_VERSION = '42.3.1';" "$repo/api/app/Services/Contracts/KnativeConstants.php" || fail "the pin did not move"
grep -qF "// Pinned: 42.3.1 = Traefik v3.7.17" "$repo/api/app/Services/Contracts/KnativeConstants.php" || fail "the comment next to the pin did not move"
grep -qF "TRAEFIK_ALIAS_HEADERS_SINCE = 'v3.7.17'" "$repo/api/app/Services/Contracts/KnativeConstants.php" || fail "a since-version moved"
f="$repo/api/tests/Feature/TraefikImageReconcileStepTest.php"
grep -qF "assertSame('42.3.1', \$argv" "$f" || fail "a test whose path names the dependency: $(cat "$f")"
grep -qF "'traefik-42.3.1'" "$f" || fail "a prefixed version: $(cat "$f")"
grep -qF "142.3.0, 42.3.01, 42.3.0.1" "$f" || fail "longer versions were edited: $(cat "$f")"
grep -qF "'updated' => '2026-09-02T09:14:03.402156871Z', 'chart' => 'traefik-42.3.1'" "$f" || fail "a fixture's timestamp is no history: $(cat "$f")"
grep -qF 'old=42.3.0' "$repo/ci/deps/replace.awk" || fail "the bot's own example was edited"
f="$repo/api/tests/Unit/MixedTest.php"
[ "$(grep -c "'42.3.1'" "$f")" = 2 ] || fail "on a line naming it and three lines below it: $(cat "$f")"
grep -qF "['v42.3.1']" "$f" || fail "a v-led version: $(cat "$f")"
grep -qF "assertSame('42.3.0', \$prometheus)" "$f" || fail "a line far from any mention was edited: $(cat "$f")"
printf 'No dependency named here, and a file without a last line end: 42.3.0' | cmp -s - "$repo/api/tests/Unit/NoEndTest.php" \
  || fail "a file without an edit changed"
grep -qF 'chart `traefik/traefik` 42.3.1, image' "$repo/docs/regions.md" || fail "the doc line"
grep -qF 'from chart 40.3.0' "$repo/docs/regions.md" || fail "an older version in the docs moved"
grep -qF 'Traefik 42.3.0, measured on 2026-11-06' "$repo/README.md" || fail "a measurement moved"
grep -qF 'Since RunR 0.6.6 the region Traefik runs chart 42.3.0.' "$repo/README.md" || fail "a release's history moved"
grep -qF 'The region Traefik runs chart 42.3.1.' "$repo/README.md" || fail "the README's current pin"
grep -qF '42.3.0' "$repo/docs/upgrade.md" || fail "docs/upgrade.md was edited"
grep -qF '_UPG_TRAEFIK_CHART="traefik-42.3.1"' "$repo/runtime/lib/test-helm-upgrade.sh" || fail "the upgrade e2e's chart"
grep -c '^kept ' "$report/edits.log" | grep -qx 2 || fail "the kept lines: $(cat "$report/edits.log")"
echo "ok - the pin moves with the lines that name it as the current one; history, longer versions and far lines stay"

# the changelog: [Unreleased] → Changed, the past untouched
sed -n '/^## \[Unreleased\]/,/^## \[0.6.7\]/p' "$repo/CHANGELOG.md" > "$tmp/unreleased"
grep -qxF '### Changed' "$tmp/unreleased" || fail "no Changed subsection: $(cat "$repo/CHANGELOG.md")"
grep -qF -- '- **Region Traefik chart 42.3.1** (from 42.3.0).' "$tmp/unreleased" || fail "the changelog line: $(cat "$tmp/unreleased")"
grep -qF -- '- **Region Traefik chart 42.3.0.** New regions install the chart 42.3.0.' "$repo/CHANGELOG.md" || fail "the 0.6.7 section changed"
awk 'length > 100 { bad = 1 } END { exit bad }' "$repo/CHANGELOG.md" || fail "a changelog line is over 100 columns"
echo "ok - the changelog gets one line under [Unreleased] → Changed, the released sections stay"

# the report
[ "$(head -n 1 "$report/commit-msg.txt")" = "fix(regions): traefik chart 42.3.1" ] || fail "subject: $(head -n 1 "$report/commit-msg.txt")"
grep -q 'Co-Authored-By' "$report/commit-msg.txt" && fail "a trailer in the commit message"
grep -qF 'TRAEFIK_CHART_VERSION' "$report/commit-msg.txt" || fail "the body does not name the pin"
[ "$(jq -r '[.[].suite] | join(" ")' "$report/suites.json")" = "e2e network upgrade" ] || fail "suites: $(cat "$report/suites.json")"
[ "$(jq -r '.[] | select(.suite == "upgrade") | .command' "$report/suites.json")" = "test-helm-upgrade --from=v0.6.7 --expect-release=knative-serving/traefik=traefik-42.3.1" ] \
  || fail "the upgrade suite: $(cat "$report/suites.json")"
[ "$(jq -r '.[] | select(.suite == "e2e") | .env' "$report/suites.json")" = "TEST_AFTER_E2E=ci/deps/after-e2e.sh DEPS_EXPECT=knative-serving/traefik=traefik-42.3.1" ] \
  || fail "the e2e's check: $(cat "$report/suites.json")"
[ "$(jq -r '[.[] | .k8s] | unique | join(" ")' "$report/suites.json")" = 1.32.0 ] || fail "the e2e's Kubernetes: $(cat "$report/suites.json")"
echo "ok - the report: subject without trailer, the suites with their commands, the last release tag"

# a second bump before the release replaces the first's changelog line
(cd "$repo" && git commit -q -a -m "traefik 42.3.1")
bump traefik 42.3.2 --no-render
[ "$rc" = 0 ] || fail "traefik 42.3.2: exit ${rc}: $(cat "$out")"
[ "$(grep -c -- '- \*\*Region Traefik chart 42.3' "$repo/CHANGELOG.md")" = 2 ] || fail "two lines of the same dependency: $(cat "$repo/CHANGELOG.md")"
grep -qF -- '- **Region Traefik chart 42.3.2** (from 42.3.1).' "$repo/CHANGELOG.md" || fail "the replaced line: $(cat "$repo/CHANGELOG.md")"
echo "ok - a later bump replaces the earlier one's changelog line"

# an [Unreleased] section with "### Added" only: Changed comes after it
cat > "$tmp/changelog" <<'EOF'
## [Unreleased]

### Added

- **Something new.** It spans
  two lines.

### Fixed

- **A fix.**

## [0.6.7] - 2026-11-06
EOF
(DEPS_ROOT="$root"; source "$root/ci/deps/lib.sh"; deps_changelog_add "$tmp/changelog" '- **zot ' '- **zot 1.2.3** moves.')
[ "$(sed -n '/two lines\./,/A fix/p' "$tmp/changelog" | tr '\n' '|')" = '  two lines.||### Changed||- **zot 1.2.3** moves.||### Fixed||- **A fix.**|' ] \
  || fail "Changed after Added: $(cat "$tmp/changelog")"
(DEPS_ROOT="$root"; source "$root/ci/deps/lib.sh"; deps_changelog_add "$tmp/changelog" '- **loki ' '- **loki 2.0.0** moves.')
[ "$(sed -n '/### Changed/,/### Fixed/p' "$tmp/changelog" | tr '\n' '|')" = '### Changed||- **zot 1.2.3** moves.|- **loki 2.0.0** moves.||### Fixed|' ] \
  || fail "a second line under Changed: $(cat "$tmp/changelog")"
echo "ok - the changelog's Changed comes after Added, a second dependency's line after the first"

# --- nothing to do, refusals -----------------------------------------------------------------------
new_repo
bump traefik 42.3.0 --no-render
[ "$rc" = 3 ] && [ "$(cat "$report/result")" = current ] || fail "at the pin: exit ${rc}: $(cat "$out")"
bump traefik 42.2.9 --no-render
[ "$rc" = 3 ] || fail "below the pin: exit ${rc}"
grep -qF '42.3.0 already' "$report/summary.md" || fail "the summary of nothing to do: $(cat "$report/summary.md")"
[ -z "$(changed)" ] || fail "nothing to do changed $(changed)"
echo "x" >> "$repo/README.md"
bump traefik 42.3.1 --no-render
[ "$rc" = 1 ] && grep -q 'without changes' "$out" || fail "a dirty checkout: exit ${rc}: $(cat "$out")"
git -C "$repo" checkout -q -- README.md
bump nginx 1.0.0 --no-render
[ "$rc" = 1 ] || fail "an unknown dependency: exit ${rc}"
bump traefik 'x;rm' --no-render
[ "$rc" = 1 ] || fail "not a version: exit ${rc}"
echo "export const traefikChart = '42.3.0'" > "$repo/vue/src/app.js"
(cd "$repo" && git commit -q -a -m vue)
bump traefik 42.3.1 --no-render
[ "$rc" = 2 ] && [ "$(cat "$report/result")" = attention ] || fail "a pin in the console: exit ${rc}: $(cat "$out")"
grep -qF 'vue/src/app.js:1' "$report/summary.md" || fail "the summary does not name the console's line: $(cat "$report/summary.md")"
[ -z "$(changed)" ] || fail "a bump that needs a person left $(changed)"
echo "ok - nothing to do at or below the pin; a dirty checkout, an unknown dependency, a non-version refused; the console's pin goes to a person"

# --- the chart checks ------------------------------------------------------------------------------
new_repo
chart 42.3.0 v3.7.17
chart 42.3.1 v3.7.17
chart 43.0.0 v3.7.17
# 42.4.0's app version is not in the reqs mirror; a variable, so that ci/check-reqs-mirror.sh does not
# take the bot's message below for a reference the repository pulls
unmirrored=v3.7.18
chart 42.4.0 "$unmirrored"
chart 42.5.0 v3.7.17 '>=1.31.0-0'
diffs "$same" "$nocrds" "$nokeys"
bump traefik 42.3.1
[ "$rc" = 0 ] || fail "a mechanical chart: exit ${rc}: $(cat "$out") $(cat "$report/summary.md" 2>/dev/null)"
grep -qF 'Kubernetes 1.30.0: 9 objects with 42.3.0, 9 with 42.3.1' "$report/summary.md" || fail "the render notes: $(cat "$report/summary.md")"
grep -qF 'Deployment knative-serving/traefik (spec.template.spec.containers.0.args.3)' "$report/summary.md" || fail "the changed objects: $(cat "$report/summary.md")"
grep -qF 'Rendered with RunR' "$report/commit-msg.txt" || fail "the render notes in the body: $(cat "$report/commit-msg.txt")"
grep -qF 'the app version stays v3.7.17' "$report/commit-msg.txt" || fail "the app version: $(cat "$report/commit-msg.txt")"
git -C "$repo" checkout -q -- .
# attention CASE VERSION NEEDLE — a chart the bot gives to a person, the checkout as it was
attention() {
  bump traefik "$2"
  [ "$rc" = 2 ] && [ "$(cat "$report/result")" = attention ] || fail "$1: exit ${rc}: $(cat "$out")"
  grep -qF -- "$3" "$report/summary.md" || fail "$1: the summary does not say '$3': $(cat "$report/summary.md")"
  [ -z "$(changed)" ] || fail "$1: left $(changed)"
}
attention "a new major" 43.0.0 "a new major release (42.3.0 → 43.0.0)"
attention "a new app version from the reqs mirror" 42.4.0 "mirror \`ghcr.io/brf-tech/runr/reqs/traefik:${unmirrored}\` first"
attention "another kubeVersion" 42.5.0 "the chart's kubeVersion changed"
diffs '{"old": 9, "new": 10, "added": ["Service knative-serving/traefik-hub"], "removed": [], "changed": [], "crds": []}' "$nocrds" "$nokeys"
attention "another object" 42.3.1 "renders objects 42.3.0 does not: Service knative-serving/traefik-hub"
diffs "$same" '{"added": [], "removed": [], "changed": ["middlewares.traefik.io"]}' "$nokeys"
attention "changed CRDs" 42.3.1 "the chart's CRDs changed (changed middlewares.traefik.io)"
diffs "$same" "$nocrds" '{"dropped": ["logs.access"]}'
attention "a dropped value" 42.3.1 "no longer has values RunR sets: logs.access"
diffs "$same" "$nocrds" "$nokeys" '{"values": 3, "dead": ["deployment.replicas"], "refused": []}' '{"values": 3, "dead": ["deployment.replicas", "service.type"], "refused": []}'
attention "a value the new chart no longer reads" 42.3.1 "chart 42.3.1 ignores values RunR sets that 42.3.0 reads: service.type"
diffs "$same" "$nocrds" "$nokeys" '{"values": 3, "dead": ["deployment.replicas"], "refused": []}' '{"values": 3, "dead": ["deployment.replicas"], "refused": []}'
bump traefik 42.3.1
[ "$rc" = 0 ] || fail "a value neither chart reads: exit ${rc}: $(cat "$report/summary.md" 2>/dev/null)"
grep -qF 'values neither chart reads on Kubernetes 1.35.0 (a condition they wait for, or a key to look at): deployment.replicas' "$report/summary.md" || fail "the note: $(cat "$report/summary.md")"
git -C "$repo" checkout -q -- .
diffs "$same" "$nocrds" "$nokeys"
touch "$FAKE/refuse-42.3.1"
attention "values the chart refuses" 42.3.1 "chart 42.3.1 refuses RunR's values on Kubernetes 1.30.0"
rm -f "$FAKE/refuse-42.3.1"
echo "ok - a new major, a new app version of a mirrored image, another kubeVersion, other objects, changed CRDs, a dropped, ignored or refused value go to a person, nothing left changed"

# Zot pins its image straight from upstream: a new app version moves it with the chart
rm -rf "$FAKE/charts"
chart 0.1.130 v2.1.23
chart 0.1.131 v2.1.24
bump zot 0.1.131
[ "$rc" = 0 ] || fail "zot 0.1.131: exit ${rc}: $(cat "$out") $(cat "$report/summary.md" 2>/dev/null)"
grep -qF '"ghcr.io/project-zot/zot:v2.1.24"' "$repo/runtime/lib/test.sh" || fail "the Zot image: $(cat "$repo/runtime/lib/test.sh")"
grep -qF 'ZOT_CHART_VERSION 0.1.131' "$repo/runtime/lib/test.sh" || fail "the Zot pin in the preload comment"
grep -qF '= zot v2.1.24' "$repo/api/app/Services/ZotManifests.php" || fail "the Zot version in a comment"
grep -qF "MANIFEST_CHECK_INTERVAL_SINCE = '2.1.23'" "$repo/api/app/Services/ZotManifests.php" || fail "a since-version moved"
[ "$(head -n 1 "$report/commit-msg.txt")" = "fix(regions): zot chart 0.1.131 (zot v2.1.24)" ] || fail "zot's subject: $(head -n 1 "$report/commit-msg.txt")"
[ "$(jq -r '.[] | select(.suite == "upgrade") | .command' "$report/suites.json")" = "test-helm-upgrade --from=v0.6.7 --after-upgrade=registry:upgrade-zot,{region} --expect-release=runr-registry/runr-zot=zot-0.1.131" ] \
  || fail "zot's upgrade suite: $(cat "$report/suites.json")"
echo "ok - a Zot bump moves its image with the chart, its upgrade suite runs registry:upgrade-zot"

# --- Knative ---------------------------------------------------------------------------------------
new_repo
printf '1.25.0\n1.24.0\n1.23.1\n' > "$FAKE/knative-versions"
export DEPS_KIND_NODE_TAGS="1.32.0 1.34.3 1.35.0"
bump knative 1.25
[ "$rc" = 2 ] || fail "knative without a Kind node: exit ${rc}: $(cat "$out")"
grep -qF 'no Kind node image of it' "$report/summary.md" || fail "knative's reason: $(cat "$report/summary.md")"
[ -z "$(changed)" ] || fail "knative left $(changed)"
export DEPS_KIND_NODE_TAGS="1.32.0 1.35.0 1.36.2 1.36.0"
bump knative 1.25
[ "$rc" = 0 ] || fail "knative 1.25: exit ${rc}: $(cat "$out") $(cat "$report/summary.md" 2>/dev/null)"
grep -qE '^        25 => 36,$' "$repo/api/app/Services/Helpers/KnativeVersionHelper.php" || fail "the row: $(cat "$repo/api/app/Services/Helpers/KnativeVersionHelper.php")"
grep -qF "'1.25' => '1.25.0'," "$repo/api/app/Services/Helpers/KnativeVersionHelper.php" || fail "the fallback chart"
[ "$(sed -n '/^|---|---|$/{n;p;q}' "$repo/docs/regions.md")" = "| 1.25 | 1.36 |" ] || fail "the docs table: $(cat "$repo/docs/regions.md")"
[ "$(jq -r '.[0].k8s' "$report/suites.json")" = 1.36.0 ] || fail "the e2e's Kind node: $(cat "$report/suites.json")"
[ "$(jq -r '.[0].env' "$report/suites.json")" = "TEST_AFTER_E2E=ci/deps/after-e2e.sh DEPS_EXPECT_KNATIVE=1.25" ] || fail "knative's check: $(cat "$report/suites.json")"
[ "$(head -n 1 "$report/commit-msg.txt")" = "fix(regions): Knative 1.25" ] || fail "knative's subject"
grep -qF -- '- **Knative 1.25** is offered' "$repo/CHANGELOG.md" || fail "knative's changelog line"
(cd "$repo" && git commit -q -a -m knative)
bump knative 1.25
[ "$rc" = 3 ] || fail "knative on already: exit ${rc}: $(cat "$out")"
bump knative 1.26
[ "$rc" = 2 ] && grep -qF 'no unreleased row for Knative 1.26' "$report/summary.md" || fail "a minor without its row: exit ${rc}: $(cat "$report/summary.md" 2>/dev/null)"
echo "ok - Knative: the row through check-knative-releases.sh, the docs table, a Kind node of its Kubernetes; without a row or a node it goes to a person"

# --- ci/deps/after-e2e.sh --------------------------------------------------------------------------
cat > "$tmp/bin/helm" <<'EOF'
#!/usr/bin/env bash
echo '[{"name":"traefik","namespace":"knative-serving","chart":"traefik-42.3.1"}]'
EOF
cat > "$tmp/bin/kubectl" <<'EOF'
#!/usr/bin/env bash
echo '{"items":[{"spec":{"version":"1.24"},"status":{"version":"1.24.0"}}]}'
EOF
chmod +x "$tmp/bin/helm" "$tmp/bin/kubectl"
DEPS_EXPECT=knative-serving/traefik=traefik-42.3.1 bash "$root/ci/deps/after-e2e.sh" http://x kubeconfig > "$out" || fail "the release runs the chart: $(cat "$out")"
rc=0
DEPS_EXPECT=knative-serving/traefik=traefik-42.3.2 bash "$root/ci/deps/after-e2e.sh" http://x kubeconfig > "$out" || rc=$?
[ "$rc" = 1 ] && grep -qF 'runs traefik-42.3.1, not traefik-42.3.2' "$out" || fail "another chart: exit ${rc}: $(cat "$out")"
DEPS_EXPECT_KNATIVE=1.24 bash "$root/ci/deps/after-e2e.sh" http://x kubeconfig > "$out" || fail "Knative 1.24: $(cat "$out")"
rc=0
DEPS_EXPECT_KNATIVE=1.25 bash "$root/ci/deps/after-e2e.sh" http://x kubeconfig > "$out" || rc=$?
[ "$rc" = 1 ] || fail "another Knative minor passes"
echo "ok - after-e2e.sh fails when the region does not run the version under test"
