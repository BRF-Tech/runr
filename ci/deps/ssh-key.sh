#!/usr/bin/env bash
# The deploy key of the dependency test bot (.github/workflows/deps-test.yml) for one step. Three
# values come from the workflow:
#   ORIGIN_REMOTE  the private development repository (origin): the repository variable
#                  GITLAB_RUNR_REMOTE, an SSH URL (git@HOST:PATH.git or ssh://git@HOST/PATH.git) of the
#                  pinned host
#   PUSH_KEY       the deploy key's private key (OpenSSH format): the secret GITLAB_RUNR_PUSH_KEY
#   KNOWN_HOSTS    the host's key line: the repository variable GITLAB_KNOWN_HOSTS
# The key and the host key go to DIR/key and DIR/known_hosts (mode 0600, DIR 0700). The host and the
# fingerprint of its key are pinned here (DEPS_ORIGIN_HOST, DEPS_ORIGIN_HOST_KEY_FINGERPRINT), so a
# changed variable can point the push at no other host. Prints the GIT_SSH_COMMAND that uses
# exactly them: no other identity, no agent, only that host key (StrictHostKeyChecking=yes, never
# accept-new). Nothing of the key is printed; the workflow removes DIR in a step of its own that
# always runs.
#
#   GIT_SSH_COMMAND="$(bash ci/deps/ssh-key.sh DIR)"
#
# Needs ssh-keygen.
set -euo pipefail

# the origin's host and the fingerprint of its published ED25519 host key
ORIGIN_HOST="${DEPS_ORIGIN_HOST:-gitlab.com}"
ORIGIN_FINGERPRINT="${DEPS_ORIGIN_HOST_KEY_FINGERPRINT:-SHA256:eUXGGm1YGsMAS7vkcx6JOJdOGHPem5gQp4taiCfCLB8}"

dir="${1:?usage: ci/deps/ssh-key.sh DIR}"
if [ -z "${ORIGIN_REMOTE:-}" ] || [ -z "${PUSH_KEY:-}" ] || [ -z "${KNOWN_HOSTS:-}" ]; then
  echo "the variable GITLAB_RUNR_REMOTE, the secret GITLAB_RUNR_PUSH_KEY and the variable GITLAB_KNOWN_HOSTS are not all set" >&2
  exit 1
fi
# an SSH URL of the pinned host, nothing a shell or git would read as more
host="${ORIGIN_HOST//./\\.}"
if ! [[ "$ORIGIN_REMOTE" =~ ^git@${host}:[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+\.git$ ]] \
  && ! [[ "$ORIGIN_REMOTE" =~ ^ssh://git@${host}(:22)?/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+\.git$ ]]; then
  echo "GITLAB_RUNR_REMOTE is no SSH URL of ${ORIGIN_HOST} (git@${ORIGIN_HOST}:GROUP/PROJECT.git)" >&2
  exit 1
fi
case "$PUSH_KEY" in
  *"-----BEGIN OPENSSH PRIVATE KEY-----"*) ;;
  *) echo "GITLAB_RUNR_PUSH_KEY is no private key in OpenSSH format" >&2; exit 1 ;;
esac

umask 077
mkdir -p "$dir"
chmod 700 "$dir"
printf '%s\n' "$KNOWN_HOSTS" | sed '/^[[:space:]]*$/d' > "$dir/known_hosts"
# one host key line of the origin's host, the one its operator publishes
lines="$(wc -l < "$dir/known_hosts")"
listed="$(awk '{ print $1 }' "$dir/known_hosts")"
fingerprint="$(ssh-keygen -l -E sha256 -f "$dir/known_hosts" 2>/dev/null | awk '{ print $2 }' || true)"
if [ "$lines" != 1 ] || [ "$listed" != "$ORIGIN_HOST" ] || [ "$fingerprint" != "$ORIGIN_FINGERPRINT" ]; then
  rm -rf -- "$dir"
  echo "GITLAB_KNOWN_HOSTS is not the single line of ${ORIGIN_HOST}'s host key ${ORIGIN_FINGERPRINT} (${lines} line(s), host ${listed:-none}, fingerprint ${fingerprint:-unreadable})" >&2
  exit 1
fi
# a key file must end in a line end
printf '%s\n' "$PUSH_KEY" | sed -e '$!b' -e '/^$/d' > "$dir/key"
chmod 600 "$dir/key" "$dir/known_hosts"
printf 'ssh -i %s -o IdentitiesOnly=yes -o IdentityAgent=none -o UserKnownHostsFile=%s -o StrictHostKeyChecking=yes -o BatchMode=yes\n' \
  "$dir/key" "$dir/known_hosts"
