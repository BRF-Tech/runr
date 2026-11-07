# The lines of `git grep -n` output (PATH:LINE:TEXT) of the console or the landing page that name
# the version old as a whole version and the dependency (names, on the line or in the path):
# "outside PATH:LINE: TEXT". A bump never edits them (deps_replace_version, ci/deps/lib.sh).

function whole(s,   pos, i, n, before, after, after2) {
  n = length(old)
  pos = 1
  while ((i = index(substr(s, pos), old)) > 0) {
    i = pos + i - 1
    before = (i > 1) ? substr(s, i - 1, 1) : ""
    after = substr(s, i + n, 1)
    after2 = substr(s, i + n + 1, 1)
    if (before !~ /[0-9A-Za-z.]/ && after !~ /[0-9A-Za-z]/ && !(after == "." && after2 ~ /[0-9]/)) return 1
    pos = i + n
  }
  return 0
}

{
  first = index($0, ":")
  rest = substr($0, first + 1)
  second = index(rest, ":")
  path = substr($0, 1, first - 1)
  num = substr(rest, 1, second - 1)
  text = substr(rest, second + 1)
  if (whole(text) && (tolower(path) ~ names || tolower(text) ~ names)) {
    sub(/^[ \t]+/, "", text)
    printf "outside %s:%s: %s\n", path, num, (length(text) > 160 ? substr(text, 1, 157) "..." : text)
  }
}
