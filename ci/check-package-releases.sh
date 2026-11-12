#!/usr/bin/env bash
set -euo pipefail

# ── Package dependency release check ─────────────────────────────────────────
# The newer versions of every package dependency RunR ships or builds with (ci/deps/packages.sh):
# the API's Composer packages, the npm packages of the console and the SDKs, the Go modules of the
# region images, the CLI, the Terraform provider and the dev tunnel, the GitHub Actions pins, the
# Dockerfiles' base images, the tools the images and the workflows download (DEPS_PINS), the
# Python SDKs' packages and the examples' manifests. Read-only: it changes no file.
#
# One finding per group: every minor and patch update of a group found in one check is one
# Markdown file in RELEASES_REPORT_DIR, whose first line is its title,
#   deps: <group> [<fingerprint>]: <n> update(s): <name> <version>, … (security)
# (the fingerprint: of the updates' names and versions, so the same set is filed once) and whose
# body ends with a marker the bot reads: <!-- deps-bot {"group", "updates": [{name, from, to, …}],
# "urgent", "advisories"} -->. An update outside the manifest's constraint (a new major, a new
# minor of a 0.x caret range, an exact pin, a new Go major path, the Go toolchain) is a finding of
# its own that a person decides: deps: <group> <name> <version> released (outside <constraint>).
# A security advisory (composer audit, npm audit, govulncheck) makes the group's finding urgent:
# its packages join it and the title says (security). The holds (ci/deps/holds.txt) are left and
# named with their reasons. .github/workflows/upstream-releases.yml files an issue per title no
# issue has yet.
#
#   bash ci/check-package-releases.sh [KIND…]          composer npm go actions docker pins pypi
#                                                      cargo (default: all)
#   bash ci/check-package-releases.sh --known          only: every tracked manifest and version
#                                                      pin is known
#   RELEASES_REPORT_DIR=DIR bash ci/check-package-releases.sh
#
# Exit 1 when a source could not be read (a registry, a manifest or a pin the tables do not know,
# a pin whose places disagree): the others are still checked and reported. Needs bash, jq, git,
# curl, perl, GNU sort and, for their kinds, composer, npm, go (govulncheck when on PATH) and
# python3 (3.11 or newer: pypi, cargo).
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC2034 # read by ci/deps/lib.sh
DEPS_ROOT="$ROOT"
# shellcheck source=ci/deps/lib.sh
source "$ROOT/ci/deps/lib.sh"

GHCR="${DEPS_GHCR:-https://ghcr.io}"
GOPROXY_URL="${DEPS_GOPROXY:-https://proxy.golang.org}"
GITHUB_URL="${DEPS_GITHUB_URL:-https://github.com}"
PYPI_URL="${DEPS_PYPI:-https://pypi.org/pypi}"
CRATES_INDEX="${DEPS_CRATES_INDEX:-https://index.crates.io}"
GO_DL_URL="${DEPS_GO_DL:-https://go.dev/dl/?mode=json}"
NODE_DIST_URL="${DEPS_NODE_DIST:-https://nodejs.org/dist/index.json}"
PHP_RELEASES_URL="${DEPS_PHP_RELEASES:-https://www.php.net/releases/active.php}"
DATE="${DEPS_CHECK_DATE:-$(date -u +%F)}"
kinds="${*:-composer npm go actions docker pins pypi cargo}"
unread=0
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT

# ── Findings ─────────────────────────────────────────────────────────────────
# report NAME TITLE BODY — one finding as RELEASES_REPORT_DIR/NAME.md
report() {
  [ -n "${RELEASES_REPORT_DIR:-}" ] || return 0
  mkdir -p "$RELEASES_REPORT_DIR"
  printf '%s\n\n%s\n' "$2" "$3" > "${RELEASES_REPORT_DIR}/$1.md"
}
unreadable() { echo "✗ $*"; unread=$((unread + 1)); }

# finding GROUP UPDATES ADVISORIES — the group's finding: UPDATES a JSON array of {name, from, to,
# …}, ADVISORIES a JSON array of {id, package}
finding() {
  local group="$1" updates="$2" advisories="${3:-[]}" n fp title urgent=false names marker list
  n="$(jq length <<<"$updates")"
  [ "$n" -gt 0 ] || { echo "✓ ${group}: nothing to move"; return 0; }
  [ "$(jq length <<<"$advisories")" -eq 0 ] || urgent=true
  fp="$(jq -r '.[] | "\(.name)@\(.to)"' <<<"$updates" | deps_fingerprint)"
  names="$(jq -r '[.[:2][] | "\(.name) \(.to)"] | join(", ")' <<<"$updates")"
  [ "$n" -le 2 ] || names="${names} and $((n - 2)) more"
  title="deps: ${group} [${fp}]: ${n} update"
  [ "$n" = 1 ] || title="${title}s"
  title="${title}: ${names}"
  [ "$urgent" != true ] || title="${title} (security)"
  list="$(jq -r '.[] | "| `\(.name)` | \(.from) | \(.to) | \(.files // [] | join(", ")) |"' <<<"$updates")"
  marker="$(jq -nc --arg group "$group" --argjson updates "$updates" --argjson urgent "$urgent" --argjson advisories "$advisories" \
    '{group: $group, updates: $updates, urgent: $urgent, advisories: $advisories}')"
  echo "▶ ${title}"
  report "deps-${group}-${fp}" "$title" "$(
    echo "Minor and patch updates of **${group}** ($(deps_field "$(deps_pkg_row "$group")" 5)), found on ${DATE}: one bump, tested and released (or landed on next) by the dependency test bot (docs/development.md → Dependency test bot)."
    echo ""
    echo "| Package | Pinned | New | Where |"
    echo "|---|---|---|---|"
    echo "$list"
    if [ "$urgent" = true ]; then
      echo ""
      echo "**Security:** $(jq -r '[.[] | "\(.id) (\(.package))"] | unique | join(", ")' <<<"$advisories")."
    fi
    echo ""
    echo "Reported by ci/check-package-releases.sh."
    echo ""
    echo "<!-- deps-bot ${marker} -->"
  )"
}

