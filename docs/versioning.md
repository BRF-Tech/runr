# Versioning

The RunR API reports its version through the `/api/version` endpoint. The versions of a
*project* — every code or configuration change is a version — and rolling a project back are
described under [Rollback](#rollback).

## Endpoint

```
GET /api/version
```

Public endpoint, no authentication required.

**Response:**
```json
{
  "version": "v1.2.0"
}
```

Tag versions (starting with `v`) are returned as is. Any other value except `dev` (for example a commit
SHA) is returned with a `main-` prefix, e.g. `main-3c1a7d7`.

## Setting the version

The version comes from the `APP_VERSION` environment variable. The image bakes in a default from the
`APP_VERSION` build argument (`dev` when none is given); a runtime `APP_VERSION` overrides it.

### Docker Compose (development)

```bash
# With the git commit SHA
docker compose build --build-arg APP_VERSION=$(git rev-parse --short HEAD) api

# With an explicit version
docker compose build --build-arg APP_VERSION=v1.0.0 api
```

### `runtime/ci.sh` builds

`ci.sh build-deploy`, `deploy` and `upgrade` resolve the version with `resolve_app_version`
(`runtime/lib/common.sh`): the exact git tag on `HEAD` if there is one, otherwise `main-<short sha>`. The value
is passed as the `APP_VERSION` build argument and as the Helm value `appVersion`.

### Helm

The chart's ConfigMap sets `APP_VERSION` from `.Values.appVersion`, defaulting to `v` + the chart's
`appVersion` (`helm/Chart.yaml`).

```bash
helm upgrade --install runr brf-tech/runr --set appVersion=v1.0.0
```

## Flow

```
Dockerfile / Helm ConfigMap      config/app.php                       routes/api.php
ARG APP_VERSION=dev   --->  'version' => env('APP_VERSION', 'dev')  --->  config('app.version')
ENV APP_VERSION=...              |                                          |
                                 v                                          v
                        safe with config:cache                       GET /api/version
```

1. `Dockerfile` → `ARG APP_VERSION=dev` + `ENV APP_VERSION=${APP_VERSION}` (baked in at build time)
2. `config/app.php` → `'version' => env('APP_VERSION', 'dev')` (moves the env value into config)
3. `routes/api.php` → `config('app.version')` (not `env()`, so it works with `config:cache`)

## Note

Routes and controllers **never** call `env()`. `env()` is used only in `config/*.php`. Once
`config:cache` has run, `.env` is not loaded and `env()` calls return `null`, so always use the `config()`
helper.

## Rollback

Every code save, upload, push and configuration change of a project is a version (`v1`, `v2`, …),
and every deploy of a version is a deployment in one of the project's environments
([Environments](environments.md)). A deployment records the **effective configuration** it was
applied with — the project's configuration with the environment's and the deploy's overrides:
image, command and ports of a CaaS project, handler, env vars, CPU, memory, scale, trigger,
schedule, queue stream, timeout — as `config_snapshot` (encrypted at rest; the API shows env var
values only with `env.view`). `config_source` says where it came from: `current` (the
configuration of its time) or `restored` (a rollback).

A rollback deploys an earlier version **with the configuration it ran with**:

```bash
# Roll back to a deployment: its version and its configuration
curl -X POST https://runr.example.com/api/projects/$PROJECT/deployments/$DEPLOYMENT/rollback \
  -H "Authorization: Bearer $TOKEN"

# … keeping the configuration of now (only the code goes back)
curl -X POST https://runr.example.com/api/projects/$PROJECT/deployments/$DEPLOYMENT/rollback \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"keep_current_config": true}'

# Deploy an older version with the newest configuration recorded for it in that environment
curl -X POST https://runr.example.com/api/projects/$PROJECT/deploy \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"version_id": "01J…", "restore_config": true}'
```

- **Which configuration**: the deployment rolled back to; with `restore_config` on the deploy
  endpoint, `restore_from_deployment_id`, else the newest deployment of the version in that
  environment; for versions deployed before deployments recorded their configuration, the
  configuration of the version's time (the newest configuration version up to it) with the
  environment's overrides. Nothing recorded at all: the current configuration (`config_source`
  `current` says so).
- **Not restored**: secret variables and bindings stay the current ones — credentials are rotated
  forward, never back — and the project's stored configuration is not changed: the next deploy
  with the current configuration runs it again.
- **The image of a CaaS project is code**: a deploy of an older version runs that version's image,
  also with `keep_current_config` (before, every deploy ran the image configured now).
- A rollback replaces only the live deployment of its environment, goes through every deploy
  check, and is audited as `deployment.rollback`.

Console: **Roll back** on a deployment, with **Keep the current configuration**. CLI:
`runr rollback v3` (`--keep-config`, `--env`). MCP: `rollback` (`keep_current_config`,
`environment`).
