#!/usr/bin/env bash
# Tests the dependency test bot's release (ci/deps-release.sh, ci/deps-run.sh's release steps,
# ci/deps/release-pins.sh, ci/deps/check-attribution.sh, ci/deps/sign-tag.sh) without GitHub or
# GitLab: the origin and the public repository are bare git repositories, gh, crane, curl and helm
# are stubs. Checks that:
#   - the release builds on the last release tag only when the origin's main is it, next contains
#     it and the public main is its public commit with the same tree;
#   - a bump is made on the tag and gets the release commit of the next patch version: the chart's
#     version, the docs' pins of the chart and the tag (not the CLI's, not landing/), CHANGELOG.md's
#     section with its upgrade notes from [Unreleased], docs/upgrade.md's section; a pin the rules do
#     not know sends it to a person, with nothing left changed;
#   - next takes the release as a fast-forward when it is still the tag, or as a merge whose
#     CHANGELOG.md and docs/upgrade.md put the release's section under next's [Unreleased]; another
#     conflict needs a person;
#   - the publish pushes the origin's main, next and the signed tag in one push and the public main
#     (one commit of the release's tree, as the bot) and its signed tag, fast-forwards only; a moved
#     main stops it before any push, a moved next is merged again; it can run again; a dry run
#     pushes nothing;
#   - the images, the GitHub release and the chart are checked: a digest the registry does not
#     serve, a failed run or another chart stops the release with the reason;
#   - the issue gets what happened: released (label, comment, close), moved (tested again), failed
#     (label, what is done, how to resume); one that waits gets a comment and no label; a row of the
#     weekly summary (ref <summary>:<group>) gets no action of its own but its row (shipped, red split
#     out to an issue, a failed release for the run's issue with its resume ref), and resume takes
#     its ref;
#   - one run, one release: the green bumps are made again one after the other on the tag (an
#     urgent one first, then by issue), with one release commit (a trailer and a changelog line
#     each, the notes and the tags name all), tested together with the union of their suites; a red
#     one is left out, a group the tag's bump does not know is held; the bumps of next only land in
#     one fast-forward; a re-make that is not mechanical stops the batch, a combined tree that fails
#     a suite releases the largest prefix whose suites passed; a moved base tests the batch again;
#   - the attribution rule: the bot's commits only outside vue/ and landing/, without
#     Co-Authored-By, no unknown identity, no internal name.
# The signing (real gpg in a keyring of its own) runs on Linux only.
#
#   bash runtime/test/deps_release_test.sh
# shellcheck disable=SC2016 # the fixture's Markdown, not this shell's expressions
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'GNUPGHOME="$tmp/gpg" gpgconf --kill gpg-agent >/dev/null 2>&1 || true; rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export GIT_AUTHOR_NAME=someone GIT_AUTHOR_EMAIL=someone@example.test GIT_COMMITTER_NAME=someone GIT_COMMITTER_EMAIL=someone@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
# the bot's identity is that of RunR's public history; its trailer tells its commits apart
export DEPS_BOT_NAME="Burak Faruk ŞAHİN" DEPS_BOT_EMAIL="19393636+brkfun@users.noreply.github.com"
BOT_ID="${DEPS_BOT_NAME} <${DEPS_BOT_EMAIL}>"
export DEPS_RELEASE_DATE=2026-11-14
# the scrub pattern of the release (the secret RUNR_SCRUB_PATTERN): a placeholder here
export DEPS_FORBIDDEN="internal-name|another-internal-host"
BURAK_ENV=(GIT_AUTHOR_NAME='Burak Faruk ŞAHİN' GIT_AUTHOR_EMAIL='19393636+brkfun@users.noreply.github.com'
           GIT_COMMITTER_NAME='Burak Faruk ŞAHİN' GIT_COMMITTER_EMAIL='19393636+brkfun@users.noreply.github.com')

# --- the origin: v1.2.3 on main, next one commit ahead -------------------------------------------
src="$tmp/src"
git init -q -b main "$src"
mkdir -p "$src"/{helm,docs,actions/deploy,compose,landing,api,ci,runtime,vue}
cat > "$src/helm/Chart.yaml" <<'EOF'
apiVersion: v2
name: runr
version: 1.2.3
appVersion: "1.2.3"
EOF
echo 'replicas: 1' > "$src/helm/values.yaml"
cat > "$src/CHANGELOG.md" <<'EOF'
# Changelog

## [Unreleased]

## [1.2.3] - 2026-01-02

### Changed

- Something of 1.2.3.

[Unreleased]: https://github.com/brf-tech/runr/compare/v1.2.3...main
[1.2.3]: https://github.com/brf-tech/runr/releases/tag/v1.2.3
EOF
cat > "$src/docs/upgrade.md" <<'EOF'
# Upgrading

## Procedure

Back up the database.

## 1.2.2 → 1.2.3

Nothing to do.
EOF
echo 'To install a specific release, pin the chart version (`--version 1.2.3`); the images follow it.' > "$src/README.md"
printf '%s\n' "<!-- README.md'nin 1.2.3 sürümüne göre çevirisi -->" 'Bir sürümü sabitlemek için (`--version 1.2.3`).' > "$src/README.tr.md"
printf '%s\n' '      - uses: brf-tech/runr/actions/deploy@v1.2.3' '| `version` | `latest` | runr CLI release (`latest` or a tag such as `v1.2.3`) |' > "$src/actions/deploy/README.md"
echo 'git clone --branch v1.2.3 https://github.com/BRF-Tech/runr.git' > "$src/compose/README.md"
echo 'curl -fsSL https://runr.sh/install.sh | RUNR_VERSION=v1.2.3 sh             # a specific release' > "$src/docs/cli.md"
printf '%s\n' '      - uses: brf-tech/runr/actions/deploy@v1.2.3' '    RUNR_VERSION: v1.2.3' > "$src/docs/github-action.md"
echo 'Every value of the Helm chart `runr` 1.2.3.' > "$src/docs/helm-values.md"
echo 'helm upgrade runr brf-tech/runr --version 1.2.3 -n runr --reuse-values' > "$src/docs/install.md"
echo 'docker build -t registry.example.com/runr/ui:1.2.3-mt .' > "$src/docs/modules.md"
echo 'with `ui.image.tag: "1.2.3"` it runs ui:1.2.3-mt; Kata 11.2.3 and 1.2.30 stay.' > "$src/docs/tenancy.md"
echo '<span>v1.2.3</span> "softwareVersion": "1.2.3"' > "$src/landing/index.html"
echo '# RUNR_VERSION=v1.2.3     install this release instead of the latest one' > "$src/landing/install.sh"
echo 'console' > "$src/vue/app.txt"
echo "const TRAEFIK = '2.0.0';" > "$src/api/pins.php"
echo "const ZOT = '0.1.0';" >> "$src/api/pins.php"
printf '%s\n' "const LOKI_STACK = '2.10.2';" "const KUBE_PROMETHEUS_STACK = '93.0.0';" "const KATA_DEPLOY = '4.3.1';" >> "$src/api/pins.php"
# the image of a chart RunR pins itself (Zot's): its app version moves with the chart
echo "const ZOT_IMAGE = 'ghcr.io/project-zot/zot:v2.1.0';" > "$src/api/zot-image.php"
# what bumps of next only move (the fake's actions and go-cli groups)
mkdir -p "$src/cli"
echo 'module example.test/cli' > "$src/cli/go.mod"
echo 'actions/checkout v7.0.1' > "$src/ci/actions-pins.txt"
# the dependencies the tag's own bump knows (its DEPS_BOT rows; no ci/deps/packages.sh: the tag is
# older than the package groups, as a release-path group's bump finds it)
mkdir -p "$src/ci/deps"
printf '%s\n' 'DEPS_BOT=(' '  "traefik|regions|traefik chart"' '  "kube-prometheus-stack|regions|kube-prometheus-stack chart"' \
  '  "loki-stack|regions|loki-stack chart"' '  "zot|regions|zot chart"' '  "kata-deploy|kata|kata-deploy"' '  "knative|regions|Knative"' ')' > "$src/ci/deps/lib.sh"
# the release's region images (runtime/ci.sh release-images), for the images step
cat > "$src/runtime/ci.sh" <<'EOF'
#!/usr/bin/env bash
v="$(sed -n 's/^version: //p' "$(dirname "$0")/../helm/Chart.yaml")"
[ "$1" = release-images ] && printf '%s\n' "ghcr.io/brf-tech/runr/agent:${v}" "ghcr.io/brf-tech/runr/gateway-auth:1.0.0"
EOF
# the tag's own bump: the pin moves and the changelog gets its line under [Unreleased] → Changed.
# FAKE_MODE=batch: every chart of api/pins.php moves mechanically (a run of several green bumps),
# actions and go-cli are bumps of next only; FAKE_REMAKE_ATTENTION: the charts whose bump needs a
# person when it is made on another commit than a release tag (made again on the batch);
# FAKE_ZOT_APP="OLD NEW": Zot's chart runs a new app version, which the bump moves (and says so, as
# ci/deps-bump.sh does) only when it reads the chart (not with --no-render)
cat > "$src/ci/deps-bump.sh" <<'EOF'
#!/usr/bin/env bash
dep="$1" version="$2" report="${3#--report=}"
cd "$(dirname "$0")/.."
mkdir -p "$report"
on_tag=0
git describe --tags --exact-match HEAD >/dev/null 2>&1 && on_tag=1
case " ${FAKE_REMAKE_ATTENTION:-} " in
  *" ${dep} "*) [ "$on_tag" = 1 ] || { printf -- '- the bump needs a person on this tree\n' > "$report/summary.md"; echo attention > "$report/result"; exit 2; } ;;
esac
next=0
case "$dep" in
  traefik)
    grep -q "TRAEFIK = " api/pins.php || { printf -- '- the pin is gone\n' > "$report/summary.md"; echo attention > "$report/result"; exit 2; }
    sed -i "s/TRAEFIK = '[^']*'/TRAEFIK = '${version}'/" api/pins.php; label="Region Traefik chart ${version}" ;;
  zot|loki-stack|kube-prometheus-stack|kata-deploy)
    if [ "${FAKE_MODE:-}" = batch ]; then
      c="$(tr 'a-z-' 'A-Z_' <<<"$dep")"
      sed -i "s/^const ${c} = '[^']*'/const ${c} = '${version}'/" api/pins.php; label="${dep} chart ${version}"
      if [ "$dep" = zot ] && [ -n "${FAKE_ZOT_APP:-}" ]; then
        case " $* " in
          *" --no-render "*) ;;
          *) sed -i "s/zot:${FAKE_ZOT_APP% *}'/zot:${FAKE_ZOT_APP#* }'/" api/zot-image.php
             app_line="Zot chart 0.1.0 → ${version}, app version ${FAKE_ZOT_APP% *} → ${FAKE_ZOT_APP#* }." ;;
        esac
      fi
    else
      case "$dep" in
        zot) sed -i "s/ZOT = '[^']*'/ZOT = '${version}'/" api/pins.php; label="Zot chart ${version}"
             # a line the release's pin rules do not know: it names the release
             echo "Zot runs in every region since RunR 1.2.3." > docs/zot.md; git add docs/zot.md ;;
        loki-stack) echo "the last release pins it already" > "$report/summary.md"; echo current > "$report/result"; exit 3 ;;
        kube-prometheus-stack) echo "**needs a person**: a new major" > "$report/summary.md"; echo attention > "$report/result"; exit 2 ;;
        *) echo "cannot read the chart repository"; exit 1 ;;
      esac
    fi ;;
  actions) echo "actions/checkout ${version}" >> ci/actions-pins.txt; label="GitHub Actions pins ${version}"; next=1 ;;
  go-cli) echo "// ${version}" >> cli/go.mod; label="CLI Go modules ${version}"; next=1 ;;
  *) echo "cannot read the chart repository"; exit 1 ;;