# series VERSION — the release line a version opens (v13.42.0 → v13.x, 0.578.1 → 0.578.x); other
# forms as they are (24-alpine)
series() {
  if [[ "$1" =~ ^(v?)0\.([0-9]+)\.[0-9] ]]; then echo "${BASH_REMATCH[1]}0.${BASH_REMATCH[2]}.x"
  elif [[ "$1" =~ ^(v?)([0-9]+)\.[0-9]+ ]]; then echo "${BASH_REMATCH[1]}${BASH_REMATCH[2]}.x"
  else echo "$1"; fi
}

# outside GROUP NAME FROM TO CONSTRAINT WHERE [LINE] [WHY] — an update a person decides: one finding
# per release line (vite 8.x; LINE when the version's form says otherwise), not per version; WHY
# when it is outside for another reason than these
outside() {
  local slug line
  slug="$(tr -c 'a-zA-Z0-9.\n-' '-' <<<"$2" | tr -s '-')"
  line="${7:-$(series "$4")}"
  if [ "$2" = go ]; then line="$4"; fi
  echo "▶ deps: $1 $2 ${line} released (outside $5)"
  report "deps-$1-outside-${slug}-${line}" "deps: $1 $2 ${line} released (outside $5)" "**${2}** ${4} is out; ${1} pins ${3} (${6}), and ${4} is outside \`${5}\`: ${8:-a new major, a new minor of a 0.x range, an exact pin or a new toolchain}. The bot does not move it: read its release notes, move it by hand (with the code it needs) and the bot takes the minor and patch updates from there.

Reported by ci/check-package-releases.sh."
}

# held GROUP NAME VERSION — true (and says so) when the holds keep NAME at VERSION
held() {
  local reason
  reason="$(deps_held "$1" "$2" "$3")" || return 1
  echo "  held: $2 $3 ($reason)"
  return 0
}
# held_always GROUP NAME — true (and says so) when the holds never move NAME, whatever its version
held_always() {
  local reason
  reason="$(deps_held_always "$1" "$2")" || return 1
  echo "  held: $2 ($reason)"
  return 0
}

# ── Composer ─────────────────────────────────────────────────────────────────
detect_composer() {
  local group="$1" dir="$2" minor major audit updates advisories name from to direct constraint
  if ! minor="$(cd "$dir" && composer outdated --locked --minor-only --format=json --no-interaction 2>"$work/composer.err")"; then
    unreadable "${group}: composer outdated failed: $(tail -n 2 "$work/composer.err" | tr '\n' ' ')"; return 0
  fi
  major="$(cd "$dir" && composer outdated --locked --direct --major-only --format=json --no-interaction 2>/dev/null)" || major='{}'
  audit="$(cd "$dir" && composer audit --locked --format=json --no-interaction 2>/dev/null)" || true
  jq -e . >/dev/null 2>&1 <<<"$audit" || audit='{}'
  advisories="$(jq -c '[.advisories // {} | to_entries[] | .key as $p | .value[] | {id: (.cve // .advisoryId), package: $p}]' <<<"$audit")"
  updates='[]'
  while IFS=$'\t' read -r name from to direct; do
    [ -n "$name" ] || continue
    held "$group" "$name" "$to" && continue
    # a transitive package moves only for an advisory (with-dependencies moves the rest with it)
    if [ "$direct" != true ] && ! jq -e --arg p "$name" 'any(.[]; .package == $p)' >/dev/null <<<"$advisories"; then continue; fi
    updates="$(jq -c --arg n "$name" --arg f "$from" --arg t "$to" --arg file "${dir}/composer.lock" '. + [{name: $n, from: $f, to: $t, files: [$file]}]' <<<"$updates")"
  done < <(jq -r '(.locked // .installed // [])[] | select(.latest != .version and (.latest | test("^v?[0-9]"))) | [.name, .version, .latest, (if has("direct-dependency") then ."direct-dependency" else true end | tostring)] | @tsv' <<<"$minor")
  finding "$group" "$updates" "$advisories"
  while IFS=$'\t' read -r name from to; do
    [ -n "$name" ] || continue
    held "$group" "$name" "$to" && continue
    constraint="$(jq -r --arg n "$name" '(.require[$n] // ."require-dev"[$n]) // "?"' "${dir}/composer.json")"
    outside "$group" "$name" "$from" "$to" "$constraint" "${dir}/composer.json"
  done < <(jq -r '(.locked // .installed // [])[] | select(.latest != .version) | [.name, .version, .latest] | @tsv' <<<"$major")
}

