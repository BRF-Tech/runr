#!/usr/bin/env python3
"""Builds the runr.sh site. Driven by ci/build-runr-sh.sh, which runs it in two phases:

  prepare  picks the documentation pages (docs/*.md, the comparison pages docs/compare/*.md, the
           root Markdown files and every Markdown page they link to), applies the site's text
           substitutions and writes the pandoc inputs
  render   turns the pandoc output into pages (links between pages rewritten to runr.sh URLs, links
           to unpublished repository files to GitHub at the release tag (at main when the tag
           does not have the file yet), images under landing/ to
           the site root and other repository images to GitHub raw at the tag; Open Graph, Twitter
           card and breadcrumb metadata), adds the landing in English (/) and Turkish (/tr/), the
           downloads (CLI, Terraform provider, signing key), the Terraform provider registry and the
           sitemap, checks every internal link and writes a deterministic ZIP

Only the standard library is used. Paths in the arguments are local paths.
"""

import argparse
import hashlib
import html
import json
import os
import posixpath
import re
import shutil
import sys
import time
import zipfile

SITE = "https://runr.sh"
REPO = "https://github.com/BRF-Tech/runr"
NAMESPACE, PROVIDER = "brf-tech", "runr"
MAX_ENTRIES = 5000                 # StaticSites ZIP limits (brf-mono config staticsites.zip)
MAX_BYTES = 512 * 1024 * 1024

ROOT_PAGES = ["README.md", "CHANGELOG.md", "SECURITY.md", "CONTRIBUTING.md", "CODE_OF_CONDUCT.md",
              "LICENSE", "compose/README.md"]

SPECIAL_URLS = {
    "docs/README.md": "/docs/",
    "README.md": "/docs/overview/",
    "CHANGELOG.md": "/docs/changelog/",
    "SECURITY.md": "/security/",
    "CONTRIBUTING.md": "/docs/contributing/",
    "CODE_OF_CONDUCT.md": "/docs/code-of-conduct/",
    "LICENSE": "/license/",
    "compose/README.md": "/docs/compose/",
    "runtime/README.md": "/docs/runtime-images/",
    "actions/deploy/README.md": "/docs/github-action/action/",
    "docs/compare/README.md": "/compare/",
}

# Comparison pages: docs/compare/<name>.md at /compare/<name>/, the directory's README at /compare/.
COMPARE_DIR = "docs/compare"

# Titles for pages whose first heading does not name them (the README's is the logo).
TITLES = {
    "/docs/overview/": "Overview and quick start",
    "/docs/runtime-images/": "Runtime images and ci.sh",
}

SITE_NAME = "RunR"
OG_IMAGE = SITE + "/assets/og-image.png"
OG_IMAGE_ALT = "RunR: open source serverless platform on Kubernetes, with a view of the console's project list"

# Site-only text substitutions: (pages, regex, replacement), for text that reads differently on
# runr.sh than in the repository. Each rule must match at least once, so a changed source fails
# the build instead of slipping through. None at the moment.
SUBSTITUTIONS = []

REPO_URL = re.compile(r"^https?://github\.com/brf-tech/runr(?:\.git)?(?P<rest>[/#?].*)?$", re.I)
LINK_MD = re.compile(r"\]\(\s*<?([^)\s>]+)>?(?:\s+\"[^\"]*\")?\s*\)")
LINK_REF = re.compile(r"^\s{0,3}\[[^\]]+\]:\s*<?(\S+?)>?(?:\s+\"[^\"]*\")?\s*$", re.M)
A_TAG = re.compile(r"<a\s+([^>]*?)>(.*?)</a>", re.S)
HREF = re.compile(r'href="([^"]*)"')
HEADING = re.compile(r'<(h[1-6])(\s+id="([^"]+)")?([^>]*)>(.*?)</\1>', re.S)


def die(msg):
    print("build.py: " + msg, file=sys.stderr)
    sys.exit(1)


def url_for(path):
    """URL of a published repository file (posix path relative to the repository root)."""
    if path in SPECIAL_URLS:
        return SPECIAL_URLS[path]
    if path.startswith("docs/") and path.count("/") == 1 and path.endswith(".md"):
        return "/docs/" + path[5:-3] + "/"
    if path.startswith(COMPARE_DIR + "/") and path.count("/") == 2 and path.endswith(".md"):
        return "/compare/" + path[len(COMPARE_DIR) + 1:-3] + "/"
    prefix = "terraform-provider-runr/docs/"
    if path.startswith(prefix) and path.endswith(".md"):
        rest = path[len(prefix):-3]
        return "/docs/terraform/reference/" if rest == "index" else "/docs/terraform/reference/" + rest + "/"
    if path.endswith("/README.md") and path.split("/")[0] in ("examples", "sdk"):
        return "/docs/" + path[:-len("/README.md")] + "/"
    return None


def page_id(path):
    return re.sub(r"[^A-Za-z0-9]+", "_", path).strip("_")


def link_targets(text):
    for m in LINK_MD.finditer(text):
        yield m.group(1)
    for m in LINK_REF.finditer(text):
        yield m.group(1)


def resolve_repo_target(src, page, target):
    """A link in `page` → (repository path or None, anchor). Absolute repository URLs count."""
    target = html.unescape(target)
    m = REPO_URL.match(target)
    if m:
        rest = m.group("rest") or ""
        if rest.startswith("#"):
            return "README.md", rest[1:]
        parts = rest.split("/")
        # /blob/<ref>/<path> or /tree/<ref>/<path>
        if len(parts) >= 4 and parts[1] in ("blob", "tree"):
            path, _, anchor = "/".join(parts[3:]).partition("#")
            return normalize_repo_path(src, path), anchor
        return None, ""
    if re.match(r"^[a-z][a-z0-9+.-]*:", target) or target.startswith("#") or target.startswith("/"):
        return None, ""
    path, _, anchor = target.partition("#")
    path = path.split("?")[0]
    if not path:
        return None, anchor
    joined = posixpath.normpath(posixpath.join(posixpath.dirname(page), path))
    if joined.startswith(".."):
        return None, anchor
    return normalize_repo_path(src, joined), anchor


def normalize_repo_path(src, path):
    path = path.strip("/")
    full = os.path.join(src, *path.split("/")) if path else src
    if os.path.isdir(full):
        readme = (path + "/README.md").lstrip("/")
        return readme if os.path.isfile(os.path.join(src, *readme.split("/"))) else None
    return path if os.path.isfile(full) else None


