#!/usr/bin/env bash
# Serves a runr.sh site ZIP (ci/build-runr-sh.sh) the way the StaticSites vhost serves runr.sh —
# Caddy, `encode zstd gzip`, `/api/*` to the backend (a stub answering 422 here), file_server for
# the rest — under the real hostname on a private docker network, and checks it from clean
# containers:
#
#   - status and Content-Type of the pages, the downloads and the provider registry documents
#   - every internal link of every page answers 200 (HTTP crawl)
#   - the ZIP's files (not with --live): the two landing pages and their metadata, the docs
#     metadata, the sitemap, the still hero, and no blue-tinted colour (check-colors.py)
#   - `curl -fsSL https://runr.sh/install.sh | sh` installs the CLI on linux/amd64 (root and
#     non-root) and linux/arm64 (emulated), the version matches latest.txt
#   - `terraform init` installs runr.sh/brf-tech/runr and verifies its signature, on amd64 and
#     arm64 (emulated); `tofu init` (OpenTofu) on amd64
#
#   ci/runr-sh/verify.sh SITE.zip [--keep PORT]
#   ci/runr-sh/verify.sh --live          the same checks against the published https://runr.sh
#
# --keep PORT leaves the server running with plain HTTP on localhost:PORT for a look in the
# browser (remove it with `docker rm -f runrsh-verify-caddy; docker network rm runrsh-verify`).
# Needs docker (and binfmt for arm64), python3. Uses only its own containers and network.
set -euo pipefail

usage="usage: verify.sh SITE.zip [--keep PORT] | verify.sh --live"
zip="${1:?$usage}"
keep=""
live=false
[ "$zip" = "--live" ] && live=true
[ "${2:-}" = "--keep" ] && keep="${3:?--keep needs a port}"

CADDY_IMAGE="${CADDY_IMAGE:-caddy:2.11.4}"
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.11.1}"
ALPINE_IMAGE="${ALPINE_IMAGE:-alpine:3.22}"
PY_IMAGE="${PY_IMAGE:-python:3.12-slim}"
TF_IMAGE="${TF_IMAGE:-hashicorp/terraform:1.16.5}"
TOFU_IMAGE="${TOFU_IMAGE:-ghcr.io/opentofu/opentofu:1.10.6}"

net=runrsh-verify
caddy=runrsh-verify-caddy
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
fails=0

PY=""
for c in "${PYTHON:-}" python3 python; do
  [ -n "$c" ] || continue
  if command -v "$c" >/dev/null 2>&1 && "$c" -c 'import sys; sys.exit(sys.version_info < (3, 8))' 2>/dev/null; then
    PY="$c"; break
  fi
done
[ -n "$PY" ] || { echo "python 3.8+ is required" >&2; exit 1; }

cleanup() {
  if [ -z "$keep" ] && [ "$live" = false ]; then
    docker rm -f "$caddy" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
  fi
  rm -rf -- "$tmp"
}
trap cleanup EXIT

ok() { printf 'ok   %s\n' "$*"; }
bad() { printf 'FAIL %s\n' "$*"; fails=$((fails + 1)); }

mkdir -p "$tmp/root/etc/caddy" "$tmp/root/srv/static/runr" "$tmp/in"
if [ "$live" = true ]; then
  net=bridge
  version="$(curl -fsS https://runr.sh/dl/cli/latest.txt | tr -d ' \r\n')"
  echo "site: https://runr.sh (CLI $version)"
  : > "$tmp/in/ca.pem"
else
# --- server ------------------------------------------------------------------------------------
"$PY" -c 'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])' "$zip" "$tmp/root/srv/static/runr"
version="$(tr -d ' \r\n' < "$tmp/root/srv/static/runr/dl/cli/latest.txt")"
echo "site: $zip (CLI $version)"