# ── npm ──────────────────────────────────────────────────────────────────────
detect_npm() {
  local group="$1" dir="$2" lock="$2/package-lock.json" updates='[]' advisories='[]' name range from wanted latest audit
  [ -f "$dir/package.json" ] || { unreadable "${group}: no ${dir}/package.json"; return 0; }
  if [ -f "$lock" ]; then
    audit="$(cd "$dir" && npm audit --package-lock-only --json 2>/dev/null)" || true
    jq -e . >/dev/null 2>&1 <<<"$audit" || audit='{}'
    advisories="$(jq -c '[.vulnerabilities // {} | to_entries[] | select(.value.fixAvailable == true or ((.value.fixAvailable | type) == "object" and .value.fixAvailable.isSemVerMajor == false)) | .key as $p | .value.via[]? | objects | {id: (.url // .source | tostring | sub("^https://github.com/advisories/"; "")), package: $p}] | unique' <<<"$audit")"
  fi
  while IFS=$'\t' read -r name range; do
    [ -n "$name" ] || continue
    from=""
    [ -f "$lock" ] && from="$(jq -r --arg n "$name" '.packages["node_modules/" + $n].version // empty' "$lock")"
    # a package the holds never move need not be on the registry (RunR's own SDKs)
    latest="$(npm view "$name" dist-tags.latest 2>"$work/npm.err")" || { held_always "$group" "$name" || unreadable "${group}: npm view ${name}: $(tail -n 1 "$work/npm.err")"; continue; }
    # the newest version the range takes (npm's own semver): an array, a string or nothing
    wanted="$(npm view "${name}@${range}" version --json 2>/dev/null | jq -r 'if type == "array" then .[-1] else . end // empty' 2>/dev/null)" || wanted=""
    if [ -f "$lock" ] && [ -n "$from" ] && [ -n "$wanted" ] && deps_is_newer "$wanted" "$from" && ! held "$group" "$name" "$wanted"; then
      updates="$(jq -c --arg n "$name" --arg f "$from" --arg t "$wanted" --arg file "$lock" '. + [{name: $n, from: $f, to: $t, files: [$file]}]' <<<"$updates")"
    fi
    if [ -n "$latest" ] && deps_is_newer "$latest" "${wanted:-${from:-0}}" && ! held "$group" "$name" "$latest"; then
      outside "$group" "$name" "${from:-$range}" "$latest" "$range" "${dir}/package.json"
    fi
  done < <(jq -r '(.dependencies // {}), (.devDependencies // {}) | to_entries[] | [.key, .value] | @tsv' "$dir/package.json")
  # an advisory of a package the updates do not move (a transitive one): npm update takes it within
  # the ranges of the tree
  while IFS= read -r name; do
    [ -n "$name" ] || continue
    jq -e --arg n "$name" 'any(.[]; .name == $n)' >/dev/null <<<"$updates" && continue
    from="$(jq -r --arg n "$name" '.packages["node_modules/" + $n].version // "?"' "$lock")"
    updates="$(jq -c --arg n "$name" --arg f "$from" --arg file "$lock" '. + [{name: $n, from: $f, to: "fixed", files: [$file]}]' <<<"$updates")"
  done < <(jq -r '.[].package' <<<"$advisories" | sort -u)
  finding "$group" "$updates" "$advisories"
}

# ── Go modules ───────────────────────────────────────────────────────────────
# next_major PATH — the module path of the next major (example.com/m → example.com/m/v2,
# example.com/m/v2 → example.com/m/v3, gopkg.in/yaml.v3 → gopkg.in/yaml.v4)
next_major() {
  if [[ "$1" =~ ^(gopkg\.in/.*\.v)([0-9]+)$ ]]; then echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
  elif [[ "$1" =~ ^(.*/v)([0-9]+)$ ]]; then echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
  else echo "$1/v2"; fi
}
# goproxy_escape PATH — the module proxy's case encoding (Upper → !upper)
goproxy_escape() { sed -E 's/([A-Z])/!\L\1/g' <<<"$1"; }

detect_go() {
  local group="$1" dir="$2" mods updates advisories='[]' name from to next latest vulns src="$2" nolock=0
  # a module that requires others and keeps no go.sum (an example the region's build resolves) is
  # a manifest without its lockfile: only its new majors are findings, and go list reads a copy of
  # its go.mod (-mod=mod would write a go.sum here)
  if [ ! -f "$dir/go.sum" ] && grep -qE '^require[[:space:]]' "$dir/go.mod" 2>/dev/null; then
    nolock=1
    src="$work/go-${group}"
    mkdir -p "$src"
    cp "$dir/go.mod" "$src/go.mod"
  fi
  if ! mods="$(cd "$src" && GOFLAGS=-mod=mod go list -m -u -json all 2>"$work/go.err")"; then
    unreadable "${group}: go list -m -u failed: $(tail -n 2 "$work/go.err" | tr '\n' ' ')"; return 0
  fi
  if [ "$nolock" = 0 ] && command -v govulncheck >/dev/null 2>&1; then
    vulns="$(cd "$dir" && govulncheck -format json ./... 2>/dev/null)" || true
    advisories="$(jq -sc '[.[] | .finding? // empty | select(.trace[0].function) | {id: .osv, package: .trace[0].module, fixed: .fixed_version}] | unique' <<<"${vulns:-}" 2>/dev/null)" || advisories='[]'
  fi
  updates="$(jq -sc --arg file "${dir}/go.mod" '[.[] | select((.Main // false | not) and (.Indirect // false | not) and .Update) | {name: .Path, from: .Version, to: .Update.Version, files: [$file]}]' <<<"$mods")"
  [ "$nolock" = 0 ] || updates='[]'
  # an advisory of a module the updates do not move: its fixed version, when it is in the same major
  while IFS=$'\t' read -r name to; do
    [ -n "$name" ] || continue
    if [ "$name" = stdlib ] || [ "$name" = toolchain ]; then
      # the toolchain is every module's: one finding of the check (below), not one per module
      if [ -z "$go_fixed" ] || deps_is_newer "$to" "$go_fixed"; then go_fixed="$to"; fi
      go_from="$(sed -n 's/^go //p' "$dir/go.mod")"
      continue
    fi
    jq -e --arg n "$name" 'any(.[]; .name == $n)' >/dev/null <<<"$updates" && continue
    from="$(jq -sr --arg n "$name" '[.[] | select(.Path == $n) | .Version][0] // "?"' <<<"$mods")"
    updates="$(jq -c --arg n "$name" --arg f "$from" --arg t "$to" --arg file "${dir}/go.mod" '. + [{name: $n, from: $f, to: $t, files: [$file]}]' <<<"$updates")"
  done < <(jq -r '.[] | select(.fixed != null and .fixed != "") | [.package, .fixed] | @tsv' <<<"$advisories")
  local kept='[]' row
  while IFS= read -r row; do
    name="$(jq -r .name <<<"$row")"; to="$(jq -r .to <<<"$row")"
    held "$group" "$name" "$to" || kept="$(jq -c --argjson r "$row" '. + [$r]' <<<"$kept")"
  done < <(jq -c '.[]' <<<"$updates")
  finding "$group" "$kept" "$(jq -c '[.[] | select(.package != "stdlib" and .package != "toolchain")]' <<<"$advisories")"
  # a new major is a module of its own path: one request per direct requirement
  while IFS=$'\t' read -r name from; do
    [ -n "$name" ] || continue
    next="$(next_major "$name")"
    latest="$(curl -fsS --max-time 20 "${GOPROXY_URL}/$(goproxy_escape "$next")/@v/list" 2>/dev/null | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n 1)" || latest=""
    [ -n "$latest" ] || continue
    held "$group" "$name" "$latest" && continue
    outside "$group" "$next" "$from" "$latest" "${name}" "${dir}/go.mod"
  done < <(jq -sr '.[] | select((.Main // false | not) and (.Indirect // false | not)) | [.Path, .Version] | @tsv' <<<"$mods")
}

