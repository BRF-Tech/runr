#!/usr/bin/env bash
# Exports the RunR API's OpenAPI document (php artisan scramble:export) from ../api into the
# file given as $1 (default: pkg/runr/testdata/openapi.json). The contract test of package
# runr checks the client against it.
#
# With PHP and the API's vendor/ present it runs locally; otherwise it runs in the RunR API
# image ($RUNR_API_IMAGE), which has PHP, its extensions and composer: the current api/ code is
# copied into the container, its dependencies installed, the Tenancy, AuthProvider and Ticket
# modules enabled (their routes are in the document, so the contract test checks the
# multi-tenant endpoints too) and a throw-away SQLite database migrated (Scramble reads model
# columns). A local run documents the modules the local api/ has enabled.
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
api="$(cd "$here/../api" && pwd)"
out="${1:-$here/pkg/runr/testdata/openapi.json}"
case "$out" in /*) ;; *) out="$here/$out" ;; esac
image="${RUNR_API_IMAGE:-ghcr.io/runrsh/runr/api:latest}"

# The document's info.description is the deployment's own text; the contract test does not
# use it, so it is stored neutral.
normalize() {
  sed -i.bak 's/"description": "[^"]*RunR API"/"description": "RunR API"/' "$out" && rm -f "$out.bak"
}

if command -v php >/dev/null 2>&1 && [ -d "$api/vendor" ]; then
  (cd "$api" && php -d memory_limit=2G artisan scramble:export --path="$out")
  normalize
  exit 0
fi

outdir="$(dirname "$out")"
mkdir -p "$outdir"
docker run --rm \
  -v "$api":/src:ro -v "$outdir":/out \
  --entrypoint /bin/sh "$image" -c '
set -e
mkdir -p /tmp/app
cd /src && tar --exclude=./vendor --exclude=./node_modules --exclude=./storage/git -cf - . | (cd /tmp/app && tar xf -)
[ -d /var/www/html/vendor ] && cp -r /var/www/html/vendor /tmp/app/vendor
cd /tmp/app
composer install --no-interaction --no-scripts --ignore-platform-reqs --quiet
cp .env.example .env
php artisan package:discover --quiet
php artisan key:generate --quiet
for m in Tenancy AuthProvider Ticket; do php artisan module:enable "$m" --quiet; done
sed -i "/^TENANCY_ENABLED=/d" .env && echo TENANCY_ENABLED=true >> .env
touch database/database.sqlite
php artisan migrate --force --quiet
for d in Modules/*/Database/Migrations; do php artisan migrate --path="$d" --realpath --force --quiet; done
php -d memory_limit=2G artisan scramble:export --path=/out/'"$(basename "$out")"'
'
normalize
echo "wrote $out"