esac
# (as ci/deps/changelog.awk: after the last bullet of [Unreleased] → Changed, which is made first)
awk -v line="- **${label}** (from the pinned one). New regions install it." '
  { l[NR] = $0 }
  END {
    for (i = 1; i <= NR; i++) if (l[i] == "## [Unreleased]") { u = i; break }
    for (i = u + 1; i <= NR && l[i] !~ /^## \[/; i++) if (l[i] == "### Changed") { c = i; break }
    if (c) {
      last = c + 1
      for (i = c + 1; i <= NR && l[i] !~ /^##/; i++) if (l[i] ~ /^- /) last = i
      for (i = 1; i <= NR; i++) { print l[i]; if (i == last) print line }
    } else {
      for (i = 1; i <= NR; i++) { print l[i]; if (i == u) { print ""; print "### Changed"; print ""; print line } }
    }
  }' CHANGELOG.md > CHANGELOG.tmp && cat CHANGELOG.tmp > CHANGELOG.md && rm CHANGELOG.tmp
echo "the ${dep} bump" > "$report/summary.md"
if [ "$next" = 1 ]; then
  echo "fix(ci): ${label}" > "$report/commit-msg.txt"
  echo next > "$report/path"
  : > "$report/unit"
  check="actionlint"; [ "$dep" = actions ] || check="go:cli"
  printf '[{"suite":"%s","job":"check","command":"%s"}]\n' "$(tr ':' '-' <<<"$check")" "$check" > "$report/suites.json"
else
  { echo "fix(regions): ${label}"; [ -z "${app_line:-}" ] || printf '\n%s\n' "$app_line"; } > "$report/commit-msg.txt"
  echo release > "$report/path"
  echo 1 > "$report/unit"
  upgrade="test-helm-upgrade --from=v1.2.3"
  [ "${FAKE_MODE:-}" != batch ] || upgrade="${upgrade} --expect-release=ns/${dep}=${dep}-${version}"
  printf '[{"suite":"e2e","command":"test","k8s":"1.37.0","kvm":false,"env":""},{"suite":"upgrade","command":"%s","k8s":"1.37.0","kvm":false,"env":""}]\n' "$upgrade" > "$report/suites.json"
fi
echo bumped > "$report/result"
EOF
(cd "$src" && git add -A && env "${BURAK_ENV[@]}" git commit -q -m "chore(release): 1.2.3" && env "${BURAK_ENV[@]}" git tag -a -m "RunR 1.2.3" v1.2.3)
tag_commit="$(git -C "$src" rev-parse 'v1.2.3^{commit}')"
# next: unfinished work after the release
git -C "$src" checkout -q -b next
awk '{ print } $0 == "## [Unreleased]" { print ""; print "### Added"; print ""; print "- **A feature of next.**" }' "$src/CHANGELOG.md" > "$tmp/cl" && cat "$tmp/cl" > "$src/CHANGELOG.md"
echo "next only" > "$src/api/feature.php"
(cd "$src" && git add -A && git commit -q -m "feat: a feature of next")
next_commit="$(git -C "$src" rev-parse HEAD)"
origin="$tmp/origin.git"
git clone -q --bare "$src" "$origin"
git --git-dir="$origin" symbolic-ref HEAD refs/heads/next

# --- the public repository: its own commit of v1.2.3's tree, tagged ------------------------------
public="$tmp/public.git"
git init -q --bare "$public"
git -C "$public" fetch -q "$origin" "refs/tags/v1.2.3:refs/tmp/v1.2.3"
tree123="$(git -C "$public" rev-parse 'refs/tmp/v1.2.3^{tree}')"
pub_old="$(printf 'RunR 1.2.2\n' | env "${BURAK_ENV[@]}" git -C "$public" commit-tree "$(git -C "$public" hash-object -t tree -w /dev/null)")"
pub_base="$(printf 'RunR 1.2.3\n' | env "${BURAK_ENV[@]}" git -C "$public" commit-tree "$tree123" -p "$pub_old")"
git -C "$public" update-ref refs/heads/main "$pub_base"
git -C "$public" update-ref -d refs/tmp/v1.2.3
printf 'object %s\ntype commit\ntag v1.2.3\ntagger Burak Faruk ŞAHİN <brkfun@users.noreply.github.com> 1700000000 +0300\n\nRunR 1.2.3\n' "$pub_base" \
  | git -C "$public" mktag > "$tmp/pubtag" && git -C "$public" update-ref refs/tags/v1.2.3 "$(cat "$tmp/pubtag")"
# (as they are now, for the runs of several green bumps further down)
cp -r "$origin" "$tmp/origin0.git"
cp -r "$public" "$tmp/public0.git"

clone="$tmp/clone"
git clone -q --no-checkout "$origin" "$clone"

# --- base -------------------------------------------------------------------------------------------
out="$(bash "$root/ci/deps-release.sh" base "$clone" "$public")" || fail "base: ${out}"
[ "$out" = "$(printf 'BASE_TAG=v1.2.3\nBASE_COMMIT=%s\nPUBLIC_BASE=%s\nNEW_VERSION=1.2.4\nNEW_TAG=v1.2.4' "$tag_commit" "$pub_base")" ] || fail "base: ${out}"
# expect_base_error PATTERN — base refuses with a message matching PATTERN
expect_base_error() {
  local rc=0 err
  err="$(bash "$root/ci/deps-release.sh" base "$clone" "$public" 2>&1 >/dev/null)" || rc=$?
  [ "$rc" = 1 ] && grep -qE "$1" <<<"$err" || fail "base should refuse (${1}): exit ${rc}: ${err}"
}
extra="$(printf 'docs\n' | env "${BURAK_ENV[@]}" git -C "$public" commit-tree "$tree123" -p "$pub_base")"
git -C "$public" update-ref refs/heads/main "$extra"
expect_base_error 'public main .* has commits after v1\.2\.3'
git -C "$public" update-ref refs/heads/main "$pub_base"
git -C "$clone" update-ref refs/remotes/origin/main "$next_commit"
expect_base_error "origin's main .* is not the last release v1\.2\.3"
git -C "$clone" update-ref refs/remotes/origin/main "$tag_commit"
git -C "$public" tag -d v1.2.3 >/dev/null
expect_base_error 'has no v1\.2\.3.*resume=v1\.2\.3'
git -C "$public" update-ref refs/tags/v1.2.3 "$(cat "$tmp/pubtag")"
other="$(printf 'RunR 1.2.3\n' | env "${BURAK_ENV[@]}" git -C "$public" commit-tree "$(git -C "$public" hash-object -t tree -w /dev/null)" -p "$pub_old")"
printf 'object %s\ntype commit\ntag v1.2.3\ntagger B <b@example.test> 1700000000 +0300\n\nx\n' "$other" | git -C "$public" mktag > "$tmp/othertag"
git -C "$public" update-ref refs/tags/v1.2.3 "$(cat "$tmp/othertag")"
git -C "$public" update-ref refs/heads/main "$other"
expect_base_error 'another tree'
git -C "$public" update-ref refs/tags/v1.2.3 "$(cat "$tmp/pubtag")"
git -C "$public" update-ref refs/heads/main "$pub_base"
echo "ok - the base: the last release tag, only when the origin's main is it, next contains it and the public main is its public commit with the same tree"

# --- the bumps: on the tag, with the release commit ---------------------------------------------
cat > "$tmp/tests.json" <<'EOF'
[{"issue": 20, "dep": "traefik", "version": "2.0.1"}, {"issue": 14, "dep": "zot", "version": "0.2.0"},
 {"issue": 8, "dep": "kube-prometheus-stack", "version": "93.0.0"}, {"issue": 15, "dep": "loki-stack", "version": "2.10.3"},
 {"issue": 21, "dep": "kata-deploy", "version": "4.3.2"}]
EOF
dir="$tmp/deps"
bash "$root/ci/deps-run.sh" bumps "$tmp/tests.json" "$clone" "$dir" "$public" https://example.test/run/1 > "$tmp/bumps.log" 2>&1 \
  || fail "bumps: $(tail -n 30 "$tmp/bumps.log")"
report="$dir/bump/traefik"
release="$(cat "$report/head")"
bump="$(cat "$report/bump")"
[ "$(git -C "$clone" rev-parse "${release}^")" = "$bump" ] && [ "$(git -C "$clone" rev-parse "${bump}^")" = "$tag_commit" ] \
  || fail "the release is not the bump's release commit on v1.2.3"
[ "$(git -C "$clone" rev-parse deps/traefik)" = "$release" ] || fail "deps/traefik is not the release commit"
[ "$(git -C "$clone" log -2 --format='%an <%ae>|%cn <%ce>' "$release" | sort -u)" = "${BOT_ID}|${BOT_ID}" ] \
  || fail "the bump and the release commit are not the bot's: $(git -C "$clone" log -2 --format='%an <%ae>|%cn <%ce>' "$release")"
[ "$(git -C "$clone" log -1 --format=%s "$release")" = "chore(release): 1.2.4" ] || fail "the release commit's subject"
# the bump and the release commit carry the bot's trailer (the bump's dependency and version)
for c in "$bump" "$release"; do
  [ "$(git -C "$clone" log -1 --format='%(trailers:key=RunR-Deps-Bot,valueonly)' "$c" | sed '/^$/d')" = "traefik 2.0.1" ] \
    || fail "the bot's trailer of ${c:0:12}: $(git -C "$clone" log -1 --format=%B "$c" | tail -n 3)"
done
git -C "$clone" log -1 --format=%B "$release" | grep -qi 'co-authored-by' && fail "the release commit has a trailer"
show() { git -C "$clone" show "${release}:$1"; }
show helm/Chart.yaml | grep -qx 'version: 1.2.4' && show helm/Chart.yaml | grep -qx 'appVersion: "1.2.4"' || fail "Chart.yaml: $(show helm/Chart.yaml)"
show README.md | grep -qF '(`--version 1.2.4`)' || fail "README's pin"
show README.tr.md | head -n 1 | grep -qF "1.2.3 sürümüne" || fail "README.tr.md's first line moved"
show actions/deploy/README.md | grep -qF 'deploy@v1.2.4' || fail "the action's pin"
show actions/deploy/README.md | grep -qF 'a tag such as `v1.2.3`' || fail "the action's CLI release moved: a dependency release builds no CLI"
show docs/cli.md | grep -qF 'RUNR_VERSION=v1.2.3 sh' || fail "the CLI's pin moved"
show docs/github-action.md | grep -qF 'RUNR_VERSION: v1.2.3' || fail "the action's CLI pin moved"
show docs/tenancy.md | grep -qF '"1.2.4"` it runs ui:1.2.4-mt; Kata 11.2.3 and 1.2.30 stay.' || fail "tenancy.md: $(show docs/tenancy.md)"
[ -z "$(git -C "$clone" diff --name-only "$tag_commit" "$release" -- landing vue .github)" ] || fail "the release changed landing/, vue/ or .github/"
show CHANGELOG.md > "$tmp/changelog"
awk '$0 == "## [Unreleased]" { u = 1; next } u && /^## \[/ { print; exit } u && NF { print "UNRELEASED: " $0; exit }' "$tmp/changelog" | grep -qx '## \[1.2.4\] - 2026-11-14' \
  || fail "[Unreleased] is not empty, or not followed by [1.2.4]: $(head -n 20 "$tmp/changelog")"
section="$(awk '/^## \[1\.2\.4\]/ { on = 1 } on && /^## \[1\.2\.3\]/ { exit } on { print }' "$tmp/changelog")"
grep -qF '### Upgrade notes' <<<"$section" && grep -qF 'A dependency release of 1.2.3: upgrading needs no step.' <<<"$section" \
  && grep -qF '(docs/upgrade.md#123--124)' <<<"$section" && grep -qF 'stay those of 1.2.3' <<<"$section" || fail "the upgrade notes: ${section}"
grep -qF -- '- **Region Traefik chart 2.0.1** (from the pinned one).' <<<"$section" || fail "the bump's line: ${section}"
[ "$(grep -c '^### Upgrade notes' <<<"$section")$(grep -c '^### Changed' <<<"$section")" = 11 ] || fail "the sections: ${section}"
grep -qx '\[Unreleased\]: https://github.com/brf-tech/runr/compare/v1.2.4...main' "$tmp/changelog" \
  && grep -qx '\[1.2.4\]: https://github.com/brf-tech/runr/releases/tag/v1.2.4' "$tmp/changelog" || fail "the links: $(tail -n 4 "$tmp/changelog")"
show docs/upgrade.md > "$tmp/upgrade"
[ "$(grep -E '^## ' "$tmp/upgrade" | paste -sd '|' -)" = "## Procedure|## 1.2.3 → 1.2.4|## 1.2.2 → 1.2.3" ] || fail "upgrade.md's sections: $(grep '^## ' "$tmp/upgrade")"
grep -qF 'ci.sh test-helm-upgrade --from=v1.2.3' "$tmp/upgrade" && grep -qF '([run](https://example.test/run/1))' "$tmp/upgrade" || fail "upgrade.md's suites: $(cat "$tmp/upgrade")"
[ "$(cat "$report/merge")" != "$release" ] && [ "$(git -C "$clone" rev-list --parents -n 1 "$(cat "$report/merge")" | cut -d' ' -f2-)" = "${next_commit} ${release}" ] \
  || fail "the merge preview: $(cat "$report/merge" "$report/merge.err" 2>/dev/null)"
[ "$(jq -c '[.[] | [.dep, .suite, .ref, .issue]]' "$dir/matrix-suites.json")" = '[["traefik","e2e","deps/traefik",20],["traefik","upgrade","deps/traefik",20]]' ] \
  || fail "the suite jobs: $(cat "$dir/matrix-suites.json")"
[ "$(jq -c '[.[].dep]' "$dir/matrix-unit.json")" = '["traefik"]' ] || fail "the unit jobs: $(cat "$dir/matrix-unit.json")"
[ "$(cat "$dir/bump/kube-prometheus-stack/result") $(cat "$dir/bump/loki-stack/result") $(cat "$dir/bump/kata-deploy/result")" = "attention current error" ] \
  || fail "a bump that needs a person, has nothing to do or failed: $(cat "$dir"/bump/{kube-prometheus-stack,loki-stack,kata-deploy}/result)"
[ "$(git -C "$clone" rev-parse HEAD)" = "$tag_commit" ] || fail "the clone is not back on the tag"
echo "ok - the bump is made on the tag and gets the release commit of 1.2.4: the chart, the pins of the chart and the tag (not the CLI's, not landing/), CHANGELOG.md's section with its upgrade notes, docs/upgrade.md's section"

[ "$(cat "$dir/bump/zot/result")" = attention ] || fail "zot: a pin the rules do not know: $(cat "$dir/bump/zot/result")"
grep -qF 'docs/zot.md:1:Zot runs in every region since RunR 1.2.3.' "$dir/bump/zot/summary.md" || fail "zot: the reason: $(cat "$dir/bump/zot/summary.md")"
grep -qF 'No suite ran, nothing was pushed' "$dir/bump/zot/summary.md" || fail "zot: the summary"
[ ! -s "$dir/bump/zot/head" ] && ! git -C "$clone" rev-parse -q --verify deps/zot >/dev/null || fail "zot: a branch for a bump that needs a person"
[ -z "$(git -C "$clone" status --porcelain)" ] || fail "the clone is left changed: $(git -C "$clone" status --porcelain)"
echo "ok - a pin the release's rules do not know sends the bump to a person, no suite runs, nothing is left changed"

# --- merge-next ---------------------------------------------------------------------------------------
m="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$tag_commit")" && [ "$m" = "$release" ] || fail "next = the tag: not a fast-forward to the release: ${m}"
m="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$release")" && [ "$m" = "$release" ] || fail "a next with the release: ${m}"
m="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$next_commit")" || fail "merge-next: ${m}"
git -C "$clone" show "${m}:CHANGELOG.md" > "$tmp/merged"
[ "$(grep -E '^(## |### |- \*\*)' "$tmp/merged" | head -n 9 | cut -c1-48 | paste -sd '|' -)" = "## [Unreleased]|### Added|- **A feature of next.**|## [1.2.4] - 2026-11-14|### Upgrade notes|- **A dependency release of 1.2.3: upgrading nee|### Changed|- **Region Traefik chart 2.0.1** (from the pinne|## [1.2.3] - 2026-01-02" ] \
  || fail "next's CHANGELOG.md: $(grep -E '^(## |### |- )' "$tmp/merged")"