http_block=""
[ -n "$keep" ] && http_block=":8080 {
	encode zstd gzip
	root * /srv/static/runr
	file_server
}"
cat > "$tmp/root/etc/caddy/Caddyfile" <<EOF
{
	local_certs
	skip_install_trust
}
# Same handlers as the generated runr.caddy (StaticSites module); the umami snippet is left out
# (it only injects a script tag into HTML) and /api answers like the backend's validation error.
runr.sh {
	encode zstd gzip
	@panel path_regexp ^/(api)(/|$)
	handle @panel {
		respond "{\"message\":\"stub\"}" 422
	}
	handle {
		root * /srv/static/runr
		file_server
	}
}
$http_block
EOF

docker rm -f "$caddy" >/dev/null 2>&1 || true
docker network rm "$net" >/dev/null 2>&1 || true
docker network create "$net" >/dev/null
publish=()
[ -n "$keep" ] && publish=(-p "127.0.0.1:$keep:8080")
docker create --name "$caddy" --network "$net" --network-alias runr.sh ${publish[@]+"${publish[@]}"} "$CADDY_IMAGE" >/dev/null
tar -C "$tmp/root" -cf - . | docker cp - "$caddy:/"
docker start "$caddy" >/dev/null
for _ in $(seq 1 60); do
  if docker exec "$caddy" test -f /data/caddy/pki/authorities/local/root.crt 2>/dev/null; then break; fi
  sleep 1
done
docker exec "$caddy" cat /data/caddy/pki/authorities/local/root.crt > "$tmp/in/ca.pem"
grep -q 'BEGIN CERTIFICATE' "$tmp/in/ca.pem" || { echo "Caddy did not create its local CA" >&2; exit 1; }
fi

# run IMAGE PLATFORM SCRIPT: runs SCRIPT (in $tmp/in) in a clean container on the network; the
# files of $tmp/in are in /tmp/t.
run() {
  local image="$1" platform="$2" script="$3"
  tar -C "$tmp/in" -cf - . | docker run --rm -i --network "$net" --platform "$platform" \
    -e HOME=/tmp/home --entrypoint sh "$image" -c \
    "mkdir -p /tmp/t /tmp/home && cd /tmp/t && tar -xf - && sh /tmp/t/$script"
}

# --- HTTP checks -------------------------------------------------------------------------------
echo "VERSION=$version" > "$tmp/in/http.sh"
cat >> "$tmp/in/http.sh" <<'EOF'
set -u
CA=""
[ -s /tmp/t/ca.pem ] && CA="--cacert /tmp/t/ca.pem"
c() { curl -sS $CA -o /dev/null -w '%{http_code} %{content_type}' "$@"; }
for spec in \
  "/|200|text/html" "/docs/|200|text/html" "/docs/cli/|200|text/html" "/docs/terraform/|200|text/html" \
  "/docs/overview/|200|text/html" "/dl/|200|text/html" "/security/|200|text/html" "/license/|200|text/html" \
  "/docs/cli|308|" "/install.sh|200|application/x-sh" "/dl/cli/latest.txt|200|text/plain" \
  "/dl/cli/VERSION/checksums.txt|200|text/plain" "/dl/cli/VERSION/runr_linux_amd64.tar.gz|200|application/gzip" \
  "/dl/cli/VERSION/runr_windows_amd64.zip|200|application/zip" "/dl/runr-release-signing.pub.asc|200|text/plain" \
  "/.well-known/security.txt|200|text/plain" "/.well-known/terraform.json|200|application/json" \
  "/terraform/providers/v1/brf-tech/runr/versions|200|" \
  "/terraform/providers/v1/brf-tech/runr/TFV/download/linux/amd64|200|" \
  "/dl/terraform/VERSION/terraform-provider-runr_TFV_SHA256SUMS|200|" \
  "/dl/terraform/VERSION/terraform-provider-runr_TFV_SHA256SUMS.sig|200|" \
  "/nope/|404|"; do
  path="${spec%%|*}"; rest="${spec#*|}"; want="${rest%%|*}"; ctype="${rest#*|}"
  path="$(echo "$path" | sed "s/VERSION/$VERSION/; s/TFV/${VERSION#v}/")"
  got="$(c "https://runr.sh$path")"
  code="${got%% *}"; type="${got#* }"
  case "$type" in "$ctype"*) tok=1 ;; *) tok=0 ;; esac
  if [ "$code" = "$want" ] && { [ -z "$ctype" ] || [ "$tok" = 1 ]; }; then
    echo "ok   $path -> $code ${type:-(no Content-Type)}"
  else
    echo "FAIL $path -> $code ${type:-(no Content-Type)} (want $want $ctype)"
  fi
