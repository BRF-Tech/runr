# The weekly summary of the dependency bots: jq functions of ci/deps-digest.sh (docs/development.md
# → Dependency test bot → The weekly summary). Included with jq -L ci/deps 'include "digest"; …'.
#
# The format is shared with filex's dependency bot (the bot's name: runr-deps):
#   - one issue per ISO week (UTC) with movement, titled "deps: week 2026-W46", its body's last
#     line <!-- runr-deps:digest:2026-W46 -->;
#   - a table "Dependency | Version | State | Item | Note", one row per dependency, its first cell
#     `<id>` <!-- runr-deps:row:<id> -->: <id> is the package group, the chart or knative (one row
#     per group: a newer finding replaces it), <group>/<package> for a release outside the
#     constraint that a hold keeps;
#   - a bot's marker (summary, major versions, a run's issue) counts only as the body's last line:
#     one quoted in a person's text is none, and none of them is the deps-bot data marker;
#   - State: new | testing | green | green → shipped in vX.Y.Z | red | needs attention | held
#     (<reason>) | closed; Item: the issue a person acts in (#N), else the last run or release;
#     Note: security: <advisory ids> for a finding with an advisory, else why;
#   - closing: "Continued in #N." (open rows carried over), "Closed at the end of the week: nothing
#     was left open."; a folded issue: "Folded into the weekly summary #N: the bot opens a separate
#     issue only when a person has to do something.";
#   - one issue per group for its major versions: "deps: <group> major versions need a decision",
#     <!-- runr-deps:majors:<group> -->, "Dependency | Pinned | Newest major | allow".
# The bot edits bodies, it writes no comment on a summary. RunR keeps each row's data (its updates,
# fingerprint, found date, …) in one hidden <!-- deps-bot {…} --> per row above the summary's
# marker: {id, kind, group, version, state, …}
#   kind     group (a package group's minor and patch updates: version its fingerprint, updates,
#            urgent, advisories), chart (version), knative (version: its newest minor), held
#            (name, line, from, to, note: the hold's reason)
#   state    new | testing | green | landed (green, on next: ships with the next release) |
#            shipped (release, url) | red | needs attention | held | closed
#   issue    the issue the row lives in: item_kind "issue" (a finding a person acts on: a red bump,
#            its tests run there) or "incident" (a run that needs a person: the row waits)

