#!/usr/bin/env bash
# A signed annotated tag of the dependency test bot, made without `git tag -s` (which could ask for a
# passphrase): the tag object's text is signed with
#   gpg --batch --no-tty --pinentry-mode loopback --armor --detach-sign --local-user FINGERPRINT
# in a keyring of its own (a new GNUPGHOME of mode 0700, removed at the end), the signature appended
# as git does it, `git mktag` writes the object and the tag ref is created (never moved: a tag that
# exists is refused). Then `git verify-tag --raw` must report the pinned key (VALIDSIG).
#
#   bash ci/deps/sign-tag.sh REPO TAG TARGET MSGFILE
#
# From the environment (the workflow's step; nothing of the key is printed):
#   DEPS_SIGN_KEY          the bot's private key, ASCII-armored (the secret RUNR_BOT_GPG_PRIVATE_KEY)
#   DEPS_SIGN_PASSPHRASE   its passphrase (the secret RUNR_BOT_GPG_PASSPHRASE; empty: none), given to
#                          gpg on a file descriptor, never as an argument
#   DEPS_SIGN_FINGERPRINT  the key's fingerprint (the variable RUNR_BOT_GPG_FINGERPRINT): an imported
#                          key with another fingerprint is refused
#   DEPS_BOT_NAME, DEPS_BOT_EMAIL  the tagger
#   DEPS_TAG_DATE          the tagger's time ("<unix> +0000", default now)
#   DEPS_KEEP_GNUPGHOME    a directory to keep the keyring in instead of removing it (the attribution
#                          check verifies the tags with it; the caller removes it)
#
# Prints the tag object's id.
set -euo pipefail

repo="${1:?usage: ci/deps/sign-tag.sh REPO TAG TARGET MSGFILE}"
tag="${2:?usage: ci/deps/sign-tag.sh REPO TAG TARGET MSGFILE}"
target="${3:?usage: ci/deps/sign-tag.sh REPO TAG TARGET MSGFILE}"
msgfile="${4:?usage: ci/deps/sign-tag.sh REPO TAG TARGET MSGFILE}"
: "${DEPS_BOT_NAME:?}" "${DEPS_BOT_EMAIL:?}"
fpr="${DEPS_SIGN_FINGERPRINT:-}"
fpr="${fpr// /}"
[[ "$fpr" =~ ^[0-9A-Fa-f]{40}$ ]] || { echo "sign-tag: RUNR_BOT_GPG_FINGERPRINT is no fingerprint (40 hex digits)" >&2; exit 1; }
fpr="${fpr^^}"
case "${DEPS_SIGN_KEY:-}" in
  *"-----BEGIN PGP PRIVATE KEY BLOCK-----"*) ;;
  *) echo "sign-tag: RUNR_BOT_GPG_PRIVATE_KEY is no ASCII-armored private key" >&2; exit 1 ;;
esac
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "sign-tag: ${tag} is no release tag" >&2; exit 1; }
[ -s "$msgfile" ] || { echo "sign-tag: no message ${msgfile}" >&2; exit 1; }
if git -C "$repo" rev-parse -q --verify "refs/tags/${tag}" >/dev/null; then
  echo "sign-tag: ${tag} exists in ${repo}" >&2
  exit 1
fi
[ "$(git -C "$repo" cat-file -t "$target" 2>/dev/null)" = commit ] || { echo "sign-tag: ${target} is no commit in ${repo}" >&2; exit 1; }
target="$(git -C "$repo" rev-parse "${target}^{commit}")"

work="$(mktemp -d)"
if [ -n "${DEPS_KEEP_GNUPGHOME:-}" ]; then
  GNUPGHOME="$DEPS_KEEP_GNUPGHOME"
else
  GNUPGHOME="$work/gnupg"
fi
export GNUPGHOME
cleanup() {
  gpgconf --kill gpg-agent >/dev/null 2>&1 || true
  [ -n "${DEPS_KEEP_GNUPGHOME:-}" ] || rm -rf -- "$GNUPGHOME"
  rm -rf -- "$work"
}
trap cleanup EXIT
umask 077
mkdir -p "$GNUPGHOME"
chmod 700 "$GNUPGHOME"
# loopback: gpg asks the agent's pinentry for nothing, the passphrase comes on fd 3
printf 'allow-loopback-pinentry\n' > "$GNUPGHOME/gpg-agent.conf"
printf 'pinentry-mode loopback\nno-tty\nbatch\n' > "$GNUPGHOME/gpg.conf"

pass="${DEPS_SIGN_PASSPHRASE:-}"
printf '%s\n' "$DEPS_SIGN_KEY" | gpg --batch --no-tty --pinentry-mode loopback --passphrase-fd 3 --quiet --import 3<<<"$pass" 2>/dev/null \
  || { echo "sign-tag: the key does not import" >&2; exit 1; }
have="$(gpg --batch --with-colons --fingerprint --list-secret-keys 2>/dev/null | awk -F: '$1 == "fpr" { print $10 }' | tr 'a-f' 'A-F')"
grep -qx "$fpr" <<<"$have" || { echo "sign-tag: the key is not ${fpr} (RUNR_BOT_GPG_FINGERPRINT)" >&2; exit 1; }

date="${DEPS_TAG_DATE:-$(date +%s) +0000}"
{
  printf 'object %s\ntype commit\ntag %s\ntagger %s <%s> %s\n\n' "$target" "$tag" "$DEPS_BOT_NAME" "$DEPS_BOT_EMAIL" "$date"
  tr -d '\r' < "$msgfile"
} > "$work/buf"
[ "$(tail -c 1 "$work/buf" | od -An -c | tr -d ' ')" = '\n' ] || printf '\n' >> "$work/buf"
gpg --batch --no-tty --quiet --pinentry-mode loopback --passphrase-fd 3 --armor --detach-sign \
  --local-user "$fpr" --output "$work/sig" "$work/buf" 3<<<"$pass" || { echo "sign-tag: gpg could not sign" >&2; exit 1; }
cat "$work/buf" "$work/sig" > "$work/signed"
obj="$(git -C "$repo" mktag < "$work/signed")"
git -C "$repo" update-ref "refs/tags/${tag}" "$obj" ""
raw="$(git -C "$repo" verify-tag --raw "refs/tags/${tag}" 2>&1)" || { echo "sign-tag: verify-tag ${tag} failed" >&2; git -C "$repo" update-ref -d "refs/tags/${tag}" "$obj"; exit 1; }
if ! grep -q "^\[GNUPG:\] VALIDSIG ${fpr} " <<<"$raw"; then
  git -C "$repo" update-ref -d "refs/tags/${tag}" "$obj"
  echo "sign-tag: ${tag} is not signed by ${fpr}" >&2
  exit 1
fi
echo "$obj"