done
got="$(curl -sS $CA -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' https://runr.sh/api/waitlist)"
[ "$got" = 422 ] && echo "ok   POST /api/waitlist -> 422 (backend route)" || echo "FAIL POST /api/waitlist -> $got"
pol="$(curl -sS $CA https://runr.sh/.well-known/security.txt | sed -n 's/^Policy: //p')"
[ "$pol" = "https://runr.sh/security/" ] && echo "ok   security.txt Policy: $pol" || echo "FAIL security.txt Policy: $pol"
enc="$(curl -sS $CA -H 'Accept-Encoding: gzip' -o /dev/null -w '%header{content-encoding}' https://runr.sh/docs/cli/)"
[ "$enc" = gzip ] && echo "ok   /docs/cli/ gzip-encoded" || echo "FAIL /docs/cli/ not compressed ($enc)"
EOF
echo "== HTTP"
out="$(run "$CURL_IMAGE" linux/amd64 http.sh 2>&1 || true)"
echo "$out"
fails=$((fails + $(printf '%s\n' "$out" | grep -c '^FAIL' || true)))

# --- SEO (the ZIP's files) ---------------------------------------------------------------------
# The two landing pages (/ English, /tr/ Turkish: lang, own canonical, the hreflang set, og:locale,
# no translation attributes or relative asset paths left on /tr/), the social tags and breadcrumb
# of a docs page, the comparison pages in the sitemap, and no Umami <script> in any page (the web
# server adds it; a second one would count every visit twice). On both landing pages: no
# unconditional three.js / hero3d.js / bg.js script (phones get the still hero image, which must
# exist), every srcset file exists, and Turnstile is not loaded with the page.
if [ "$live" = false ]; then
cat > "$tmp/seo.py" <<'EOF'
import json, os, posixpath, re, sys
root = sys.argv[1]
site = "https://runr.sh"
fails = 0
def check(ok, what):
    global fails
    print(("ok   " if ok else "FAIL ") + what)
    fails += 0 if ok else 1
def page(rel):
    p = os.path.join(root, *rel.split("/"))
    return open(p, encoding="utf-8").read() if os.path.isfile(p) else ""
def meta(text, attr, name):
    m = re.search(r'<meta %s="%s" content="([^"]*)"' % (attr, re.escape(name)), text)
    return m.group(1) if m else None
