# A bump's line into the [Unreleased] section of a Keep a Changelog file: see deps_changelog_add
# (ci/deps/lib.sh).
#
#   awk -v key='- **Region Traefik chart ' -v line='- **Region Traefik chart 42.3.1** …' \
#     -f ci/deps/changelog.awk CHANGELOG.md > NEW
#
# The line is wrapped at 100 columns like the file's own bullets (continuation lines indented by
# two). It goes under "### Changed": after the subsection's last bullet, or in place of the bullet
# that starts with key; a missing subsection is created after "### Added", or first.

function wrap(text,   n, words, i, out, cur) {
  n = split(text, words, " ")
  out = ""
  cur = ""
  for (i = 1; i <= n; i++) {
    if (cur == "") { cur = words[i]; continue }
    if (length(cur) + 1 + length(words[i]) > 100) {
      out = out cur "\n"
      cur = "  " words[i]
    } else {
      cur = cur " " words[i]
    }
  }
  return out cur
}

function emit(from, to,   i) {
  for (i = from; i <= to; i++) print lines[i]
}

{ lines[NR] = $0 }

END {
  for (i = 1; i <= NR; i++) if (lines[i] ~ /^## \[Unreleased\]/) { u = i; break }
  if (!u) { print "no ## [Unreleased] section" > "/dev/stderr"; exit 1 }
  e = NR + 1
  for (i = u + 1; i <= NR; i++) if (lines[i] ~ /^## \[/) { e = i; break }
  bullet = wrap(line)

  c = 0
  for (i = u + 1; i < e; i++) if (lines[i] ~ /^### Changed[ \t]*$/) { c = i; break }

  if (c) {
    ce = e
    for (i = c + 1; i < e; i++) if (lines[i] ~ /^### /) { ce = i; break }
    bs = 0
    for (i = c + 1; i < ce; i++) if (index(lines[i], key) == 1) { bs = i; break }
    if (bs) {
      be = bs + 1
      while (be < ce && lines[be] ~ /^  /) be++
      emit(1, bs - 1)
      print bullet
      emit(be, NR)
      exit 0
    }
    last = c
    for (i = c + 1; i < ce; i++) if (lines[i] !~ /^[ \t]*$/) last = i
    emit(1, last)
    if (last == c) print ""
    print bullet
    emit(last + 1, NR)
    exit 0
  }

  a = 0
  for (i = u + 1; i < e; i++) if (lines[i] ~ /^### Added[ \t]*$/) { a = i; break }
  if (a) {
    ae = e
    for (i = a + 1; i < e; i++) if (lines[i] ~ /^### /) { ae = i; break }
    last = a
    for (i = a + 1; i < ae; i++) if (lines[i] !~ /^[ \t]*$/) last = i
    emit(1, last)
    print ""
    print "### Changed"
    print ""
    print bullet
    emit(last + 1, NR)
    exit 0
  }

  emit(1, u)
  print ""
  print "### Changed"
  print ""
  print bullet
  if (u + 1 <= NR && lines[u + 1] !~ /^[ \t]*$/) print ""
  emit(u + 1, NR)
}
