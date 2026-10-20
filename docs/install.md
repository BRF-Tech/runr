# Installing RunR in production

This page installs the RunR control plane — console, API, queue workers, WebSocket server and
their data services — on a Kubernetes cluster with the Helm chart `runr` from BRF's public Helm
repository. Functions run in regions, which you connect after the install. For a production
install on a single machine with Docker Compose, see [compose/README.md](../compose/README.md).

Every value mentioned here is described in the [Helm values reference](helm-values.md).

- [Requirements](#requirements)
- [Edition and modules](#edition-and-modules)
- [DNS](#dns)
- [Ingress and TLS](#ingress-and-tls)
- [Database, Redis and mail](#database-redis-and-mail)
- [Storage](#storage)
- [High availability](#high-availability)
- [First administrator](#first-administrator)
- [Horizon dashboard](#horizon-dashboard)
- [Network policies](#network-policies)
- [Example values file](#example-values-file)
- [Install](#install)
- [Production checklist](#production-checklist)
- [Backup and restore](#backup-and-restore)
- [Upgrades](#upgrades)
- [Connecting the first region](#connecting-the-first-region)

## Requirements

| Requirement | Details |
| --- | --- |
| Kubernetes | 1.23 or later (the chart's `kubeVersion`): the chart uses only stable APIs, the newest of them `autoscaling/v2` (HorizontalPodAutoscaler). A cluster that you also add as a region needs 1.30 or later (`knative.minK8sMinor`). |
| Helm | Helm 3 (the chart's API version is `v2`). |
| Ingress controller | An ingress controller with an IngressClass for the console (`ingress.className`, default `traefik`). What it has to pass through is listed under [Ingress and TLS](#ingress-and-tls). |
| Storage | A StorageClass that provisions ReadWriteOnce volumes: the API volume `runr-api-storage` and, with the chart's PostgreSQL, `runr-postgres-data` (chart defaults: 10Gi each). For API, Horizon, scheduler and Reverb pods on several nodes, a StorageClass with ReadWriteMany volumes for the API volume. See [Storage](#storage). |
| cert-manager | Only for `ingress.tls.mode: letsencrypt`: cert-manager and a ClusterIssuer (default name `letsencrypt-prod`). |
| Metrics API | Only for the HorizontalPodAutoscalers (`gateway.hpa`, `api.hpa`, `ui.hpa`), which scale on CPU utilization: metrics-server or another provider of the resource metrics API. |
| Network policy | Only for `networkPolicy.enabled`: a CNI that enforces NetworkPolicy ([Network policies](#network-policies)). |
| Outbound access | The nodes pull RunR's images from `ghcr.io/brf-tech/runr/` (public, no credentials). The API, Horizon and scheduler pods connect to the Kubernetes API server of every region, to the public Helm repositories of the region components (region installs run Helm in these pods) and to `ghcr.io` (the API reads the versions of the built-in runtimes from their image repositories). |
| Resources | The requests of all pods with the chart defaults add up to 1275m CPU and 1632Mi memory; the values per component are in the [Helm values reference](helm-values.md#application-pods). |

The chart installs into the Helm release namespace (`--namespace runr --create-namespace` in the
examples below).

## Edition and modules

`edition` selects the installation type:

| `edition` | What you get |
| --- | --- |
| `single-tenant` (default) | One organization. Users sign in with local accounts. |
| `multi-tenant` | Tenancy and Auth Provider modules on: several tenants share the installation, each with its own PostgreSQL schema, users, roles and quota per region; SSO providers (OAuth, OpenID Connect, SAML) can be added. |

The modules of the console are built into its image, so the multi-tenant console is a separate UI
image: with `edition: multi-tenant` the UI runs `ghcr.io/brf-tech/runr/ui:<tag>-mt`
(`ui.image.multiTenantSuffix`, default `-mt`). The tag defaults to the chart's release, its
`appVersion`, so chart 0.5.10 runs `0.5.10-mt`; the moving tag `latest` has no multi-tenant variant
and runs `<appVersion>-mt` as well. A UI image of your own that carries the modules under its plain
tag needs `ui.image.multiTenantSuffix: ""`.

The Ticket module (support tickets) is on by default in both editions: `ticket.enabled: false`
switches it off in the API (`TICKET_ENABLED`). The console shows the ticket pages when its UI image
is built with them (`VITE_TICKET_ENABLED=true`).

Tenants, Platform users and Tenant users are described in [tenancy.md](tenancy.md); the optional
modules and how they are switched in [modules.md](modules.md).

### Billing

The Billing module calculates a monthly bill per project — the project's owner pays — and, in the
multi-tenant edition, per tenant for the shared platform regions; an external invoicing system reads
the bills and writes back payment links and paid status ([billing.md](billing.md)). It is off by
default in both editions. To switch it on with Helm:

```yaml
billing:
  enabled: true
  currency: EUR                                         # every price and bill
  webhookUrl: https://invoicing.example.com/hooks/runr  # optional
  webhookSecret: "<at least 16 characters>"             # optional, goes into runr-secret
```

With Docker Compose set `BILLING_ENABLED=true` in `compose/.env` before building the images and
`BILLING_CURRENCY`, `BILLING_WEBHOOK_URL`, `BILLING_WEBHOOK_SECRET` in `compose/api.env`
([Compose README](../compose/README.md#1-choose-the-modules)). The published UI images contain the
billing pages and show them as soon as the API reports billing on; a UI image of your own needs
`VITE_BILLING_ENABLED=true`. After the first start, set the prices and the billing type of every
region in Settings → Billing → Regions & prices — until then the
[default prices](billing.md#price-fields) apply.

## DNS

| Record | Points to | Serves |
| --- | --- | --- |
| The console domain (`domain`, e.g. `runr.example.com`) | The external address of the ingress controller | The console, the API (`/api`), WebSockets (`/ws`), project git remotes (`/git`), the MCP server (`/mcp`) |
| A wildcard record per region, `*.<function domain>` | The external address of that region | Project URLs `<project>.<function domain>` |

Regions on other clusters connect back to the console domain (by default their agents open a
WebSocket to `wss://<domain>/ws`), so it must be reachable from them as well.

A region's function domain is `<region name>.<base domain>`, unless the region has a function
domain of its own. The base domain is a console setting: **Settings → Platform → Base Domain** (or
`PATCH /api/settings` with `base_domain`, permission `settings.manage`). Setting it the first time
gives every region with Knative Serving ready and without a function domain the function domain
`<region name>.<base domain>`, and a region connected later gets it when its Knative Serving is
installed. Set the base domain before you connect the first region. A changed base domain moves
the regions that follow it: they answer on the old and the new domain until DNS of the new
`<region name>.<base domain>` answers or the move is completed by hand
([Changing the base domain](regions.md#changing-the-base-domain)).
In the multi-tenant edition a tenant can give its functions a domain of its own, which moves the
same way ([Organization domain](tenancy.md#organization-domain)). The region's external address,
its DNS check and function-domain certificates are described in [regions.md](regions.md).

## Ingress and TLS

With `ingress.enabled: true` (the default) and `domain` set, the chart creates one Ingress `runr`:
host `<domain>`, path `/` (Prefix) to the Service `runr-gateway` on `gateway.port` (80), with
`ingressClassName: <ingress.className>` and the annotations in `ingress.annotations`. The gateway
(Caddy) routes the paths behind it:

| Path | Goes to |
| --- | --- |
| `/` | `runr-ui` (the console) |
| `/api` | `runr-api` |
| `/ws` | `runr-reverb` (WebSockets: console events, browser shell, region agents) |
| `/git` | `runr-api`, streamed (project git remotes; only with `gitDeploy.enabled`) |
| `/mcp`, `/oauth2`, `/.well-known/oauth-*` | `runr-api`, streamed (MCP server and its OAuth endpoints) |
| `/oauth/<provider>/callback` | `runr-api` (sign-in callback of an SSO provider); the console's own `/oauth/callback` page stays with `runr-ui` |
| `/horizon` (the [Horizon dashboard](#horizon-dashboard)), `/storage` | `runr-api` |
| `/healthz` | answered by the gateway |

With the chart's Ingress the ingress controller is a trusted proxy of the gateway
(`clientIp.trustedProxies`, [Client IP](helm-values.md#clientip)): the gateway keeps its
`X-Forwarded-For` and `X-Forwarded-Proto`, so the API records the client's address and builds
`https` URLs — the SSO callback URL it sends to a provider, the MCP server's OAuth metadata.

The ingress controller has to pass WebSocket upgrades on `/ws`, keep streamed responses open
(`/mcp` sends Server-Sent Events) and accept request bodies up to the API's 64 MB request limit
(git pushes up to `gitDeploy.maxPushMb`, image uploads in 8 MiB chunks). With ingress-nginx:

```yaml
ingress:
  className: nginx
  annotations:
    nginx.ingress.kubernetes.io/proxy-buffering: "off"
    nginx.ingress.kubernetes.io/proxy-read-timeout: "900"
    nginx.ingress.kubernetes.io/proxy-body-size: "64m"
```

The console always runs as `https://<domain>` (`APP_URL`). Choose the certificate with
`ingress.tls.mode`:

| `ingress.tls.mode` | What the chart does | What you provide |
| --- | --- | --- |
| `letsencrypt` | Adds the annotation `cert-manager.io/cluster-issuer: <ingress.tls.clusterIssuer>` (default `letsencrypt-prod`) and a `tls` section with `ingress.tls.secretName` (default `runr-tls`); cert-manager issues the certificate into that Secret | cert-manager and the ClusterIssuer; the console domain resolving to the ingress controller |
| `custom` | Creates a `kubernetes.io/tls` Secret (`ingress.tls.secretName`, default `runr-tls-custom`) from `ingress.tls.certificate` and `ingress.tls.privateKey` and references it in the Ingress | The full PEM chain (server certificate first) and its unencrypted private key, as PEM or base64-encoded PEM |
| `secret` | References your `kubernetes.io/tls` Secret `ingress.tls.secretName` (required) in the Ingress; creates nothing | The Secret in the release namespace, kept up to date by you or your certificate tooling |
| `none` | No `tls` section; the ingress controller serves its default certificate | A default certificate in the ingress controller that covers the console domain |

The default, `mode: ""`, applies the keys of releases up to 0.3.5 (`ingress.tls.enabled`,
`clusterIssuer`, `secretName`); with their defaults the Ingress has no `tls` section. Set the mode
explicitly for a production install.

`letsencrypt` needs the `cert-manager.io/v1` API in the cluster when the chart is rendered. Without
it the Ingress gets no `tls` section and no annotation, and `helm status` says so. A tool that
renders the chart without cluster access needs `--api-versions cert-manager.io/v1`.

A custom certificate is easiest to pass from files, and renewed by running the same upgrade with
the new files:

```sh
helm upgrade runr brf-tech/runr --version 0.5.10 -n runr --reuse-values \
  --set ingress.tls.mode=custom \
  --set-file ingress.tls.certificate=fullchain.pem \
  --set-file ingress.tls.privateKey=privkey.pem
```

The chart never prints the private key. Redirect HTTP to HTTPS in the ingress controller.

A certificate that other tooling issues into a Secret (an existing cert-manager `Certificate`, an
external secrets operator) is referenced as it is:

```yaml
ingress:
  tls:
    mode: secret
    secretName: runr-console-tls
```

## Database, Redis and mail

Each of the three services is installed by the chart unless you point RunR at your own:

| Service | Bundled (default) | External |
| --- | --- | --- |
| PostgreSQL | `database.host` empty: Deployment `runr-postgres` (PostgreSQL 17, one pod) with the PVC `runr-postgres-data` | `database.host` set (with `database.port`, `database.name`, `database.user` and the password): no PostgreSQL is installed |
| Redis | `redis.host` empty: Deployment `runr-redis` (Redis 7, one pod, no volume) | `redis.host` set (with `redis.port`, optionally `redis.username` and the password): no Redis is installed |
| Mail | `mail.host` empty: Mailpit (`runr-mailpit`) catches every message and delivers none | `mail.host` set (with `mail.port`, `mail.from`, `mail.fromName`, optionally `mail.username`, the password and `mail.encryption`): RunR sends through that SMTP server, no Mailpit |

The passwords of all three reach the pods from Secrets, never from the ConfigMap `runr-config`:

| Service | Password in the chart's Secret | Password in a Secret of yours |
| --- | --- | --- |
| PostgreSQL | `database.password` (key `DB_PASSWORD`); empty: generated on the first install and kept | `database.existingSecret`, key `database.existingSecretPasswordKey` (default `password`) |
| Redis | `redis.password` (key `REDIS_PASSWORD`) | `redis.existingSecret`, key `redis.existingSecretPasswordKey` (default `password`) |
| SMTP | `mail.password` (key `MAIL_PASSWORD`) | `mail.existingSecret`, key `mail.existingSecretPasswordKey` (default `password`) |

### The Secret

The pods read their secrets from one Secret, `runr-secret` (`secretName`), and from the Secrets
named in `*.existingSecret`:

| Key | Content |
| --- | --- |
| `APP_KEY` | Encrypts the secrets stored in the database (env vars, region kubeconfigs, credentials — [security.md](security.md#encryption-at-rest)) |
| `REVERB_APP_SECRET` | Shared by the API and Reverb |
| `DB_PASSWORD` | Password of the database user (unless `database.existingSecret` is set) |
| `REDIS_PASSWORD`, `MAIL_PASSWORD` | Passwords of Redis and the SMTP server, while `redis.password` / `mail.password` are set |
| `ADMIN_PASSWORD` | Password of the first administrator (optional) |
| `APP_PREVIOUS_KEYS` | Earlier keys during a [key rotation](security.md#key-rotation) (optional) |
| `BILLING_WEBHOOK_SECRET`, `SIGNUP_CAPTCHA_SECRET` | Signing secret of billing events, CAPTCHA secret of the signup form (optional) |

With `secret.create: true` (the default) the chart creates the Secret. On the first install it
generates `APP_KEY` (`base64:` and 32 random bytes), `REVERB_APP_SECRET`, `DB_PASSWORD` (unless the
password comes from `database.password` or `database.existingSecret`) and, unless `admin.password`
is set, `ADMIN_PASSWORD`. On every upgrade it reads the existing Secret and keeps the generated
values, also when the passwords of an external database, Redis or SMTP server come from your
values or Secrets. `secret.appKey` replaces `APP_KEY` (key rotation, restore);
`secret.previousAppKeys`, `database.password`, `redis.password`, `mail.password`,
`billing.webhookSecret` and `signup.captcha.secret` are written to it while they are set.

With `secret.create: false` you create the Secret before the install and the chart only references
it. `secret.appKey`, `secret.previousAppKeys`, `admin.password`, `billing.webhookSecret` and
`signup.captcha.secret` are then not used: put those keys into your Secret. The passwords of the
database, Redis and the SMTP server come from `DB_PASSWORD` of your Secret or from
`*.existingSecret` (which may name your Secret, e.g. `redis.existingSecret: runr-secret` with
`redis.existingSecretPasswordKey: REDIS_PASSWORD`); `database.password`, `redis.password` and
`mail.password` stop the install with a message, as there is no chart Secret to write them to.

The chart reads the existing Secret (Helm `lookup`) and detects cert-manager when `helm install` or
`helm upgrade` runs against the cluster. A tool that renders the chart without cluster access
(`helm template`, some GitOps controllers) would generate a new `APP_KEY` on every render: give it
`secret.appKey` with the current key, or use `secret.create: false`.

### PostgreSQL

RunR's database is PostgreSQL: the API, Horizon, scheduler and Reverb pods share one database
server. The chart's PostgreSQL runs as one pod with the `Recreate` strategy, its volume on
`database.postgres.storageClassName` (empty: the cluster's default StorageClass). Its password is
`DB_PASSWORD` of the Secret (or `database.existingSecret`), which PostgreSQL applies when it
initialises an empty volume; to change it later, change the user's password first
(`kubectl -n runr exec deploy/runr-postgres -- psql -U runr -c "ALTER USER runr PASSWORD '…'"`),
then set the new one in the values or your Secret and upgrade.

For an external PostgreSQL server:

- Create the database (`database.name`, default `runr`) owned by the RunR user (`database.user`,
  default `runr`). RunR keeps its tables in the schema `public`; the multi-tenant edition creates
  one schema per tenant (`tenant_…`) in the same database.
- Give the chart the user's password: `database.existingSecret` with a Secret of yours (key
  `database.existingSecretPasswordKey`), or `database.password`, which the chart writes to its
  Secret. The generated `APP_KEY` and `REVERB_APP_SECRET` stay with the chart.
- TLS to the server: `DB_SSLMODE` through `extraEnv` (default `prefer`, e.g. `require`).

### Redis

The chart's Redis has no volume: a restart of its pod empties the queues and the cache.

For an external Redis server set `redis.host` and `redis.port`. RunR uses the Redis databases 0
and 1 (`REDIS_DB`, `REDIS_CACHE_DB`). An ACL user name goes into `redis.username`, the password into
`redis.existingSecret` (a Secret of yours) or `redis.password` (written to the chart's Secret). With
a password set, the chart's own Redis requires it as well.

### Mail

RunR sends e-mail for sign-up verification, tenant invitations, alerts and resource thresholds.
Mailpit, the default, keeps every message inside the cluster; its web UI is reached with
`kubectl port-forward svc/runr-mailpit -n runr 8025:8025`.

For production set `mail.host` and `mail.port` (the default port `1025` is Mailpit's), `mail.from`
(default `noreply@runr.sh`) to an address of your own domain, and `mail.fromName`. When the server
requires authentication, set `mail.username` and the password: `mail.existingSecret` (a Secret of
yours) or `mail.password` (written to the chart's Secret). `mail.encryption` empty (or `starttls`)
uses STARTTLS when the server offers it and TLS from the first byte on port 465; `tls` uses TLS from
the first byte on any port.

## Storage

| PVC | Mounted by | Size | StorageClass | Access mode |
| --- | --- | --- | --- | --- |
| `runr-api-storage` | `runr-api`, `runr-horizon`, `runr-scheduler`, `runr-reverb` and the hook Jobs, at `/var/www/html/storage` | `storage.application`, else `api.storage.size` (chart default 10Gi) | `api.storage.storageClassName`, else the cluster default | `api.storage.accessModes` (default ReadWriteOnce) |
| `runr-postgres-data` | `runr-postgres` (only with the chart's PostgreSQL) | `storage.database`, else `database.postgres.storage` (chart default 10Gi) | `database.postgres.storageClassName`, else the cluster default | ReadWriteOnce |

The access mode decides where the pods that mount the API volume run:

| `api.storage.accessModes` | Pods |
| --- | --- |
| `[ReadWriteOnce]` (default) | The volume is attached to one node at a time: `podColocation: true` (the default) gives the API, Horizon, scheduler and Reverb pods and the hook Jobs a required pod affinity on the label `runr/storage-group: api`, so they all run on the volume's node |
| `[ReadWriteMany]` | Any node: no colocation, the replicas spread like those of any Deployment. Needs a StorageClass with ReadWriteMany volumes (`api.storage.storageClassName`, e.g. an NFS or CephFS class) |

The access modes and the StorageClass of a claim are set when it is created; Kubernetes does not
change them later. When the values ask an existing claim for others, the install stops with a
message naming the claim.

The API volume holds:

| Path | Content |
| --- | --- |
| `git/` | The internal git repository with the code of every project (one directory per tenant in the multi-tenant edition) |
| `git-remotes/` | The bare repositories behind the project git remotes ([git deploy](git-deploy.md)) |
| `app/public/` | Files served under `/storage`, such as uploaded branding assets |

`api.storage.fixPermissions` (default `true`) runs an init container in every pod that mounts the
API volume and gives it to the `www-data` user (UID 82); some volume types are created owned by
root. `database.postgres.fixPgdata` (default `true`) removes `lost+found` from PostgreSQL's volume
and keeps the data in the subdirectory `pgdata`, owned by the postgres user (UID 70).

A PVC can grow, never shrink: a larger size in an upgrade takes effect when the StorageClass allows
volume expansion. `helm uninstall` deletes both PVCs together with `runr-secret`; a StorageClass
with `reclaimPolicy: Retain` keeps the volumes of a deleted claim.

### Moving the API volume to ReadWriteMany

An installation whose API volume is ReadWriteOnce moves to a ReadWriteMany volume by copying the
files into a new claim of the same name:

1. Back up the API volume ([Backup and restore](#backup-and-restore)):

   ```sh
   kubectl -n runr exec deploy/runr-api -- tar -C /var/www/html -czf - storage > runr-storage.tgz
   ```

2. Stop the pods that mount it and delete the claim:

   ```sh
   kubectl -n runr scale deploy/runr-api deploy/runr-horizon deploy/runr-scheduler deploy/runr-reverb --replicas=0
   kubectl -n runr delete pvc runr-api-storage
   ```

3. Create the new claim from the chart, under the release's ownership, with the values of the
   release and the new access mode:

   ```sh
   helm get values runr -n runr -o yaml > runr-values.yaml
   helm template runr brf-tech/runr --version <version> -n runr -f runr-values.yaml \
     --set 'api.storage.accessModes={ReadWriteMany}' --set api.storage.storageClassName=<rwx class> \
     --show-only templates/pvc.yaml | kubectl -n runr apply -f -
   kubectl -n runr annotate pvc runr-api-storage meta.helm.sh/release-name=runr meta.helm.sh/release-namespace=runr
   ```

4. Upgrade the release with the same two values:

   ```sh
   helm upgrade runr brf-tech/runr --version <version> -n runr --reuse-values \
     --set 'api.storage.accessModes={ReadWriteMany}' --set api.storage.storageClassName=<rwx class>
   ```

5. Restore the files:

   ```sh
   kubectl -n runr exec deploy/runr-api -- rm -rf /var/www/html/storage/git /var/www/html/storage/git-remotes
   kubectl -n runr exec -i deploy/runr-api -- tar -C /var/www/html -xzf - < runr-storage.tgz
   ```

## High availability

| Component | Replicas | Notes |
| --- | --- | --- |
| `runr-gateway` | `gateway.replicas` (2) | Stateless; `gateway.hpa`, `gateway.pdb` |
| `runr-ui` | `ui.replicas` (2) | Stateless; `ui.hpa`, `ui.pdb` |
| `runr-api` | `api.replicas` (2) | Mounts the API volume; `api.hpa`, `api.pdb` |
| `runr-horizon` | `horizon.replicas` (1) | Queue workers; mounts the API volume. Replaced with the `Recreate` strategy: the old pod lets its running jobs end (`horizon:terminate`, 60-second grace period) before the new one starts |
| `runr-reverb` | `reverb.replicas` (1) | WebSockets: console events, browser shells, sandbox exec streams, region agents. With 2 or more replicas they share broadcasts over Redis publish/subscribe (`REVERB_SCALING_ENABLED`), so an event reaches every browser whichever replica holds its connection; a shell, exec or agent session is one WebSocket that stays on the replica it reached, which reads the session from Redis — no sticky routing |
| `runr-scheduler` | always 1 | Runs the scheduled tasks |
| `runr-postgres`, `runr-redis`, `runr-mailpit` | always 1 | Bundled services; `database.postgres.pdb`, `redis.pdb` |

`gateway.hpa`, `api.hpa` and `ui.hpa` add a HorizontalPodAutoscaler that keeps the Deployment
between `minReplicas` and `maxReplicas` at `targetCPU` percent CPU utilization; while it is on,
the HPA owns the replica count and `helm upgrade` keeps the count it set (`*.replicas` is not
used). `gateway.pdb`, `ui.pdb`, `database.postgres.pdb` and `redis.pdb` add a PodDisruptionBudget
with `maxUnavailable: 1`.

`api.pdb` adds the API's budget when the API volume is ReadWriteMany: its pods can then move to
another node during a drain. With a ReadWriteOnce volume the API pods run on the volume's node
([Storage](#storage)), a replacement cannot start elsewhere, and a budget would hold a drain of
that node forever, so the chart creates none (`helm status` says so). More API replicas on that
node still keep the API answering while one pod fails or restarts.

For a database and a Redis that survive the loss of a node, use external services with their own
replication (`database.host`, `redis.host`).

`placement.nodeSelector`, `placement.affinity` and `placement.tolerations` apply to every pod and
hook Job of the chart; `gateway.affinity`, `api.affinity` and `ui.affinity` replace
`placement.affinity` for that component, e.g. to spread the gateway over zones:

```yaml
gateway:
  affinity:
    podAntiAffinity:
      preferredDuringSchedulingIgnoredDuringExecution:
        - weight: 100
          podAffinityTerm:
            topologyKey: topology.kubernetes.io/zone
            labelSelector:
              matchLabels:
                app: runr-gateway
```

`placement.tolerateDedicatedWorkers` (default `true`) adds a toleration of the taint
`runr.dev/dedicated=projects:NoSchedule`, which a region on the same cluster puts on its dedicated
worker nodes, and prefers the other nodes; the colocation of the API volume's pods is added to the
affinity as well.

## First administrator

| Value | Default | Effect |
| --- | --- | --- |
| `admin.username` | `admin` | User name: a lower-case letter, then 2–31 of `a-z`, `0-9`, `_`, `.`, `-` |
| `admin.email` | `admin@example.com` | E-mail address of the account; set a real one |
| `admin.password` | empty | Empty: generated |
| `bootstrap.enabled` | `true` | Renders the Job `runr-bootstrap` |
| `bootstrap.tenancySetup` | `true` | Multi-tenant edition: the Job first converts an installation that ran single-tenant into its first tenant ([Converting a single-organization installation](tenancy.md#converting-a-single-organization-installation)) |

`runr-bootstrap` is a post-install and post-upgrade hook that runs after the database migrations.
It creates the account as a platform administrator when no account with `admin.username` exists,
no other platform administrator exists and `admin.email` belongs to no other account. Existing
accounts are left alone, so changing `admin.*` later changes no account. Before the administrator
check the same Job deploys the agent again on regions whose agent has no token
(`agent:redeploy --missing`), moves stored image references (`images:move-registry`), brings the
existing regions to the installed version (`region:reconcile`) and gives accounts without a role
the default role (`rbac:assign-missing`) — on every upgrade too ([upgrade.md](upgrade.md#procedure)):
keep it on. In the multi-tenant edition it then runs `php artisan tenancy:setup --force`, which
converts an installation that ran single-tenant into its first tenant and leaves fresh, converted
and multi-tenant installations alone.

Reading the password:

| Setup | Password |
| --- | --- |
| `secret.create: true`, `admin.password` empty | `kubectl get secret runr-secret -n runr -o jsonpath='{.data.ADMIN_PASSWORD}' \| base64 -d` (`helm status runr -n runr` prints this command) |
| `admin.password` set | That value |
| `secret.create: false` | `ADMIN_PASSWORD` of your Secret; without it the bootstrap Job generates one and stores it in the Secret `runr-admin`: `kubectl get secret runr-admin -n runr -o jsonpath='{.data.password}' \| base64 -d` (`helm status` prints this command) |

The password is never written to a log. With `secret.create: false` the bootstrap Job may read and
patch the one Secret `runr-admin` (a Role for the `runr-api` ServiceAccount); an attempt that is
repeated uses the password stored before, so the account and the Secret always agree.

After the first sign-in, change the password and turn on two-factor authentication in **User
Settings**.

## Horizon dashboard

Horizon (`https://<domain>/horizon`) shows the queues of the whole installation — running, failed
and completed jobs of every organization — and retries or deletes jobs. It is open to the people
who operate the installation: platform administrators (`users.is_admin`, multi-tenant) and, in the
single-tenant edition, the organization's Owners. The console signs in with API tokens, which a
browser does not send to `/horizon`, so such an administrator opens the dashboard with a
single-use sign-in link:

| From | Command |
| --- | --- |
| The cluster | `kubectl -n runr exec deploy/runr-api -- php artisan runr:horizon-link` (`--user=<name or e-mail>`, default the oldest administrator; `--minutes=<1–60>`, default 5) |
| The API | `curl -X POST https://runr.example.com/api/horizon/link -H "Authorization: Bearer $TOKEN"` with a console session or an unscoped API token of the administrator; the answer is `{"url": …, "expires_at": …}` |

Opening the link signs the browser in to Horizon: the link works once and only until it expires
(stored as a hash in the cache), and the session that it starts is checked again on every Horizon
request — removing the administrator flag or the Owner role, or disabling the account, ends the
access at once. The session cookie is sent over HTTPS only. `POST /api/horizon/link` is recorded
in the audit log as `horizon.link.create`; the link itself is never logged.

## Network policies

`networkPolicy.enabled: true` adds NetworkPolicies that allow exactly these connections and deny
everything else to the chart's pods:

| From | To |
| --- | --- |
| `networkPolicy.gatewayFrom` (NetworkPolicy peers; empty: any source) | `runr-gateway` on `gateway.port` — the console, the API, WebSockets, and the agents of regions, which reach Reverb through the ingress controller (`wss://<domain>/ws`) |
| `runr-gateway` | `runr-ui` (`ui.port`), `runr-api` (`api.port`), `runr-reverb` (`reverb.port`) |
| The API, Horizon, scheduler and Reverb pods and the hook Jobs | PostgreSQL, Redis and the SMTP server (the bundled pods, or any address on `database.port`, `redis.port`, `mail.port` for external ones), `runr-reverb` (broadcasts), DNS |
| The same pods, outbound | `networkPolicy.egress.to` (default every IPv4 and IPv6 address) on `networkPolicy.egress.ports` (default 80, 443, 6443; empty: every port): the regions' Kubernetes API servers, function domains and registries, the Helm repositories of the region components, `ghcr.io`, git providers, alert targets, the Hugging Face Hub |
| — | `runr-ui`, the bundled PostgreSQL, Redis and Mailpit open no connections |

`networkPolicy.extraEgress` adds egress rules of their own to the API, Horizon, scheduler and
Reverb pods and the hook Jobs, e.g. a region registry reached on a NodePort:

```yaml
networkPolicy:
  enabled: true
  gatewayFrom:
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ingress-nginx
  extraEgress:
    - to:
        - ipBlock:
            cidr: 10.20.0.0/16
      ports:
        - port: 30500
          protocol: TCP
```

Keep `gatewayFrom` empty when the ingress controller runs on the host network or the gateway is
exposed with `gateway.serviceType: LoadBalancer` or `NodePort`: the connections then come from node
addresses.

## Example values file

A production install with an external PostgreSQL and Redis, an SMTP server — their passwords in
a Secret `runr-external` created before the install — a Let's Encrypt certificate, two replicas of
the stateless components and network policies:

```yaml
# values-production.yaml
domain: runr.example.com
edition: single-tenant

gateway:
  replicas: 2
  pdb:
    enabled: true
ui:
  replicas: 2
  pdb:
    enabled: true
api:
  replicas: 2
  storage:
    size: 50Gi
    # storageClassName: fast-rwo     # empty: the cluster's default StorageClass

database:
  host: postgres.example.internal
  port: "5432"
  name: runr
  user: runr
  existingSecret: runr-external
  existingSecretPasswordKey: database-password
redis:
  host: redis.example.internal
  port: "6379"
  existingSecret: runr-external
  existingSecretPasswordKey: redis-password
mail:
  host: smtp.example.com
  port: "587"
  from: runr@example.com
  fromName: RunR
  username: runr@example.com
  existingSecret: runr-external
  existingSecretPasswordKey: smtp-password

admin:
  username: admin
  email: platform-admin@example.com

ingress:
  enabled: true
  className: traefik
  tls:
    mode: letsencrypt
    clusterIssuer: letsencrypt-prod

clientIp:
  trustedProxies: "10.42.0.0/16"     # the cluster's pod CIDR

networkPolicy:
  enabled: true
  gatewayFrom:
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: kube-system   # where the cluster's Traefik runs

# Public signup answers 403; accounts come from invitations and administrators
signup:
  inviteOnly: true

# Written to the ConfigMap runr-config
extraEnv:
  DB_SSLMODE: require
```

The chart generates `APP_KEY`, `REVERB_APP_SECRET` and the administrator's password into
`runr-secret` and keeps them on every upgrade. For the multi-tenant edition change `edition` to
`multi-tenant`; the UI then runs the multi-tenant console image.

## Install

1. **Add the Helm repository:**

   ```sh
   helm repo add brf-tech https://helm.brf.sh
   helm repo update brf-tech
   ```

2. **Create the namespace and the Secret with the external services' passwords** (as in the
   example):

   ```sh
   kubectl create namespace runr
   kubectl -n runr create secret generic runr-external \
     --from-literal=database-password='<password of the database user>' \
     --from-literal=redis-password='<password of Redis>' \
     --from-literal=smtp-password='<password of the SMTP user>'
   ```

3. **Install the release**, pinned to one version of the chart. The API and UI images follow it:
   `api.image.tag` and `ui.image.tag` default to the chart's `appVersion`.

   ```sh
   helm upgrade --install runr brf-tech/runr --version 0.5.10 \
     --namespace runr --create-namespace \
     -f values-production.yaml
   ```

   The install runs two hook Jobs: `runr-migrate-install-<revision>` (database migrations, 300-second
   deadline) and then `runr-bootstrap` (the first administrator). Store a copy of the generated
   `APP_KEY` outside the cluster right away ([Backup and restore](#backup-and-restore)).

4. **Check** the pods, the bootstrap log and the version:

   ```sh
   kubectl -n runr get pods
   kubectl -n runr logs job/runr-bootstrap
   curl -s https://runr.example.com/api/version
   ```

   `helm status runr -n runr` prints the gateway, TLS and service summary again.

## Production checklist

- **Pinned versions.** Install and upgrade with `--version`. `api.image.tag` and `ui.image.tag`
  are empty by default and follow the chart's `appVersion`, so `helm rollback` returns to the
  images of that revision as well. Do not set them to `latest`: that tag moves with every
  release.
- **TLS.** `ingress.tls.mode` set to `letsencrypt`, `custom` or `secret`, HTTP redirected to HTTPS
  by the ingress controller.
- **Self-service registration.** Registration is on by default, so anyone who reaches the console
  can create an account; in the multi-tenant edition each signup also gets its own tenant
  (`signup.tenantAutoCreate`) with the default quota `signup.defaultQuota`. Registration is a
  console setting: switch it off in **Settings → Platform → User Registration** or **Settings →
  Signup**, or through the API (`PATCH /api/settings` with `{"registration_enabled": false}`,
  permission `settings.manage`). To have public signup closed from the first start, install with
  `signup.inviteOnly: true`. To keep it open but controlled, use `signup.allowedDomains`,
  `signup.emailVerification` and `signup.captcha` — [white-label.md](white-label.md#2-self-service-signup).
- **Mail server.** `mail.host` set to your SMTP server and `mail.from` to an address of your
  domain. Mailpit, the default, holds every message — sign-up verification links included — and
  its web UI has no sign-in.
- **Administrator.** A real `admin.email`; the generated password changed after the first sign-in;
  two-factor authentication on.
- **APP_KEY.** A copy of `runr-secret` (or at least `APP_KEY`) outside the cluster. Without the key
  the encrypted values in a database backup cannot be read. Never `helm uninstall` a production
  release: it deletes the Secret and both PVCs.
- **Horizon dashboard.** Opened with a single-use link ([Horizon dashboard](#horizon-dashboard));
  keep `app.env: production` and `app.debug: "false"` (with `app.env: local` it admits everyone).
- **Client IP.** `clientIp.trustedProxies` (default `private_ranges`, every private address)
  narrowed to the cluster's pod CIDR. Add 1 to `clientIp.proxyHops` per HTTP proxy in front of
  the ingress controller whose `X-Forwarded-For` the controller keeps, e.g. an L7 load balancer.
  Rate limits, logs and audit records use this address.
- **Network policies.** `networkPolicy.enabled: true` with `networkPolicy.gatewayFrom` set to the
  ingress controller's namespace ([Network policies](#network-policies)).
- **Passwords.** The passwords of an external database, Redis and SMTP server in
  `*.existingSecret` (or `*.password`), not in `extraEnv`, which lands in the ConfigMap
  `runr-config`.
- **Private targets.** `observability.uptime.allowPrivateTargets` and
  `observability.alerts.allowPrivateTargets` stay `false` (the default), so uptime checks and alert
  webhooks of users cannot reach private addresses.
- **Sessions and audit.** `auth.loginTokenTtlDays` (default 30) limits how long a sign-in stays
  valid; `audit.siemForwarding: true` writes every audit event to the container log for a log
  collector ([security.md](security.md#siem-forwarding)).

## Backup and restore

| What | Why |
| --- | --- |
| The database | Everything RunR stores: users, organizations or tenants, projects, versions, regions. With the multi-tenant edition it holds every tenant schema; back up the whole database |
| The API volume `runr-api-storage` | The code of every project (`git/`) and the project git remotes (`git-remotes/`) |
| `runr-secret` | `APP_KEY` decrypts the secrets in the database |

The chart's Redis and Mailpit hold nothing to back up.

**Database** — with the chart's PostgreSQL:

```sh
kubectl -n runr exec deploy/runr-postgres -- pg_dump -U runr -Fc runr > runr-db-$(date +%F).dump
```

With an external server, `pg_dump -Fc` of the whole database (no `-n` / `--schema` filter) with
your own tooling.

**API volume** — through a running API pod:

```sh
kubectl -n runr exec deploy/runr-api -- tar -C /var/www/html -czf - storage > runr-storage-$(date +%F).tgz
```

**Secret:**

```sh
kubectl -n runr get secret runr-secret -o jsonpath='{.data.APP_KEY}' | base64 -d > runr-app-key.txt
```

Keep these three together: a database backup belongs to the API volume of the same time and to
the `APP_KEY` it was encrypted with.

**Restore** into an empty namespace, with the chart's PostgreSQL:

1. Install the same chart version with the same values and the saved key —
   `--set secret.appKey="$(cat runr-app-key.txt)"` — or, with `secret.create: false`, create the
   Secret with the saved `APP_KEY` before the install ([Install](#install)). Wait until the install
   and its hook Jobs have finished.
2. Restore the API volume:

   ```sh
   kubectl -n runr exec deploy/runr-api -- rm -rf /var/www/html/storage/git /var/www/html/storage/git-remotes
   kubectl -n runr exec -i deploy/runr-api -- tar -C /var/www/html -xzf - < runr-storage-<date>.tgz
   ```

3. Stop the pods that use the database:

   ```sh
   kubectl -n runr scale deploy/runr-api deploy/runr-horizon deploy/runr-scheduler deploy/runr-reverb --replicas=0
   ```

4. Replace the database with the dump:

   ```sh
   kubectl -n runr exec deploy/runr-postgres -- dropdb -U runr runr
   kubectl -n runr exec deploy/runr-postgres -- createdb -U runr -O runr runr
   kubectl -n runr exec -i deploy/runr-postgres -- pg_restore -U runr -d runr --no-owner < runr-db-<date>.dump
   ```

   With an external server, restore the dump into an empty database the same way with your tooling.

5. Start the pods again with the replica counts of your values:

   ```sh
   kubectl -n runr scale deploy/runr-api --replicas=2
   kubectl -n runr scale deploy/runr-horizon deploy/runr-scheduler deploy/runr-reverb --replicas=1
   ```

   The starting pods give the restored files to `www-data` again. Sign in with an account of the
   backup; the regions' agents reconnect once the console domain points at the restored install.

## Upgrades

An installation is upgraded in place with `helm upgrade`; the procedure, what runs during it and
the changes per release are in [upgrade.md](upgrade.md).

## Connecting the first region

Set the base domain ([DNS](#dns)), then add a region in **Settings → Add Region** with the
kubeconfig of its cluster; RunR installs Knative Serving and the region components through it.
Region requirements, settings and domains: [regions.md](regions.md).