[ "$(grep -c '^\[1.2.4\]: ' "$tmp/merged")" = 1 ] && grep -qx '\[Unreleased\]: https://github.com/brf-tech/runr/compare/v1.2.4...main' "$tmp/merged" || fail "next's links: $(tail -n 4 "$tmp/merged")"
[ "$(git -C "$clone" show "${m}:docs/upgrade.md" | grep -cE '^## 1\.2\.3 → 1\.2\.4$')" = 1 ] || fail "next's upgrade.md"
git -C "$clone" show "${m}:helm/Chart.yaml" | grep -qx 'version: 1.2.4' || fail "next's chart"
git -C "$clone" cat-file -e "${m}:api/feature.php" || fail "next's own work is gone"
[ "$(git -C "$clone" log -1 --format='%an <%ae>|%s' "$m")" = "${BOT_ID}|Merge RunR 1.2.4 into next" ] || fail "the merge commit: $(git -C "$clone" log -1 --format='%an <%ae>|%s' "$m")"
git -C "$clone" log -1 --format=%B "$m" | grep -qx 'RunR-Deps-Bot: traefik 2.0.1' || fail "the merge commit has no trailer"
# next changed the lines the release moves (a pin's sentence, the bump's pin): the bump is made
# again on next by next's own ci/deps-bump.sh, the pins move on next's text
conflicting="$(cd "$src" && git checkout -q next && echo 'Pin the chart (`--version 1.2.3`) differently.' > README.md \
  && sed -i "s/TRAEFIK = '2.0.0';/TRAEFIK = '2.0.0'; \/\/ the region's Traefik/" api/pins.php && git commit -q -am "docs: README" && git rev-parse HEAD)"
git -C "$clone" fetch -q "$src" "$conflicting"
rc=0
err="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$conflicting" 2>&1 >/dev/null)" || rc=$?
[ "$rc" = 2 ] && grep -qF 'README.md' <<<"$err" || fail "a conflict without the dependency to make again: exit ${rc}: ${err}"
m="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$conflicting" traefik:2.0.1 2>"$tmp/merge.err")" || fail "made again on next: $(cat "$tmp/merge.err")"
[ "$(git -C "$clone" show "${m}:README.md")" = 'Pin the chart (`--version 1.2.4`) differently.' ] || fail "next's README: $(git -C "$clone" show "${m}:README.md")"
[ "$(git -C "$clone" show "${m}:api/pins.php" | head -n 1)" = "const TRAEFIK = '2.0.1'; // the region's Traefik" ] || fail "next's pin: $(git -C "$clone" show "${m}:api/pins.php")"
git -C "$clone" show "${m}:helm/Chart.yaml" | grep -qx 'version: 1.2.4' || fail "next's chart, made again"
[ "$(git -C "$clone" show "${m}:CHANGELOG.md" | grep -c 'Region Traefik chart 2.0.1')" = 1 ] || fail "the bump's line twice on next: $(git -C "$clone" show "${m}:CHANGELOG.md" | head -n 20)"
[ "$(git -C "$clone" rev-list --parents -n 1 "$m" | cut -d' ' -f2-)" = "${conflicting} ${release}" ] || fail "the merge's parents"
git -C "$clone" log -1 --format=%B "$m" | tr '\n' ' ' | grep -qF "next's own ci/deps-bump.sh made the bump again on it" || fail "the merge's message: $(git -C "$clone" log -1 --format=%B "$m")"
[ -z "$(git -C "$clone" worktree list --porcelain | grep -c '^worktree ' | grep -vx 1)" ] || fail "a worktree is left: $(git -C "$clone" worktree list)"
# next's own bump needs a person: so does the release's merge
gone="$(cd "$src" && echo 'Pin it (`--version 1.2.3`) again.' > README.md && sed -i '/TRAEFIK/d' api/pins.php && git commit -q -am "refactor: no pin" && git rev-parse HEAD)"
git -C "$clone" fetch -q "$src" "$gone"
rc=0
err="$(bash "$root/ci/deps-release.sh" merge-next "$clone" "$release" "$gone" traefik:2.0.1 2>&1 >/dev/null)" || rc=$?
[ "$rc" = 2 ] && grep -qF 'the pin is gone' <<<"$err" || fail "next's bump needs a person: exit ${rc}: ${err}"
echo "ok - next takes the release: a fast-forward when it is still the tag, else a merge with the release's sections under next's [Unreleased]; where next changed the lines the bump changes, next's own bump makes it again; else a person"

# --- notes --------------------------------------------------------------------------------------------
bash "$root/ci/deps-release.sh" notes "$clone" "$release" "$tmp/notes"
grep -qF 'RunR 1.2.4 is a dependency release of [1.2.3](https://github.com/brf-tech/runr/releases/tag/v1.2.3)' "$tmp/notes/notes.md" \
  && grep -qF -- '- **Region Traefik chart 2.0.1** (from the pinned one). New regions install it.' "$tmp/notes/notes.md" \
  && grep -qF 'helm upgrade runr brf-tech/runr --version 1.2.4 -n runr --reuse-values' "$tmp/notes/notes.md" \
  && grep -qF 'The bot ran these suites on this release before it was tagged: PHPUnit' "$tmp/notes/notes.md" \
  && grep -qF 'those of [RunR 1.2.3](https://github.com/brf-tech/runr/releases/tag/v1.2.3)' "$tmp/notes/notes.md" \
  && grep -qF 'upgrade.md#123--124' "$tmp/notes/notes.md" || fail "the release notes: $(cat "$tmp/notes/notes.md")"
grep -qi 'upgrading needs no step' "$tmp/notes/notes.md" && fail "the notes repeat the upgrade notes' bullet"
[ "$(head -n 1 "$tmp/notes/public-msg.txt")" = "RunR 1.2.4" ] && tr '\n' ' ' < "$tmp/notes/public-msg.txt" | grep -qF 'test bot: Region Traefik chart 2.0.1.' \
  && grep -qF '(https://example.test/run/1)' "$tmp/notes/public-msg.txt" || fail "the public commit's message: $(cat "$tmp/notes/public-msg.txt")"
awk 'length > 72' "$tmp/notes/public-msg.txt" | grep -q . && fail "the public message is wider than 72"
[ "$(cat "$tmp/notes/tag-msg.txt")" = "$(printf 'RunR 1.2.4\n\nA dependency release of 1.2.3: Region Traefik chart 2.0.1.\n\nRunR-Deps-Bot: traefik 2.0.1')" ] || fail "the tag message: $(cat "$tmp/notes/tag-msg.txt")"
tail -n 1 "$tmp/notes/public-msg.txt" | grep -qx 'RunR-Deps-Bot: traefik 2.0.1' || fail "the public commit's message has no trailer"
echo "ok - the texts: the release notes, the public commit's message and the tags' from the release commit"

# --- outcome, pick ---------------------------------------------------------------------------------
result() {
  mkdir -p "$dir/results/deps-result-$1-$2"
  echo "$3" > "$dir/results/deps-result-$1-$2/status"
  echo "$2" > "$dir/results/deps-result-$1-$2/command"
}
for s in unit shell e2e upgrade; do result traefik "$s" success; done
echo 0 > "$dir/results/deps-result-traefik-e2e/failed"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir"/outcome/{traefik,zot,kube-prometheus-stack,loki-stack,kata-deploy} | paste -sd ' ' -)" = "green attention attention current error" ] \
  || fail "outcomes: $(cat "$dir"/outcome/* | paste -sd ' ' -)"
[ "$(cat "$dir/green")" = traefik ] || fail "green: $(cat "$dir/green")"
rm -rf "$dir/results/deps-result-traefik-upgrade"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik")" = red ] || fail "a suite without a result passed"
result traefik upgrade success
# red: the label and what failed, nothing released; nothing to do: closed; an error: a comment
result traefik e2e failure
echo "- **xaas-python-3.15-http** [ready]: timed out" > "$dir/results/deps-result-traefik-e2e/failures.md"
echo "the end of the e2e log of traefik" > "$dir/results/deps-result-traefik-e2e/tail.txt"
bash "$root/ci/deps-run.sh" outcome "$dir"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qF "$(printf 'label\t20\tneeds attention')" "$dir/actions" && grep -qF 'xaas-python-3.15-http' "$dir/comments/20.md" \
  && grep -qF 'the end of the e2e log of traefik' "$dir/comments/20.md" && grep -qF 'Nothing was released or pushed' "$dir/comments/20.md" \
  || fail "red: $(cat "$dir/actions"; cat "$dir/comments/20.md")"
grep -qF "$(printf 'close\t15')" "$dir/actions" || fail "nothing to do: not closed"
grep -qF "$(printf 'comment\t21\t')" "$dir/actions" && ! grep -qF "$(printf 'label\t21')" "$dir/actions" || fail "an error: a comment, no label"
grep -qF "$(printf 'label\t8\tneeds attention')" "$dir/actions" && grep -qF 'a new major' "$dir/comments/8.md" || fail "attention: the reasons"
# the same bumps as rows of the weekly summary (ref <summary>:<group>): no action of their own, a row
# each for ci/deps-digest.sh (red: split out to an issue of its own with its report; nothing to
# do: closed), an issue of its own both
loki="$(cat "$dir/bump/loki-stack/issue")"
echo 90:traefik > "$dir/bump/traefik/ref"; echo 90:loki-stack > "$dir/bump/loki-stack/ref"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
! grep -qE "^[a-z]+	(20|${loki})(	|\$)" "$dir/actions" && grep -qF "$(printf 'label\t8\tneeds attention')" "$dir/actions" \
  || fail "rows of the summary got actions of their own: $(cat "$dir/actions")"
grep -qxF "$(printf '90:traefik\037chart\037traefik\0372.0.1\037red\037split\037failed on v1.2.3\037%s\037\037https://example.test/run/1' "$dir/comments/20-traefik.md")" "$dir/lines" \
  && grep -qF 'xaas-python-3.15-http' "$dir/comments/20-traefik.md" \
  && grep -qxF "$(printf '90:loki-stack\037chart\037loki-stack\037%s\037closed\037line\037the last release has it\037\037\037https://example.test/run/1' "$(cat "$dir/bump/loki-stack/version")")" "$dir/lines" \
  && grep -qF "$(printf '8\037chart\037')" "$dir/lines" || fail "the rows: $(cat -v "$dir/lines")"
rm -f "$dir/bump/traefik/ref" "$dir/bump/loki-stack/ref"
rm -f "$dir/results/deps-result-traefik-e2e/failures.md"
result traefik e2e success
echo 0 > "$dir/results/deps-result-traefik-e2e/failed"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik")" = green ] || fail "traefik is not green again"
# a suite whose job ended 0 while its results name failures failed (the filex bot's #1807)
echo 2 > "$dir/results/deps-result-traefik-e2e/failed"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik")" = red ] || fail "a success with failed tests in its results passed"
echo 0 > "$dir/results/deps-result-traefik-e2e/failed"
echo "- **x** [ready]: timed out" > "$dir/results/deps-result-traefik-e2e/failures.md"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik")" = red ] || fail "a success with failures.md passed"
rm -f "$dir/results/deps-result-traefik-e2e/failures.md"
bash "$root/ci/deps-run.sh" outcome "$dir"
# one green bump: a batch of one, the tree it was tested on alone, no second test phase
bash "$root/ci/deps-run.sh" batch "$dir" "$clone" > "$tmp/batch.log" 2>&1 || fail "batch: $(tail -n 20 "$tmp/batch.log")"
[ "$(cat "$dir/batch/release/members")" = traefik ] && [ "$(cat "$dir/batch/release/head")" = "$release" ] && [ ! -e "$dir/batch/release/tested" ] \
  && [ "$(cat "$dir"/batch/matrix-{suites,checks,unit,shell}.json | paste -sd ' ' -)" = '[] [] [] []' ] \
  && ! git -C "$clone" rev-parse -q --verify deps/batch >/dev/null || fail "a batch of one: $(cat "$dir"/batch/matrix-*.json "$tmp/batch.log")"
