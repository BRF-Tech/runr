# Versioning

The RunR API reports its version through the `/api/version` endpoint.

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
helm upgrade --install runr runr/runr --set appVersion=v1.0.0
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
