#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: the release ─────────────────────────────────────────
# The green dependency bumps of a run are one patch release of RunR (docs/development.md →
# Dependency test bot): they are made and tested on the last release tag, not on next, so nothing
# unfinished of next ships; when their suites pass, they are released together as the next patch
# version, and next takes the release as a merge (a fast-forward of next). The steps of
# .github/workflows/deps-test.yml that are more than a command, tested by
# runtime/test/deps_release_test.sh:
#
#   bash ci/deps-release.sh base CLONE PUBLIC
#       the release a run builds on: the newest release tag of the origin clone CLONE, which its main
#       is and its next contains, and which the public repository PUBLIC (its URL) has as its main,
#       with the same tree. Prints BASE_TAG, BASE_COMMIT, PUBLIC_BASE, NEW_VERSION, NEW_TAG (KEY=VALUE
#       lines); exit 1 with the reason when the bot cannot release on top of it.
#   bash ci/deps-release.sh prepare TREE BASE_TAG NEW_VERSION REPORT
#       in the checkout TREE, whose HEAD is one or more bump commits of the bot on BASE_TAG (each
#       with its trailer): the release commit "chore(release): NEW_VERSION" (helm/Chart.yaml, the
#       docs' pins, CHANGELOG.md's section from [Unreleased], docs/upgrade.md's section), committed
#       as the bot (DEPS_BOT_NAME, DEPS_BOT_EMAIL), with one trailer line per bump; REPORT holds the
#       suites of the bumps (suites.json, unit), the run (run-url) and, for bumps tested together,
#       combined (batch: on this release; prefix: on this release and later bumps). Exit 2: it
#       needs a person (REPORT/release-reasons.md says why, nothing is left changed).
#   bash ci/deps-release.sh merge-next REPO RELEASE NEXT [DEPS [UPDATES]]
#       the commit next becomes with the release RELEASE in it: RELEASE itself when NEXT is still the
#       release it was made on, otherwise a merge of RELEASE into NEXT made by the bot, where
#       CHANGELOG.md and docs/upgrade.md take the release's section above next's own. When next
#       changed lines the bumps change, every bump of DEPS (dep:version,dep:version…, in order) is
#       made again on next by next's own ci/deps-bump.sh (--no-render; a package group's with
#       UPDATES/<dep>/updates.json) instead, and the app version a chart bump moved on the tag
#       (its message: a chart whose image RunR pins, Zot's) moves on next by next's own pin rules;
#       then next's pins of the release before move too.
#       Exit 2 with the reason when it is not mechanical.
#   bash ci/deps-release.sh notes REPO RELEASE DIR
#       from the release commit: DIR/notes.md (the GitHub release), DIR/public-msg.txt (the public
#       commit), DIR/tag-msg.txt (the tags)
#   bash ci/deps-release.sh resume-env CLONE PUBLIC TAG
#       release.env of a release whose tag TAG is on the origin already (the workflow's `resume`),
#       its bumps read from the tag's trailer lines
#   bash ci/deps-release.sh land STATE [--dry-run]
#       the package groups' bumps that ship nothing (ci/deps-run.sh pick: PATH_KIND=next): the
#       bumps, one or more commits of the bot on next, become the origin's next in one fast-forward;
#       no release
#   bash ci/deps-release.sh publish|images|github|helm STATE [--dry-run]
#       the steps of a release, STATE/release.env saying which (REPO, RELEASE, DEPS, UPDATES,
#       BASE_TAG, BASE_COMMIT, PUBLIC_BASE, NEW_VERSION, NEW_TAG): publish signs the tags and pushes origin
#       (main, next, the tag: one atomic push) and then the public repository (main and the tag);
#       images waits for release-images.yml's run on the tag and checks every image it pushed
#       against the registry, anonymously; github creates the GitHub release; helm waits for the
#       chart on helm.brf.sh and compares it with the release's. Each step appends what it did to
#       STATE/steps; a release that cannot go on writes STATE/failed (or STATE/moved: the base
#       moved under it) and the later steps do nothing. --dry-run pushes and creates nothing: the
#       git half is made and checked, the other steps check the base release read-only.
#
# Needs bash, git, jq, awk; publish: gpg (ci/deps/sign-tag.sh); images: gh, crane; github: gh;
# helm: curl, helm.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECTIONS="$ROOT/ci/deps/release-sections.awk"
REPO_URL="https://github.com/brf-tech/runr"
VERSION_RE='^[0-9]+\.[0-9]+\.[0-9]+$'
TAG_RE='^v[0-9]+\.[0-9]+\.[0-9]+$'
SHA_RE='^[0-9a-f]{40}$'
# a release's bumps: dep:version,dep:version… (a version, a Knative minor or a package group's
# finding fingerprint)
DEP_ITEM='[a-z0-9-]+:(v?[0-9]+(\.[0-9]+){1,3}(-[0-9A-Za-z.]+)?|[0-9a-f]{8})'
DEPS_RE="^${DEP_ITEM}(,${DEP_ITEM})*\$"
# how long the images step waits for release-images.yml's run to appear and to end, and the helm
# step for the chart on helm.brf.sh (minutes), and how often it looks (seconds)
RUN_APPEAR_MIN="${DEPS_RUN_APPEAR_MIN:-20}"
RUN_TIMEOUT_MIN="${DEPS_RUN_TIMEOUT_MIN:-180}"
HELM_WAIT_MIN="${DEPS_HELM_WAIT_MIN:-30}"
POLL_S="${DEPS_POLL_S:-60}"
HELM_INDEX="${DEPS_HELM_INDEX:-https://helm.brf.sh/index.yaml}"
HELM_CHARTS_REPO="${DEPS_HELM_CHARTS_REPO:-BRF-Tech/helm-charts}"

# the trailer that marks the bot's commits and tags: their author is the identity of RunR's public
# history (DEPS_BOT_NAME, DEPS_BOT_EMAIL), the trailer tells them apart (ci/deps/check-attribution.sh)
DEPS_TRAILER="${DEPS_TRAILER:-RunR-Deps-Bot}"
# what a release never changes: the landing page, CI, and the console but for its npm manifest and
# lockfile (a console npm bump of the bot, its trailer and nothing else under vue/)
NOT_RELEASED='^(landing|\.github)/|^vue/'
VUE_ALLOWED='^vue/package(-lock)?\.json$'

die() { echo "$*" >&2; exit 1; }

# trailer_of REPO COMMIT — the bot's trailer value of a commit ("traefik 42.3.1"), empty when none
trailer_of() {
  git -C "$1" log -1 --format="%(trailers:key=${DEPS_TRAILER},valueonly,separator=%x2C)" "$2" 2>/dev/null | sed '/^$/d' | head -n 1
}

# trailers_of REPO COMMIT — every trailer value of the bot of a commit, one per line, in order (a
# release of several bumps has one line per bump)
trailers_of() {
  git -C "$1" log -1 --format="%(trailers:key=${DEPS_TRAILER},valueonly)" "$2" 2>/dev/null | sed '/^$/d'
}

# bump_line REPO BASE HEAD — the commits of BASE..HEAD, oldest first, when HEAD is one or more
# commits on BASE in a line (no merge), each with the bot's trailer; exit 1 otherwise
bump_line() {
  local n c
  n="$(git -C "$1" rev-list --count "${2}..${3}" 2>/dev/null || echo 0)"
  [ "$n" -ge 1 ] && [ "$(git -C "$1" rev-parse "${3}~${n}")" = "$(git -C "$1" rev-parse "${2}^{commit}")" ] \
    && [ -z "$(git -C "$1" rev-list --merges "${2}..${3}")" ] || return 1
  for c in $(git -C "$1" rev-list --reverse "${2}..${3}"); do
    [ -n "$(trailer_of "$1" "$c")" ] || return 1
    echo "$c"
  done
}

# deps_word N — "one dependency" or "N dependencies"
deps_word() { if [ "$1" = 1 ]; then echo "one dependency"; else echo "$1 dependencies"; fi; }

# not_released PATHS… — the paths of stdin a release does not take (landing/, .github/, vue/ but
# its package.json and package-lock.json)
not_released() { grep -E "$NOT_RELEASED" | grep -vE "$VUE_ALLOWED" || true; }

# polls MINUTES — how many times a wait of MINUTES looks, POLL_S apart
polls() { echo $(( $1 * 60 / (POLL_S > 0 ? POLL_S : 1) )); }

# bot ARGS… — git with the bot as author and committer
bot() {
  GIT_AUTHOR_NAME="${DEPS_BOT_NAME:?}" GIT_AUTHOR_EMAIL="${DEPS_BOT_EMAIL:?}" \
  GIT_COMMITTER_NAME="$DEPS_BOT_NAME" GIT_COMMITTER_EMAIL="$DEPS_BOT_EMAIL" \
    git -c commit.gpgSign=false "$@"
}

# anchor HEADING — GitHub's anchor of a Markdown heading ("0.6.8 → 0.6.9" → 068--069)
anchor() {
  tr '[:upper:]' '[:lower:]' <<<"$1" | sed -E 's/[^a-z0-9 _-]//g; s/ /-/g'
}