def repo_file_target(src, page, target):
    """A relative link in `page` to a repository file or directory → (kind, path, anchor), kind
    "blob" or "tree"; (None, path, anchor) when nothing exists there, None for other links."""
    target = html.unescape(target)
    if re.match(r"^[a-z][a-z0-9+.-]*:", target) or target.startswith(("#", "/")):
        return None
    path, _, anchor = target.partition("#")
    path = path.split("?")[0]
    if not path:
        return None
    joined = posixpath.normpath(posixpath.join(posixpath.dirname(page), path))
    if joined.startswith("..") or joined == ".":
        return None
    full = os.path.join(src, *joined.split("/"))
    kind = "tree" if os.path.isdir(full) else "blob" if os.path.isfile(full) else None
    return kind, joined, anchor


def collect_pages(src):
    docs = sorted("docs/" + f for f in os.listdir(os.path.join(src, "docs")) if f.endswith(".md"))
    compare = os.path.join(src, *COMPARE_DIR.split("/"))
    if os.path.isdir(compare):
        docs += sorted(COMPARE_DIR + "/" + f for f in os.listdir(compare) if f.endswith(".md"))
    queue = [p for p in docs + ROOT_PAGES if os.path.isfile(os.path.join(src, *p.split("/")))]
    pages = []
    seen = set()
    while queue:
        page = queue.pop(0)
        if page in seen or url_for(page) is None:
            continue
        seen.add(page)
        pages.append(page)
        if not page.endswith(".md"):
            continue
        text = read(os.path.join(src, *page.split("/")))
        for target in link_targets(text):
            path, _ = resolve_repo_target(src, page, target)
            if path and path not in seen and url_for(path):
                queue.append(path)
    urls = {}
    for p in pages:
        u = url_for(p)
        if u in urls:
            die("two pages map to %s: %s and %s" % (u, urls[u], p))
        urls[u] = p
    return pages


def read(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="\n") as f:
        f.write(text)


# ---------------------------------------------------------------------------------------------
# prepare


def cmd_prepare(a):
    pages = collect_pages(a.src)
    md_dir = os.path.join(a.work, "md")
    os.makedirs(md_dir, exist_ok=True)
    used = [0] * len(SUBSTITUTIONS)
    for page in pages:
        if not page.endswith(".md"):
            continue
        text = read(os.path.join(a.src, *page.split("/"))).replace("\r\n", "\n")
        for i, (where, pattern, repl) in enumerate(SUBSTITUTIONS):
            if page in where:
                text, n = re.subn(pattern, repl, text)
                used[i] += n
        write(os.path.join(md_dir, page_id(page) + ".md"), text)
    for i, n in enumerate(used):
        if n == 0:
            die("substitution %r matched nothing; update SUBSTITUTIONS" % SUBSTITUTIONS[i][1])
    write(os.path.join(a.work, "pages.json"), json.dumps(pages, indent=1))
    print("prepare: %d pages" % len(pages))


# ---------------------------------------------------------------------------------------------
# render: pages


def strip_tags(s):
    return html.unescape(re.sub(r"<[^>]+>", "", s)).strip()


TAG_FILES = None


def github_ref(rel, version):
    """The GitHub ref a repository path is linked at: the release tag, or `main` for a path the
    tag's tree does not have (a file added after the release; `main` has it). Without
    --tag-files every path links to the tag."""
    if TAG_FILES is None or rel in TAG_FILES or any(f.startswith(rel + "/") for f in TAG_FILES):
        return "v" + version
    return "main"


def rewrite_links(src, page, body, urls, version, stats):
    """Links between published pages → runr.sh URLs; links to repository files that are not
    published → the file (blob) or directory (tree) on GitHub at the release tag; repository URLs
    stay links. A relative link to nothing in the repository is shown as text."""
    def repl(m):
        attrs, inner = m.group(1), m.group(2)
        hm = HREF.search(attrs)
        if not hm:
            return m.group(0)
        href = html.unescape(hm.group(1))
        if href.startswith("#") or href.startswith("mailto:"):
            return m.group(0)
        new = None
        repo = REPO_URL.match(href)
        if repo:
            rest = repo.group("rest") or ""
            if re.match(r"^/releases/?$|^/releases/(latest|download)\b", rest):
                new = "/dl/"
            elif rest.startswith("/security/policy"):
                new = "/security/"
        if new is None:
            path, anchor = resolve_repo_target(src, page, href)
            if path and path in urls:
                new = urls[path] + ("#" + anchor if anchor else "")
            elif not repo:
                local = repo_file_target(src, page, href)
                if local and local[0]:
                    kind, rel, anchor = local
                    new = "%s/%s/%s/%s" % (REPO, kind, github_ref(rel, version), rel) + ("#" + anchor if anchor else "")
                    stats["github"].append("%s -> %s" % (page, new))
                elif not re.match(r"^[a-z][a-z0-9+.-]*:", href):
                    stats["unlinked"].append("%s -> %s" % (page, href))
                    return '<span class="d-nolink">' + inner + "</span>"
        if new is None:
            return m.group(0)
        stats["rewritten"] += 1
        return "<a " + HREF.sub('href="%s"' % html.escape(new, quote=True), attrs, count=1) + ">" + inner + "</a>"
    return A_TAG.sub(repl, body)


RAW = "https://raw.githubusercontent.com/brf-tech/runr"
IMG_SRC = re.compile(r'\s(src|srcset)="([^"]*)"')


def rewrite_images(src, page, body, version, stats):
    """Relative image paths (`<img src>`, `<source srcset>`, Markdown images rendered by pandoc)
    → the site: a file under landing/ is copied to the site root by copy_landing, so it is served
    from there; any other repository file comes from GitHub raw at the release tag. A path to
    nothing in the repository is left as it is, for check_links to report."""
    def one(path):
        if not path or re.match(r"^[a-z][a-z0-9+.-]*:", path) or path.startswith(("/", "#", "data:")):
            return path
        rel = posixpath.normpath(posixpath.join(posixpath.dirname(page), path))
        if rel.startswith("..") or not os.path.isfile(os.path.join(src, *rel.split("/"))):
            return path
        stats["images"] += 1
        if rel.startswith("landing/"):
            return "/" + rel[len("landing/"):]
        return "%s/%s/%s" % (RAW, github_ref(rel, version), rel)

    def repl(m):
        attr, value = m.group(1), html.unescape(m.group(2))
        if attr == "srcset":
            parts = [c.strip().split(None, 1) for c in value.split(",") if c.strip()]
            new = ", ".join(" ".join([one(p[0])] + p[1:]) for p in parts)
        else:
            new = one(value)
        return ' %s="%s"' % (attr, html.escape(new, quote=True))
    return IMG_SRC.sub(repl, body)


