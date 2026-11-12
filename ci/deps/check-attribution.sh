#!/usr/bin/env bash
# The commit attribution rule of the public repositories, for the commits a release adds there: the
# check of the manual release (its verify-attribution.sh) with the dependency test bot's identity.
# The dependency test bot (.github/workflows/deps-test.yml) runs it on its release before the push.
#
#   bash ci/deps/check-attribution.sh REPO OLD NEW [TAG…]
#
# Every commit of OLD..NEW (OLD empty: all of NEW's history) and the tags given:
#   - author and committer are known identities: Burak or Can (their noreply addresses, the
#     committer always Burak);
#   - a commit of the dependency test bot is one with its trailer (RunR-Deps-Bot: <dep> <version>,
#     DEPS_TRAILER) in the message's trailer block (git interpret-trailers): its author and committer are the bot's identity, which is Burak's
#     (DEPS_BOT_NAME <DEPS_BOT_EMAIL>, default Burak's noreply address: the identity of RunR's
#     public history), it has no Co-Authored-By (no Claude, no Can), changes nothing in landing/
#     and nothing in vue/ but vue/package.json and vue/package-lock.json (a console npm bump);
#   - a commit with a Claude Co-Authored-By changes only vue/ or landing/, one with Can only vue/,
#     one that changes anything else has no Co-Authored-By at all, and every Co-Authored-By is
#     Claude, Can or Burak;
#   - no commit or tag message matches DEPS_FORBIDDEN: the internal names of the release's scrub
#     (an extended regex, case-insensitive), which stay out of this repository's files (the
#     workflow's secret RUNR_SCRUB_PATTERN); no check without it;
#   - every tag given verifies (git verify-tag, with the GNUPGHOME of the environment).
# Exit status 0 when all of them hold; otherwise 1 and a FAIL line per problem.
set -uo pipefail
export LC_ALL=C.UTF-8

repo="${1:?usage: ci/deps/check-attribution.sh REPO OLD NEW [TAG…]}"
old="${2-}"
new="${3:?usage: ci/deps/check-attribution.sh REPO OLD NEW [TAG…]}"
shift 3
tags=("$@")

BURAK='Burak Faruk ŞAHİN <19393636+brkfun@users.noreply.github.com>'
CAN='Can Şentay <140180359+cansentay@users.noreply.github.com>'
CLAUDE='Claude Opus 5.5 <noreply@anthropic.com>'
BOT="${DEPS_BOT_NAME:-Burak Faruk ŞAHİN} <${DEPS_BOT_EMAIL:-19393636+brkfun@users.noreply.github.com}>"
TRAILER="${DEPS_TRAILER:-RunR-Deps-Bot}"
FORBIDDEN="${DEPS_FORBIDDEN:-}"

g() { git -C "$repo" "$@"; }
fails=0
fail() { echo "FAIL $1: $2"; fails=$((fails + 1)); }

g rev-parse -q --verify "${new}^{commit}" >/dev/null || { echo "FAIL: ${new} is not a commit in ${repo}"; exit 1; }
if [ -n "$old" ]; then
  g rev-parse -q --verify "${old}^{commit}" >/dev/null || { echo "FAIL: ${old} is not a commit in ${repo}"; exit 1; }
  mapfile -t commits < <(g rev-list --reverse "${old}..${new}")
else
  mapfile -t commits < <(g rev-list --reverse "$new")
fi

