# The version pins of a RunR release: see ci/deps/release-pins.sh.
#
#   edit: awk -v mode=edit -v prev=0.6.8 -v new=0.6.9 -v cliprev=0.6.8 -v keep=FILE -v path=FILE \
#           TABLES -f ci/deps/release-pins.awk FILE > NEW    (exit 3: nothing edited)
#   left: awk -v mode=left -v ver=0.6.8 -v clikeep=1 -v keep=FILE -v places=FILE TABLES \
#           -f ci/deps/release-pins.awk FILE…                (the files that name ver: prints
#                                                             "PATH:LINE:TEXT" per line that still pins it)
#   TABLES: -v relimages="api ui …" -v ownimages="DIR IMAGE …" -v thirdnames=FILE (release-pins.sh)
#
# A pin is `v<prev>` (v0.6.8, @v0.6.8: not in a longer word) or a bare `<prev>` that is no part of a
# longer version (not 10.6.8, 0.6.80 or 0.6.8.1; 0.6.8-mt is one), as bump-pins.sh of the manual
# release has them. A line of the CLI (RUNR_VERSION, "CLI release") pins the CLI's release: it moves
# from cliprev, and stays when cliprev is empty (a dependency release builds no CLI). The literal
# strings of the keep file name a version as history (a measurement's label): they stay byte for
# byte. README.tr.md's first line names the release its translation follows.
#
# A third party's version that happens to be the release's is no pin (left): a dependency manifest's
# requirement of a package that is not RunR's own (go.mod and go.work, package.json, composer.json,
# requirements*.txt, pyproject.toml, Cargo.toml: terraform-provider-runr/go.mod's
# github.com/hashicorp/go-retryablehttp v0.7.8 at RunR 0.7.8), the toolchain's version there (go,
# toolchain, requires-python, rust-version), and the version at a place of the bot's pins of
# third-party tools (DEPS_PINS of ci/deps/packages.sh; `places`: "FILE<TAB>SLOT<TAB>NAME" per
# line). Each is taken out of its line before the check; whatever else the line names still counts.
# A package with "runr" as a word of its name (github.com/brf-tech/runr/cli, @runr/workflows,
# runr-workflows) is RunR's own: its requirement is checked. A manifest's own version is its
# package's, never a RunR release's (the release moves none). Lock files are not read at all.
# Everywhere else, in both modes, a third party's version is shielded (below: images, URLs, version
# keys, helm commands, names), and a comment line is history (left).

function is_word(c) { return c ~ /[A-Za-z0-9_]/ }

# ── a third party's version ───────────────────────────────────────────────────────────────────────

# own NAME — a package of RunR's own (or one a person tells apart: a third party's that is named so)
function own(name) { return tolower(name) ~ /(^|[^a-z0-9])runr([^a-z0-9]|$)/ }

# is_spec S — is S a version requirement (1.2.3, ^0.7.8, >=1.0 <2.0, 1.0.*, ~=3.1, 1.x || 2.x)?
function is_spec(s,   n, w, i, seen) {
  n = split(s, w, /[ \t]+/)
  seen = 0
  for (i = 1; i <= n; i++) {
    if (w[i] == "" || w[i] ~ /^([|][|]|-|,|as)$/) continue
    if (w[i] !~ /^[~^<>=!]*v?[0-9xX*][0-9A-Za-z.*+_-]*(@[A-Za-z]+)?,?$/) return 0
    seen = 1
  }
  return seen
}

# go_mod S — a line of go.mod or go.work without the versions of other modules than RunR's own
# (require, replace and exclude, in a block or not) and without Go's (go, toolchain); retract names
# the module's own versions
function go_mod(s,   n, w, t, m, i, out, keyword) {
  sub(/\/\/.*/, "", s)
  n = split(s, w, /[ \t]+/)
  m = 0
  for (i = 1; i <= n; i++) if (w[i] != "") t[++m] = w[i]
  if (m && (t[1] == "go" || t[1] == "toolchain")) return ""
  out = ""
  for (i = 1; i <= m; i++) {
    # a module and its version (a keyword, a parenthesis or => is none)
    keyword = t[i] ~ /^(module|require|replace|exclude|retract|tool|ignore|godebug|use|[(]|[)]|=>)$/
    if (i < m && t[i + 1] ~ /^v[0-9]/ && !keyword && !own(t[i])) {
      i++
      continue
    }
    out = out " " t[i]
  }
  return out
}