next_patch() {
  local v="${1#v}"
  echo "${v%.*}.$(( ${v##*.} + 1 ))"
}

# remote_refs REMOTE REF… — "SHA REF" lines of the remote's refs (tags peeled: their commit)
remote_refs() {
  local remote="$1" p pats=()
  shift
  # a tag's peeled line ("refs/tags/v1^{}") matches its own pattern only
  for p in "$@"; do
    pats+=("$p")
    case "$p" in refs/tags/*[!*]) pats+=("${p}^{}") ;; esac
  done
  git ls-remote "$remote" "${pats[@]}" | awk '
    { ref = $2; sha = $1 }
    ref ~ /\^\{\}$/ { sub(/\^\{\}$/, "", ref); peeled[ref] = sha; next }
    { plain[ref] = sha; order[++n] = ref }
    END { for (i = 1; i <= n; i++) { r = order[i]; print ((r in peeled) ? peeled[r] : plain[r]) " " r } }'
}
ref_of() { awk -v r="$2" '$2 == r { print $1 }' <<<"$1"; }

# the CLI's release, as the docs of TREE (a commit of REPO) pin it
cli_release() {
  git -C "$1" show "$2:docs/cli.md" 2>/dev/null | sed -nE 's/.*RUNR_VERSION=v([0-9]+\.[0-9]+\.[0-9]+) sh.*/\1/p' | head -n 1
}

# ── base ─────────────────────────────────────────────────────────────────────
cmd_base() {
  local clone="${1:?usage: ci/deps-release.sh base CLONE PUBLIC}" public="${2:?usage: ci/deps-release.sh base CLONE PUBLIC}"
  local tag commit main next refs pub_main pub_tag new tmp pub_tree
  tag="$(git -C "$clone" tag -l 'v*' | grep -E "$TAG_RE" | sort -V | tail -n 1 || true)"
  [ -n "$tag" ] || die "the origin has no release tag v<x.y.z>"
  commit="$(git -C "$clone" rev-parse "${tag}^{commit}")"
  main="$(git -C "$clone" rev-parse -q --verify refs/remotes/origin/main || true)"
  next="$(git -C "$clone" rev-parse -q --verify refs/remotes/origin/next || true)"
  [ -n "$main" ] && [ -n "$next" ] || die "the clone has no origin/main or origin/next"
  [ "$main" = "$commit" ] || die "origin's main (${main:0:12}) is not the last release ${tag} (${commit:0:12}): a release is under way, or main has commits of no release; the bot releases only on top of a release"
  git -C "$clone" merge-base --is-ancestor "$commit" "$next" || die "origin's next does not contain ${tag}: the next manual release would leave it out"
  new="$(next_patch "$tag")"
  git -C "$clone" rev-parse -q --verify "refs/tags/v${new}" >/dev/null && die "the origin has v${new} already"
  refs="$(remote_refs "$public" refs/heads/main 'refs/tags/v*')" || die "the public repository cannot be read"
  pub_main="$(ref_of "$refs" refs/heads/main)"
  pub_tag="$(ref_of "$refs" "refs/tags/${tag}")"
  [ -n "$pub_tag" ] || die "the public repository has no ${tag}: the last release is not published (a release of the bot that did not finish: run the workflow with resume=${tag})"
  [ -z "$(ref_of "$refs" "refs/tags/v${new}")" ] || die "the public repository has v${new} already"
  [ "$pub_main" = "$pub_tag" ] || die "the public main (${pub_main:0:12}) has commits after ${tag} (${pub_tag:0:12}) that are in no release: the bot would leave them out of its release's tree; a person releases them first"
  tmp="$(mktemp -d)"
  git init -q --bare "$tmp/public.git"
  git -C "$tmp/public.git" fetch -q --depth=1 "$public" "refs/tags/${tag}:refs/tags/${tag}" || { rm -rf -- "$tmp"; die "cannot fetch the public ${tag}"; }
  pub_tree="$(git -C "$tmp/public.git" rev-parse "${tag}^{tree}")"
  rm -rf -- "$tmp"
  [ "$pub_tree" = "$(git -C "$clone" rev-parse "${tag}^{tree}")" ] || die "the public ${tag} is another tree than the origin's ${tag}"
  printf 'BASE_TAG=%s\nBASE_COMMIT=%s\nPUBLIC_BASE=%s\nNEW_VERSION=%s\nNEW_TAG=v%s\n' "$tag" "$commit" "$pub_tag" "$new" "$new"
}

# ── prepare: the release commit ───────────────────────────────────────────────
# bullets FILE — the bullets of CHANGELOG.md's [Unreleased] section, each on one line
unreleased_bullets() {
  awk '
    $0 == "## [Unreleased]" { on = 1; next }
    on && /^## \[/ { exit }
    on && /^- / { if (cur != "") print cur; cur = $0; next }
    on && /^  [^ ]/ && cur != "" { sub(/^ +/, ""); cur = cur " " $0; next }
    on && cur != "" { print cur; cur = "" }
    END { if (cur != "") print cur }
  ' "$1"
}

# label BULLET — the bold head of a changelog bullet ("Region Traefik chart 42.3.1")
label() { sed -nE 's/^- \*\*([^*]+)\*\*.*/\1/p' <<<"$1"; }

# suites_sentence REPORT — the suites of the release's bumps as one sentence: PHPUnit when one of
# them needs it (REPORT/unit, 1 when there is none), runtime/test, the Kind suites and the checks
suites_sentence() {
  local report="$1" items=() kind check joined
  [ "$(cat "$report/unit" 2>/dev/null || echo 1)" != 1 ] || items+=("PHPUnit, Pint, Larastan and the API reference")
  items+=("\`runtime/test\`")
  kind="$(jq -r '[.[] | select((.job // "kind") == "kind") | "`runtime/ci.sh \(.command)`"] | join(", ")' "$report/suites.json")"
  check="$(jq -r '[.[] | select(.job == "check") | "`ci/deps-check.sh \(.command)`"] | join(", ")' "$report/suites.json")"
  [ -z "$kind" ] || items+=("$kind")
  [ -z "$check" ] || items+=("$check")
  # the console's suites, through their reusable workflows
  if jq -e 'any(.[]; .job == "ui")' "$report/suites.json" >/dev/null; then items+=("the console's lint, unit tests and build (\`ui-tests.yml\`)"); fi
  if jq -e 'any(.[]; .job == "ui-stack")' "$report/suites.json" >/dev/null; then items+=("the console against the dev stack (\`ui-stack-suite.yml\`)"); fi
  local IFS=';'
  joined="${items[*]}"
  echo "${joined//;/, }"
}

# suites_lead REPORT — where the suites ran: on this release (one bump, or bumps tested together),
# or on a tree of this release and later bumps of the run (a batch that broke after them)
suites_lead() {
  case "$(cat "$1/combined" 2>/dev/null || true)" in
    batch) echo "before the release was tagged, each bump's own on that bump alone and all of them on this release" ;;
    prefix) echo "before the release was tagged, each bump's own on that bump alone and all of them on this release together with the later bumps of the run" ;;
    *) echo "on this release before it was tagged" ;;
  esac
}