# ── GitHub Actions pins ──────────────────────────────────────────────────────
# the newest release tag of a repository: "TAG SHA" (the tag's commit), within a major or overall
newest_tag() {
  local tags="$1" major="${2:-}"
  awk '{ sha = $1; ref = $2; sub(/^refs\/tags\//, "", ref)
         if (ref ~ /\^\{\}$/) { sub(/\^\{\}$/, "", ref); peeled[ref] = sha } else if (!(ref in plain)) plain[ref] = sha }
       END { for (r in plain) print r, ((r in peeled) ? peeled[r] : plain[r]) }' <<<"$tags" \
    | grep -E '^v?[0-9]+(\.[0-9]+){0,2} ' \
    | { if [ -n "$major" ]; then awk -v m="$major" '{ v = $1; sub(/^v/, "", v); split(v, p, "."); if (p[1] == m) print }'; else cat; fi; } \
    | sort -V -k1,1 | tail -n 1
}

detect_actions() {
  local group="$1" uses repo ref comment file updates='[]' tags key best major latest
  declare -A seen=() tagcache=()
  local line re='uses:[[:space:]]*["'"'"']?([A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+)@([A-Za-z0-9_.-]+)["'"'"']?([[:space:]]*#[[:space:]]*([^[:space:]]+))?'
  uses="$(git grep -n -I -E '^[^#]*uses: *[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@' -- '.github/workflows/*.yml' 'actions/*/action.yml' 2>/dev/null \
    | while IFS= read -r line; do
        [[ "$line" =~ $re ]] && printf '%s\t%s\t%s\t%s\n' "${line%%:*}" "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[4]}"
      done)" || uses=""
  while IFS=$'\t' read -r file repo ref comment; do
    [ -n "$repo" ] || continue
    key="${repo}@${ref}"
    [[ "$repo" == ./* ]] && continue
    local slug
    slug="${repo%%/*}/$(cut -d/ -f2 <<<"$repo")"
    if [ -z "${tagcache[$slug]+x}" ]; then
      tagcache[$slug]="$(git ls-remote --tags "${GITHUB_URL}/${slug}" 2>"$work/ls.err")" || { unreadable "${group}: git ls-remote ${slug}: $(tail -n 1 "$work/ls.err")"; tagcache[$slug]=""; }
    fi
    tags="${tagcache[$slug]}"
    [ -n "$tags" ] || continue
    if [[ "$ref" =~ ^[0-9a-f]{40}$ ]] && [[ "$comment" =~ ^v?[0-9]+(\.[0-9]+)*$ ]]; then
      major="$(deps_major "$comment")"
      best="$(newest_tag "$tags" "$major")"
      if [ -n "$best" ] && deps_is_newer "${best%% *}" "$comment" && ! held "$group" "$repo" "${best%% *}"; then
        if [ -z "${seen[$key]+x}" ]; then
          updates="$(jq -c --arg n "$repo" --arg f "$comment" --arg t "${best%% *}" --arg sha "${best##* }" --arg old "$ref" --arg file "$file" \
            '. + [{name: $n, from: $f, to: $t, sha: $sha, old_sha: $old, files: [$file]}]' <<<"$updates")"
        else
          updates="$(jq -c --arg n "$repo" --arg f "$comment" --arg file "$file" 'map(if .name == $n and .from == $f then .files += [$file] | .files |= unique else . end)' <<<"$updates")"
        fi
      fi
    elif [[ "$ref" =~ ^v?[0-9]+$ ]]; then
      major="${ref#v}"
    else
      continue
    fi
    latest="$(newest_tag "$tags")"
    if [ -n "$latest" ] && [ "$(deps_major "${latest%% *}")" -gt "$major" ] 2>/dev/null && [ -z "${seen[$key]+x}" ] && ! held "$group" "$repo" "${latest%% *}"; then
      outside "$group" "$repo" "${comment:-$ref}" "${latest%% *}" "v${major}" "$file"
    fi
    seen[$key]=1
  done <<<"$uses"
  finding "$group" "$updates" '[]'
}

# ── Dockerfile base images ───────────────────────────────────────────────────
# the FROM references of a Dockerfile, its ARGs' defaults filled in: "LINE REF" (stages skipped)
from_refs() {
  awk '
    toupper($1) == "ARG" && index($2, "=") { split($2, kv, "="); def[kv[1]] = substr($2, length(kv[1]) + 2); next }
    toupper($1) == "FROM" {
      ref = ""
      for (i = 2; i <= NF; i++) if ($i !~ /^--/) { ref = $i; break }
      while (match(ref, /\$\{?[A-Za-z_][A-Za-z0-9_]*\}?/)) {
        name = substr(ref, RSTART, RLENGTH); gsub(/[${}]/, "", name)
        ref = substr(ref, 1, RSTART - 1) ((name in def) ? def[name] : "?") substr(ref, RSTART + RLENGTH)
      }
      print FNR, ref
    }' "$1" | awk '$2 ~ /\// && $2 !~ /\?/'
}

# ghcr_tags PATH — the tags of a ghcr.io repository (anonymous)
ghcr_tags() {
  local token
  token="$(curl -fsS --max-time 20 "${GHCR}/token?scope=repository:$1:pull" | jq -r .token)" || return 1
  curl -fsS --max-time 30 -H "Authorization: Bearer ${token}" "${GHCR}/v2/$1/tags/list?n=10000" | jq -r '.tags[]?'
}
# ghcr_digest PATH TAG — the digest the tag names (a HEAD request: no layer is read)
ghcr_digest() {
  local token
  token="$(curl -fsS --max-time 20 "${GHCR}/token?scope=repository:$1:pull" | jq -r .token)" || return 1
  curl -fsSI --max-time 20 -H "Authorization: Bearer ${token}" \
    -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
    "${GHCR}/v2/$1/manifests/$2" | tr -d '\r' | sed -n 's/^[Dd]ocker-[Cc]ontent-[Dd]igest: *//p'
}

# line_of IMAGE VERSION — the release line of a base image's version: its major, the minor of a 0.x
# version, and the minor of a toolchain image (DEPS_DOCKER_LINES: golang 1.26 → 1.27 moves Go)
line_of() {
  local v="${2#v}"
  if [[ " ${DEPS_DOCKER_LINES} " == *" $1 "* ]] || [ "${v%%.*}" = 0 ]; then cut -d. -f1-2 <<<"$v"; else echo "${v%%.*}"; fi
}

detect_docker() {
  local group="$1" row file line ref image tag digest path tags prefix ver suffix n best cand bestmaj updates='[]' key
  declare -A tagcache=() seen=()
  for row in "${DEPS_DOCKERFILES[@]}"; do
    file="${row%%|*}"
    [ -f "$file" ] || { unreadable "${group}: ${file} of DEPS_DOCKERFILES is gone"; continue; }
    while read -r line ref; do
      image="${ref%%@*}"; digest=""; [[ "$ref" == *@* ]] && digest="${ref#*@}"
      tag="${image##*:}"; image="${image%:*}"
      [[ "$image" == ghcr.io/* ]] || continue
      path="${image#ghcr.io/}"
      [[ "$tag" =~ ^(v?)([0-9]+(\.[0-9]+)*)(.*)$ ]] || continue
      prefix="${BASH_REMATCH[1]}"; ver="${BASH_REMATCH[2]}"; suffix="${BASH_REMATCH[4]}"
      n="$(awk -F. '{ print NF }' <<<"$ver")"
      if [ -z "${tagcache[$path]+x}" ]; then
        tagcache[$path]="$(ghcr_tags "$path" 2>/dev/null)" || { unreadable "${group}: the tags of ${image}"; tagcache[$path]=""; }
      fi
      tags="${tagcache[$path]}"
      # the tags of the same form: prefix, as many components, the same suffix (-alpine, -slim-bookworm)
      cand="$(grep -E "^${prefix}[0-9]+(\.[0-9]+){$((n - 1))}$(sed 's/[.[\*^$/]/\\&/g' <<<"$suffix")\$" <<<"$tags" | sed -E "s/^${prefix}([0-9.]+).*/\1/" | sort -V || true)"
      best="$(while read -r c; do [ -z "$c" ] || [ "$(line_of "$image" "$c")" != "$(line_of "$image" "$ver")" ] || echo "$c"; done <<<"$cand" | tail -n 1)"
      bestmaj="$(tail -n 1 <<<"$cand")"
      key="${image}:${tag}"
      if [ -n "$best" ] && deps_is_newer "$best" "$ver" && ! held "$group" "$image" "$best"; then
        local newdigest=""
        if [ -n "$digest" ]; then
          newdigest="$(ghcr_digest "$path" "${prefix}${best}${suffix}" 2>/dev/null)" || newdigest=""
          [ -n "$newdigest" ] || { unreadable "${group}: the digest of ${image}:${prefix}${best}${suffix}"; continue; }
        fi
        if [ -z "${seen[$key]+x}" ]; then
          updates="$(jq -c --arg n "$image" --arg f "$tag" --arg t "${prefix}${best}${suffix}" --arg d "$newdigest" --arg od "$digest" --arg file "$file" \
            '. + [{name: $n, from: $f, to: $t, digest: $d, old_digest: $od, files: [$file]}]' <<<"$updates")"
        else
          updates="$(jq -c --arg n "$image" --arg f "$tag" --arg file "$file" 'map(if .name == $n and .from == $f then .files += [$file] | .files |= unique else . end)' <<<"$updates")"
        fi
      fi
      if [ -n "$bestmaj" ] && [ "$(line_of "$image" "$bestmaj")" != "$(line_of "$image" "$ver")" ] && deps_is_newer "$bestmaj" "$ver" \
        && [ -z "${seen[$key]+x}" ] && ! held "$group" "$image" "$bestmaj"; then
        outside "$group" "$image" "$tag" "${prefix}${bestmaj}${suffix}" "${prefix}$(line_of "$image" "$ver")${suffix}" "$file" \
          "$(if [ "$n" -le 2 ]; then echo "${prefix}${bestmaj}${suffix}"; else series "${prefix}${bestmaj}"; fi)"
      fi
      seen[$key]=1
    done < <(from_refs "$file")
  done
  finding "$group" "$updates" '[]'
}

# ── Version pins (DEPS_PINS) ─────────────────────────────────────────────────
# crate_versions NAME — the crate's releases on the crates.io index (its sparse protocol), the
# yanked ones left out
crate_versions() {
  curl -fsS --max-time 30 "${CRATES_INDEX}/$(deps_crate_path "$1")" | jq -r 'select(.yanked | not) | .vers'
}

# pin_versions SOURCE — the stable releases of a pin's upstream (DEPS_PINS' SOURCE), one per line,
# without a leading v, oldest first
pin_versions() {
  local src="$1"
  case "$src" in
    github:*) git ls-remote --tags "${GITHUB_URL}/${src#github:}" | awk '{ sub(/^refs\/tags\//, "", $2); print $2 }' ;;
    npm:*) npm view "${src#npm:}" versions --json | jq -r 'if type == "array" then .[] else . end' ;;
    ghcr:*) ghcr_tags "${src#ghcr:}" ;;
    goproxy:*) curl -fsS --max-time 20 "${GOPROXY_URL}/$(goproxy_escape "${src#goproxy:}")/@v/list" ;;
    crates:*) crate_versions "${src#crates:}" ;;
    go.dev) curl -fsS --max-time 20 "$GO_DL_URL" | jq -r '.[] | select(.stable) | .version | sub("^go"; "")' ;;
    nodejs.org) curl -fsS --max-time 30 "$NODE_DIST_URL" | jq -r '.[] | select(.lts != false) | .version' ;;
    php.net) curl -fsS --max-time 20 "$PHP_RELEASES_URL" | jq -r '.[] | .[] | .version' ;;
    *) echo "unknown source ${src}" >&2; return 1 ;;
  esac | sed -E 's/^v//' | { grep -E '^[0-9]+(\.[0-9]+)*$' || { echo "no stable release" >&2; false; }; } | sort -uV
}