# json_deps S — a line of package.json or composer.json without the requirements of other packages
# than RunR's own ("vue": "^3.5.0", "php": "^8.2", "node": ">=20", "packageManager": "npm@10.9.0",
# "x": "npm:other@1.0.0"), and the package's own "version" (a package's, never a RunR release's)
function json_deps(s, data,   out, pair, key, val, pkg, at) {
  out = ""
  while (match(s, /"[^"]*"[ \t]*:[ \t]*"[^"]*"/)) {
    pair = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    key = pair
    sub(/^"/, "", key)
    sub(/".*/, "", key)
    val = pair
    sub(/"$/, "", val)
    sub(/.*"/, "", val)
    pkg = key
    sub(/^npm:/, "", val)
    # NAME@SPEC (packageManager, an npm alias): NAME's requirement
    if (match(val, /^(@[A-Za-z0-9._-]+\/)?[A-Za-z][A-Za-z0-9._-]*@/)) {
      at = RLENGTH
      pkg = substr(val, 1, at - 1)
      val = substr(val, at + 1)
    }
    # in another JSON file only a third party's name is a package (an object's "version" goes too)
    if (key != "version" && (!is_spec(val) || own(pkg) || (data && !is_name(pkg)))) out = out pair
  }
  return out s
}

# pip_req S — a line of a requirements file without the requirement of another package than RunR's
# own (transformers==5.23.0 ; python_version >= "3.11"); its comment stays
function pip_req(s,   name, rest) {
  if (s !~ /^[ \t]*[A-Za-z0-9]/) return s
  name = s
  sub(/^[ \t]*/, "", name)
  sub(/[^A-Za-z0-9._-].*/, "", name)
  if (own(name)) return s
  rest = ""
  if (match(s, /[ \t]#/)) rest = substr(s, RSTART)
  return rest
}

# toml_deps S — a line of pyproject.toml or Cargo.toml without the requirements of other packages
# than RunR's own: PEP 508 strings ("httpx>=0.27", "pytest") in dependencies, optional and group
# dependencies and build-system requires; a dependency table's NAME = "SPEC" or NAME = { … } (Cargo,
# Poetry); the toolchain's requires-python and rust-version; a version = line (the package's own).
function toml_deps(s,   key, val, q, out, name) {
  if (match(s, /^[ \t]*[A-Za-z0-9_.-]+[ \t]*=[ \t]*/)) {
    key = substr(s, RSTART, RLENGTH)
    val = substr(s, RSTART + RLENGTH)
    sub(/^[ \t]*/, "", key)
    sub(/[ \t]*=.*/, "", key)
    if (key == "name") return s
    if (key == "version") return ""
    if (key == "requires-python" || key == "rust-version") return ""
    if (val ~ /^[{]/) return own(key) ? s : ""
    if (val ~ /^["']/) {
      q = substr(val, 2)
      sub(/["'].*/, "", q)
      if (is_spec(q)) return own(key) ? s : ""
    }
  }
  out = ""
  while (match(s, /"[A-Za-z][A-Za-z0-9._-]*[ \t]*(\[[A-Za-z0-9,._ -]*\])?[ \t]*([<>=!~;@(][^"]*)?"/)) {
    name = substr(s, RSTART + 1, RLENGTH - 2)
    sub(/[^A-Za-z0-9._-].*/, "", name)
    out = out substr(s, 1, RSTART - 1) (own(name) ? substr(s, RSTART, RLENGTH) : "\"\"")
    s = substr(s, RSTART + RLENGTH)
  }
  return out s
}

# manifest PATH S — S without the third parties' versions of the dependency manifest PATH
function manifest(p, s,   b) {
  b = p
  sub(/.*\//, "", b)
  if (b == "go.mod" || b == "go.work") return go_mod(s)
  if (b == "package.json" || b == "composer.json") return json_deps(s, 0)
  if (b ~ /^requirements.*[.]txt$/) return pip_req(s)
  if (b == "pyproject.toml" || b == "Cargo.toml") return toml_deps(s)
  # another JSON file's requirements and versions, also of a package.json it holds as a string
  # (api/resources/runtimes/builtin.json's templates: \"vite\": \"^8.3.0\", \"version\": \"1.0.0\")
  if (b ~ /[.]json$/) {
    gsub(/\\"/, "\"", s)
    return json_deps(s, 1)
  }
  return s
}

# cut_from S I RE — S without the match of RE (anchored with ^) that starts at I
function cut_from(s, i, re,   rest) {
  rest = substr(s, i)
  if (!match(rest, re)) return s
  return substr(s, 1, i - 1) substr(rest, RLENGTH + 1)
}

# place S SLOT NAME — S without the version a place of the bot's pins holds, as deps_pin_read of
# ci/deps/packages.sh reads it: ARG NAME=V (a Dockerfile), NAME: V (a workflow), NAME=V (a shell
# script), V right after the text NAME (AT)
function place(s, slot, name,   len, i, j, pos, out) {
  len = length(name)
  if (slot == "ARG") {
    if (match(s, /^[ \t]*ARG[ \t]+/) && substr(s, RLENGTH + 1, len + 1) == name "=")
      return cut_from(s, RLENGTH + len + 2, "^[^ \t]+")
    return s
  }
  if (slot == "YAML") {
    if (match(s, /^[ \t]*(-[ \t]+)?/) && substr(s, RLENGTH + 1, len + 1) == name ":") {
      i = RLENGTH + len + 2
      while (substr(s, i, 1) ~ /[ \t"']/ && i <= length(s)) i++
      return cut_from(s, i, "^[^\"' \t#]+")
    }
    return s
  }
  out = ""
  pos = 1
  while ((i = index(substr(s, pos), name)) > 0) {
    i = pos + i - 1
    j = i + len
    if (slot == "SH") {
      if ((i > 1 && is_word(substr(s, i - 1, 1))) || substr(s, j, 1) != "=") {
        out = out substr(s, pos, j - pos)
        pos = j
        continue
      }
      j++
      if (substr(s, j, 1) ~ /["']/) j++
      out = out substr(s, pos, j - pos)
      if (match(substr(s, j), /^[^"' \t;]+/)) j += RLENGTH
    } else {
      out = out substr(s, pos, j - pos)
      if (match(substr(s, j), /^[0-9A-Za-z][0-9A-Za-z.+_-]*/)) j += RLENGTH
    }
    pos = j
  }
  return out substr(s, pos)
}

# places_of PATH S — S without the versions the bot's pin places of PATH hold
function places_of(p, s,   k) {
  for (k = 1; k <= nplace; k++) if (pfile[k] == p) s = place(s, pslot[k], pname[k])
  return s
}

# ── a third party's version anywhere: shielded, in both modes ──────────────────────────────────────
# Before the release's pins move (edit) or are looked for (left), a version that belongs to
# something else than a RunR release is shielded (a placeholder), and put back afterwards:
#   - in an image: the tag of an image that is no release image of RunR (`relimages`,
#     DEPS_DOCKERFILES' release rows: api, ui, agent, queue-consumer): a third party's, a mirror of
#     reqs/, one of RunR's own images with a version of its own (image-upload:1.0.0, hf-serve:1.1.1),
#     a tag with a prefix (text-embeddings-inference:cpu-1.9.4); FROM golang:1.27.4, image: x:1.2.3;
#   - in a URL that is not RunR's (brf-tech/runr, runr.sh): …/releases/download/v0.26.0/…;
#   - the value of the version key of something else: a key with a version word (version, tag,
#     since, default) and words that name the thing (devicePluginChartVersion, RUNR_ESO_CHART_VERSION
#     — RUNR_ is the settings' namespace —, KUBECTL_1_37_VERSION, go-version, --kube-version), or a
#     key that names nothing in a context that does: its YAML parents (supplyChain.policyController.
#     chartVersion, inputs.k8s.default), a PHP file or an own image's directory (CertManager.php's
#     CHART_VERSION, scramble.php's 'version', runtime/image-upload's ARG VERSION=), a Markdown
#     table's key cell (| `ai.gpu.devicePluginChartVersion` | `"0.17.0"` |), a program's or data
#     file's object (a generic version key there is the object's: a package, a client, a server).
#     A key that opens the line owns all of it (a template's default). A key of RunR's own (runr,
#     app, api, ui, cli, console, software, release, platform, edition) or one that names nothing
#     (outside those contexts) is RunR's; so is a line of prose that names RunR;
#   - `helm … --version V` of a chart other than runr;
#   - right after the name of a third party (`names`: the bot's tables of dependencies, the
#     packages of the tracked package.json and requirements files, DEPS_NAMED of
#     ci/deps/packages.sh), or of a chart other than runr (repo/chart): "Kind v0.33.0",
#     "containerd-shim-spin-v0.27.2", "`axios` 1.21.0 → 1.21.1", "--k8s=1.37.0", "Kubernetes
#     version (1.37.0)", "CloudNativePG (chart 0.26.0", "jetstack/cert-manager v1.21.2";
#   - an SVG path's numbers (d="…").

function shield_tok(tok) {
  shv[++shn] = tok
  return "\002" shn "\002"
}

# shield_vers S — S with every version in it shielded
function shield_vers(s,   out) {
  out = ""
  while (match(s, /v?[0-9]+[.][0-9]+[0-9A-Za-z.+_-]*/)) {
    out = out substr(s, 1, RSTART - 1) shield_tok(substr(s, RSTART, RLENGTH))
    s = substr(s, RSTART + RLENGTH)
  }
  return out s
}

function unshield(s,   k, ph, p) {
  for (k = shn; k >= 1; k--) {
    ph = "\002" k "\002"
    while ((p = index(s, ph)) > 0) s = substr(s, 1, p - 1) shv[k] substr(s, p + length(ph))
  }
  return s
}

# key_words K — K's words, lower case (devicePluginChartVersion, AI_GPU_X, go-version)
function key_words(k,   i, c, p, out) {
  out = ""
  p = ""
  for (i = 1; i <= length(k); i++) {
    c = substr(k, i, 1)
    if (c ~ /[A-Z]/ && p ~ /[a-z0-9]/) out = out " "
    out = out c
    p = c
  }
  out = tolower(out)
  gsub(/[^a-z0-9]+/, " ", out)
  return out
}

# names K — what K names besides a version: its words but the version words, the generic ones and
# numbers (a leading runr of more words is the settings' namespace); `vkey`: K has a version word
function names(k,   n, w, i, out) {
  n = split(key_words(k), w, " ")
  out = ""
  vkey = 0
  for (i = 1; i <= n; i++) {
    if (w[i] ~ /^(version|versions|ver|tag|tags|since|default|toolchain|trigger)$/) { vkey = 1; continue }
    if (w[i] == "" || w[i] ~ /^[0-9]+$/) continue
    if (w[i] ~ /^(chart|charts|image|images|min|minimum|max|maximum|pinned|pin|current|latest|installed|running|from|to|new|old|target|base|the|of)$/) continue
    out = out " " w[i]
  }
  if (out ~ /^ runr ./) out = substr(out, 6)
  return out
}

function own_names(q) { return q ~ /(^| )(runr|app|application|api|ui|console|cli|software|release|platform|edition)( |$)/ }

# names_runr S — does S name RunR (not as the RUNR_ namespace of a setting)?
function names_runr(s,   t) {
  t = tolower(s)
  gsub(/runr_[a-z0-9_]+/, "", t)
  return t ~ /(^|[^a-z0-9_-])runr([^a-z0-9_]|$)/
}

# third_key K CTX — is K the version key of something else than a RunR release? CTX: what the
# key's context names, for a key that names nothing
function third_key(k, ctx,   q) {
  q = names(k)
  if (!vkey) return 0
  if (q == "") q = ctx
  return q != "" && !own_names(q)
}

# is_name W — is W a third party's name (or a chart other than runr: repo/chart)?
function is_name(w,   t) {
  t = tolower(w)
  sub(/^[-]+/, "", t)
  if (t ~ /^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/) return !own(t)
  # a Go module (github.com/spinframework/spin-go-sdk/v2 v2.2.1)
  if (t ~ /^[a-z0-9-]+([.][a-z0-9-]+)+\/[a-z0-9._\/-]+$/) return !own(t)
  if ((t in third) && !own(t)) return 1
  # a path's last part (/src/patches/containerd-shim-spin-v0.27.2.patch), an own image's name
  # (runr-image-upload)
  if (t ~ /\// && t !~ /brf-tech\/runr$/) sub(/.*\//, "", t)
  if ((t in third) && !own(t)) return 1
  sub(/^runr-/, "", t)
  return (t in relimg) ? 0 : (t in third)
}

# keys S CTX — S with the versions of other things' version keys shielded
function keys(s, ctx,   out, n, kw, k, lead, m, i, rest, cell, q, v, line) {
  line = s
  if (match(s, /^[ \t]*(-[ \t]+)?(((export|const|public|private|protected|static|final|readonly|var|let|local|declare|ARG|ENV)[ \t]+)*)["']?[A-Za-z_][A-Za-z0-9_.-]*["']?[ \t]*(:=|=>|=|:)/)) {
    lead = substr(s, 1, RLENGTH)
    rest = substr(s, RLENGTH + 1)
    k = lead
    sub(/[ \t]*(:=|=>|=|:)$/, "", k)
    gsub(/["']/, "", k)
    n = split(k, kw, /[ \t]+/)
    k = kw[n]
    if (third_key(k, ctx) || (is_name(k) && !own_names(names(k)))) return lead shield_vers(rest)
  }
  # a Markdown table's row of keys: its first cell's (| `ai.gpu.devicePluginChartVersion` | …,
  # | `RUNR_POLICY_CONTROLLER_HELM_REPO` / `_CHART_VERSION` | …)
  if (match(s, /^[ \t]*[|][^|]*[|]/)) {
    cell = substr(s, 1, RLENGTH)
    rest = substr(s, RLENGTH + 1)
    q = ""
    v = 0
    while (match(cell, /`[A-Za-z_.][A-Za-z0-9_.-]*`/)) {
      k = substr(cell, RSTART + 1, RLENGTH - 2)
      cell = substr(cell, RSTART + RLENGTH)
      n = names(k)
      if (vkey) v = 1
      if (q == "" && n != "") q = n
    }
    if (v && q != "" && !own_names(q)) return substr(s, 1, length(s) - length(rest)) shield_vers(rest)
  }
  # a line of prose that names a third party's version key and not RunR
  # (`chartVersion` (`RUNR_POLICY_CONTROLLER_CHART_VERSION`, default `0.10.5`))
  if (!names_runr(line)) {
    m = line
    while (match(m, /`[A-Za-z_.][A-Za-z0-9_.-]*`/)) {
      k = substr(m, RSTART + 1, RLENGTH - 2)
      m = substr(m, RSTART + RLENGTH)
      if (third_key(k, "")) return shield_vers(s)
    }
  }
  # a Helm template's line of a third party's value ({{- if … (has $collectorChart (list "0.175.1")) }})
  if (index(line, "{{") && !names_runr(line)) {
    m = line
    while (match(m, /([$]|[.]Values[.])[A-Za-z_][A-Za-z0-9_.]*/)) {
      k = substr(m, RSTART, RLENGTH)
      m = substr(m, RSTART + RLENGTH)
      sub(/^[$]|^[.]Values[.]/, "", k)
      n = names(k)
      if (n != "" && !own_names(n)) return shield_vers(s)
    }
  }
  # a key anywhere and the version right after it (KEY: V, KEY=V, 'KEY' => 'V', env('KEY', 'V'),
  # --flag V); in a program's or data file's object a key that names nothing is the object's
  out = ""
  while (match(s, /[A-Za-z_][A-Za-z0-9_.-]*\\?["']?[ \t]*(:=|=>|=|:-|:|,|>|[ \t])[ \t]*\\?["']?v?[0-9]+[.][0-9]/)) {
    m = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    k = m
    sub(/\\?["']?[ \t]*(:=|=>|=|:-|:|,|>|[ \t]).*/, "", k)
    # a flag's value only after a space (--kube-version 1.22.0), not a word of prose
    if (m ~ /^[A-Za-z_][A-Za-z0-9_.-]*[ \t]+\\?["']?v?[0-9]/ && substr(out, length(out) - 1) != "--") k = ""
    # > only of an XML element (<version>1.0.0</version>), not an attribute's end (…="footer-version">v0.7.8)
    if (m ~ /^[A-Za-z_][A-Za-z0-9_.-]*\\?["']?[ \t]*>/ && substr(out, length(out)) != "<") k = ""
    # the match ends with the version's first characters: the version starts there
    match(m, /v?[0-9]+[.][0-9]$/)
    i = RSTART
    out = out substr(m, 1, i - 1)
    s = substr(m, i) s
    match(s, /^v?[0-9]+[.][0-9]+[0-9A-Za-z.+_-]*/)
    m = substr(s, 1, RLENGTH)
    s = substr(s, RLENGTH + 1)
    # a program's output is data ("stdout": "2.2.3\n")
    out = out (((k != "" && third_key(k, objctx)) || k ~ /^(stdout|stderr)$/) ? shield_tok(m) : m)
  }
  return out s
}

function is_relimage(name) { sub(/^runr-/, "", name); return (name in relimg) }

# images S — S with the versions in the tags of images that are no release image of RunR shielded
function images(s,   out, ref, i, j, name, lead, tag, rest) {
  out = ""
  while (match(s, /[A-Za-z0-9<{$…][A-Za-z0-9._<>{}$…-]*(:[0-9]+)?(\/[A-Za-z0-9._-]+)+:[A-Za-z0-9_.{}-]*[0-9]+[.][0-9][0-9A-Za-z.+_-]*/)) {
    ref = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    for (i = length(ref); i > 0 && substr(ref, i, 1) != ":"; i--) ;
    name = substr(ref, 1, i - 1)
    sub(/.*\//, "", name)
    out = out (is_relimage(name) ? ref : substr(ref, 1, i) shield_vers(substr(ref, i + 1)))
  }
  s = out s
  # an image without a registry where the line names one (FROM, image:)
  if (match(s, /^[ \t]*(FROM[ \t]+(--platform=[^ \t]+[ \t]+)?|(-[ \t]+)?["']?image["']?[ \t]*:[ \t]*["']?)[A-Za-z0-9._-]+:[^ \t"']+/)) {
    lead = substr(s, 1, RLENGTH)
    rest = substr(s, RLENGTH + 1)
    for (j = length(lead); j > 0 && substr(lead, j, 1) != ":"; j--) ;
    name = substr(lead, 1, j - 1)
    sub(/.*[ \t"']/, "", name)
    tag = substr(lead, j + 1)
    if (!is_relimage(name)) s = substr(lead, 1, j) shield_vers(tag) rest
  }
  return s
}

# urls S — S with the versions in URLs that are not RunR's shielded
function urls(s,   out, u) {
  out = ""
  while (match(s, /[a-z][a-z0-9+.-]*:\/\/[^ \t"'<>()`]+/)) {
    u = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    out = out ((tolower(u) ~ /brf-tech\/runr|runr[.]sh/) ? u : shield_vers(u))
  }
  return out s
}

# paths S — S with an SVG path's numbers (d="…", points="…") and an OID (urn:oid:0.9.2342.…)
# shielded
function paths(s,   out, a) {
  out = ""
  while (match(s, /((^|[ \t])(d|points|viewBox)="[^"]*"|urn:oid:[0-9.]+)/)) {
    a = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    out = out shield_tok(a)
  }
  return out s
}

# helm_version S — S with `--version V` shielded where the helm command's chart is not runr
function helm_version(s,   n, w, i, t, chart, other, out, m, j) {
  if (s !~ /(^|[^A-Za-z0-9_-])helm[ \t]+(install|upgrade|template|pull|fetch|show)/) return s
  n = split(s, w, /[ \t]+/)
  other = 0
  for (i = 1; i <= n; i++) {
    t = w[i]
    gsub(/[`"'(),;]/, "", t)
    if (t ~ /^oci:\/\/[^ ]+$/ || t ~ /^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/) {
      chart = t
      sub(/.*\//, "", chart)
      if (chart == "runr") return s
      other = 1
    }
  }
  if (!other) return s
  out = ""
  while (match(s, /--version([ \t]+|=)["'`]?v?[0-9]+[.][0-9][0-9A-Za-z.+_-]*/)) {
    m = substr(s, RSTART, RLENGTH)
    out = out substr(s, 1, RSTART - 1)
    s = substr(s, RSTART + RLENGTH)
    for (j = 10; j <= length(m) && substr(m, j, 1) !~ /[v0-9]/; j++) ;
    out = out substr(m, 1, j - 1) shield_tok(substr(m, j))
  }
  return out s
}

# products S — S with a version right after a third party's name shielded, one right after a
# shielded one (`axios` 1.21.0 → 1.21.1, v0.27.2-runr.2: v0.27.2) and a RunR build of an upstream
# release (v0.27.2-runr.2, the shim's own version)
function products(s,   out, tok, pre, w, cut) {
  out = ""
  while (match(s, /v?[0-9]+[.][0-9]+[0-9A-Za-z.+_-]*/)) {
    pre = out substr(s, 1, RSTART - 1)
    tok = substr(s, RSTART, RLENGTH)
    s = substr(s, RSTART + RLENGTH)
    if (tok ~ /-runr[.][0-9]+$/) {
      out = pre shield_tok(tok)
      continue
    }
    # what precedes the version: a name, then separators, "chart", "version", "(", "`", a
    # Markdown link's target ([driver.js](https://driverjs.com/) v1.4.0)
    w = pre
    do {
      cut = w
      sub(/[ \t`'"(=:@\/_|*<>^~\\,-]+$/, "", w)
      sub(/\]\([^()]*\)$/, "", w)
      # an Actions pin's commit (uses: shivammathur/setup-php@<sha> # 2.40.0)
      sub(/@[0-9a-f]+[ \t]*#$/, "", w)
      if (w ~ /(^|[^A-Za-z0-9_-])(chart|version|versions|than|older|newer)$/) sub(/(chart|version|versions|than|older|newer)$/, "", w)
    } while (w != cut)
    if (w ~ /\002[0-9]+\002(→|->)?$/ || w ~ /\002[0-9]+\002[ \t]*(→|->)$/) {
      out = pre shield_tok(tok)
      continue
    }
    match(w, /[A-Za-z0-9][A-Za-z0-9._\/-]*$/)
    w = (RSTART ? substr(w, RSTART) : "")
    out = pre (w != "" && is_name(w) ? shield_tok(tok) : tok)
  }
  return out s
}

# shield S CTX — S with every third party's version shielded (shn counts them; unshield)
function shield(s, ctx) { return products(keys(helm_version(images(urls(paths(s)))), ctx)) }

# yaml_line S — the YAML parents of line S (yind/ykey, ydepth deep): `yctx`, what the nearest
# parent that names something names
function yaml_line(s,   ind, rest, k, i, q) {
  yctx = ""
  if (s ~ /^[ \t]*(#.*)?\r?$/) return
  match(s, /^[ \t]*(-[ \t]+)?/)
  ind = RLENGTH
  rest = substr(s, RLENGTH + 1)
  while (ydepth > 0 && yind[ydepth] >= ind) ydepth--
  for (i = ydepth; i >= 1; i--) {
    q = names(ykey[i])
    if (q != "") { yctx = q; break }
  }
  if (match(rest, /^["']?[A-Za-z0-9_.-]+["']?[ \t]*:([ \t]|\r?$)/)) {
    k = substr(rest, 1, RLENGTH)
    sub(/[ \t]*:.*/, "", k)
    gsub(/["']/, "", k)
    ydepth++
    yind[ydepth] = ind
    ykey[ydepth] = k
  }
}

# swap S OLD NEW — S with every pin of OLD replaced by NEW; `swapped` counts them
function swap(s, o, n,   out, pos, i, len, b, b2, a, rest, ok) {
  len = length(o)
  out = ""
  pos = 1
  while ((i = index(substr(s, pos), o)) > 0) {
    i = pos + i - 1
    b = (i > 1) ? substr(s, i - 1, 1) : ""
    b2 = (i > 2) ? substr(s, i - 2, 1) : ""
    a = substr(s, i + len, 1)
    rest = substr(s, i + len)
    ok = 0
    # v<old> as a word of its own (bump-pins.sh: \bv<old>\b)
    if (b == "v" && !is_word(b2) && !is_word(a)) ok = 1
    # a bare <old> that is no part of a longer version ((?<![\w.])<old>(?![\w.]*\d))
    if (b !~ /[A-Za-z0-9_.]/ && rest !~ /^[A-Za-z0-9_.]*[0-9]/) ok = 1
    out = out substr(s, pos, i - pos) (ok ? n : o)
    if (ok) swapped++
    pos = i + len
  }
  return out substr(s, pos)
}

# pins S V — does S still pin V? (bump-pins.sh: \bv<V>\b, or <V> without a digit or dot before it
# and a digit after it)
function pins(s, v,   pos, i, len, b, b2, a) {
  len = length(v)
  pos = 1
  while ((i = index(substr(s, pos), v)) > 0) {
    i = pos + i - 1
    b = (i > 1) ? substr(s, i - 1, 1) : ""
    b2 = (i > 2) ? substr(s, i - 2, 1) : ""
    a = substr(s, i + len, 1)
    if (b == "v" && !is_word(b2) && !is_word(a)) return 1
    if (b !~ /[0-9.]/ && a !~ /[0-9]/) return 1
    pos = i + len
  }
  return 0
}

function is_cli(s) { return s ~ /RUNR_VERSION|[Cc][Ll][Ii] release/ }

# protect S — S with every keep string as a placeholder (restore puts them back)
function protect(s,   k, p) {
  for (k = 1; k <= nkeep; k++) {
    while ((p = index(s, keeps[k])) > 0) s = substr(s, 1, p - 1) "\001" k "\001" substr(s, p + length(keeps[k]))
  }
  return s
}
function restore(s,   k, ph, p) {
  for (k = nkeep; k >= 1; k--) {
    ph = "\001" k "\001"
    while ((p = index(s, ph)) > 0) s = substr(s, 1, p - 1) keeps[k] substr(s, p + length(ph))
  }
  return s
}
function strip_keeps(s,   k, p) {
  for (k = 1; k <= nkeep; k++) {
    while ((p = index(s, keeps[k])) > 0) s = substr(s, 1, p - 1) substr(s, p + length(keeps[k]))
  }
  return s
}

BEGIN {
  nkeep = 0
  if (keep != "") {
    while ((getline l < keep) > 0) {
      sub(/\r$/, "", l)
      if (l == "" || l ~ /^#/) continue
      keeps[++nkeep] = l
    }
    close(keep)
  }
  nplace = 0
  if (places != "") {
    while ((getline l < places) > 0) {
      sub(/\r$/, "", l)
      if (split(l, f, "\t") != 3 || f[1] == "" || f[3] == "") continue
      nplace++
      pfile[nplace] = f[1]
      pslot[nplace] = f[2]
      pname[nplace] = f[3]
    }
    close(places)
  }
  n = split(relimages, f, " ")
  for (i = 1; i <= n; i++) relimg[f[i]] = 1
  # "DIR IMAGE" per word pair: the directory of an image of RunR's own with a version of its own
  nown = split(ownimages, f, " ")
  for (i = 1; i + 1 <= nown; i += 2) owndir[f[i]] = f[i + 1]
  # the third parties' names, one per line
  if (thirdnames != "") {
    while ((getline l < thirdnames) > 0) {
      sub(/\r$/, "", l)
      if (l != "") third[tolower(l)] = 1
    }
    close(thirdnames)
  }
  objctx = ""
  edited = 0
}

mode == "edit" {
  line = $0
  if (FNR == 1 && path == "README.tr.md" && line ~ /^<!-- README\.md'nin/) { print line; next }
  shn = 0
  s = shield(protect(line), "")
  swapped = 0
  if (is_cli(s)) {
    if (cliprev != "") s = swap(s, cliprev, new)
  } else {
    s = swap(s, prev, new)
  }
  if (swapped) edited++
  print restore(unshield(s))
  next
}

# left: the files that name ver; prints "PATH:LINE:TEXT" for each line that still pins it
mode == "left" && FNR == 1 {
  p = FILENAME
  ydepth = 0
  # what a key that names nothing is of: a PHP file's name (CertManager.php's CHART_VERSION), the
  # image of an own image's directory (runtime/image-upload's ARG VERSION=, var version =)
  fctx = ""
  if (p ~ /\.php$/) {
    fctx = p
    sub(/.*\//, "", fctx)
    sub(/[.]php$/, "", fctx)
    fctx = names(fctx)
  }
  for (d in owndir) if (index(p, d "/") == 1) fctx = names(owndir[d])
  if (own_names(fctx)) fctx = ""
  # a program's or data file's object: a key there that names nothing is the object's
  objctx = (p ~ /\.(json|js|mjs|cjs|ts|tsx|vue|go|php|py)$/) ? " object" : ""
  # its comment lines: #, //, /* … */ (the history; prose of a Markdown file is no comment)
  cre = ""
  if (p ~ /\.(php|js|mjs|cjs|ts|tsx|vue|go|css|scss|tf|hcl)$/) cre = "^[ \t]*(\\*|//|/\\*|#)"
  else if (p ~ /\.(py|sh|bash|ya?ml|toml|conf|ini|cfg|rb|mk)$/ || p ~ /(^|\/)(Dockerfile[^\/]*|Makefile|Caddyfile[^\/]*|[^\/]*\.Dockerfile)$/) cre = "^[ \t]*#"
  hctx = ""
}
mode == "left" {
  text = $0
  # a Terraform variable's or output's block names what its default is of (variable "web_version"
  # { default = "1.4.2" }); a resource of RunR's provider names nothing
  if (text ~ /^[ \t]*(variable|output)[ \t]+"[^"]+"/) {
    hctx = text
    sub(/^[^"]*"/, "", hctx)
    sub(/".*/, "", hctx)
    hctx = names(hctx)
    if (own_names(hctx)) hctx = ""
  } else if (text ~ /^[ \t]*(resource|data|module|provider|locals)[ \t]/ || text ~ /^}[ \t\r]*$/) hctx = ""
  yctx = (hctx != "") ? hctx : fctx
  if (p ~ /\.ya?ml$/) yaml_line(text)
  if (!index(text, ver)) next
  if (p == "README.tr.md" && FNR == 1) next
  # history, not a pin: "before 0.6.8", "0.6.8 and earlier", a comment line
  if (index(text, "before " ver) || index(text, "before v" ver)) next
  if (cre != "" && text ~ cre) next
  if (clikeep && is_cli(text)) next
  shn = 0
  t = shield(places_of(p, manifest(p, strip_keeps(text))), yctx)
  # a PHP file that names a third party (KnativeVersionHelper.php, ExternalSecretsStep.php): its
  # versions are that party's (RunR's own reaches the API through its settings, never a literal)
  if (p ~ /\.php$/ && fctx != "") t = shield_vers(t)
  re = ver
  gsub(/\./, "[.]", re)
  gsub("v?" re " (and|or) earlier", "", t)
  if (pins(t, ver)) print p ":" FNR ":" text
  next
}

END {
  if (mode == "edit" && !edited) exit 3
}
