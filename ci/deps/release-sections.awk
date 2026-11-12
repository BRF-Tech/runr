# The sections a release writes into CHANGELOG.md and docs/upgrade.md: see ci/deps-release.sh.
#
#   release:  awk -v mode=release -v prev=0.6.8 -v new=0.6.9 -v date=2026-11-14 -v notes=FILE \
#               -f ci/deps/release-sections.awk CHANGELOG.md
#             [Unreleased] → "## [new] - date": the notes file ("### Upgrade notes" …) first, then
#             what [Unreleased] held; [Unreleased] stays, empty; the compare link moves to v<new> and
#             the link of [new] follows it
#   extract:  awk -v mode=extract -v heading='## [0.6.9] - ' -f … FILE
#             the section from the first line that starts with `heading` up to the next "## "
#             heading
#   wrap:     awk -v mode=wrap [-v width=100] -f …   (stdin: one paragraph per line)
#             each paragraph wrapped at width columns; a bullet ("- ") goes on indented by two
#   insert:   awk -v mode=insert -v before=REGEX -v section=FILE [-v prev=… -v new=…] -f … FILE
#             FILE with the section file inserted above its first line that matches before (a
#             CHANGELOG also gets the links of the release when prev and new are given)
#
# Exit status 1 with a message on stderr when the file is not as expected.

function die(msg) { print msg > "/dev/stderr"; failed = 1; exit 1 }

function emit(from, to,   i) { for (i = from; i <= to; i++) print lines[i] }

function readfile(f,   l, out, n) {
  out = ""
  n = 0
  while ((getline l < f) > 0) { out = out (n++ ? "\n" : "") l }
  close(f)
  return out
}

# the links of a release: [Unreleased] compares from v<new>, [new] names its tag
function links(i,   old, line) {
  old = "[Unreleased]: https://github.com/brf-tech/runr/compare/v" prev "...main"
  if (lines[i] != old) return 0
  print "[Unreleased]: https://github.com/brf-tech/runr/compare/v" new "...main"
  print "[" new "]: https://github.com/brf-tech/runr/releases/tag/v" new
  linked++
  return 1
}

{ lines[NR] = $0 }

END {
  if (failed) exit 1
  if (mode == "release") {
    for (i = 1; i <= NR; i++) if (lines[i] == "## [Unreleased]") { u = i; break }
    if (!u) die("no \"## [Unreleased]\" heading")
    e = 0
    for (i = u + 1; i <= NR; i++) if (lines[i] ~ /^## \[/) { e = i; break }
    if (!e) die("no release section after [Unreleased]")
    if (lines[e] !~ ("^## \\[" prev "\\] - ")) die("the section after [Unreleased] is not [" prev "]: " lines[e])
    bs = u + 1
    while (bs < e && lines[bs] ~ /^[ \t]*$/) bs++
    be = e - 1
    while (be >= bs && lines[be] ~ /^[ \t]*$/) be--
    if (be < bs) die("[Unreleased] is empty: the release has nothing to say")
    emit(1, u)
    print ""
    print "## [" new "] - " date
    print ""
    print readfile(notes)
    print ""
    emit(bs, be)
    print ""
    linked = 0
    for (i = e; i <= NR; i++) if (!links(i)) print lines[i]
    if (linked != 1) die("no single [Unreleased] compare link from v" prev)
    exit 0
  }
  if (mode == "wrap") {
    if (width == "") width = 100
    for (i = 1; i <= NR; i++) {
      if (lines[i] ~ /^[ \t]*$/) { print ""; continue }
      n = split(lines[i], words, " ")
      ind = (words[1] == "-") ? "  " : ""
      cur = ""
      for (k = 1; k <= n; k++) {
        if (cur == "") { cur = words[k]; continue }
        if (length(cur) + 1 + length(words[k]) > width) { print cur; cur = ind words[k] }
        else cur = cur " " words[k]
      }
      print cur
    }
    exit 0
  }
  if (mode == "extract") {
    s = 0
    for (i = 1; i <= NR; i++) if (index(lines[i], heading) == 1) { s = i; break }
    if (!s) die("no heading \"" heading "\"")
    t = NR
    for (i = s + 1; i <= NR; i++) if (lines[i] ~ /^## /) { t = i - 1; break }
    while (t > s && lines[t] ~ /^[ \t]*$/) t--
    emit(s, t)
    exit 0
  }
  if (mode == "insert") {
    at = 0
    for (i = 1; i <= NR; i++) if (lines[i] ~ before) { at = i; break }
    if (!at) die("no line matches " before)
    emit(1, at - 1)
    print readfile(section)
    print ""
    linked = 0
    for (i = at; i <= NR; i++) {
      if (prev != "" && new != "" && links(i)) continue
      print lines[i]
    }
    if (prev != "" && new != "" && linked != 1) die("no single [Unreleased] compare link from v" prev)
    exit 0
  }
  die("mode: release, extract or insert")
}