def add_anchors(body):
    def repl(m):
        tag, ident, rest, inner = m.group(1), m.group(3), m.group(4), m.group(5)
        if not ident or tag in ("h1",):
            return m.group(0)
        return '<%s id="%s"%s>%s<a class="d-anchor" href="#%s" aria-label="Link to this section">#</a></%s>' % (
            tag, ident, rest, inner, ident, tag)
    return HEADING.sub(repl, body)


def sidebar(src, urls):
    """Groups and pages of docs/README.md, as (group, [(title, url)])."""
    text = read(os.path.join(src, "docs", "README.md"))
    groups, current = [], None
    for line in text.splitlines():
        if line.startswith("## "):
            current = (line[3:].strip(), [])
            groups.append(current)
        elif current and line.startswith("| [") and "](" in line:
            m = re.match(r"\|\s*\[([^\]]+)\]\(([^)]+)\)", line)
            if not m:
                continue
            path, anchor = resolve_repo_target(src, "docs/README.md", m.group(2))
            if path in urls:
                current[1].append((m.group(1).replace("`", ""), urls[path] + ("#" + anchor if anchor else "")))
    return [g for g in groups if g[1]]


def render_sidebar(groups, url):
    out = ['<aside class="d-side"><details open><summary>Documentation</summary>',
           '<ul><li><a href="/docs/"%s>Index</a></li><li><a href="/docs/overview/"%s>Overview and quick start</a></li></ul>' % (
               ' aria-current="page"' if url == "/docs/" else "",
               ' aria-current="page"' if url == "/docs/overview/" else "")]
    for name, items in groups:
        out.append("<h3>%s</h3><ul>" % html.escape(name))
        for title, u in items:
            cur = ' aria-current="page"' if u == url else ""
            out.append('<li><a href="%s"%s>%s</a></li>' % (html.escape(u), cur, html.escape(title)))
        out.append("</ul>")
    out.append("</details></aside>")
    return "\n".join(out)


DOCS_CRUMB = ("Documentation", "/docs/")
COMPARE_CRUMB = ("Comparisons", "/compare/")

# Umami (the web server adds its script to every page) counts clicks on elements with
# data-umami-event; data-umami-event-<name> attributes become the event's properties.
def umami(event, **props):
    return ' data-umami-event="%s"' % event + "".join(
        ' data-umami-event-%s="%s"' % (k, html.escape(v, quote=True)) for k, v in props.items())


def social_meta(title, desc, url, og_type):
    """Open Graph and Twitter card tags of a page, with the landing's social image."""
    e = lambda s: html.escape(s, quote=True)
    return "\n".join([
        '<meta property="og:type" content="%s">' % og_type,
        '<meta property="og:site_name" content="%s">' % SITE_NAME,
        '<meta property="og:title" content="%s">' % e(title),
        '<meta property="og:description" content="%s">' % e(desc),
        '<meta property="og:url" content="%s%s">' % (SITE, url),
        '<meta property="og:image" content="%s">' % OG_IMAGE,
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="630">',
        '<meta property="og:image:alt" content="%s">' % e(OG_IMAGE_ALT),
        '<meta property="og:locale" content="en_US">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="%s">' % e(title),
        '<meta name="twitter:description" content="%s">' % e(desc),
        '<meta name="twitter:image" content="%s">' % OG_IMAGE,
    ]) + "\n"


def breadcrumb_jsonld(crumb, name, url):
    """BreadcrumbList JSON-LD: the section (Documentation, Comparisons) → the page."""
    doc = {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": 1, "name": crumb[0], "item": SITE + crumb[1]},
        {"@type": "ListItem", "position": 2, "name": name, "item": SITE + url}]}
    return '<script type="application/ld+json">%s</script>\n' % json.dumps(
        doc, ensure_ascii=False).replace("</", "<\\/")


def page_html(title, desc, url, content, version, side=None, nav=None, crumb=None, name=None, og_type="article"):
    """A page of the site. crumb: (label, URL) of the section the page belongs to, shown above
    the page and published as BreadcrumbList JSON-LD with `name` (default: the title)."""
    nav_items = [("/docs/", "Docs", ""), ("/dl/", "Download", umami("docs-download-click", place="nav")),
                 ("/security/", "Security", ""), (REPO, "GitHub", umami("docs-github-click", place="nav"))]
    nav_html = "".join('<a href="%s"%s%s>%s</a>' % (u, ' aria-current="page"' if nav == u else "", ev, t)
                       for u, t, ev in nav_items)
    crumb_html = '<p class="d-crumb"><a href="%s">%s</a></p>' % (crumb[1], crumb[0]) if crumb else ""
    wrap = "d-wrap" if side else "d-wrap d-single"
    head_extra = social_meta(title, desc, url, og_type)
    if crumb:
        head_extra += breadcrumb_jsonld(crumb, name or title, url)
    return """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<link rel="canonical" href="{site}{url}">
{head_extra}<meta name="theme-color" content="#0a0a0a">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/css/docs.css">
{script}</head>
<body>
<header class="d-top"><div class="d-top-in">
<a class="d-brand" href="/" aria-label="RunR home"><img class="icon" src="/assets/runr-icon.svg" alt=""><img class="wordmark" src="/assets/runr-wordmark.svg" alt="RunR"></a>
<span class="d-ver">v{version}</span>
<nav class="d-nav">{nav}</nav>
</div></header>
<div class="{wrap}">
{side}
<main class="d-main">
{crumb}<article class="d-doc">
{content}
</article>
<p class="d-meta">RunR v{version} · MIT License</p>
</main>
</div>
<footer class="d-foot"><div class="d-foot-in">
<span>© 2026 BRF Teknoloji and RunR contributors</span>
<a href="/">runr.sh</a><a href="/docs/">Docs</a><a href="/dl/"{ev_dl}>Download</a><a href="/compare/">Comparisons</a><a href="/docs/changelog/">Changelog</a><a href="/security/">Security policy</a><a href="/license/">License (MIT)</a><a href="{repo}"{ev_gh}>GitHub</a>
</div></footer>
</body>
</html>
""".format(title=html.escape(title), desc=html.escape(desc, quote=True), site=SITE, repo=REPO, url=url, version=version,
           nav=nav_html, wrap=wrap, side=side or "", crumb=crumb_html, content=content, head_extra=head_extra,
           ev_dl=umami("docs-download-click", place="footer"), ev_gh=umami("docs-github-click", place="footer"),
           script='<script src="/js/docs.js" defer></script>\n' if side else "")


