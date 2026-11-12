# shellcheck shell=bash
# shellcheck disable=SC2154 # pkg_row, dep, report, work, run_url, reasons, notes: ci/deps-bump.sh's
# The bump of a package group (ci/deps/packages.sh, DEPS_PKG): sourced by ci/deps-bump.sh, whose
# report, finish, attention, reasons, notes and suite_json it uses. It has no shebang.
#
# The updates come from the finding (--updates=FILE, the issue's marker: [{name, from, to, …}]).
# Each kind moves them with its own tool, mechanically and within the manifest's constraints:
#   composer  composer update NAME… --with-dependencies (composer.lock only)
#   npm       npm update NAME… --package-lock-only; npm audit fix --package-lock-only for an
#             advisory (package-lock.json only)
#   go        go get NAME@VERSION… and go mod tidy (go.mod, go.sum)
#   actions   uses: NAME@<the tag's commit> # <tag>, in every workflow that pins the old one
#   docker    FROM <image>:<tag>[@<digest>] in the Dockerfiles that name the old one
#   pins      the version at every place of the pin (DEPS_PINS), and with it the checksums: the
#             published ones of the old version become the new one's (a checksum not published,
#             or not where the files pin it, sends the bump to a person)
#   pypi, cargo  nothing: without a lockfile only a release outside the constraint is a finding,
#             which a person moves
# The lockfile must come out the same when the bump is made twice (a scratch worktree of HEAD,
# then this checkout), the manifest's constraints must stay as they are, and a held package must
# not move; anything else goes to a person. The path follows what moved (DEPS_PKG's PATH): a bump
# that changes nothing a release ships lands on next only.

