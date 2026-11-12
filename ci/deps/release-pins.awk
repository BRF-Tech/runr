# The version pins of a RunR release: see ci/deps/release-pins.sh.
#
#   edit: awk -v mode=edit -v prev=0.6.8 -v new=0.6.9 -v cliprev=0.6.8 -v keep=FILE -v path=FILE \
#           -f ci/deps/release-pins.awk FILE > NEW            (exit 3: nothing edited)
#   left: git grep -n -I … | awk -v mode=left -v ver=0.6.8 -v clikeep=1 -v keep=FILE \
#           -f ci/deps/release-pins.awk                       (prints the lines that still pin ver)
#
# A pin is `v<prev>` (v0.6.8, @v0.6.8: not in a longer word) or a bare `<prev>` that is no part of a
# longer version (not 10.6.8, 0.6.80 or 0.6.8.1; 0.6.8-mt is one), as bump-pins.sh of the manual
# release has them. A line of the CLI (RUNR_VERSION, "CLI release") pins the CLI's release: it moves
# from cliprev, and stays when cliprev is empty (a dependency release builds no CLI). The literal
# strings of the keep file name a version as history (a measurement's label, a third-party version
# that happens to be the same): they stay byte for byte. README.tr.md's first line names the release
# its translation follows.

function is_word(c) { return c ~ /[A-Za-z0-9_]/ }

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
  edited = 0
}

mode == "edit" {
  line = $0
  if (FNR == 1 && path == "README.tr.md" && line ~ /^<!-- README\.md'nin/) { print line; next }
  s = protect(line)
  swapped = 0
  if (is_cli(s)) {
    if (cliprev != "") s = swap(s, cliprev, new)
  } else {
    s = swap(s, prev, new)
  }
  if (swapped) edited++
  print restore(s)
  next
}

# left: "PATH:LINE:TEXT" lines of git grep -n
mode == "left" {
  rec = $0
  c1 = index(rec, ":")
  p = substr(rec, 1, c1 - 1)
  r = substr(rec, c1 + 1)
  c2 = index(r, ":")
  n = substr(r, 1, c2 - 1)
  text = substr(r, c2 + 1)
  if (p == "README.tr.md" && n == 1) next
  # history, not a pin: "before 0.6.8", "0.6.8 and earlier", a comment line of program code
  if (index(text, "before " ver) || index(text, "before v" ver)) next
  if (p ~ /\.(php|js|mjs|cjs|ts|vue|go|py)$/ && text ~ /^[ \t]*(\*|\/\/|\/\*|#)/) next
  if (clikeep && is_cli(text)) next
  t = strip_keeps(text)
  re = ver
  gsub(/\./, "[.]", re)
  gsub("v?" re " (and|or) earlier", "", t)
  if (pins(t, ver)) print rec
  next
}

END {
  if (mode == "edit" && !edited) exit 3
}