cmd_prepare() {
  local tree="${1:?usage: ci/deps-release.sh prepare TREE BASE_TAG NEW_VERSION REPORT}" base_tag="${2:?}" new="${3:?}" report="${4:?}"
  local prev base bump date cli bullets b labels run_url suites upgrade heading first rc work commits c trailers=() n
  [[ "$base_tag" =~ $TAG_RE ]] && [[ "$new" =~ $VERSION_RE ]] || die "prepare: BASE_TAG v<x.y.z>, NEW_VERSION x.y.z"
  prev="${base_tag#v}"
  base="$(git -C "$tree" rev-parse "${base_tag}^{commit}")"
  bump="$(git -C "$tree" rev-parse HEAD)"
  # HEAD: the bumps of the bot on the tag, one commit each, in the order of the release
  commits="$(bump_line "$tree" "$base" HEAD)" || die "prepare: HEAD is no line of bump commits of the bot (each with its ${DEPS_TRAILER} trailer) on ${base_tag}"
  [ -z "$(git -C "$tree" status --porcelain --untracked-files=no)" ] || die "prepare: the checkout has changes"
  for c in $commits; do trailers+=("$(trailer_of "$tree" "$c")"); done
  n="${#trailers[@]}"
  date="${DEPS_RELEASE_DATE:-$(date -u +%F)}"
  run_url="$(cat "$report/run-url" 2>/dev/null || true)"
  cli="$(cli_release "$tree" HEAD)"
  [ -n "$cli" ] || cli="$prev"
  work="$(mktemp -d)"
  rm -f -- "$report/release-reasons.md"

  # attention REASON… — the reasons, the checkout put back, status 2
  attention() {
    printf -- '- %s\n' "$@" > "$report/release-reasons.md"
    git -C "$tree" reset -q --hard "$bump"
    rm -rf -- "$work"
    exit 2
  }

  bullets="$(unreleased_bullets "$tree/CHANGELOG.md")"
  [ -n "$bullets" ] || attention "the bumps put no line under [Unreleased] of CHANGELOG.md: the release would have nothing to say"
  labels="$(while IFS= read -r b; do label "$b"; done <<<"$bullets" | paste -sd ';' - | sed 's/;/; /g')"

  # 1. the chart's version
  if ! grep -qxF "version: ${prev}" "$tree/helm/Chart.yaml" || ! grep -qxF "appVersion: \"${prev}\"" "$tree/helm/Chart.yaml"; then
    attention "helm/Chart.yaml of ${base_tag} does not say version ${prev} and appVersion \"${prev}\""
  fi
  sed -i -e "s/^version: ${prev//./\\.}\$/version: ${new}/" -e "s/^appVersion: \"${prev//./\\.}\"\$/appVersion: \"${new}\"/" "$tree/helm/Chart.yaml"

  # 2. the docs' pins of the chart and the tag (the CLI's stay: a dependency release builds none)
  rc=0
  (cd "$tree" && bash "$ROOT/ci/deps/release-pins.sh" "$prev" "$new" --server-only --keep="$ROOT/ci/deps/pin-keep.txt") > "$report/pins.log" 2>&1 || rc=$?
  case "$rc" in
    0) ;;
    2) attention "the docs name ${prev} where the bot does not know it as a pin or as history (ci/deps/release-pins.sh; a person adds the line to its rules or to ci/deps/pin-keep.txt): $(sed -n 's/^left //p' "$report/pins.log" | head -n 10 | paste -sd ';' - | sed 's/;/; /g')" ;;
    *) attention "ci/deps/release-pins.sh failed: $(tail -n 3 "$report/pins.log" | tr '\n' ' ')" ;;
  esac

  # 3. CHANGELOG.md: [Unreleased] becomes the release's section, with its upgrade notes
  heading="${prev} → ${new}"
  upgrade="$(anchor "$heading")"
  {
    echo "### Upgrade notes"
    echo ""
    echo "- **A dependency release of ${prev}: upgrading needs no step.** There is no database migration, the images keep their names and nothing has to be set; only $([ "$n" = 1 ] && echo "the dependency below changes" || echo "the ${n} dependencies below change"). The CLI, the Terraform provider and runr.sh stay those of ${cli}. See [docs/upgrade.md](docs/upgrade.md#${upgrade})." \
      | awk -v mode=wrap -f "$SECTIONS"
  } > "$work/notes.md"
  awk -v mode=release -v prev="$prev" -v new="$new" -v date="$date" -v notes="$work/notes.md" -f "$SECTIONS" "$tree/CHANGELOG.md" > "$work/CHANGELOG.md" 2>"$work/err" \
    || attention "CHANGELOG.md: $(cat "$work/err")"
  cat "$work/CHANGELOG.md" > "$tree/CHANGELOG.md"

  # 4. docs/upgrade.md: the release's section above the previous one
  first="$(grep -m 1 -E '^## [0-9]+\.[0-9]+\.[0-9]+ → [0-9]+\.[0-9]+\.[0-9]+$' "$tree/docs/upgrade.md" || true)"
  [ -n "$first" ] && [ "${first##* → }" = "$prev" ] || attention "docs/upgrade.md's newest section is not the one of ${prev} (${first:-none})"
  suites="$(suites_sentence "$report")"
  {
    echo "## ${heading}"
    echo ""
    {
      echo "${new} is a dependency release of ${prev}, made by the dependency test bot: it moves $(if [ "$n" = 1 ]; then echo "one dependency of a region"; else deps_word "$n"; fi) and nothing else. There is no database migration, the images keep their names, and nothing has to be set ([Procedure](#procedure)). The CLI, the Terraform provider and runr.sh stay those of ${cli}."
      echo ""
      cat <<<"$bullets"
      echo ""
      echo "The bot ran these suites $(suites_lead "$report"): ${suites}${run_url:+ ([run](${run_url}))}."
    } | awk -v mode=wrap -f "$SECTIONS"
  } > "$work/section.md"
  awk -v mode=insert -v before='^## [0-9]+[.][0-9]+[.][0-9]+ → ' -v section="$work/section.md" -f "$SECTIONS" "$tree/docs/upgrade.md" > "$work/upgrade.md" 2>"$work/err" \
    || attention "docs/upgrade.md: $(cat "$work/err")"
  cat "$work/upgrade.md" > "$tree/docs/upgrade.md"

  # 5. the commit
  {
    echo "chore(release): ${new}"
    echo ""
    echo "A dependency release of ${prev}, made by the dependency test bot: ${labels}. helm/Chart.yaml says ${new}, CHANGELOG.md has its section (what [Unreleased] held), docs/upgrade.md its ${heading} section, and the docs' examples pin the chart and the tag of ${new}; the CLI's pins stay at ${cli}, whose CLI it ships." \
      | awk -v mode=wrap -v width=72 -f "$SECTIONS"
    echo ""
    for b in "${trailers[@]}"; do echo "${DEPS_TRAILER}: ${b}"; done
  } > "$work/msg"
  git -C "$tree" add -A -- helm/Chart.yaml CHANGELOG.md docs/upgrade.md
  git -C "$tree" add -u
  bot -C "$tree" commit -q -F "$work/msg"
  # nothing of the console (but a console npm bump's manifest and lockfile), the landing page or CI:
  # their commits carry other authors
  local touched
  touched="$(git -C "$tree" diff --name-only "$base" HEAD | not_released)"
  if [ -n "$touched" ]; then
    git -C "$tree" reset -q --hard "$bump"
    attention "the release would change $(paste -sd ' ' <<<"$touched"), which the bot does not release"
  fi
  rm -rf -- "$work"
  git -C "$tree" log -1 --format='release commit %H: %s'
}

# ── merge-next ───────────────────────────────────────────────────────────────
# section_of REPO COMMIT FILE HEADING — the section of FILE at COMMIT that starts with HEADING
section_of() {
  git -C "$1" show "$2:$3" | awk -v mode=extract -v heading="$4" -f "$SECTIONS"
}

# deps_list DEPS — "dep version" lines of a list dep:version,dep:version…
deps_list() { tr ',' '\n' <<<"$1" | sed '/^$/d; s/:/ /'; }

# app_moved REPO COMMIT — "OLD NEW": the app version a chart bump of the bot moved with its chart, as
# its message says ("Zot chart 0.1.0 → 0.2.0, app version v2.1.0 → v2.1.5."); empty when it stayed
app_moved() {
  git -C "$1" log -1 --format=%B "$2" \
    | sed -nE '/^.* → [^ ,]+, app version [^ ]+ → [^ ]+\.$/{s/^.* → [^ ,]+, app version ([^ ]+) → ([^ ]+)\.$/\1 \2/p;q;}'
}

# app_on_next TREE DEP OLD NEW — next's own pin rules (its ci/deps/lib.sh: the DEPS_BOT row, which
# says whether RunR pins the image itself, and deps_replace_version) move DEP's app version OLD →
# NEW in TREE, as the tag's bump moved it with its chart
app_on_next() {
  # shellcheck disable=SC2016 # the inner shell's expressions
  (cd "$1" && bash -c '
    set -euo pipefail
    DEPS_ROOT="$PWD"
    source ci/deps/lib.sh
    row="$(deps_bot_row "$1" || true)"
    case "$(deps_field "$row" 5)" in
      direct:*) deps_replace_version "${2#v}" "${3#v}" "$(deps_field "$row" 4)" ;;
      *) echo "RunR does not pin $1'"'"'s image itself: nothing to move" ;;
    esac' deps-app "$2" "$3" "$4")
}

