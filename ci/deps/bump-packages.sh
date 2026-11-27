# shellcheck shell=bash
# shellcheck disable=SC2154 # pkg_row, dep, report, work, run_url, reasons, notes: ci/deps-bump.sh's
# The bump of a package group (ci/deps/packages.sh, DEPS_PKG): sourced by ci/deps-bump.sh, whose
# report, finish, attention, reasons, notes and suite_json it uses. It has no shebang.
#
# The updates come from the finding (--updates=FILE, the issue's marker: [{name, from, to, …}]).
# Each kind moves them with its own tool, mechanically and within the manifest's constraints:
#   composer  composer update NAME… --with-dependencies (composer.lock only)
#   npm       npm update NAME… --package-lock-only (npm install NAME@VERSION… for the ones it
#             leaves where they are, package.json put back); npm audit fix --package-lock-only for
#             an advisory (package-lock.json only)
#   go        go get NAME@VERSION… and go mod tidy (go.mod, go.sum)
#   actions   uses: NAME@<the tag's commit> # <tag>, in every workflow that pins the old one
#   docker    FROM <image>:<tag>[@<digest>] in the Dockerfiles that name the old one
#   pins      the version at every place of the pin (DEPS_PINS), and with it the checksums: the
#             published ones of the old version become the new one's (a checksum not published,
#             or not where the files pin it, sends the bump to a person)
#   pypi, cargo  nothing: without a lockfile only a release outside the constraint is a finding
# A release outside the constraint (its finding's id <group>--<package>, one update with "outside":
# true) moves the constraint itself first, mechanically (bump_outside, below), then the kind's tool.
# The lockfile must come out the same when the bump is made twice (a scratch worktree of HEAD,
# then this checkout), the manifest's constraints must stay as they are (but the one an update
# outside it moves), and a held package must not move; anything else goes to a person. The path
# follows what moved (DEPS_PKG's PATH): a bump that changes nothing a release ships lands on next
# only.

pkg_kind="$(deps_field "$pkg_row" 2)"
pkg_dir="$(deps_field "$pkg_row" 3)"
pkg_scope="$(deps_field "$pkg_row" 4)"
pkg_label="$(deps_field "$pkg_row" 5)"
pkg_path="$(deps_field "$pkg_row" 6)"
pkg_suites="$(deps_field "$pkg_row" 7)"
# the group: the finding's id is the group itself, or <group>--<package> for a release outside the
# constraint (the holds and the pins are the group's)
pkg_group="$(deps_base_group "$dep")"
# what the bump changed, "name old → new" per line; whether it ships; the images whose own version moves
moved=()
shipped=0
own_images=()
extra_suites=()

