#!/usr/bin/env python3
"""Builds the runr.sh site. Driven by ci/build-runr-sh.sh, which runs it in two phases:

  prepare  picks the documentation pages (docs/*.md, the root Markdown files and every Markdown
           page they link to), applies the site's text substitutions and writes the pandoc inputs
  render   turns the pandoc output into pages (links between pages rewritten to runr.sh URLs, links
           to unpublished repository files to GitHub at the release tag), adds the landing, the
           downloads (CLI, Terraform provider, signing key), the Terraform provider registry, checks
           every internal link and writes a deterministic ZIP

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
}

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
                    new = "%s/%s/v%s/%s" % (REPO, kind, version, rel) + ("#" + anchor if anchor else "")
                    stats["github"].append("%s -> %s" % (page, new))
                elif not re.match(r"^[a-z][a-z0-9+.-]*:", href):
                    stats["unlinked"].append("%s -> %s" % (page, href))
                    return '<span class="d-nolink">' + inner + "</span>"
        if new is None:
            return m.group(0)
        stats["rewritten"] += 1
        return "<a " + HREF.sub('href="%s"' % html.escape(new, quote=True), attrs, count=1) + ">" + inner + "</a>"
    return A_TAG.sub(repl, body)


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


def page_html(title, desc, url, content, version, side=None, nav=None, crumb=True):
    nav_items = [("/docs/", "Docs"), ("/dl/", "Download"), ("/security/", "Security"), (REPO, "GitHub")]
    nav_html = "".join('<a href="%s"%s>%s</a>' % (u, ' aria-current="page"' if nav == u else "", t)
                       for u, t in nav_items)
    crumb_html = '<p class="d-crumb"><a href="/docs/">Documentation</a></p>' if crumb else ""
    wrap = "d-wrap" if side else "d-wrap d-single"
    return """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<link rel="canonical" href="{site}{url}">
<meta name="theme-color" content="#0a0e1a">
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
<a href="/">runr.sh</a><a href="/docs/">Docs</a><a href="/dl/">Download</a><a href="/docs/changelog/">Changelog</a><a href="/security/">Security policy</a><a href="/license/">License (MIT)</a><a href="{repo}">GitHub</a>
</div></footer>
</body>
</html>
""".format(title=html.escape(title), desc=html.escape(desc, quote=True), site=SITE, repo=REPO, url=url, version=version,
           nav=nav_html, wrap=wrap, side=side or "", crumb=crumb_html, content=content,
           script='<script src="/js/docs.js" defer></script>\n' if side else "")


def describe(body, fallback):
    for m in re.finditer(r"<p>(.*?)</p>", body, re.S):
        text = re.sub(r"\s+", " ", strip_tags(m.group(1)))
        if len(text) > 40:
            return text if len(text) <= 180 else text[:177].rsplit(" ", 1)[0] + "…"
    return fallback


def render_pages(a, site, version, stats):
    pages = json.loads(read(os.path.join(a.work, "pages.json")))
    urls = {p: url_for(p) for p in pages}
    groups = sidebar(a.src, urls)
    written = []
    for page in pages:
        url = urls[page]
        if page == "LICENSE":
            text = read(os.path.join(a.src, "LICENSE"))
            body = '<h1>License</h1>\n<div class="d-license">%s</div>' % html.escape(text.strip())
            title, desc = "License · RunR", "RunR is released under the MIT License."
        else:
            frag = os.path.join(a.work, "html", page_id(page) + ".html")
            if not os.path.isfile(frag):
                die("pandoc output missing for " + page)
            body = rewrite_links(a.src, page, read(frag), urls, version, stats)
            body = add_anchors(body)
            h1 = re.search(r"<h1[^>]*>(.*?)</h1>", body, re.S)
            name = strip_tags(h1.group(1)) if h1 else posixpath.basename(page)
            title = name + (" · RunR" if url == "/security/" else " · RunR documentation")
            desc = describe(body, name)
        is_doc = url.startswith("/docs/")
        out = page_html(title, desc, url, body, version,
                        side=render_sidebar(groups, url) if is_doc else None,
                        nav="/docs/" if is_doc else ("/security/" if url == "/security/" else None),
                        crumb=is_doc and url != "/docs/")
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
        out.append('<tr><td>%s</td><td><a href="%s/%s">%s</a></td><td class="d-size">%s</td><td class="d-sum">%s</td></tr>' % (
            html.escape(plat), base, name, html.escape(name), human(size), digest))
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
<p>runr.sh serves the provider registry protocol, so <code>terraform init</code> (or OpenTofu) installs
the provider from here:</p>
<pre><code>terraform {{
  required_providers {{
    runr = {{
      source  = "runr.sh/brf-tech/runr"
      version = "~&gt; 0.5"
    }}
  }}
}}</code></pre>
<p><code>terraform init</code> downloads the archive for your platform and checks it against
<code>{sums_name}</code>, whose signature it verifies with the RunR release key below. Guide:
<a href="/docs/terraform/">Terraform provider</a>.</p>
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
""".format(version=version, tag=tag, repo=REPO, cli_base=cli_base, tf_base=tf_base, sums_name=sums_name,
           cli_table=file_table(cli_rows, cli_base), tf_table=file_table(tf_rows, tf_base),
           key_id=key_id, fpr_spaced=fpr_spaced)
    return page_html("Download · RunR", "Download the RunR %s CLI and Terraform provider, with checksums "
                     "and the release signing key." % version, "/dl/", body, version, nav="/dl/", crumb=False)


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


def write_sitemap(site, urls, date):
    path = os.path.join(site, "sitemap.xml")
    text = read(path)
    extra = "".join("  <url>\n    <loc>%s%s</loc>\n    <lastmod>%s</lastmod>\n  </url>\n" % (SITE, u, date)
                    for u in sorted(urls))
    if "</urlset>" not in text:
        die("landing sitemap.xml has no </urlset>")
    write(path, text.replace("</urlset>", extra + "</urlset>", 1))


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
        # attribute values inside data-tr/data-en (single-quoted) count too
        refs = LOCAL_REF.findall(text) + re.findall(r"href='([^']+)'", text)
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
            stored = rel.endswith((".zip", ".gz", ".png", ".sig"))
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
    stats = {"rewritten": 0, "github": [], "unlinked": []}
    copy_landing(a.src, site)
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
        "links_unlinked": len(stats["unlinked"]),
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
    a = p.parse_args()
    {"prepare": cmd_prepare, "render": cmd_render}[a.cmd](a)


if __name__ == "__main__":
    main()