def final_states: ["shipped", "closed"];
def is_final: (.state // "new") as $s | final_states | index($s) != null;
# a row someone (the bot or a person) still acts on
def is_open: (is_final | not) and .state != "held";
def lkey: "\(.id)|\(.version)";
def ikey: "\(.name) \(.line)";

def markers: [(. // "") | scan("<!-- deps-bot (\\{[^\n]*\\}) -->") | .[0] | fromjson? // empty];
def marker_text: "<!-- deps-bot " + (tojson | gsub("-->"; "--\\u003e")) + " -->";
# a body's last line: where a bot's own marker is (one quoted in the text is no marker)
def last_line: (. // "") | sub("\\s+$"; "") | split("\n") | last // "";
def digest_week: last_line | (capture("^<!-- runr-deps:digest:(?<w>[0-9]{4}-W[0-9]{2}) -->$")? | .w) // null;
def majors_group: last_line | (capture("^<!-- runr-deps:majors:(?<g>[a-z0-9-]+) -->$")? | .g) // null;
def is_incident: last_line == "<!-- runr-deps:incident -->";
def previous_of: [(. // "") | scan("Continued from #([0-9]+)\\.") | .[0] | tonumber] | .[0];
def dates_of: [(. // "") | capture("\\((?<monday>[0-9]{4}-[0-9]{2}-[0-9]{2}) to (?<sunday>[0-9]{4}-[0-9]{2}-[0-9]{2})")] | (.[0] // {});

def norm_issue: if . == null then null else (tostring | tonumber? // .) end;
def normline: .issue |= norm_issue | .state = (.state // "new") | .id = (.id // .group);

# versions: 1.10.2 > 1.9, v-prefix ignored; the numeric parts compare as numbers
def vparts: tostring | sub("^v"; "") | [splits("[.+-]")] | map(tonumber? // .);
def vnewer($a; $b): ($a | vparts) > ($b | vparts);
def plural($n; $word): "\($n) \($word)\(if $n == 1 then "" else "s" end)";
def knative_newest: [scan("1\\.[0-9]+")] | sort_by(vparts) | last;

# ── issues ───────────────────────────────────────────────────────────────────
# one issue of gh issue list --json number,title,state,labels,body,createdAt: what it is to the bots
def parse_issue:
  . as $i
  | ($i.title // "") as $t
  | ($i.body // "") as $b
  | ($b | markers) as $ms
  | {number: $i.number, state: ($i.state // "OPEN"), labels: [$i.labels[]?.name], title: $t,
     created: (($i.createdAt // "")[0:10]), body: $b}
  + (if ($b | digest_week) != null or ($t | test("^deps: week [0-9]{4}-W[0-9]{2}$")) then
       {kind: "digest", lines: [$ms[] | select(.group != null and .kind != null) | normline],
        meta: ({week: (($b | digest_week) // ($t | ltrimstr("deps: week "))), previous: ($b | previous_of)} + ($b | dates_of))}
     elif ($b | majors_group) != null or ($t | test("^deps: [a-z0-9-]+ major versions need a decision$")) then
       {kind: "majors", group: (($b | majors_group) // ($t | capture("^deps: (?<g>[a-z0-9-]+) ").g)),
        items: [$ms[] | select(.kind == "outside") | .items[]?]}
     elif ($b | is_incident) then {kind: "incident"}
     elif ($t | test("^deps: [a-z0-9-]+ \\[[0-9a-f]{8}\\]: ")) then
       ($t | capture("^deps: (?<group>[a-z0-9-]+) \\[(?<version>[0-9a-f]{8})\\]: ")) + {kind: "group", marker: ($ms[0] // null)}
     elif ($t | test("^deps: [a-z0-9-]+ .+ [^ ]+ released \\(outside .*\\)$")) then
       ($t | capture("^deps: (?<group>[a-z0-9-]+) (?<name>.+) (?<line>[^ ]+) released \\(outside (?<constraint>.*)\\)$"))
       | {kind: "outside-legacy", group, items: [{name, line, constraint}]}
     elif ($t | test("^deps: [a-z0-9-]+ v?[0-9][0-9A-Za-z.-]* released$")) then
       ($t | capture("^deps: (?<group>[a-z0-9-]+) (?<version>v?[0-9][0-9A-Za-z.-]*) released$")) + {kind: "chart"}
     elif ($t | test("^Knative 1\\.[0-9]+(, 1\\.[0-9]+)* released: update KNATIVE_K8S_COMPAT$")) then
       {kind: "knative", group: "knative", version: ($t | knative_newest)}
     elif ($t | test("^Knative 1\\.[0-9]+ is not in KNATIVE_K8S_COMPAT$")) then
       {kind: "knative-missing", group: "knative", version: ($t | capture("^Knative (?<v>1\\.[0-9]+)").v)}
     else {kind: "other"} end)
  | if (.kind | IN("group", "chart", "knative", "knative-missing")) then
      .id = .group
      | .key = lkey
      | .separate = (($ms[0].separate // false) == true)
      # a finding filed as an issue of its own before the weekly summary: folded into it
      | .loose = (.state == "OPEN" and (.separate | not) and (.kind | IN("group", "chart", "knative"))
                  and ([.labels[] | select(IN("needs attention", "deps: passed"))] | length == 0))
    else . end;

# ── findings (RELEASES_REPORT_DIR: {title, body} of each *.md) ───────────────
def parse_finding:
  .title as $t | (.body | markers) as $ms | ($ms[0] // {}) as $m | .body as $b
  | if ($m.kind // "") == "outside" then {type: "outside", item: ($m | del(.kind))}
    elif ($t | test("^deps: [a-z0-9-]+ \\[[0-9a-f]{8}\\]: ")) then
      ($t | capture("^deps: (?<group>[a-z0-9-]+) \\[(?<version>[0-9a-f]{8})\\]: "))
      + {type: "finding", kind: "group", title: $t, body: $b, updates: ($m.updates // []), urgent: ($m.urgent // false), advisories: ($m.advisories // [])}
    elif ($t | test("^deps: [a-z0-9-]+ .+ [^ ]+ released \\(outside .*\\)$")) then
      ($t | capture("^deps: (?<group>[a-z0-9-]+) (?<name>.+) (?<line>[^ ]+) released \\(outside (?<constraint>.*)\\)$"))
      | {type: "outside", item: .}
    elif ($t | test("^deps: [a-z0-9-]+ v?[0-9][0-9A-Za-z.-]* released$")) then
      ($t | capture("^deps: (?<group>[a-z0-9-]+) (?<version>v?[0-9][0-9A-Za-z.-]*) released$")) + {type: "finding", kind: "chart", title: $t, body: $b}
    elif ($t | test("^Knative 1\\.[0-9]+(, 1\\.[0-9]+)* released: update KNATIVE_K8S_COMPAT$")) then
      {type: "finding", kind: "knative", group: "knative", version: ($t | knative_newest), title: $t, body: $b}
    elif ($t | test("^Knative 1\\.[0-9]+ is not in KNATIVE_K8S_COMPAT$")) then
      {type: "finding", kind: "knative-missing", group: "knative", version: ($t | capture("^Knative (?<v>1\\.[0-9]+)").v), title: $t, body: $b}
    else empty end
  | if .type == "finding" then .id = .group else . end;

# a finding as a new row
def line_new($date):
  {id, kind, group, version, title, state: "new", found: $date}
  + (if .kind == "group" then {updates, urgent, advisories} else {} end);

# the marker of a finding's issue of its own (a bump that needs a person): the plan reads its
# group and updates as a package group's issue's
def sep_marker($date):
  {group, kind, version, separate: true, found: (.found // $date)}
  + (if .kind == "group" then {updates: (.updates // []), urgent: (.urgent // false), advisories: (.advisories // [])} else {} end);

# BODY with its deps-bot markers replaced by MARKER
def with_marker($m):
  ([splits("\n")] | map(select(test("^<!-- deps-bot ") | not)) | join("\n") | sub("\\s+$"; ""))
  + "\n\n" + ($m | marker_text) + "\n";
# BODY with TEXT added (an issue's closing text: the bot writes no comment), above a bot's marker
# that ends it (which stays the last line)
def appended($text):
  sub("\\s+$"; "") as $b
  | ($b | split("\n")) as $l
  | if ($l | last // "" | startswith("<!-- runr-deps:")) then ($l[:-1] | join("\n") | sub("\\s+$"; "")) + "\n\n" + $text + "\n\n" + ($l | last) + "\n"
    else $b + "\n\n" + $text + "\n" end;

def cell: tostring | gsub("\\|"; "\\|") | gsub("[\r\n]+"; " ");

# ── the issue of a group's major versions ───────────────────────────────────
def majors_title($g): "deps: \($g) major versions need a decision";
def majors_body($g; $items):
  "Major versions of **\($g)** (releases outside the constraint of its manifest or pin) need a decision: the dependency bot does not move them. For each one a person either moves it by hand with the code it needs (the bot then takes its minor and patch updates), or holds it with a written reason in `ci/deps/holds.txt` (it then leaves this list and the weekly summary shows it as `held (<reason>)`). The bot edits this list every day and closes the issue when it is empty; while the list is not empty, it reopens the issue when it is closed by hand.\n\n"
  + "| Dependency | Pinned | Newest major | allow |\n|---|---|---|---|\n"
  + ([$items | sort_by(.name)[] | "| `\(.name | cell)` | \(.from // "" | cell) | \(.to // .line | cell) | `\(.constraint // "" | cell)` |"] | join("\n"))
  + "\n\n`allow`: what the manifest or pin allows (its constraint). Reported by ci/check-package-releases.sh, kept by ci/deps-digest.sh (docs/development.md → Dependency test bot).\n\n"
  + ({group: $g, kind: "outside", items: ($items | sort_by(ikey))} | marker_text) + "\n"
  + "<!-- runr-deps:majors:\($g) -->\n";

# ── the summary's body ───────────────────────────────────────────────────────
def rank:
  {"red": 0, "needs attention": 0, "new": 1, "testing": 1, "green": 1, "landed": 2, "held": 3, "shipped": 4, "closed": 5}[.state // "new"] // 1;

def version_cell($max):
  if .kind == "group" then
    if (.updates // []) | length > 0 then
      (.updates) as $u
      | (if ($u | length) > 1 then "\($u | length) updates: " else "" end)
        + ([$u[:$max][] | "`\(.name)` \(.from) → \(.to)"] | join(", "))
        + (if ($u | length) > $max then " and \(($u | length) - $max) more" else "" end)
    else (.title // "" | sub("^deps: [a-z0-9-]+ \\[[0-9a-f]{8}\\]: "; "")) end
  elif .kind == "held" then "\(if (.from // "") != "" then .from else "?" end) → \(.to // .line)"
  else (.version | tostring) end;
def state_cell:
  if .state == "shipped" then "green → shipped in \(.release // "?")"
  elif .state == "landed" then "green"
  elif .state == "held" then "held (\(.note // "no reason written"))"
  else .state end;
def note_cell:
  [(if .urgent == true then "security: " + ([.advisories[]?.id] | unique | join(", ") | if . == "" then "an advisory" else . end) else empty end),
   (if .state == "landed" then "landed on next: ships with the next release" else empty end),
   (if .state != "held" and (.note // "") != "" then .note else empty end)] | join("; ");
def item_cell:
  if .issue != null then "#\(.issue)"
  elif (.url // "") != "" then "[\(if .state == "shipped" then (.release // "release") else "run" end)](\(.url))"
  else "" end;

def render($meta; $lines; $max; $closing):
  ($lines | sort_by(rank, .id)) as $ls
  | "What the dependency bots found and did in **\($meta.week)** (\($meta.monday // "?") to \($meta.sunday // "?"), UTC), one row per dependency. The bots edit this issue as they work and do not comment on it: `upstream-releases.yml` adds each finding once (daily), `deps-test.yml` tests the open rows (Saturdays; a security finding the same day) and writes here what became of them. A row links the issue where a person acts (a bump that went red, a test run that needs a person); a group's major versions have an issue of their own. Rows still open when a later week has movement move to that week's summary. To test again: **Actions → Dependency tests → Run workflow** with `issue` = this issue's number (its open rows) or `<number>:<id>` (one row). docs/development.md → Dependency test bot.\n\n"
  + (if $meta.previous != null then "Continued from #\($meta.previous).\n\n" else "" end)
  + (if ($ls | length) == 0 then "Nothing is open.\n"
     else "| Dependency | Version | State | Item | Note |\n|---|---|---|---|---|\n"
       + ([$ls[] | "| `\(.id | cell)` <!-- runr-deps:row:\(.id) --> | \(version_cell($max) | cell) | \(state_cell | cell) | \(item_cell | cell) | \(note_cell | cell) |"] | join("\n")) + "\n" end)
  + "\n" + ([$ls[] | marker_text] | join("\n")) + "\n"
  + (if $closing != null then "\n\($closing)\n" else "" end)
  + "\n<!-- runr-deps:digest:\($meta.week) -->\n";
# GitHub takes 65536 characters: a long summary names fewer updates per row
def render_fit($meta; $lines; $closing):
  render($meta; $lines; 8; $closing) as $b | if ($b | length) > 60000 then render($meta; $lines; 2; $closing) else $b end;

# ── state of a run: {lines, ops, digest, late, dispatch, dispatches, move, touched, gone} ──
def has_key($k): any(.lines[]; lkey == $k);
def has_id($id): any(.lines[]; .id == $id);
def touch($k): .touched += [$k];
# a row replaced: a newer finding of its dependency
def drop_id($id): .lines |= map(select(.id != $id));

# the week's summary: the open one edited; in a later week, the last one closes (its open rows
# carried over into the week's summary when the week has movement, "Continued in #N."; nothing
# open: "Closed at the end of the week: nothing was left open."), a quiet week opens none
# $cur: the open summary (parsed) or null; $others: more open ones (a race), merged and closed
def publish($cur; $others; $week):
  . as $s
  | ($s.lines | sort_by(.id)) as $now
  | ($s.touched) as $t
  | ([$now[] | select(is_open)] | length > 0) as $open
  | ($others | map({op: "edit", issue: .number, title: .title, body: render_fit(.meta; .lines; "Continued in #{{digest}}.")},
                    {op: "close", issue: .number, reason: "completed"})) as $merge
  | if $cur == null then
      .digest = (if $s.move and ($now | length) > 0 then
                   [{op: "create", key: "digest", title: "deps: week \($week.week)", labels: ["deps"], body: render_fit($week + {previous: null}; $now; null)}]
                 else [] end)
    elif ($cur.meta.week // "") == $week.week then
      .digest = [{op: "alias", key: "digest", issue: $cur.number}]
        + (if ($cur.lines | sort_by(.id)) != $now then [{op: "edit", issue: $cur.number, title: $cur.title, body: render_fit($cur.meta; $now; null)}] else [] end)
    elif $s.move then
      [$now[] | select((is_final | not) or (lkey | IN($t[])))] as $carry
      | .digest = [{op: "create", key: "digest", title: "deps: week \($week.week)", labels: ["deps"],
                    body: render_fit($week + {previous: (if $open then $cur.number else null end)}; $carry; null)}]
      | .late += [{op: "edit", issue: $cur.number, title: $cur.title,
                   body: render_fit($cur.meta; (if $open then $cur.lines else $now end);
                                    (if $open then "Continued in #{{digest}}." else "Closed at the end of the week: nothing was left open." end))},
                  {op: "close", issue: $cur.number, reason: "completed"}]
    elif $open | not then
      .digest = [{op: "alias", key: "digest", issue: $cur.number},
                 {op: "edit", issue: $cur.number, title: $cur.title, body: render_fit($cur.meta; $now; "Closed at the end of the week: nothing was left open.")},
                 {op: "close", issue: $cur.number, reason: "completed"}]
    else
      .digest = [{op: "alias", key: "digest", issue: $cur.number}]
        + (if ($cur.lines | sort_by(.id)) != $now then [{op: "edit", issue: $cur.number, title: $cur.title, body: render_fit($cur.meta; $now; null)}] else [] end)
    end
  | .late += $merge;

# the open summaries of the parsed issues: the newest is the current one, the others' open rows join it
def open_digests: [.[] | select(.kind == "digest" and .state == "OPEN")] | sort_by(.number) | reverse;
def start_lines($cur; $others):
  ($cur.lines // []) as $l
  | ([$l[] | .id]) as $ids
  | $l + [$others[] | .lines[] | select((is_final | not) and ((.id | IN($ids[])) | not))] | unique_by(.id);
def run_state($cur; $others):
  {lines: start_lines($cur; $others), ops: [], late: [], digest: [], dispatch: [], dispatches: [],
   incident: [], move: false, touched: [], gone: []};

# an incident (a run that needs a person) whose rows all moved on: closed
def close_incidents($all):
  . as $s
  | .late += [$all[] | select(.kind == "incident" and .state == "OPEN") | .number as $n
              | select(any($s.lines[]; .issue == $n) | not)
              | {op: "edit", issue: $n, title: .title, body: (.body | appended("Nothing it held is open any more: closed by the dependency bot."))},
                {op: "close", issue: $n, reason: "completed"}];

# the operations in order: issues of their own first, the summary, what points at it, the dispatch
def all_ops($dispatch_ref):
  .ops + .digest + .late
  + (if (.dispatch | length) > 0 then [{op: "dispatch", refs: (.dispatch | join(",")), attempt: "1", ref: $dispatch_ref}] else [] end)
  + .dispatches;

# ── file: the daily check's findings into the summary (upstream-releases.yml) ──
# input {findings: [{title, body}], held: [{group, name, version, line, from, constraint, reason}],
# checked: [group or chart], issues: [every issue, open and closed], date, week: {week, monday,
# sunday}, release: the version of the last release (helm/Chart.yaml's appVersion)}
def file_main:
  . as $in
  | ($in.date) as $date
  | ($in.issues | map(parse_issue)) as $all
  | ($all | open_digests) as $od
  | ($od[0] // null) as $cur
  | ($od[1:]) as $others
  | ($in.checked // []) as $checked
  | ([$all[] | select(.state == "OPEN") | .number]) as $open
  | ([$all[] | select(.kind | IN("group", "chart", "knative", "knative-missing")) | .key]
     + [$all[] | select(.kind == "digest") | .lines[] | lkey] | unique) as $known
  | ([$all[] | select(.state == "OPEN" and (.kind | IN("group", "chart", "knative", "knative-missing")) and (.loose | not)
                       and ((.labels | index("deps: passed")) == null))]) as $sep
  | ([$all[] | select(.loose == true)] | sort_by(.number)) as $loose
  | ([$in.findings[] | parse_finding]) as $parsed
  | ([$parsed[] | select(.type == "finding")] | unique_by(lkey)) as $F
  | ([$F[] | .id]) as $fids
  | ([$parsed[] | select(.type == "outside") | .item] | unique_by("\(.group) \(ikey)")) as $O
  | ($in.held // []) as $H
  | ([$F[] | select((lkey | IN($known[])) | not)]) as $new
  | run_state($cur; $others)

  # 1. a finding filed as an issue of its own before the summary: a row now (unless a newer finding
  #    of its dependency replaces it), its issue closed with the pointer in its body
  | reduce $loose[] as $l (.;
      ([$F[] | select(.id == $l.id and lkey != $l.key)] | length > 0) as $newer_f
      | ([$loose[] | select(.id == $l.id and .number > $l.number)] | length > 0) as $newer_l
      | .move = true
      | (if ($newer_f or $newer_l) or has_key($l.key) then .
         else drop_id($l.id)
           | .lines += [{id: $l.id, kind: $l.kind, group: $l.group, version: $l.version, title: $l.title, state: "new", found: $l.created}
                        + (if $l.kind == "group" then {updates: ($l.marker.updates // []), urgent: ($l.marker.urgent // false), advisories: ($l.marker.advisories // [])} else {} end)]
           | touch($l.key) end)
      | .late += [{op: "edit", issue: $l.number, title: $l.title,
                   body: ($l.body | appended("Folded into the weekly summary #{{digest}}: the bot opens a separate issue only when a person has to do something."))},
                  {op: "close", issue: $l.number, reason: "not planned"}])

  # 2. an issue of its own (a bump a person acts on) that a new finding of its dependency
  #    supersedes: closed, its row replaced below
  | reduce $sep[] as $s (.;
      ([$new[] | select(.id == $s.id and .kind == $s.kind and .kind != "knative-missing"
                        and (if .kind == "group" then true else vnewer(.version; $s.version) end))][0]) as $f
      | if $f == null then .
        else .gone += [$s.number] | .move = true
          | .late += [{op: "edit", issue: $s.number, title: $s.title,
                       body: ($s.body | appended("Superseded by \($f.id) \($f.version) (the check of \($date)), a row of the weekly summary #{{digest}}: the bot tests the newest finding only."))},
                      {op: "close", issue: $s.number, reason: "not planned"}] end)

  # 3. the findings: one an open issue holds keeps its row (pointing at it), one the summary has
  #    stays as it is, one any summary or issue had is not listed again; a new one replaces its
  #    dependency's row (a security finding is flagged and tested today); a Knative minor without
  #    its row is an issue for a person
  | reduce $F[] as $f (.;
      ($f | lkey) as $k
      | . as $st
      | ([$sep[] | select(.key == $k and ((.number | IN($st.gone[])) | not))][0]) as $s
      | if $s != null then
          (if has_key($k) then .
           else drop_id($f.id) | .lines += [$f | line_new($date) | .issue = $s.number | .item_kind = "issue"
                  | .state = (if ($s.labels | index("needs attention")) != null then "red" else "new" end)] end)
        elif has_key($k) or ($k | IN($known[])) then .
        elif $f.kind == "knative-missing" then
          .ops += [{op: "create", key: "knative-\($f.version)", title: $f.title, labels: ["deps", "needs attention"],
                    body: ($f.body | with_marker($f | sep_marker($date)))}]
        else
          .move = true | touch($k)
          | drop_id($f.id)
          | .lines += [$f | line_new($date)]
          | if $f.kind == "group" and $f.urgent then .dispatch += ["{{digest}}:\($f.id)"] else . end
        end)

  # 4. a group's major versions (releases outside the constraint no hold keeps): one issue per
  #    group, its list edited every day, closed when empty, reopened when closed by hand while the
  #    list is not empty; the issues of single releases folded into it
  | ([$O[].group] + [$all[] | select(.kind == "majors" and .state == "OPEN") | .group]
     + [$all[] | select(.kind == "outside-legacy" and .state == "OPEN") | .group] | unique) as $og
  | reduce $og[] as $g (.;
      ([$O[] | select(.group == $g)]) as $today
      | ([$all[] | select(.kind == "majors" and .group == $g)] | sort_by(.number) | reverse | .[0] // null) as $mi
      | ([$all[] | select(.kind == "outside-legacy" and .state == "OPEN" and .group == $g)] | sort_by(.number)) as $lg
      | ($checked | index($g) != null) as $chk
      | (($today + (if $chk then [] else (($mi.items // []) + [$lg[] | .items[]]) end)) | unique_by(ikey)) as $want
      | ("majors-\($g)") as $key
      | (if $mi != null then $mi.number else "{{\($key)}}" end) as $target
      | (if ($want | length) == 0 then
           (if $mi != null and $mi.state == "OPEN" then .late += [{op: "close", issue: $mi.number, reason: "completed"}] else . end)
         elif $mi == null then
           .ops += [{op: "create", key: $key, title: majors_title($g), labels: ["deps", "needs attention"], body: majors_body($g; $want)}]
         else
           (if $mi.state != "OPEN" then .ops += [{op: "reopen", issue: $mi.number}] else . end)
           | (if ($want | sort_by(ikey) | map({name, line, from, to, constraint})) != ($mi.items | sort_by(ikey) | map({name, line, from, to, constraint}))
                 or $mi.title != majors_title($g) then
                .ops += [{op: "edit", issue: $mi.number, title: majors_title($g), body: majors_body($g; $want)}] else . end)
         end)
      | reduce $lg[] as $l (.;
          .late += [{op: "edit", issue: $l.number, title: $l.title,
                     body: ($l.body | appended(if ($want | length) > 0 then "Folded into #\($target): the bot keeps one issue per group for its major versions, and opens a separate issue only when a person has to do something."
                                               else "No longer found by the check of \($date): moved by hand or held." end))},
                    {op: "close", issue: $l.number, reason: "not planned"}]))

  # 5. a major a hold keeps (with its reason): a row "held (<reason>)" and no issue; one of a
  #    checked group the check no longer reports leaves; a hold the summaries had is no movement
  | ([$H[] | "\(.group)/\(.name)"]) as $hids
  | reduce $H[] as $h (.;
      {id: "\($h.group)/\($h.name)", kind: "held", group: $h.group, name: $h.name, version: $h.line, line: $h.line,
       from: ($h.from // ""), to: $h.version, state: "held", note: $h.reason} as $nl
      | ($nl | lkey) as $k
      | if has_id($nl.id) then .lines |= map(if .id == $nl.id then . + $nl else . end)
        else .lines += [$nl] | (if ($k | IN($known[])) then . else .move = true | touch($k) end) end)
  | .lines |= map(select(.kind != "held" or (.group | IN($checked[]) | not) or (.id | IN($hids[]))))

  # 6. a finding the check of a read group no longer reports: landed on next and now in a release
  #    → shipped in it; one nobody acts on yet → closed
  | reduce (.lines[] | select((.kind | IN("group", "chart")) and .issue == null and (.group | IN($checked[])) and ((.id | IN($fids[])) | not))) as $r (.;
      if $r.state == "landed" then
        .lines |= map(if .id == $r.id then .state = "shipped" | .release = "v\($in.release)" | del(.note, .url) else . end)
        | .move = true | touch($r | lkey)
      elif ($r | is_open) then
        .lines |= map(if .id == $r.id then .state = "closed" | .note = "no longer found by the check of \($date)" else . end)
      else . end)

  # 7. a row whose issue was closed: closed (an incident's: back to new); an open issue of a
  #    finding without its row: pointed at; an incident none points at: closed
  | . as $st
  | .lines |= map(if (.issue | type) == "number" and ((.issue | IN($open[])) | not) and (.issue | IN($st.gone[]) | not) then
                    if .item_kind == "incident" then .state = "new" | .note = "#\(.issue) was closed" | del(.issue, .item_kind)
                    elif is_final then . else .state = "closed" | .note = "#\(.issue) was closed" end
                  else . end)
  | reduce ($sep[] | select(.kind != "knative-missing" and ((.number | IN($st.gone[])) | not))) as $s (.;
      if any(.lines[]; .issue == $s.number) or has_id($s.id) then .
      else .lines += [{id: $s.id, kind: $s.kind, group: $s.group, version: $s.version, title: $s.title, issue: $s.number, item_kind: "issue",
                       found: $s.created, state: (if ($s.labels | index("needs attention")) != null then "red" else "new" end)}
                      + (if $s.kind == "group" then {updates: ($s.marker.updates // []), urgent: ($s.marker.urgent // false), advisories: ($s.marker.advisories // [])} else {} end)] end)
  | close_incidents($all)

  | publish($cur; $others; $in.week)
  | all_ops(null);

# ── report: what the dependency test bot did, into the summary (deps-test.yml) ─
# input {issues: [the open issues], updates: [{ref, kind, group, version, state, mode, note, body,
# release, url}], actions: [ops on issues of their own], date, week, dispatch_ref}. A ref N:id is a
# row of the summary, N an issue of its own (its row mirrors it). mode: line, quiet (no movement: a
# run that starts, a bump job that failed), split (the row moves to an issue of its own, body its
# report), incident (the run needs a person: one issue for all such rows, body its text)
def report_main:
  . as $in
  | ($in.issues | map(parse_issue)) as $all
  | ($all | open_digests) as $od
  | ($od[0] // null) as $cur
  | ($od[1:]) as $others
  | run_state($cur; $others)
  | .ops += [$in.actions[] | select(.op != "dispatch")]
  | .dispatches = [$in.actions[] | select(.op == "dispatch") | .ref = $in.dispatch_ref]
  | reduce $in.updates[] as $u (.;
      ($u.ref | test(":")) as $dl
      | ($u.ref | split(":")[0] | tonumber) as $n
      | ({state: $u.state, note: (if ($u.note // "") != "" then $u.note else null end), url: (if ($u.url // "") != "" then $u.url else null end)}
         + (if $u.state == "shipped" then {release: $u.release} else {} end)) as $set
      | ([.lines | to_entries[] | select(.value as $l
           | if $dl then $l.id == $u.group and (($u.version // "") == "" or ($l.version | tostring) == $u.version)
             else $l.issue == $n end) | .key][0]) as $idx
      | (if $u.mode != "quiet" then .move = true else . end)
      | if $idx != null then
          .lines[$idx] |= (. + $set
            | if .note == null then del(.note) else . end | if .url == null then del(.url) else . end
            | if .state != "needs attention" and .item_kind == "incident" then del(.issue, .item_kind) else . end)
          | touch(.lines[$idx] | lkey)
          | if $u.mode == "split" and $dl then
              ("split-\($u.group)") as $key
              | (.lines[$idx]) as $line
              | .ops += [{op: "create", key: $key, title: ($line.title // "deps: \($u.group) \($u.version) released"),
                          labels: (["deps", "needs attention"] + (if $line.urgent == true then ["security"] else [] end)),
                          body: ((($u.body // "") + "\n\nFrom the weekly summary of \($in.week.week): a person has to do something.")
                                 | with_marker($line | sep_marker($in.date)))}]
              | .lines[$idx] |= (.issue = "{{\($key)}}" | .item_kind = "issue" | del(.url))
            elif $u.mode == "incident" and $dl then
              .incident = (.incident + [$u.body // ""] | unique)
              | .lines[$idx] |= (.issue = "{{incident}}" | .item_kind = "incident")
            else . end
        elif $dl then .
        else
          ([$all[] | select(.number == $n)][0]) as $i
          | if $i == null or ($i.id // null) == null or ($i.kind == "knative-missing") or has_id($i.id // "") then .
            else ({id: $i.id, kind: $i.kind, group: $i.group, version: $i.version, title: $i.title, issue: $n, item_kind: "issue", found: $i.created} + $set
                 | if .note == null then del(.note) else . end | if .url == null then del(.url) else . end) as $nl
              | .lines += [$nl] | touch($nl | lkey) end
        end)
  | (if (.incident | length) > 0 then
       ([.lines[] | select(.issue == "{{incident}}") | "`\(.id)`"] | join(", ")) as $rows
       | .ops += [{op: "create", key: "incident", title: "deps: a dependency test run needs a person (\($in.week.week))", labels: ["deps", "needs attention"],
                   body: ((.incident | join("\n\n---\n\n")) + "\n\nRows of the weekly summary it holds: \($rows). The dependency bot closes this issue when none of them waits for it any more.\n\n<!-- runr-deps:incident -->\n")}]
     else . end)
  | close_incidents($all)
  | publish($cur; $others; $in.week)
  | all_ops($in.dispatch_ref);