# pin_line LINE VERSION — the release line of a pin's version (DEPS_PINS' LINE): its major (a 0.x
# version: its minor), or its major.minor
pin_line() {
  local v="${2#v}" maj min
  maj="${v%%.*}"
  min="$(cut -d. -f2 <<<"${v}.0")"
  case "$1" in
    minor|toolchain-minor) echo "${maj}.${min}" ;;
    toolchain-major) echo "$maj" ;;
    *) if [ "$maj" = 0 ]; then echo "0.${min}"; else echo "$maj"; fi ;;
  esac
}

# pin_current ROW — "VERSION<TAB>FILE…": the version the places of a row pin, every file that pins
# it; several versions are one line each (the places disagree)
pin_current() {
  local place glob slot var file v
  for place in $(deps_field "$1" 7); do
    IFS='~' read -r glob slot var <<<"$place"
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      while IFS= read -r v; do [ -z "$v" ] || printf '%s\t%s\n' "$v" "$file"; done < <(deps_pin_read "$file" "$slot" "$var" | sort -u)
    done < <(deps_pin_files "$glob")
  done | sort -u | awk -F'\t' '{ files[$1] = files[$1] (files[$1] == "" ? "" : " ") $2 } END { for (v in files) print v "\t" files[v] }'
}

detect_pins() {
  local group="$1" name row source line cur from where prefix n vers best newest label label_place updates='[]'
  declare -A vcache=()
  for name in $(deps_pin_names "$group"); do
    row="$(deps_pin_row "$group" "$name")"
    source="$(deps_field "$row" 3)"
    line="$(deps_field "$row" 4)"
    cur="$(pin_current "$row")"
    if [ -z "$cur" ]; then unreadable "${group}: ${name}: no file pins it ($(deps_field "$row" 7))"; continue; fi
    if [ "$(wc -l <<<"$cur")" -gt 1 ]; then
      unreadable "${group}: ${name}: its places pin different versions ($(tr '\t\n' ':;' <<<"$cur" | sed 's/;$//; s/;/; /g')): one version, moved together"
      continue
    fi
    from="${cur%%$'\t'*}"
    where="${cur#*$'\t'}"
    held_always "$group" "$name" && continue
    prefix=""
    [[ "$from" != v* ]] || prefix=v
    if [ -z "${vcache[$source]+x}" ]; then
      vcache[$source]="$(pin_versions "$source" 2>"$work/pin.err")" || { unreadable "${group}: ${name}: the releases of ${source}: $(tail -n 1 "$work/pin.err")"; vcache[$source]=""; }
    fi
    vers="${vcache[$source]}"
    [ -n "$vers" ] || continue
    newest="$(tail -n 1 <<<"$vers")"
    case "$line" in
      toolchain-*)
        # a newer line of the toolchain the code is written for: never a bump
        if deps_is_newer "$newest" "${from#v}" && [ "$(pin_line "$line" "$newest")" != "$(pin_line "$line" "$from")" ] && ! held "$group" "$name" "$newest"; then
          label="$(pin_line "$line" "$newest").x"
          if [ "$name" = go ]; then
            # the Go toolchain of every module: one finding with the advisories' (go-toolchain)
            outside go-toolchain go "$from" "$label" "go ${from}" "every go.mod's go line, the workflows' go-version (${where}) and the golang base image" "" "a new release line of the Go toolchain"
          else
            label_place="$(deps_field "$row" 7)"
            outside "$group" "$name" "$from" "$newest" "${label_place##*~} ${from}" "$where" "$label" "a new release line of the toolchain the code is written for"
          fi
        fi ;;
      person)
        # RunR builds a patched upstream: every newer release is a person's
        if deps_is_newer "$newest" "${from#v}" && ! held "$group" "$name" "${prefix}${newest}"; then
          outside "$group" "$name" "$from" "${prefix}${newest}" "$from" "$where" "" "RunR builds this upstream with patches of its own, so a person moves it with them"
        fi ;;
      *)
        n="$(awk -F. '{ print NF }' <<<"${from#v}")"
        vers="$(grep -E "^[0-9]+(\.[0-9]+){$((n - 1))}\$" <<<"$vers" || true)"
        best="$(while read -r c; do [ -z "$c" ] || [ "$(pin_line "$line" "$c")" != "$(pin_line "$line" "$from")" ] || echo "$c"; done <<<"$vers" | tail -n 1)"
        newest="$(tail -n 1 <<<"$vers")"
        if [ -n "$best" ] && deps_is_newer "$best" "${from#v}" && ! held "$group" "$name" "${prefix}${best}"; then
          updates="$(jq -c --arg n "$name" --arg f "$from" --arg t "${prefix}${best}" --arg files "$where" \
            '. + [{name: $n, from: $f, to: $t, files: ($files | split(" "))}]' <<<"$updates")"
        fi
        if [ -n "$newest" ] && deps_is_newer "$newest" "${from#v}" && [ "$(pin_line "$line" "$newest")" != "$(pin_line "$line" "$from")" ] \
          && ! held "$group" "$name" "${prefix}${newest}"; then
          label="$(series "${prefix}${newest}")"
          [ "$line" != minor ] || label="${prefix}$(pin_line minor "$newest").x"
          outside "$group" "$name" "$from" "${prefix}${newest}" "${prefix}$(pin_line "$line" "$from")" "$where" "$label"
        fi ;;
    esac
  done
  finding "$group" "$updates" '[]'
}