cmd_merge_next() {
  local repo="${1:?usage: ci/deps-release.sh merge-next REPO RELEASE NEXT [DEPS [UPDATES]]}" release="${2:?}" next="${3:?}" deps="${4:-}" updates_dir="${5:-}"
  local base_commit base_tag new prev tree rc out conflicts other work wt how merged d v args made=0 bumps c app apps=()
  release="$(git -C "$repo" rev-parse "${release}^{commit}")"
  next="$(git -C "$repo" rev-parse "${next}^{commit}")"
  # the release's base: the tag under its bumps (release ← bump… ← tag)
  base_tag="$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' "${release}^" 2>/dev/null)" || die "the release ${release:0:12} has no release tag under it"
  base_commit="$(git -C "$repo" rev-parse "${base_tag}^{commit}")"
  bumps="$(bump_line "$repo" "$base_commit" "${release}^")" || die "the release ${release:0:12} is not bump commits of the bot and its release commit on ${base_tag}"
  prev="${base_tag#v}"
  new="$(git -C "$repo" show "${release}:helm/Chart.yaml" | sed -n 's/^version: //p')"
  [[ "$new" =~ $VERSION_RE ]] || die "the release's helm/Chart.yaml has no version"
  if git -C "$repo" merge-base --is-ancestor "$release" "$next"; then
    echo "$next"
    return 0
  fi
  git -C "$repo" merge-base --is-ancestor "$base_commit" "$next" || { echo "next does not contain ${base_tag}" >&2; exit 2; }
  if [ "$next" = "$base_commit" ]; then
    echo "$release"
    return 0
  fi
  rc=0
  out="$(git -C "$repo" merge-tree --write-tree --name-only --no-messages "$next" "$release")" || rc=$?
  [ "$rc" = 0 ] || [ "$rc" = 1 ] || die "git merge-tree failed (${rc}): ${out}"
  tree="$(head -n 1 <<<"$out")"
  conflicts="$(tail -n +2 <<<"$out" | sed '/^$/d')"
  other="$(grep -vxE 'CHANGELOG\.md|docs/upgrade\.md' <<<"$conflicts" || true)"

  work="$(mktemp -d)"
  wt="$work/next"
  # merge_fail REASON — not mechanical: exit 2, nothing left behind
  merge_fail() {
    git -C "$repo" worktree remove --force "$wt" >/dev/null 2>&1 || true
    git -C "$repo" worktree prune >/dev/null 2>&1 || true
    rm -rf -- "$work"
    echo "$1; a person merges ${base_tag} → v${new} into next" >&2
    exit 2
  }
  git -C "$repo" worktree add -q --detach "$wt" "$next" >/dev/null 2>&1 || merge_fail "cannot check out next"
  if [ -z "$other" ]; then
    # git merges every file but the release's sections: its result
    git -C "$wt" read-tree -u --reset "$tree"
    how="git merges every file but CHANGELOG.md and docs/upgrade.md"
  else
    # next changed lines the bumps change: every bump is made again on next, in the release's
    # order, by next's own ci/deps-bump.sh (its pins, docs and tests as next has them; the charts
    # were compared on the tag already), with the release's chart version
    [ -n "$deps" ] || merge_fail "next changed what the release changes ($(paste -sd ' ' <<<"$other"))"
    while read -r d v; do
      args=(--report="$work/bump-${d}" --no-render)
      # a package group's updates (its finding's), as the bump on the tag had them
      [ -z "$updates_dir" ] || [ ! -s "$updates_dir/$d/updates.json" ] || args+=(--updates="$updates_dir/$d/updates.json")
      rc=0
      (cd "$wt" && bash ci/deps-bump.sh "$d" "$v" "${args[@]}") < /dev/null > "$work/bump.log" 2>&1 || rc=$?
      case "$rc" in
        0|3) ;;
        *) merge_fail "next changed what the release changes ($(paste -sd ' ' <<<"$other")), and next's own bump of ${d} ${v} $( [ "$rc" = 2 ] && echo "needs a person: $(sed -n 's/^- //p' "$work/bump-${d}/summary.md" 2>/dev/null | head -n 3 | paste -sd ';' -)" || echo "failed: $(tail -n 2 "$work/bump.log" | tr '\n' ' ')")" ;;
      esac
      # the app version the tag's bump moved with the chart (an image RunR pins itself, as Zot's):
      # --no-render does not read it, but it is the chart's own, the same chart on next; next's own
      # pin rules move it in next's text
      app=""
      if [ "$rc" = 0 ]; then
        for c in $bumps; do
          [ "$(trailer_of "$repo" "$c")" = "${d} ${v}" ] || continue
          app="$(app_moved "$repo" "$c")"
          break
        done
      fi
      if [ -n "$app" ]; then
        # shellcheck disable=SC2086 # OLD NEW, two words
        app_on_next "$wt" "$d" $app > "$work/app.log" 2>&1 \
          || merge_fail "next's own pin rules cannot move ${d}'s app version (${app/ / → }): $(tail -n 2 "$work/app.log" | tr '\n' ' ')"
        apps+=("${d} ${app/ / → }")
      fi
      # (the next bump needs a checkout without changes: a commit of the worktree's own, which only
      # the merge's tree takes)
      git -C "$wt" add -A
      bot -C "$wt" commit -q --allow-empty -m "deps: ${d} ${v} made again on next"
      made=$((made + 1))
    done < <(deps_list "$deps")
    if ! grep -qxF "version: ${prev}" "$wt/helm/Chart.yaml" || ! grep -qxF "appVersion: \"${prev}\"" "$wt/helm/Chart.yaml"; then
      merge_fail "next's helm/Chart.yaml does not say ${prev}"
    fi
    sed -i -e "s/^version: ${prev//./\\.}\$/version: ${new}/" -e "s/^appVersion: \"${prev//./\\.}\"\$/appVersion: \"${new}\"/" "$wt/helm/Chart.yaml"
    if [ "$made" = 1 ]; then
      how="next changed lines the bump changes ($(paste -sd ' ' <<<"$other")): next's own ci/deps-bump.sh made the bump again on it"
    else
      how="next changed lines the bumps change ($(paste -sd ' ' <<<"$other")): next's own ci/deps-bump.sh made the ${made} bumps again on it, in the release's order"
    fi
    if [ "${#apps[@]}" -gt 0 ]; then
      local IFS=';'
      how="${how}, and its pin rules moved the app version the tag's bump moved with the chart (${apps[*]})"
      unset IFS
    fi
  fi
  # next's own pins of the release before (what next added since: the release's rules)
  rc=0
  (cd "$wt" && bash "$ROOT/ci/deps/release-pins.sh" "$prev" "$new" --server-only --keep="${DEPS_PIN_KEEP:-$ROOT/ci/deps/pin-keep.txt}") > "$work/pins.log" 2>&1 || rc=$?
  [ "$rc" = 0 ] || merge_fail "next names ${prev} where the pin rules do not know it: $(sed -n 's/^left //p' "$work/pins.log" | head -n 5 | paste -sd ';' - | sed 's/;/; /g')"
  # CHANGELOG.md and docs/upgrade.md: next's own, with the release's section above next's newest
  # one (next's [Unreleased] stays where it is)
  section_of "$repo" "$release" CHANGELOG.md "## [${new}] - " > "$work/changelog-section"
  git -C "$repo" show "${next}:CHANGELOG.md" \
    | awk -v mode=insert -v before='^## [[][0-9]' -v section="$work/changelog-section" -v prev="$prev" -v new="$new" -f "$SECTIONS" > "$wt/CHANGELOG.md" 2>"$work/err" \
    || merge_fail "next's CHANGELOG.md: $(cat "$work/err")"
  section_of "$repo" "$release" docs/upgrade.md "## ${prev} → ${new}" > "$work/upgrade-section"
  git -C "$repo" show "${next}:docs/upgrade.md" \
    | awk -v mode=insert -v before='^## [0-9]+[.][0-9]+[.][0-9]+ → ' -v section="$work/upgrade-section" -f "$SECTIONS" > "$wt/docs/upgrade.md" 2>"$work/err" \
    || merge_fail "next's docs/upgrade.md: $(cat "$work/err")"
  git -C "$wt" add -A
  merged="$(git -C "$wt" write-tree)"
  {
    echo "Merge RunR ${new} into next"
    echo ""
    echo "v${new} is a dependency release of ${base_tag} (the bumps and their release commit on the tag); next takes it as a merge, so the release is in next's history and the next release from next carries it: ${how}. CHANGELOG.md and docs/upgrade.md get the release's section above next's newest one."
  } | awk -v mode=wrap -v width=72 -f "$SECTIONS" > "$work/msg"
  { echo ""; trailers_of "$repo" "$release" | sed "s/^/${DEPS_TRAILER}: /"; } >> "$work/msg"
  bot -C "$repo" commit-tree "$merged" -p "$next" -p "$release" -F "$work/msg"
  git -C "$repo" worktree remove --force "$wt" >/dev/null 2>&1 || true
  git -C "$repo" worktree prune >/dev/null 2>&1 || true
  rm -rf -- "$work"
}

