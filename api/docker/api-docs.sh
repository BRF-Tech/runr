#!/bin/sh
# Builds the OpenAPI document the API image serves (GET /api/docs.json, the API reference) as the
# image does: every module enabled, the models' columns read from a throw-away SQLite database
# (Scramble), `php artisan runr:api-docs`. The api-docs stage of api/Dockerfile.prod runs it, and
# CI (.github/workflows/ci.yml, job api) runs it with --check on every pull request, so the module
# operations are checked as well (ApiDocument::problems).
#
#   sh docker/api-docs.sh <path> [runr:api-docs options, e.g. --check]
#
# It enables every module of the checkout (modules_statuses.json) and writes the database
# $API_DOCS_DATABASE (default /tmp/api-docs.sqlite): meant for the image build and CI, not the dev
# stack (there: php artisan runr:api-docs, docs/development.md).
set -eu

path=${1:?usage: sh docker/api-docs.sh <path> [runr:api-docs options]}
shift
cd "$(dirname "$0")/.."

export APP_ENV=production APP_DEBUG=false APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
       DB_CONNECTION=sqlite DB_DATABASE="${API_DOCS_DATABASE:-/tmp/api-docs.sqlite}" \
       CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null \
       LOG_CHANNEL=stderr TENANCY_ENABLED=true AUTH_PROVIDER_ENABLED=true TICKET_ENABLED=true \
       BILLING_ENABLED=true

rm -f "$DB_DATABASE"
touch "$DB_DATABASE"
php artisan package:discover --quiet
for m in Tenancy AuthProvider Ticket Billing; do php artisan module:enable "$m" --quiet; done
php artisan migrate --force --quiet
for d in Modules/*/Database/Migrations; do php artisan migrate --path="$d" --realpath --force --quiet; done
php -d memory_limit=2G artisan runr:api-docs --path="$path" "$@"