def index_summaries(src):
    """What docs/README.md says each page covers: {repository path: text}."""
    out = {}
    for line in read(os.path.join(src, "docs", "README.md")).splitlines():
        m = re.match(r"\|\s*\[[^\]]+\]\(([^)]+)\)\s*\|\s*(.+?)\s*\|\s*$", line)
        if not m:
            continue
        path, _ = resolve_repo_target(src, "docs/README.md", m.group(1))
        text = re.sub(r"\[([^\]]+)\]\([^)]*\)", r"\1", m.group(2)).replace("`", "").strip()
        if path and text:
            out[path] = text if text.endswith((".", "!", "?")) else text + "."
    return out


def clip(text, limit=160):
    """At most `limit` characters: up to the last sentence end when one is past the middle,
    else whole words and an ellipsis."""
    if len(text) <= limit:
        return text
    cut = text[:limit]
    end = cut.rfind(". ")
    if end >= limit // 2:
        return cut[:end + 1]
    return cut[:limit - 1].rsplit(" ", 1)[0].rstrip(",;:—–- ") + "…"


def first_paragraph(fragment):
    """The first paragraph of more than 40 characters. One that leads into a list or a table
    (ends with a colon) counts without its last sentence, or not at all when that is all it has."""
    for m in re.finditer(r"<p>(.*?)</p>", fragment, re.S):
        text = re.sub(r"\s+", " ", strip_tags(m.group(1))).strip()
        if text.endswith(":"):
            head = text.rsplit(". ", 1)
            text = head[0] + "." if len(head) == 2 else ""
        if len(text) > 40:
            return text
    return None


# <!-- description: … --> in a Markdown page sets its meta description (GitHub does not show it).
DESC_COMMENT = re.compile(r"<!--\s*description:\s*(.*?)\s*-->\s*", re.S)


def describe(body, fallback, summary=None):
    """A page's meta description: its <!-- description: … --> comment, else the first paragraph
    of its introduction (before the first section heading), else what the documentation index
    says the page covers when that is a phrase of some length, else the page's first paragraph."""
    m = DESC_COMMENT.search(body)
    if m:
        return re.sub(r"\s+", " ", html.unescape(m.group(1)))
    intro = re.split(r"<h2[\s>]", body, 1)[0]
    text = (first_paragraph(intro) or (summary if summary and len(summary) >= 50 else None)
            or first_paragraph(body) or summary)
    return clip(text) if text else fallback


def page_title(name, url):
    if url.startswith("/compare/") or url == "/security/":
        return name + " · RunR"
    if "RunR documentation" in name:
        return name
    return name + " · RunR documentation"


def render_pages(a, site, version, stats):
    pages = json.loads(read(os.path.join(a.work, "pages.json")))
    urls = {p: url_for(p) for p in pages}
    groups = sidebar(a.src, urls)
    summaries = index_summaries(a.src)
    written = []
    for page in pages:
        url = urls[page]
        if page == "LICENSE":
            text = read(os.path.join(a.src, "LICENSE"))
            body = '<h1>License</h1>\n<div class="d-license">%s</div>' % html.escape(text.strip())
            name = "License"
            title, desc = "License · RunR", "RunR is released under the MIT License."
        else:
            frag = os.path.join(a.work, "html", page_id(page) + ".html")
            if not os.path.isfile(frag):
                die("pandoc output missing for " + page)
            body = rewrite_links(a.src, page, read(frag), urls, version, stats)
            body = rewrite_images(a.src, page, body, version, stats)
            body = add_anchors(body)
            h1 = re.search(r"<h1[^>]*>(.*?)</h1>", body, re.S)
            name = TITLES.get(url) or (strip_tags(h1.group(1)) if h1 else posixpath.basename(page))
            title = page_title(name, url)
            desc = describe(body, name, summaries.get(page))
            body = DESC_COMMENT.sub("", body, count=1)
        is_doc = url.startswith("/docs/")
        is_compare = url.startswith("/compare/")
        crumb = DOCS_CRUMB if is_doc and url != "/docs/" else COMPARE_CRUMB if is_compare and url != "/compare/" else None
        out = page_html(title, desc, url, body, version,
                        side=render_sidebar(groups, url) if is_doc else None,
                        nav="/docs/" if is_doc else ("/security/" if url == "/security/" else None),
                        crumb=crumb, name=name)
        write(os.path.join(site, *url.strip("/").split("/"), "index.html"), out)
        written.append(url)
    return written


# ---------------------------------------------------------------------------------------------
# render: downloads and the Terraform registry


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def read_sums(path):
    sums = {}
    for line in read(path).splitlines():
        parts = line.split()
        if len(parts) == 2:
            sums[parts[1].lstrip("*")] = parts[0]
    return sums


def human(n):
    return "%.1f MB" % (n / 1e6) if n >= 1e6 else "%d KB" % max(1, round(n / 1e3))


def copy_checked(src, dst, want=None):
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    shutil.copyfile(src, dst)
    got = sha256(dst)
    if want and got != want:
        die("checksum mismatch for %s" % src)
    return got


PLATFORM_NAMES = {"linux": "Linux", "darwin": "macOS", "windows": "Windows", "freebsd": "FreeBSD"}