# pkg_safe — every name and version of the updates is one a command line or a pattern takes as is
pkg_safe() {
  jq -e 'type == "array" and length > 0 and all(.[];
      (.name | type == "string" and test("^[A-Za-z0-9@][A-Za-z0-9@._/-]*$"))
      and (.to | type == "string" and test("^[A-Za-z0-9][A-Za-z0-9._+-]*$"))
      and ((.outside // false) | type == "boolean")
      and (if .outside == true then
             ((.from // "") | type == "string" and test("^[A-Za-z0-9._+^~<>=!,*| -]*$"))
             and ((.constraint // "") | type == "string" and test("^[A-Za-z0-9._+^~<>=!,*|@/ -]*$"))
             and all((.line // ""), (.why // ""), (.where // ""); type == "string" and (test("[[:cntrl:]`$]") | not))
           else ((.from // "") | test("^[A-Za-z0-9._+-]*$")) end)
      and ((.sha // "") | test("^([0-9a-f]{40})?$")) and ((.old_sha // "") | test("^([0-9a-f]{40})?$"))
      and ((.digest // "") | test("^(sha256:[0-9a-f]{64})?$")) and ((.old_digest // "") | test("^(sha256:[0-9a-f]{64})?$"))
      and ((.files // []) | all(.[]; test("^[A-Za-z0-9._/-]+$") and (test("[.][.]") | not))))' "$updates_file" >/dev/null
}

# ── composer ──
# composer_run TREE — the update in TREE/<dir> (composer.lock only)
composer_run() {
  local -a pkgs=()
  mapfile -t pkgs < <(jq -r '.[].name' "$updates_file")
  (cd "$1/$pkg_dir" && composer update "${pkgs[@]}" --with-dependencies --no-install --no-scripts --no-interaction \
    --no-progress --ignore-platform-req='ext-*') > "$work/composer-$(basename "$1").log" 2>&1
}
# composer_versions LOCK SECTION — "name version" of a section of composer.lock
composer_versions() { jq -r --arg s "$2" '.[$s] // [] | .[] | "\(.name) \(.version)"' "$1" | sort; }

bump_composer() {
  local lock="$pkg_dir/composer.lock" again="$work/again"
  cp "$lock" "$work/lock.old"
  git -C "$ROOT" worktree add -q --detach "$again" HEAD
  outside_hook "$again"
  [ ! -d "$ROOT/$pkg_dir/vendor" ] || ln -s "$ROOT/$pkg_dir/vendor" "$again/$pkg_dir/vendor"
  composer_run "$again" || { reasons+=("\`composer update\` failed: $(tail -n 3 "$work/composer-again.log" | tr '\n' ' ')"); pkg_attention; }
  composer_run "$ROOT" || { reasons+=("\`composer update\` failed: $(tail -n 3 "$work/composer-$(basename "$ROOT").log" | tr '\n' ' ')"); pkg_attention; }
  cmp -s "$again/$lock" "$ROOT/$lock" || reasons+=("composer.lock does not come out the same when the update is made twice: the resolution is not reproducible")
  pkg_cleanup
  local section changed
  for section in packages packages-dev; do
    changed="$(join -a1 -a2 -e none -o 0,1.2,2.2 <(composer_versions "$work/lock.old" "$section") <(composer_versions "$lock" "$section") \
      | awk '$2 != $3 { print $1 " " $2 " → " $3 }')"
    [ -n "$changed" ] || continue
    mapfile -t -O "${#moved[@]}" moved <<<"$changed"
    [ "$section" = packages-dev ] || shipped=1
  done
}

# ── npm ──
# npm_left LOCK — the finding's updates (not an advisory's "fixed", not one outside the
# constraint) that LOCK still has on their old version: "name@to" per line
npm_left() {
  jq -r --slurpfile lock "$1" '.[] | select(.to != "fixed" and (.outside // false) == false and (.from // "") != "")
    | select(($lock[0].packages["node_modules/" + .name].version // "") == .from) | "\(.name)@\(.to)"' "$updates_file"
}

# npm_stuck TREE — in TREE/<dir> (the current directory), after `npm update`: the updates it left
# on their old version, installed at the finding's version. npm update says "up to date" for
# packages whose peers pin each other's own release (vitest and @vitest/coverage-v8): it moves
# neither, while `npm install NAME@VERSION…` moves them together. That writes ^VERSION into
# package.json, which is put back as it was; a second `npm install --package-lock-only` puts the
# lockfile's ranges back with it (the versions stay: they are within the same ranges).
npm_stuck() {
  local keep
  local -a left=()
  mapfile -t left < <(npm_left package-lock.json)
  [ "${#left[@]}" -gt 0 ] || return 0
  echo "npm update left ${left[*]} where they were: npm install"
  keep="$work/package-$(basename "$1").json"
  cp package.json "$keep"
  npm install "${left[@]}" --package-lock-only --ignore-scripts --no-audit --no-fund || return 1
  cp "$keep" package.json
  npm install --package-lock-only --ignore-scripts --no-audit --no-fund
}

npm_run() {
  local -a pkgs=()
  mapfile -t pkgs < <(jq -r '.[] | select(.to != "fixed") | .name' "$updates_file")
  (
    cd "$1/$pkg_dir" || exit 1
    # a failed update fails the bump (its log says why)
    if [ "${outside_mode:-0}" = 1 ]; then
      # a range outside the old one (bump_outside moved it): the lockfile resolves it again
      npm install --package-lock-only --ignore-scripts --no-audit --no-fund || exit 1
    elif [ "${#pkgs[@]}" -gt 0 ]; then
      npm update "${pkgs[@]}" --package-lock-only --ignore-scripts --no-audit --no-fund || exit 1
      npm_stuck "$1" || exit 1
    fi
    # an advisory: the fixes within the ranges of the tree (never --force)
    if [ "$(jq -r '.urgent // false' "$updates_meta" 2>/dev/null || echo false)" = true ] || jq -e 'any(.[]; .to == "fixed")' "$updates_file" >/dev/null; then
      npm audit fix --package-lock-only --ignore-scripts --no-fund || true
    fi
  ) > "$work/npm-$(basename "$1").log" 2>&1
}

bump_npm() {
  local lock="$pkg_dir/package-lock.json" again="$work/again" changed roots=""
  [ -f "$lock" ] || { reasons+=("${pkg_dir} keeps no package-lock.json: its ranges take the minor and patch updates without a change (a library); only a new major needs a person"); pkg_attention; }
  # npm 10's lockfile-only update fails on the console's tree ("Cannot read properties of null
  # (reading 'edgesOut')", vite): npm 11, as the Workflow SDK's CI job has it
  local npm_major
  npm_major="$(npm --version 2>/dev/null | cut -d. -f1)"
  [ "${npm_major:-0}" -ge 11 ] 2>/dev/null || { reasons+=("npm ${npm_major:-none} cannot make the bump: npm 11 or newer (npm install -g npm@11); npm 10 fails on the console's tree"); pkg_attention; }
  cp "$lock" "$work/lock.old"
  git -C "$ROOT" worktree add -q --detach "$again" HEAD
  outside_hook "$again"
  npm_run "$again" || { reasons+=("\`npm update\` failed: $(tail -n 3 "$work/npm-again.log" | tr '\n' ' ')"); pkg_attention; }
  npm_run "$ROOT" || { reasons+=("\`npm update\` failed: $(tail -n 3 "$work/npm-$(basename "$ROOT").log" | tr '\n' ' ')"); pkg_attention; }
  cmp -s "$again/$lock" "$ROOT/$lock" || reasons+=("package-lock.json does not come out the same when the update is made twice: the resolution is not reproducible")
  pkg_cleanup
  # every update of the finding moved: one npm leaves where it was would ship as moved in the
  # commit, the changelog and the summary, and the check would find it again
  local left
  left="$(npm_left "$lock" | sed 's/@[^@]*$//' | paste -sd ' ' - | sed 's/ /, /g')"
  [ -z "$left" ] || reasons+=("npm leaves ${left} on the old version (\`npm update\`, then \`npm install <name>@<version>\`): another package holds it back, which the code or a person changes")
  changed="$(jq -r --slurpfile old "$work/lock.old" '
      ($old[0].packages // {}) as $o | (.packages // {}) as $n
      | ([$o, $n] | map(keys) | add | unique)[] | select(. != "" and startswith("node_modules/"))
      | select(($o[.].version // "none") != ($n[.].version // "none"))
      | "\(sub("^(.*/)?node_modules/"; "")) \($o[.].version // "none") → \($n[.].version // "none")"' "$lock" | sort -u)"
  [ -z "$changed" ] || mapfile -t moved <<<"$changed"
  # Playwright (the e2e's runner and the Chromium CI installs for its version, DEPS_NPM_E2E_ROOTS):
  # ui runs none of it, so a bump that moves it runs ui-stack too, also when it lands on next
  local m
  if [ "$pkg_dir" = vue ]; then
    for m in ${moved[@]+"${moved[@]}"}; do
      if [[ " $DEPS_NPM_E2E_ROOTS " == *" ${m%% *} "* ]]; then extra_suites+=(ui-stack); break; fi
    done
  fi
  # what the bundle takes: the console's dependencies and its build (DEPS_NPM_BUILD_ROOTS)
  [ "$pkg_dir" = vue ] && roots="$DEPS_NPM_BUILD_ROOTS"
  # shellcheck disable=SC2086 # the roots, one per word
  if [ "$pkg_path" = auto ] && [ -n "$(node "$ROOT/ci/deps/npm-shipped.mjs" "$work/lock.old" "$lock" $roots)" ]; then shipped=1; fi
}

# ── go ──
go_run() {
  local -a args=()
  mapfile -t args < <(jq -r '.[] | "\(.name)@\(.to)"' "$updates_file")
  (cd "$1/$pkg_dir" && GOFLAGS=-mod=mod go get "${args[@]}" && GOFLAGS=-mod=mod go mod tidy) > "$work/go-$(basename "$1").log" 2>&1
}
go_requires() { (cd "$1" && go mod edit -json) | jq -r '.Require // [] | .[] | "\(.Path) \(.Version)"' | sort; }

bump_go() {
  local mod="$pkg_dir/go.mod" again="$work/again" changed goline
  goline="$(grep -E '^(go|toolchain) ' "$mod" | sort)"
  go_requires "$pkg_dir" > "$work/req.old"
  git -C "$ROOT" worktree add -q --detach "$again" HEAD
  outside_hook "$again"
  go_run "$again" || { reasons+=("\`go get\` failed: $(tail -n 3 "$work/go-again.log" | tr '\n' ' ')"); pkg_attention; }
  go_run "$ROOT" || { reasons+=("\`go get\` failed: $(tail -n 3 "$work/go-$(basename "$ROOT").log" | tr '\n' ' ')"); pkg_attention; }
  { cmp -s "$again/$mod" "$ROOT/$mod" && cmp -s "$again/$pkg_dir/go.sum" "$ROOT/$pkg_dir/go.sum"; } \
    || reasons+=("go.mod and go.sum do not come out the same when the update is made twice")
  pkg_cleanup
  [ "$(grep -E '^(go|toolchain) ' "$mod" | sort)" = "$goline" ] \
    || reasons+=("the update needs another Go (${goline//$'\n'/, } → $(grep -E '^(go|toolchain) ' "$mod" | sort | paste -sd, -)): the toolchain of CI, the golang base image and every module move together, by a person")
  changed="$(join -a1 -a2 -e none -o 0,1.2,2.2 "$work/req.old" <(go_requires "$pkg_dir") | awk '$2 != $3 { print $1 " " $2 " → " $3 }')"
  [ -z "$changed" ] || mapfile -t moved <<<"$changed"
  [ "$pkg_path" != release ] || shipped=1
  # the image the module builds, when it has a version of its own
  local row
  for row in "${DEPS_DOCKERFILES[@]}"; do
    if [ "${row%%|*}" = "$pkg_dir/Dockerfile" ] && [ "$(deps_field "$row" 3)" = own ]; then own_images+=("$(deps_field "$row" 2)"); fi
  done
}

# ── literal replacement of a pin ──
# replace_pin FILE OLD NEW — every OLD of FILE (a fixed string) becomes NEW; prints the count
replace_pin() {
  OLD="$2" NEW="$3" perl -i -ne 'BEGIN { $n = 0 } $n += s/\Q$ENV{OLD}\E/$ENV{NEW}/g; print; END { print STDERR "$n\n" }' "$1" 2>&1 >/dev/null | tail -n 1
}

# ── actions ──
bump_actions() {
  local i n name from to sha old file count total other=0 gh=0
  n="$(jq length "$updates_file")"
  for ((i = 0; i < n; i++)); do
    name="$(jq -r ".[$i].name" "$updates_file")"; from="$(jq -r ".[$i].from" "$updates_file")"; to="$(jq -r ".[$i].to" "$updates_file")"
    sha="$(jq -r ".[$i].sha" "$updates_file")"; old="$(jq -r ".[$i].old_sha" "$updates_file")"
    total=0
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      count="$(replace_pin "$file" "${name}@${old} # ${from}" "${name}@${sha} # ${to}")"
      total=$((total + count))
      echo "edit ${file}: ${name} ${from} → ${to}" >> "$report/edits.log"
      case "$file" in .github/*) gh=1 ;; *) other=1 ;; esac
    done < <(git -C "$ROOT" grep -l -F -e "${name}@${old} # ${from}" -- '.github/workflows/*.yml' 'actions/*/action.yml' 2>/dev/null || true)
    if [ "$total" = 0 ]; then
      # the new pin is there already (a release a person made has it): nothing to move, no reason
      if git -C "$ROOT" grep -q -F -e "${name}@${sha} # ${to}" -- '.github/workflows/*.yml' 'actions/*/action.yml' 2>/dev/null; then
        echo "kept ${name} ${to}: pinned already" >> "$report/edits.log"
      else
        reasons+=("no workflow pins ${name}@${old} # ${from} any more")
      fi
      continue
    fi
    moved+=("${name} ${from} → ${to}")
  done
  # RunR's own composite actions ship with a release; the workflows do not (and a release never
  # changes .github/)
  if [ "$other" = 1 ] && [ "$gh" = 1 ]; then reasons+=("the pins move in .github/ and in actions/ at once: the release takes actions/ and never .github/; a person splits them"); fi
  [ "$other" = 0 ] || shipped=1
}

# ── docker ──
bump_docker() {
  local i n name from to digest old file row version count total files already
  n="$(jq length "$updates_file")"
  for ((i = 0; i < n; i++)); do
    name="$(jq -r ".[$i].name" "$updates_file")"; from="$(jq -r ".[$i].from" "$updates_file")"; to="$(jq -r ".[$i].to" "$updates_file")"
    digest="$(jq -r ".[$i].digest // \"\"" "$updates_file")"; old="$(jq -r ".[$i].old_digest // \"\"" "$updates_file")"
    total=0
    already=0
    files="$(jq -r ".[$i].files[]" "$updates_file")"
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      row="$(deps_dockerfile_row "$file" || true)"
      [ -n "$row" ] || { reasons+=("${file} is no Dockerfile of DEPS_DOCKERFILES"); continue; }
      if [ -n "$old" ]; then
        count="$(replace_pin "$file" "${name}:${from}@${old}" "${name}:${to}@${digest}")"
      else
        count="$(replace_pin "$file" "${name}:${from}" "${name}:${to}")"
      fi
      # a tag of an ARG (FROM ghcr.io/aquasecurity/trivy:${TRIVY_VERSION}): the ARG's default
      if [ "$count" = 0 ]; then
        local var
        # shellcheck disable=SC2013 # ARG names, one per word
        for var in $(grep -oE "${name//./\\.}:\\\$\\{?[A-Za-z_][A-Za-z0-9_]*\\}?" "$file" | sed -E 's/.*:\$\{?([A-Za-z0-9_]+)\}?$/\1/' | sort -u); do
          count=$((count + $(replace_pin "$file" "ARG ${var}=${from}" "ARG ${var}=${to}")))
          # the ARG's default is the new tag already
          ! grep -qxF "ARG ${var}=${to}" "$file" || already=1
        done
      fi
      # the new tag is there already (a release a person made has it): nothing to move here
      if [ "$count" = 0 ]; then
        if [ -n "$digest" ]; then
          ! grep -qF "${name}:${to}@${digest}" "$file" || already=1
        else
          ! grep -qE "$(sed 's/[][\.*^$+?(){}|/]/\\&/g' <<<"${name}:${to}")([@[:space:]]|\$)" "$file" || already=1
        fi
      fi
      if [ "$count" = 0 ] && [ "$already" = 1 ]; then echo "kept ${file}: ${name} ${to} already" >> "$report/edits.log"; fi
      [ "$count" -gt 0 ] || continue
      total=$((total + count))
      echo "edit ${file}: ${name} ${from} → ${to}" >> "$report/edits.log"
      extra_suites+=("image:${file}")
      for version in $(deps_field "$row" 4); do extra_suites+=("$version"); done
      case "$(deps_field "$row" 3)" in
        release) shipped=1 ;;
        own) shipped=1; own_images+=("$(deps_field "$row" 2)") ;;
      esac
    done <<<"$files"
    [ "$total" -gt 0 ] || { [ "$already" = 1 ] || reasons+=("no Dockerfile names ${name}:${from} any more"); continue; }
    moved+=("${name} ${from} → ${to}${digest:+ (${digest:0:19}…)}")
  done
}

# ── pins ──
# shellcheck source=ci/deps/fetch.sh
source "$ROOT/ci/deps/fetch.sh"

# pin_expand TEMPLATE VERSION ARCH — {v} the version as pinned, {V} without its v, {arch} ARCH
pin_expand() {
  local t="$1"
  t="${t//\{v\}/$2}"
  t="${t//\{V\}/${2#v}}"
  echo "${t//\{arch\}/$3}"
}

# pin_sum_of FILE NAME — the sha256 of NAME's line in a checksums file (sha256sum's format); the
# first field of a file of one hash when NAME is empty
pin_sum_of() {
  if [ -n "$2" ]; then
    awk -v n="$2" '{ f = $2; sub(/^\*/, "", f) } f == n { print $1; exit }' "$1"
  else
    awk 'NF { print $1; exit }' "$1"
  fi | tr 'A-F' 'a-f' | grep -E '^[0-9a-f]{64}$'
}

# pin_npm_digests PACKAGE VERSION PATH — the sha256 (hex) and the sha384 integrity (base64) of a
# file of an npm package, from the tarball the registry's sha512 integrity checks
pin_npm_digests() {
  local pkg="$1" v="$2" path="$3" tarball integrity
  deps_fetch "${DEPS_NPM_REGISTRY:-https://registry.npmjs.org}/${pkg}/${v}" "$work/npm-doc.json" 2>"$work/pin.err" \
    || { pin_err="the npm registry has no ${pkg}@${v} ($(tail -n 1 "$work/pin.err"))"; return 1; }
  tarball="$(jq -r '.dist.tarball // empty' "$work/npm-doc.json")"
  integrity="$(jq -r '.dist.integrity // empty' "$work/npm-doc.json")"
  [[ "$integrity" == sha512-* ]] && [[ "$tarball" == https://* ]] || { pin_err="the npm registry publishes no sha512 integrity of ${pkg}@${v}"; return 1; }
  deps_fetch "$tarball" "$work/npm.tgz" 2>"$work/pin.err" || { pin_err="$(tail -n 1 "$work/pin.err")"; return 1; }
  [ "sha512-$(openssl dgst -sha512 -binary "$work/npm.tgz" | base64 -w0)" = "$integrity" ] \
    || { pin_err="${tarball} is not the tarball the registry's integrity names"; return 1; }
  { tar -xzOf "$work/npm.tgz" "package/${path}" > "$work/npm.file" 2>/dev/null && [ -s "$work/npm.file" ]; } \
    || { pin_err="${pkg}@${v} has no ${path}"; return 1; }
  sha256sum "$work/npm.file" | cut -d' ' -f1
  echo "sha384-$(openssl dgst -sha384 -binary "$work/npm.file" | base64 -w0)"
}

# pin_digests ROW VERSION — the published checksums of VERSION that the files pin, one per line in
# a fixed order (per arch of the row; the npm file's sha256 and sha384); nothing for a row without
# one, nor for build (it only checks the release publishes its checksums file for every arch).
# Status 1 and pin_err when one is not published
pin_digests() {
  local row="$1" v="$2" spec kind archs tmpl url name arch src d
  spec="$(deps_field "$row" 6)"
  [ "$spec" != - ] || return 0
  kind="${spec%%:*}"
  spec="${spec#*:}"
  archs="${spec%%:*}"
  tmpl="${spec#*:}"
  src="$(deps_field "$row" 3)"
  case "$kind" in
    sum|build)
      for arch in ${archs//,/ }; do
        url="$(pin_expand "${tmpl%%#*}" "$v" "$arch")"
        name=""
        [[ "$tmpl" != *'#'* ]] || name="$(pin_expand "${tmpl#*#}" "$v" "$arch")"
        deps_fetch "$url" "$work/pin.sums" 2>"$work/pin.err" || { pin_err="no published checksum: $(tail -n 1 "$work/pin.err")"; return 1; }
        d="$(pin_sum_of "$work/pin.sums" "$name")" || { pin_err="${url} has no sha256 of ${name:-its file}"; return 1; }
        [ "$kind" = build ] || echo "$d"
      done ;;
    digest)
      url="$(pin_expand "$tmpl" "$v" "")"
      d="$(deps_release_digest "$url")" || { pin_err="GitHub publishes no sha256 of ${url}"; return 1; }
      echo "$d" ;;
    npm) pin_npm_digests "${src#npm:}" "${v#v}" "$tmpl" || return 1 ;;
    crate)
      deps_fetch "${DEPS_CRATES_INDEX:-https://index.crates.io}/$(deps_crate_path "${src#crates:}")" "$work/crate.idx" 2>"$work/pin.err" \
        || { pin_err="the crates.io index: $(tail -n 1 "$work/pin.err")"; return 1; }
      d="$(jq -r --arg v "${v#v}" 'select(.vers == $v) | .cksum' "$work/crate.idx" | grep -E '^[0-9a-f]{64}$' | head -n 1)" \
        || { pin_err="the crates.io index has no checksum of ${src#crates:} ${v#v}"; return 1; }
      echo "$d" ;;
    *) pin_err="unknown checksum kind ${kind} (DEPS_PINS)"; return 1 ;;
  esac
}

bump_pins() {
  local i n name from to row line path place glob slot var file cur count c total seen j drow s gh
  local -a files=() olds=() news=()
  n="$(jq length "$updates_file")"
  for ((i = 0; i < n; i++)); do
    name="$(jq -r ".[$i].name" "$updates_file")"; from="$(jq -r ".[$i].from" "$updates_file")"; to="$(jq -r ".[$i].to" "$updates_file")"
    row="$(deps_pin_row "$pkg_group" "$name" || true)"
    [ -n "$row" ] || { reasons+=("${name} is no pin of ${pkg_group} (ci/deps/packages.sh, DEPS_PINS)"); continue; }
    line="$(deps_field "$row" 4)"
    path="$(deps_field "$row" 5)"
    case "$line" in person|toolchain-*) reasons+=("${name}: every newer release of it is a person's (DEPS_PINS: ${line})"); continue ;; esac
    # the version at every place that pins it: all of them the old one, or the new one already
    files=()
    total=0
    seen=0
    for place in $(deps_field "$row" 7); do
      IFS='~' read -r glob slot var <<<"$place"
      while IFS= read -r file; do
        [ -n "$file" ] || continue
        cur="$(deps_pin_read "$file" "$slot" "$var" | sort -u | paste -sd ' ' -)"
        [ -n "$cur" ] || continue
        seen=1
        [ "$cur" != "$to" ] || continue
        if [ "$cur" != "$from" ]; then reasons+=("${file} pins ${name} ${cur}, not ${from}: the places of a pin move together"); continue; fi
        count="$(deps_pin_write "$file" "$slot" "$var" "$from" "$to")"
        [ "$count" -gt 0 ] || { reasons+=("${file}: ${var} ${from} was not replaced"); continue; }
        total=$((total + count))
        echo "edit ${file}: ${name} ${from} → ${to}" >> "$report/edits.log"
        [[ " ${files[*]} " == *" ${file} "* ]] || files+=("$file")
      done < <(deps_pin_files "$glob")
    done
    [ "$seen" = 1 ] || { reasons+=("no file pins ${name} any more ($(deps_field "$row" 7))"); continue; }
    [ "$total" -gt 0 ] || continue
    # the checksums move with the pin: the published ones of the old version become the new one's
    pin_err=""
    if ! pin_digests "$row" "$to" > "$work/pin.new"; then
      reasons+=("${name} ${to}: ${pin_err}. The checksum moves with the pin: a person finds the published one (or why there is none)")
      continue
    fi
    # (build: the image's build reads the new release's checksums file, no file pins one)
    : > "$work/pin.old"
    if [[ "$(deps_field "$row" 6)" != build:* ]] && ! pin_digests "$row" "$from" > "$work/pin.old"; then
      reasons+=("${name} ${from} (the pinned one): ${pin_err}. The bump replaces the published checksum of the old version, so a person moves this one")
      continue
    fi
    mapfile -t olds < "$work/pin.old"
    mapfile -t news < "$work/pin.new"
    for ((j = 0; j < ${#olds[@]}; j++)); do
      count=0
      for file in "${files[@]}"; do
        c="$(replace_pin "$file" "${olds[$j]}" "${news[$j]}")"
        count=$((count + c))
        [ "$c" = 0 ] || echo "edit ${file}: ${name} checksum ${olds[$j]:0:16}… → ${news[$j]:0:16}…" >> "$report/edits.log"
      done
      [ "$count" -gt 0 ] || reasons+=("no file of ${name} (${files[*]}) pins the published checksum of ${from} (${olds[$j]:0:23}…): a person checks where its checksum comes from")
    done
    # every place reads the new version now
    for place in $(deps_field "$row" 7); do
      IFS='~' read -r glob slot var <<<"$place"
      while IFS= read -r file; do
        cur="$(deps_pin_read "$file" "$slot" "$var" | sort -u | paste -sd ' ' -)"
        [ -z "$cur" ] || [ "$cur" = "$to" ] || reasons+=("after the bump ${file} pins ${name} ${cur}, not ${to}")
      done < <(deps_pin_files "$glob")
    done
    # what ships: the row's path; the image builds and the suites of the Dockerfiles it moved
    gh=0
    case "$path" in release|own) shipped=1 ;; esac
    for file in "${files[@]}"; do
      case "$file" in .github/*) gh=1 ;; esac
      drow="$(deps_dockerfile_row "$file" || true)"
      [ -n "$drow" ] || continue
      extra_suites+=("image:${file}")
      for s in $(deps_field "$drow" 4); do extra_suites+=("$s"); done
      if [ "$path" = own ] && [ "$(deps_field "$drow" 3)" = own ] && [[ " ${own_images[*]:-} " != *" $(deps_field "$drow" 2) "* ]]; then
        own_images+=("$(deps_field "$drow" 2)")
      fi
    done
    for s in $(deps_field "$row" 8); do extra_suites+=("$s"); done
    [ "$gh" = 0 ] || [ "$path" = next ] || reasons+=("${name} moves in .github/ and ships in a release (${path}): a release never changes .github/, the table splits them (pins, pins-ci)")
    [ "$path" = next ] || [ "$pkg_path" != next ] || reasons+=("${name} ships (${path}) and ${dep} lands on next only: its row belongs to the pins group")
    moved+=("${name} ${from} → ${to}")
  done
}

# bump_no_lock — a group without a lockfile has nothing to move (pypi, cargo)
bump_no_lock() {
  reasons+=("${pkg_dir} keeps no lockfile: its constraints take the minor and patch updates without a change (a library, an example); only a release outside them is a finding, which a person moves")
  pkg_attention
}

# own_version_bump IMAGE — the image's own version a patch up, wherever it is named as the current
# one, so release-images.yml builds the new tag (runtime/ci.sh release-images lists it)
own_version_bump() {
  local image="$1" old new var file count total=0
  old="$(bash "$ROOT/runtime/ci.sh" release-images 2>/dev/null | sed -n "s#^ghcr.io/brf-tech/runr/${image}:##p" | head -n 1)"
  [ -n "$old" ] || { reasons+=("runtime/ci.sh release-images does not list ${image}: its version cannot be moved"); return 0; }
  new="$(deps_bump_patch "$old")" || { reasons+=("${image}'s version ${old} has no patch to move"); return 0; }
  var="$(tr 'a-z-' 'A-Z_' <<<"$image")_VERSION"
  while IFS= read -r file; do
    [ -n "$file" ] || continue
    # a tag of this image in its namespace (/image:old, not runr-image:old), and the same version
    # quoted in the file that names the tag (WasmShim's version => v0.26.0-runr.1); not a line of
    # the past, nor one that maps the legacy name to it (RegistryMoveTest: runr-image:old => …)
    count="$(IMG="$image" OLD="$old" NEW="$new" HIST="$DEPS_HISTORY_RE" perl -i -ne 'BEGIN { $n = 0 }
      unless (/$ENV{HIST}/i || /-\Q$ENV{IMG}:$ENV{OLD}\E/) { $n +=s{(?<=/)\Q$ENV{IMG}:$ENV{OLD}\E(?![0-9A-Za-z-]|\.[0-9A-Za-z])}{$ENV{IMG}:$ENV{NEW}}g;
        $n += s{(?<=[\x27"])\Q$ENV{OLD}\E(?=[\x27"])}{$ENV{NEW}}g }
      print; END { print STDERR $n, "\n" }' "$file" 2>&1 >/dev/null | tail -n 1)"
    if [ "$count" -gt 0 ]; then total=$((total + count)); echo "edit ${file}: ${image} ${old} → ${new}" >> "$report/edits.log"; fi
  done < <(git -C "$ROOT" grep -l -F -e "/${image}:${old}" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null || true)
  # the image's Dockerfile and the build's default
  for file in $(git -C "$ROOT" ls-files -- "runtime/*/Dockerfile" "runtime/lib/*.sh"); do
    count=$(( $(replace_pin "$file" "${var}:-${old}}" "${var}:-${new}}") ))
    if grep -qF "/${image}:" "$file" 2>/dev/null || [ "$file" = "runtime/${image}/Dockerfile" ] \
      || { [ "$image" = containerd-shim-spin ] && [ "$file" = runtime/wasm-shim-spin/Dockerfile ]; }; then
      count=$((count + $(replace_pin "$file" "ARG VERSION=${old}" "ARG VERSION=${new}")))
    fi
    if [ "$count" -gt 0 ]; then total=$((total + count)); echo "edit ${file}: ${image} ${old} → ${new}" >> "$report/edits.log"; fi
  done
  # a version only this image has (the Spin shim's v0.27.2-runr.1, RunR's build of an upstream):
  # every whole mention of it that is not a line of the past, bare ones too (docs/wasm.md's
  # "v0.27.2-runr.1 — RunR's build", a test's VERSION file "(v0.27.2-runr.1)", the Dockerfile's
  # "versioned v0.27.2-runr.1"); a plain version (gateway-auth's 1.1.1) is too common for that
  if [[ "$old" =~ -runr\.[0-9]+$ ]]; then
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      count="$(IMG="$image" OLD="$old" NEW="$new" HIST="$DEPS_HISTORY_RE" perl -i -ne 'BEGIN { $n = 0 }
        unless (/$ENV{HIST}/i || /-\Q$ENV{IMG}:$ENV{OLD}\E/) { $n += s{(?<![0-9A-Za-z.+-])\Q$ENV{OLD}\E(?![0-9A-Za-z-]|\.[0-9A-Za-z])}{$ENV{NEW}}g }
        print; END { print STDERR $n, "\n" }' "$file" 2>&1 >/dev/null | tail -n 1)"
      if [ "$count" -gt 0 ]; then total=$((total + count)); echo "edit ${file}: ${image} ${old} → ${new}" >> "$report/edits.log"; fi
    done < <(git -C "$ROOT" grep -l -F -e "$old" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null || true)
  fi
  # the binary's own version where its source names it (image-upload's `var version = "1.1.1"`, which
  # a build without the Dockerfile's -ldflags reports; runtime/test/release_images_test.sh holds it
  # equal to the build's default)
  for file in $(git -C "$ROOT" ls-files -- "runtime/${image}/*.go"); do
    count=$(( $(replace_pin "$file" "var version = \"${old}\"" "var version = \"${new}\"") ))
    if [ "$count" -gt 0 ]; then total=$((total + count)); echo "edit ${file}: ${image} ${old} → ${new}" >> "$report/edits.log"; fi
  done
  if ! bash "$ROOT/runtime/ci.sh" release-images 2>/dev/null | grep -qxF "ghcr.io/brf-tech/runr/${image}:${new}"; then
    reasons+=("after moving ${image} ${old} → ${new}, runtime/ci.sh release-images does not list ghcr.io/brf-tech/runr/${image}:${new}")
  fi
  local left
  left="$(git -C "$ROOT" grep -n -F -e "/${image}:${old}" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null | grep -viE "$DEPS_HISTORY_RE" \
    | grep -vF -e "-${image}:${old}" | head -n 5 || true)"
  [ -z "$left" ] || reasons+=("lines still name ${image}:${old} as the current one: ${left//$'\n'/; }")
  if [[ "$old" =~ -runr\.[0-9]+$ ]]; then
    left="$(git -C "$ROOT" grep -n -F -e "$old" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null | grep -viE "$DEPS_HISTORY_RE" \
      | grep -vF -e "-${image}:${old}" | head -n 5 || true)"
    [ -z "$left" ] || reasons+=("lines still name ${image}'s ${old} as the current one: ${left//$'\n'/; }")
  fi
  outside_lines="$(git -C "$ROOT" grep -n -F -e "/${image}:${old}" -- 'vue/**' 'landing/**' 2>/dev/null | grep -viE "$DEPS_HISTORY_RE" || true)"
  [ -z "$outside_lines" ] || reasons+=("the console or the landing page names ${image}:${old}, which the bot does not edit: ${outside_lines//$'\n'/; }")
  moved+=("${image} image ${old} → ${new} (its own version: release-images.yml builds it)")
  notes+=("${image} ${old} → ${new}: ${total} line(s)")
}

# ── outside the constraint (bump_outside) ──
# A release outside the manifest's constraint (ci/check-package-releases.sh outside(): its id
# <group>--<package>, one update with "outside": true) moves the constraint itself, mechanically,
# and then goes through the kind's own bump as any update: composer and npm a caret, tilde or exact
# version to the new release's, Go a new major path (go.mod and the imports), an Actions pin to the
# new tag's commit (a floating major to the new major), a Dockerfile base to the new tag (and its
# digest), a pin of a major line to the new version with its checksums, a Python specifier (==,
# ~=, an upper bound, a floor alone) and a Cargo requirement to the new release. Anything else, a
# Go toolchain line, a new kubectl minor of the API image's set, a toolchain the code is written
# for or a patched upstream needs the code or a person's decision: the bump refuses it with the
# reason (the issue gets `next update required` and the bot tries it again every week).
outside_mode=0
o_name=""
o_from=""
o_to=""
o_constraint=""
o_new=""
o_where=""
o_kind=""

# outside_refuse REASON — the bump cannot move it: the reason, nothing left changed, status 2
outside_refuse() {
  reasons+=("$1")
  pkg_attention
}

# outside_error MESSAGE — the bump could not be checked (a registry did not answer): status 1, the
# next run tries again
outside_error() {
  echo "${dep}: $1" >&2
  pkg_cleanup
  deps_revert_all
  exit 1
}

# outside_num TEXT — the first version number of TEXT (^7.1.0 → 7.1.0, >=1.12,<2 → 1.12, v3 → 3)
outside_num() { grep -oE '[0-9]+(\.[0-9]+)*' <<<"$1" | head -n 1 || true; }

# outside_kind — "a new major version" when TO opens a newer major than FROM (a Go module's new
# major path always), else "outside the constraint" (an exact pin, a 0.x minor, an upper bound)
outside_kind() {
  local f t
  if [ "$pkg_kind" = go ]; then echo "a new major version"; return 0; fi
  f="$(outside_num "$o_from")"
  t="$(outside_num "$o_to")"
  if [ -n "$f" ] && [ -n "$t" ] && [ "${t%%.*}" -gt "${f%%.*}" ] 2>/dev/null && [ "${t%%.*}" -ge 1 ]; then
    echo "a new major version"
  else
    echo "outside the constraint"
  fi
}

# outside_on_line VERSION — VERSION is on the line of TO: its major (a 0.x version: its minor)
outside_on_line() {
  local v="${1#v}" t="${o_to#v}"
  [ -n "$v" ] || return 1
  if [ "${t%%.*}" = 0 ]; then
    [ "$(cut -d. -f1-2 <<<"$v")" = "$(cut -d. -f1-2 <<<"$t")" ]
  else
    [ "${v%%.*}" = "${t%%.*}" ]
  fi
}

# outside_semver STYLE CONSTRAINT TO — the constraint a caret, tilde or exact version becomes
# (composer: ^major.minor, ^0.minor for 0.x, ~major.minor; npm: ^TO, ~TO; an exact one: TO);
# nothing for any other form (||, a range, >=, *, dev-…)
outside_semver() {
  local style="$1" c="$2" to="${3#v}" op="" maj min
  maj="${to%%.*}"
  min="$(cut -d. -f2 <<<"${to}.0")"
  case "$c" in
    '^'*|'~'*) op="${c:0:1}"; [[ "${c:1}" =~ ^v?[0-9]+(\.[0-9]+){0,2}$ ]] || return 0 ;;
    *) [[ "$c" =~ ^v?[0-9]+(\.[0-9]+){0,3}$ ]] || return 0 ;;
  esac
  if [ -z "$op" ]; then
    if [[ "$c" == v* ]]; then echo "v${to}"; else echo "$to"; fi
  elif [ "$style" = composer ]; then
    if [ "$maj" = 0 ]; then echo "${op}0.${min}"; else echo "${op}${maj}.${min}"; fi
  else
    echo "${op}${to}"
  fi
}

# outside_json_edit TREE FILE — NAME's constraint o_constraint → o_new in a JSON manifest of TREE
# (composer.json, package.json: the text, its format kept); status 1 when it names none
outside_json_edit() {
  local count
  count="$(NAME="$o_name" OLD="$o_constraint" NEW="$o_new" perl -i -ne 'BEGIN { $n = 0 }
    $n += s/("\Q$ENV{NAME}\E"\s*:\s*")\Q$ENV{OLD}\E(")/$1$ENV{NEW}$2/g; print; END { print STDERR "$n\n" }' "$1/$2" 2>&1 >/dev/null | tail -n 1)"
  [ "${count:-0}" -gt 0 ] 2>/dev/null
}

# outside_go_edit TREE — the old major path (o_constraint) becomes the new one (o_name) at o_to:
# its require line of go.mod, and every import of it (and of its packages) in the module's .go
# files; status 1 when go.mod requires no old path
outside_go_edit() {
  local tree="$1" count rest="" f
  count="$(OLD="$o_constraint" NEW="$o_name" V="$o_to" perl -i -ne 'BEGIN { $n = 0 }
    $n += s/^(\s*(?:require\s+)?)\Q$ENV{OLD}\E\s+v\S+/$1$ENV{NEW} $ENV{V}/; print; END { print STDERR "$n\n" }' \
    "$tree/$pkg_dir/go.mod" 2>&1 >/dev/null | tail -n 1)"
  [ "${count:-0}" -gt 0 ] 2>/dev/null || return 1
  # a new path under the old one (example.com/m → example.com/m/v2): an import of it already is not
  # rewritten again
  [[ "$o_name" != "$o_constraint"/* ]] || rest="${o_name#"$o_constraint"}"
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    OLD="$o_constraint" NEW="$o_name" REST="$rest" perl -i -pe '
      if ($ENV{REST} ne "") { s{"\Q$ENV{OLD}\E(?=["/])(?!\Q$ENV{REST}\E["/])}{"$ENV{NEW}}g }
      else { s{"\Q$ENV{OLD}\E(?=["/])}{"$ENV{NEW}}g }' "$tree/$f"
  done < <(git -C "$tree" ls-files -- "$pkg_dir/*.go")
  return 0
}

# outside_edit TREE — the manifest's constraint moved in TREE (composer, npm, go)
outside_edit() {
  case "$pkg_kind" in
    composer) outside_json_edit "$1" "$pkg_dir/composer.json" ;;
    npm) outside_json_edit "$1" "$pkg_dir/package.json" ;;
    go) outside_go_edit "$1" ;;
  esac
}

# outside_hook TREE — before a kind's bump runs its tool in its scratch worktree TREE and in the
# checkout: the constraint moves in both (the bump is made twice and must come out the same)
outside_hook() {
  [ "$outside_mode" = 1 ] || return 0
  if ! outside_edit "$1" || ! outside_edit "$ROOT"; then
    outside_refuse "${o_where} no longer names ${o_name} as \`${o_constraint}\`: it moved since the check, which files the release again"
  fi
  echo "edit ${o_where}: ${o_name} \`${o_constraint}\` → \`${o_new}\`" >> "$report/edits.log"
}

# outside_ghcr_digest PATH TAG — the digest a ghcr.io tag names, anonymously (a HEAD request)
outside_ghcr_digest() {
  local ghcr="${DEPS_GHCR:-https://ghcr.io}" token
  token="$(curl -fsS --max-time 20 "${ghcr}/token?scope=repository:$1:pull" | jq -r .token)" || return 1
  curl -fsSI --max-time 20 -H "Authorization: Bearer ${token}" \
    -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
    "${ghcr}/v2/$1/manifests/$2" | tr -d '\r' | sed -n 's/^[Dd]ocker-[Cc]ontent-[Dd]igest: *//p' | grep -E '^sha256:[0-9a-f]{64}$'
}

outside_composer() {
  local v
  o_where="$pkg_dir/composer.json"
  jq -e --arg n "$o_name" --arg c "$o_constraint" '((.require // {})[$n] // (."require-dev" // {})[$n]) == $c' "$o_where" >/dev/null 2>&1 \
    || outside_refuse "${o_where} no longer requires ${o_name} as \`${o_constraint}\`: it moved since the check, which files the release again"
  o_new="$(outside_semver composer "$o_constraint" "$o_to")"
  [ -n "$o_new" ] || outside_refuse "${o_name}'s constraint \`${o_constraint}\` (${o_where}) is no caret, tilde or exact version the bot moves mechanically: the code or a person writes the new one"
  bump_composer
  v="$(jq -r --arg n "$o_name" '[(.packages // [])[], (."packages-dev" // [])[] | select(.name == $n) | .version][0] // empty' "$pkg_dir/composer.lock")"
  outside_on_line "$v" || reasons+=("after the update composer.lock has ${o_name} ${v:-none}, not the ${o_to} line: another package holds it back, which the code or a person changes")
}

outside_npm() {
  local v
  o_where="$pkg_dir/package.json"
  jq -e --arg n "$o_name" --arg c "$o_constraint" '((.dependencies // {})[$n] // (.devDependencies // {})[$n]) == $c' "$o_where" >/dev/null 2>&1 \
    || outside_refuse "${o_where} no longer names ${o_name} as \`${o_constraint}\`: it moved since the check, which files the release again"
  o_new="$(outside_semver npm "$o_constraint" "$o_to")"
  [ -n "$o_new" ] || outside_refuse "${o_name}'s range \`${o_constraint}\` (${o_where}) is no caret, tilde or exact version the bot moves mechanically: the code or a person writes the new one"
  if [ -f "$pkg_dir/package-lock.json" ]; then
    bump_npm
    v="$(jq -r --arg n "$o_name" '.packages["node_modules/" + $n].version // empty' "$pkg_dir/package-lock.json")"
    outside_on_line "$v" || reasons+=("after the update package-lock.json has ${o_name} ${v:-none}, not the ${o_to} line: another package holds it back, which the code or a person changes")
  else
    # no lockfile (a library, an example): the range is all that moves
    outside_hook_root
    moved+=("${o_name} ${o_constraint} → ${o_new}")
  fi
}

# outside_hook_root — the constraint moved in the checkout alone (a manifest without a lockfile)
outside_hook_root() {
  outside_edit "$ROOT" || outside_refuse "${o_where} no longer names ${o_name} as \`${o_constraint}\`: it moved since the check, which files the release again"
  echo "edit ${o_where}: ${o_name} \`${o_constraint}\` → \`${o_new}\`" >> "$report/edits.log"
}

outside_go() {
  local old="$o_constraint" new="$o_name" m keep=()
  o_where="$pkg_dir/go.mod"
  o_new="$new"
  [[ "$old" =~ ^[A-Za-z0-9][A-Za-z0-9._/~-]*$ ]] && [ "$old" != "$new" ] \
    || outside_refuse "${new} ${o_to}: the finding names no module path it replaces (\`${old}\`)"
  OLD="$old" perl -ne '$f = 1 if /^\s*(?:require\s+)?\Q$ENV{OLD}\E\s+v\S+/; END { exit($f ? 0 : 1) }' "$o_where" \
    || outside_refuse "${o_where} no longer requires ${old}: it moved since the check, which files the release again"
  if [ -f "$pkg_dir/go.sum" ]; then
    bump_go
    # one line for the new path: the old one went, the new one came
    for m in "${moved[@]}"; do
      case "${m%% *}" in "$old"|"$new") ;; *) keep+=("$m") ;; esac
    done
    moved=("${old} ${o_from} → ${new} ${o_to}" ${keep[@]+"${keep[@]}"})
  else
    # no go.sum (a module the region's build resolves): go.mod and the imports only
    outside_hook_root
    moved+=("${old} ${o_from} → ${new} ${o_to}")
  fi
}

outside_actions() {
  local slug tags sha maj file count total=0 gh=0 other=0
  o_where=".github/workflows, actions/"
  [[ "$o_to" =~ ^v?[0-9]+(\.[0-9]+)*$ ]] || outside_refuse "${o_name} ${o_to} is no release tag the bot pins"
  slug="$(cut -d/ -f1-2 <<<"$o_name")"
  tags="$(git ls-remote --tags "${DEPS_GITHUB_URL:-https://github.com}/${slug}" 2>"$work/ls.err")" \
    || outside_error "git ls-remote ${slug}: $(tail -n 1 "$work/ls.err")"
  sha="$(awk -v t="refs/tags/${o_to}" '$2 == t "^{}" { p = $1 } $2 == t { q = $1 } END { print (p != "" ? p : q) }' <<<"$tags")"
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || outside_refuse "${slug} has no tag ${o_to} (any more)"
  maj="$(deps_major "$o_to")"
  [[ "$o_from" != v* ]] || maj="v${maj}"
  while IFS= read -r file; do
    [ -n "$file" ] || continue
    # a pin (name@<commit> # <from>) moves to the new tag's commit; a floating major (name@v4) to
    # the new major
    count="$(NAME="$o_name" FROM="$o_from" SHA="$sha" TO="$o_to" MAJ="$maj" perl -i -ne 'BEGIN { $n = 0 }
      $n += s/(\Q$ENV{NAME}\E@)[0-9a-f]{40}(\s+#\s+)\Q$ENV{FROM}\E(?![0-9A-Za-z._-])/$1$ENV{SHA}$2$ENV{TO}/g;
      $n += s/(\Q$ENV{NAME}\E@)\Q$ENV{FROM}\E(?![0-9A-Za-z._-])/$1$ENV{MAJ}/g if $ENV{FROM} =~ /^v?[0-9]+$/;
      print; END { print STDERR "$n\n" }' "$file" 2>&1 >/dev/null | tail -n 1)"
    [ "${count:-0}" -gt 0 ] 2>/dev/null || continue
    total=$((total + count))
    echo "edit ${file}: ${o_name} ${o_from} → ${o_to}" >> "$report/edits.log"
    case "$file" in .github/*) gh=1 ;; *) other=1 ;; esac
  done < <(git -C "$ROOT" grep -l -F -e "${o_name}@" -- '.github/workflows/*.yml' 'actions/*/action.yml' 2>/dev/null || true)
  [ "$total" -gt 0 ] || outside_refuse "no workflow pins ${o_name} ${o_from} any more: it moved since the check, which files the release again"
  if [ "$other" = 1 ] && [ "$gh" = 1 ]; then outside_refuse "the pins of ${o_name} move in .github/ and in actions/ at once: the release takes actions/ and never .github/; a person splits them"; fi
  [ "$other" = 0 ] || shipped=1
  o_new="${o_to} (${sha:0:12})"
  moved+=("${o_name} ${o_from} → ${o_to}")
}

outside_docker() {
  local file old="" new="" d
  o_where="$(jq -r '.[0].files // [] | join(" ")' "$updates_file")"
  o_new="$o_to"
  case "$o_name" in
    */golang) outside_refuse "${o_name}:${o_to} opens a new Go toolchain line: every go.mod's go line, the workflows' go-version and the golang base images move together, which the code (and a person) does" ;;
  esac
  [ -n "$o_where" ] || outside_refuse "the finding names no Dockerfile of ${o_name}:${o_from}"
  # a base pinned by digest gets the new tag's
  for file in $o_where; do
    d="$(IMG="${o_name}:${o_from}" perl -ne 'print "$1\n" if /\Q$ENV{IMG}\E\@(sha256:[0-9a-f]{64})/' "$file" 2>/dev/null | head -n 1)"
    [ -z "$d" ] || old="$d"
  done
  if [ -n "$old" ]; then
    [[ "$o_name" == ghcr.io/* ]] || outside_refuse "${o_name} is pinned by digest and not on ghcr.io: the bot reads digests of ghcr.io only"
    new="$(outside_ghcr_digest "${o_name#ghcr.io/}" "$o_to")" || outside_error "the digest of ${o_name}:${o_to} (ghcr.io did not answer)"
  fi
  jq -c --arg d "$new" --arg od "$old" '[.[0] | {name, from, to, files, digest: $d, old_digest: $od}]' "$updates_file" > "$work/outside-updates.json"
  updates_file="$work/outside-updates.json"
  bump_docker
}

outside_pins() {
  local row line
  row="$(deps_pin_row "$pkg_group" "$o_name" || true)"
  [ -n "$row" ] || outside_refuse "${o_name} is no pin of ${pkg_group} (ci/deps/packages.sh, DEPS_PINS)"
  line="$(deps_field "$row" 4)"
  o_where="$(deps_field "$row" 7 | tr ' ' '\n' | cut -d'~' -f1 | paste -sd ' ' -)"
  o_new="$o_to"
  case "$line" in
    toolchain-*)
      if [ "$o_name" = go ]; then
        outside_refuse "Go ${o_to} is a new line of the toolchain the code is written for: every go.mod's go line, the workflows' go-version and the golang base images move together, which the code (and a person) does"
      fi
      outside_refuse "${o_name} ${o_to} is a new line of a toolchain the code is written for (DEPS_PINS: ${line}): the workflows' ${o_name}-version moves with the code, which a person does" ;;
    person) outside_refuse "${o_name} ${o_to}: RunR builds this upstream with patches of its own (DEPS_PINS: person), so a person moves it with them" ;;
    minor)
      [ "$pkg_group" != pins-images ] || outside_refuse "${o_name} ${o_to} is a new Kubernetes minor: the API image carries one kubectl for every second minor, so a new minor is a new row of the set (its ARG, its checksums in both API Dockerfiles, its DEPS_PINS row, the docs that list the set), which a person adds" ;;
  esac
  jq -c '[.[0] | {name, from, to, files}]' "$updates_file" > "$work/outside-updates.json"
  updates_file="$work/outside-updates.json"
  bump_pins
}

# outside_pep440 SPEC TO — the specifier a Python requirement moves to: ==X → ==TO, ~=X.Y →
# ~=major.minor, a floor alone >=X → >=TO, an upper bound <N → <(major + 1) (its floor kept);
# status 1 for any other form
outside_pep440() {
  local spec="$1" to="$2" maj min c out="" lt=0
  local -a cl=()
  maj="${to%%.*}"
  min="$(cut -d. -f2 <<<"${to}.0")"
  [[ "$to" =~ ^[0-9]+(\.[0-9]+)*$ ]] || return 1
  IFS=',' read -r -a cl <<<"$spec"
  if [ "${#cl[@]}" = 1 ]; then
    case "$spec" in
      '=='*) [[ "${spec#==}" =~ ^[0-9]+(\.[0-9]+)*$ ]] && { echo "==${to}"; return 0; } ;;
      '~='*) [[ "${spec#\~=}" =~ ^[0-9]+(\.[0-9]+)+$ ]] && { echo "~=${maj}.${min}"; return 0; } ;;
      '>='*) [[ "${spec#>=}" =~ ^[0-9]+(\.[0-9]+)*$ ]] && { echo ">=${to}"; return 0; } ;;
      '<'[0-9]*) [[ "${spec#<}" =~ ^[0-9]+(\.[0-9]+)*$ ]] && { echo "<$((maj + 1))"; return 0; } ;;
    esac
    return 1
  fi
  for c in "${cl[@]}"; do
    case "$c" in
      '<'[0-9]*) [[ "${c#<}" =~ ^[0-9]+(\.[0-9]+)*$ ]] || return 1; out="${out:+${out},}<$((maj + 1))"; lt=1 ;;
      '>='[0-9]*|'>'[0-9]*|'!='[0-9]*) out="${out:+${out},}${c}" ;;
      *) return 1 ;;
    esac
  done
  [ "$lt" = 1 ] || return 1
  echo "$out"
}

outside_pypi() {
  local count
  o_where="$(jq -r '.[0].files[0] // empty' "$updates_file")"
  [ -n "$o_where" ] && [ -f "$o_where" ] || outside_refuse "the finding names no manifest of ${o_name} (${pkg_dir})"
  o_new="$(outside_pep440 "$o_constraint" "${o_to#v}")" \
    || outside_refuse "${o_name}'s specifier \`${o_constraint}\` (${o_where}) is no exact pin, ~=, floor or upper bound the bot moves mechanically: the code or a person writes the new one"
  # the requirement as the file writes it (blanks between its parts are allowed): its specifier
  count="$(NAME="$o_name" OLD="$o_constraint" NEW="$o_new" perl -0 -i -pe 'BEGIN { $n = 0 }
    s{(?<![A-Za-z0-9._-])(\Q$ENV{NAME}\E\s*(?:\[[^\]\n]*\])?\s*)([<>=!~][^"\x27#;\n]*?)(?=\s*(?:["\x27#;\]]|$))}{
      my ($a, $b) = ($1, $2); (my $s = $b) =~ s/\s+//g;
      if ($s eq $ENV{OLD}) { $n++; $a . $ENV{NEW} } else { $a . $b } }gme;
    END { print STDERR "$n\n" }' "$o_where" 2>&1 >/dev/null | tail -n 1)"
  [ "${count:-0}" -gt 0 ] 2>/dev/null || outside_refuse "${o_where} no longer names ${o_name}${o_constraint}: it moved since the check, which files the release again"
  echo "edit ${o_where}: ${o_name} \`${o_constraint}\` → \`${o_new}\`" >> "$report/edits.log"
  moved+=("${o_name} ${o_constraint} → ${o_new}")
}

outside_cargo() {
  local count
  o_where="$(jq -r '.[0].files[0] // empty' "$updates_file")"
  [ -n "$o_where" ] && [ -f "$o_where" ] || outside_refuse "the finding names no Cargo.toml of ${o_name} (${pkg_dir})"
  [[ "$o_constraint" =~ ^([~^=]?)([0-9]+(\.[0-9]+){0,2})$ ]] \
    || outside_refuse "${o_name}'s requirement \`${o_constraint}\` (${o_where}) is no version the bot moves mechanically: the code or a person writes the new one"
  o_new="${BASH_REMATCH[1]}${o_to#v}"
  [[ "${o_to#v}" =~ ^[0-9]+(\.[0-9]+){0,2}$ ]] || outside_refuse "${o_name} ${o_to} is no version Cargo's requirement takes"
  # the crate's line ("name = …", or one that renames it: package = "name")
  count="$(NAME="$o_name" OLD="$o_constraint" NEW="$o_new" perl -i -ne 'BEGIN { $n = 0 }
    if (/^\s*\Q$ENV{NAME}\E\s*=/ || /package\s*=\s*"\Q$ENV{NAME}\E"/) { $n += s/"\Q$ENV{OLD}\E"/"$ENV{NEW}"/g }
    print; END { print STDERR "$n\n" }' "$o_where" 2>&1 >/dev/null | tail -n 1)"
  [ "${count:-0}" -gt 0 ] 2>/dev/null || outside_refuse "${o_where} no longer requires ${o_name} \"${o_constraint}\" on its line: it moved since the check, which files the release again"
  echo "edit ${o_where}: ${o_name} \`${o_constraint}\` → \`${o_new}\`" >> "$report/edits.log"
  moved+=("${o_name} ${o_constraint} → ${o_new}")
}

# bump_outside — the finding's one update outside the constraint, moved as above (deps-run.sh asks a
# release's bump-packages.sh for this function: a release without it cannot move a constraint)
bump_outside() {
  [ "$(jq length "$updates_file")" = 1 ] || { echo "${dep}: an update outside the constraint is a finding of its own, one update" >&2; exit 1; }
  outside_mode=1
  o_name="$(jq -r '.[0].name' "$updates_file")"
  o_from="$(jq -r '.[0].from // ""' "$updates_file")"
  o_to="$(jq -r '.[0].to' "$updates_file")"
  o_constraint="$(jq -r '.[0].constraint // ""' "$updates_file")"
  o_kind="$(outside_kind)"
  case "$pkg_kind" in
    composer) outside_composer ;;
    npm) outside_npm ;;
    go) outside_go ;;
    actions) outside_actions ;;
    docker) outside_docker ;;
    pins) outside_pins ;;
    pypi) outside_pypi ;;
    cargo) outside_cargo ;;
    *) echo "${dep}: unknown kind ${pkg_kind}" >&2; exit 1 ;;
  esac
}

# pkg_attention — every reason found: the checkout put back (the tools' files too), status 2
pkg_attention() {
  pkg_cleanup
  deps_revert_all
  attention
}

pkg_cleanup() {
  git -C "$ROOT" worktree remove --force "$work/again" >/dev/null 2>&1 || true
  git -C "$ROOT" worktree prune >/dev/null 2>&1 || true
}

# pkg_suites_json TAG — suites.json: each suite of the group (the + ones when the bump ships) and
# the Dockerfiles' (docker)
pkg_suites_json() {
  local tag="$1" s list=() seen=" "
  for s in $pkg_suites ${extra_suites[@]+"${extra_suites[@]}"}; do
    case "$s" in
      +*) [ "$shipped" = 1 ] || continue; s="${s#+}" ;;
    esac
    [[ "$seen" == *" ${s} "* ]] && continue
    seen="${seen}${s} "
    list+=("$s")
  done
  : > "$report/unit"
  {
    for s in ${list[@]+"${list[@]}"}; do
      case "$s" in
        unit) echo 1 > "$report/unit" ;;
        e2e) suite_json e2e "test" "$DEPS_DEFAULT_K8S" false "" ;;
        upgrade) suite_json upgrade "test-helm-upgrade --from=${tag}" "$DEPS_DEFAULT_K8S" false "" ;;
        api-product|images|org-transfer|supply-chain|wasm|network|runtimes) suite_json "$s" "test-${s}" "$DEPS_DEFAULT_K8S" false "" ;;
        # the console's: a workflow each (ui-tests.yml, ui-stack-suite.yml), as ci.yml and e2e.yml run them
        ui|ui-stack) jq -nc --arg s "$s" '{suite: $s, job: $s, command: $s}' ;;
        caddy|actionlint|npm-test:*|py-test:*|go:*|image:*)
          jq -nc --arg suite "$(tr -c 'A-Za-z0-9\n-' '-' <<<"$s" | sed 's/-*$//')" --arg command "$s" '{suite: $suite, job: "check", command: $command}' ;;
        *) echo "deps-bump: unknown suite ${s} of ${dep}" >&2; return 1 ;;
      esac
    done
  } | jq -s -c 'map(. + {job: (.job // "kind")})' > "$report/suites.json"
  # a bump that changed the API's code or tests (an image's own version named in its config and
  # tests: gateway-auth, supply-chain, the Spin shim) runs PHPUnit too
  [ -z "$(git -C "$ROOT" diff --name-only -- api)" ] || echo 1 > "$report/unit"
}

bump_packages() {
  local tag edited touched list n label_line key path img held_reason name after
  [ -n "${updates_file:-}" ] && [ -s "$updates_file" ] || { echo "${dep}: a package group needs --updates=FILE (the finding's updates)" >&2; exit 1; }
  updates_meta="${updates_file%.json}.meta.json"
  # the updates as the bump takes them: an array (the marker's updates), names and versions checked
  jq -e 'type == "array"' "$updates_file" >/dev/null 2>&1 || { jq -c '.updates // []' "$updates_file" > "$work/updates.json"; cp "$updates_file" "$work/meta.json"; updates_meta="$work/meta.json"; updates_file="$work/updates.json"; }
  pkg_safe || { echo "${dep}: the updates name a package, a version, a digest or a file the bot does not take as is" >&2; exit 1; }
  # a held package is never moved, whatever the finding says
  while IFS=$'\t' read -r name after; do
    held_reason="$(deps_held "$pkg_group" "$name" "$after")" && reasons+=("${name} ${after} is held: ${held_reason}")
  done < <(jq -r '.[] | [.name, .to] | @tsv' "$updates_file")
  # a Go module's new major path: the hold names the path it replaces
  while IFS=$'\t' read -r name after; do
    [ -n "$name" ] || continue
    held_reason="$(deps_held "$pkg_group" "$name" "$after")" && reasons+=("${name} ${after} is held: ${held_reason}")
  done < <(jq -r '.[] | select(.outside == true and (.constraint // "") != "" and (.constraint | test("^[A-Za-z0-9][A-Za-z0-9._/~-]*$"))) | [.constraint, .to] | @tsv' "$updates_file")
  [ "${#reasons[@]}" -eq 0 ] || pkg_attention

  if jq -e 'any(.[]; .outside == true)' "$updates_file" >/dev/null; then
    bump_outside
  else
    case "$pkg_kind" in
      composer) bump_composer ;;
      npm) bump_npm ;;
      go) bump_go ;;
      actions) bump_actions ;;
      docker) bump_docker ;;
      pins) bump_pins ;;
      pypi|cargo) bump_no_lock ;;
      *) echo "${dep}: unknown kind ${pkg_kind}" >&2; exit 1 ;;
    esac
  fi

  # only the manifest's lockfile (and the pins of its kind) changed: no constraint moved
  touched="$(git -C "$ROOT" diff --name-only)"
  case "$pkg_kind" in
    composer) list="$(grep -vxF "$pkg_dir/composer.lock" <<<"$touched" || true)" ;;
    npm) list="$(grep -vxF "$pkg_dir/package-lock.json" <<<"$touched" || true)" ;;
    go) list="$(grep -vxE "${pkg_dir//./\\.}/go\\.(mod|sum)" <<<"$touched" || true)" ;;
    *) list="" ;;
  esac
  # an update outside the constraint moves its manifest (and a Go major path the module's imports)
  if [ "$outside_mode" = 1 ] && [ -n "$list" ]; then
    case "$pkg_kind" in
      composer) list="$(grep -vxF "$pkg_dir/composer.json" <<<"$list" || true)" ;;
      npm) list="$(grep -vxF "$pkg_dir/package.json" <<<"$list" || true)" ;;
      go) list="$(grep -vE "^${pkg_dir//./\\.}/.+\\.go\$" <<<"$list" || true)" ;;
    esac
  fi
  [ -z "$list" ] || reasons+=("the update changed more than the lockfile ($(paste -sd ' ' <<<"$list")): a constraint moved, which a person decides")
  # the holds: a held package that moved with another one's dependencies
  for n in ${moved[@]+"${moved[@]}"}; do
    name="${n%% *}"; after="${n##* }"
    held_reason="$(deps_held "$pkg_group" "$name" "$after")" && reasons+=("${name} moved to ${after} with the others, and it is held: ${held_reason}")
  done
  [ "${#moved[@]}" -gt 0 ] || { [ "${#reasons[@]}" -gt 0 ] || { echo "the last release has every update of ${dep} already (nothing moved)." > "$report/summary.md"; deps_revert_all; finish current 3; }; }

  case "$pkg_path" in
    release) path=release ;;
    next) path=next; [ "$shipped" = 0 ] || [ "$pkg_kind" != actions ] || path=release ;;
    auto) if [ "$shipped" = 1 ]; then path=release; else path=next; fi ;;
  esac
  if [ "$path" = release ]; then
    shipped=1
    # each image once: two updates of one image move its version one patch
    for img in $(printf '%s\n' ${own_images[@]+"${own_images[@]}"} | sort -u); do own_version_bump "$img"; done
  fi
  if [ "${#reasons[@]}" -gt 0 ]; then pkg_attention; fi
  echo "$path" > "$report/path"

  # the finding's own updates first, then what moved with them (their dependencies, a package the
  # lockfile added or dropped): the changelog line names the first eight, which would otherwise be
  # whatever sorts first (@emnapi/core 2.0.0-beta.2 → none before @sentry/vue)
  local -a firsts=() others=()
  local wanted
  wanted=" $(jq -r '.[].name' "$updates_file" | paste -sd ' ' -) "
  for n in "${moved[@]}"; do
    if [[ "$wanted" == *" ${n%% *} "* ]]; then firsts+=("$n"); else others+=("$n"); fi
  done
  moved=(${firsts[@]+"${firsts[@]}"} ${others[@]+"${others[@]}"})

  # the changelog: one line under [Unreleased] → Changed, which a later bump of the group replaces
  n="${#moved[@]}"
  key="- **${pkg_label^} "
  label_line="$(printf '%s\n' "${moved[@]}" | head -n 8 | paste -sd ';' - | sed 's/;/, /g')"
  [ "$n" -le 8 ] || label_line="${label_line} and $((n - 8)) more"
  if [ "$outside_mode" = 1 ]; then
    # one line per release outside the constraint (its own key: the group's line stays), which says
    # it is a new major version and what an installed region does
    key="- **${pkg_label^}: ${o_name} "
    line="${key}${o_to}** (${o_kind}: ${o_name} ${o_from} → ${o_to}, outside \`${o_constraint}\` before; now \`${o_new}\` in ${o_where}). "
    if [ "$path" = release ]; then
      line="${line}$( [ "$pkg_kind" = docker ] && echo "The images build on the new base" || echo "The release's images carry it"); an installed region needs no step."
    else
      line="${line}Nothing a release image ships changes: it lands on next."
    fi
  elif [ "$path" = release ]; then
    line="${key}moved** (minor and patch updates): ${label_line}. $( [ "$pkg_kind" = docker ] && echo "The images build on the new bases." || echo "The release's images carry them.")"
  else
    line="${key}moved** (minor and patch updates, nothing a release image ships): ${label_line}."
  fi
  deps_changelog_add CHANGELOG.md "$key" "$line"
  echo "edit CHANGELOG.md: [Unreleased] → Changed" >> "$report/edits.log"

  tag="$(deps_last_release_tag HEAD || true)"
  if [ -z "$tag" ] && [[ " $pkg_suites ${extra_suites[*]:-} " == *upgrade* ]]; then
    echo "${dep}: no release tag v<x.y.z> in this checkout's history: the upgrade suite needs the last release (fetch the tags)" >&2
    deps_revert_all
    exit 1
  fi
  pkg_suites_json "$tag" || { deps_revert_all; exit 1; }

  edited="$(git -C "$ROOT" diff --name-only | paste -sd ',' - | sed 's/,/, /g')"
  if [ "$outside_mode" = 1 ]; then
    outside_texts
    finish bumped 0
  fi
  {
    echo "fix(${pkg_scope}): ${pkg_label} (${n} update$([ "$n" = 1 ] || echo s))"
    echo ""
    echo "The minor and patch updates of ${dep} the check found, moved mechanically within the"
    echo "constraints$([ "$path" = release ] && echo ", released as a patch release" || echo ", on next only: nothing a release ships changes"):"
    printf -- '- %s\n' "${moved[@]}"
    if [ "${#notes[@]}" -gt 0 ]; then echo ""; printf -- '- %s\n' "${notes[@]}"; fi
    echo ""
    echo "Changed: ${edited}."
    echo ""
    echo "The dependency test bot ran its suites on this commit before it landed: $(jq -r '[.[].command] | join(", ")' "$report/suites.json")$([ -s "$report/unit" ] && echo ", PHPUnit, Pint, Larastan and the API reference"), runtime/test${run_url:+ (${run_url})}."
  } > "$report/commit-msg.txt"
  {
    echo "**${dep}: ${n} update$([ "$n" = 1 ] || echo s)** ($([ "$path" = release ] && echo "a patch release: it changes what a release ships" || echo "lands on next only: nothing a release ships changes"))."
    echo ""
    printf -- '- %s\n' "${moved[@]}"
    echo ""
    echo "Edited: ${edited}."
  } > "$report/summary.md"
  finish bumped 0
}

# outside_texts — the commit message and the summary of a release outside the constraint
outside_texts() {
  {
    echo "fix(${pkg_scope}): ${pkg_label} (${o_name} ${o_to}, ${o_kind})"
    echo ""
    echo "${o_name} ${o_from} → ${o_to} is outside ${pkg_group}'s constraint \`${o_constraint}\` (${o_kind}): the"
    echo "dependency test bot moved the constraint mechanically (\`${o_constraint}\` → \`${o_new}\` in ${o_where})"
    echo "and made the group's bump$([ "$path" = release ] && echo ", released as a patch release" || echo ", on next only: nothing a release ships changes"):"
    printf -- '- %s\n' "${moved[@]}"
    if [ "${#notes[@]}" -gt 0 ]; then echo ""; printf -- '- %s\n' "${notes[@]}"; fi
    echo ""
    echo "Changed: ${edited}."
    echo ""
    echo "The dependency test bot ran its suites on this commit before it landed: $(jq -r '[.[].command] | join(", ")' "$report/suites.json")$([ -s "$report/unit" ] && echo ", PHPUnit, Pint, Larastan and the API reference"), runtime/test${run_url:+ (${run_url})}."
  } > "$report/commit-msg.txt"
  {
    echo "**${dep}: ${o_name} ${o_from} → ${o_to}** (${o_kind}; $([ "$path" = release ] && echo "a patch release: it changes what a release ships" || echo "lands on next only: nothing a release ships changes"))."
    echo ""
    echo "The constraint moved mechanically: \`${o_constraint}\` → \`${o_new}\` in ${o_where}; then the group's bump:"
    echo ""
    printf -- '- %s\n' "${moved[@]}"
    echo ""
    echo "Edited: ${edited}."
  } > "$report/summary.md"
}

# deps_revert_all — the checkout as it was (every tracked file the bump changed)
deps_revert_all() {
  local -a files=()
  mapfile -t files < <(git -C "$ROOT" diff --name-only)
  [ "${#files[@]}" -eq 0 ] || git -C "$ROOT" checkout -q -- "${files[@]}"
}