# ── notes ────────────────────────────────────────────────────────────────────
cmd_notes() {
  local repo="${1:?usage: ci/deps-release.sh notes REPO RELEASE DIR}" release="${2:?}" dir="${3:?}"
  local new prev base_tag cli bullets labels suites run_url work n
  mkdir -p "$dir"
  new="$(git -C "$repo" show "${release}:helm/Chart.yaml" | sed -n 's/^version: //p')"
  base_tag="$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' "${release}^" 2>/dev/null)" || die "notes: no release tag before ${release}"
  prev="${base_tag#v}"
  cli="$(cli_release "$repo" "$release")"
  [ -n "$cli" ] || cli="$prev"
  work="$(mktemp -d)"
  section_of "$repo" "$release" CHANGELOG.md "## [${new}] - " > "$work/changelog" || die "notes: no [${new}] section in CHANGELOG.md"
  section_of "$repo" "$release" docs/upgrade.md "## ${prev} → ${new}" > "$work/upgrade" || die "notes: no ${prev} → ${new} section in docs/upgrade.md"
  # the release's bullets: those after its upgrade notes
  bullets="$(awk '/^### / { sec = $0; next } sec != "### Upgrade notes" && /^- / { if (cur != "") print cur; cur = $0; next }
    sec != "### Upgrade notes" && /^  [^ ]/ && cur != "" { sub(/^ +/, ""); cur = cur " " $0; next }
    cur != "" { print cur; cur = "" } END { if (cur != "") print cur }' "$work/changelog")"
  labels="$(while IFS= read -r b; do label "$b"; done <<<"$bullets" | paste -sd ';' - | sed 's/;/; /g')"
  # the suites paragraph of the upgrade section, on one line
  suites="$(awk '/^The bot ran these suites/ { on = 1 } on && /^$/ { exit } on { printf "%s%s", (n++ ? " " : ""), $0 } END { print "" }' "$work/upgrade")"
  run_url="$(grep -oE '\[run\]\([^)]+\)' <<<"$suites" | sed -E 's/^\[run\]\((.*)\)$/\1/' | head -n 1 || true)"
  {
    n="$(trailers_of "$repo" "$release" | wc -l | tr -d ' ')"
    echo "RunR ${new} is a dependency release of [${prev}](${REPO_URL}/releases/tag/v${prev}), made by RunR's dependency test bot: $([ "$n" -le 1 ] && echo "one dependency of a region moves" || echo "${n} dependencies move"), and nothing else changes."
    echo ""
    cat <<<"$bullets"
    echo ""
    echo "## Upgrading from ${prev}"
    echo ""
    echo "No database migration, the images keep their names, and nothing has to be set ([${prev} → ${new}](${REPO_URL}/blob/v${new}/docs/upgrade.md#$(anchor "${prev} → ${new}")) in docs/upgrade.md):"
    echo ""
    echo '```bash'
    echo "helm repo update brf-tech"
    echo "helm upgrade runr brf-tech/runr --version ${new} -n runr --reuse-values"
    echo '```'
    echo ""
    [ -z "$suites" ] || { echo "$suites"; echo ""; }
    echo "- **Images:** under \`ghcr.io/brf-tech/runr\` (\`api:${new}\`, \`ui:${new}\`, \`ui:${new}-mt\`, \`agent:${new}\`, \`queue-consumer:${new}\` and the region images), for linux/amd64 and linux/arm64, built by [release-images.yml](${REPO_URL}/blob/v${new}/.github/workflows/release-images.yml); they pull without credentials."
    echo "- **Chart:** \`brf-tech/runr\` ${new} from [helm.brf.sh](https://helm.brf.sh) (\`helm repo add brf-tech https://helm.brf.sh\`)."
    echo "- **CLI, Terraform provider and runr.sh:** unchanged, those of [RunR ${cli}](${REPO_URL}/releases/tag/v${cli}) (\`curl -fsSL https://runr.sh/install.sh | sh\`)."
    echo ""
    echo "Details: [CHANGELOG.md](${REPO_URL}/blob/v${new}/CHANGELOG.md), section ${new}."
  } > "$dir/notes.md"
  {
    echo "RunR ${new}"
    echo ""
    {
      echo "A dependency release of ${prev}, made by the dependency test bot: ${labels}."
      echo ""
      echo "The bot ran the suites on this tree before the release${run_url:+ (${run_url})}."
    } | awk -v mode=wrap -v width=72 -f "$SECTIONS"
    echo ""
    trailers_of "$repo" "$release" | sed "s/^/${DEPS_TRAILER}: /"
  } > "$dir/public-msg.txt"
  {
    echo "RunR ${new}"
    echo ""
    echo "A dependency release of ${prev}: ${labels}." | awk -v mode=wrap -v width=72 -f "$SECTIONS"
    echo ""
    trailers_of "$repo" "$release" | sed "s/^/${DEPS_TRAILER}: /"
  } > "$dir/tag-msg.txt"
  rm -rf -- "$work"
}

# ── resume-env ───────────────────────────────────────────────────────────────
cmd_resume_env() {
  local clone="${1:?usage: ci/deps-release.sh resume-env CLONE PUBLIC TAG}" public="${2:?}" tag="${3:?}" release base_tag refs pub_base tagger deps
  [[ "$tag" =~ $TAG_RE ]] || die "resume: ${tag} is no release tag"
  git -C "$clone" rev-parse -q --verify "refs/tags/${tag}" >/dev/null || die "resume: the origin has no ${tag}"
  tagger="$(git -C "$clone" cat-file tag "refs/tags/${tag}" 2>/dev/null | sed -n 's/^tagger \(.*>\) [0-9]* [-+][0-9]*$/\1/p' || true)"
  [ "$tagger" = "${DEPS_BOT_NAME:?} <${DEPS_BOT_EMAIL:?}>" ] || die "resume: ${tag} is no release of the bot (tagger ${tagger:-none})"
  git -C "$clone" cat-file tag "refs/tags/${tag}" | sed '1,/^$/d' | grep -qE "^${DEPS_TRAILER}: " \
    || die "resume: ${tag} is no release of the bot (its message has no ${DEPS_TRAILER} line: a release of a person)"
  release="$(git -C "$clone" rev-parse "${tag}^{commit}")"
  base_tag="$(git -C "$clone" describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' "${release}^" 2>/dev/null)" || die "resume: no release tag before ${tag}"
  refs="$(remote_refs "$public" "refs/tags/${base_tag}")" || die "resume: the public repository cannot be read"
  pub_base="$(ref_of "$refs" "refs/tags/${base_tag}")"
  [ -n "$pub_base" ] || die "resume: the public repository has no ${base_tag}"
  # the bumps it releases: the tag message's trailer lines ("RunR-Deps-Bot: traefik 42.3.1"), in order
  deps="$(git -C "$clone" cat-file tag "refs/tags/${tag}" | sed '1,/^$/d' | sed '/^-----BEGIN PGP SIGNATURE-----$/,$d' \
    | sed -n "s/^${DEPS_TRAILER}: \([a-z0-9-]*\) \([^ ]*\)\$/\1:\2/p" | paste -sd ',' -)"
  [ -n "$deps" ] || die "resume: ${tag}'s message names no bump (${DEPS_TRAILER}: <dep> <version>)"
  printf 'REPO=%s\nRELEASE=%s\nDEPS=%s\nBASE_TAG=%s\nBASE_COMMIT=%s\nPUBLIC_BASE=%s\nNEW_VERSION=%s\nNEW_TAG=%s\n' \
    "$clone" "$release" "$deps" "$base_tag" "$(git -C "$clone" rev-parse "${base_tag}^{commit}")" "$pub_base" "${tag#v}" "$tag"
}

# ── the release's steps ──────────────────────────────────────────────────────
# load STATE — release.env checked: every value of the form it must have
load() {
  st="$1"
  [ -f "$st/release.env" ] || die "no ${st}/release.env"
  REPO="$(sed -n 's/^REPO=//p' "$st/release.env")"
  RELEASE="$(sed -n 's/^RELEASE=//p' "$st/release.env")"
  BASE_TAG="$(sed -n 's/^BASE_TAG=//p' "$st/release.env")"
  BASE_COMMIT="$(sed -n 's/^BASE_COMMIT=//p' "$st/release.env")"
  PUBLIC_BASE="$(sed -n 's/^PUBLIC_BASE=//p' "$st/release.env")"
  NEW_VERSION="$(sed -n 's/^NEW_VERSION=//p' "$st/release.env")"
  NEW_TAG="$(sed -n 's/^NEW_TAG=//p' "$st/release.env")"
  # the bumps, in order (dep:version,dep:version…), to make them again on a next that conflicts, and
  # where the package groups' updates are (none on a resume: the origin has the release)
  DEPS="$(sed -n "s/^DEPS=//p" "$st/release.env")"
  UPDATES="$(sed -n "s/^UPDATES=//p" "$st/release.env")"
  [ -d "$REPO" ] && [[ "$RELEASE" =~ $SHA_RE ]] && [[ "$BASE_TAG" =~ $TAG_RE ]] && [[ "$BASE_COMMIT" =~ $SHA_RE ]] \
    && [[ "$PUBLIC_BASE" =~ $SHA_RE ]] && [[ "$NEW_VERSION" =~ $VERSION_RE ]] && [ "$NEW_TAG" = "v${NEW_VERSION}" ] \
    && [[ "$DEPS" =~ $DEPS_RE ]] && [[ -z "$UPDATES" || -d "$UPDATES" ]] \
    || die "${st}/release.env is not a release's"
  touch "$st/steps"
}
step() { echo "$*" >> "$st/steps"; echo "$*"; }
stopped() { [ -e "$st/failed" ] || [ -e "$st/moved" ]; }
failed() { echo "$*" > "$st/failed"; echo "::error::release ${NEW_TAG}: $*"; }
moved() { echo "$*" > "$st/moved"; echo "::warning::release ${NEW_TAG}: $*"; }
done_step() { grep -q "^$1 " "$st/steps"; }
step_value() { awk -v s="$1" -v f="$2" '$1 == s { v = $f } END { print v }' "$st/steps"; }

# make_tag REPO TAG TARGET MSGFILE [KEEP_GNUPGHOME] — the signed tag (a dry run without a key: unsigned)
make_tag() {
  if [ -n "${DEPS_SIGN_KEY:-}" ]; then
    DEPS_KEEP_GNUPGHOME="${5:-}" bash "$ROOT/ci/deps/sign-tag.sh" "$1" "$2" "$3" "$4"
  elif [ "$dry_run" = 1 ]; then
    local obj
    obj="$({ printf 'object %s\ntype commit\ntag %s\ntagger %s <%s> %s +0000\n\n' "$(git -C "$1" rev-parse "$3^{commit}")" "$2" "$DEPS_BOT_NAME" "$DEPS_BOT_EMAIL" "$(date +%s)"; cat "$4"; } | git -C "$1" mktag)"
    git -C "$1" update-ref "refs/tags/$2" "$obj" ""
    echo "$obj"
    UNSIGNED=1
  else
    echo "no signing key (the secret RUNR_BOT_GPG_PRIVATE_KEY)" >&2
    return 1
  fi
}