for c in "${commits[@]}"; do
  s="${c:0:8}"
  raw="$(g cat-file commit "$c")"
  author="$(sed -n '/^$/q; s/^author //p' <<<"$raw" | sed -E 's/ [0-9]+ [-+][0-9]{4}$//')"
  committer="$(sed -n '/^$/q; s/^committer //p' <<<"$raw" | sed -E 's/ [0-9]+ [-+][0-9]{4}$//')"
  msg="$(sed '1,/^$/d' <<<"$raw")"
  parent="$(sed -n '/^$/q; s/^parent //p' <<<"$raw" | head -n 1)"
  if [ -n "$parent" ]; then
    mapfile -d '' -t files < <(g diff -z --no-renames --name-only "$parent" "$c")
  else
    mapfile -d '' -t files < <(g diff-tree -z --no-renames --root -r --name-only --no-commit-id "$c")
  fi
  outside=0
  nonvue=0
  # what a commit of the bot may not change: landing/, and vue/ but its npm manifest and lockfile
  botfe=0
  for f in "${files[@]}"; do
    case "$f" in
      vue/package.json|vue/package-lock.json) ;;
      vue/*) botfe=1 ;;
      landing/*) nonvue=1; botfe=1 ;;
      *) outside=1; nonvue=1 ;;
    esac
  done
  # the bot's trailer in the message's trailer block, read by git's trailer parser as
  # ci/deps-release.sh reads it: a commit of a person whose body mentions the trailer (a line
  # "RunR-Deps-Bot: <dep> <version>, …" in its text) is not the bot's
  bot=0
  trailers="$(g interpret-trailers --parse <<<"$msg")"
  grep -qE "^${TRAILER}: [^ ]+" <<<"$trailers" && bot=1

  mapfile -t coauthors < <(grep -i '^co-authored-by:' <<<"$msg" | sed -E 's/^[Cc][Oo]-[Aa][Uu][Tt][Hh][Oo][Rr][Ee][Dd]-[Bb][Yy]:[[:space:]]*//; s/[[:space:]]+$//')
  claude=0
  can=0
  [ "$author" = "$CAN" ] && can=1
  for a in ${coauthors[@]+"${coauthors[@]}"}; do
    case "$a" in
      "$CLAUDE") claude=1 ;;
      "$CAN") can=1 ;;
      "$BURAK") ;;
      *) fail "$s" "unknown Co-Authored-By: ${a}" ;;
    esac
  done
  grep -qiE '^co-authored-by:.*(claude|anthropic)' <<<"$msg" && claude=1

  if [ "$bot" = 1 ]; then
    [ "$author" = "$BOT" ] && [ "$committer" = "$BOT" ] || fail "$s" "a commit of the bot (${TRAILER}) is not the bot's identity as author and committer: ${author} / ${committer}"
    [ "$botfe" = 1 ] && fail "$s" "a commit of the bot changes landing/ or vue/ beyond vue/package.json and vue/package-lock.json"
    [ "${#coauthors[@]}" -gt 0 ] && fail "$s" "a commit of the bot has a Co-Authored-By"
    [ "$claude" = 1 ] && fail "$s" "a commit of the bot names Claude"
  else
    case "$author" in "$BURAK"|"$CAN") ;; *) fail "$s" "author is neither Burak nor Can noreply: ${author}" ;; esac
    [ "$committer" = "$BURAK" ] || fail "$s" "committer is not Burak noreply: ${committer}"
  fi
  [ "$claude" = 1 ] && [ "$outside" = 1 ] && fail "$s" "Claude co-author on a commit that changes paths outside vue/ and landing/"
  [ "$can" = 1 ] && [ "$nonvue" = 1 ] && fail "$s" "Can Şentay on a commit that changes paths outside vue/"
  [ "$outside" = 1 ] && [ "${#coauthors[@]}" -gt 0 ] && fail "$s" "Co-Authored-By on a commit that changes paths outside vue/ and landing/"
  # (the line numbers only: the output reaches a public log)
  if [ -n "$FORBIDDEN" ] && hit="$(grep -n -i -E "$FORBIDDEN" <<<"$msg" | cut -d: -f1 | paste -sd, -)" && [ -n "$hit" ]; then
    fail "$s" "message names an internal system (line ${hit})"
  fi
done

for t in ${tags[@]+"${tags[@]}"}; do
  if ! out="$(g verify-tag "refs/tags/${t}" 2>&1)"; then
    fail "tag ${t}" "git verify-tag failed: $(head -n 3 <<<"$out" | tr '\n' ' ')"
  fi
  tmsg="$(g cat-file tag "refs/tags/${t}" 2>/dev/null | sed '1,/^$/d' | sed '/^-----BEGIN PGP SIGNATURE-----$/,$d')"
  if [ -n "$FORBIDDEN" ] && hit="$(grep -n -i -E "$FORBIDDEN" <<<"$tmsg" | cut -d: -f1 | paste -sd, -)" && [ -n "$hit" ]; then
    fail "tag ${t}" "message names an internal system (line ${hit})"
  fi
done

if [ "$fails" -eq 0 ]; then
  echo "OK: ${#commits[@]} commit(s), ${#tags[@]} tag(s) follow the attribution rule"
  exit 0
fi
echo "FAILED: ${fails} problem(s) in ${#commits[@]} commit(s), ${#tags[@]} tag(s)"
exit 1