def render_downloads(a, site, version):
    tag = "v" + version
    cli_src = os.path.join(a.release, "cli")
    tf_src = os.path.join(a.release, "terraform-provider")
    if not os.path.isdir(cli_src) or not os.path.isdir(tf_src):
        die("release dir needs cli/ and terraform-provider/: " + a.release)

    # CLI
    cli_sums = read_sums(os.path.join(cli_src, "checksums.txt"))
    cli_dir = os.path.join(site, "dl", "cli", tag)
    cli_rows = []
    for name in sorted(cli_sums):
        m = re.match(r"^runr_(\w+)_(\w+)\.(tar\.gz|zip)$", name)
        if not m:
            die("unexpected CLI file " + name)
        copy_checked(os.path.join(cli_src, name), os.path.join(cli_dir, name), cli_sums[name])
        cli_rows.append((PLATFORM_NAMES.get(m.group(1), m.group(1)) + " " + m.group(2), name,
                         os.path.getsize(os.path.join(cli_dir, name)), cli_sums[name]))
    copy_checked(os.path.join(cli_src, "checksums.txt"), os.path.join(cli_dir, "checksums.txt"))
    write(os.path.join(site, "dl", "cli", "latest.txt"), tag + "\n")

    # Terraform provider
    prefix = "terraform-provider-%s_%s" % (PROVIDER, version)
    sums_name = prefix + "_SHA256SUMS"
    tf_sums = read_sums(os.path.join(tf_src, sums_name))
    tf_dir = os.path.join(site, "dl", "terraform", tag)
    for extra in (sums_name, sums_name + ".sig", prefix + "_manifest.json"):
        want = tf_sums.get(extra)
        copy_checked(os.path.join(tf_src, extra), os.path.join(tf_dir, extra), want)
    manifest = json.loads(read(os.path.join(tf_src, prefix + "_manifest.json")))
    protocols = manifest.get("metadata", {}).get("protocol_versions") or ["6.0"]

    key_text = read(a.key).replace("\r\n", "\n")
    if "BEGIN PGP PUBLIC KEY BLOCK" not in key_text:
        die("signing key is not an armored public key: " + a.key)
    write(os.path.join(site, "dl", "runr-release-signing.pub.asc"), key_text)

    base = "%s/dl/terraform/%s" % (SITE, tag)
    reg = os.path.join(site, "terraform", "providers", "v1", NAMESPACE, PROVIDER)
    platforms, tf_rows = [], []
    for name in sorted(tf_sums):
        m = re.match(r"^%s_(\w+)_(\w+)\.zip$" % re.escape(prefix), name)
        if not m:
            continue
        os_, arch = m.group(1), m.group(2)
        copy_checked(os.path.join(tf_src, name), os.path.join(tf_dir, name), tf_sums[name])
        platforms.append({"os": os_, "arch": arch})
        tf_rows.append((PLATFORM_NAMES.get(os_, os_) + " " + arch, name,
                        os.path.getsize(os.path.join(tf_dir, name)), tf_sums[name]))
        doc = {
            "protocols": protocols,
            "os": os_,
            "arch": arch,
            "filename": name,
            "download_url": "%s/%s" % (base, name),
            "shasums_url": "%s/%s" % (base, sums_name),
            "shasums_signature_url": "%s/%s.sig" % (base, sums_name),
            "shasum": tf_sums[name],
            "signing_keys": {"gpg_public_keys": [{
                "key_id": a.key_id,
                "ascii_armor": key_text,
                "trust_signature": "",
                "source": "RunR",
                "source_url": SITE + "/dl/",
            }]},
        }
        write(os.path.join(reg, version, "download", os_, arch), json.dumps(doc, indent=2) + "\n")
    if not platforms:
        die("no provider archives in " + tf_src)
    versions = {"versions": [{"version": version, "protocols": protocols, "platforms": platforms}]}
    write(os.path.join(reg, "versions"), json.dumps(versions, indent=2) + "\n")
    write(os.path.join(site, ".well-known", "terraform.json"),
          json.dumps({"providers.v1": "/terraform/providers/v1/"}) + "\n")

    write(os.path.join(site, "dl", "index.html"),
          downloads_page(version, tag, cli_rows, tf_rows, sums_name, a.key_id, a.key_fpr))
    return len(platforms)


def file_table(rows, base):
    out = ['<table class="d-files"><thead><tr><th>Platform</th><th>File</th><th>Size</th><th>SHA-256</th></tr></thead><tbody>']
    for plat, name, size, digest in rows:
        out.append('<tr><td>%s</td><td><a href="%s/%s"%s>%s</a></td><td class="d-size">%s</td><td class="d-sum">%s</td></tr>' % (
            html.escape(plat), base, name, umami("dl-file-click", file=name), html.escape(name), human(size), digest))
    out.append("</tbody></table>")
    return "\n".join(out)


