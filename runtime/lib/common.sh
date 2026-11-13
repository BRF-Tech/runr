# ── Shared variables and functions ──
# This file is sourced, so it has no shebang.

REGISTRY="${REGISTRY:-ghcr.io/brf-tech/runr}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ── Dockerfile base images ──

# dockerfile_bases DOCKERFILE [NAME=VALUE...] — the base image of every stage of a Dockerfile,
# ${NAME} replaced by the build argument given or by the default of its ARG before the first FROM.
# Stage names, scratch and references that stay variable (cross-${TARGETARCH}) are left out.
dockerfile_bases() {
  local file="$1"
  shift
  awk -v overrides="$*" '
    BEGIN {
      n = split(overrides, kv, " ")
      for (i = 1; i <= n; i++) {
        eq = index(kv[i], "=")
        if (eq) given[substr(kv[i], 1, eq - 1)] = substr(kv[i], eq + 1)
      }
    }
    function subst(s,   out, m, name, val) {
      out = ""
      while (match(s, /[$]([{][A-Za-z_][A-Za-z0-9_]*[}]|[A-Za-z_][A-Za-z0-9_]*)/)) {
        m = substr(s, RSTART, RLENGTH)
        name = m
        gsub(/[${}]/, "", name)
        if (name in given) val = given[name]
        else if (name in args) val = args[name]
        else { unresolved = 1; val = m }
        out = out substr(s, 1, RSTART - 1) val
        s = substr(s, RSTART + RLENGTH)
      }
      return out s
    }
    toupper($1) == "ARG" && !from {
      eq = index($2, "=")
      if (eq) {
        val = substr($2, eq + 1)
        gsub(/^["\047]|["\047]$/, "", val)
        args[substr($2, 1, eq - 1)] = val
      }
    }
    toupper($1) == "FROM" {
      from = 1
      i = 2
      while ($i ~ /^--/) i++
      unresolved = 0
      ref = subst($i)
      if (!unresolved && ref != "scratch" && !(tolower(ref) in stages)) print ref
      if (tolower($(i + 1)) == "as") stages[tolower($(i + 2))] = 1
    }
  ' "$file"
}

# ── Git version: the tag if there is one, otherwise main-<hash> ──
resolve_app_version() {
  local root="${1:-$(git rev-parse --show-toplevel 2>/dev/null || echo .)}"
  local tag
  tag=$(git -C "$root" describe --tags --exact-match HEAD 2>/dev/null || true)
  if [ -n "$tag" ]; then
    echo "$tag"
  else
    echo "main-$(git -C "$root" rev-parse --short HEAD)"
  fi
}

# ── The previous release: what an upgrade test starts from ──
# previous_release_tag ROOT — the newest release tag vX.Y.Z at or below the checkout's version
# (appVersion of helm/Chart.yaml) that is not HEAD itself: the release `main` carries, also in a
# checkout without `main` (kind-suite.yml's tree-artifact bundle has the tested branch and the
# tags). The tags are the checkout's own; a checkout without any (a shallow CI checkout) lists the
# origin's and fetches the one chosen. Prints the tag; 1 (and a reason on stderr) when there is none.
previous_release_tag() {
  local root="$1" version head list tag commit
  version="$(tr -d '\r' < "${root}/helm/Chart.yaml" 2>/dev/null \
    | sed -n 's/^appVersion: *"\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' | head -n 1)"
  [ -n "$version" ] || { echo "no appVersion in ${root}/helm/Chart.yaml" >&2; return 1; }
  head="$(git -C "$root" rev-parse -q --verify 'HEAD^{commit}' 2>/dev/null || true)"
  # "<tag> <commit>" of every release tag (an annotated tag: the commit it points at)
  local remote=0
  list="$(git -C "$root" for-each-ref \
    --format='%(refname:short) %(if)%(*objectname)%(then)%(*objectname)%(else)%(objectname)%(end)' \
    'refs/tags/v*' 2>/dev/null | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+ ' || true)"
  if [ -z "$list" ]; then
    remote=1
    list="$(git -C "$root" ls-remote --tags origin 'refs/tags/v*' 2>/dev/null | awk '
      { name = $2; sub(/^refs\/tags\//, "", name)
        if (name ~ /\^\{\}$/) { sub(/\^\{\}$/, "", name); peeled[name] = $1 } else direct[name] = $1 }
      END { for (n in direct) print n, (n in peeled ? peeled[n] : direct[n]) }' \
      | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+ ' || true)"
  fi
  [ -n "$list" ] || { echo "no release tag v<X.Y.Z> in ${root} or its origin" >&2; return 1; }
  tag=""
  while read -r name commit; do
    [ "$commit" = "$head" ] && continue
    # at or below the checkout's version
    [ "$(printf '%s\n%s\n' "${name#v}" "$version" | sort -V | head -n 1)" = "${name#v}" ] || continue
    tag="$name"
    break
  done < <(printf '%s\n' "$list" | sort -V -r -k1,1)
  [ -n "$tag" ] || { echo "no release tag at or below ${version} other than HEAD" >&2; return 1; }
  if [ "$remote" = 1 ]; then
    local depth=()
    [ "$(git -C "$root" rev-parse --is-shallow-repository 2>/dev/null)" = true ] && depth=(--depth=1)
    git -C "$root" fetch -q --no-tags ${depth[@]+"${depth[@]}"} origin "+refs/tags/${tag}:refs/tags/${tag}" >&2 \
      || { echo "git fetch origin ${tag} failed" >&2; return 1; }
  fi
  echo "$tag"
}

# ── Kind clusters ──

# kind_etcd_patch — KIND_ETCD_IN_MEMORY=1: the lines of a control-plane's kubeadm
# ClusterConfiguration patch (at their indent in a Kind config) that keep etcd's data on the
# node's tmpfs (/tmp), for hosts whose disk is too slow for etcd's fsyncs ("etcdserver: request
# timed out", Jobs never started). Nothing otherwise. Every suite that creates a Kind cluster
# uses it.
kind_etcd_patch() {
  [ "${KIND_ETCD_IN_MEMORY:-0}" = "1" ] || return 0
  printf '%s\n' '        etcd:' '          local:' '            dataDir: /tmp/etcd'
}
