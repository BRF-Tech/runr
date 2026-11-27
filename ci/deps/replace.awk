# A file with a pinned version moved: see deps_replace_version (ci/deps/lib.sh). The file comes in
# on stdin or as the argument, the new file goes to stdout; "edit PATH:LINE" and "kept PATH:LINE:
# TEXT" lines go to the file `report`. Exit status 3: no line was edited.
#
#   awk -v old=42.3.0 -v new=42.3.1 -v names=traefik -v history=RE -v measure=RE -v path=FILE \
#     -v report=LOG -f ci/deps/replace.awk FILE
#
# old is a whole version where the characters around it are no part of a longer one: not 142.3.0,
# 42.3.01 or 42.3.0.1; a leading v (v42.3.0, :v42.3.0) and prefixes such as traefik- are kept.
# A line of the past keeps it: one that history matches, a row of a table whose introducing
# paragraph speaks of a measurement (measure) and the paragraph right after such a table (a table
# of measured numbers names the versions they were measured with on rows of their own), and a line
# of a list item that records the past.

# replace_tokens S — S with every whole occurrence of old replaced by new; `replaced` counts them
function replace_tokens(s,   out, pos, i, n, before, before2, after, after2, ok) {
  n = length(old)
  out = ""
  pos = 1
  replaced = 0
  while ((i = index(substr(s, pos), old)) > 0) {
    i = pos + i - 1
    before = (i > 1) ? substr(s, i - 1, 1) : ""
    before2 = (i > 2) ? substr(s, i - 2, 1) : ""
    after = substr(s, i + n, 1)
    after2 = substr(s, i + n + 1, 1)
    ok = 1
    if (before ~ /[0-9A-Za-z.]/ && !(before == "v" && before2 !~ /[0-9A-Za-z.]/)) ok = 0
    if (after ~ /[0-9A-Za-z]/) ok = 0
    if (after == "." && after2 ~ /[0-9]/) ok = 0
    out = out substr(s, pos, i - pos) (ok ? new : old)
    if (ok) replaced++
    pos = i + n
  }
  return out substr(s, pos)
}

function trim(s) {
  sub(/^[ \t]+/, "", s)
  sub(/[ \t\r]+$/, "", s)
  return length(s) > 160 ? substr(s, 1, 157) "..." : s
}

# bare S — S without a comment's marker (//, #, *, --) and the spaces around it: an empty one is a
# line between paragraphs
function bare(s) {
  sub(/^[ \t]*(\/\/+|#+|\*+|--)?[ \t]*/, "", s)
  sub(/[ \t\r]+$/, "", s)
  return s
}

# par_start K, par_end K — the first and the last line of the paragraph of line K (the lines
# around it up to an empty one); above K — the last line of the paragraph above the one that
# starts at K (0: none)
function par_start(k) { while (k > 1 && bare(line[k - 1]) != "") k--; return k }
function par_end(k) { while (k < NR && bare(line[k + 1]) != "") k++; return k }
function above(k) { k--; while (k >= 1 && bare(line[k]) == "") k--; return k }
function is_row(k) { return bare(line[k]) ~ /^[|]/ }
function is_item(k) { return bare(line[k]) ~ /^([-+]|[0-9]+[.)])[ \t]/ }

# range_has A B RE — a line from A to B matches RE
function range_has(a, b, re,   j) {
  for (j = a; j <= b; j++) if (tolower(line[j]) ~ re) return 1
  return 0
}

# measured_table K — line K is a row of a table that a measurement's paragraph introduces
function measured_table(k,   p) {
  p = above(par_start(k))
  return p >= 1 && range_has(par_start(p), p, measure)
}

# measured I — line I records the past without saying so itself: a row of a table a measurement's
# paragraph introduces (README's cold starts: a row per Kata hypervisor), the paragraph right after
# such a table (its notes), or a line of a list item that records the past (a measurement entry of
# a comment)
function measured(i,   s, p, a, b) {
  s = par_start(i)
  if (measure != "") {
    if (is_row(i)) return measured_table(i)
    p = above(s)
    if (p >= 1 && is_row(p) && measured_table(p)) return 1
  }
  for (a = i; a > s && !is_item(a); a--) ;
  if (!is_item(a)) return 0
  for (b = i; b < par_end(i) && !is_item(b + 1); b++) ;
  return range_has(a, b, history)
}

{ line[NR] = $0 }

END {
  lower_path = tolower(path)
  for (i = 1; i <= NR; i++) {
    s = line[i]
    if (index(s, old) == 0) { print s; continue }
    t = replace_tokens(s)
    if (replaced == 0) { print s; continue }
    named = (lower_path ~ names)
    for (j = i - 3; !named && j <= i + 3; j++) {
      if (j >= 1 && j <= NR && tolower(line[j]) ~ names) named = 1
    }
    if (!named) { print s; continue }
    if (tolower(s) ~ history || measured(i)) {
      print s
      printf "kept %s:%d: %s\n", path, i, trim(s) >> report
      continue
    }
    print t
    printf "edit %s:%d\n", path, i >> report
    edits++
  }
  # 3: nothing edited (the caller keeps the file as it is, its last line end too)
  exit (edits > 0 ? 0 : 3)
}