def downloads_page(version, tag, cli_rows, tf_rows, sums_name, key_id, fpr):
    cli_base = "/dl/cli/" + tag
    tf_base = "/dl/terraform/" + tag
    fpr_spaced = " ".join(fpr[i:i + 4] for i in range(0, len(fpr), 4))
    body = """<h1>Download RunR {version}</h1>
<p>The <code>runr</code> CLI and the Terraform provider of RunR {version}. Every archive is listed with
its SHA-256; the installer and <code>terraform init</code> check it before anything runs. The control
plane is installed with the <a href="/docs/overview/#kubernetes-helm">Helm chart</a> from BRF's public
Helm repository (<code>helm repo add brf-tech https://helm.brf.sh</code>, chart <code>brf-tech/runr</code>)
or with <a href="/docs/compose/">Docker Compose</a> on a single host. The source code is on
<a href="{repo}/tree/{tag}">GitHub</a>.</p>

<h2 id="cli">runr CLI<a class="d-anchor" href="#cli" aria-label="Link to this section">#</a></h2>
<p>Linux and macOS (amd64, arm64):</p>
<pre><code>curl -fsSL https://runr.sh/install.sh | sh</code></pre>
<p>The <a href="/install.sh">installer</a> reads the latest release from
<a href="/dl/cli/latest.txt"><code>/dl/cli/latest.txt</code></a>, downloads the archive for your platform,
checks it against <code>checksums.txt</code> and installs <code>runr</code> into <code>/usr/local/bin</code>
when that is writable, else into <code>~/.local/bin</code>. <code>RUNR_VERSION=v{version}</code> pins a release,
<code>RUNR_INSTALL_DIR</code> picks the directory:</p>
<pre><code>curl -fsSL https://runr.sh/install.sh | RUNR_VERSION={tag} RUNR_INSTALL_DIR=$HOME/bin sh</code></pre>
<p>Or download an archive and check it yourself:</p>
<pre><code>curl -fsSLO https://runr.sh{cli_base}/runr_linux_amd64.tar.gz
curl -fsSLO https://runr.sh{cli_base}/checksums.txt
grep ' runr_linux_amd64.tar.gz$' checksums.txt | sha256sum -c
tar -xzf runr_linux_amd64.tar.gz runr &amp;&amp; sudo mv runr /usr/local/bin/</code></pre>
<p>Windows (PowerShell): download the zip, compare its hash with <code>checksums.txt</code>, unpack
<code>runr.exe</code> and add the folder to your <code>PATH</code>:</p>
<pre><code>Invoke-WebRequest https://runr.sh{cli_base}/runr_windows_amd64.zip -OutFile runr.zip
(Get-FileHash runr.zip -Algorithm SHA256).Hash.ToLower()
Expand-Archive runr.zip -DestinationPath "$env:LOCALAPPDATA\\runr"</code></pre>
{cli_table}
<p>Checksums: <a href="{cli_base}/checksums.txt"><code>checksums.txt</code></a>. More in the
<a href="/docs/cli/#install">CLI documentation</a>.</p>

<h2 id="terraform">Terraform provider<a class="d-anchor" href="#terraform" aria-label="Link to this section">#</a></h2>
<p>The provider is on the <a href="https://registry.terraform.io/providers/brf-tech/runr/latest">Terraform
Registry</a> as <code>brf-tech/runr</code>:</p>
<pre><code>terraform {{
  required_providers {{
    runr = {{
      source  = "brf-tech/runr"
      version = "~&gt; {minor}"
    }}
  }}
}}</code></pre>
<p>runr.sh serves this release, the same signed archives, with the provider registry protocol under the address
<code>runr.sh/brf-tech/runr</code>: OpenTofu installs the provider from here, and configurations that use
this address keep working. Either way <code>terraform init</code> downloads the archive for your platform
and checks it against <code>{sums_name}</code>, whose signature it verifies with the RunR release key below.
Guide: <a href="/docs/terraform/">Terraform provider</a>.</p>
{tf_table}
<p>Checksums: <a href="{tf_base}/{sums_name}"><code>{sums_name}</code></a>,
signature <a href="{tf_base}/{sums_name}.sig"><code>{sums_name}.sig</code></a>.</p>

<h2 id="signing-key">Release signing key<a class="d-anchor" href="#signing-key" aria-label="Link to this section">#</a></h2>
<p><a href="/dl/runr-release-signing.pub.asc"><code>runr-release-signing.pub.asc</code></a> — RSA 4096,
key ID <code>{key_id}</code>, fingerprint <code>{fpr_spaced}</code>.</p>
<pre><code>curl -fsSLO https://runr.sh/dl/runr-release-signing.pub.asc
gpg --import runr-release-signing.pub.asc
curl -fsSLO https://runr.sh{tf_base}/{sums_name}
curl -fsSLO https://runr.sh{tf_base}/{sums_name}.sig
gpg --verify {sums_name}.sig {sums_name}</code></pre>
""".format(version=version, minor=".".join(version.split(".")[:2]), tag=tag, repo=REPO, cli_base=cli_base,
           tf_base=tf_base, sums_name=sums_name,
           cli_table=file_table(cli_rows, cli_base), tf_table=file_table(tf_rows, tf_base),
           key_id=key_id, fpr_spaced=fpr_spaced)
    return page_html("Download · RunR", "Download the RunR %s CLI and Terraform provider, with checksums "
                     "and the release signing key." % version, "/dl/", body, version, nav="/dl/",
                     og_type="website")


# ---------------------------------------------------------------------------------------------
# render: landing, sitemap, checks, ZIP


def copy_landing(src, site):
    root = os.path.join(src, "landing")
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames.sort()
        for name in sorted(filenames):
            if name.endswith(".md"):
                continue
            s = os.path.join(dirpath, name)
            rel = os.path.relpath(s, root)
            d = os.path.join(site, rel)
            os.makedirs(os.path.dirname(d), exist_ok=True)
            shutil.copyfile(s, d)


# The landing in two languages. landing/index.html is the English page with the Turkish texts in
# attributes: `data-tr` holds an element's Turkish content (or, with data-target="placeholder",
# "aria-label" or "alt", that attribute's Turkish value), `data-tr-<attr>` the Turkish value of
# <attr> (`data-tr-lang`, `data-tr-href`, `data-tr-content` on the head's meta tags…), `data-en`
# the English text. The build writes / (English) and /tr/ (Turkish) as static pages without these
# attributes; /tr/ gets root-relative asset paths. Both carry the FAQ section as FAQPage JSON-LD.
LANDING_PAGES = (("en", "/"), ("tr", "/tr/"))
TEXT_TARGETS = ("placeholder", "aria-label", "alt")
VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"}
RAW_TEXT = {"script", "style", "textarea", "title"}
TAG_TOKEN = re.compile(r"""<!--.*?-->|<![A-Za-z][^>]*>|<(/?)([A-Za-z][A-Za-z0-9:-]*)((?:\s+[^\s"'<>/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*)\s*(/?)>""", re.S)
ATTR_TOKEN = re.compile(r"""\s+([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?""")
URL_ATTR = re.compile(r"""(\s(?:href|src|srcset|poster)=)("|')(.*?)\2""", re.S)
FAQ_ITEM = re.compile(r'<details class="faq-item[^"]*"[^>]*>\s*<summary[^>]*>(.*?)</summary>\s*'
                      r'<div class="faq-body"[^>]*>(.*?)</div>\s*</details>', re.S)
FAQ_LD = re.compile(r'(<script type="application/ld\+json">)\s*\{\s*"@context": "https://schema.org",\s*'
                    r'"@type": "FAQPage".*?(</script>)', re.S)


def tag_attrs(attr_text):
    """The attributes of a start tag: [(name, value or None, start, end)], value unescaped as a
    browser reads it, positions relative to attr_text."""
    out = []
    for m in ATTR_TOKEN.finditer(attr_text):
        v = next((g for g in m.groups()[1:] if g is not None), None)
        out.append((m.group(1).lower(), None if v is None else html.unescape(v), m.start(), m.end()))
    return out