# ── Python packages and crates (no lockfile: only what is outside the constraint) ──
MANIFESTS_PY="$ROOT/ci/deps/manifests.py"

# no_lock_check GROUP KIND MANIFEST REQUIREMENTS — each "NAME<TAB>CONSTRAINT" of REQUIREMENTS against
# its registry: a release outside the constraint is a finding a person decides
no_lock_check() {
  local group="$1" kind="$2" manifest="$3" name spec vers verdict newest out why
  why="a release the constraint leaves out (an upper bound, an exact pin), or a new major above its floor, which the package was never tested with"
  [ "$kind" = pypi ] || why="a release Cargo's requirement leaves out (a new major of a caret requirement)"
  while IFS=$'\t' read -r name spec; do
    [ -n "$name" ] || continue
    held_always "$group" "$name" && continue
    [ -n "$spec" ] || continue
    if [ "$kind" = pypi ]; then
      vers="$(curl -fsS --max-time 30 "${PYPI_URL}/${name}/json" 2>"$work/reg.err" \
        | jq -r '.releases // {} | to_entries[] | select((.value | length) > 0 and (.value | all(.yanked // false | not))) | .key')" \
        || { unreadable "${group}: PyPI ${name}: $(tail -n 1 "$work/reg.err")"; continue; }
    else
      vers="$(crate_versions "$name" 2>"$work/reg.err")" || { unreadable "${group}: crates.io ${name}: $(tail -n 1 "$work/reg.err")"; continue; }
    fi
    verdict="$(python3 -I "$MANIFESTS_PY" verdict "$kind" "$spec" <<<"$vers" 2>"$work/py.err")" \
      || { unreadable "${group}: ${name} ${spec}: $(tail -n 1 "$work/py.err")"; continue; }
    newest="${verdict%%$'\t'*}"
    out="${verdict##*$'\t'}"
    [ "$out" = 1 ] && [ -n "$newest" ] || continue
    held "$group" "$name" "$newest" && continue
    outside "$group" "$name" "$spec" "$newest" "$spec" "$manifest" "" "$why"
  done <<<"$4"
  # without a lockfile the minor and patch updates need no change
  finding "$group" '[]' '[]'
}

