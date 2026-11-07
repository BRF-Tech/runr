# A file with a pinned version moved: see deps_replace_version (ci/deps/lib.sh). The file comes in
# on stdin or as the argument, the new file goes to stdout; "edit PATH:LINE" and "kept PATH:LINE:
# TEXT" lines go to the file `report`. Exit status 3: no line was edited.
#
#   awk -v old=42.3.0 -v new=42.3.1 -v names=traefik -v history=RE -v path=FILE -v report=LOG \
#     -f ci/deps/replace.awk FILE
#
# old is a whole version where the characters around it are no part of a longer one: not 142.3.0,
# 42.3.01 or 42.3.0.1; a leading v (v42.3.0, :v42.3.0) and prefixes such as traefik- are kept.

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
    if (tolower(s) ~ history) {
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