def rebuild_tag(tag, offset, attrs, drop, values):
    """`tag` (a start tag's text) without the attributes in `drop` and with `values` set, a value
    whose attribute is missing added at the end. offset: where the attribute text starts in tag."""
    out, last, done = [], 0, set()
    for name, _, s, e in attrs:
        s, e = s + offset, e + offset
        if name in values:
            out.append(tag[last:s] + ' %s="%s"' % (name, html.escape(values[name], quote=True)))
            last = e
            done.add(name)
        elif name in drop:
            out.append(tag[last:s])
            last = e
    rest = tag[last:]
    extra = "".join(' %s="%s"' % (n, html.escape(v, quote=True)) for n, v in values.items() if n not in done)
    cut = len(rest) - (2 if rest.endswith("/>") else 1)
    out.append(rest[:cut] + extra + rest[cut:])
    return "".join(out)


def localize(text, lang):
    """The landing page in `lang` ("en" or "tr"): the Turkish texts applied for "tr" (as the
    landing's script did in the browser, `data-tr` content replacing the element's content),
    the translation attributes removed for both."""
    edits, replaced, stack = [], [], []
    pos = 0
    while True:
        m = TAG_TOKEN.search(text, pos)
        if not m:
            break
        pos = m.end()
        if m.group(2) is None:                              # comment or doctype
            continue
        name = m.group(2).lower()
        if m.group(1):                                      # end tag: close up to its element
            for i in range(len(stack) - 1, -1, -1):
                if stack[i][0] == name:
                    _, start, tr = stack[i]
                    del stack[i:]
                    if tr is not None:
                        edits.append((start, m.start(), tr))
                        replaced.append((start, m.start()))
                    break
            continue
        attrs = tag_attrs(m.group(3))
        a = {n: v for n, v, _, _ in attrs}
        target = a.get("data-target") if "data-tr" in a else None
        drop = {n for n in a if n in ("data-tr", "data-en") or n.startswith("data-tr-")}
        if target in TEXT_TARGETS:
            drop.add("data-target")
        values, content = {}, None
        if lang == "tr":
            if a.get("data-tr") is not None:
                if target in TEXT_TARGETS:
                    values[target] = a["data-tr"]
                else:
                    content = a["data-tr"]
            for n, v in a.items():
                if n.startswith("data-tr-") and v is not None:
                    values[n[len("data-tr-"):]] = v
        if drop or values:
            edits.append((m.start(), m.end(), rebuild_tag(m.group(0), m.start(3) - m.start(), attrs, drop, values)))
        if name in VOID or m.group(4):
            if content is not None:
                die("landing: data-tr on the empty element <%s> (line %d)" % (name, text.count("\n", 0, m.start()) + 1))
            continue
        if name in RAW_TEXT:
            end = re.compile(r"</%s\s*>" % name, re.I).search(text, pos)
            if not end:
                die("landing: <%s> without </%s>" % (name, name))
            if content is not None:
                edits.append((pos, end.start(), content))
                replaced.append((pos, end.start()))
            pos = end.end()
            continue
        stack.append((name, m.end(), content))
    if any(tr is not None for _, _, tr in stack):
        die("landing: an element with data-tr is never closed")
    # An element whose content is replaced takes its children's changes with it.
    edits = [e for e in edits if not any(s <= e[0] and e[1] <= t and (e[0], e[1]) != (s, t) for s, t in replaced)]
    out, last = [], 0
    for s, e, r in sorted(edits):
        if s < last:
            die("landing: overlapping changes at offset %d" % s)
        out.append(text[last:s] + r)
        last = e
    out.append(text[last:])
    return "".join(out)


def absolutize(text):
    """Relative URLs of href/src/srcset → root-relative, for a copy of the page one level down."""
    def one(u):
        u = u.strip()
        if not u or u.startswith(("/", "#", "?")) or re.match(r"^[a-z][a-z0-9+.-]*:", u, re.I):
            return u
        return "/" + u

    def repl(m):
        value = m.group(3)
        if "srcset" in m.group(1):
            new = ", ".join(" ".join([one(p.split()[0])] + p.split()[1:]) for p in value.split(",") if p.strip())
        else:
            new = one(value)
        return m.group(1) + m.group(2) + new + m.group(2)
    return URL_ATTR.sub(repl, text)


def faq_jsonld(page):
    """The page's FAQPage JSON-LD, rebuilt from its FAQ section (questions and answers as text)."""
    items = FAQ_ITEM.findall(page)
    if not items:
        die("landing: no FAQ items (details.faq-item) found")
    if not FAQ_LD.search(page):
        die("landing: no FAQPage JSON-LD block found")
    text = lambda s: re.sub(r"\s+", " ", strip_tags(s)).strip()
    doc = {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
        {"@type": "Question", "name": text(q), "acceptedAnswer": {"@type": "Answer", "text": text(a)}}
        for q, a in items]}
    body = json.dumps(doc, ensure_ascii=False, indent=2).replace("</", "<\\/")
    block = "\n".join("  " + line for line in body.splitlines())
    return FAQ_LD.sub(lambda m: m.group(1) + "\n" + block + "\n  " + m.group(2), page, count=1)


def render_landing(src, site):
    """/index.html (English) and /tr/index.html (Turkish) from landing/index.html."""
    text = read(os.path.join(src, "landing", "index.html")).replace("\r\n", "\n")
    for lang, url in LANDING_PAGES:
        page = localize(text, lang)
        if lang != "en":
            page = absolutize(page)
        page = faq_jsonld(page)
        if not re.search(r'<html lang="%s"' % lang, page):
            die("landing: the %s page has no <html lang=\"%s\">" % (url, lang))
        canonical = re.search(r'<link rel="canonical" href="([^"]+)"', page)
        if not canonical or canonical.group(1) != SITE + url:
            die("landing: the canonical of %s is %s" % (url, canonical and canonical.group(1)))
        write(os.path.join(site, *url.strip("/").split("/"), "index.html"), page)


def write_sitemap(site, urls, date):
    """sitemap.xml: the landing in English and Turkish with their hreflang alternates, then every
    other page; lastmod is the build's date (the commit date of the published ref)."""
    alternates = "".join('    <xhtml:link rel="alternate" hreflang="%s" href="%s%s"/>\n' % (h, SITE, u)
                         for h, u in (("en", "/"), ("tr", "/tr/"), ("x-default", "/")))
    landing = [u for _, u in LANDING_PAGES]
    entries = ["  <url>\n    <loc>%s%s</loc>\n    <lastmod>%s</lastmod>\n%s  </url>\n" % (
        SITE, u, date, alternates if u in landing else "") for u in landing + sorted(urls)]
    write(os.path.join(site, "sitemap.xml"),
          '<?xml version="1.0" encoding="UTF-8"?>\n'
          '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"\n'
          '        xmlns:xhtml="http://www.w3.org/1999/xhtml">\n' + "".join(entries) + "</urlset>\n")