detect_pypi() {
  local group="$1" dir="$2" manifest reqs
  manifest="$dir/pyproject.toml"
  [ -f "$manifest" ] || manifest="$dir/requirements.txt"
  [ -f "$manifest" ] || { unreadable "${group}: no pyproject.toml or requirements.txt in ${dir}"; return 0; }
  reqs="$(python3 -I "$MANIFESTS_PY" requirements "$manifest" 2>"$work/py.err")" || { unreadable "${group}: ${manifest}: $(tail -n 1 "$work/py.err")"; return 0; }
  no_lock_check "$group" pypi "$manifest" "$reqs"
}

detect_cargo() {
  local group="$1" dir="$2" reqs
  [ -f "$dir/Cargo.toml" ] || { unreadable "${group}: no ${dir}/Cargo.toml"; return 0; }
  reqs="$(python3 -I "$MANIFESTS_PY" cargo "$dir/Cargo.toml" 2>"$work/py.err")" || { unreadable "${group}: ${dir}/Cargo.toml: $(tail -n 1 "$work/py.err")"; return 0; }
  no_lock_check "$group" cargo "$dir/Cargo.toml" "$reqs"
}

# ── Every manifest is known ──────────────────────────────────────────────────
check_known() {
  local f dir known=" " row pat
  for row in "${DEPS_PKG[@]}"; do known="${known}$(deps_field "$row" 3) "; done
  for row in "${DEPS_DOCKERFILES[@]}"; do known="${known}${row%%|*} "; done
  while IFS= read -r f; do
    dir="$(dirname "$f")"
    case "$(basename "$f")" in
      Dockerfile*) [[ "$known" == *" ${f} "* ]] && continue ;;
      *) [[ "$known" == *" ${dir} "* ]] && continue ;;
    esac
    for row in "${DEPS_UNTRACKED[@]}"; do
      pat="${row%%|*}"
      # shellcheck disable=SC2254 # the pattern of DEPS_UNTRACKED
      case "$f" in $pat) continue 2 ;; esac
    done
    unreadable "${f}: a manifest ci/deps/packages.sh does not know (a group of DEPS_PKG, DEPS_DOCKERFILES or DEPS_UNTRACKED with its reason)"
  done < <(git ls-files -- '*composer.json' '*package.json' '*go.mod' '*pyproject.toml' '*requirements*.txt' '*Cargo.toml' \
    '*Dockerfile' '*Dockerfile.*' ':!:**/node_modules/**' ':!:**/vendor/**' ':!:ci/check-dockerfiles.sh')
  check_pins_known
}