[ "$(bash "$root/ci/deps-run.sh" pick "$dir" "$clone")" = "$(printf 'release=true\nland=false')" ] || fail "pick: no release"
st="$dir/release"
grep -qx "RELEASE=${release}" "$st/release.env" && grep -qx "PUBLIC_BASE=${pub_base}" "$st/release.env" && grep -qx "REPO=${clone}" "$st/release.env" \
  && grep -qx 'DEPS=traefik:2.0.1' "$st/release.env" && [ "$(cat "$dir/role/traefik")" = release ] || fail "release.env: $(cat "$st/release.env")"
echo "ok - green only when every suite passed by its job and by its results file; one green bump is a batch of one: released as tested alone, no second phase"

# --- publish ----------------------------------------------------------------------------------------
export ORIGIN_REMOTE="$origin" PUBLIC_REMOTE="$public"
refs_of() { git --git-dir="$1" for-each-ref --format='%(refname) %(objectname)'; }
o_before="$(refs_of "$origin")"
p_before="$(refs_of "$public")"
cp -r "$st" "$tmp/dry"
bash "$root/ci/deps-release.sh" publish "$tmp/dry" --dry-run > "$tmp/dry.log" 2>&1 || fail "dry run: $(cat "$tmp/dry.log")"
[ ! -e "$tmp/dry/failed" ] && [ ! -e "$tmp/dry/moved" ] || fail "dry run: $(cat "$tmp/dry/failed" "$tmp/dry/moved" 2>/dev/null)"
grep -q "^origin v1.2.4 ${release} [0-9a-f]\{40\} (dry run" "$tmp/dry/steps" && grep -q '^public v1.2.4 [0-9a-f]\{40\} (dry run' "$tmp/dry/steps" || fail "dry run steps: $(cat "$tmp/dry/steps")"
[ "$(refs_of "$origin")" = "$o_before" ] && [ "$(refs_of "$public")" = "$p_before" ] || fail "the dry run pushed"
grep -q '^OK: 1 commit(s)' "$tmp/dry/attribution.txt" || fail "dry run: the attribution check: $(cat "$tmp/dry/attribution.txt")"
# the scrub: a release that adds a line the pattern matches is not published, the line not printed
cp -r "$st" "$tmp/scrub"
DEPS_FORBIDDEN='traefik chart 2[.]0[.]1' bash "$root/ci/deps-release.sh" publish "$tmp/scrub" > "$tmp/scrub.log" 2>&1
grep -qF 'names an internal system' "$tmp/scrub/failed" 2>/dev/null && grep -qF 'CHANGELOG.md' "$tmp/scrub/failed" && ! grep -qi 'traefik chart 2.0.1' "$tmp/scrub/failed" "$tmp/scrub.log" \
  || fail "the scrub: $(cat "$tmp/scrub/failed" "$tmp/scrub.log" 2>/dev/null)"
[ "$(refs_of "$origin")" = "$o_before" ] && [ "$(refs_of "$public")" = "$p_before" ] || fail "a release that fails the scrub pushed"
cp -r "$st" "$tmp/scrub2" && DEPS_FORBIDDEN='' bash "$root/ci/deps-release.sh" publish "$tmp/scrub2" >/dev/null 2>&1
grep -qF 'no scrub pattern' "$tmp/scrub2/failed" 2>/dev/null || fail "a release without the scrub pattern: $(cat "$tmp/scrub2/failed" 2>/dev/null)"
echo "ok - a dry run makes and checks the release's git half and pushes nothing; a release the scrub pattern matches, or without the pattern, is not published"

# the origin's main moved: nothing is pushed
cp -r "$st" "$tmp/moved"
git --git-dir="$origin" update-ref refs/heads/main "$next_commit"
bash "$root/ci/deps-release.sh" publish "$tmp/moved" --dry-run > /dev/null 2>&1
grep -qF "origin's main moved" "$tmp/moved/moved" 2>/dev/null || fail "a moved main: $(cat "$tmp/moved/moved" "$tmp/moved/failed" 2>/dev/null)"
git --git-dir="$origin" update-ref refs/heads/main "$tag_commit"
echo "ok - an origin whose main moved (another release came first) stops the release before any push"

signing=0
if [ "$(uname -s)" = Linux ] && command -v gpg >/dev/null 2>&1; then signing=1; fi
if [ "$signing" = 1 ]; then
  export GNUPGHOME="$tmp/gpg"
  mkdir -p "$GNUPGHOME" && chmod 700 "$GNUPGHOME"
  gpg --batch --no-tty --pinentry-mode loopback --passphrase 'test-pass' --quick-gen-key 'RunR deps bot (brkfun) <19393636+brkfun@users.noreply.github.com>' ed25519 sign never 2>/dev/null
  fpr="$(gpg --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }')"
  DEPS_SIGN_KEY="$(gpg --batch --no-tty --pinentry-mode loopback --passphrase 'test-pass' --armor --export-secret-keys "$fpr")"
  export DEPS_SIGN_KEY DEPS_SIGN_PASSPHRASE='test-pass' DEPS_SIGN_FINGERPRINT="$fpr"
  # sign-tag.sh: the key's fingerprint is pinned, a tag is never moved
  rc=0
  DEPS_SIGN_FINGERPRINT="$(printf '%040d' 0)" bash "$root/ci/deps/sign-tag.sh" "$clone" v9.9.9 "$release" "$tmp/notes/tag-msg.txt" >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "sign-tag.sh: another key's fingerprint passed"
  rc=0
  DEPS_SIGN_PASSPHRASE=wrong bash "$root/ci/deps/sign-tag.sh" "$clone" v9.9.9 "$release" "$tmp/notes/tag-msg.txt" >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] && ! git -C "$clone" rev-parse -q --verify refs/tags/v9.9.9 >/dev/null || fail "sign-tag.sh: a wrong passphrase signed"
  obj="$(bash "$root/ci/deps/sign-tag.sh" "$clone" v9.9.9 "$release" "$tmp/notes/tag-msg.txt")" || fail "sign-tag.sh: ${obj}"
  git -C "$clone" verify-tag v9.9.9 2>/dev/null || fail "sign-tag.sh: the tag does not verify"
  git -C "$clone" cat-file tag v9.9.9 | grep -qF "tagger ${BOT_ID} " || fail "sign-tag.sh: the tagger"
  rc=0
  bash "$root/ci/deps/sign-tag.sh" "$clone" v9.9.9 "$release" "$tmp/notes/tag-msg.txt" >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "sign-tag.sh moved a tag"
  git -C "$clone" tag -d v9.9.9 >/dev/null
  echo "ok - sign-tag.sh: gpg in a keyring of its own, the pinned key only, a wrong passphrase fails, a tag is never moved"

  # next moved after the bump: the publish merges the release into the new next
  newer="$(cd "$src" && git checkout -q "$next_commit" && echo more > api/more.php && git add -A && git commit -q -m "feat: more" && git rev-parse HEAD)"
  git -C "$src" push -q "$origin" "${newer}:refs/heads/next"
  bash "$root/ci/deps-release.sh" publish "$st" > "$tmp/publish.log" 2>&1 || fail "publish: $(cat "$tmp/publish.log")"
  [ ! -e "$st/failed" ] && [ ! -e "$st/moved" ] || fail "publish: $(cat "$st/failed" "$st/moved" 2>/dev/null)"
  [ "$(git --git-dir="$origin" rev-parse refs/heads/main)" = "$release" ] || fail "origin's main is not the release"
  merged="$(git --git-dir="$origin" rev-parse refs/heads/next)"
  [ "$(git --git-dir="$origin" rev-list --parents -n 1 "$merged" | cut -d' ' -f2-)" = "${newer} ${release}" ] || fail "origin's next is not the merge on the new next"
  [ "$(git --git-dir="$origin" rev-parse 'refs/tags/v1.2.4^{commit}')" = "$release" ] || fail "origin's tag"
  git --git-dir="$origin" verify-tag v1.2.4 2>/dev/null || fail "origin's tag is not signed by the bot's key"
  p="$(git --git-dir="$public" rev-parse refs/heads/main)"
  [ "$(git --git-dir="$public" rev-parse "${p}^")" = "$pub_base" ] && [ "$(git --git-dir="$public" rev-parse "${p}^{tree}")" = "$(git -C "$clone" rev-parse "${release}^{tree}")" ] \
    || fail "the public main is not one commit of the release's tree on v1.2.3"
  [ "$(git --git-dir="$public" log -1 --format='%an <%ae>|%cn <%ce>|%s' "$p")" = "${BOT_ID}|${BOT_ID}|RunR 1.2.4" ] || fail "the public commit: $(git --git-dir="$public" log -1 --format='%an <%ae>|%cn <%ce>|%s' "$p")"
  [ "$(git --git-dir="$public" rev-parse 'refs/tags/v1.2.4^{commit}')" = "$p" ] && git --git-dir="$public" verify-tag v1.2.4 2>/dev/null || fail "the public tag"
  git --git-dir="$public" cat-file -e "$release" 2>/dev/null && fail "the origin's commits reached the public repository"
  [ "$(git --git-dir="$public" for-each-ref --format='%(refname)' | paste -sd ' ' -)" = "refs/heads/main refs/tags/v1.2.3 refs/tags/v1.2.4" ] || fail "the public refs: $(git --git-dir="$public" for-each-ref --format='%(refname)')"
  grep -q "^origin v1.2.4 ${release} ${merged}$" "$st/steps" && grep -q "^public v1.2.4 ${p}$" "$st/steps" || fail "steps: $(cat "$st/steps")"
  bash "$root/ci/deps/check-attribution.sh" "$public" "$pub_base" "$p" v1.2.4 | grep -q '^OK: 1 commit(s), 1 tag(s)' || fail "the public release breaks the attribution rule"
  # it can run again: both are there
  cp "$st/steps" "$tmp/steps.before"
  bash "$root/ci/deps-release.sh" publish "$st" > /dev/null 2>&1
  [ ! -e "$st/failed" ] && tail -n 2 "$st/steps" | grep -q '^origin v1.2.4 .* (there already)$' && tail -n 1 "$st/steps" | grep -q '^public v1.2.4 .* (there already)$' \
    || fail "publish again: $(cat "$st/steps" "$st/failed" 2>/dev/null)"
  cp "$tmp/steps.before" "$st/steps"
  # resume: release.env from the origin's tag
  rclone="$tmp/rclone"
  git clone -q --no-checkout "$origin" "$rclone"
  env_out="$(bash "$root/ci/deps-release.sh" resume-env "$rclone" "$public" v1.2.4)" || fail "resume-env: ${env_out}"
  grep -qx "RELEASE=${release}" <<<"$env_out" && grep -qx 'BASE_TAG=v1.2.3' <<<"$env_out" && grep -qx "PUBLIC_BASE=${pub_base}" <<<"$env_out" \
    && grep -qx 'DEPS=traefik:2.0.1' <<<"$env_out" || fail "resume-env: ${env_out}"
  rc=0
  bash "$root/ci/deps-release.sh" resume-env "$rclone" "$public" v1.2.3 >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "resume of a release that is not the bot's"
  rdir="$tmp/resumed"
  bash "$root/ci/deps-run.sh" resume "$rdir" "$rclone" "$public" v1.2.4 20
  [ "$(cat "$rdir/bump/resume-20/label")" = "Region Traefik chart 2.0.1" ] && [ "$(cat "$rdir/outcome/resume-20")" = green ] \
    && [ "$(cat "$rdir/role/resume-20")" = release ] && grep -qx "RELEASE=${release}" "$rdir/release/release.env" \
    || fail "resume: $(cat "$rdir/bump/resume-20/label" "$rdir/release/release.env" "$rdir/release/failed" 2>/dev/null)"
  bash "$root/ci/deps-release.sh" publish "$rdir/release" > /dev/null 2>&1
  [ "$(awk '{ print $1 " " $NF }' "$rdir/release/steps" | paste -sd '|' -)" = "origin already)|public already)" ] || fail "resume's publish: $(cat "$rdir/release/steps" "$rdir/release/failed" 2>/dev/null)"
  bash "$root/ci/deps-run.sh" resume "$tmp/resumed2" "$rclone" "$public" v1.2.3 20
  grep -qF 'no release of the bot' "$tmp/resumed2/release/failed" || fail "resume of another's release: $(cat "$tmp/resumed2/release/failed" 2>/dev/null)"
  # a release of several bumps closes all their issues: resume takes their numbers
  bash "$root/ci/deps-run.sh" resume "$tmp/resumed3" "$rclone" "$public" v1.2.4 20,14
  [ "$(cat "$tmp/resumed3/role/resume-20") $(cat "$tmp/resumed3/role/resume-14") $(cat "$tmp/resumed3/bump/resume-14/issue")" = "release release 14" ] \
    || fail "resume of several issues: $(ls "$tmp/resumed3/bump")"
  # rows of the weekly summary: their refs
  bash "$root/ci/deps-run.sh" resume "$tmp/resumed4" "$rclone" "$public" v1.2.4 20,90:traefik
  [ "$(cat "$tmp/resumed4/role/resume-90-traefik") $(cat "$tmp/resumed4/bump/resume-90-traefik/ref") $(cat "$tmp/resumed4/bump/resume-90-traefik/issue")" = "release 90:traefik 90" ] \
    || fail "resume of a row of the summary: $(ls "$tmp/resumed4/bump")"
  rc=0
  bash "$root/ci/deps-run.sh" resume "$tmp/resumed5" "$rclone" "$public" v1.2.4 '90:Traefik' >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "resume took a ref that is no N or N:group"
  echo "ok - the publish: the origin's main, next (merged again on a next that moved) and the signed tag in one push, the public main one commit of the release's tree as the bot with its signed tag; again, nothing changes; resume reads the release from the origin's tag"
