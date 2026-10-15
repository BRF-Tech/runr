#!/bin/sh
set -e

ROLE=${CONTAINER_ROLE:-api}

# Prevent the dubious-ownership error when the bind mount has a different owner
# --system: writes to /etc/gitconfig, read by all users (root, www-data). Set before the first
# git command below: on a bind mount whose files belong to another uid (Docker Desktop, WSL drive
# mounts) the initial commit of a fresh storage/git otherwise fails and stops the container.
git config --system --add safe.directory '*'
git config --system user.email "runr@system"
git config --system user.name "RunR System"

# FaaS git repo (create it if missing)
if [ ! -d "storage/git/.git" ]; then
    git init storage/git
    git -C storage/git commit --allow-empty -m "init"
fi

# A bind mount can override the chown from the Dockerfile; enforce the permissions
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Clear the stale bootstrap cache coming from the host bind mount (it may contain dev dependencies)
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php


# Toggle the Tenancy module based on the TENANCY_ENABLED env
if [ "${TENANCY_ENABLED:-false}" = "true" ]; then
    php artisan module:enable Tenancy 2>/dev/null || true
else
    php artisan module:disable Tenancy 2>/dev/null || true
fi

# Toggle the AuthProvider module based on the AUTH_PROVIDER_ENABLED env
if [ "${AUTH_PROVIDER_ENABLED:-false}" = "true" ]; then
    php artisan module:enable AuthProvider 2>/dev/null || true
else
    php artisan module:disable AuthProvider 2>/dev/null || true
fi

# Toggle the Ticket module based on the TICKET_ENABLED env
if [ "${TICKET_ENABLED:-true}" = "true" ]; then
    php artisan module:enable Ticket 2>/dev/null || true
else
    php artisan module:disable Ticket 2>/dev/null || true
fi

# Refresh the package list from vendor (the host cache may be incompatible)
php artisan package:discover --ansi

# Caches — run only in the api/migrator roles
# horizon/scheduler share the same bind mount; concurrent caching causes a race condition
# DEV: with APP_ENV=local, don't cache (so code changes take effect immediately)
if [ "$ROLE" = "api" ] || [ "$ROLE" = "migrator" ] || [ "$ROLE" = "migrator-fresh" ] || [ "$ROLE" = "migrate-only" ]; then
    if [ "$APP_ENV" = "local" ]; then
        php artisan config:clear
        php artisan route:clear
        php artisan view:clear
    else
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache || true
    fi
fi

# Run the module migrations explicitly (nwidart auto-discover may not work on a case-sensitive FS).
# Tenancy's migrations create the central tables (tenants, tenant_users, ...) on the 'central'
# connection, which only the enabled Tenancy module registers: with tenancy off they are skipped
# (they used to stop at create_tenants_table with "Database connection [central] not configured").
# They run once TENANCY_ENABLED=true, like the tenant schema migrations below.
run_module_migrations() {
    for module_dir in Modules/*/Database/Migrations; do
        [ -d "$module_dir" ] || continue
        if [ "$module_dir" = "Modules/Tenancy/Database/Migrations" ] && [ "${TENANCY_ENABLED:-false}" != "true" ]; then
            continue
        fi
        php artisan migrate --path="$module_dir" --realpath --force 2>/dev/null || true
    done
}

# Tenant schemas (with Tenancy on): pending tenant migrations — the Helm upgrade's pre-upgrade
# migrate-only job and the install-time migrator also apply new tenant migrations to existing tenants.
# Idempotent: tenancy:migrate runs only migrations that have not run yet; no-op when there are no tenants.
# Failure = job failure (new code must not start against an old tenant schema).
run_tenant_migrations() {
    [ "${TENANCY_ENABLED:-false}" = "true" ] || return 0
    php artisan tenancy:migrate
}

# Migrate: api only migrates, migrator migrates and seeds, migrate-only migrates without seeding
if [ "$ROLE" = "api" ]; then
    php artisan migrate --force
    run_module_migrations
elif [ "$ROLE" = "migrate-only" ]; then
    php artisan migrate --force
    run_module_migrations
    run_tenant_migrations
elif [ "$ROLE" = "migrator" ]; then
    php artisan migrate --force
    run_module_migrations
    run_tenant_migrations
    php artisan db:seed --force
elif [ "$ROLE" = "migrator-fresh" ]; then
    # Reset the contents of storage/git (except .git) and commit
    find storage/git -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +
    touch storage/git/.gitkeep
    git -C storage/git add -A
    if [ -n "$(git -C storage/git diff --cached --name-only)" ]; then
        git -C storage/git commit -m "chore: fresh migration - git storage cleared"
    fi
    php artisan migrate:fresh --force
    run_module_migrations
    php artisan db:seed --force
    # The fresh database has no runtime versions; the running api container synced them into the
    # old one at its start (below). Offline the next api start syncs them.
    php artisan executor:sync || echo "executor:sync failed; the api container syncs the runtimes at its next start"
fi

# Sync the executor images in the background (api only)
if [ "$ROLE" = "api" ]; then
    php artisan executor:sync &
fi

if [ "$ROLE" = "migrator" ] || [ "$ROLE" = "migrator-fresh" ] || [ "$ROLE" = "migrate-only" ]; then
    exit 0
elif [ "$ROLE" = "reverb" ]; then
    exec php artisan reverb:start-shell --host=0.0.0.0 --port=8080 --debug
elif [ "$ROLE" = "horizon" ]; then
    exec php artisan horizon
elif [ "$ROLE" = "scheduler" ]; then
    exec sh docker/schedule-watch.sh
else
    # Octane state file: octane:reload/status read this file.
    # In embedded mode FrankenPHP may not create the state file, so we seed it.
    # In the background we write the FrankenPHP child PID (required by octane:reload).
    STATE_FILE="storage/logs/octane-server-state.json"
    printf '{"state":{"server":"frankenphp","host":"0.0.0.0","port":80,"adminHost":"localhost","adminPort":2019},"masterProcessId":null}' > "$STATE_FILE"
    (sleep 5 && FPID=$(pgrep -f 'frankenphp run' | head -1) && \
     [ -n "$FPID" ] && \
     printf '{"state":{"server":"frankenphp","host":"0.0.0.0","port":80,"adminHost":"localhost","adminPort":2019},"masterProcessId":%s}' "$FPID" > "$STATE_FILE") &
    exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80 --admin-port=2019 --workers=auto
fi