pkg_kind="$(deps_field "$pkg_row" 2)"
pkg_dir="$(deps_field "$pkg_row" 3)"
pkg_scope="$(deps_field "$pkg_row" 4)"
pkg_label="$(deps_field "$pkg_row" 5)"
pkg_path="$(deps_field "$pkg_row" 6)"
pkg_suites="$(deps_field "$pkg_row" 7)"
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
      and ((.from // "") | test("^[A-Za-z0-9._+-]*$"))
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
npm_run() {
  local -a pkgs=()
  mapfile -t pkgs < <(jq -r '.[] | select(.to != "fixed") | .name' "$updates_file")
  (
    cd "$1/$pkg_dir" || exit 1
    # a failed update fails the bump (its log says why)
    if [ "${#pkgs[@]}" -gt 0 ]; then npm update "${pkgs[@]}" --package-lock-only --ignore-scripts --no-audit --no-fund || exit 1; fi
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
  npm_run "$again" || { reasons+=("\`npm update\` failed: $(tail -n 3 "$work/npm-again.log" | tr '\n' ' ')"); pkg_attention; }
  npm_run "$ROOT" || { reasons+=("\`npm update\` failed: $(tail -n 3 "$work/npm-$(basename "$ROOT").log" | tr '\n' ' ')"); pkg_attention; }
  cmp -s "$again/$lock" "$ROOT/$lock" || reasons+=("package-lock.json does not come out the same when the update is made twice: the resolution is not reproducible")
  pkg_cleanup
  changed="$(jq -r --slurpfile old "$work/lock.old" '
      ($old[0].packages // {}) as $o | (.packages // {}) as $n
      | ([$o, $n] | map(keys) | add | unique)[] | select(. != "" and startswith("node_modules/"))
      | select(($o[.].version // "none") != ($n[.].version // "none"))
      | "\(sub("^(.*/)?node_modules/"; "")) \($o[.].version // "none") → \($n[.].version // "none")"' "$lock" | sort -u)"
  [ -z "$changed" ] || mapfile -t moved <<<"$changed"
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
    [ "$total" -gt 0 ] || { reasons+=("no workflow pins ${name}@${old} # ${from} any more"); continue; }
    moved+=("${name} ${from} → ${to}")
  done
  # RunR's own composite actions ship with a release; the workflows do not (and a release never
  # changes .github/)
  if [ "$other" = 1 ] && [ "$gh" = 1 ]; then reasons+=("the pins move in .github/ and in actions/ at once: the release takes actions/ and never .github/; a person splits them"); fi
  [ "$other" = 0 ] || shipped=1
}

# ── docker ──
bump_docker() {
  local i n name from to digest old file row version count total files
  n="$(jq length "$updates_file")"
  for ((i = 0; i < n; i++)); do
    name="$(jq -r ".[$i].name" "$updates_file")"; from="$(jq -r ".[$i].from" "$updates_file")"; to="$(jq -r ".[$i].to" "$updates_file")"
    digest="$(jq -r ".[$i].digest // \"\"" "$updates_file")"; old="$(jq -r ".[$i].old_digest // \"\"" "$updates_file")"
    total=0
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
        done
      fi
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
    [ "$total" -gt 0 ] || { reasons+=("no Dockerfile names ${name}:${from} any more"); continue; }
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
    row="$(deps_pin_row "$dep" "$name" || true)"
    [ -n "$row" ] || { reasons+=("${name} is no pin of ${dep} (ci/deps/packages.sh, DEPS_PINS)"); continue; }
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
  if ! bash "$ROOT/runtime/ci.sh" release-images 2>/dev/null | grep -qxF "ghcr.io/brf-tech/runr/${image}:${new}"; then
    reasons+=("after moving ${image} ${old} → ${new}, runtime/ci.sh release-images does not list ghcr.io/brf-tech/runr/${image}:${new}")
  fi
  local left
  left="$(git -C "$ROOT" grep -n -F -e "/${image}:${old}" -- . "${DEPS_NEVER_EDIT[@]}" 2>/dev/null | grep -viE "$DEPS_HISTORY_RE" \
    | grep -vF -e "-${image}:${old}" | head -n 5 || true)"
  [ -z "$left" ] || reasons+=("lines still name ${image}:${old} as the current one: ${left//$'\n'/; }")
  outside_lines="$(git -C "$ROOT" grep -n -F -e "/${image}:${old}" -- 'vue/**' 'landing/**' 2>/dev/null || true)"
  [ -z "$outside_lines" ] || reasons+=("the console or the landing page names ${image}:${old}, which the bot does not edit: ${outside_lines//$'\n'/; }")
  moved+=("${image} image ${old} → ${new} (its own version: release-images.yml builds it)")
  notes+=("${image} ${old} → ${new}: ${total} line(s)")
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
    held_reason="$(deps_held "$dep" "$name" "$after")" && reasons+=("${name} ${after} is held: ${held_reason}")
  done < <(jq -r '.[] | [.name, .to] | @tsv' "$updates_file")
  [ "${#reasons[@]}" -eq 0 ] || pkg_attention

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

  # only the manifest's lockfile (and the pins of its kind) changed: no constraint moved
  touched="$(git -C "$ROOT" diff --name-only)"
  case "$pkg_kind" in
    composer) list="$(grep -vxF "$pkg_dir/composer.lock" <<<"$touched" || true)" ;;
    npm) list="$(grep -vxF "$pkg_dir/package-lock.json" <<<"$touched" || true)" ;;
    go) list="$(grep -vxE "${pkg_dir//./\\.}/go\\.(mod|sum)" <<<"$touched" || true)" ;;
    *) list="" ;;
  esac
  [ -z "$list" ] || reasons+=("the update changed more than the lockfile ($(paste -sd ' ' <<<"$list")): a constraint moved, which a person decides")
  # the holds: a held package that moved with another one's dependencies
  for n in ${moved[@]+"${moved[@]}"}; do
    name="${n%% *}"; after="${n##* }"
    held_reason="$(deps_held "$dep" "$name" "$after")" && reasons+=("${name} moved to ${after} with the others, and it is held: ${held_reason}")
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

  # the changelog: one line under [Unreleased] → Changed, which a later bump of the group replaces
  n="${#moved[@]}"
  key="- **${pkg_label^} "
  label_line="$(printf '%s\n' "${moved[@]}" | head -n 8 | paste -sd ';' - | sed 's/;/, /g')"
  [ "$n" -le 8 ] || label_line="${label_line} and $((n - 8)) more"
  if [ "$path" = release ]; then
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

# deps_revert_all — the checkout as it was (every tracked file the bump changed)
deps_revert_all() {
  local -a files=()
  mapfile -t files < <(git -C "$ROOT" diff --name-only)
  [ "${#files[@]}" -eq 0 ] || git -C "$ROOT" checkout -q -- "${files[@]}"
}
