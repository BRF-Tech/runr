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