# untracked_file FILE — FILE is a manifest of DEPS_UNTRACKED (its pins are left with it)
untracked_file() {
  local row pat
  for row in "${DEPS_UNTRACKED[@]}"; do
    pat="${row%%|*}"
    # shellcheck disable=SC2254 # the pattern of DEPS_UNTRACKED
    case "$1" in $pat) return 0 ;; esac
  done
  return 1
}

# pin_candidates — "FILE<TAB>NAME<TAB>VALUE" of every version pin of the tracked Dockerfiles (ARG
# *_VERSION= and *_SHA256=, but an ARG a FROM names: the docker group's) and workflows (*_VERSION:,
# *_SHA256:, *_IMAGE:, *-version: and *_version: with a literal value, not an expression)
pin_candidates() {
  local f
  while IFS= read -r f; do
    untracked_file "$f" && continue
    awk -v f="$f" '
      toupper($1) == "FROM" { from = from " " $0 }
      toupper($1) == "ARG" && $2 ~ /^[A-Z0-9_]*_(VERSION|SHA256)=/ { split($2, kv, "="); args[kv[1]] = substr($2, length(kv[1]) + 2) }
      END { for (a in args) if (index(from, "$" a) == 0 && index(from, "${" a "}") == 0) print f "\t" a "\t" args[a] }' "$f"
  done < <(git ls-files -- '*Dockerfile' '*Dockerfile.*' ':!:**/node_modules/**' ':!:**/vendor/**' ':!:ci/check-dockerfiles.sh')
  while IFS= read -r f; do
    sed -nE "s/^[[:space:]]*(- )?([A-Za-z0-9_-]*(_VERSION|_SHA256|_IMAGE|-version|_version)):[[:space:]]*['\"]?([^'\"[:space:]#]+).*/\\2\\t\\4/p" "$f" \
      | grep -v '[$]' | awk -F'\t' -v f="$f" '{ print f "\t" $1 "\t" $2 }'
  done < <(git ls-files -- '.github/workflows/*.yml' 'actions/*/action.yml')
}

# check_pins_known — every version pin of pin_candidates is a row's place (its NAME, or its
# NAME_SHA256 when the row moves a checksum, or the text an AT place reads after) or a line of
# DEPS_PINS_UNTRACKED with its reason
check_pins_known() {
  local f var value row place glob slot name pat
  while IFS=$'\t' read -r f var value; do
    [ -n "$f" ] || continue
    for row in "${DEPS_PINS[@]}"; do
      for place in $(deps_field "$row" 7); do
        IFS='~' read -r glob slot name <<<"$place"
        # shellcheck disable=SC2053 # the place's glob
        [[ "$f" == $glob ]] || continue
        case "$slot" in
          AT) [[ "$value" == "$name"* ]] && continue 3 ;;
          *) { [ "$var" = "$name" ] || { [ "$var" = "${name%_VERSION}_SHA256" ] && [ "$(deps_field "$row" 6)" != - ]; }; } && continue 3 ;;
        esac
      done
    done
    for row in "${DEPS_PINS_UNTRACKED[@]}"; do
      pat="${row%%|*}"
      # shellcheck disable=SC2053 # the glob of DEPS_PINS_UNTRACKED
      [[ "$f" == ${pat%%~*} ]] && [ "$var" = "${pat#*~}" ] && continue 2
    done
    unreadable "${f}: ${var} (${value}) is a version pin ci/deps/packages.sh does not know (a row of DEPS_PINS, or DEPS_PINS_UNTRACKED with its reason)"
  done < <(pin_candidates)
}

if [ "${1:-}" = --known ]; then
  # only that every tracked manifest and version pin is known (runtime/test/deps_scope_test.sh)
  check_known
  [ "$unread" = 0 ] && echo "Every manifest and pin is known." && exit 0
  exit 1
fi
echo "══════════════════════════════════════════════════════════"
echo "  Package dependency release check (${kinds})"
echo "══════════════════════════════════════════════════════════"
check_known
go_fixed=""
go_from=""
for kind in $kinds; do
  case "$kind" in composer|npm|go|actions|docker|pins|pypi|cargo) ;; *) echo "unknown kind: ${kind}" >&2; exit 2 ;; esac
  for group in $(deps_pkg_names "$kind"); do
    echo ""
    echo "── ${group}"
    dir="$(deps_field "$(deps_pkg_row "$group")" 3)"
    "detect_${kind}" "$group" "$dir"
  done
done
# the Go toolchain an advisory of the standard library needs: one finding for every module
if [ -n "$go_fixed" ]; then
  echo ""
  outside go-toolchain go "$go_from" "$go_fixed" "go ${go_from}" "every go.mod's go line, ci.yml's setup-go and the golang base image" "$go_fixed"
fi
echo ""
if [ "$unread" -gt 0 ]; then
  echo "${unread} source(s) could not be read or are unknown: see above."
  exit 1
fi
echo "Every group checked."
