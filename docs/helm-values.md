# Helm values reference

Every value of the Helm chart `runr` 0.6.0 from BRF's public Helm repository, grouped by top-level
key, with its default from the chart's `values.yaml` and what the templates do with it.
`helm show values brf-tech/runr --version 0.6.0` prints the file with its comments. How these
values fit together in a production install is described in [Installing RunR in
production](install.md).

- [Validation](#validation)
- [Console and gateway](#console-and-gateway): `domain`, `cors`, `gateway`, `ingress`, `clientIp`
- [Application pods](#application-pods): `api`, `ui`, `reverb`, `horizon`, `scheduler`, `app`,
  `appVersion`, `queue`, `cache`, `extraEnv`
- [Data services](#data-services): `database`, `redis`, `storage`, `mail`, `mailpit`
- [Secret and first administrator](#secret-and-first-administrator): `secretName`, `secret`,
  `admin`, `bootstrap`
- [Edition](#edition): `edition`, `tenancy`, `authProvider`, `ticket`
- [Platform settings](#platform-settings): `quota`, `audit`, `projectSecrets`, `auth`, `billing`,
  `branding`, `signup`
- [Regions and features](#regions-and-features): `knative`, `observability`, `sandboxApi`,
  `gitDeploy`, `supplyChain`, `globalDns`, `images`, `runtimePatches`, `apiProduct`, `orgTransfer`,
  `ai`, `bindings`
- [Scheduling and cluster](#scheduling-and-cluster): `podColocation`, `placement`,
  `networkPolicy`, `imagePullSecrets`, `imagePullSecretsFromDefaultServiceAccount`, `initImage`,
  `namespace`, `createNamespace`, `projectNamespace`, `deployTimestamp`

Most values become environment variables of the API, Horizon, scheduler and Reverb pods and of the
hook Jobs through the ConfigMap `runr-config`; the variable is named where it helps.

## Validation

Helm checks the values against the chart's `values.schema.json` on `install`, `upgrade`,
`template` and `lint`. The schema covers these keys:

| Keys | Checked |
| --- | --- |
| `domain` | Must be present, as a string (the default `""` passes) |
| `edition` | `single-tenant` or `multi-tenant` |
| `admin.username`, `admin.email`, `admin.password` | A lower-case letter followed by 2–31 of `a-z`, `0-9`, `_`, `.`, `-`; an e-mail address; a string |
| `ingress.enabled`, `ingress.className`, `ingress.tls.*` | Types; `ingress.tls.mode` is `""`, `letsencrypt`, `custom`, `secret` or `none` |
| `cors.allowedOrigins` | A list of origins (`https://host[:port]`) or one comma-separated string of them |
| `gateway.affinity`, `api.affinity`, `ui.affinity` | Objects |
| `api.storage.size`, `database.postgres.storage` | `<number>Gi` |
| `api.storage.accessModes`, `api.storage.storageClassName`, `database.postgres.storageClassName` | A non-empty list of `ReadWriteOnce` / `ReadWriteMany`; strings |
| `database.password`, `database.existingSecret`, `database.existingSecretPasswordKey`, and the same keys and `username` of `redis` and `mail` | Strings |
| `mail.encryption` | `""`, `starttls` or `tls` |
| `storage.application`, `storage.database` | `<number>Gi` or empty |
| `quota.cpu`, `quota.memoryGiB` | A number of at least 0, a numeric string or empty |
| `projectSecrets.*` | `storage` `region` or `database`; `finalize` a boolean |
| `knative.*` | `minK8sMinor` a string or an integer; `traefikImage` a string; `certManager.install` a boolean, `certManager.acmeEmail`, `acmeServer`, `chartVersion`, `imagePrefix` strings |
| `clientIp.trustedProxies`, `clientIp.proxyHops` | A string; an integer of at least 0 |
| `ai.*` | `huggingface.endpoint` an http(s) URL; `gpu.defaultTimeout` 1–3600; `gpu.progressDeadline` seconds or minutes (`1800s`); `gpu.scaleToZeroRetention` seconds, minutes or hours (`5m`); `modelStorage.sizeGi` at least 1; `modelStorage.accessMode` `ReadWriteMany` or `ReadWriteOnce`; the image and chart version keys are strings |
| `billing.*` | `enabled` a boolean; `currency` three capital letters (ISO 4217); `webhookUrl` an http(s) URL or empty |
| `branding.*` | `productName` up to 80 and `companyName` up to 120 characters; logos and favicon empty, an https URL, a path, a `data:image/…;base64,…` URI (up to 750000 characters) or a ConfigMap / Secret key reference; colours `#rgb` / `#rrggbb` (`primary` also as the `300`–`900` scale); `supportUrl` an http(s) URL, `supportEmail` an address; up to 8 `footerLinks` with a label and an http(s) or `mailto:` URL |
| `observability.*` | `tracing.defaultMode` `managed`, `reuse`, `external` or `off`; `tracing.knativeSampleRate` 0–1; `tracing.tempoStorage` `<number>Mi`, `Gi` or `Ti`; lower bounds of the retention, interval and count keys (`uptime.minIntervalSeconds` at least 10, `loadTest.maxUsers` at least 10, `loadTest.maxDurationSeconds` at least 30); `replay.ingestUrl` an http(s) URL or empty |
| `sandboxApi.enabled`, `sandboxApi.namespacePrefix`, `sandboxApi.allowCustomImages` | Booleans; `namespacePrefix` a DNS label of up to 22 characters |
| `bindings.namespace`, `bindings.retentionDays`, `bindings.storageClass`, `bindings.quotas.*` | A DNS label; at least 1; a string; integers of at least 0 |
| `gitDeploy.*` | `maxPushMb` 1–60; `remoteOwner` letters, digits, `.`, `_`, `-`; `webhookBaseUrl` an http(s) URL or empty; `previews.limit` 0–20, `previews.ttlHours` 1–720, `previews.maxLimit` 0–100 |
| `supplyChain.*` | `enabled` a boolean (or `"true"` / `"false"`); the image, namespace, source and repository keys strings; the hour keys and `policyController.chartVersion` strings or numbers |
| `globalDns.*` | `zone`, `namespace`, `image`, `storageClass`, `geoipUrl`, `tls.acmeServer`, `tls.acmeEmail`, `dataLink.image` strings; `serviceAnnotations` an object of strings; `ttl`, `maxRegions`, `failover.afterSeconds`, `dataLink.port` strings or integers; `tls.mode` `""`, `acme` or `none` |
| `images.*` | `uploadImage` a string; `quotaGb`, `maxUploadGb`, `uploadTtlHours` strings or numbers |
| `runtimePatches.*` | `feed`, `publicKey`, `viaRegion` strings; `autoMigrate` a boolean (or `"true"` / `"false"`) |
| `apiProduct.*` | `gatewayAuthImage`, `callbackUrl` strings; `syncSeconds`, `usageRetentionDays` strings or numbers |
| `orgTransfer.*` | `transferImage` a string; `maxPackageGb`, `retentionHours`, `podDiskGb` strings or numbers |
| `placement.*` | `tolerateDedicatedWorkers` a boolean, `tolerations` a list, `affinity` an object, `nodeSelector` string values |
| `networkPolicy.*` | `enabled` a boolean; `gatewayFrom`, `egress.to` and `extraEgress` lists of objects; `egress.ports` port numbers |

Keys outside this table reach the templates as given (`api.images` and `api.supplyChain`, which
earlier schemas listed, are accepted and not read).

The templates stop an install with a message when:

| Values | Message says |
| --- | --- |
| `database.driver` other than `pgsql` | RunR's pods share one PostgreSQL server |
| `secret.create: false` with `database.password`, `redis.password` or `mail.password` | there is no chart Secret to write it to: use `*.existingSecret` |
| `ingress.tls.mode` other than `""`, `letsencrypt`, `custom`, `secret` or `none` | the supported modes |
| `ingress.tls.mode: secret` without `ingress.tls.secretName` | name your TLS Secret |
| `ingress.tls.mode: custom` with an empty `certificate` or `privateKey`, or one that is not PEM | PEM required |
| `mail.encryption` other than `""`, `starttls` or `tls` (with `mail.host` set) | the supported values |
| `reverb.replicas` below 1 | Reverb is required |
| `api.storage.accessModes` / `storageClassName` or `database.postgres.storageClassName` other than those of the existing claim | Kubernetes does not change them ([moving the API volume](install.md#moving-the-api-volume-to-readwritemany)) |
| a cluster older than Kubernetes 1.23 (the chart's `kubeVersion`) | the chart's minimum version |

## Console and gateway

### domain

| Key | Default | What it does |
| --- | --- | --- |
| `domain` | `""` | Host name of the console, e.g. `runr.example.com`. Sets `APP_URL` and `FRONTEND_URL` to `https://<domain>`, the origin CORS allows; the Ingress is created only when it is set. With it the session cookie (Horizon dashboard) is sent over HTTPS only |

### cors

The console calls the API on its own origin and needs no CORS. Browser pages on other origins
that call the API (`/api/*`) need their origin allowed.

| Key | Default | What it does |
| --- | --- | --- |
| `cors.allowedOrigins` | `[]` | Origins allowed besides `https://<domain>`, e.g. `["https://portal.example.com"]` (`CORS_ALLOWED_ORIGINS`) |

### gateway

`runr-gateway` (Caddy) is the single entry point: it routes `/` to the UI, `/api`, `/git`,
`/mcp`, `/oauth2`, `/.well-known/oauth-*`, `/oauth/<provider>/callback`, `/horizon` and
`/storage` to the API and `/ws` to Reverb. It keeps the `X-Forwarded-For` and
`X-Forwarded-Proto` of an ingress controller in `clientIp.trustedProxies`, so the API sees the
client's address and builds `https` URLs.

| Key | Default | What it does |
| --- | --- | --- |
| `gateway.replicas` | `2` | Replicas of `runr-gateway`; while `gateway.hpa.enabled`, the HPA owns the count |
| `gateway.affinity` | `{}` | Affinity of the gateway pods instead of `placement.affinity`; empty: `placement.affinity` |
| `gateway.serviceType` | `ClusterIP` | Type of the Service `runr-gateway`; `LoadBalancer` or `NodePort` expose the gateway without the Ingress (`helm status` prints how to reach it) |
| `gateway.port` | `80` | Port Caddy listens on and the port of the Service |
| `gateway.image.repository` | `ghcr.io/brf-tech/runr/reqs/caddy` | Gateway image |
| `gateway.image.tag` | `2-alpine` | |
| `gateway.image.pullPolicy` | `IfNotPresent` | |
| `gateway.resources` | requests `50m` / `64Mi`, limits `250m` / `128Mi` | |
| `gateway.hpa.enabled` | `false` | HorizontalPodAutoscaler on CPU utilization |
| `gateway.hpa.minReplicas`, `maxReplicas`, `targetCPU` | `2`, `6`, `70` | Replica range and target CPU utilization in percent |
| `gateway.pdb.enabled` | `false` | PodDisruptionBudget with `maxUnavailable: 1` |

### ingress

The Ingress `runr` is created when `ingress.enabled` is `true` and `domain` is set: host
`<domain>`, path `/` (Prefix) to `runr-gateway` on `gateway.port`.

| Key | Default | What it does |
| --- | --- | --- |
| `ingress.enabled` | `true` | Creates the Ingress |
| `ingress.className` | `traefik` | `ingressClassName` of the Ingress; empty: none is set |
| `ingress.annotations` | `{}` | Annotations of the Ingress. With ingress-nginx, the MCP streams need `nginx.ingress.kubernetes.io/proxy-buffering: "off"` and `nginx.ingress.kubernetes.io/proxy-read-timeout: "900"` |
| `ingress.tls.mode` | `""` | The console certificate, see below |
| `ingress.tls.enabled` | `false` | Only with `mode: ""`: adds a `tls` section with `secretName` |
| `ingress.tls.secretName` | `""` | Name of the TLS Secret; empty: `runr-tls` (`letsencrypt`, and `mode: ""` with `enabled`) or `runr-tls-custom` (`custom`); required with `secret` |
| `ingress.tls.clusterIssuer` | `""` | ClusterIssuer of the cert-manager annotation; empty: `letsencrypt-prod` (`letsencrypt`). With `mode: ""` the annotation is added only when this is set |
| `ingress.tls.certificate` | `""` | `custom`: the full PEM chain, server certificate first; PEM or base64-encoded PEM |
| `ingress.tls.privateKey` | `""` | `custom`: the certificate's unencrypted private key (PEM); never written to any output |

| `ingress.tls.mode` | Result |
| --- | --- |
| `letsencrypt` | Annotation `cert-manager.io/cluster-issuer` and a `tls` section; cert-manager issues the certificate into the Secret. Without the `cert-manager.io/v1` API in the cluster the Ingress gets neither, like `none` |
| `custom` | The chart creates a `kubernetes.io/tls` Secret from `certificate` and `privateKey`; the install stops with an error when either is empty or not PEM |
| `secret` | A `tls` section with your `kubernetes.io/tls` Secret `secretName` in the release namespace; nothing is created |
| `none` | No `tls` section; the ingress controller serves its default certificate |
| `""` | The keys of releases up to 0.3.5 apply: `enabled` adds the `tls` section with `secretName`, `clusterIssuer` adds the annotation |

Any other mode stops the install with an error. The console's TLS Secret name is also passed to
the API (`CONSOLE_TLS_SECRET_NAME`), whose ServiceAccount may read that one Secret: a region's
function domain can reuse the console certificate ([regions.md](regions.md)).

### clientIp

The request path is client → ingress controller → `runr-gateway` → `runr-api`. The API takes the
client address — per-IP rate limits, IPs in logs and audit records — from `X-Forwarded-For` only as
far as these proxies wrote it.

| Key | Default | What it does |
| --- | --- | --- |
| `clientIp.trustedProxies` | `private_ranges` | Addresses the proxies connect from: CIDRs, comma-separated. `runr-gateway` keeps (and appends to) the `X-Forwarded-For`, and keeps the `X-Forwarded-Proto`, of an ingress controller in these ranges; `runr-api` (`TRUSTED_PROXIES`) accepts them from a gateway in them. `private_ranges` is RFC 1918, IPv6 ULA, loopback and link-local; a narrower value is the cluster's pod CIDR |
| `clientIp.proxyHops` | `0` | Proxies in front of `runr-api` that append to `X-Forwarded-For` (`TRUSTED_PROXY_HOPS`). `0` is automatic: 2 with the chart's Ingress (ingress controller and gateway), 1 without it (the gateway then replaces the header with the connecting address). Add 1 per HTTP proxy in front of the ingress controller whose header the controller keeps, e.g. an L7 load balancer; a load balancer that sends PROXY protocol adds none |

## Application pods

Replicas and resources with the chart defaults:

| Component | Replicas | Requests (CPU / memory) | Limits (CPU / memory) |
| --- | --- | --- | --- |
| `runr-gateway` | 2 | `50m` / `64Mi` | `250m` / `128Mi` |
| `runr-api` | 2 | `200m` / `256Mi` | `1` / `1Gi` |
| `runr-ui` | 2 | `50m` / `64Mi` | `200m` / `128Mi` |
| `runr-reverb` | 1 | `100m` / `128Mi` | `500m` / `512Mi` |
| `runr-horizon` | 1 | `200m` / `256Mi` | `1` / `1Gi` |
| `runr-scheduler` | 1 | `50m` / `64Mi` | `256m` / `256Mi` |
| `runr-redis` (bundled) | 1 | `100m` / `128Mi` | `500m` / `512Mi` |
| `runr-postgres` (bundled) | 1 | `200m` / `256Mi` | `1` / `1Gi` |
| `runr-mailpit` (bundled) | 1 | `25m` / `32Mi` | `100m` / `64Mi` |

### api

The API image also runs Horizon, the scheduler, Reverb and the hook Jobs.

| Key | Default | What it does |
| --- | --- | --- |
| `api.image.repository` | `ghcr.io/brf-tech/runr/api` | API image |
| `api.image.tag` | `""` | Empty: the chart's `appVersion`, so `--version` pins the image and `helm rollback` rolls it back. A tag that is set (`latest` included) is used as it is ([Upgrading](upgrade.md#procedure)) |
| `api.image.pullPolicy` | `IfNotPresent` | |
| `api.replicas` | `2` | Replicas of `runr-api`; while `api.hpa.enabled`, the HPA owns the count |
| `api.port` | `80` | Port Octane listens on in the API container (`RUNR_API_PORT`), the container port, its probes and the Service's target port (the Service itself stays on 80) |
| `api.resources` | requests `200m` / `256Mi`, limits `1` / `1Gi` | |
| `api.storage.size` | `10Gi` | Size of the PVC `runr-api-storage`; `storage.application` replaces it when set |
| `api.storage.accessModes` | `[ReadWriteOnce]` | Access modes of `runr-api-storage`, set when the claim is created. ReadWriteOnce: the pods that mount it run on its node (`podColocation`); `[ReadWriteMany]` (with a ReadWriteMany StorageClass): on any node ([Storage](install.md#storage)) |
| `api.storage.fixPermissions` | `true` | Init container in every pod that mounts the API volume: `chown -R 82:82` and `chmod -R 775` (the `www-data` user; some volume types are created owned by root) |
| `api.storage.storageClassName` | not set | StorageClass of `runr-api-storage`, set when the claim is created; unset: the cluster's default StorageClass |
| `api.affinity` | `{}` | Affinity of the API pods instead of `placement.affinity`; empty: `placement.affinity` |
| `api.hpa.enabled` | `false` | HorizontalPodAutoscaler on CPU utilization |
| `api.hpa.minReplicas`, `maxReplicas`, `targetCPU` | `2`, `6`, `70` | |
| `api.pdb.enabled` | `false` | PodDisruptionBudget with `maxUnavailable: 1`, created with a ReadWriteMany API volume. With ReadWriteOnce the API pods cannot leave the volume's node, a budget would hold a drain of that node forever, and none is created (`helm status` says so) |

### ui

| Key | Default | What it does |
| --- | --- | --- |
| `ui.image.repository` | `ghcr.io/brf-tech/runr/ui` | UI image |
| `ui.image.tag` | `""` | Empty: the chart's `appVersion`, as `api.image.tag`. A tag that is set (`latest` included) is used as it is |
| `ui.image.multiTenantSuffix` | `-mt` | Appended to the tag with `edition: multi-tenant`: the multi-tenant UI, built with the Tenancy and Auth Provider modules, is published as `<version>-mt`, so the default is `<appVersion>-mt`. The tag `latest` has no multi-tenant variant: the multi-tenant edition runs `<appVersion>-mt` for it too. `""`: the tag as it is (a UI image of your own that carries the modules) |
| `ui.image.pullPolicy` | `IfNotPresent` | |
| `ui.replicas` | `2` | Replicas of `runr-ui`; while `ui.hpa.enabled`, the HPA owns the count |
| `ui.port` | `80` | Port Caddy listens on in the UI container (`RUNR_UI_PORT`), the container port, its probe and the Service's target port (the Service itself stays on 80) |
| `ui.affinity` | `{}` | Affinity of the UI pods instead of `placement.affinity`; empty: `placement.affinity` |
| `ui.resources` | requests `50m` / `64Mi`, limits `200m` / `128Mi` | |
| `ui.hpa.enabled` | `false` | HorizontalPodAutoscaler on CPU utilization |
| `ui.hpa.minReplicas`, `maxReplicas`, `targetCPU` | `2`, `4`, `70` | |
| `ui.pdb.enabled` | `false` | PodDisruptionBudget with `maxUnavailable: 1` |

### reverb

Reverb, the WebSocket server, is always installed.

| Key | Default | What it does |
| --- | --- | --- |
| `reverb.replicas` | `1` | Replicas of `runr-reverb`. From 2 on the replicas share broadcasts over Redis publish/subscribe (`REVERB_SCALING_ENABLED`); a shell, sandbox exec or region agent session is one WebSocket that stays on the replica it reached, which reads the session from Redis |
| `reverb.port` | `8080` | Port Reverb listens on (`REVERB_SERVER_PORT`), the port of the Service and the probes, and `REVERB_PORT` |
| `reverb.resources` | requests `100m` / `128Mi`, limits `500m` / `512Mi` | |

### horizon

| Key | Default | What it does |
| --- | --- | --- |
| `horizon.replicas` | `1` | Replicas of `runr-horizon`, the queue workers. The Deployment uses the `Recreate` strategy; a stopping pod lets its running jobs end (`horizon:terminate`) within a 60-second grace period |
| `horizon.resources` | requests `200m` / `256Mi`, limits `1` / `1Gi` | |

### scheduler

`runr-scheduler` runs the scheduled tasks with one replica.

| Key | Default | What it does |
| --- | --- | --- |
| `scheduler.resources` | requests `50m` / `64Mi`, limits `256m` / `256Mi` | |

### app, queue and cache

| Key | Default | What it does |
| --- | --- | --- |
| `app.env` | `production` | `APP_ENV`. Keep `production`: with `local` the Horizon dashboard admits every request, the API skips its configuration cache, Reverb logs every message and the install seeds development data |
| `app.debug` | `"false"` | `APP_DEBUG`; `"true"` also makes Reverb log every connection and message |
| `app.logChannel` | `stderr` | `LOG_CHANNEL` |
| `appVersion` | not set | `APP_VERSION`, the version `GET /api/version` reports; unset: `v` and the chart's `appVersion`. Not in `values.yaml`: `runtime/ci.sh` deploys set it to the build's version ([versioning.md](versioning.md#helm)) |
| `queue.connection` | `redis` | `QUEUE_CONNECTION` |
| `cache.store` | `redis` | `CACHE_STORE` |

### extraEnv

| Key | Default | What it does |
| --- | --- | --- |
| `extraEnv` | `{}` | Additional environment variables (`NAME: value`) for the API, Horizon, scheduler and Reverb pods and the hook Jobs, written to the ConfigMap `runr-config` — no secrets. For settings the chart has no value of its own for, e.g. `DB_SSLMODE`; passwords come from `database.*`, `redis.*` and `mail.*` |

Settings without a Helm value are described with their features: the load tests' Job
(`OBS_LOADTEST_*`, [load-test.md](load-test.md)), rightsizing (`RIGHTSIZING_*`,
[monitoring.md](monitoring.md#rightsizing)), the rollouts of deploy strategies (`RUNR_ROLLOUT_*`,
[deploy-strategies.md](deploy-strategies.md)), triggers, the webhook inbox and topics
(`TRIGGERS_*`, [triggers.md](triggers.md)), organization export / import
(`RUNR_ORG_TRANSFER_*`, [org-export.md](org-export.md)), External Secrets Operator
(`RUNR_ESO_*`, [secret-stores.md](secret-stores.md)), and on this page OpenBao
([projectSecrets](#projectsecrets)), the region components' images ([knative](#knative)),
global DNS ([globalDns](#globaldns)) and gateway-auth ([apiProduct](#apiproduct)).

## Data services

### database

| Key | Default | What it does |
| --- | --- | --- |
| `database.driver` | `pgsql` | `DB_CONNECTION`: PostgreSQL, the database the API, Horizon, scheduler and Reverb pods share |
| `database.host` | `""` | Empty: the chart installs PostgreSQL (`runr-postgres`, host `runr-postgres`). Set: that external server, no PostgreSQL is installed |
| `database.port` | `"5432"` | `DB_PORT` |
| `database.name` | `runr` | `DB_DATABASE`; the database the chart's PostgreSQL creates |
| `database.user` | `runr` | `DB_USERNAME`; the user the chart's PostgreSQL creates |
| `database.password` | `""` | The user's password, written to the chart's Secret as `DB_PASSWORD` (`secret.create: true`). Empty: the password kept from an earlier release, else generated on the first install |
| `database.existingSecret` | `""` | A Secret of yours with the password; the pods (and the chart's PostgreSQL) read `DB_PASSWORD` from it instead of the chart's Secret |
| `database.existingSecretPasswordKey` | `password` | Key of the password in `database.existingSecret` |
| `database.postgres.image.repository` | `ghcr.io/brf-tech/runr/reqs/postgres` | Image of the chart's PostgreSQL |
| `database.postgres.image.tag` | `17-alpine` | |
| `database.postgres.storage` | `10Gi` | Size of the PVC `runr-postgres-data` (ReadWriteOnce); `storage.database` replaces it when set |
| `database.postgres.storageClassName` | `""` | StorageClass of `runr-postgres-data`, set when the claim is created; empty: the cluster's default StorageClass |
| `database.postgres.fixPgdata` | `true` | Init container: removes `lost+found` from the volume and keeps the data in the subdirectory `pgdata` (`PGDATA`), owned by UID 70 |
| `database.postgres.resources` | requests `200m` / `256Mi`, limits `1` / `1Gi` | |
| `database.postgres.pdb.enabled` | `false` | PodDisruptionBudget with `maxUnavailable: 1` |

The chart's PostgreSQL applies the password when it initialises an empty volume; a later change
needs `ALTER USER` first ([PostgreSQL](install.md#postgresql)).

### redis

| Key | Default | What it does |
| --- | --- | --- |
| `redis.host` | `""` | Empty: the chart installs Redis (`runr-redis`, no volume). Set: that external server, no Redis is installed |
| `redis.port` | `"6379"` | `REDIS_PORT` |
| `redis.username` | `""` | ACL user (`REDIS_USERNAME`); empty: the default user |
| `redis.password` | `""` | Password, written to the chart's Secret as `REDIS_PASSWORD` (`secret.create: true`) and read by the pods from there |
| `redis.existingSecret` | `""` | A Secret of yours with the password; the pods read `REDIS_PASSWORD` from it |
| `redis.existingSecretPasswordKey` | `password` | Key of the password in `redis.existingSecret` |
| `redis.image.repository` | `ghcr.io/brf-tech/runr/reqs/redis` | Image of the chart's Redis |
| `redis.image.tag` | `7-alpine` | |
| `redis.resources` | requests `100m` / `128Mi`, limits `500m` / `512Mi` | |
| `redis.pdb.enabled` | `false` | PodDisruptionBudget with `maxUnavailable: 1` |

With a password set (`redis.password` or `redis.existingSecret`), the chart's Redis requires it as
well (`--requirepass`).

### storage

Shortcuts for the two disk sizes, e.g. from an install form. A PVC can grow, never shrink.

| Key | Default | What it does |
| --- | --- | --- |
| `storage.application` | `""` | When set, the size of `runr-api-storage` instead of `api.storage.size` |
| `storage.database` | `""` | When set, the size of `runr-postgres-data` instead of `database.postgres.storage` |

### mail and mailpit

RunR always sends through SMTP (`MAIL_MAILER=smtp`).

| Key | Default | What it does |
| --- | --- | --- |
| `mail.host` | `""` | Empty: Mailpit is installed and receives every message (`runr-mailpit:1025`). Set: that SMTP server, no Mailpit |
| `mail.port` | `"1025"` | `MAIL_PORT`, used with `mail.host` |
| `mail.from` | `noreply@runr.sh` | `MAIL_FROM_ADDRESS` |
| `mail.fromName` | `RunR` | `MAIL_FROM_NAME` |
| `mail.username` | `""` | SMTP user (`MAIL_USERNAME`), used with `mail.host` |
| `mail.password` | `""` | SMTP password, written to the chart's Secret as `MAIL_PASSWORD` (`secret.create: true`) and read by the pods from there |
| `mail.existingSecret` | `""` | A Secret of yours with the SMTP password; the pods read `MAIL_PASSWORD` from it |
| `mail.existingSecretPasswordKey` | `password` | Key of the password in `mail.existingSecret` |
| `mail.encryption` | `""` | `""` or `starttls`: STARTTLS when the server offers it (TLS from the first byte on port 465); `tls`: TLS from the first byte on any port (`MAIL_SCHEME=smtps`) |
| `mailpit.image.repository` | `ghcr.io/brf-tech/runr/reqs/mailpit` | Mailpit image |
| `mailpit.image.tag` | `latest` | |
| `mailpit.uiPort` | `8025` | Port of Mailpit's web UI in the pod and the Service (the image serves it on 8025) |
| `mailpit.resources` | requests `25m` / `32Mi`, limits `100m` / `64Mi` | |

## Secret and first administrator

### secretName and secret

| Key | Default | What it does |
| --- | --- | --- |
| `secretName` | `runr-secret` | The Secret every pod reads: `APP_KEY`, `REVERB_APP_SECRET`, `DB_PASSWORD` (unless `database.existingSecret`) and the optional `ADMIN_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `APP_PREVIOUS_KEYS`, `BILLING_WEBHOOK_SECRET`, `SIGNUP_CAPTCHA_SECRET` |
| `secret.create` | `true` | `true`: the chart creates the Secret. The first install generates `APP_KEY`, `REVERB_APP_SECRET`, `DB_PASSWORD` (unless `database.password` or `database.existingSecret` gives it) and `ADMIN_PASSWORD`; upgrades read the existing Secret (`lookup`) and keep them. `database.password`, `redis.password` and `mail.password` are written to it while set. `false`: create the Secret before installing; the chart only references it, and the passwords of the database, Redis and SMTP server come from `DB_PASSWORD` of it or from `*.existingSecret` |
| `secret.appKey` | `""` | Replaces `APP_KEY` (`base64:` and 32 random bytes, base64). Empty: generated at install and kept on upgrade. Used for a key rotation and a restore, and by tools that render without cluster access |
| `secret.previousAppKeys` | `""` | `APP_PREVIOUS_KEYS`, comma-separated earlier keys: values encrypted with them still decrypt. Present only while set |

`APP_KEY` encrypts the secrets stored in the database ([security.md](security.md#encryption-at-rest));
the rotation procedure is in [security.md](security.md#key-rotation). With `secret.create: false`
the values `secret.appKey`, `secret.previousAppKeys`, `admin.password`, `billing.webhookSecret` and
`signup.captcha.secret` are not used: put the keys into your Secret.

### admin

| Key | Default | What it does |
| --- | --- | --- |
| `admin.username` | `admin` | User name of the first administrator (`ADMIN_USERNAME`); it signs in with this or `admin.email` |
| `admin.email` | `admin@example.com` | Its e-mail address (`ADMIN_EMAIL`) |
| `admin.password` | `""` | Its password, written to `ADMIN_PASSWORD` with `secret.create: true`; empty: generated by the chart. With `secret.create: false` the password is `ADMIN_PASSWORD` of your Secret; without that key the bootstrap Job generates one into the Secret `runr-admin` (key `password`; the Job may read and patch that one Secret). Never written to a log |

### bootstrap

| Key | Default | What it does |
| --- | --- | --- |
| `bootstrap.enabled` | `true` | Renders two Jobs. `runr-bootstrap`, a post-install and post-upgrade hook after the migration hooks — the database only, so helm waits seconds for it: it moves stored image references to the current image namespace (`images:move-registry`), gives accounts without a role the default role (`rbac:assign-missing`), with tenancy runs the pending tenant migrations (`tenancy:migrate`), in the multi-tenant edition converts an installation that ran single-tenant (`bootstrap.tenancySetup`), and creates the administrator above as a platform administrator when that account does not exist, no other platform administrator exists, the e-mail address is free in any letter case and the user name contains no `@`. Existing accounts are left alone. A step that fails or runs out of its time limit prints the command to run by hand and does not stop the upgrade (`activeDeadlineSeconds` 420 s; 540 with tenancy, 1020 with a conversion). And `runr-region-reconcile-<revision>`, no hook — helm does not wait for it: after the bootstrap hook it deploys the agent again on regions whose agent has no token (`agent:redeploy --missing`), brings the existing regions to the installed version (`region:reconcile`), moves the project secret values an earlier release kept in the database into their regions (`secrets:move-to-region`, with `--finalize` while `projectSecrets.finalize` is set; not with `projectSecrets.storage: database`) and runs `images:move-registry` and `rbac:assign-missing` again. Up to three attempts: one that does not finish (a step failed or still waits, or its deadline left steps) fails, and the next continues where it stopped; a region cluster that does not answer is only a warning. The console's region status, the region API (`reconcile`), `/api/version` (`region_reconcile`) and a notification of the platform administrators show a run that left regions behind ([upgrade.md](upgrade.md#order-of-operations)). Kept a week (`ttlSecondsAfterFinished`); `activeDeadlineSeconds` 420 s (the wait for the hook) + 3 × (`region:reconcile`'s time limit, 5400 s by default, + 1380 s) = 20760 s. `false`: neither Job is rendered |
| `bootstrap.regionReconcileSeconds` | `3600` | Seconds `region:reconcile` may start steps for in each attempt of `runr-region-reconcile-<revision>` (`--deadline`); the step running then runs to its end, within a time limit 1800 s longer (the longest step: the private-ingress move with two Traefik rollouts), so no change is cut in the middle. 3600 covers the worst case of one region cluster on an upgrade from 0.5.10 (every wait of every step at its limit, about 3400 s); a cluster typically takes 5–10 minutes. Steps the deadline leaves are done by the next attempt; raise it for installations with many region clusters (`activeDeadlineSeconds` follows). At least 300 |
| `bootstrap.tenancySetup` | `true` | Multi-tenant edition: before the administrator check the Job converts an installation that ran single-tenant into its first tenant (`php artisan tenancy:setup --force`: name `Default`, the slug of the git remote owner; up to 8 minutes, `activeDeadlineSeconds` 480 s longer: 6720 s). Fresh, converted and multi-tenant installations are left alone, a conversion that stopped continues. `false`: run `tenancy:setup` yourself, e.g. to review the plan with `--dry-run` or choose `--name` / `--slug` ([Converting a single-organization installation](tenancy.md#converting-a-single-organization-installation)) |

## Edition

| Key | Default | What it does |
| --- | --- | --- |
| `edition` | `single-tenant` | `single-tenant`: one organization. `multi-tenant`: the Tenancy and Auth Provider modules are on (`TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`) and the UI runs the multi-tenant console, the tag with `ui.image.multiTenantSuffix` (`<tag>-mt`) |
| `tenancy.enabled` | `false` | Turns the Tenancy module on in the API with `edition: single-tenant` as well |
| `authProvider.enabled` | `false` | Turns the Auth Provider module on in the API with `edition: single-tenant` as well |
| `ticket.enabled` | `true` | The Ticket module (support tickets) in the API and the hook Jobs (`TICKET_ENABLED`); values without the key keep it on. The console shows the ticket pages when its UI image is built with `VITE_TICKET_ENABLED=true` |

Editions: [tenancy.md](tenancy.md). Modules: [modules.md](modules.md).

## Platform settings

### quota

The organization's CPU and memory ceiling, applied to every region's project namespace as a
ResourceQuota and a LimitRange. In the multi-tenant edition it is the default tenant quota and the
upper bound of the sum of tenant quotas on a region.

| Key | Default | What it does |
| --- | --- | --- |
| `quota.cpu` | `0` | Ceiling in vCPU, e.g. `8` or `0.5` (`QUOTA_CPU`); `0` or empty: none |
| `quota.memoryGiB` | `0` | Ceiling in GiB (`QUOTA_MEMORY_GIB`); `0` or empty: none |

### audit

| Key | Default | What it does |
| --- | --- | --- |
| `audit.retentionDays` | `365` | Audit events older than this are deleted daily; `0` keeps them (`AUDIT_RETENTION_DAYS`). The setting in Administration → Audit log overrides it |
| `audit.siemForwarding` | `false` | Every audit event also goes to the API, Horizon and Reverb container log as one JSON line for a log collector (`AUDIT_FORWARD_ENABLED`) |

Details: [security.md](security.md#audit-log).

### projectSecrets

| Key | Default | What it does |
| --- | --- | --- |
| `projectSecrets.storage` | `region` | Where the values of project secret variables go (`RUNR_SECRET_STORAGE`): `region` straight into the project's Kubernetes Secret in its region, RunR's database keeps metadata only; `database` the behaviour up to 0.5.10, values encrypted in the database (deprecated) |
| `projectSecrets.finalize` | `false` | `true` once the upgrade from 0.5.10 is confirmed: the region Job of the upgrade (`runr-region-reconcile-<revision>`) runs `secrets:move-to-region --finalize`, which drops the encrypted copies RunR keeps so that 0.5.10 can be installed again, and lets secret store references be set. Do not install 0.5.10 again afterwards ([Rolling back to 0.5.10](upgrade.md#rolling-back-to-0510)). A fresh installation is finalized from the start. No effect with `projectSecrets.storage: database`, where the region Job skips the step |

Values written before the upgrade move with `php artisan secrets:move-to-region`, which the
Job `runr-region-reconcile-<revision>` runs after every upgrade (with `projectSecrets.storage: region`). The fingerprint
key (`RUNR_SECRET_FINGERPRINT_KEY`, default: derived from `APP_KEY`), the settings of External
Secrets Operator (`RUNR_ESO_*`) and those of the regions' OpenBao are set through `extraEnv`:

| Env | Default | |
| --- | --- | --- |
| `RUNR_OPENBAO_IMAGE` | `ghcr.io/brf-tech/runr/reqs/openbao:2.4.1` | the image of the regions' OpenBao |
| `RUNR_OPENBAO_STORAGE_SIZE` | `2Gi` | its volume |
| `RUNR_OPENBAO_STORAGE_CLASS` | empty (the cluster's default) | the StorageClass of its volume |
| `RUNR_OPENBAO_DB_TTL` / `RUNR_OPENBAO_DB_MAX_TTL` | `168h` / `720h` | how long a dynamic Postgres user lives / at most |
| `RUNR_OPENBAO_DB_REFRESH` | `24h` | how often the region asks for a new dynamic user |

Details: [security.md](security.md#secret-environment-variables), [secret-stores.md](secret-stores.md),
[bindings.md](bindings.md#dynamic-credentials).

### auth

| Key | Default | What it does |
| --- | --- | --- |
| `auth.loginTokenTtlDays` | `30` | Days a sign-in to the console or the CLI stays valid, counted from the sign-in; it applies to earlier sign-ins as well. `0`: until signed out (`RUNR_LOGIN_TOKEN_TTL_DAYS`). API tokens keep the expiry they were created with |

### billing

The Billing module ([billing.md](billing.md)): a monthly bill per project and, with tenancy, per
tenant, for an external invoicing system. Off by default. A webhook saved in Administration → Billing →
Webhook wins over the two webhook values.

| Key | Default | What it does |
| --- | --- | --- |
| `billing.enabled` | `false` | The Billing module in the API and the hook Jobs (`BILLING_ENABLED`); values without the key keep it off. The published UI images contain the billing screens and show them while the API reports billing on |
| `billing.currency` | `USD` | ISO 4217 currency of every price and bill (`BILLING_CURRENCY`) |
| `billing.webhookUrl` | `""` | Billing events (`bill.closed`, `bill.paid`, `fixed_request.approved`, …) are POSTed here (`BILLING_WEBHOOK_URL`); empty: they are only kept for `GET /api/billing/events` |
| `billing.webhookSecret` | `""` | Signs the events (`X-RunR-Signature`); written to the Secret as `BILLING_WEBHOOK_SECRET` with `secret.create: true` |

### branding

The installation's brand, applied when the pods start ([white-label.md](white-label.md)). Per
field, a value saved in Administration → Branding wins over these values, which win over the RunR
default. Logos and the favicon take an https URL, a `data:image/…;base64,…` URI or a reference to a
key of a ConfigMap or Secret in the release namespace (`{configMapKeyRef: {name, key}}` or
`{secretKeyRef: {name, key}}`).

| Key | Default | What it does |
| --- | --- | --- |
| `branding.productName` | `""` | Product name; empty: RunR |
| `branding.companyName` | `""` | Company name; empty: BRF Teknoloji |
| `branding.logo.light` | `""` | Logo for the light theme and e-mails |
| `branding.logo.dark` | `""` | Logo for the dark themes and the login page |
| `branding.logo.mark` | `""` | Square icon |
| `branding.favicon` | `""` | Favicon |
| `branding.colors.primary` | `""` | One base colour `#rrggbb` (shade 500; 300–900 derived) or the full `300`–`900` scale; empty: the RunR orange scale (`#f97316`) |
| `branding.colors.accent` | `""` | Accent colour; empty: `#e63946` |
| `branding.loginHeadline`, `branding.loginText` | `""` | Texts of the login page |
| `branding.supportUrl`, `branding.supportEmail` | `""` | Support contact |
| `branding.footerLinks` | `[]` | Footer links, `[{label: Status, url: https://status.example.com}]` |
| `branding.poweredBy` | `true` | "Powered by RunR" on a re-branded login page and in e-mails |
| `branding.email.fromName` | `""` | Sender name of e-mails; empty: the product name |
| `branding.email.logo` | `""` | Logo in e-mails; empty: `branding.logo.light` |
| `branding.tenantOverrides` | `true` | Multi-tenant: tenant owners may brand what their users see |

### signup

Defaults of the self-service signup ([white-label.md](white-label.md#2-self-service-signup)); the
values saved in Administration → Signup override them. Whether registration is open at all is the
console setting `registration_enabled` (on by default, Administration → Regions & settings → User Registration).

| Key | Default | What it does |
| --- | --- | --- |
| `signup.emailVerification` | `false` | The account is created when the link in the e-mail is opened |
| `signup.verificationTtlHours` | `48` | Lifetime of the verification link |
| `signup.allowedDomains` | `""` | Comma-separated e-mail domains allowed to sign up, `"example.com,*.example.org"`; empty: any |
| `signup.inviteOnly` | `false` | Public signup answers 403; accounts come from tenant invitations and administrators |
| `signup.captcha.provider` | `none` | `none`, `turnstile` or `hcaptcha` |
| `signup.captcha.siteKey` | `""` | The provider's site key |
| `signup.captcha.secret` | `""` | The provider's secret, written to the Secret as `SIGNUP_CAPTCHA_SECRET` with `secret.create: true` |
| `signup.termsUrl`, `signup.privacyUrl` | `""` | Terms of service and privacy policy links of the signup form |
| `signup.tenantAutoCreate` | `true` | Multi-tenant: every signup gets its own tenant; the onboarding wizard (organization name, region) runs at the first sign-in |
| `signup.defaultQuota.cpuM` | `2000` | CPU quota of such a tenant, in millicores |
| `signup.defaultQuota.memoryMb` | `4096` | Memory quota of such a tenant, in MB |
| `signup.regions` | `""` | Platform region ids offered in the wizard, comma-separated; empty: every active region |

## Regions and features

### knative

| Key | Default | What it does |
| --- | --- | --- |
| `knative.minK8sMinor` | `"30"` | Lowest Kubernetes minor version accepted for a region (`KNATIVE_MIN_K8S_MINOR`) |
| `knative.traefikImage` | `""` | Image of the region Traefik (Knative ingress); empty: `ghcr.io/brf-tech/runr/reqs/traefik` at the version of the pinned chart. Point it at a registry the regions reach, with the same Traefik version; `region:reconcile` moves existing regions to it |
| `knative.certManager.install` | `true` | Install cert-manager in regions whose cluster runs none (`RUNR_CERT_MANAGER_INSTALL`); a cluster's own cert-manager is reused either way and never removed by RunR ([cert-manager](regions.md#cert-manager)) |
| `knative.certManager.acmeEmail` | `""` | ACME account e-mail of the Let's Encrypt issuer RunR creates in regions reachable from the internet (`RUNR_CERT_MANAGER_ACME_EMAIL`); setting it accepts the ACME server's terms. Empty: every region issues from its own CA. A region's setting `cert_manager_acme_email` overrides it |
| `knative.certManager.acmeServer` | `""` | ACME directory (`RUNR_CERT_MANAGER_ACME_SERVER`); empty: Let's Encrypt production. `https://acme-staging-v02.api.letsencrypt.org/directory` for tests |
| `knative.certManager.chartVersion` | `""` | Chart `jetstack/cert-manager` (`RUNR_CERT_MANAGER_CHART_VERSION`); empty: `v1.21.2`. Its images take the same tag |
| `knative.certManager.imagePrefix` | `""` | Where the cert-manager images come from: `<prefix>/cert-manager-controller`, `-webhook`, `-cainjector`, `-acmesolver` (`RUNR_CERT_MANAGER_IMAGE_PREFIX`); empty: `ghcr.io/brf-tech/runr/reqs`, the mirror of `quay.io/jetstack` |

The images of RunR's own region components have no value of their own; set them through
[`extraEnv`](#extraenv) to pull them from a registry the regions reach:

| Env | Default | |
| --- | --- | --- |
| `RUNR_AGENT_IMAGE` | `ghcr.io/brf-tech/runr/agent:<version>` (the installed version: `APP_VERSION`, which the chart sets from its `appVersion`; `latest` for a build that is no release) | the region agent (`runr-agent`: metrics, pod sessions, the global-health listener); `region:reconcile` moves the regions' agents to it |
| `RUNR_INTERNAL_AUTHZ_IMAGE` | `RUNR_AGENT_IMAGE` | the agent in authz mode that checks the calls of the [internal network](internal-network.md) (`runr-internal-auth`) |
| `RUNR_QUEUE_CONSUMER_IMAGE` | `ghcr.io/brf-tech/runr/queue-consumer:<version>` (the installed version, as the agent) | the projects' event gateway (`<slug>-qc`) and the topic publisher (`runr-topics`) ([triggers.md](triggers.md#the-event-gateway)); `region:reconcile` moves the regions' gateways to it |
| `RUNR_INTERNAL_AUTHZ_READY_TIMEOUT` | `180` | seconds an install and `region:reconcile` wait for the authz before the private entry point refers to it |
| `RUNR_REGISTRY_PVC_TEST_SECONDS` | `180` | seconds the internal registry's setup waits for its test volume of the chosen StorageClass to bind (30 to 900); raise it for slow storage or busy clusters ([regions.md](regions.md#storage)) |
| `RUNR_PRIVATE_INGRESS_DNS_GRACE_SECONDS`, `RUNR_PRIVATE_INGRESS_REGENERATE_TIMEOUT` | `35`, `180` | `region:reconcile`'s move of cluster-local routes to the private entry point: how long they stay on the public one too after the move (DNS caches), and how long the KIngresses get to name `traefik-internal` before the step fails and the next run goes on ([regions.md](regions.md#cluster-local-services-and-network-isolation)) |

### observability

Tracing, request replay, uptime checks, load tests, the status page and alert delivery
([observability.md](observability.md), [load-test.md](load-test.md)).

| Key | Default | What it does |
| --- | --- | --- |
| `observability.tracing.defaultMode` | `managed` | `managed`, `reuse`, `external` or `off`: the tracing backend of regions without their own setting. With `installWithServing` it also decides whether `region:reconcile` installs the backend on such a region |
| `observability.tracing.installWithServing` | `true` | Install the tracing backend together with Knative Serving |
| `observability.tracing.knativeSampleRate` | `0.1` | Span sampling of the Knative activator and queue-proxy; a region setting overrides it |
| `observability.tracing.tempoRetentionHours` | `72` | Trace retention of Tempo |
| `observability.tracing.tempoStorage` | `10Gi` | Size of Tempo's volume |
| `observability.tracing.collectorChartVersion` | `""` | Chart version of `open-telemetry/opentelemetry-collector`; empty: the pinned 0.176.0 (collector 0.162.0) |
| `observability.tracing.tempoChartVersion` | `""` | Chart version of `grafana-community/tempo`; empty: the pinned 3.2.0 (Tempo 3.1.0) |
| `observability.tracing.collectorImage` | `""` | Collector image; empty: `ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib` at the pinned version |
| `observability.tracing.tempoImage` | `""` | Tempo image; empty: `ghcr.io/brf-tech/runr/reqs/tempo` at the pinned version |
| `observability.replay.maxBodyBytes` | `65536` | Project default of the captured body size; projects choose up to 1 MiB |
| `observability.replay.retentionDays` | `7` | Project default of the capture retention; projects choose up to 30 |
| `observability.replay.maxCaptures` | `500` | Captures per project, the oldest dropped first |
| `observability.replay.ingestUrl` | `""` | RunR as seen from region pods; empty: the region's agent URL |
| `observability.uptime.minIntervalSeconds` | `30` | Shortest interval of an uptime check |
| `observability.uptime.resultsRetentionDays` | `90` | Retention of check results |
| `observability.uptime.allowPrivateTargets` | `false` | `true`: checks of an absolute URL may target private addresses |
| `observability.loadTest.enabled` | `true` | Load tests of HTTP projects ([load-test.md](load-test.md)) |
| `observability.loadTest.dailyLimit` | `20` | Load tests an organization may start per day; `0`: none |
| `observability.loadTest.maxUsers` | `100` | The largest of the 10 / 50 / 100 concurrent-user choices offered |
| `observability.loadTest.maxDurationSeconds` | `300` | The longest of the 30 / 60 / 300 s duration choices offered |
| `observability.loadTest.maxConcurrentPerRegion` | `5` | k6 pods at once in a region (ResourceQuota of the `runr-loadtest` namespace) |
| `observability.loadTest.image` | `""` | k6 image; empty: `ghcr.io/brf-tech/runr/reqs/k6` at the pinned version |
| `observability.statusPage.enabled` | `true` | The public status page |
| `observability.alerts.deliveryTries` | `4` | Delivery attempts of an alert |
| `observability.alerts.allowPrivateTargets` | `false` | `true`: webhook, Slack and Teams URLs may be private addresses |

### sandboxApi

Isolated execution of untrusted code ([sandbox-api.md](sandbox-api.md)). Region settings, warm
pools and organization quotas are set in Build & Run → Sandbox settings; these are the platform defaults and
ceilings.

| Key | Default | What it does |
| --- | --- | --- |
| `sandboxApi.enabled` | `true` | The Sandbox API |
| `sandboxApi.namespacePrefix` | `runr-sbx` | Namespace `<prefix>-<organization>` per region |
| `sandboxApi.defaultRuntime` | `python` | Runtime of a sandbox created without one |
| `sandboxApi.allowCustomImages` | `true` | Sandboxes may name an `image` instead of a runtime of the catalog |
| `sandboxApi.defaults.cpuM`, `memoryMb`, `diskMb` | `500`, `512`, `1024` | Default size of a sandbox |
| `sandboxApi.defaults.ttlSeconds`, `idleTimeoutSeconds` | `900`, `600` | Default lifetime and idle timeout |
| `sandboxApi.limits.maxCpuM`, `maxMemoryMb`, `maxDiskMb`, `maxTtlSeconds` | `4000`, `8192`, `10240`, `86400` | Ceilings per sandbox; a region may lower them |
| `sandboxApi.quota.maxConcurrent`, `maxCpuM`, `maxMemoryMb` | `20`, `8000`, `16384` | Per organization unless set in Build & Run → Sandbox settings; `0` = unlimited |
| `sandboxApi.quota.maxSandboxSecondsMonth`, `maxPoolSize` | `0`, `10` | Sandbox seconds per month and warm pool size per organization; `0` = unlimited |
| `sandboxApi.exec.defaultTimeout`, `maxTimeout` | `60`, `3600` | Timeouts of a command, in seconds |
| `sandboxApi.files.maxUploadBytes`, `maxDownloadBytes` | `52428800`, `52428800` | Largest file upload and download |
| `sandboxApi.poolMaxAgeSeconds` | `3600` | Warm pods are replaced after this |
| `sandboxApi.retentionDays` | `30` | Ended sandboxes are removed after this |

### gitDeploy

Every project gets a git remote `https://<domain>/git/<organization>/<project>.git`, served by the
API behind the gateway's `/git` route; the bare repositories live on the API volume
(`git-remotes/`). Providers are added in Data & Integrations → Git providers ([git-deploy.md](git-deploy.md)).

| Key | Default | What it does |
| --- | --- | --- |
| `gitDeploy.enabled` | `true` | Project git remotes and the gateway's `/git` route |
| `gitDeploy.maxPushMb` | `50` | Largest pack one push may send; stay below the API's 64 MB request limit |
| `gitDeploy.remoteOwner` | `runr` | Owner segment of remote URLs in the single-tenant edition |
| `gitDeploy.webhookBaseUrl` | `""` | Base URL of provider webhooks; empty: `https://<domain>` |
| `gitDeploy.previews.limit` | `3` | Default number of live previews per project |
| `gitDeploy.previews.ttlHours` | `72` | Default hours a preview lives without a new commit |
| `gitDeploy.previews.maxLimit` | `20` | Highest per-project preview limit |

### supplyChain

SBOMs, vulnerability scans, signatures and the admission policy per region
([supply-chain.md](supply-chain.md)). What runs in a region is set in the console (Region → Supply
chain); these are the platform-wide defaults. Empty values keep the application defaults.

| Key | Default | What it does |
| --- | --- | --- |
| `supplyChain.enabled` | `true` | Every image RunR builds gets its supply-chain run |
| `supplyChain.toolsImage` | `""` | Tools image; empty: `ghcr.io/brf-tech/runr/supply-chain:1.0.0` |
| `supplyChain.jobNamespace` | `""` | Namespace of the Jobs and the region signing key; empty: `runr-supply-chain` |
| `supplyChain.rescanHours` | `""` | Re-scan interval of deployed images for new regions; empty: 24 |
| `supplyChain.trivyDbSource` | `""` | Mirror source of the vulnerability database; empty: `mirror.gcr.io/aquasec/trivy-db:2` |
| `supplyChain.trivyJavaDbSource` | `""` | Mirror source of the Java database; empty: `mirror.gcr.io/aquasec/trivy-java-db:1` |
| `supplyChain.trivyDbRefreshHours` | `""` | Refresh interval of the mirrored databases; empty: 12 |
| `supplyChain.policyController.helmRepo` | `""` | Helm repository of the sigstore policy-controller; empty: `https://sigstore.github.io/helm-charts` |
| `supplyChain.policyController.chartVersion` | `""` | Its chart version; empty: the version the release pins |
| `supplyChain.policyController.extraArgs` | `""` | Webhook flags, e.g. `"disable-tuf=true"` for regions without internet access |

### globalDns

[Global projects](global-projects.md): the global zone and the PowerDNS server each taking-part
region runs for it. Which regions run one is set in the console (Region → General → Global DNS);
these are the platform-wide defaults. Empty values keep the application defaults.

| Key | Default | What it does |
| --- | --- | --- |
| `globalDns.zone` | `""` | The global zone; empty: `g.<base domain>` |
| `globalDns.namespace` | `""` | Namespace of the server in a region; empty: `runr-global-dns` |
| `globalDns.image` | `""` | PowerDNS Authoritative; empty: `ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8` |
| `globalDns.storageClass` | `""` | StorageClass of the server's 1 GiB volume; empty: the cluster default |
| `globalDns.serviceAnnotations` | `{}` | Annotations of the DNS LoadBalancer Service (UDP and TCP 53) |
| `globalDns.ttl` | `""` | TTL of the project records in seconds; empty: 30 |
| `globalDns.maxRegions` | `""` | Regions of one global project; empty: 8 |
| `globalDns.geoipUrl` | `""` | GeoIP database of proximity mode (gzipped MaxMind-format city database, `{yyyy}` / `{mm}`: the current month); empty: DB-IP IP to City Lite (CC BY 4.0); `-`: none ([licensing](global-projects.md#geoip-licensing)) |
| `globalDns.tls.mode` | `""` | `acme` (empty: the default) — cert-manager issues `*.<zone>` in every region with DNS-01; `none` — the region's default certificate |
| `globalDns.tls.acmeServer` | `""` | ACME directory; empty: Let's Encrypt production |
| `globalDns.tls.acmeEmail` | `""` | Contact address of the ACME account; empty: the region's cert-manager ACME e-mail (the region setting `cert_manager_acme_email`, else `knative.certManager.acmeEmail`) |
| `globalDns.failover.afterSeconds` | `""` | Seconds a global project's primary may fail its health check before an automatic failover; empty: 180 ([failover](global-projects.md#failover)) |
| `globalDns.dataLink.image` | `""` | Forwarder of a binding's data link in the other regions of a global project (nginx stream); empty: `ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine` ([data link](global-projects.md#the-data-link)) |
| `globalDns.dataLink.port` | `""` | Port of the binding region's Traefik the data link reaches (TLS with SNI on websecure); empty: 443 |

Settings without a value of their own, set through [`extraEnv`](#extraenv): `RUNR_GLOBAL_DNS_ZONE_LABEL`
(the label in front of the base domain when `zone` is empty, default `g`),
`RUNR_GLOBAL_DNS_STORAGE_SIZE` (the server's volume, default `1Gi`), the health checks of the
regions' copies `RUNR_GLOBAL_DNS_HEALTH_INTERVAL` / `_TIMEOUT` (seconds, default 5 / 2) and
`RUNR_GLOBAL_DNS_HEALTH_MINIMUM_FAILURES` (failed checks in a row before a region leaves the
answers, default 2), and `RUNR_GLOBAL_IMAGE_COPY_TIMEOUT` (seconds the copy of an uploaded CaaS
image into another region's registry may take, default 3600; the copy runs on the long queue, whose
limits follow the longer of it and `RUNR_ORG_TRANSFER_JOB_TIMEOUT`).

### images

Container images and `.wasm` modules uploaded into a region's registry ([images.md](images.md)).
Empty values keep the application defaults.

| Key | Default | What it does |
| --- | --- | --- |
| `images.uploadImage` | `""` | Image of the upload receiver the regions pull; empty: `ghcr.io/brf-tech/runr/image-upload:1.1.0` (the image of the transfer agent too) |
| `images.quotaGb` | `""` | Storage of uploaded images per organization over every region; empty: 50, `0` = unlimited |
| `images.maxUploadGb` | `""` | Largest file of one upload; empty: 20 |
| `images.uploadTtlHours` | `""` | An upload not completed within this time is aborted; empty: 24 |

### runtimePatches

The signed feed that announces the patch tags of the runtime images — level, reason, CVEs and,
for critical and zero-day patches, a deadline ([runtimes.md](runtimes.md#runtime-patches),
[security.md](security.md#runtime-patch-feed)). RunR checks the feed's digest every five minutes
and uses a feed only once its cosign signature verified with `publicKey`. Empty values keep the
application defaults.

| Key | Default | What it does |
| --- | --- | --- |
| `runtimePatches.feed` | `""` | OCI reference of the feed (`RUNR_PATCH_FEED`); empty: `ghcr.io/brf-tech/runr/runtime-patches:feed` |
| `runtimePatches.publicKey` | the key of BRF-Tech/runr-runtimes (`keys/patch-feed.pub`, DER SHA-256 `24d79a49…c0df`) | cosign public key (PEM) the feed must be signed with (`RUNR_PATCH_FEED_PUBLIC_KEY`); another key for a feed of your own; `""`: the feed is off and platform administrators see a warning. Set it as a block: `publicKey: \|` followed by the key's lines — the line breaks reach the API |
| `runtimePatches.viaRegion` | `""` | Read the feed through this region's internal registry (id or name; its ghcr.io pull-through) instead of from the feed's registry, for an API without a way out (`RUNR_PATCH_FEED_VIA_REGION`) |
| `runtimePatches.autoMigrate` | `true` | At a critical or zero-day patch's deadline, redeploy the projects still behind onto the newest patch, rolled back when the new revision does not become ready (`RUNR_PATCH_AUTO_MIGRATE`). The default of the platform policy: platform administrators switch it on the Patches page, which wins |

`RUNR_PATCH_FEED_TIMEOUT` (seconds of one registry request, default 15) has no value of its own:
set it through [`extraEnv`](#extraenv).

### apiProduct

API keys, JWT routes, plans and usage of the gateways ([api-product.md](api-product.md)). The
regions run gateway-auth, which the region Traefik asks before every call of a key or JWT route.
Empty values keep the application defaults.

| Key | Default | What it does |
| --- | --- | --- |
| `apiProduct.gatewayAuthImage` | `""` | Image of gateway-auth the regions pull; empty: `ghcr.io/brf-tech/runr/gateway-auth:1.0.0` |
| `apiProduct.callbackUrl` | `""` | RunR's base URL as the regions reach it (config sync, usage reports); empty: the URL the region agent connects to |
| `apiProduct.syncSeconds` | `""` | How often gateway-auth fetches changed keys and plans — the longest a revoked key keeps working; empty: 10 |
| `apiProduct.usageRetentionDays` | `""` | Hourly usage rows kept; empty: 400 |

Settings without a value of their own, set through [`extraEnv`](#extraenv):
`RUNR_GATEWAY_AUTH_REPLICAS` (gateway-auth pods per region with Redis, default 2; a region without
Redis runs one pod), `RUNR_GATEWAY_AUTH_FLUSH_SECONDS` (how often gateway-auth reports the calls it
counted, default 30) and `RUNR_API_KEY_ROTATION_OVERLAP_MINUTES` (how long the old key of a
rotation keeps working unless the rotation says otherwise, default 1440).

### orgTransfer

Organization export / import ([org-export.md](org-export.md)). Packages live on the API volume
`runr-api-storage` (`api.storage.size`, or `storage.application`): size it for what you export, or
write exports to an object binding / S3. Empty values keep the application defaults; the further
settings (`RUNR_ORG_TRANSFER_*`) are in [org-export.md](org-export.md).

| Key | Default | What it does |
| --- | --- | --- |
| `orgTransfer.transferImage` | `""` | Image of the transfer pods the regions pull (binding data, images); empty: `ghcr.io/brf-tech/runr/image-upload:1.1.0` |
| `orgTransfer.maxPackageGb` | `""` | Largest package an import takes; empty: 50 |
| `orgTransfer.retentionHours` | `""` | Packages are removed after this many hours; empty: 168 |
| `orgTransfer.podDiskGb` | `""` | emptyDir of a transfer pod (one binding's dump or one image); empty: 20 |

### ai

GPU support and Hugging Face models ([ai.md](ai.md), [huggingface.md](huggingface.md)). Point the
images and charts at a registry the regions reach for air-gapped installations.

| Key | Default | What it does |
| --- | --- | --- |
| `ai.huggingface.endpoint` | `https://huggingface.co` | Hub API of the console's model browser and of online model downloads; a mirror with the same API works |
| `ai.huggingface.browse` | `true` | `false`: the console does not call the Hub; models are uploaded or imported |
| `ai.images.hfServe` | `ghcr.io/brf-tech/runr/hf-serve:1.1.1` | `hf-serve` image |
| `ai.images.hfServeCuda` | `ghcr.io/brf-tech/runr/hf-serve:1.1.1-cuda` | `hf-serve` CUDA image |
| `ai.images.vllm` | `docker.io/vllm/vllm-openai:v0.6.6` | vLLM image |
| `ai.images.tgi` | `ghcr.io/huggingface/text-generation-inference:3.0.1` | TGI image |
| `ai.images.teiCpu` | `ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4` | TEI image for CPU |
| `ai.images.teiGpu` | `ghcr.io/huggingface/text-embeddings-inference:{arch}1.9.4` | TEI image for GPU; `{arch}` follows the GPU's compute capability |
| `ai.images.oras` | `ghcr.io/oras-project/oras:v1.2.0` | ORAS image |
| `ai.gpu.devicePluginChartVersion` | `"0.17.0"` | NVIDIA device plugin chart version |
| `ai.gpu.operatorChartVersion` | `"v24.9.2"` | NVIDIA GPU Operator chart version |
| `ai.gpu.defaultTimeout` | `300` | Default timeout in seconds of GPU projects and model services |
| `ai.gpu.progressDeadline` | `1800s` | Start-up deadline of GPU workloads and model services (image pull and model load) |
| `ai.gpu.scaleToZeroRetention` | `5m` | How long GPU pods stay after the last request before they scale to zero |
| `ai.modelStorage.sizeGi` | `100` | Default size of a region's model volume (PVC `runr-models`) |
| `ai.modelStorage.accessMode` | `ReadWriteMany` | Its default access mode |
| `ai.modelStorage.storageClass` | `""` | Its default StorageClass; each region can change these before its first model |

### bindings

Managed KV, object storage and Postgres that projects attach to ([bindings.md](bindings.md)). The
data services are installed per region from the console (Region → Data services).

| Key | Default | What it does |
| --- | --- | --- |
| `bindings.namespace` | `runr-data` | Namespace of the data services in each region |
| `bindings.retentionDays` | `7` | A deleted binding keeps its data this long (restore possible) |
| `bindings.rotationGraceSeconds` | `600` | Old credentials keep working this long after a rotation |
| `bindings.storageClass` | `""` | StorageClass of the data services' PVCs; empty: the cluster default |
| `bindings.quotas.maxBindings` | `20` | Bindings per organization (per tenant in multi-tenant installs); `0` = unlimited |
| `bindings.quotas.kvMb`, `objectGb`, `postgresGb` | `1024`, `100`, `50` | KV, object and Postgres storage per organization; `0` = unlimited |
| `bindings.quotas.dedicatedPostgres` | `2` | Dedicated Postgres instances per organization; `0` = unlimited |
| `bindings.kv.maxmemoryMb` | `512` | Memory of the managed KV Redis (`noeviction`) |
| `bindings.kv.storage` | `2Gi` | Its AOF volume |
| `bindings.object.image` | `ghcr.io/brf-tech/runr/reqs/garage:v2.1.0` | Object storage image (Garage) |
| `bindings.object.storage` | `20Gi` | Its volume |
| `bindings.object.s3Region` | `garage` | S3 region name |
| `bindings.postgres.cnpgChartVersion` | `"0.26.0"` | CloudNativePG chart version |
| `bindings.postgres.image` | `ghcr.io/cloudnative-pg/postgresql:17.5` | PostgreSQL image of the managed instances |
| `bindings.postgres.sharedInstances` | `1` | Instances of the shared Postgres cluster |
| `bindings.postgres.sharedStorage` | `10Gi` | Its volume |
| `bindings.postgres.backupRetention` | `7d` | Backup retention |

## Scheduling and cluster

| Key | Default | What it does |
| --- | --- | --- |
| `podColocation` | `true` | With a ReadWriteOnce API volume (`api.storage.accessModes`), the pods that mount it — API, Horizon, scheduler, Reverb and the hook Jobs — get the label `runr/storage-group: api` and a required pod affinity on it per node, so they run on the volume's node. No effect with ReadWriteMany |
| `placement.tolerateDedicatedWorkers` | `true` | Every RunR pod tolerates the taint `runr.dev/dedicated=projects:NoSchedule`, which a region on this cluster puts on its dedicated worker nodes, and prefers the other nodes (preferred node affinity); a pod whose volume is bound to such a node still starts there |
| `placement.tolerations` | `[]` | Tolerations added to every RunR pod |
| `placement.affinity` | `{}` | Affinity of every RunR pod, unless `gateway.affinity`, `api.affinity` or `ui.affinity` replaces it for that component; `podColocation` and `tolerateDedicatedWorkers` add their terms to it |
| `placement.nodeSelector` | `{}` | nodeSelector of every RunR pod, e.g. `{node-role.kubernetes.io/infra: "true"}` |
| `networkPolicy.enabled` | `false` | NetworkPolicies that allow exactly the connections of [Network policies](install.md#network-policies) and deny the rest (needs a CNI that enforces NetworkPolicy) |
| `networkPolicy.gatewayFrom` | `[]` | NetworkPolicy peers that may reach `runr-gateway`, e.g. the ingress controller's namespace; empty: any source |
| `networkPolicy.egress.to` | `0.0.0.0/0`, `::/0` | Peers of the outbound connections of the API, Horizon, scheduler and Reverb pods and the hook Jobs (regions, registries, Helm repositories, git providers, alert targets) |
| `networkPolicy.egress.ports` | `[80, 443, 6443]` | TCP ports of those outbound connections; empty: every port |
| `networkPolicy.extraEgress` | `[]` | Further egress rules (NetworkPolicyEgressRule) of those pods |
| `imagePullSecrets` | `[]` | Pull secrets added to every pod and hook Job: `["regcred"]` or `[{name: regcred}]` |
| `imagePullSecretsFromDefaultServiceAccount` | `true` | Also adds the pull secrets of the `default` ServiceAccount of the release namespace (read with `lookup`, so only when Helm runs against the cluster) |
| `initImage.repository` | `ghcr.io/brf-tech/runr/reqs/busybox` | Image of the init containers (`fix-permissions`, `fix-pgdata`) |
| `initImage.tag` | `latest` | The mirror carries only `latest` |
| `namespace` | `""` | Install namespace; empty: the Helm release namespace |
| `createNamespace` | `false` | `true`: the chart creates Namespace objects for the install namespace and `projectNamespace` |
| `projectNamespace` | `runr-projects` | Name of the second Namespace that `createNamespace` creates |
| `deployTimestamp` | `""` | When set, written as an annotation on the pod templates of the gateway, API, UI, Horizon, scheduler and Reverb: a new value rolls them, also with an unchanged image tag |