else
  # without signing, the git half as a dry run is what can be checked here
  printf 'origin v1.2.4 %s %s\npublic v1.2.4 %s\n' "$release" "$(cat "$report/merge")" "$(awk '$1 == "public" { print $3 }' "$tmp/dry/steps")" > "$st/steps"
  p="$(awk '$1 == "public" { print $3 }' "$tmp/dry/steps")"
  echo "ok - (signing and the real pushes run on Linux with gpg only)"
fi

# --- images, github, helm (stubs) -------------------------------------------------------------------
stub="$tmp/stub"
mkdir -p "$stub/bin" "$stub/manifests" "$stub/digests-amd64" "$stub/digests-arm64"
cat > "$stub/bin/gh" <<'EOF'
#!/usr/bin/env bash
s="$STUB"
case "$1 $2" in
  "api repos/"*"/actions/workflows/release-images.yml/runs"*) printf '77\t%s\t%s\thttps://example.test/runs/77\n' "$(cat "$s/run-status")" "$(cat "$s/run-conclusion")" ;;
  "api repos/"*"/actions/runs/77") printf '77\t%s\t%s\thttps://example.test/runs/77\n' "$(cat "$s/run-status")" "$(cat "$s/run-conclusion")" ;;
  "run download")
    while [ $# -gt 0 ]; do case "$1" in -n) n="$2"; shift ;; -D) d="$2"; shift ;; esac; shift; done
    mkdir -p "$d" && cp "$s/$n"/* "$d/" ;;
  "release view") [ -e "$s/release-$3" ] || exit 1; case "$*" in *isDraft,url*) echo "false https://example.test/releases/$3" ;; *) echo false ;; esac ;;
  "release create") touch "$s/release-$3"; while [ $# -gt 0 ]; do [ "$1" = --notes-file ] && cp "$2" "$s/notes.md"; shift; done ;;
  "api repos/"*"/releases/tags/"*) t="${2##*/}"; [ -e "$s/release-$t" ] && echo "false false https://example.test/releases/$t" ;;
  "api repos/"*"/releases/latest") ls "$s" | sed -n 's/^release-//p' | sort -V | tail -n 1 ;;
  "api repos/"*"/dispatches") touch "$s/dispatched" ;;
  *) echo "gh stub: $*" >&2; exit 1 ;;
esac
EOF
cat > "$stub/bin/crane" <<'EOF'
#!/usr/bin/env bash
[ "$1" = manifest ] || exit 1
f="$STUB/manifests/$(printf '%s' "$2" | tr '/:' '__')"
[ -f "$f" ] && cat "$f" || { echo "MANIFEST_UNKNOWN: $2" >&2; exit 1; }
EOF
cat > "$stub/bin/curl" <<'EOF'
#!/usr/bin/env bash
while [ $# -gt 0 ]; do [ "$1" = -o ] && out="$2"; shift; done
cp "$STUB/index.yaml" "$out"
EOF
cat > "$stub/bin/helm" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  repo) exit 0 ;;
  pull) while [ $# -gt 0 ]; do [ "$1" = --untardir ] && d="$2"; shift; done; mkdir -p "$d" && cp -r "$STUB/chart" "$d/runr" ;;
  package) src="$2"; while [ $# -gt 0 ]; do [ "$1" = -d ] && d="$2"; shift; done
    v="$(sed -n 's/^version: //p' "$src/Chart.yaml")"; t="$(mktemp -d)"; mkdir -p "$t/runr"; cp -r "$src/." "$t/runr/"; tar -czf "$d/runr-$v.tgz" -C "$t" runr ;;
  *) exit 1 ;;