alternates = {"en": site + "/", "tr": site + "/tr/", "x-default": site + "/"}
for rel, lang, url, locale in (("index.html", "en", "/", "en_US"), ("tr/index.html", "tr", "/tr/", "tr_TR")):
    t = page(rel)
    check(bool(t), "%s exists" % url)
    check(re.search(r'<html lang="%s"' % lang, t) is not None, '%s has <html lang="%s">' % (url, lang))
    canon = re.findall(r'<link rel="canonical" href="([^"]+)"', t)
    check(canon == [site + url], "%s canonical is itself (%s)" % (url, canon))
    alts = dict(re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', t))
    check(alts == alternates, "%s hreflang en / tr / x-default (%s)" % (url, alts))
    check(meta(t, "property", "og:locale") == locale, "%s og:locale %s" % (url, locale))
    check(meta(t, "property", "og:url") == site + url, "%s og:url" % url)
    check(re.search(r'\sdata-(?:tr|en)(?:-[a-z-]+)?=', t) is None, "%s has no data-tr / data-en attributes" % url)
    ld = [json.loads(b) for b in re.findall(r'<script type="application/ld\+json">(.*?)</script>', t, re.S)]
    faq = [d for d in ld if d.get("@type") == "FAQPage"]
    check(len(faq) == 1 and len(faq[0]["mainEntity"]) > 0, "%s FAQPage JSON-LD (%d questions)" % (
        url, len(faq[0]["mainEntity"]) if faq else 0))
    # phones, touch screens and reduced motion: the still hero, and no 3D / canvas scripts by default
    check(re.search(r'<script[^>]+src="[^"]*(?:three|hero3d|bg\.js)', t) is None,
          "%s loads no three.js / hero3d.js / bg.js unconditionally" % url)
    check("matchMedia('(max-width: 768px), (pointer: coarse), (prefers-reduced-motion: reduce)')" in t,
          "%s loads the 3D hero only past its media query" % url)
    stills = re.findall(r'<picture class="hero-static".*?</picture>', t, re.S)
    srcs = re.findall(r'srcset="([^"]+)"', stills[0]) if stills else []
    check(len(srcs) == 2 and all(os.path.isfile(os.path.join(root, *s.lstrip("/").split("/"))) for s in srcs),
          "%s has the still hero images %s" % (url, srcs))
    missing = [c.split()[0] for s in re.findall(r'\ssrcset="([^"]+)"', t) for c in s.split(",") if c.strip()]
    missing = [c for c in missing if not os.path.isfile(os.path.join(root, *(c.lstrip("/") if c.startswith("/")
               else posixpath.join(posixpath.dirname(rel), c)).split("/")))]
    check(not missing, "%s: every srcset file exists %s" % (url, missing[:3]))
    check("challenges.cloudflare.com" not in re.sub(r"<!--.*?-->", "", t, flags=re.S),
          "%s does not load Turnstile with the page" % url)
for f in ("hero-static.webp", "hero-static-wide.webp"):
    p = os.path.join(root, "assets", f)
    check(os.path.isfile(p) and os.path.getsize(p) > 2000, "assets/%s exists" % f)
tr = page("tr/index.html")
rel_refs =[u for u in re.findall(r'\s(?:href|src)="([^"]+)"', tr) if not re.match(r"^(/|#|\?|[a-z][a-z0-9+.-]*:)", u)]
check(not rel_refs, "/tr/ has no relative asset paths %s" % rel_refs[:3])
check(re.search(r"<title>[^<]*Kubernetes Üzerinde", tr) is not None, "/tr/ has the Turkish title")
d = page("docs/triggers/index.html")
for attr, name, want in (("property", "og:type", "article"), ("property", "og:url", site + "/docs/triggers/"),
                         ("property", "og:site_name", "RunR"), ("property", "og:image", site + "/assets/og-image.png"),
                         ("name", "twitter:card", "summary_large_image")):
    check(meta(d, attr, name) == want, "/docs/triggers/ %s = %s" % (name, want))
for attr, name in (("property", "og:title"), ("property", "og:description"), ("name", "twitter:title"),
                   ("name", "twitter:description"), ("name", "twitter:image")):
    check(bool(meta(d, attr, name)), "/docs/triggers/ has %s" % name)
crumbs = [json.loads(b) for b in re.findall(r'<script type="application/ld\+json">(.*?)</script>', d, re.S)]
crumbs = [c for c in crumbs if c.get("@type") == "BreadcrumbList"]
check(bool(crumbs) and [i["item"] for i in crumbs[0]["itemListElement"]] == [site + "/docs/", site + "/docs/triggers/"],
      "/docs/triggers/ BreadcrumbList Documentation -> page")
sm = page("sitemap.xml")
locs = re.findall(r"<loc>([^<]+)</loc>", sm)
for u in ("/", "/tr/", "/compare/", "/compare/cloud-run/", "/compare/aws-lambda/", "/compare/vercel/",
          "/compare/knative/", "/compare/openfaas/", "/dl/", "/docs/"):
    check(site + u in locs, "sitemap lists %s" % u)
check(sm.count('hreflang="tr" href="%s/tr/"' % site) == 2 and sm.count('hreflang="x-default"') == 2,
      "sitemap: / and /tr/ carry their alternates")
check("?lang=" not in sm and len(set(re.findall(r"<lastmod>([^<]+)</lastmod>", sm))) == 1,
      "sitemap: no ?lang= URLs, one lastmod (the build date)")
umami = []
for dirpath, _, files in os.walk(root):
    for f in files:
        if f.endswith(".html"):
            t = open(os.path.join(dirpath, f), encoding="utf-8").read()
            if re.search(r"<script[^>]+(?:umami|stats\.brf\.sh)", t, re.I):
                umami.append(os.path.relpath(os.path.join(dirpath, f), root))
check(not umami, "no page has its own Umami script %s" % umami[:3])
print("seo: %d failed" % fails)
EOF
echo "== SEO"
out="$("$PY" "$tmp/seo.py" "$tmp/root/srv/static/runr" 2>&1 || true)"
echo "$out"
fails=$((fails + $(printf '%s\n' "$out" | grep -c '^FAIL' || true)))
# The site is neutral black and gray with orange / red accents: no blue-tinted colour in its
# stylesheets, the colours its pages apply or the canvas scripts (check-colors.py, allow list there).
echo "== colours"
out="$("$PY" "$here/check-colors.py" "$tmp/root/srv/static/runr" 2>&1 || true)"
echo "$out"
printf '%s\n' "$out" | grep -q '^colours: ' || { echo "FAIL check-colors.py did not run"; fails=$((fails + 1)); }
fails=$((fails + $(printf '%s\n' "$out" | grep -c '^FAIL' || true)))
fi

# --- crawl -------------------------------------------------------------------------------------
cat > "$tmp/in/crawl.py" <<'EOF'
import html, re, ssl, sys, urllib.error, urllib.parse, urllib.request
import os
ctx = ssl.create_default_context(cafile="/tmp/t/ca.pem" if os.path.getsize("/tmp/t/ca.pem") else None)
base = "https://runr.sh"
seen, queue, bad, pages = set(), ["/"], [], 0
ids = {}
anchors = []
while queue:
    path = queue.pop()
    if path in seen:
        continue
    seen.add(path)
    big = path.endswith((".zip", ".gz", ".sig", ".asc", ".txt", ".sh", ".json"))
    req = urllib.request.Request(base + path, method="HEAD" if big else "GET")
    try:
        with urllib.request.urlopen(req, context=ctx) as r:
            body = b"" if big else r.read()
            ctype = r.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        bad.append("%s -> %d" % (path, e.code))
        continue
    if not ctype.startswith("text/html"):
        continue
    pages += 1
    text = body.decode("utf-8")
    ids[path] = set(re.findall(r'\sid="([^"]+)"', text))
    for ref in re.findall(r'(?:href|src)=["\']([^"\']+)["\']', text):
        ref = html.unescape(ref)
        u = urllib.parse.urljoin(base + path, ref)
        p = urllib.parse.urlparse(u)
        if p.netloc != "runr.sh" or p.scheme != "https":
            continue
        if p.fragment:
            anchors.append((path, p.path, p.fragment))
        if p.path not in seen:
            queue.append(p.path)
for src, path, frag in anchors:
    if path in ids and frag not in ids[path]:
        bad.append("%s -> %s#%s (no anchor)" % (src, path, frag))
print("crawled %d URLs, %d HTML pages" % (len(seen), pages))
for b in bad:
    print("FAIL " + b)
if not bad:
    print("ok   every internal link and anchor resolves")
EOF
echo "python3 /tmp/t/crawl.py" > "$tmp/in/crawl.sh"
echo "== crawl"
out="$(run "$PY_IMAGE" linux/amd64 crawl.sh 2>&1 || true)"
echo "$out"
fails=$((fails + $(printf '%s\n' "$out" | grep -c '^FAIL' || true)))

# --- install.sh --------------------------------------------------------------------------------
cat > "$tmp/in/install-curl.sh" <<EOF
set -eu
[ -s /tmp/t/ca.pem ] && export CURL_CA_BUNDLE=/tmp/t/ca.pem
export PATH="/tmp/home/.local/bin:\$PATH"
echo "user \$(id -un), \$(uname -m)"
curl -fsSL https://runr.sh/install.sh | sh
v="\$(runr version --client -o json 2>/dev/null || runr version --client)"
echo "\$v"
echo "\$v" | grep -q '${version#v}' && echo "ok   installed runr ${version}: \$(command -v runr)" || echo "FAIL runr version"
EOF
cat > "$tmp/in/install-alpine.sh" <<EOF
set -eu
cat /tmp/t/ca.pem >> /etc/ssl/certs/ca-certificates.crt
echo "user \$(id -un), \$(uname -m), downloader: busybox wget"
wget -qO- https://runr.sh/install.sh | RUNR_VERSION=${version} sh
v="\$(runr version --client)"
echo "\$v"
[ "\$(command -v runr)" = /usr/local/bin/runr ] && echo "\$v" | grep -q '${version#v}' && echo "ok   installed runr ${version} into /usr/local/bin" || echo "FAIL runr install as root"
EOF
for spec in "$CURL_IMAGE|linux/amd64|install-curl.sh" "$CURL_IMAGE|linux/arm64|install-curl.sh" \
            "$ALPINE_IMAGE|linux/amd64|install-alpine.sh"; do
  image="${spec%%|*}"; rest="${spec#*|}"; platform="${rest%%|*}"; script="${rest#*|}"
  echo "== install.sh: $image $platform"
  out="$(run "$image" "$platform" "$script" 2>&1 || true)"
  echo "$out"
  printf '%s\n' "$out" | grep -q '^ok ' || fails=$((fails + 1))
done

# --- terraform init ----------------------------------------------------------------------------
cat > "$tmp/in/tf.sh" <<'EOF'
set -eu
TF="${1:-terraform}"
[ -s /tmp/t/ca.pem ] && export SSL_CERT_FILE=/tmp/t/ca.pem
export CHECKPOINT_DISABLE=1 TF_IN_AUTOMATION=1
mkdir -p /tmp/tf && cd /tmp/tf
cat > main.tf <<'TF'
terraform {
  required_providers {
    runr = {
      source  = "runr.sh/brf-tech/runr"
      version = "~> 0.5"
    }
  }
}

provider "runr" {
  api_url = "https://runr.example.com"
  token   = "unused"
}
TF
echo "$($TF version | head -1), $(uname -m)"
$TF init -input=false -no-color 2>&1 | grep -E 'Installing|Installed|signed|Error|error' || true
$TF validate -no-color
if grep -q 'provider "runr.sh/brf-tech/runr"' .terraform.lock.hcl; then
  echo "ok   $TF init installed and verified runr.sh/brf-tech/runr"
else
  echo "FAIL no runr.sh/brf-tech/runr in .terraform.lock.hcl"
fi
EOF
echo 'sh /tmp/t/tf.sh tofu' > "$tmp/in/tofu.sh"
for spec in "$TF_IMAGE|linux/amd64|tf.sh" "$TF_IMAGE|linux/arm64|tf.sh" "$TOFU_IMAGE|linux/amd64|tofu.sh"; do
  image="${spec%%|*}"; rest="${spec#*|}"; platform="${rest%%|*}"; script="${rest#*|}"
  echo "== provider install: $image $platform"
  out="$(run "$image" "$platform" "$script" 2>&1 || true)"
  echo "$out"
  if ! printf '%s\n' "$out" | grep -q '^ok ' || ! printf '%s\n' "$out" | grep -q 'signed'; then
    fails=$((fails + 1))
  fi
done

echo
if [ "$fails" -eq 0 ]; then
  echo "verify: all checks passed"
else
  echo "verify: $fails failed"
fi
[ -n "$keep" ] && echo "server left running: http://localhost:$keep/"
[ "$fails" -eq 0 ]
