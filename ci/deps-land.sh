#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: a green bump onto next ──────────────────────────────
# Lands COMMIT (one dependency's bump, tested on BASE) on the next branch of REMOTE, only as a
# fast-forward of the next it was tested on: when next is still BASE, COMMIT is pushed as next; a
# next that moved meanwhile is left alone, and the bot tests the bump again on top of it (the
# workflow dispatches itself). The one push is `git push REMOTE HEAD:refs/heads/next` with COMMIT
# checked out: no other branch or tag (the deploy key may write any branch the origin does not
# protect), never forced, and git refuses one that is no fast-forward, so a next that moves
# between the check and the push stays as it is too.
#
#   bash ci/deps-land.sh REMOTE BASE COMMIT [--dry-run]
#
#   REMOTE  the private development repository (the workflow passes the variable GITLAB_RUNR_REMOTE,
#           with the deploy key in GIT_SSH_COMMAND), or any git remote
#   BASE    the next the bump was made and tested on
#   COMMIT  the bump: its only parent is BASE
#
# Prints "landed COMMIT" (exit 0), "moved NEXT" (exit 4: next is NEXT now), or what failed (exit 1).
# Runs in a clone that has COMMIT (the workflow's bundle of the bumps).
# ─────────────────────────────────────────────────────────────────────────────

remote="${1:?usage: ci/deps-land.sh REMOTE BASE COMMIT [--dry-run]}"
base="${2:?usage: ci/deps-land.sh REMOTE BASE COMMIT [--dry-run]}"
commit="${3:?usage: ci/deps-land.sh REMOTE BASE COMMIT [--dry-run]}"
dry_run=0
[ "${4:-}" = "--dry-run" ] && dry_run=1

[[ "$base" =~ ^[0-9a-f]{40}$ ]] && [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || { echo "BASE and COMMIT are full commit ids" >&2; exit 1; }
git cat-file -e "${commit}^{commit}" 2>/dev/null || { echo "${commit} is not in this clone" >&2; exit 1; }
parents="$(git rev-list --parents -n 1 "$commit" | cut -d' ' -f2-)"
[ "$parents" = "$base" ] || { echo "${commit} is no single commit on top of ${base} (parents: ${parents})" >&2; exit 1; }

next="$(git ls-remote "$remote" refs/heads/next | awk '{ print $1 }')"
[ -n "$next" ] || { echo "the remote has no next branch" >&2; exit 1; }
if [ "$next" != "$base" ]; then
  echo "moved ${next}"
  exit 4
fi
if [ "$dry_run" = 1 ]; then
  echo "would land ${commit}"
  exit 0
fi
# a plain push of a commit whose parent is the remote's next: a fast-forward, nothing forced
git checkout -q --detach "$commit"
if ! out="$(git push "$remote" HEAD:refs/heads/next 2>&1)"; then
  now="$(git ls-remote "$remote" refs/heads/next | awk '{ print $1 }')"
  if [ -n "$now" ] && [ "$now" != "$base" ]; then
    echo "moved ${now}"
    exit 4
  fi
  echo "push refused: ${out}" >&2
  exit 1
fi
echo "landed ${commit}"