esac
EOF
chmod +x "$stub/bin"/*
export STUB="$stub" PATH="$stub/bin:$PATH" GH_REPO=BRF-Tech/runr DEPS_POLL_S=0 DEPS_RUN_APPEAR_MIN=0 DEPS_RUN_TIMEOUT_MIN=0 DEPS_HELM_WAIT_MIN=0
echo completed > "$stub/run-status"
echo success > "$stub/run-conclusion"
# index MANIFEST_FILE AMD64 ARM64 — an image index of the two platforms
index() { printf '{"manifests":[{"digest":"%s","platform":{"os":"linux","architecture":"amd64"}},{"digest":"%s","platform":{"os":"linux","architecture":"arm64"}}]}\n' "$2" "$3" > "$stub/manifests/$(printf '%s' "$1" | tr '/:' '__')"; }
d1="sha256:$(printf '%064d' 1)" d2="sha256:$(printf '%064d' 2)" d3="sha256:$(printf '%064d' 3)" d4="sha256:$(printf '%064d' 4)"
for r in api:1.2.4 api:latest ui:1.2.4 ui:latest ui:1.2.4-mt; do index "ghcr.io/brf-tech/runr/$r" "$d1" "$d2"; done
index ghcr.io/brf-tech/runr/agent:1.2.4 "$d3" "$d4"
index ghcr.io/brf-tech/runr/gateway-auth:1.0.0 "$d3" "$d4"
printf 'ghcr.io/brf-tech/runr/api@%s ghcr.io/brf-tech/runr/api:1.2.4 ghcr.io/brf-tech/runr/api:latest\nghcr.io/brf-tech/runr/ui@%s ghcr.io/brf-tech/runr/ui:1.2.4-mt\n' "$d1" "$d1" > "$stub/digests-amd64/app-amd64.txt"
printf 'ghcr.io/brf-tech/runr/agent@%s ghcr.io/brf-tech/runr/agent:1.2.4\n' "$d3" > "$stub/digests-amd64/region-amd64.txt"
printf 'ghcr.io/brf-tech/runr/api@%s ghcr.io/brf-tech/runr/api:1.2.4 ghcr.io/brf-tech/runr/api:latest\n' "$d2" > "$stub/digests-arm64/app-arm64.txt"
printf 'ghcr.io/brf-tech/runr/agent@%s ghcr.io/brf-tech/runr/agent:1.2.4\n' "$d4" > "$stub/digests-arm64/region-arm64.txt"
if [ "$signing" = 0 ]; then
  # the public tag the images step reads the latest release from
  git -C "$public" update-ref refs/tags/v1.2.4 "$pub_base"
fi
cp -r "$st" "$tmp/imgfail"
bash "$root/ci/deps-release.sh" images "$st" > "$tmp/images.log" 2>&1 || fail "images: $(cat "$tmp/images.log")"
[ ! -e "$st/failed" ] || fail "images: $(cat "$st/failed")"
grep -q '^images v1.2.4 https://example.test/runs/77 7$' "$st/steps" || fail "images steps: $(cat "$st/steps"; cat "$st/images.txt")"
# a tag that names another digest than the run pushed
index ghcr.io/brf-tech/runr/agent:1.2.4 "$d3" "$d1"
bash "$root/ci/deps-release.sh" images "$tmp/imgfail" > /dev/null 2>&1
grep -qF 'agent:1.2.4 linux/arm64' "$tmp/imgfail/failed" 2>/dev/null || fail "a digest mismatch passed: $(cat "$tmp/imgfail/failed" 2>/dev/null)"
index ghcr.io/brf-tech/runr/agent:1.2.4 "$d3" "$d4"
cp -r "$st" "$tmp/runfail"
sed -i '/^images /d' "$tmp/runfail/steps"
echo failure > "$stub/run-conclusion"
bash "$root/ci/deps-release.sh" images "$tmp/runfail" > /dev/null 2>&1
grep -qF 'ended failure: no GitHub release' "$tmp/runfail/failed" 2>/dev/null && grep -qF 'resume=v1.2.4' "$tmp/runfail/failed" || fail "a failed run passed: $(cat "$tmp/runfail/failed" 2>/dev/null)"
echo success > "$stub/run-conclusion"
bash "$root/ci/deps-release.sh" github "$tmp/runfail" > /dev/null 2>&1
[ ! -e "$stub/release-v1.2.4" ] || fail "a GitHub release after a failed run"
echo "ok - the images: release-images.yml's run on the tag must succeed, every tag it pushed names its digests anonymously, every image of the release pulls for both platforms"

bash "$root/ci/deps-release.sh" github "$st" > "$tmp/github.log" 2>&1 || fail "github: $(cat "$tmp/github.log")"
[ -e "$stub/release-v1.2.4" ] && [ -e "$stub/dispatched" ] || fail "github: no release or no dispatch"
grep -q '^github v1.2.4 https://example.test/releases/v1.2.4 dispatched$' "$st/steps" || fail "github steps: $(cat "$st/steps")"
cmp -s "$stub/notes.md" "$tmp/notes/notes.md" || fail "the release's notes are not the release commit's"
mkdir -p "$stub/chart"
git -C "$clone" archive "$release" helm | tar -x -C "$tmp" && cp -r "$tmp/helm/." "$stub/chart/"
printf 'apiVersion: v1\nentries:\n  runr:\n  - apiVersion: v2\n    version: 1.2.3\n  - apiVersion: v2\n    appVersion: 1.2.4\n    version: "1.2.4"\n  traefik:\n  - version: 9.9.9\n' > "$stub/index.yaml"
cp -r "$st" "$tmp/helmfail"
bash "$root/ci/deps-release.sh" helm "$st" > "$tmp/helm.log" 2>&1 || fail "helm: $(cat "$tmp/helm.log")"
grep -q '^helm 1.2.4 ok$' "$st/steps" || fail "helm steps: $(cat "$st/steps" "$st/failed" 2>/dev/null)"
echo 'replicas: 2' > "$stub/chart/values.yaml"
bash "$root/ci/deps-release.sh" helm "$tmp/helmfail" > /dev/null 2>&1
grep -qF 'is not the release' "$tmp/helmfail/failed" 2>/dev/null || fail "another chart on helm.brf.sh passed"
echo "ok - the GitHub release with the release commit's notes, the chart's publish dispatched, helm.brf.sh's chart compared with the release's"

# --- the issues -------------------------------------------------------------------------------------
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qF "$(printf 'label\t20\tdeps: passed')" "$dir/actions" && grep -qF "$(printf 'close\t20')" "$dir/actions" || fail "released: $(cat "$dir/actions")"
grep -qF '**Released: traefik 2.0.1 is RunR 1.2.4** ([release](https://example.test/releases/v1.2.4))' "$dir/comments/20.md" || fail "the released comment: $(cat "$dir/comments/20.md")"
grep -qF "$(printf 'label\t14\tneeds attention')" "$dir/actions" || fail "attention: $(cat "$dir/actions")"
grep -q '^dispatch' "$dir/actions" && fail "a dispatch after a release"
grep -qF 'together with' "$dir/comments/20.md" && fail "the release of one bump names others: $(cat "$dir/comments/20.md")"
grep -qxF "$(printf '20\037chart\037traefik\0372.0.1\037shipped\037line\037\037\037v1.2.4\037https://example.test/releases/v1.2.4')" "$dir/lines" \
  || fail "the row that mirrors a released issue: $(cat -v "$dir/lines")"
# released as a row of the weekly summary: shipped in the release, no action of its own
echo 90:traefik > "$dir/bump/traefik/ref"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qE '^[a-z]+	20(	|$)' "$dir/actions" && fail "a released row got actions of its own: $(cat "$dir/actions")"
grep -qxF "$(printf '90:traefik\037chart\037traefik\0372.0.1\037shipped\037line\037\037\037v1.2.4\037https://example.test/releases/v1.2.4')" "$dir/lines" \
  || fail "the released row: $(cat -v "$dir/lines")"
rm -f "$dir/bump/traefik/ref"
# a green bump that waits (its batch stopped before it): a comment, no label, no dispatch
mkdir -p "$dir/bump/kata"
echo 31 > "$dir/bump/kata/issue"; echo 4.0.0 > "$dir/bump/kata/version"; echo v1.2.3 > "$dir/bump/kata/base-tag"
echo green > "$dir/outcome/kata"
echo wait > "$dir/role/kata"
echo "**kata 4.0.0 passed alone on v1.2.3; it waits for the next run.**" > "$dir/role/kata.md"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
[ "$(grep -c "$(printf '\t31')" "$dir/actions")" = 1 ] && grep -qxF "$(printf 'comment\t31\t%s' "$dir/comments/31.md")" "$dir/actions" \
  && grep -qF 'it waits for the next run' "$dir/comments/31.md" && ! grep -q '^dispatch' "$dir/actions" || fail "a bump that waits: $(cat "$dir/actions")"
# one of a run whose batch was not made: it waits too
echo nobatch > "$dir/role/kata"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qF 'could not put its green bumps together' "$dir/comments/31.md" && [ "$(grep -c "$(printf '\t31')" "$dir/actions")" = 1 ] || fail "no batch: $(cat "$dir/comments/31.md")"
echo wait > "$dir/role/kata"
# a release that failed after the origin's push: attention, what is done, how to resume
cp "$st/steps" "$tmp/steps.ok"
grep -E '^(origin|public) ' "$tmp/steps.ok" | head -n 1 > "$st/steps"
echo "publish: the origin has v1.2.4, the public push failed: denied" > "$st/failed"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qF "$(printf 'label\t20\tneeds attention')" "$dir/actions" && ! grep -qF "$(printf 'close\t20')" "$dir/actions" || fail "failed: $(cat "$dir/actions")"
grep -qF 'the public push failed' "$dir/comments/20.md" && grep -qF '`resume` = `v1.2.4` and this issue'"'"'s number' "$dir/comments/20.md" || fail "failed comment: $(cat "$dir/comments/20.md")"
# the same as a row of the weekly summary: needs attention, its text for the run's issue, how to
# resume with the row's ref
echo 90:traefik > "$dir/bump/traefik/ref"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
grep -qxF "$(printf '90:traefik\037chart\037traefik\0372.0.1\037needs attention\037incident\037its release v1.2.4 did not finish\037%s\037\037https://example.test/run/1' "$dir/comments/20-traefik.md")" "$dir/lines" \
  && grep -qF '`resume` = `v1.2.4` and `issue` = `90:traefik`' "$dir/comments/20-traefik.md" || fail "a failed release's row: $(cat -v "$dir/lines"; cat "$dir/comments/20-traefik.md")"
rm -f "$dir/bump/traefik/ref"
! grep -q '^dispatch' "$dir/actions" && ! grep -qF "$(printf 'label\t31')" "$dir/actions" || fail "the bump that waits after a failed release: $(cat "$dir/actions")"
# the base moved: the release's bumps are tested again, attempt + 1, at most three in a row; one that
# waits is not
rm -f "$st/failed"
: > "$st/steps"
echo "origin's main moved" > "$st/moved"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/2 1
grep -qxF "$(printf 'dispatch\t20\t2')" "$dir/actions" && grep -qF 'attempt 2 of 3' "$dir/comments/20.md" || fail "moved: $(cat "$dir/actions")"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/3 3
grep -q '^dispatch' "$dir/actions" && fail "a fourth run in a row"
grep -qF 'next weekly run' "$dir/comments/20.md" || fail "the third moved run's comment"
# a dry run: comments for the summary, no action
rm -f "$st/moved"
cp "$tmp/steps.ok" "$st/steps"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/4 1 --dry-run
[ ! -s "$dir/actions" ] || fail "a dry run has actions: $(cat "$dir/actions")"
grep -qF '**Dry run: traefik 2.0.1 passed on v1.2.3 and would be RunR 1.2.4**' "$dir/comments/20.md" || fail "the dry run's comment: $(cat "$dir/comments/20.md")"
echo "ok - the issues: released (label, comment, close), failed (label, what is done, how to resume), moved (tested again, at most three in a row); a green bump that waits gets a comment and no label, no dispatch; a dry run acts on none"

# --- one run, one release: three green bumps, a red one, a group the tag does not know, two of next --
# fresh NAME — the origin and the public repository as the fixture made them, a clone of the origin
# (S: the run's directory)
fresh() {
  S="$tmp/$1"
  rm -rf -- "$S"
  mkdir -p "$S"
  cp -r "$tmp/origin0.git" "$S/origin.git"
  cp -r "$tmp/public0.git" "$S/public.git"
  git clone -q --no-checkout "$S/origin.git" "$S/clone"
}
# res DIR NAME SUITE STATUS — a test job's result
res() { mkdir -p "$1/results/deps-result-$2-$3"; echo "$4" > "$1/results/deps-result-$2-$3/status"; echo 0 > "$1/results/deps-result-$2-$3/failed"; }
# green_results DIR NAME SUITE… — all of them passed
green_results() { local d="$1" n="$2" s; shift 2; for s in "$@"; do res "$d" "$n" "$s" success; done; }
# trailers REPO COMMIT — the bot's trailer lines of a commit, joined with |
trailers() { git -C "$1" log -1 --format='%(trailers:key=RunR-Deps-Bot,valueonly)' "$2" | sed '/^$/d' | paste -sd '|' -; }
export FAKE_MODE=batch
fresh batch
d2="$S/deps"
next0="$(git -C "$S/clone" rev-parse refs/remotes/origin/next)"
upd='"updates": [{"name": "x", "from": "1.0.0", "to": "1.0.1"}]'
cat > "$tmp/tests2.json" <<EOF
[{"issue": 12, "dep": "zot", "version": "0.2.0"}, {"issue": 11, "dep": "traefik", "version": "2.0.1"},
 {"issue": 13, "dep": "loki-stack", "version": "2.10.4"}, {"issue": 14, "dep": "kube-prometheus-stack", "version": "93.0.1"},
 {"issue": 15, "dep": "composer-api", "version": "0a1b2c3d", ${upd}, "urgent": false},
 {"issue": 16, "dep": "actions", "version": "0a1b2c3e", ${upd}, "urgent": false},
 {"issue": 17, "dep": "go-cli", "version": "0a1b2c3f", ${upd}, "urgent": true}]
EOF
bash "$root/ci/deps-run.sh" bumps "$tmp/tests2.json" "$S/clone" "$d2" "$S/public.git" https://example.test/run/5 > "$S/bumps.log" 2>&1 \
  || fail "bumps of the batch: $(tail -n 30 "$S/bumps.log")"
# a package group the last release's bump does not know (its ci/deps/packages.sh has no row): held
[ "$(cat "$d2/bump/composer-api/result")" = held ] && [ -e "$d2/bump/composer-api/held-wait" ] \
  && grep -qxF "the last release's bot does not know composer-api; the next full release brings it" "$d2/bump/composer-api/held" \
  || fail "a group the tag does not know: $(cat "$d2/bump/composer-api/result" "$d2/bump/composer-api/held" 2>/dev/null)"
[ "$(cat "$d2/bump/actions/path") $(cat "$d2/bump/go-cli/path")" = "next next" ] || fail "the bumps of next only"
green_results "$d2" traefik unit shell e2e upgrade
green_results "$d2" zot unit shell e2e upgrade
green_results "$d2" loki-stack unit shell e2e upgrade
green_results "$d2" kube-prometheus-stack unit shell upgrade
res "$d2" kube-prometheus-stack e2e failure
green_results "$d2" actions shell actionlint
green_results "$d2" go-cli shell go-cli
bash "$root/ci/deps-run.sh" batch "$d2" "$S/clone" > "$S/batch.log" 2>&1 || fail "batch: $(tail -n 30 "$S/batch.log")"
[ "$(cat "$d2/green" | paste -sd ' ' -)" = "go-cli traefik zot loki-stack actions" ] || fail "the order of the green bumps: $(cat "$d2/green")"
b="$d2/batch/release"
[ "$(paste -sd ' ' "$b/members")" = "traefik zot loki-stack" ] && [ -e "$b/tested" ] || fail "the release's batch: $(cat "$b/members"; tail -n 20 "$S/batch.log")"
c1="$(sed -n '1s/.* //p' "$b/chain")" c2="$(sed -n '2s/.* //p' "$b/chain")" c3="$(sed -n '3s/.* //p' "$b/chain")"
batch_release="$(cat "$b/head")"
tag0="$(git -C "$S/clone" rev-parse 'v1.2.3^{commit}')"
[ "$c1" = "$(cat "$d2/bump/traefik/bump")" ] && [ "$(git -C "$S/clone" rev-parse "${c1}^")" = "$tag0" ] && [ "$(git -C "$S/clone" rev-parse "${c2}^")" = "$c1" ] \
  && [ "$(git -C "$S/clone" rev-parse "${c3}^")" = "$c2" ] && [ "$(git -C "$S/clone" rev-parse "${batch_release}^")" = "$c3" ] \
  || fail "the batch is not its bumps one after the other on the tag, with one release commit"
[ "$(trailers "$S/clone" "$c2")" = "zot 0.2.0" ] && [ "$(trailers "$S/clone" "$c3")" = "loki-stack 2.10.4" ] \
  && [ "$(git -C "$S/clone" log -1 --format='%an <%ae>' "$c2")" = "$BOT_ID" ] || fail "the bumps made again are not the bot's commits with their trailers"
[ "$(trailers "$S/clone" "$batch_release")" = "traefik 2.0.1|zot 0.2.0|loki-stack 2.10.4" ] || fail "the release commit's trailers: $(trailers "$S/clone" "$batch_release")"
git -C "$S/clone" show "${batch_release}:api/pins.php" | grep -qxF "const ZOT = '0.2.0';" && git -C "$S/clone" show "${batch_release}:api/pins.php" | grep -qxF "const LOKI_STACK = '2.10.4';" \
  && git -C "$S/clone" show "${batch_release}:api/pins.php" | grep -qF "TRAEFIK = '2.0.1'" && git -C "$S/clone" show "${batch_release}:api/pins.php" | grep -qxF "const KUBE_PROMETHEUS_STACK = '93.0.0';" \
  || fail "the release's pins: $(git -C "$S/clone" show "${batch_release}:api/pins.php")"
git -C "$S/clone" show "${batch_release}:CHANGELOG.md" | awk '/^## \[1\.2\.4\]/ { on = 1; next } on && /^## \[/ { exit } on' > "$S/section"
[ "$(grep -c '^- \*\*' "$S/section")" = 4 ] && [ "$(grep -c '^### Changed' "$S/section")" = 1 ] && grep -qF -- '- **zot chart 0.2.0**' "$S/section" \
  && grep -qF -- '- **loki-stack chart 2.10.4**' "$S/section" && grep -qF -- '- **Region Traefik chart 2.0.1**' "$S/section" \
  && tr '\n' ' ' < "$S/section" | tr -s ' ' | grep -qF 'only the 3 dependencies below change' || fail "the release's changelog section: $(cat "$S/section")"
git -C "$S/clone" show "${batch_release}:docs/upgrade.md" | tr '\n' ' ' | tr -s ' ' > "$S/upgrade"
grep -qF 'it moves 3 dependencies and nothing else' "$S/upgrade" && grep -qF "The bot ran these suites before the release was tagged, each bump's own on that bump alone and all of them on this release:" "$S/upgrade" \
  && grep -qF 'ns/zot=zot-0.2.0' "$S/upgrade" || fail "the release's upgrade section: $(cat "$S/upgrade")"
# the union of their suites, once per command: one e2e, an upgrade suite of each; the next only ones' checks
[ "$(jq -c '[.[] | [.dep, .suite, .ref]]' "$d2/batch/matrix-suites.json")" = '[["batch","e2e","deps/batch"],["batch","upgrade","deps/batch"],["batch","upgrade-2","deps/batch"],["batch","upgrade-3","deps/batch"]]' ] \
  && [ "$(jq -c '[.[] | [.dep, .suite]]' "$d2/batch/matrix-checks.json")" = '[["batch-next","go-cli"],["batch-next","actionlint"]]' ] \
  && [ "$(jq -c '[.[].dep]' "$d2/batch/matrix-unit.json")|$(jq -c '[.[].dep]' "$d2/batch/matrix-shell.json")" = '["batch"]|["batch-next","batch"]' ] \
  && [ "$(jq -c '.[] | select(.suite == "e2e") | .members' "$b/report/suites.json")" = '["traefik","zot","loki-stack"]' ] \
  || fail "the combined tests: $(cat "$d2"/batch/matrix-*.json)"
[ "$(git -C "$S/clone" rev-parse deps/batch)" = "$batch_release" ] || fail "deps/batch"
# the bumps of next only: one after the other on next (the urgent one first), no release commit
n="$d2/batch/next"
land_head="$(cat "$n/head")"
[ "$(paste -sd ' ' "$n/members")" = "go-cli actions" ] && [ "$(git -C "$S/clone" rev-parse deps/batch-next)" = "$land_head" ] \
  && [ "$(git -C "$S/clone" rev-parse "${land_head}^")" = "$(cat "$d2/bump/go-cli/head")" ] && [ "$(git -C "$S/clone" rev-parse "${land_head}~2")" = "$next0" ] \
  && [ "$(trailers "$S/clone" "$land_head")" = "actions 0a1b2c3e" ] || fail "the batch of next: $(cat "$n/members" "$n/chain")"
# next takes the release on the next of the landing (the merge preview)
[ "$(git -C "$S/clone" rev-list --parents -n 1 "$(cat "$b/merge")" | cut -d' ' -f2-)" = "${land_head} ${batch_release}" ] || fail "the release's merge preview: $(cat "$b/merge" "$b/merge.err")"
echo "ok - the green bumps of a run together: made again one after the other on the tag (an urgent one first, then by issue), one release commit with a trailer and a changelog line each; the union of their suites, once per command; the bumps of next only the same on next"

# the second phase passed: one release of the three, one landing of the two
green_results "$d2" batch unit shell e2e upgrade upgrade-2 upgrade-3
green_results "$d2" batch-next shell go-cli actionlint
[ "$(bash "$root/ci/deps-run.sh" pick "$d2" "$S/clone")" = "$(printf 'release=true\nland=true')" ] || fail "pick: $(cat "$d2/release/release.env")"
grep -qx "RELEASE=${batch_release}" "$d2/release/release.env" && grep -qx 'DEPS=traefik:2.0.1,zot:0.2.0,loki-stack:2.10.4' "$d2/release/release.env" \
  && grep -qx "UPDATES=${d2}/bump" "$d2/release/release.env" && grep -qx "LAND=${land_head}" "$d2/land/release.env" && grep -qx "NEXT_BASE=${next0}" "$d2/land/release.env" \
  || fail "release.env, land: $(cat "$d2/release/release.env" "$d2/land/release.env")"
[ "$(cat "$d2"/role/{traefik,zot,loki-stack,go-cli,actions} | paste -sd ' ' -)" = "release release release land land" ] || fail "the roles: $(head "$d2"/role/*)"
# the notes and the tags' messages name every bump
bash "$root/ci/deps-release.sh" notes "$S/clone" "$batch_release" "$S/notes"
grep -qF '3 dependencies move, and nothing else changes' "$S/notes/notes.md" && [ "$(grep -c '^- \*\*.* chart ' "$S/notes/notes.md")" = 3 ] \
  && grep -qF 'each bump'"'"'s own on that bump alone' "$S/notes/notes.md" || fail "the notes: $(cat "$S/notes/notes.md")"
[ "$(tail -n 3 "$S/notes/tag-msg.txt" | paste -sd '|' -)" = "RunR-Deps-Bot: traefik 2.0.1|RunR-Deps-Bot: zot 0.2.0|RunR-Deps-Bot: loki-stack 2.10.4" ] \
  && tr '\n' ' ' < "$S/notes/tag-msg.txt" | tr -s ' ' | grep -qF 'Region Traefik chart 2.0.1; zot chart 0.2.0; loki-stack chart 2.10.4.' \
  && [ "$(grep -c '^RunR-Deps-Bot: ' "$S/notes/public-msg.txt")" = 3 ] || fail "the tag's message: $(cat "$S/notes/tag-msg.txt")"
# merge-next on a next that changed what the bumps change: every bump made again on next, in order
w="$S/work"
git clone -q "$S/origin.git" "$w"
conflicting2="$(cd "$w" && git checkout -q next && echo 'Pin the chart (`--version 1.2.3`) differently.' > README.md \
  && sed -i "s/^const ZOT = '0.1.0';/const ZOT = '0.1.0'; \/\/ the registry/; s/^const LOKI_STACK = '2.10.2';/const LOKI_STACK = '2.10.2'; \/\/ logs/" api/pins.php \
  && git commit -q -am "docs: README" && git rev-parse HEAD)"
git -C "$S/clone" fetch -q "$w" "$conflicting2"
m2="$(bash "$root/ci/deps-release.sh" merge-next "$S/clone" "$batch_release" "$conflicting2" "traefik:2.0.1,zot:0.2.0,loki-stack:2.10.4" "$d2/bump" 2>"$S/merge.err")" \
  || fail "the batch made again on next: $(cat "$S/merge.err")"
git -C "$S/clone" show "${m2}:api/pins.php" > "$S/pins"
grep -qxF "const ZOT = '0.2.0'; // the registry" "$S/pins" && grep -qxF "const LOKI_STACK = '2.10.4'; // logs" "$S/pins" && grep -qF "TRAEFIK = '2.0.1'" "$S/pins" \
  && [ "$(git -C "$S/clone" show "${m2}:README.md")" = 'Pin the chart (`--version 1.2.4`) differently.' ] \
  && [ "$(git -C "$S/clone" show "${m2}:CHANGELOG.md" | grep -c 'zot chart 0.2.0')" = 1 ] \
  && git -C "$S/clone" log -1 --format=%B "$m2" | tr '\n' ' ' | grep -qF "made the 3 bumps again on it, in the release's order" \
  && [ "$(trailers "$S/clone" "$m2")" = "traefik 2.0.1|zot 0.2.0|loki-stack 2.10.4" ] || fail "the batch made again on next: $(cat "$S/pins"; git -C "$S/clone" log -1 --format=%B "$m2")"
echo "ok - pick: one release of the three green bumps (DEPS lists them), one landing of the two of next; the notes and the tags name every bump; a next that changed their lines gets every bump made again, in order"

# the base moved under the release: all of its bumps are tested again (attempt + 1), not one by one
cp -r "$d2" "$S/moved"
sed -i "s#^REPO=.*#REPO=${S}/clone#" "$S/moved/release/release.env"
git --git-dir="$S/origin.git" update-ref refs/heads/main "$next0"
ORIGIN_REMOTE="$S/origin.git" PUBLIC_REMOTE="$S/public.git" bash "$root/ci/deps-release.sh" publish "$S/moved/release" --dry-run > /dev/null 2>&1
git --git-dir="$S/origin.git" update-ref refs/heads/main "$tag0"
grep -qF "origin's main moved" "$S/moved/release/moved" || fail "a moved base: $(cat "$S/moved/release/moved" "$S/moved/release/failed" 2>/dev/null)"
bash "$root/ci/deps-run.sh" actions "$S/moved" https://example.test/run/6 1
[ "$(sed -n 's/^dispatch\t\([0-9,]*\)\t2$/\1/p' "$S/moved/actions" | tr ',' '\n' | sort -n | paste -sd ',' -)" = "11,12,13" ] \
  && [ "$(grep -c '^dispatch' "$S/moved/actions")" = 1 ] && grep -qF 'the 3 bumps of the release again on the new one' "$S/moved/comments/11.md" \
  || fail "the moved release's bumps: $(cat "$S/moved/actions")"
bash "$root/ci/deps-run.sh" actions "$S/moved" https://example.test/run/7 3
grep -q '^dispatch' "$S/moved/actions" && fail "the moved batch: a fourth run in a row"
echo "ok - a base that moved under the release: every bump of the batch is tested again in one run, attempt + 1, three runs in a row at most"

# the landing (one fast-forward of next with both) and the release (merged into the landed next)
ORIGIN_REMOTE="$S/origin.git" bash "$root/ci/deps-release.sh" land "$d2/land" > "$S/land.log" 2>&1 || fail "land: $(cat "$S/land.log")"
[ "$(git --git-dir="$S/origin.git" rev-parse refs/heads/next)" = "$land_head" ] && grep -q '^OK: 2 commit(s)' "$d2/land/attribution.txt" \
  && grep -qx "land ${land_head}" "$d2/land/steps" || fail "the landing: $(cat "$d2/land/steps" "$d2/land/failed" "$d2/land/attribution.txt" 2>/dev/null)"
if [ "$signing" = 1 ]; then
  ORIGIN_REMOTE="$S/origin.git" PUBLIC_REMOTE="$S/public.git" bash "$root/ci/deps-release.sh" publish "$d2/release" > "$S/publish.log" 2>&1 || fail "publish: $(cat "$S/publish.log")"
  [ ! -e "$d2/release/failed" ] && [ ! -e "$d2/release/moved" ] || fail "publish of the batch: $(cat "$d2/release/failed" "$d2/release/moved" 2>/dev/null)"
  [ "$(git --git-dir="$S/origin.git" rev-parse refs/heads/main)" = "$batch_release" ] && [ "$(git --git-dir="$S/origin.git" tag -l | paste -sd ' ' -)" = "v1.2.3 v1.2.4" ] \
    && [ "$(git --git-dir="$S/origin.git" rev-list --parents -n 1 refs/heads/next | cut -d' ' -f2-)" = "${land_head} ${batch_release}" ] \
    && [ "$(git --git-dir="$S/origin.git" cat-file tag v1.2.4 | grep -c '^RunR-Deps-Bot: ')" = 3 ] || fail "the origin after the batch's release"
  p2="$(git --git-dir="$S/public.git" rev-parse refs/heads/main)"
  [ "$(git --git-dir="$S/public.git" rev-list --count "${pub_base}..${p2}")" = 1 ] && [ "$(git --git-dir="$S/public.git" rev-parse "${p2}^{tree}")" = "$(git -C "$S/clone" rev-parse "${batch_release}^{tree}")" ] \
    && [ "$(git --git-dir="$S/public.git" log -1 --format=%B "$p2" | grep -c '^RunR-Deps-Bot: ')" = 3 ] || fail "the public release of the batch"
else
  ORIGIN_REMOTE="$S/origin.git" PUBLIC_REMOTE="$S/public.git" bash "$root/ci/deps-release.sh" publish "$d2/release" --dry-run > "$S/publish.log" 2>&1 || fail "publish: $(cat "$S/publish.log")"
  m3="$(awk '$1 == "origin" { print $4 }' "$d2/release/steps")"
  [ "$(git -C "$S/clone" rev-list --parents -n 1 "$m3" | cut -d' ' -f2-)" = "${land_head} ${batch_release}" ] || fail "the release's merge on the landed next: $(cat "$d2/release/steps")"
fi
printf 'images v1.2.4 https://example.test/runs/78 9\ngithub v1.2.4 https://example.test/releases/v1.2.4\nhelm 1.2.4 ok\n' >> "$d2/release/steps"
bash "$root/ci/deps-run.sh" actions "$d2" https://example.test/run/5 1
for i in 11 12 13 16 17; do
  grep -qF "$(printf 'label\t%s\tdeps: passed' "$i")" "$d2/actions" && grep -qxF "$(printf 'close\t%s' "$i")" "$d2/actions" || fail "#${i} is not closed as passed: $(cat "$d2/actions")"
done
grep -qF '**Released: traefik 2.0.1 is RunR 1.2.4**' "$d2/comments/11.md" && grep -qF 'together with #12 zot 0.2.0, #13 loki-stack 2.10.4' "$d2/comments/11.md" \
  && grep -qF 'with all 3 bumps together' "$d2/comments/11.md" && grep -qF '(`deps/batch`, ' "$d2/comments/11.md" || fail "the released comment: $(cat "$d2/comments/11.md")"
grep -qF 'Landed on next: go-cli 0a1b2c3f' "$d2/comments/17.md" && grep -qF 'the 2 bumps of this run together' "$d2/comments/17.md" || fail "the landed comment: $(cat "$d2/comments/17.md")"
grep -qF "$(printf 'label\t14\tneeds attention')" "$d2/actions" && ! grep -qF "$(printf 'close\t14')" "$d2/actions" || fail "the red bump: $(cat "$d2/actions")"
[ "$(grep -c "$(printf '\t15')" "$d2/actions")" = 1 ] && grep -qF 'the next full release brings it' "$d2/comments/15.md" || fail "the held group: $(cat "$d2/actions")"
grep -q '^dispatch' "$d2/actions" && fail "a dispatch after the release of the batch"
echo "ok - the release of three bumps: one release commit and one tag on the origin, one public commit; the two of next land in one fast-forward first; every released and landed issue closed, the red one needs attention, the unknown group waits without a label"

# a bump whose re-make on the batch is not mechanical stops it: the bumps before it are released,
# it needs attention, the ones after it wait
fresh remake
d3="$S/deps"
cat > "$tmp/tests3.json" <<'EOF'
[{"issue": 11, "dep": "traefik", "version": "2.0.1"}, {"issue": 12, "dep": "zot", "version": "0.2.0"}, {"issue": 13, "dep": "loki-stack", "version": "2.10.4"}]
EOF
bash "$root/ci/deps-run.sh" bumps "$tmp/tests3.json" "$S/clone" "$d3" "$S/public.git" https://example.test/run/8 > "$S/bumps.log" 2>&1 || fail "bumps: $(tail -n 20 "$S/bumps.log")"
for dep in traefik zot loki-stack; do green_results "$d3" "$dep" unit shell e2e upgrade; done
FAKE_REMAKE_ATTENTION=zot bash "$root/ci/deps-run.sh" batch "$d3" "$S/clone" > "$S/batch.log" 2>&1 || fail "batch: $(tail -n 20 "$S/batch.log")"
[ "$(cat "$d3/batch/release/members")" = traefik ] && [ "$(cat "$d3/batch/release/breaker")" = zot ] && [ "$(cat "$d3/batch/release/waiting")" = loki-stack ] \
  && [ ! -e "$d3/batch/release/tested" ] && [ "$(cat "$d3/batch/matrix-shell.json")" = '[]' ] || fail "a re-make that is not mechanical: $(tail -n 20 "$S/batch.log")"
[ "$(bash "$root/ci/deps-run.sh" pick "$d3" "$S/clone")" = "$(printf 'release=true\nland=false')" ] && grep -qx "RELEASE=$(cat "$d3/bump/traefik/head")" "$d3/release/release.env" \
  && grep -qx 'DEPS=traefik:2.0.1' "$d3/release/release.env" || fail "pick after a breaker: $(cat "$d3/release/release.env")"
[ "$(cat "$d3"/role/{traefik,zot,loki-stack} | paste -sd ' ' -)" = "release breaker wait" ] || fail "the roles after a breaker: $(head "$d3"/role/*)"
grep -qF 'it is not mechanical' "$d3/role/zot.md" && grep -qF 'the bump needs a person on this tree' "$d3/role/zot.md" && grep -qF '#11 traefik 2.0.1' "$d3/role/zot.md" \
  && grep -qF 'stops before #12 zot 0.2.0' "$d3/role/loki-stack.md" || fail "the breaker's and the waiting one's reasons: $(cat "$d3/role/zot.md" "$d3/role/loki-stack.md")"
bash "$root/ci/deps-run.sh" actions "$d3" https://example.test/run/8 1
grep -qF "$(printf 'label\t12\tneeds attention')" "$d3/actions" && ! grep -qF "$(printf 'label\t13')" "$d3/actions" && grep -qF "$(printf 'comment\t13')" "$d3/actions" \
  && ! grep -q '^dispatch' "$d3/actions" || fail "the breaker's and the waiting one's actions: $(cat "$d3/actions")"
echo "ok - a bump not made mechanically on the batch stops it: the bumps before it are released (one: its tree tested alone), it needs attention, the ones after it wait with no label"

# the combined tree fails a suite: the largest prefix whose suites passed there is released
fresh prefix
d4="$S/deps"
cat > "$tmp/tests4.json" <<'EOF'
[{"issue": 11, "dep": "traefik", "version": "2.0.1"}, {"issue": 12, "dep": "zot", "version": "0.2.0"},
 {"issue": 13, "dep": "loki-stack", "version": "2.10.4"}, {"issue": 18, "dep": "kata-deploy", "version": "4.3.2"}]
EOF
bash "$root/ci/deps-run.sh" bumps "$tmp/tests4.json" "$S/clone" "$d4" "$S/public.git" https://example.test/run/9 > "$S/bumps.log" 2>&1 || fail "bumps: $(tail -n 20 "$S/bumps.log")"
for dep in traefik zot loki-stack kata-deploy; do green_results "$d4" "$dep" unit shell e2e upgrade; done
bash "$root/ci/deps-run.sh" batch "$d4" "$S/clone" > "$S/batch.log" 2>&1 || fail "batch: $(tail -n 20 "$S/batch.log")"
b="$d4/batch/release"
[ "$(wc -l < "$b/members")" = 4 ] && [ "$(jq -r '.[] | select(.suite == "upgrade-3") | .members[]' "$b/report/suites.json")" = loki-stack ] || fail "the batch of four: $(cat "$b/members")"
green_results "$d4" batch unit shell e2e upgrade upgrade-2 upgrade-4
res "$d4" batch upgrade-3 failure
echo "- **loki-region** [ready]: timed out" > "$d4/results/deps-result-batch-upgrade-3/failures.md"
[ "$(bash "$root/ci/deps-run.sh" pick "$d4" "$S/clone")" = "$(printf 'release=true\nland=false')" ] || fail "pick after a combined failure"
prefix="$(sed -n 's/^RELEASE=//p' "$d4/release/release.env")"
c2="$(sed -n '2s/.* //p' "$b/chain")"
[ "$(git -C "$S/clone" rev-parse "${prefix}^")" = "$c2" ] && [ "$prefix" != "$(cat "$b/head")" ] && grep -qx 'DEPS=traefik:2.0.1,zot:0.2.0' "$d4/release/release.env" \
  && [ "$(trailers "$S/clone" "$prefix")" = "traefik 2.0.1|zot 0.2.0" ] || fail "the prefix release: $(cat "$d4/release/release.env")"
git -C "$S/clone" show "${prefix}:api/pins.php" | grep -qxF "const LOKI_STACK = '2.10.2';" || fail "the prefix release moved the breaker's pin"
git -C "$S/clone" show "${prefix}:docs/upgrade.md" | tr '\n' ' ' | tr -s ' ' | grep -qF 'all of them on this release together with the later bumps of the run' || fail "the prefix release's suites sentence"
[ "$(git -C "$S/clone" show "${prefix}:CHANGELOG.md" | awk '/^## \[1\.2\.4\]/ { on = 1; next } on && /^## \[/ { exit } on' | grep -c '^- \*\*.* chart ')" = 2 ] || fail "the prefix release's changelog"
[ "$(cat "$d4"/role/{traefik,zot,loki-stack,kata-deploy} | paste -sd ' ' -)" = "release release breaker wait" ] || fail "the roles of the prefix: $(head "$d4"/role/*)"
grep -qF 'failed together with the bumps before it' "$d4/role/loki-stack.md" && grep -qF '(#11 traefik 2.0.1, #12 zot 0.2.0)' "$d4/role/loki-stack.md" \
  && grep -qF 'failed upgrade-3' "$d4/role/loki-stack.md" && grep -qF 'loki-region' "$d4/role/loki-stack.md" && grep -qF '#18 kata-deploy 4.3.2' "$d4/role/loki-stack.md" \
  && grep -qF 'stops before #13 loki-stack 2.10.4, which failed together with the bumps before it' "$d4/role/kata-deploy.md" \
  || fail "the comments of the breaker and the one after it: $(cat "$d4/role/loki-stack.md" "$d4/role/kata-deploy.md")"
printf 'origin v1.2.4 %s %s\npublic v1.2.4 %s\nimages v1.2.4 u 1\ngithub v1.2.4 https://example.test/releases/v1.2.4\nhelm 1.2.4 ok\n' "$prefix" "$prefix" "$prefix" > "$d4/release/steps"
bash "$root/ci/deps-run.sh" actions "$d4" https://example.test/run/9 1
grep -qF "$(printf 'close\t11')" "$d4/actions" && grep -qF "$(printf 'close\t12')" "$d4/actions" && grep -qF "$(printf 'label\t13\tneeds attention')" "$d4/actions" \
  && ! grep -qF "$(printf 'label\t18')" "$d4/actions" && grep -qF 'has the first 2 of them' "$d4/comments/12.md" || fail "the prefix's actions: $(cat "$d4/actions")"
# a suite of the first bump failed on the combined tree: the second breaks it, the first goes alone
res "$d4" batch upgrade-3 success
rm -f "$d4/results/deps-result-batch-upgrade-3/failures.md"
res "$d4" batch upgrade failure
bash "$root/ci/deps-run.sh" pick "$d4" "$S/clone" > /dev/null
grep -qx "RELEASE=$(cat "$d4/bump/traefik/head")" "$d4/release/release.env" && [ "$(cat "$d4"/role/{traefik,zot,loki-stack,kata-deploy} | paste -sd ' ' -)" = "release breaker wait wait" ] \
  || fail "a suite of the first bump failed: $(cat "$d4/release/release.env"; head "$d4"/role/*)"
res "$d4" batch upgrade success
res "$d4" batch shell failure
bash "$root/ci/deps-run.sh" pick "$d4" "$S/clone" > /dev/null
[ "$(cat "$d4"/role/{traefik,zot} | paste -sd ' ' -)" = "release breaker" ] || fail "runtime/test failed on the combined tree: $(head "$d4"/role/*)"

# next changed the lines of a chart whose image RunR pins itself (Zot's): next's own bump makes it
# again without reading the chart (--no-render), and the app version the tag's bump moved with the
# chart moves on next too, by next's own pin rules (its ci/deps/lib.sh)
fresh app
d5="$S/deps"
export FAKE_ZOT_APP="v2.1.0 v2.1.5"
echo '[{"issue": 12, "dep": "zot", "version": "0.2.0"}]' > "$tmp/tests5.json"
bash "$root/ci/deps-run.sh" bumps "$tmp/tests5.json" "$S/clone" "$d5" "$S/public.git" https://example.test/run/10 > "$S/bumps.log" 2>&1 || fail "bumps: $(tail -n 20 "$S/bumps.log")"
zrel="$(cat "$d5/bump/zot/head")"
git -C "$S/clone" show "${zrel}:api/zot-image.php" | grep -qF "zot:v2.1.5'" || fail "the tag's bump did not move Zot's app version: $(git -C "$S/clone" show "${zrel}:api/zot-image.php")"
w="$S/work"
git clone -q "$S/origin.git" "$w"
zconf="$(cd "$w" && git checkout -q next && cp "$root/ci/deps/lib.sh" "$root/ci/deps/packages.sh" "$root/ci/deps/replace.awk" ci/deps/ \
  && sed -i "s/^const ZOT = '0.1.0';/const ZOT = '0.1.0'; \/\/ the registry/" api/pins.php && git add -A && git commit -q -m "feat: next's pin rules" && git rev-parse HEAD)"
git -C "$S/clone" fetch -q "$w" "$zconf"
m5="$(bash "$root/ci/deps-release.sh" merge-next "$S/clone" "$zrel" "$zconf" zot:0.2.0 "$d5/bump" 2>"$S/merge.err")" || fail "Zot made again on next: $(cat "$S/merge.err")"
git -C "$S/clone" show "${m5}:api/pins.php" | grep -qxF "const ZOT = '0.2.0'; // the registry" || fail "next's Zot pin: $(git -C "$S/clone" show "${m5}:api/pins.php")"
git -C "$S/clone" show "${m5}:api/zot-image.php" | grep -qxF "const ZOT_IMAGE = 'ghcr.io/project-zot/zot:v2.1.5';" \
  || fail "next's Zot image kept the old app version: $(git -C "$S/clone" show "${m5}:api/zot-image.php")"
git -C "$S/clone" log -1 --format=%B "$m5" | tr '\n' ' ' | tr -s ' ' | grep -qF "moved the app version the tag's bump moved with the chart (zot v2.1.0 → v2.1.5)" \
  || fail "the merge's message: $(git -C "$S/clone" log -1 --format=%B "$m5")"
unset FAKE_ZOT_APP
echo "ok - next that changed a Zot bump's lines: next's own bump makes it again without the chart, and the app version the tag's bump moved with it moves on next by next's own pin rules"
unset FAKE_MODE
echo "ok - a combined tree that failed a suite: the first bump from the second on whose suites with those before it include it breaks the batch, the largest prefix is released (made the same way, not tested again; the first alone is its own tree), the ones after it wait"

# --- the attribution rule ----------------------------------------------------------------------------
ar="$tmp/attr"
git init -q -b main "$ar"
echo a > "$ar/a"
(cd "$ar" && git add -A && env "${BURAK_ENV[@]}" git commit -q -m base)
a0="$(git -C "$ar" rev-parse HEAD)"
# commit ENV-ARGS… FILE MESSAGE — a commit of FILE with the identity of the env arguments
acommit() {
  local file="${*: -2:1}" msg="${*: -1}"
  mkdir -p "$ar/$(dirname "$file")"
  echo "$RANDOM" >> "$ar/$file"
  (cd "$ar" && git add -A && env "${@:1:$#-2}" git commit -q -m "$msg")
  git -C "$ar" rev-parse HEAD
}
BOT_ENV=("${BURAK_ENV[@]}")
TR=$'\n\nRunR-Deps-Bot: traefik 2.0.1'
check() { bash "$root/ci/deps/check-attribution.sh" "$ar" "$1" "$2" 2>&1 || true; }
c1="$(acommit "${BOT_ENV[@]}" api/x.php "RunR 1.2.4${TR}")"
check "$a0" "$c1" | grep -q '^OK: 1 commit' || fail "a backend commit of the bot: $(check "$a0" "$c1")"
c2="$(acommit "${BOT_ENV[@]}" landing/index.html "chore(landing): pins${TR}")"
check "$c1" "$c2" | grep -qF 'a commit of the bot changes landing/ or vue/' || fail "the bot on landing/ passed"
c3="$(acommit "${BOT_ENV[@]}" api/y.php "$(printf 'x\n\nRunR-Deps-Bot: traefik 2.0.1\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')")"
check "$c2" "$c3" | grep -qF 'Co-Authored-By' || fail "the bot with a Co-Authored-By passed"
c4="$(acommit GIT_AUTHOR_NAME=x GIT_AUTHOR_EMAIL=x@example.test GIT_COMMITTER_NAME="$DEPS_BOT_NAME" GIT_COMMITTER_EMAIL="$DEPS_BOT_EMAIL" api/z.php "x${TR}")"
check "$c3" "$c4" | grep -qF "is not the bot's identity as author and committer" || fail "a bot commit of another author passed"
c5="$(acommit GIT_AUTHOR_NAME=someone GIT_AUTHOR_EMAIL=someone@example.test GIT_COMMITTER_NAME=someone GIT_COMMITTER_EMAIL=someone@example.test api/w.php "x")"
check "$c4" "$c5" | grep -qF 'author is neither' || fail "an unknown identity passed"
c6="$(acommit "${BOT_ENV[@]}" api/v.php "RunR 1.2.4 for internal-name${TR}")"
check "$c5" "$c6" | grep -qF 'names an internal system' || fail "an internal name passed"
check "$a0" "$c6" | grep -q '^FAILED: ' || fail "the range does not fail"
# a console npm bump of the bot: vue/package.json and vue/package-lock.json, nothing else of vue/
c7="$(acommit "${BOT_ENV[@]}" vue/package-lock.json "fix(ui): console npm packages (2 updates)${TR/traefik 2.0.1/npm-vue 0a1b2c3d}")"
check "$c6" "$c7" | grep -q '^OK: 1 commit' || fail "the bot's console npm bump: $(check "$c6" "$c7")"
c8="$(acommit "${BOT_ENV[@]}" vue/src/App.vue "fix(ui): x${TR}")"
check "$c7" "$c8" | grep -qF 'beyond vue/package.json and vue/package-lock.json' || fail "the bot on vue/src passed"
c9="$(acommit "${BOT_ENV[@]}" vue/package.json "$(printf 'fix(ui): x\n\nRunR-Deps-Bot: npm-vue 0a1b2c3d\nCo-Authored-By: Can Şentay <140180359+cansentay@users.noreply.github.com>')")"
check "$c8" "$c9" | grep -qF 'a commit of the bot has a Co-Authored-By' || fail "the bot's npm bump with Can passed"
# a person's commit whose body describes the trailer (as 497b7eb6's does): only the trailer block
# counts, so it is Burak's console commit with Claude, not the bot's (which may not touch vue/src)
c10="$(acommit "${BURAK_ENV[@]}" vue/src/App.vue "$(printf 'feat(console): x\n\nEach bump commit carries the trailer\nRunR-Deps-Bot: <dep> <version>, by which the release tells it apart.\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')")"
check "$c9" "$c10" | grep -q '^OK: 1 commit' || fail "a person's commit that mentions the trailer in its body counted as the bot's: $(check "$c9" "$c10")"
# the same line as the last paragraph is the trailer block: the bot's, and vue/src is refused
c11="$(acommit "${BOT_ENV[@]}" vue/src/App.vue "$(printf 'fix(ui): x\n\nRunR-Deps-Bot: npm-vue 0a1b2c3d')")"
check "$c10" "$c11" | grep -qF 'beyond vue/package.json and vue/package-lock.json' || fail "the bot's trailer block was not read: $(check "$c10" "$c11")"
echo "ok - the attribution rule: a commit of the bot (its trailer, in the trailer block) is its identity's, without Co-Authored-By, nothing of landing/ and of vue/ only package.json and package-lock.json; a person's commit that mentions the trailer in its body stays a person's; no unknown identity, no internal name"