cmd_publish() {
  load "$1"
  stopped && return 0
  local o_refs o_main o_next o_tag p_refs p_main p_tag origin_done=0 public_done=0 try x m out now p pub tree paths notes_dir
  UNSIGNED=0
  : "${ORIGIN_REMOTE:?}" "${PUBLIC_REMOTE:?}"
  notes_dir="$st/text"
  ( cmd_notes "$REPO" "$RELEASE" "$notes_dir" ) 2>"$st/notes.err" || { failed "publish: the release's texts: $(cat "$st/notes.err")"; return 0; }
  # the scrub of a public release: nothing the release adds, and none of its texts, matches the
  # internal names of DEPS_FORBIDDEN (the secret RUNR_SCRUB_PATTERN, never in this repository's
  # files); the lines themselves are not printed (the log is public)
  local hits files
  if [ -z "${DEPS_FORBIDDEN:-}" ]; then
    [ "$dry_run" = 1 ] || { failed "publish: no scrub pattern (the secret RUNR_SCRUB_PATTERN)"; return 0; }
    step "scrub skipped (dry run without RUNR_SCRUB_PATTERN)"
  else
    hits="$( { git -C "$REPO" diff "$BASE_COMMIT" "$RELEASE" | grep '^+[^+]' || true; cat "$notes_dir/notes.md" "$notes_dir/public-msg.txt" "$notes_dir/tag-msg.txt"; } | grep -ciE "$DEPS_FORBIDDEN" || true)"
    if [ "${hits:-0}" != 0 ]; then
      files="$(git -C "$REPO" diff -U0 "$BASE_COMMIT" "$RELEASE" | awk '/^\+\+\+ b\// { f = substr($0, 7); next } /^\+/ { print f "\t" substr($0, 2) }' | grep -iE "$DEPS_FORBIDDEN" | cut -f1 | sort -u | paste -sd ' ' - || true)"
      failed "publish: the release names an internal system (the scrub pattern matches ${hits} line(s)${files:+ of ${files}}${files:- of its texts}): nothing was pushed"
      return 0
    fi
  fi

  o_refs="$(remote_refs "$ORIGIN_REMOTE" refs/heads/main refs/heads/next "refs/tags/${NEW_TAG}")" || { failed "publish: the origin cannot be read"; return 0; }
  o_main="$(ref_of "$o_refs" refs/heads/main)"
  o_next="$(ref_of "$o_refs" refs/heads/next)"
  o_tag="$(ref_of "$o_refs" "refs/tags/${NEW_TAG}")"
  if [ -n "$o_tag" ]; then
    [ "$o_tag" = "$RELEASE" ] || { moved "the origin has ${NEW_TAG} on another commit (${o_tag:0:12})"; return 0; }
    origin_done=1
    step "origin ${NEW_TAG} ${RELEASE} ${o_next} (there already)"
  elif [ "$o_main" != "$BASE_COMMIT" ]; then
    moved "origin's main moved (${o_main:0:12}, not ${BASE_TAG} = ${BASE_COMMIT:0:12}): another release came first"
    return 0
  fi
  p_refs="$(remote_refs "$PUBLIC_REMOTE" refs/heads/main "refs/tags/${NEW_TAG}")" || { failed "publish: the public repository cannot be read"; return 0; }
  p_main="$(ref_of "$p_refs" refs/heads/main)"
  p_tag="$(ref_of "$p_refs" "refs/tags/${NEW_TAG}")"
  if [ -n "$p_tag" ]; then
    public_done=1
  elif [ "$p_main" != "$PUBLIC_BASE" ]; then
    if [ "$origin_done" = 1 ]; then
      failed "publish: the origin has ${NEW_TAG}, but the public main moved (${p_main:0:12}, not ${BASE_TAG} = ${PUBLIC_BASE:0:12}): a person publishes ${NEW_TAG} by hand"
    else
      moved "the public main moved (${p_main:0:12}, not ${BASE_TAG} = ${PUBLIC_BASE:0:12})"
    fi
    return 0
  fi
  if [ "$origin_done" = 0 ] && [ "$public_done" = 1 ]; then
    failed "publish: the public repository has ${NEW_TAG} and the origin has not: a person decides"
    return 0
  fi

  # origin: main ← the release, next ← next with the release, the tag; one atomic push, retried
  # when next moved meanwhile (the merge is made again on the new next)
  if [ "$origin_done" = 0 ]; then
    # the tag is made here (a local one of an earlier try is not the origin's: made again)
    git -C "$REPO" update-ref -d "refs/tags/${NEW_TAG}" 2>/dev/null || true
    make_tag "$REPO" "$NEW_TAG" "$RELEASE" "$notes_dir/tag-msg.txt" > /dev/null 2>"$st/sign.err" || { failed "publish: the tag ${NEW_TAG}: $(cat "$st/sign.err")"; return 0; }
    for try in 1 2 3; do
      git -C "$REPO" fetch -q --no-tags "$ORIGIN_REMOTE" "+refs/heads/next:refs/deps-release/next" || { failed "publish: cannot fetch origin's next"; return 0; }
      x="$(git -C "$REPO" rev-parse refs/deps-release/next)"
      if ! m="$(cmd_merge_next "$REPO" "$RELEASE" "$x" "$DEPS" "$UPDATES" 2>"$st/merge.err")"; then
        failed "next cannot take the release mechanically: $(cat "$st/merge.err")"
        return 0
      fi
      if [ "$dry_run" = 1 ]; then
        git -C "$REPO" update-ref -d "refs/tags/${NEW_TAG}"
        step "origin ${NEW_TAG} ${RELEASE} ${m} (dry run: not pushed; next was ${x})"
        break
      fi
      if out="$(git -C "$REPO" push --atomic --porcelain "$ORIGIN_REMOTE" \
          "${RELEASE}:refs/heads/main" "${m}:refs/heads/next" "refs/tags/${NEW_TAG}:refs/tags/${NEW_TAG}" 2>&1)"; then
        step "origin ${NEW_TAG} ${RELEASE} ${m}"
        origin_done=1
        break
      fi
      now="$(remote_refs "$ORIGIN_REMOTE" refs/heads/main refs/heads/next "refs/tags/${NEW_TAG}" || true)"
      if [ "$(ref_of "$now" refs/heads/main)" != "$BASE_COMMIT" ] || [ -n "$(ref_of "$now" "refs/tags/${NEW_TAG}")" ]; then
        moved "the origin moved while the bot pushed (main $(ref_of "$now" refs/heads/main | cut -c1-12)): nothing of the release was pushed"
        return 0
      fi
      if [ "$(ref_of "$now" refs/heads/next)" = "$x" ]; then
        failed "publish: the origin refused the push: $(tail -n 5 <<<"$out" | tr '\n' ' ')"
        return 0
      fi
      echo "next moved while the bot pushed (try ${try}): merging again"
    done
    [ "$origin_done" = 1 ] || [ "$dry_run" = 1 ] || { failed "publish: next moved under three pushes in a row: nothing of the release was pushed"; return 0; }
  fi

  # the public repository: one commit of the release's tree on its main, and the tag
  pub="$st/public.git"
  rm -rf -- "$pub"
  git init -q --bare "$pub"
  if [ "$public_done" = 1 ]; then
    git -C "$pub" fetch -q --depth=1 "$PUBLIC_REMOTE" "refs/tags/${NEW_TAG}:refs/tags/${NEW_TAG}" || { failed "publish: cannot fetch the public ${NEW_TAG}"; return 0; }
    [ "$(git -C "$pub" rev-parse "${NEW_TAG}^{tree}")" = "$(git -C "$REPO" rev-parse "${RELEASE}^{tree}")" ] \
      || { failed "publish: the public ${NEW_TAG} is another tree than the release's"; return 0; }
    step "public ${NEW_TAG} ${p_tag} (there already)"
    return 0
  fi
  git -C "$pub" fetch -q --depth=1 "$PUBLIC_REMOTE" "refs/heads/main:refs/heads/main" || { failed "publish: cannot fetch the public main"; return 0; }
  [ "$(git -C "$pub" rev-parse refs/heads/main)" = "$PUBLIC_BASE" ] || { moved "the public main moved while the bot released"; return 0; }
  # the release's tree only: nothing of the origin's history enters the public clone
  git -C "$pub" fetch -q --no-tags --depth=1 "$REPO" "${RELEASE}:refs/deps-release/release"
  tree="$(git -C "$pub" rev-parse "${RELEASE}^{tree}")"
  git -C "$pub" update-ref -d refs/deps-release/release
  paths="$(git -C "$pub" diff --name-only "$PUBLIC_BASE" "$tree" | not_released)"
  [ -z "$paths" ] || { failed "publish: the release changes $(paste -sd ' ' <<<"$paths") against the public ${BASE_TAG}, which the bot does not publish"; return 0; }
  p="$(bot -C "$pub" commit-tree "$tree" -p "$PUBLIC_BASE" -F "$notes_dir/public-msg.txt")"
  git -C "$pub" update-ref refs/heads/main "$p" "$PUBLIC_BASE"
  rm -rf -- "$st/gnupg-public"
  make_tag "$pub" "$NEW_TAG" "$p" "$notes_dir/tag-msg.txt" "$st/gnupg-public" > /dev/null 2>"$st/sign.err" \
    || { rm -rf -- "$st/gnupg-public"; failed "publish: the public tag ${NEW_TAG}: $(cat "$st/sign.err")$([ "$origin_done" = 1 ] && echo " (the origin has ${NEW_TAG}: once fixed, run the workflow with resume=${NEW_TAG})")"; return 0; }
  # the attribution rule of the public repository (its tags verify with the bot's key)
  if [ "$UNSIGNED" = 1 ]; then
    out="$(bash "$ROOT/ci/deps/check-attribution.sh" "$pub" "$PUBLIC_BASE" "$p" 2>&1)" || true
  else
    out="$(GNUPGHOME="$st/gnupg-public" bash "$ROOT/ci/deps/check-attribution.sh" "$pub" "$PUBLIC_BASE" "$p" "$NEW_TAG" 2>&1)" || true
  fi
  rm -rf -- "$st/gnupg-public"
  echo "$out" > "$st/attribution.txt"
  grep -q '^OK: ' <<<"$out" || { failed "publish: the attribution rule: $(grep '^FAIL' <<<"$out" | head -n 3 | tr '\n' ' ')"; return 0; }
  if [ "$dry_run" = 1 ]; then
    step "public ${NEW_TAG} ${p} (dry run: not pushed$([ "$UNSIGNED" = 1 ] && echo '; tags unsigned: no signing key'))"
    return 0
  fi
  if ! out="$(git -C "$pub" push --atomic --porcelain "$PUBLIC_REMOTE" "${p}:refs/heads/main" "refs/tags/${NEW_TAG}:refs/tags/${NEW_TAG}" 2>&1)"; then
    failed "publish: the origin has ${NEW_TAG}, the public push failed: $(tail -n 5 <<<"$out" | tr '\n' ' ') — once fixed, run the workflow with resume=${NEW_TAG}"
    return 0
  fi
  step "public ${NEW_TAG} ${p}"
}