def site_files(site):
    out = []
    for dirpath, dirnames, filenames in os.walk(site):
        dirnames.sort()
        for name in sorted(filenames):
            full = os.path.join(dirpath, name)
            out.append((os.path.relpath(full, site).replace(os.sep, "/"), full))
    return sorted(out)


ID_ATTR = re.compile(r'\sid="([^"]+)"')
LOCAL_REF = re.compile(r'\s(?:href|src)="([^"]+)"')


def check_links(site, files):
    """Every local href/src of every page must exist, and so must its #fragment."""
    ids, errors, warnings = {}, [], []
    pages = [(rel, full) for rel, full in files if rel.endswith(".html")]
    for rel, full in pages:
        ids[rel] = set(ID_ATTR.findall(read(full)))
    existing = {rel for rel, _ in files}
    for rel, full in pages:
        text = read(full)
        # attribute values inside data-tr/data-en (single-quoted) count too, and every srcset candidate
        refs = LOCAL_REF.findall(text) + re.findall(r"href='([^']+)'", text)
        for srcset in re.findall(r'\ssrcset="([^"]+)"', text):
            refs += [c.split()[0] for c in srcset.split(",") if c.strip()]
        for ref in refs:
            ref = html.unescape(ref)
            if re.match(r"^[a-z][a-z0-9+.-]*:", ref) or ref.startswith("//"):
                continue
            path, _, frag = ref.partition("#")
            path = path.split("?")[0]
            if path == "":
                target = rel
            elif path.startswith("/"):
                target = path.lstrip("/")
            else:
                target = posixpath.normpath(posixpath.join(posixpath.dirname(rel), path))
            if target == "" or target.endswith("/") or (target not in existing and target + "/index.html" in existing):
                target = (target.rstrip("/") + "/index.html").lstrip("/")
            if target not in existing:
                errors.append("%s: %s (missing)" % (rel, ref))
                continue
            if frag and target.endswith(".html") and frag not in ids.get(target, ()):
                warnings.append("%s: %s (no #%s)" % (rel, ref, frag))
    return errors, warnings


def write_zip(site, files, zip_path, epoch):
    stamp = time.gmtime(max(epoch, 315532800))[:6]
    os.makedirs(os.path.dirname(os.path.abspath(zip_path)), exist_ok=True)
    tmp = zip_path + ".tmp"
    with zipfile.ZipFile(tmp, "w") as z:
        for rel, full in files:
            info = zipfile.ZipInfo(rel, date_time=stamp)
            info.external_attr = 0o644 << 16
            info.create_system = 3
            stored = rel.endswith((".zip", ".gz", ".png", ".webp", ".sig"))
            info.compress_type = zipfile.ZIP_STORED if stored else zipfile.ZIP_DEFLATED
            with open(full, "rb") as f:
                z.writestr(info, f.read(), compresslevel=None if stored else 9)
    os.replace(tmp, zip_path)


def cmd_render(a):
    version = a.version
    if not re.match(r"^\d+\.\d+\.\d+$", version):
        die("--version must be X.Y.Z")
    site = a.site
    if os.path.exists(site):
        die("site dir exists: " + site)
    stats = {"rewritten": 0, "github": [], "unlinked": [], "images": 0}
    if a.tag_files:
        global TAG_FILES
        TAG_FILES = set(l.strip() for l in read(a.tag_files).splitlines() if l.strip())
    copy_landing(a.src, site)
    render_landing(a.src, site)
    pages = render_pages(a, site, version, stats)
    nplat = render_downloads(a, site, version)
    write_sitemap(site, [u for u in pages] + ["/dl/"], a.date)

    files = site_files(site)
    errors, warnings = check_links(site, files)
    for e in errors:
        print("broken link: " + e, file=sys.stderr)
    for w in warnings:
        print("missing anchor: " + w, file=sys.stderr)
    if errors:
        die("%d broken links" % len(errors))
    total = sum(os.path.getsize(full) for _, full in files)
    if len(files) > MAX_ENTRIES or total > MAX_BYTES:
        die("site too large for a ZIP deploy: %d files, %d bytes" % (len(files), total))

    write_zip(site, files, a.zip, a.epoch)
    zsha, zsize = sha256(a.zip), os.path.getsize(a.zip)
    summary = {
        "zip": os.path.basename(a.zip), "sha256": zsha, "zip_bytes": zsize, "files": len(files),
        "content_bytes": total, "pages": len(pages), "provider_platforms": nplat,
        "links_rewritten": stats["rewritten"], "links_github": len(stats["github"]),
        "links_unlinked": len(stats["unlinked"]), "images_rewritten": stats["images"],
        "missing_anchors": len(warnings),
    }
    write(os.path.join(a.work, "summary.json"), json.dumps(summary, indent=1))
    write(os.path.join(a.work, "github.txt"), "\n".join(stats["github"]) + "\n")
    write(os.path.join(a.work, "unlinked.txt"), "\n".join(stats["unlinked"]) + "\n")
    print(json.dumps(summary, indent=1))


def main():
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = p.add_subparsers(dest="cmd", required=True)
    pp = sub.add_parser("prepare")
    pp.add_argument("--src", required=True)
    pp.add_argument("--work", required=True)
    pr = sub.add_parser("render")
    pr.add_argument("--src", required=True)
    pr.add_argument("--work", required=True)
    pr.add_argument("--site", required=True)
    pr.add_argument("--release", required=True)
    pr.add_argument("--key", required=True)
    pr.add_argument("--key-id", required=True)
    pr.add_argument("--key-fpr", required=True)
    pr.add_argument("--version", required=True)
    pr.add_argument("--zip", required=True)
    pr.add_argument("--date", required=True)
    pr.add_argument("--epoch", type=int, required=True)
    pr.add_argument("--tag-files", help="paths of the release tag's tree (git ls-tree -r --name-only); "
                    "a linked repository file that is not among them goes to GitHub at main")
    a = p.parse_args()
    {"prepare": cmd_prepare, "render": cmd_render}[a.cmd](a)


if __name__ == "__main__":
    main()
