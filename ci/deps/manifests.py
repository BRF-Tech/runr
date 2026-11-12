"""The Python and Cargo manifests of the dependency test bot's package groups (ci/deps/packages.sh,
kinds pypi and cargo), for ci/check-package-releases.sh. Python 3.11 or newer (tomllib), nothing
else: run it with python3 -I.

  manifests.py requirements FILE    pyproject.toml (project dependencies, optional dependencies,
                                    dependency groups, build-system requires) or requirements.txt:
                                    "NAME<TAB>SPECIFIER" per requirement
  manifests.py cargo FILE           Cargo.toml (dependencies, dev-, build- and target
                                    dependencies with a version): "CRATE<TAB>REQUIREMENT"
  manifests.py verdict KIND SPEC    the releases on stdin, one per line: "NEWEST<TAB>OUTSIDE",
                                    NEWEST the newest stable release, OUTSIDE 1 when a person
                                    decides it (below)

Neither kind keeps a lockfile here (libraries and examples), so only a release outside the
manifest's constraint is a finding, and the bot moves nothing by itself:
  pypi   one the specifier does not allow (an upper bound, an exact pin, ~=), or, under a floor
         alone (>=), a new major above the floor's (pytest>=7 and pytest 9: never tested with it)
  cargo  one the requirement does not allow (Cargo's caret, tilde, exact and wildcard forms)
Only stable releases count (digits and dots: no pre-, post- or dev releases).
"""

import re
import sys
import tomllib

STABLE = re.compile(r"^\d+(\.\d+)*$")
NAME = re.compile(r"^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(\[[^\]]*\])?\s*(.*)$")


def version(text):
    return tuple(int(p) for p in text.split("."))


def padded(a, b):
    n = max(len(a), len(b))
    return a + (0,) * (n - len(a)), b + (0,) * (n - len(b))


def compare(a, b):
    a, b = padded(a, b)
    return (a > b) - (a < b)


# ── pypi ──
def requirement(line):
    """A PEP 508 requirement: (name, specifier), without extras and markers; None for a URL."""
    line = line.split(";", 1)[0].strip()
    m = NAME.match(line)
    if not m or "@" in m.group(3):
        return None
    spec = m.group(3).strip().strip("()").replace(" ", "")
    return m.group(1), spec


def requirements(path):
    out = []
    if path.endswith(".toml"):
        with open(path, "rb") as f:
            data = tomllib.load(f)
        project = data.get("project", {})
        lines = list(project.get("dependencies", []))
        for extra in project.get("optional-dependencies", {}).values():
            lines += extra
        for group in data.get("dependency-groups", {}).values():
            lines += [g for g in group if isinstance(g, str)]
        lines += data.get("build-system", {}).get("requires", [])
    else:
        with open(path, encoding="utf-8") as f:
            lines = [l.split(" #", 1)[0] for l in f if l.strip() and not l.lstrip().startswith(("#", "-"))]
    for line in lines:
        r = requirement(line)
        if r:
            out.append(r)
    return out


def pypi_clauses(spec):
    clauses = []
    for c in filter(None, spec.split(",")):
        m = re.match(r"^(===|==|!=|~=|>=|<=|>|<)(.+)$", c)
        if not m:
            raise ValueError(f"unknown specifier {c}")
        clauses.append((m.group(1), m.group(2)))
    return clauses


def pypi_allows(clauses, v):
    for op, w in clauses:
        if op == "===":
            ok = ".".join(map(str, v)) == w
        elif w.endswith(".*"):
            prefix = version(w[:-2])
            ok = v[: len(prefix)] == prefix
            ok = ok if op == "==" else not ok
        else:
            c = compare(v, version(w))
            if op == "~=":
                prefix = version(w)[:-1]
                ok = c >= 0 and v[: len(prefix)] == prefix
            else:
                ok = {"==": c == 0, "!=": c != 0, ">=": c >= 0, "<=": c <= 0, ">": c > 0, "<": c < 0}[op]
        if not ok:
            return False
    return True


def pypi_outside(spec, newest):
    clauses = pypi_clauses(spec)
    if not clauses:
        return False
    if not pypi_allows(clauses, newest):
        return True
    floors = [version(w) for op, w in clauses if op in (">=", ">")]
    if floors and len(floors) == len(clauses):
        return newest[0] > max(floors)[0]
    return False


# ── cargo ──
def cargo(path):
    with open(path, "rb") as f:
        data = tomllib.load(f)
    tables = [data]
    tables += [t for t in data.get("target", {}).values() if isinstance(t, dict)]
    out = []
    for t in tables:
        for key in ("dependencies", "dev-dependencies", "build-dependencies"):
            for name, dep in t.get(key, {}).items():
                if isinstance(dep, str):
                    out.append((name, dep))
                elif isinstance(dep, dict) and "version" in dep:
                    out.append((dep.get("package", name), dep["version"]))
    return out


def cargo_bounds(req):
    """The [low, high) ranges of one comparator, as tuples of three."""
    req = req.strip()
    m = re.match(r"^(\^|~|=|>=|<=|>|<)?\s*([0-9*]+(?:\.[0-9*]+){0,2})$", req)
    if req == "*":
        return (0, 0, 0), None
    if not m:
        raise ValueError(f"unknown requirement {req}")
    op, text = m.group(1) or "^", m.group(2)
    parts = text.split(".")
    if "*" in parts:
        parts = parts[: parts.index("*")]
        op = "="
    nums = [int(p) for p in parts]
    full = tuple(nums + [0] * (3 - len(nums)))

    def bump(i):
        b = list(full[: i + 1])
        b[i] += 1
        return tuple(b + [0] * (3 - len(b)))

    if op == "^":
        i = next((k for k, n in enumerate(nums) if n != 0), len(nums) - 1)
        return full, bump(i)
    if op == "~":
        return full, bump(min(1, len(nums) - 1))
    if op == "=":
        return full, bump(len(nums) - 1) if nums else None
    if op in (">=", ">"):
        return (bump(2) if op == ">" else full), None
    return (0, 0, 0), (full if op == "<" else bump(2))


def cargo_outside(req, newest):
    v = tuple((list(newest) + [0, 0, 0])[:3])
    for comparator in filter(None, req.split(",")):
        low, high = cargo_bounds(comparator)
        if v < low or (high is not None and v >= high):
            return True
    return False


def main(argv):
    if argv[0] == "requirements":
        for name, spec in requirements(argv[1]):
            print(f"{name}\t{spec}")
    elif argv[0] == "cargo":
        for name, req in cargo(argv[1]):
            print(f"{name}\t{req}")
    elif argv[0] == "verdict":
        kind, spec = argv[1], argv[2]
        stable = sorted({version(l.strip()) for l in sys.stdin if STABLE.match(l.strip())})
        if not stable:
            print("\t0")
            return 0
        newest = stable[-1]
        outside = pypi_outside(spec, newest) if kind == "pypi" else cargo_outside(spec, newest)
        print(".".join(map(str, newest)) + "\t" + ("1" if outside else "0"))
    else:
        print(f"unknown command {argv[0]}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except (OSError, ValueError, tomllib.TOMLDecodeError) as e:
        print(f"manifests.py: {e}", file=sys.stderr)
        sys.exit(1)