# images: release-images.yml's run on the public tag, and every image it pushed, anonymously
cmd_images() {
  load "$1"
  stopped && return 0
  local commit tag version i run id status conclusion url latest dir f line ref digest arch idx got refs=() region n=0 bad=0 tree anon
  : "${GH_REPO:?}"
  if [ "$dry_run" = 1 ]; then
    tag="$BASE_TAG"; version="${BASE_TAG#v}"; commit="$PUBLIC_BASE"
  else
    done_step public || { failed "images: the public repository has no ${NEW_TAG}"; return 0; }
    tag="$NEW_TAG"; version="$NEW_VERSION"; commit="$(step_value public 3)"
  fi
  run=""
  for ((i = 0; i <= $(polls "$RUN_APPEAR_MIN"); i++)); do
    run="$(gh api "repos/${GH_REPO}/actions/workflows/release-images.yml/runs?event=push&head_sha=${commit}&per_page=20" \
      --jq '[.workflow_runs[]] | sort_by(.created_at) | last | select(. != null) | [.id, .status, (.conclusion // "-"), .html_url] | @tsv' 2>/dev/null || true)"
    [ -n "$run" ] && break
    sleep "$POLL_S"
  done
  [ -n "$run" ] || { failed "images: no run of release-images.yml for ${tag} (${commit:0:12}) after ${RUN_APPEAR_MIN} min"; return 0; }
  id="$(cut -f1 <<<"$run")"
  for ((i = 0; i <= $(polls "$RUN_TIMEOUT_MIN"); i++)); do
    run="$(gh api "repos/${GH_REPO}/actions/runs/${id}" --jq '[.id, .status, (.conclusion // "-"), .html_url] | @tsv' 2>/dev/null || true)"
    status="$(cut -f2 <<<"$run")"
    [ "$status" = completed ] && break
    sleep "$POLL_S"
  done
  conclusion="$(cut -f3 <<<"$run")"
  url="$(cut -f4 <<<"$run")"
  [ "$status" = completed ] || { failed "images: release-images.yml's run ${url} did not end in ${RUN_TIMEOUT_MIN} min"; return 0; }
  [ "$conclusion" = success ] || { failed "images: release-images.yml's run ${url} ended ${conclusion}: no GitHub release; once its failed jobs passed (a re-run), run the workflow with resume=${tag}"; return 0; }

  # every tag of every image the run pushed names the digest it pushed for that platform; every
  # image of the release (the app images and runtime/ci.sh release-images of its tree) answers
  # for linux/amd64 and linux/arm64 without credentials
  dir="$st/digests"
  rm -rf -- "$dir"
  mkdir -p "$dir"
  for arch in amd64 arm64; do
    gh run download "$id" -R "$GH_REPO" -n "digests-${arch}" -D "$dir/${arch}" >/dev/null 2>&1 || { failed "images: the run's artifact digests-${arch} cannot be downloaded"; return 0; }
  done
  latest="$(git ls-remote --tags --refs "$PUBLIC_REMOTE" 'v*' | sed 's#.*refs/tags/##' | grep -E "$TAG_RE" | sort -V | tail -n 1 || true)"
  anon="$(mktemp -d)"
  echo '{}' > "$anon/config.json"
  : > "$st/images.txt"
  for f in "$dir"/amd64/*.txt "$dir"/arm64/*.txt; do
    [ -f "$f" ] || continue
    arch="$(basename "$(dirname "$f")")"
    while read -r line; do
      [ -n "$line" ] || continue
      digest="${line%% *}"
      digest="${digest#*@}"
      for ref in ${line#* }; do
        [[ "$ref" == *:latest ]] && [ "$latest" != "$tag" ] && continue
        idx="$(DOCKER_CONFIG="$anon" crane manifest "$ref" 2>&1)" || { echo "FAIL ${ref}: does not pull without credentials: $(tail -n 1 <<<"$idx")" >> "$st/images.txt"; bad=1; continue; }
        got="$(jq -r --arg a "$arch" '[.manifests[]? | select(.platform.os == "linux" and .platform.architecture == $a) | .digest][0] // empty' <<<"$idx")"
        if [ "$got" = "$digest" ]; then
          echo "${ref} linux/${arch} ${digest}" >> "$st/images.txt"
          n=$((n + 1))
        else
          echo "FAIL ${ref} linux/${arch}: ${got:-nothing}, the run pushed ${digest}" >> "$st/images.txt"
          bad=1
        fi
      done
    done < "$f"
  done
  tree="$(mktemp -d)"
  if [ "$dry_run" = 1 ]; then
    git -C "$REPO" archive "$BASE_TAG" helm runtime | tar -x -C "$tree"
  else
    git -C "$REPO" archive "$RELEASE" helm runtime | tar -x -C "$tree"
  fi
  mapfile -t region < <(bash "$tree/runtime/ci.sh" release-images 2>/dev/null)
  rm -rf -- "$tree"
  refs=("ghcr.io/brf-tech/runr/api:${version}" "ghcr.io/brf-tech/runr/ui:${version}" "ghcr.io/brf-tech/runr/ui:${version}-mt" ${region[@]+"${region[@]}"})
  [ "${#region[@]}" -gt 0 ] || { echo "FAIL runtime/ci.sh release-images listed no region image" >> "$st/images.txt"; bad=1; }
  for ref in "${refs[@]}"; do
    idx="$(DOCKER_CONFIG="$anon" crane manifest "$ref" 2>&1)" || { echo "FAIL ${ref}: does not pull without credentials: $(tail -n 1 <<<"$idx")" >> "$st/images.txt"; bad=1; continue; }
    got="$(jq -r '[.manifests[]?.platform | select(.architecture != "unknown") | "\(.os)/\(.architecture)"] | sort | join(",")' <<<"$idx")"
    [ "$got" = "linux/amd64,linux/arm64" ] || { echo "FAIL ${ref}: platforms '${got}'" >> "$st/images.txt"; bad=1; }
  done
  rm -rf -- "$anon"
  if [ "$bad" = 1 ] || [ "$n" = 0 ]; then
    failed "images: the registry does not serve what the run ${url} pushed: $(grep '^FAIL' "$st/images.txt" | head -n 5 | tr '\n' ' ')"
    return 0
  fi
  step "images ${tag} ${url} ${n}"
}

# github: the GitHub release, then the chart's publish on helm.brf.sh dispatched
cmd_github() {
  load "$1"
  stopped && return 0
  local state url latest
  : "${GH_REPO:?}"
  done_step images || { failed "github: the images are not checked"; return 0; }
  ( cmd_notes "$REPO" "$RELEASE" "$st/text" ) 2>"$st/notes.err" || { failed "github: the release's texts: $(cat "$st/notes.err")"; return 0; }
  if [ "$dry_run" = 1 ]; then
    state="$(gh release view "$BASE_TAG" -R "$GH_REPO" --json isDraft,url --jq '"\(.isDraft) \(.url)"' 2>/dev/null || echo "none")"
    [ "${state%% *}" = false ] || { failed "github: the release ${BASE_TAG} is not published (${state})"; return 0; }
    step "github ${NEW_TAG} ${state#* } (dry run: not created; the base release ${BASE_TAG} is)"
    return 0
  fi
  state="$(gh release view "$NEW_TAG" -R "$GH_REPO" --json isDraft --jq .isDraft 2>/dev/null || echo none)"
  case "$state" in
    false) ;;
    true) gh release edit "$NEW_TAG" -R "$GH_REPO" --draft=false --latest >/dev/null || { failed "github: the draft ${NEW_TAG} cannot be published"; return 0; } ;;
    *) gh release create "$NEW_TAG" -R "$GH_REPO" --verify-tag --latest --title "RunR ${NEW_VERSION}" --notes-file "$st/text/notes.md" >/dev/null \
         || { failed "github: the release ${NEW_TAG} cannot be created"; return 0; } ;;
  esac
  state="$(gh api "repos/${GH_REPO}/releases/tags/${NEW_TAG}" --jq '"\(.draft) \(.prerelease) \(.html_url)"' 2>/dev/null || echo none)"
  url="${state##* }"
  [ "${state%% *}" = false ] || { failed "github: the release ${NEW_TAG} reads ${state}"; return 0; }
  latest="$(gh api "repos/${GH_REPO}/releases/latest" --jq .tag_name 2>/dev/null || true)"
  [ "$latest" = "$NEW_TAG" ] || echo "::warning::the latest release is ${latest:-none}, not ${NEW_TAG}"
  if [ "$HELM_CHARTS_REPO" != none ]; then
    if GH_TOKEN="${DEPS_HELM_CHARTS_TOKEN:-${GH_TOKEN:-}}" gh api "repos/${HELM_CHARTS_REPO}/dispatches" -f event_type=product-released >/dev/null 2>&1; then
      step "github ${NEW_TAG} ${url} dispatched"
      return 0
    fi
    echo "::warning::the dispatch of ${HELM_CHARTS_REPO} failed: its schedule publishes the chart"
  fi
  step "github ${NEW_TAG} ${url}"
}

# helm: the chart on helm.brf.sh is the release's
cmd_helm() {
  load "$1"
  stopped && return 0
  local version ref i work pulled local_dir diffs f
  done_step github || { failed "helm: the GitHub release is not made"; return 0; }
  if [ "$HELM_INDEX" = none ]; then
    step "helm skipped (DEPS_HELM_INDEX=none)"
    return 0
  fi
  if [ "$dry_run" = 1 ]; then version="${BASE_TAG#v}"; ref="$BASE_TAG"; else version="$NEW_VERSION"; ref="$RELEASE"; fi
  work="$(mktemp -d)"
  for ((i = 0; i <= $(polls "$HELM_WAIT_MIN"); i++)); do
    if curl -fsS --retry 3 --retry-all-errors "$HELM_INDEX" -o "$work/index.yaml" 2>/dev/null \
      && awk -v v="$version" '/^  runr:/ { f = 1; next } /^  [^ -]/ { f = 0 } f && $1 == "version:" { gsub(/"/, "", $2); if ($2 == v) ok = 1 } END { exit !ok }' "$work/index.yaml"; then
      break
    fi
    rm -f -- "$work/index.yaml"
    sleep "$POLL_S"
  done
  [ -s "$work/index.yaml" ] || { rm -rf -- "$work"; failed "helm: ${HELM_INDEX} does not list runr ${version} after ${HELM_WAIT_MIN} min (BRF-Tech/helm-charts' publish): the release is out, the chart is not"; return 0; }
  export HELM_CACHE_HOME="$work/cache" HELM_CONFIG_HOME="$work/config" HELM_DATA_HOME="$work/data"
  helm repo add deps-release "${HELM_INDEX%/index.yaml}" >/dev/null 2>&1 \
    && helm pull deps-release/runr --version "$version" --untar --untardir "$work/pulled" >/dev/null 2>"$work/err" \
    || { failed "helm: runr ${version} cannot be pulled from ${HELM_INDEX%/index.yaml}: $(tail -n 2 "$work/err" | tr '\n' ' ')"; rm -rf -- "$work"; return 0; }
  mkdir -p "$work/src" "$work/pkg" "$work/local"
  git -C "$REPO" archive "$ref" helm | tar -x -C "$work/src"
  helm package "$work/src/helm" -d "$work/pkg" >/dev/null
  tar -xzf "$work/pkg/runr-${version}.tgz" -C "$work/local"
  pulled="$work/pulled/runr"
  local_dir="$work/local/runr"
  diffs="$(diff -rq -x Chart.yaml "$local_dir" "$pulled" 2>&1 || true)"
  for f in name version appVersion; do
    [ "$(sed -n "s/^${f}: //p" "$local_dir/Chart.yaml" | tr -d '"')" = "$(sed -n "s/^${f}: //p" "$pulled/Chart.yaml" | tr -d '"')" ] \
      || diffs="${diffs}${diffs:+; }Chart.yaml ${f}"
  done
  rm -rf -- "$work"
  [ -z "$diffs" ] || { failed "helm: helm.brf.sh's runr ${version} is not the release's chart: $(head -c 400 <<<"$diffs")"; return 0; }
  step "helm ${version} ok"
}

# ── land: the bumps of next only ─────────────────────────────────────────────
# land STATE [--dry-run] — STATE/release.env (PATH_KIND=next, REPO, LAND, NEXT_BASE, DEPS): LAND,
# one or more commits of the bot on NEXT_BASE (one bump each, with its trailer), becomes the
# origin's next in one fast-forward (never forced). A next that moved since writes STATE/moved: the
# bumps are made and tested again on the new next. Nothing goes to the public repository: the next
# full release carries them.
cmd_land() {
  st="$1"
  [ -f "$st/release.env" ] || die "no ${st}/release.env"
  local repo land next_base now out deps
  # (what failed and moved name)
  NEW_TAG="next"
  repo="$(sed -n 's/^REPO=//p' "$st/release.env")"
  land="$(sed -n 's/^LAND=//p' "$st/release.env")"
  next_base="$(sed -n 's/^NEXT_BASE=//p' "$st/release.env")"
  deps="$(sed -n 's/^DEPS=//p' "$st/release.env")"
  [ -d "$repo" ] && [[ "$land" =~ $SHA_RE ]] && [[ "$next_base" =~ $SHA_RE ]] && [[ -z "$deps" || "$deps" =~ $DEPS_RE ]] \
    || die "${st}/release.env is not a landing's"
  touch "$st/steps"
  stopped && return 0
  : "${ORIGIN_REMOTE:?}"
  bump_line "$repo" "$next_base" "$land" >/dev/null \
    || { failed "land: ${land:0:12} is not bump commits of the bot (each with its ${DEPS_TRAILER} trailer) on next ${next_base:0:12}"; return 0; }
  # the attribution rule holds for them as for a release (the bot's identity, its vue/ allowance)
  out="$(bash "$ROOT/ci/deps/check-attribution.sh" "$repo" "$next_base" "$land" 2>&1)" || true
  echo "$out" > "$st/attribution.txt"
  grep -q '^OK: ' <<<"$out" || { failed "land: the attribution rule: $(grep '^FAIL' <<<"$out" | head -n 3 | tr '\n' ' ')"; return 0; }
  now="$(remote_refs "$ORIGIN_REMOTE" refs/heads/next)" || { failed "land: the origin cannot be read"; return 0; }
  now="$(ref_of "$now" refs/heads/next)"
  if [ "$now" = "$land" ]; then step "land ${land} (there already)"; return 0; fi
  [ "$now" = "$next_base" ] || { moved "origin's next moved (${now:0:12}, not ${next_base:0:12}): the bumps are made again on it"; return 0; }
  if [ "$dry_run" = 1 ]; then
    step "land ${land} (dry run: not pushed; next is ${next_base})"
    return 0
  fi
  if ! out="$(git -C "$repo" push --porcelain "$ORIGIN_REMOTE" "${land}:refs/heads/next" 2>&1)"; then
    now="$(ref_of "$(remote_refs "$ORIGIN_REMOTE" refs/heads/next || true)" refs/heads/next)"
    if [ "$now" != "$next_base" ]; then moved "origin's next moved while the bot pushed (${now:0:12})"; return 0; fi
    failed "land: the origin refused the push: $(tail -n 5 <<<"$out" | tr '\n' ' ')"
    return 0
  fi
  step "land ${land}"
}

dry_run=0
command="${1:-}"
shift || true
case "$command" in
  base) cmd_base "$@" ;;
  prepare) cmd_prepare "$@" ;;
  merge-next) cmd_merge_next "$@" ;;
  notes) cmd_notes "$@" ;;
  resume-env) cmd_resume_env "$@" ;;
  land)
    [ "${2:-}" = --dry-run ] && dry_run=1
    cmd_land "${1:?usage: ci/deps-release.sh land STATE [--dry-run]}" ;;
  publish|images|github|helm)
    [ "${2:-}" = --dry-run ] && dry_run=1
    "cmd_${command}" "${1:?usage: ci/deps-release.sh ${command} STATE [--dry-run]}" ;;
  *) sed -n '4,57p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
