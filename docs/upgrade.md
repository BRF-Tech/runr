# Upgrading RunR

A RunR installation is upgraded in place with `helm upgrade` of its release. The database,
the API volume, the generated secrets and every region keep running. Never uninstall and install
again: `helm uninstall` deletes the chart's PersistentVolumeClaims (database, API volume) and the
generated `runr-secret`, whose `APP_KEY` encrypts the secrets in the database.

## Procedure

1. **Back up the database.** With the chart's PostgreSQL:

   ```sh
   kubectl -n runr exec deploy/runr-postgres -- pg_dump -U runr -Fc runr > runr-$(date +%F).dump
   ```

2. **Keep `APP_KEY`.** The chart reads it from the existing `runr-secret` on every upgrade
   (`lookup`), so nothing has to be done for an install the chart created. Do not set
   `secret.appKey` for the first time in the same upgrade (see [Key rotation](security.md#key-rotation)
   for changing it). Tools that render the chart without cluster access (`helm template`, some
   GitOps controllers) cannot read the Secret: give them `secret.appKey` with the current key, or
   manage the Secret yourself (`secret.create=false`).

3. **Upgrade the release** with the new chart and its images. The chart comes from BRF's
   public Helm repository, added as `brf-tech` at install
   ([Kubernetes (Helm)](../README.md#kubernetes-helm)); `--version` pins the release, and the API
   and UI images follow it (`api.image.tag` and `ui.image.tag` are empty by default: the chart's
   `appVersion`):

   ```sh
   helm repo update brf-tech
   helm upgrade runr brf-tech/runr --version <version> -n runr --reuse-values
   ```

   helm returns once the migrations ran, the Deployments were applied and the short bootstrap hook
   ended — about a minute. The regions are brought to the new version afterwards, by the Job
   `runr-region-reconcile-<revision>`, which helm does not wait for (see below).

   `--reuse-values` keeps the values of the running release; keys the new chart added take their
   defaults. `--reset-then-reuse-values` does the same but starts from the new chart's
   `values.yaml`. Values files work as before (`-f values.yaml`); new settings such as
   `branding.*` can be added in the same upgrade.

   **Image tags stored in the release stay.** A release installed with a chart up to 0.5.8 carries
   that chart's default tag `latest` (`--reuse-values` keeps it), and one installed with
   `--set api.image.tag=<version>` keeps that version: the upgrade would change the chart but not
   the images. `helm get values runr -n runr --all` shows the tags. Empty them once, so they
   follow the chart from then on (`helm rollback` included):

   ```sh
   helm upgrade runr brf-tech/runr --version <version> -n runr --reuse-values \
     --set api.image.tag= --set ui.image.tag=
   ```

   or set both to `<version>` on every upgrade.

4. **Check** `GET /api/version` (`version`, and `region_reconcile`: `running` until the regions are
   at the new version, then `done`) and the region Job:

   ```sh
   kubectl -n runr get jobs -l app=runr-region-reconcile
   kubectl -n runr logs -l app=runr-region-reconcile --tail=-1 --prefix
   ```

   A region the Job did not bring to the new version says so in its status in the console (the
   region's **General** tab: the steps that failed or were left), in the region API (`reconcile`)
   and `/api/version` (`region_reconcile: unfinished`); the platform administrators get a
   notification when the Job's last attempt ends so. Fix what it names, then run
   `kubectl -n runr exec deploy/runr-api -- php artisan region:reconcile` (idempotent).

What runs during the upgrade, in order:

| Step | When | What |
| --- | --- | --- |
| `runr-migrate-upgrade-<revision>` Job | pre-upgrade hook, before any Deployment changes | `php artisan migrate` (and `tenancy:migrate`) with the **new** API image and the module switches of the new values (`TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`, `BILLING_ENABLED`, `BILLING_CURRENCY` as explicit env: the ConfigMap is applied after this hook); the previous version keeps serving meanwhile |
| Deployments | after the hook | api, horizon, scheduler, reverb, ui and gateway roll to the new images |
| `runr-bootstrap` Job | post-upgrade hook — the last thing helm waits for, seconds | the database only: `images:move-registry` (stored image references, idempotent), `rbac:assign-missing`, with tenancy `tenancy:migrate` (the tenant migrations of the modules switched on, a safety net), in the multi-tenant edition `tenancy:setup --force` (converts an installation that ran single-tenant into its first tenant, otherwise does nothing; `bootstrap.tenancySetup`), the first administrator when none exists |
| `runr-region-reconcile-<revision>` Job | after the hook, in the background: helm does not wait for it (not a hook) | `agent:redeploy --missing`, `images:move-registry` and `rbac:assign-missing` again (what the previous version's pods wrote until the rollout ended), `region:reconcile` (region-side changes of the new version, idempotent; `--deadline` `bootstrap.regionReconcileSeconds`), `secrets:move-to-region` (secret values still in the database into their regions, `--finalize` with `projectSecrets.finalize`; not with `projectSecrets.storage: database`). Up to three attempts — the next continues where one stopped; kept a week for its logs |

Functions keep serving throughout: their traffic goes through each region's ingress, not through
the console. Existing Knative Services, CronJobs and sandboxes are not redeployed by the upgrade;
they pick up the new version's manifests on their next deploy. `region:reconcile` changes only
what a release lists below (e.g. the image namespace of 0.5.1).

Between the migration job and the end of the rollout the previous version still serves the
console — 27 seconds in the e2e run below (migration job 9 s, then the new pods ready). Do not
edit projects, deploy or change regions in that time: the previous version cannot read values the
migration has just encrypted, shows env vars as empty and would save that. Its region status
checks fail in that window and the new version's next check reports the region again.

## 0.6.10 → 0.6.11

0.6.11 is a dependency release of 0.6.10, made by the dependency test bot: it moves one dependency
of a region and nothing else. There is no database migration, the images keep their names, and
nothing has to be set ([Procedure](#procedure)). The CLI, the Terraform provider and runr.sh stay
those of 0.6.10.

- **Queue consumer Go modules moved** (minor and patch updates): github.com/dgryski/go-rendezvous
  v0.0.0-20200823014737-9f7001d12a5f → none, github.com/jackc/pglogrepl
  v0.0.0-20261003132456-662581bb6bb6 → v0.0.0-20261111174325-d50336b09c47, github.com/jackc/pgx/v5
  v5.11.0 → v5.13.0, github.com/klauspost/compress v1.20.0 → v1.20.2, github.com/nats-io/nats.go
  v1.54.0 → v1.55.0, github.com/nats-io/nkeys v0.4.16 → v0.4.17, github.com/redis/go-redis/v9 v9.7.3
  → v9.23.0, go.uber.org/atomic none → v1.12.0. The release's images carry them.

The bot ran these suites on this release before it was tagged: `runtime/test`, `runtime/ci.sh test`,
`runtime/ci.sh test-helm-upgrade --from=v0.6.10`, `ci/deps-check.sh go:runtime/queue-consumer`,
`ci/deps-check.sh image:runtime/queue-consumer/Dockerfile`
([run](https://github.com/BRF-Tech/runr/actions/runs/43218823525)).

## 0.6.9 → 0.6.10

0.6.10 is a security release of the internal network ([internal-network.md](internal-network.md)).
It changes the API only: no migration, no new Helm value, and the images keep their names; the
region Job moves the region agent and the event gateway to 0.6.10
([Order of operations](#order-of-operations)). Upgrade every installation that has projects in
access mode `allowlist`, allow lists or model services.

### What was exposed

From 0.6.0 to 0.6.9 a change of the internal network that the region's API server did not take
(refused, failed, not reached) was saved and lost until the next deploy:

- **A project in access mode `allowlist` could lose the IP allow list of its public host.** A change
  of its network that could not read its Knative Service took it for a project without one and
  deleted its routes: its `runr.internal` route and the IngressRoutes `runr-access-<service>` and
  `runr-access-<service>-tls` with the Middleware `runr-access-<service>`. The public host then
  answered every client until the project's next deploy.
- **A caller removed from a project's allow list, or deleted with its project, was still admitted**
  on the internal network until the project's next deploy, and so was a later project of the
  deleted one's name in the same organization.
- **A model service kept admitting a project that was unbound from it or deleted**, and a later
  project of that name, until the model service's next deploy. A project bound to it was refused
  (403) until then, and a bound project that was renamed was refused under its new name.

### What 0.6.10 changes

- A write of the internal network that the region does not take is tried again for about twelve
  minutes (an allow list, an access mode, a model service's callers); a binding made while the
  model service deploys is written after the deploy as well.
- A failed read of a project's Knative Service no longer removes its routes: only a missing Knative
  Service does.
- A deleted project leaves the allow lists and the model services it was on; a renamed one is its
  new name there.
- `region:reconcile` (step `internal-network`) writes a model service's callers and a project's
  allow list where the region's differ from the database's. **The region Job of the upgrade runs
  it, so callers lost before the upgrade are put right with the upgrade.**
- The model volume's pod spec features (persistent volume claims, their writes) are part of every
  region's KnativeServing configuration from its install, and `region:reconcile` (step
  `knative-serving-config`) enables them again; a refusal for a feature enables them before the
  write is tried again. Before, they were turned on by a region's first model service deploy, and
  Knative's webhook could refuse that service, or a write to it, for tens of seconds after. The
  upgrade's region Job writes them into each region's `config-features` once; nothing restarts.

### After the upgrade: the projects in access mode allowlist

The region Job does not bring back the routes of a public host that an earlier release deleted.
Every project in access mode `allowlist` (its **Network** tab, `GET /api/projects/{project}/network`)
needs its two IngressRoutes in its region:

```sh
kubectl get ingressroute -A | grep runr-access-
```

and its public host answers `403` to a client outside its ranges. Redeploy a project whose routes
are missing: its deploy writes them.

Until the upgrade (0.6.0 to 0.6.9), the same steps put a lost write right: redeploy a project after
a change of its allow list or access mode, and a model service after a binding changed or a bound
project was deleted or renamed.

### Back to 0.6.9

A rollback to 0.6.9 is a `helm rollback` (`helm history runr -n runr` lists the revisions; where only
one package version is kept, add the 0.6.9 package again and upgrade to it). Nothing runs on the
database, and the region Job of the rollback moves the region agent and the event gateway back to
0.6.9. **Never uninstall** to go back. 0.6.9 loses a write the region does not take again, as above.

## 0.6.8 → 0.6.9

From 0.6.8 the images keep their names and nothing has to be set. The upgrade runs two migrations,
which only add a column; the region Job moves the region agent and the event gateway to 0.6.9
([Order of operations](#order-of-operations)). Traefik, kube-prometheus-stack, Loki, Zot's chart
and the sandbox installers stay where 0.6.8 left them.

### Stopped projects give their capacity back

The migration `2026_11_11_100001_add_capacity_released_at_to_projects` adds the nullable timestamp
`projects.capacity_released_at`; a multi-tenant installation adds the same column to every tenant
schema (`0111_add_capacity_released_at_to_projects`, through `tenancy:migrate` of the upgrade's
migration Job). Existing projects keep what they have; the column stays empty until a project is
resumed on 0.6.9.

What changes once 0.6.9 runs ([architecture.md](architecture.md#region-capacity-and-admission),
[billing.md](billing.md#project-stop-and-resume)):

- **In a pay-as-you-go region — every region while billing is off — a stopped project holds no
  capacity.** The projects that are stopped at the upgrade release their reservation at once: their
  own workload and their environments count neither in the region's budgets nor in their
  organization's quota, so other projects may use the room. Running projects are not touched.
- **A resume admits the project again.** Its redeploy has to fit the region's budgets
  (`capacity_exceeded`) and its organization's quota (`quota_exceeded`) next to what holds the
  region then. When it does not fit, the resume answers `200` without a deployment
  (`redeploy_error`, `redeploy_code`), the project holds nothing until a deploy of it fits, and a
  console deploy or a git push of it is refused the same way (`422`). The project resource carries
  `capacity_waiting` meanwhile, and the project page shows why it waits and what each resource
  lacks.
- **A fixed region keeps a stopped project's reservation.** Its allocation stays billed, and its
  share is lent to its organization's other projects in the region; its resume has to fit its
  allocation minus what the others still use of the lent shares (`fixed_allocation_exceeded`)
  ([billing.md](billing.md#what-a-fixed-project-may-use)).

### The capacity alarm with stopped projects

Region → Workers → **Capacity** shows next to the budgets what the region would need with every
stopped project resumed at the same time (`GET /api/knative-regions/{id}/capacity` → `with_stopped`).
The capacity alarm watches that figure too: when it would cross the alarm's levels (60 % warning,
80 % critical), the region's provider is told how much the region would be exceeded, how much
capacity to add and which stopped projects need how much — a `resource_threshold` notification with
`data.basis: "with_stopped"`, in the app, by mail and to the `resource_threshold` alert rules, once
an hour per region, resource and level.

**Look at the card before the upgrade** on a region with many stopped projects: the check runs every
minute, so such a region alarms within minutes of the upgrade. Nothing has to be changed for it; add
nodes, or delete projects that will not come back.

### Capacity alarm recipients

- **On a multi-tenant installation, the capacity alarm of an organization's own region goes to the
  organization:** its members who manage its regions (owners and admins) see it in the app and get
  the mail. The platform administrators no longer get that mail; the tenant provides that region's
  nodes.
- **A platform region's alarm also counts the platform's own projects**, next to every tenant's.
- **Every capacity alarm mail honours the recipient's `resource_threshold` mail preference**
  (`PUT /api/account/notification-preferences`).

### Build and registry retries

- **A build tries a failed registry operation again.** Function and runtime image builds try the
  manifest of a `FROM` image, the layers Kaniko unpacks and the push up to five more times with
  backoff ([runtimes.md](runtimes.md#compiled-languages)). Nothing has to be set.
- **The region registry's `ghcr.io` upstream gets `maxRetries: 5` and `retryDelay: 2s`**
  ([regions.md](regions.md#registry)). A registry set up by an earlier release gets them with the
  next change `region:reconcile` makes to its config.json, or with `registry:upgrade-zot`; the
  upgrade alone does not restart Zot.
- **A region's setup survives a GitHub 5xx.** The charts a region installs from GitHub releases and
  Knative Serving's CRDs are downloaded again on a 5xx, a 429 or a broken connection, then once
  through the GitHub API's asset endpoint; a chart that comes that way is installed only with the
  digest its repository's index gives it.

### Back to 0.6.8

A rollback to 0.6.8 is a `helm rollback` on the same database (`helm history runr -n runr` lists the
revisions; where only one package version is kept, add the 0.6.8 package again and upgrade to it).
**Never uninstall** to go back. The migrations stay: 0.6.8 has no file for them, its pods find
nothing to do, and it ignores the column. The region Job of the rollback moves the region agent and
the event gateway back to 0.6.8.

On 0.6.8 a stopped project counts in its region again, as before the upgrade. Where other projects
took the room while it was stopped, the region can be over its budgets: running deployments keep
running, a deploy whose reservation does not grow passes, and one that grows is refused until there
is room. A project resumed on 0.6.9 that still waits for capacity deploys on 0.6.8 without the
admission.

**Back on 0.6.9 again**, a project resumed on 0.6.9 and deployed on 0.6.8 still carries its mark
(`capacity_released_at`): it counts as released and its page shows the waiting banner until its
next deploy admits it. Deploy such projects once after the upgrade.

## 0.6.7 → 0.6.8

### Runtime sources

The migration `2026_11_07_100001_create_runtime_sources` runs with the upgrade
([Procedure](#procedure)). It only adds: the table `runtime_sources`; `runtimes.source_id`,
`source_path` and `orphaned_at`; `executor_images.deprecated_at` and `sunset_at`;
`runtime_patches.source_id` and `runtime_patch_feeds.source_id`. Existing runtimes, versions,
patches and projects keep what they have ([runtimes.md](runtimes.md#runtime-sources)).

- **The built-in runtimes now come from RunR's signed catalog.** The API creates the default source
  *RunR runtimes*, the OCI catalog `ghcr.io/brf-tech/runr/runtime-catalog:stable` signed with the
  patch feed's key, and reads it at its start and every 15 minutes. Until it has been read once,
  the catalog the API image ships (`api/resources/runtimes/builtin.json`) stays in use, as in
  0.6.7; the Runtimes page says which one is in use. A read that fails leaves the runtimes as they
  were, and the source's status says why: `unreachable`, `not_found` (the registry has no such
  catalog, or it is not public: ghcr.io answers an anonymous read of both with 401 / 403),
  `invalid_signature` or `invalid_catalog`.
- **The API needs to reach ghcr.io** for it, as it does for the patch feed, or a region's registry:
  `runtimeCatalog.viaRegion` (default `runtimePatches.viaRegion`). An air-gapped installation sets
  `runtimeCatalog.offline: true` (`RUNR_RUNTIME_CATALOG_REF=""`) and keeps the bundled catalog; its
  source then shows `disabled`.
- **Who connects sources.** Platform administrators (`runtimes.manage`) connect sources for every
  organization; members with `runtimes.use_custom` connect their organization's own, while custom
  runtimes are on. A source is a signed OCI catalog or a Git repository of
  `runtimes/<id>/runtime.yaml`, public or private (an HTTPS token or an SSH deploy key, registry
  credentials for a private catalog and private images); credentials are encrypted at rest and
  never returned. The upgrade connects no source besides RunR's catalog.
- **`EXECUTOR_RUNTIMES` is now only the first default of the platform setting Built-in runtimes**
  (Build & Run → Runtimes). Its default changed from the eleven built-ins to every runtime of the
  catalog. An installation that sets `EXECUTOR_RUNTIMES` keeps its list until a platform
  administrator saves the setting.
- **Lifecycle fields.** A manifest may set `deprecated` (a date or `true`), `sunset`, `replacement`
  and `notice` for the runtime, `deprecated` and `sunset` for a version, and
  `versionDiscovery.lifecycle` for discovered versions ([runtimes.md](runtimes.md#lifecycle)). A
  version ends at the earlier of its end of life and its sunset, with the reminders and refusals of
  an end of life; a deprecation is announced once.
- **Projects without a runtime version are refused once their runtime's default version ends**, as
  pinned projects already were. Before, such a project deployed a default that was past its end of
  life. Running deployments stay untouched.
- **Orphaned runtimes.** A runtime that leaves its source (removed from the catalog or repository,
  or its source removed while projects use it) is orphaned, not deleted. No new project or sandbox
  picks it; its projects keep saving and deploying for a grace period of 90 days
  (`RUNR_RUNTIME_ORPHAN_GRACE_DAYS`, via `extraEnv`), which becomes its sunset, and the owners of
  its projects are told once. It is no longer orphaned when it comes back to its source or another
  source of the same owner adopts it. A built-in that RunR's catalog drops is orphaned the same way
  ([runtimes.md](runtimes.md#orphaned-runtimes)).
- The API image adds `openssh-client`, for Git sources with an SSH deploy key.

### New runtimes: Java, .NET, Ruby and Bun

Four runtimes join the built-in catalog, in RunR's catalog and in the one the 0.6.8 API image
ships ([runtimes.md](runtimes.md#built-in-catalog)):

| Runtime | Versions | Image |
|---|---|---|
| `java` | Eclipse Temurin 21.0 and 25.0 | `ghcr.io/brf-tech/runr/exec-java` |
| `dotnet` (C#) | .NET 10.0 | `ghcr.io/brf-tech/runr/exec-dotnet` |
| `ruby` | 3.3, 3.4 and 4.0 | `ghcr.io/brf-tech/runr/exec-ruby` |
| `bun` (TypeScript, JavaScript) | Bun 1.4 | `ghcr.io/brf-tech/runr/exec-bun` |

- Existing projects keep their runtimes; nothing moves.
- With the default of the Built-in runtimes setting (every runtime of the catalog) the four are
  offered right after the upgrade. An installation that lists its runtimes (the setting, or
  `EXECUTOR_RUNTIMES`) offers them once they are added to the list.
- Java and .NET build every save: Kaniko runs the version image, which the region registry fetches
  and `region:reconcile` pre-warms. A .NET function then runs on
  `ghcr.io/brf-tech/runr/reqs/dotnet-runtime-deps:10.0-chiseled-extra`, not on the SDK image.
- A region whose nodes pull through a mirror of their own needs these images there before a
  project uses them.
- The code interpreter of the Sandbox API runs Java and C# too, and TypeScript and JavaScript with
  Bun in a Bun sandbox ([sandbox-api.md](sandbox-api.md)).

### Sandbox commands without kubectl

A sandbox command no longer starts a kubectl process: the API opens the API server's exec stream
itself, a WebSocket per command (`v5.channel.k8s.io`). That covers `/exec` (JSON and SSE), code
runs, file transfers, background processes, kill and pause, and the exec WebSocket without a
terminal. Exit codes, output caps, timeouts, kills, error messages and the activity records stay as
they were. Nothing needs a step ([sandbox-api.md](sandbox-api.md)).

- **`SANDBOX_EXEC_CLIENT`** (via `extraEnv`): `native`, the default; `kubectl` brings back a
  kubectl process for every command, as in 0.6.7.
- **Terminal sessions** (`"tty": true`, the console terminal) keep `kubectl exec -t`. The API image
  keeps kubectl.
- **kubectl takes over by itself** where the native client cannot run a command, before the command
  starts, so it never fails for it:
  - a region kubeconfig with an exec plugin, an auth provider, a token, client certificate or CA in
    a file, basic auth, impersonation or a `proxy-url`;
  - an `HTTPS_PROXY` (`HTTP_PROXY` for an `http://` server) in the API's environment that
    `NO_PROXY` does not exclude, which kubectl and the API's other cluster calls follow;
  - an API server that refuses the WebSocket upgrade. One without `v5.channel.k8s.io` where a
    command's stdin must end is remembered for 10 minutes.

  The API logs each reason once per process:
  `sandbox exec: kubectl instead of the native client: <reason>`.
- The native client takes a bearer `token` (a ServiceAccount's too) and `client-certificate-data` /
  `client-key-data`, alone or together, with `certificate-authority-data` or
  `insecure-skip-tls-verify`. The CA, client certificate and key go to 0600 temp files of the API
  process, as the API's other cluster calls already do.
- The native client asks the API server what the API image's kubectl 1.35 asks, a WebSocket upgrade
  of `pods/exec`: the region kubeconfig needs no other permission.

### The console certificate warning

The console now warns platform administrators (single-tenant: the holders of `platform.manage`,
[below](#the-installations-own-pages-on-single-tenant-installs)) when cert-manager cannot issue the
console's own certificate ([install.md](install.md#when-the-console-certificate-is-not-issued)).
The chart adds three keys to `runr-config` (`CONSOLE_TLS_MODE`, `CONSOLE_TLS_ISSUER`, `CONSOLE_TLS_CHECK`) and,
when cert-manager issues the certificate, two read rules to the Role `runr-api-role`: `get` on the
console's Certificate and `list` on the CertificateRequests of the release namespace.
`ingress.tls.certificateCheck: false` leaves them out.

- **Check the TLS mode the upgrade renders.** A marketplace that merges the package's default values
  under the installation's own values on an upgrade gives a release that never set
  `ingress.tls.mode` the package default, e.g. `letsencrypt`. The console Ingress then gets the
  annotation `cert-manager.io/cluster-issuer: letsencrypt-prod` and the `tls` Secret `runr-tls`
  as soon as the cluster has cert-manager, also when RunR installed that cert-manager for a region in
  the same cluster. Without a ClusterIssuer `letsencrypt-prod` the certificate is never issued and
  the console goes on with the ingress controller's default certificate, which the warning now
  reports. Check after the upgrade:

  ```sh
  kubectl -n runr get configmap runr-config -o jsonpath='{.data.CONSOLE_TLS_MODE} {.data.CONSOLE_TLS_ISSUER}{"\n"}'
  kubectl -n runr get certificate,certificaterequest   # READY True once issued
  ```

  A console served by the ingress controller's default certificate (e.g. a Traefik `TLSStore`
  named `default`) sets `ingress.tls.mode: none` in its own values; one that wants Let's Encrypt
  creates the ClusterIssuer or names an existing one in `ingress.tls.clusterIssuer`.

### The installation's own pages on single-tenant installs

A single-tenant installation has no platform administrators (`users.is_admin` comes with the
multi-tenant edition). Up to 0.6.7 nobody there reached what is theirs: the Patches page, the
banners of a newer RunR release and of the patch feed's trouble, and the platform administrators'
notifications; the console certificate warning above would have reached nobody either. 0.6.8 adds
the single-tenant permission `platform.manage`, which stands in for them
([permissions.md](permissions.md#single-tenant-installs-platformmanage)).

- **The Owners hold it after the upgrade.** Built-in roles are read from the catalog, not stored:
  no migration runs, and the Owners see Build & Run → Patches and the banners once the new API
  pods serve. They also keep the Horizon dashboard, which now follows the same permission.
- **Admins do not.** To let an Admin operate the installation, create a custom role with
  `platform.manage` (Administration → Roles) and give it to them, or to a group they are in: a
  group's role adds to their own.

Multi-tenant installations are unchanged: only platform administrators.

### Region Traefik chart 42.3.1

New regions install the Traefik chart 42.3.1 instead of 42.3.0. Traefik stays v3.7.17, from the same
image (`ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17`). An existing region moves by itself:
`region:reconcile` (step `traefik-chart`) moves it, and no command is needed.

- It is the move of 0.6.6 ([below](#region-traefik-chart-4230)). The step runs a `helm upgrade` of
  the `traefik` release to chart 42.3.1 with the release's own values, rewritten for the keys the
  chart reads. The upgrade is atomic: pods that are not ready within 10 minutes bring the release
  back to its chart, and the next run tries again.
- The Traefik pods roll once, because the pod template carries the chart's version label
  (`helm.sh/chart`). A new pod is Ready before an old one goes (`maxUnavailable: 0`), so requests
  keep being served.
- The new pods run RunR's probe timing instead of the chart's defaults (0.6.8's fix of the values
  RunR gives the chart, in the changelog):
  - readiness after 10 s, every 10 s, a 5 s timeout, two successes, three failures;
  - liveness after 20 s, every 10 s, a 5 s timeout, five failures.
- The `traefik` LoadBalancer Service renders as before apart from the chart's version label, and
  keeps its address. `traefik-internal` now states `type: ClusterIP`, the type it already had.
- Nothing to mirror. A region whose nodes pull through a mirror of their own already has the image.
  With `knative.traefikImage` set, the region keeps that image.
- Left alone, with a `warning:` from `region:reconcile`, as under 0.6.6:
  - a Traefik of a chart before 40;
  - a cluster on Kubernetes before 1.30;
  - a `knative.traefikImage` older than v3.7.17.
- A region still on chart 40.3.0, which 0.6.7 did not move, goes to 42.3.1 in one step.
- **No CRD step is needed.** Helm does not upgrade CRDs. The `traefik.io` CRDs of 42.3.1 only add
  optional fields to those of 42.3.0, and RunR sets none of them:
  - `IngressRoute`: `routes[].respondingTimeouts`;
  - `Middleware`: `buffering.disableRequestBuffer`, `buffering.disableResponseBuffer` and `tap`;
  - `ServersTransport`: `peerCertSANs` and `forwardingTimeouts.readTimeout` / `writeTimeout`;
  - `ServersTransportTCP`: `tls.peerCertSANs`;
  - `TraefikService`: `mirroring.streamBody`.

  Nothing is removed and no field changes type. An installed region keeps the CRDs it has; a new
  region gets those of 42.3.1.

### kube-prometheus-stack 92.22.0

Regions installed from now on run kube-prometheus-stack 92.22.0 instead of 92.21.2. Prometheus
(v3.16.0), the Prometheus Operator (v0.94.2), kube-state-metrics and node-exporter (1.12.1) keep
their versions and images: the chart moves its node-exporter subchart from 4.60.1 to 5.0.0. Nothing
moves by itself: an installed region keeps its chart until
`php artisan cluster:patch-observability [REGION-ID]` moves it, and `region:reconcile` does not.
Run it when it suits the region:

- node-exporter restarts once, one node at a time (a DaemonSet). Its container gets the new
  defaults of the subchart: `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]` and
  `seccompProfile.type: RuntimeDefault`. Its host access (`hostNetwork`, `hostPID`, the `/proc`,
  `/sys` and `/` mounts) stays as it was, and RunR sets no node-exporter values of its own. Each
  node's host metrics have a gap while its pod restarts.
- Prometheus, the Prometheus Operator and kube-state-metrics keep running: their pods render as
  under 92.21.2.
- The admission webhook's certificate Jobs run, as they do on every upgrade of the chart, with
  kube-webhook-certgen 1.11.1 as before, and keep the certificates.
- **No CRD step is needed.** The chart's CRDs are those of 92.21.2.
- Nothing to mirror: the images are those of 92.21.2. A region whose nodes pull through a mirror of
  their own needs nothing new there.
- A region still on 92.18.0 or 92.10.1, not moved under 0.6.7 or 0.6.6, also gets what those
  brought in the same run ([below](#kube-prometheus-stack-92212)).
- Loki's chart and values are those of 0.6.7.

### Back to 0.6.7

A rollback to 0.6.7 is a `helm rollback` on the same database. The migration of 0.6.8 stays: do not
roll it back first.

1. **Roll the release back:** `helm rollback runr <revision of 0.6.7> -n runr --wait`
   (`helm history runr -n runr` lists the revisions). Where only one package version is kept (a
   marketplace that offers the latest release only), add the 0.6.7 package again and upgrade the
   release to it. **Never uninstall** to go back: `helm uninstall` deletes the chart's database
   volume and `runr-secret` with its `APP_KEY`.
2. **Nothing runs on the database.** A rollback runs neither the migration Job nor the bootstrap Job:
   both are upgrade hooks. The 0.6.7 API pods run `php artisan migrate` at their start and find
   nothing to do. 0.6.7 has no file for `2026_11_07_100001_create_runtime_sources`, so its row stays
   in `migrations` and `migrate:status` lists nothing pending. The table `runtime_sources` and the
   new columns stay; they are nullable, and 0.6.7 ignores them.
3. **The regions follow by themselves.** The region Job `runr-region-reconcile-<revision of 0.6.7>`
   runs again. It first waits for the bootstrap hook of that revision, which a rollback does not run,
   and goes on after 420 s (multi-tenant: 1020 s) with `the bootstrap hook of revision <n> did not
   finish within 420 s — going on`. Then it moves the region agent and the queue consumers back to
   0.6.7. The region Traefik stays on chart 42.3.1, because 0.6.7 moves no chart down. Every
   `region:reconcile` of 0.6.7 says so in a `warning:` line of the step `traefik-chart`, which needs
   nothing.

**Do not roll the migration back.** Its `down()` is complete, and with the 0.6.8 API, before the
rollback, `php artisan migrate:rollback --path=database/migrations/2026_11_07_100001_create_runtime_sources.php --force`
would run it. 0.6.7 does not need it, and it costs the next upgrade:

- it deletes the connected sources and their credentials;
- it drops every runtime's link to its source. After the next upgrade, a source connected again
  finds its runtime ids held by runtimes it does not manage and skips them, and a runtime that
  projects use cannot be deleted to make room.

**What keeps working on 0.6.7:**

- Every function keeps serving, and running deployments are not touched.
- Projects on the runtimes of 0.6.7's own catalog deploy as before. 0.6.7 takes their manifests from
  the catalog its image ships, not from the rows RunR's catalog wrote.
- **Projects on Java, .NET, Ruby and Bun keep running and deploying.** 0.6.7 does not have these
  runtimes, so it reads their manifests from their `runtimes` rows: 0.6.8 keeps the manifest on
  every built-in row, also while the catalog of the API image is in use. They deploy on the
  versions 0.6.8 last discovered. 0.6.7 discovers no new ones and leaves the four out of the node
  pre-pull and the region registry's prewarm.
- 0.6.7 does not list these four: the runtime picker and Build & Run → Runtimes do not show them,
  and `GET /api/runtimes/<id>` answers 404. A request to its API still creates a project on one.
- The runtimes of a connected source stay platform runtimes; those of an organization's source stay
  custom runtimes of that organization. 0.6.7 no longer reads their sources: it lists them as
  runtimes registered by hand, and their manifests can be changed in the console.

**What stops or changes:**

- **A source's runtime that discovers its versions loses them.** Its manifest has
  `versionDiscovery` instead of a `versions` list. 0.6.7's `executor:sync` takes the versions of a
  registered runtime from that list only and retires the rest; it runs at the API's start and every
  30 minutes. From then on, a deploy of its projects stops with `Runtime <id>:<version> is no
  longer available`. Their running deployments stay.
- **The images of a private source** cannot be pulled: 0.6.7 has no pull Secret for them. New
  deploys and builds of their projects fail; running pods keep their image.
- What only 0.6.8 has stays stored but is not used:
  - runtime sources, with their pages, their MCP tools, `runr runtimes sources` and the Terraform
    resource `runr_runtime_source` (the API answers 404);
  - the lifecycle: orphaned runtimes are offered again, a version past its sunset deploys again, and
    the deprecation badges and the sunset reminders are gone;
  - the Built-in runtimes setting. 0.6.7 offers the runtimes of `EXECUTOR_RUNTIMES`, by default its
    whole catalog. To keep a selection, set it on the 0.6.7 release with
    `helm upgrade runr brf-tech/runr --version 0.6.7 -n runr --reuse-values --set-string 'extraEnv.EXECUTOR_RUNTIMES=python\,go'`;
    `runtimeCatalog.builtinRuntimes` is a value of the 0.6.8 chart only;
  - the console certificate warning. Its Role rules and its `CONSOLE_TLS_*` keys leave with the
    0.6.8 chart, and the check kept its state in the cache only;
  - single-tenant, `platform.manage`. The Owners and Admins get what 0.6.7 gives them, which has no
    Patches page and no platform banners for them. A custom role that holds `platform.manage` keeps
    it in the database. 0.6.7 does not know the permission, so it shows the role without it, and
    saving the role's permissions there drops it. An API token limited to it reaches nothing more
    there.
- **Patches:** 0.6.7 applies RunR's patch feed as before. The patches of a source's own runtimes stay
  stored, and 0.6.7 handles them like RunR's. When RunR's feed changes while 0.6.7 runs, 0.6.7 marks
  them as no longer in their feed.
- The notifications of 0.6.8 (a deprecated or orphaned runtime, a sunset reminder) stay readable with
  the title and text they were sent with. 0.6.7's console titles a sunset reminder of a version that
  also has an end of life with the end-of-life date.
- Sandboxes keep running until their TTL. 0.6.7 runs their commands with kubectl again, and its code
  runs know neither Java nor C#.
- One job only 0.6.8 queues fails if the 0.6.7 workers take it: the removal of a deleted source's
  pull Secrets from the regions. Those Secrets then stay.

**Upgrading to 0.6.8 again** is the normal upgrade. The migration has run already, so the migration
Job finds nothing to do, and the `executor:sync` of the API's start discovers the versions of the
sources' runtimes again. Where sources are connected, read them all once anew:

```sh
kubectl -n runr exec deploy/runr-api -- php artisan runtime-sources:sync --force
```

It brings back their runtimes as the sources have them, also those changed or deleted by hand on
0.6.7, and the patches 0.6.7 marked as gone. A custom role whose `platform.manage` 0.6.7 dropped
needs it again.

`runtime/ci.sh test-helm-upgrade --rollback` repeats this rollback after the upgrade
([Testing the upgrade path](#testing-the-upgrade-path)).

## 0.6.6 → 0.6.7

### kube-prometheus-stack 92.21.2

Regions installed from now on run kube-prometheus-stack 92.21.2 (Prometheus v3.16.0) instead of
92.18.0 (Prometheus v3.15.1). Nothing moves by itself: an installed region keeps its chart until
`php artisan cluster:patch-observability [REGION-ID]` moves it, and `region:reconcile` does not. Run
it when it suits the region:

- Prometheus restarts once, on the new image. It keeps its volume and its data; the region's
  metrics have a gap while the new pod starts and reads back its write-ahead log.
- The Prometheus Operator restarts once. The chart no longer puts its own version in the labels of
  the operator's pod, so a later chart upgrade restarts the operator only when the operator changes.
- The admission webhook's certificate Jobs run with kube-webhook-certgen 1.11.1 instead of 1.11.0,
  as they do on every upgrade of the chart, and keep the certificates.
- kube-state-metrics and node-exporter keep running: their charts and images are the same. Loki's
  chart and values are those of 0.6.6.
- No CRD step is needed. The Prometheus Operator stays at v0.94.2, and its CRDs are the same as in
  92.18.0 and 92.10.1.
- A region still on 92.10.1, not moved under 0.6.6, also gets what 0.6.6 brought in the same run
  ([below](#registry-monitoring-and-knative)): the operator moves to v0.94.2, the chart recreates
  the admission webhook's Secret as `kubernetes.io/tls` with the same certificates, and
  kube-state-metrics restarts once.
- A region whose nodes pull through a mirror of their own needs
  `quay.io/prometheus/prometheus:v3.16.0-distroless` and `ghcr.io/jkroepke/kube-webhook-certgen:1.11.1`
  there first. Both come from their upstream registries, not from RunR's reqs mirror.

Prometheus v3.16.0 brings fixes of PromQL and of its storage, and makes the `anchored` and `smoothed`
range selectors stable ([release notes](https://github.com/prometheus/prometheus/releases/tag/v3.16.0)).
Its changes of behaviour concern what RunR does not use: the experimental search API, query
statistics, TLS of the web server, remote write and exemplars. It writes its data in the format
v3.15.1 reads.

### Sandbox input and output

0.6.7 changes how a sandbox command's input and output travel, around three bugs of the runtimes.
Nothing needs a step, and Kata and gVisor keep their versions: none of the three is fixed upstream
yet (Kata 4.3.1 is Kata's latest release; gVisor's code is the same up to 20261102.0), and a newer
Kata would run kata-deploy again, which stops Kata pods.

- **Kata's Go runtime (Cloud Hypervisor, QEMU, Firecracker)** stops a command's output once the
  exec's stdin is closed. A command's stdin is no longer closed while it runs: an `/exec` with
  `stdin` (and a code run, as before) takes one exec more, as the input is written into the sandbox
  first and the command reads it from there.
- **Kata's Rust runtime (Dragonball)** ends an exec's stdin once 64 KiB of it wait unread in the
  guest: its passfd IO writes the guest's pipe without waiting. Input to a Dragonball sandbox goes in
  acknowledged frames of 48 KiB, the next once the sandbox read the last. On the single-node test VM
  a 1 MiB upload took 1.0 s and a 10 MiB one 6.6 s (median of 20 each). Turning passfd IO off
  (`use_passfd_io = false`) would avoid the bug as well, but needs kata-deploy to run again.
- **gVisor** returns a short write when a program writes more than 64 KiB at once to an exec's stdout
  or stderr, and a program that does not write the rest loses it (Python with `PYTHONUNBUFFERED`, as
  RunR's Python runtime sets it). A user command on gVisor (`/exec`, `/run` and the exec WebSocket
  without a terminal) now writes through pipes inside the sandbox, whose `cat` writes everything; the
  exit code, kill and timeout stay as they were. It costs about 100 ms per command on the
  single-node test VM (`true`: median 294 ms instead of 192 ms, n=60 each, interleaved).
  Terminal sessions are unchanged.
- Every input is taken as exactly its size and checked once written: input that arrives short fails
  with `runr: the input ended after N of M bytes` (`the upload`, `the archive` for those) instead of
  being stored short. An archive upload is written to `/tmp/.runr/exec` first and extracted from
  there, so while it is extracted it takes its size on the sandbox's disk twice.
- The changes are in the scripts the API runs in a sandbox for each command. Running sandboxes keep
  running, those started by 0.6.6 included, and their next command runs the new way.
- While the API and reverb pods roll, an exec WebSocket without a terminal that reaches a reverb pod
  of 0.6.6 runs as it did in 0.6.6: the session keeps 0.6.6's command next to the new one.
- A custom image (`sandbox.allow_custom_images`) now needs `cat` for `/exec` with `stdin` (code runs
  needed it before), `mkfifo` for the exec WebSocket without a terminal, and on Dragonball `head` or
  `dd` for any input. coreutils and busybox have them all. Without `head` an upload reads to the end
  of its input, as before; without `cat` a command on gVisor writes directly, as before.

## 0.6.5 → 0.6.6

### Kata Containers 4.3.1

RunR installs kata-deploy 4.3.1 instead of 3.28.0 ([kata-hypervisors.md](kata-hypervisors.md)).
Nothing has to be set. On every region with Kata, `region:reconcile` queues the region's Kata
install, and the region shows "installing" until it finishes:

- The `kata-installer` DaemonSet rolls one node at a time. On each node the 3.28 pod's cleanup and
  the 4.3.1 install restart the container runtime, twice back to back (containerd, or the whole
  k3s / RKE2 service: on a single-node cluster the Kubernetes API is unavailable for about 10 to
  20 seconds each time). `region:reconcile --dry-run` reports it first.
- **Running Kata pods stop on each node, so plan this for a quiet hour.** The 3.28 pod's cleanup
  removes `/opt/kata`, and containerd cannot take back a task whose shim is gone when it restarts.
  Pods under runc and gVisor keep running. For a Kata pod it depends on what owns it:
  - A project's pod, or anything else with `restartPolicy: Always`, starts again by itself on
    4.3.1 when the node is done, with its guest image and kernel. In a test on a Kind cluster,
    clh, qemu and Dragonball workloads on one Kata node were down for about 6 minutes.
  - A Sandbox API sandbox (`restartPolicy: Never`) ends. RunR closes it as failed; create it again.
- Most of that interruption is the pull of the new image: about 4 minutes 40 seconds of the 6 in
  that test, after the old pod is gone. Pull it on the Kata nodes first, before the upgrade that
  runs `region:reconcile`:
  `crictl pull quay.io/kata-containers/kata-deploy:4.3.1`.
- RuntimeClasses, handlers and the mappings of the project types stay as they are.
- A Dragonball workload keeps its guest size. The vCPU pod annotations it may carry are ignored.
- A region whose Kata install is not "ready" is left out, as in every reconcile, and stays on 3.28
  until Kata is installed again from its Sandbox tab. Dragonball workloads deployed there meanwhile
  get no vCPU annotations and boot with 1 vCPU.

### Region Traefik chart 42.3.0

New regions install the Traefik chart 42.3.0 (Traefik v3.7.17) instead of 40.3.0 (Traefik v3.7.4).
`region:reconcile` (step `traefik-chart`) moves existing regions:

- It runs a `helm upgrade` of the `traefik` release to the new chart with the release's own values.
  `--reuse-values` cannot do that: chart 41 renamed the logging keys and refuses the old ones, so
  the step reads the values back, rewrites `logs` as `log` and `accessLog`, and sets the image. The
  entry points, RBAC, Services and selectors render as before, and the `LoadBalancer` Service
  keeps its address.
- The Traefik pods roll once. A new pod is Ready before an old one goes (`maxUnavailable: 0`), so
  requests keep being served. The upgrade is atomic: if the new pods do not become ready within
  10 minutes the release goes back to its chart, `region:reconcile` reports the failure and the
  next run tries again. If that rollback fails too, the release stays `failed`, and the next run
  moves it again rather than taking it as done.
- A region whose nodes pull through a mirror of their own needs
  `ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17` there first. With `knative.traefikImage` set, the
  region runs that image on the new chart: mirror v3.7.17 and set it before the upgrade.
  `ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4` stays published for the regions that keep it.
- Left alone, with a `warning:` from `region:reconcile`: a Traefik of a chart before 40, a cluster on
  Kubernetes before 1.30, which the chart refuses, and a `knative.traefikImage` older than v3.7.17
  ([below](#request-headers-with-aliasing-names)). Upgrade Kubernetes or set the image, then run
  `region:reconcile`.
- Helm does not touch CRDs on an upgrade. The `traefik.io` CRDs of the two charts differ by one
  optional field of `Middleware` (`errors.errorRequestHeaders`), which RunR does not set; a new
  region gets the new CRDs.

If `traefik-chart` fails, it fails like any other step of `region:reconcile`: the other steps still
run, the region keeps serving on its chart, the attempt ends in error and the next one tries
again after 5 minutes. After three attempts the Job `runr-region-reconcile-<revision>` ends as
failed and the platform administrators get a notification. RunR's own `helm upgrade` is done by
then: the Job is no hook and the release stays `deployed`, unless you run it with `--wait-for-jobs`,
which makes helm wait for this Job and fail with it. With several serving namespaces in one cluster
the step starts no upgrade that could outlast its time, and the next attempt goes on with the rest.
`bootstrap.regionReconcileSeconds` is 4800 by default now, for the longer worst case. An upgrade
with `--reuse-values` keeps 3600 unless you set it: that is safe, no step is cut, and what the
deadline leaves in the worst case is done by the next attempt.

What changes for traffic is Traefik's own, from v3.7.5 to v3.7.17
([migration notes](https://doc.traefik.io/traefik/migrate/v3/)). A request with more than 500 header
values is answered with 431 before routing, `CONNECT` requests with 501 over every HTTP version,
and a request target like `http:example.com/admin` with 400. `Upgrade: h2c` and `HTTP2-Settings`
request headers no longer reach the backends.

### Request headers with aliasing names

Every entry point of a region Traefik now deletes a request header whose name has anything but
letters, digits and dashes. It comes with the move to chart 42.3.0, and a region on that chart that
does not do it yet gets it from `traefik-chart` (one more roll of the Traefik pods).

Why: Traefik vouches for `X-Runr-Caller` and the gateway's `X-Runr-Consumer` in their dash form only.
A backend that folds header names into variables reads `X_Runr_Caller` or `X.Runr.Caller` as the same
header, so a caller could pass itself off as another project or consumer
([security.md](security.md#region-network-isolation)). Until a region moves it is open to this, as
it was before.

What a project sees: **a header with `_`, `.` or another such character in its name no longer
arrives.** A client that sends `X_Request_Id`, `api_key`, `client_id` or `X.Custom` gets its normal
answer, on public routes, gateway routes and calls between projects alike, but the project does not
get the header, and nothing is logged. nginx does the same by default for underscores. gRPC
metadata keys with `_` or `.` are headers too. Response headers, cookies and query strings stay
as they are. Rename the header to dashes (`X-Request-Id`), or send the value in the query string or
the body. RunR's access logs do not record such headers, so check your clients and what your
projects read.

If an app cannot change in time, `knative.traefikAliasHeadersStrategy: keep` lets such headers through
on every region of the installation: there is no setting per region or project. It reopens the hole
above, so it is a stopgap while the app renames its headers: an underscore or dot alias of the
identity headers can reach workloads, and `region:reconcile` warns on every run. Set it before the
upgrade, and regions move to the new chart with it; set it back to `delete` and `region:reconcile`
moves them again (one roll of the Traefik pods each way).

Regions that keep the old behaviour, each reported as a `warning:` by `region:reconcile`:

- a Traefik of a chart before 40, or on Kubernetes before 1.30: the option does not exist there;
- `knative.traefikImage` older than v3.7.17. The chart refuses the option on such an image, so these
  regions stay on chart 40, and a new region with such an image fails its Serving install. Set
  `knative.traefikImage` to v3.7.17 or later.

### Registry, monitoring and Knative

Nothing here moves by itself, because each one restarts something a region depends on. Run them
when it suits the region:

- **Zot 2.1.23.** Registries set up from now on run it. `php artisan registry:upgrade-zot
  <region-id>` moves an installed one, `--dry-run` first: the registry restarts once, it keeps its
  values and running config, adds the two sync settings Zot 2.1.23 needs
  ([regions.md](regions.md#registry)), and a failed upgrade rolls back. `region:reconcile` does not
  do this.
- **kube-prometheus-stack 92.18.0.** Regions installed from now on run it. `php artisan
  cluster:patch-observability` moves an installed region. These are security rebuilds of Prometheus
  and its operator, so move regions soon. No CRD step is needed.
- **Knative 1.22, 1.23 and 1.24.** A region stays on its version until someone upgrades it, one
  minor at a time. New installs take Knative 1.23 on Kubernetes 1.34 and 1.24 on 1.35 or newer.

Three changes are visible to API and CLI users:

- `compatible-versions` lists four minors at most: those the operator can install. A running
  Serving is still offered its next minor.
- `POST install-serving` answers 422 for a `version` outside that list.
- `runr regions knative install REGION serving` without `--version` follows the running operator
  instead of taking the newest minor the cluster supports.

## 0.6.4 → 0.6.5

0.6.5 takes the dependency updates for published advisories and fixes the API reference, the
sign-in sessions and API tokens, the transfer agent, the sign-in page and the shared notifications
([CHANGELOG.md](../CHANGELOG.md)). It adds one database migration, `notification_user_states`,
which the pre-upgrade migration Job runs before the new pods start, and one Helm value,
`notifications.retentionDays` (default 90): values of 0.6.4, `--reuse-values` included, keep
working and render it as 90. Upgrade with the [Procedure](#procedure).

- The migration `notification_user_states` (central `2026_11_04_100001`, and with multi-tenancy
  the tenant migration `0110` in every organization's schema) holds each member's read and deleted
  state of the shared notifications, the ones without a user such as resource thresholds. It is
  additive only: one table with its indexes and a foreign key to `notifications`; no existing row
  changes. It is safe while 0.6.4 still serves requests: 0.6.4 never reads the table, and on
  PostgreSQL creating the foreign key briefly holds back writes to `notifications`, not reads. A
  shared notification that someone marked read or deleted before the upgrade, or on a 0.6.4 pod
  during the rollout, stays read or deleted for everyone. From 0.6.5, a member's mark read, Mark
  all as read, delete and Delete all apply to that member only; the list, the unread badge and
  `GET /api/notifications/unread-count` are per member. A user's own notifications keep their
  state as before.
- 0.6.5 adds the daily task `notification-retention` (03:45). Its first run after the upgrade
  deletes every in-app notification older than 90 days, read or not, with the members' read state,
  and the notifications of accounts deleted earlier. To keep them longer, set
  `notifications.retentionDays` (`RUNR_NOTIFICATION_RETENTION_DAYS`) before upgrading; `0` keeps
  them. The task runs in the central schema and, with multi-tenancy, in every organization's schema
  ([notifications](helm-values.md#notifications)). Deleting an account now removes its notifications
  and their read and deleted state, centrally and in every organization; removing a member from an
  organization removes their notifications in that organization only.
- Regions that pull through a mirror of their own need `ghcr.io/brf-tech/runr/image-upload:1.1.1`
  there before the upgrade, next to the agent and queue-consumer images of 0.6.5. The upload
  receiver and the transfer agent of the uploads and organization transfers started after the
  upgrade use it (image-upload 1.1.1: a failed pull keeps its reason, a pull its result); nothing
  that runs is changed. An installation that sets `images.uploadImage` or
  `orgTransfer.transferImage` keeps the image named there: point it at 1.1.1 for the fix
  ([images](helm-values.md#images), [orgTransfer](helm-values.md#orgtransfer)).
- Active Sessions and "Revoke All Other Sessions" cover sign-in sessions only: revoking the other
  sessions keeps the user's API tokens (CI, CLI, MCP clients), which it ended before. `GET
  /api/account/sessions` lists sign-in sessions only and leaves out those that ended; `DELETE
  /api/account/sessions/{id}` answers 404 for an API token's id, and `DELETE /api/tokens/{id}`
  for a sign-in session's id. Disabling the account now signs the other browsers out at once
  ([Sign-in sessions and API tokens](security.md#sign-in-sessions-and-api-tokens)).
- Every user may list and revoke their own API tokens and connected MCP clients (`GET` / `DELETE
  /api/tokens`, `GET` / `DELETE /api/mcp/clients`: route rule `@own:tokens.manage`), so
  Administration → API Tokens is in everyone's menu; creating a token or connecting an MCP client
  still needs `tokens.manage`, which now reads "Create API tokens". An API token limited to some
  permissions must still carry `tokens.manage` to list or delete the user's other tokens. In an
  organization of the multi-tenant edition, a member without `tokens.manage` lists their own
  tokens only ([Organization](permissions.md#organization)). `GET /api/tokens` adds `mcp_client`
  for each token; nothing is removed.
- `GET /api/branding` adds `features.auth_providers` (the AuthProvider module is on); nothing is
  removed. The sign-in page asks for SSO providers (`GET /api/auth-providers/discover`) only when
  the console has the module's screens and the API reports it on
  ([UI build flags](modules.md#ui-build-flags)).
- The API reference opens again. `/api/docs.json` and the page `/api/docs` serve an OpenAPI
  document the API image builds (`php artisan runr:api-docs`), instead of generating it on each
  request, which ended in a 500 at the 30 s limit or never loaded; `/api/docs.json` carries an ETag
  and `Cache-Control: public, max-age=300`. The page loads the Scalar viewer, which the image now
  carries. The document lists the endpoints of every module, also of those the installation has
  turned off (their routes answer only where the module is on), and names its server relative to
  the host (`servers`: `/api`): a client generated from it takes the installation's address as
  its base. Its 677 operations are in 54 groups, one group each (such as "AI > GPU", "Regions >
  Install", "Tenants"), each with a one-line summary and an operationId of its own; the operations
  that need no sign-in (webhooks, Git callbacks, invitations, the public status and developer
  pages) no longer show a required token. The image build fails when an operation lacks one of
  these (`php artisan runr:api-docs --check`).
- Six operationIds changed; an SDK generated from the document names its methods after them:

  | Operation | Before | Now |
  | --- | --- | --- |
  | `GET /projects/{project}/workflow-runs` | `workflows.workflowRun.index_0` | `workflowRun.index` |
  | `GET /projects/{project}/workflows/{workflow}/runs` | `workflows.workflowRun.index_0` | `workflowRun.indexForWorkflow` |
  | `GET /projects/{project}/workflows/app` | `workflows.workflow` | `workflow.app` |
  | `POST /model-services/{service}/invoke` | `ai.modelService` | `modelService.invoke` |
  | `GET /billing/settings` | `settings.show` | `billingSettings.show` |
  | `PUT /billing/settings` | `modules.billing.settings.update` | `billingSettings.update` |

  The two run lists shared one operationId before, which OpenAPI does not allow. Routes and
  behaviour are unchanged.
- The UI image sends its files pre-compressed: the build puts a Brotli and a gzip copy next to
  every text file above 1 KiB, and Caddy sends them as they are (`file_server { precompressed br
  gzip }`); what has no copy (small files, `branding-defaults.js`) is still compressed on the fly,
  now gzip first, and a client that takes only zstd gets it on the fly as before. Cache-Control and
  the other headers are unchanged. Both UI images (`ui`, `ui-mt`) are built this way and are larger
  for the copies: 150 MB for linux/amd64, against 124 MB for `ui:0.6.4`.
- The UI image builds on `ghcr.io/brf-tech/runr/reqs/node:22-alpine` (vue-i18n 11 requires
  Node.js 22), its build stage on the build host's platform: a UI image built from source with
  `vue/Dockerfile` ([UI build flags](modules.md#ui-build-flags)) pulls it.
- `compose/build.sh` builds the API image with its OpenAPI document (the image's last stage, no
  longer `--target base`); the build machine downloads the Scalar viewer from jsDelivr, checked
  against its SHA-256 ([development.md](development.md#useful-commands)).
- The region agent and the queue consumer move to 0.6.5 (the Job
  `runr-region-reconcile-<revision>` after `helm upgrade`); gateway-auth, supply-chain and the Spin
  shim keep their versions.

A rollback to 0.6.4 is a plain `helm rollback`: there is no migration to undo; the
`notification_user_states` table stays and 0.6.4 ignores it (shared notifications show the one
state they keep in `notifications`). The region Job of the 0.6.4
chart moves the region images back to 0.6.4, and uploads and transfers started after the rollback
use image-upload 1.1.0 again. 0.6.4 lists API tokens under Active Sessions again, and its "Revoke
All Other Sessions" ends them. Notifications that the retention task or an account deletion removed
stay removed; 0.6.4 does not use `notifications.retentionDays`.

## 0.6.3 → 0.6.4

0.6.4 brings bot protection on the anonymous forms and a Helm fix for secret values
([CHANGELOG.md](../CHANGELOG.md)). It adds one database migration, `captcha_settings`, which the
pre-upgrade migration Job runs before the new pods start. Its new Helm values `captcha.*` are
optional and the protection stays off until a provider is set, so values of 0.6.3,
`--reuse-values` included, keep working. Upgrade with the [Procedure](#procedure).

- Bot protection is off until set: `captcha.provider` is `none` by default. To switch it on, set
  a provider with its site key and secret under Administration → Access & security → Bot
  protection, or with the Helm values `captcha.provider`, `captcha.siteKey` and `captcha.secret`
  (and, optional, `captcha.forms` — empty: every form —, `captcha.fallback`,
  `captcha.verifyTimeoutMs`, `captcha.localMaxNumber`; [helm-values.md](helm-values.md#captcha),
  [Bot protection](security.md#bot-protection)). The values are schema-checked: an unknown
  provider or form stops the upgrade with a message. The secret goes to the Secret as
  `CAPTCHA_SECRET`; with `secret.create: false`, put `CAPTCHA_SECRET` into your Secret. A wrong
  configuration — a key missing, a stored secret that cannot be decrypted, or keys the provider
  refuses — never refuses everyone: the forms get the local check, the administrators an in-app
  alarm, and the settings page shows the problem under the keys
  ([Monitoring](security.md#monitoring)).
- The sign-up CAPTCHA becomes the bot protection's `register` form. A CAPTCHA saved in
  Administration → Signup moves to Bot protection with the forms set to `register` (the migration
  copies it, the old settings stay stored for a rollback), so it keeps guarding the sign-up form
  only; tick more forms to widen it. The Helm values `signup.captcha.*` keep guarding the sign-up
  form while `captcha.provider` is `none`. It no longer fails closed: a wrong configuration (no
  site key or secret, a secret the provider refuses) or a provider out of reach hands out the local
  check instead of refusing every sign-up, and the administrators get an alarm.
- The API adds `GET /api/captcha/config`, `POST /api/captcha/local/challenge`, `GET` / `PUT
  /api/admin/captcha`, `GET` / `PUT /api/tenant/captcha` and `GET /api/admin/captcha/metrics`.
  The `captcha_*` fields of `/api/admin/signup-settings` and the CAPTCHA section of
  Administration → Signup are gone; `GET /api/auth/registration-status` still reports the sign-up
  form's provider and site key. Once the protection is on, a client that sends a guarded form
  without the console needs a token ([The flow](security.md#the-flow)).
- The pods roll once. With `secret.create: true` (the default) the API, UI, Horizon, scheduler and
  Reverb pods get the annotation `checksum/secret-values`, a hash of the values' secret values
  salted with the stored `APP_KEY`; the first upgrade to 0.6.4 rolls them once, with the new
  images. After that, a `helm upgrade` that changes `captcha.secret`, a password,
  `billing.webhookSecret` or `secret.appKey` rolls them too, so the new value applies at once
  ([secretName and secret](helm-values.md#secretname-and-secret)). With your own Secret there is
  no such annotation: restart the pods after changing it.
- The region agent and the queue consumer move to 0.6.4 (the Job
  `runr-region-reconcile-<revision>` after `helm upgrade`); gateway-auth, image-upload,
  supply-chain and the Spin shim keep their versions.

A rollback to 0.6.3 is a plain `helm rollback`: there is no migration to undo; the
`captcha_settings` table stays and 0.6.3 does not use it, and 0.6.3 goes back to the sign-up
CAPTCHA settings stored before the upgrade (changes made on the Bot protection page do not reach
it).

## 0.6.2 → 0.6.3

0.6.3 brings white-label themes, RunR's colours and logo in the console, and fixes for read-only
roles ([CHANGELOG.md](../CHANGELOG.md)). It adds no database migrations. Its new Helm values
`branding.colors.dark`, `branding.colors.light`, `branding.defaultTheme` and `auth.frozenAccounts`
are optional and empty by default, so values of 0.6.2, `--reuse-values` included, keep working.
Upgrade with the [Procedure](#procedure).

- The console has two themes, Dark and Light; the navy theme is gone. A browser that has it
  stored opens in Dark. To keep the navy look, set it as a white-label theme: the "Ocean" preset
  on the Branding page, or `branding.colors.dark`
  ([white-label.md](white-label.md#theme-tones-and-the-default-theme)).
- RunR's own colours change: the dark theme is neutral black (`#0a0a0a`), the light theme warm
  (`#fffcf8`), warnings yellow. An installation with its own brand (`branding.colors.primary`,
  `accent`) keeps them; its themes take the new defaults unless `branding.colors.dark` /
  `light` are set.
- The UI container gets the platform's branding defaults: the chart gives it the `BRANDING_*`
  keys of `runr-config`, and its entrypoint writes them to `/branding-defaults.js` at start, so a
  first visit opens in the platform's theme. A UI container that cannot write the file starts
  without them and says so in its log.
- A first visit opens in the browser's language: Turkish for a Turkish browser, English for every
  other one (it was Turkish for all). A user's own choice still wins.
- `auth.frozenAccounts` (env `RUNR_FROZEN_ACCOUNTS`) is new and optional: e-mail addresses of
  shared sign-ins, such as a public demo login, that cannot change their own account; empty (the
  default) changes nothing ([Shared accounts](security.md#shared-accounts)).
- The branding API (`GET /api/branding`, `/api/admin/branding`, `/api/tenant/branding`) adds the
  theme fields (`dark_color`, `dark_scale`, `light_color`, `light_scale`, `default_theme`), an API
  gateway adds `can_manage`, and `GET /api/account` and `GET /api/auth/me` add `frozen`; nothing is
  removed. The `runr_branding` attributes `dark_color`, `light_color` and `default_theme` of the
  Terraform provider 0.6.3 need a 0.6.3 API.

A rollback to 0.6.2 is a plain `helm rollback`: there is no migration to undo; theme settings saved
with 0.6.3 stay stored, and 0.6.2 does not use them.

## 0.6.1 → 0.6.2

0.6.2 changes how the release images are built and gives the Terraform provider its Terraform
Registry address ([CHANGELOG.md](../CHANGELOG.md)). It adds no database migrations and no Helm
values; values of 0.6.1, `--reuse-values` included, keep working. Upgrade with the
[Procedure](#procedure).

- The images keep their names and tags (`ghcr.io/brf-tech/runr/api`, `ui`, `ui:<version>-mt`,
  `agent`, `queue-consumer`); they are built by the repository's release workflow now and carry
  RunR's own OCI labels. A registry mirror or an allow list keyed on the image names needs no
  change.
- The API image carries `docs/` again, so the MCP server's `runr://docs/{page}` resources answer
  without `MCP_DOCS_PATH` ([mcp.md](mcp.md)).
- Terraform configurations with `source = "runr.sh/brf-tech/runr"` keep working with this
  release's provider; moving them to `brf-tech/runr` is optional
  ([terraform.md](terraform.md#install)).

A rollback to 0.6.1 is a plain `helm rollback`: nothing of 0.6.2 has to be undone first.

## 0.6.0 → 0.6.1

0.6.1 is a fix release ([CHANGELOG.md](../CHANGELOG.md)). It adds no database migrations and no
Helm values; values of 0.6.0, `--reuse-values` included, keep working. Upgrade with the
[Procedure](#procedure).

- With tenancy, the upgrade's migration Job runs the tenant migrations of the Billing and Ticket
  modules that tenant schemas missed. 0.6.0 skipped them while the module was switched off
  (`billing.enabled` is off by default): a tenant created on 0.6.0 has no `billing_*` tables, and
  a tenant of 0.5.x lacks the two Billing migrations of 0.6.0 (`billing_cost_limits`, queue consumer
  charging). They create tables and columns only; billing stays as it is set.

- Horizon works a second queue, `deploys`, next to `default`: a new deployment's Knative apply and
  its readiness checks. Its process pool always keeps a worker of its own, so an idle
  `runr-horizon` runs one more worker process (about 70 MB). The chart's default memory limit
  (1 GiB) covers it; a lower `horizon.resources.limits.memory` needs room for it.

### Rolling back to 0.6.0

0.6.0's Horizon does not work the `deploys` queue. A deploy whose apply or readiness check still
waits there when the 0.6.0 pods take over stays `pending` or `running`.

1. Before `helm rollback runr <revision of 0.6.0> -n runr`, let the running deploys finish:
   `kubectl -n runr exec deploy/runr-api -- php artisan queue:monitor redis:deploys` shows a size
   of 0. A readiness check gives up after 300 s at the latest. Where only one package version is
   kept (a marketplace that offers the latest release only), add the 0.6.0 package again and
   upgrade the release to it. **Never uninstall** to go back: `helm uninstall` deletes the chart's
   database volume and `runr-secret` with its `APP_KEY`.
2. A deploy started after that check, before the 0.6.0 pods run, keeps its status on 0.6.0: deploy
   that version again.

## 0.5.10 → 0.6.0

0.6.0 adds environments, triggers beyond HTTP, schedules and queues, the internal network, secret
stores, global projects, the API product, organization export and import, runtime patches, load
tests and rightsizing ([CHANGELOG.md](../CHANGELOG.md)). Values of 0.5.10, `--reuse-values`
included, keep working, and nothing has to be set for the upgrade itself. Some defaults change
what running installations do, so read the list below before upgrading.

### Before the upgrade

1. **Back up the database** (step 1 of the [procedure](#procedure)) and keep the dump together with
   a copy of `APP_KEY` until the upgrade is finalized. The values of project secret variables that
   are still in the database, and their rollback copies, are encrypted with it
   ([Project secrets move into the regions](#project-secrets-move-into-the-regions)).
2. **List the projects that call each other inside a region** (their cluster-local URLs). After the
   upgrade every region enforces the internal network, and such a call needs an entry on the
   callee's allow list ([Internal network of the regions](#internal-network-of-the-regions)).
3. **Decide on cert-manager in the regions.** `region:reconcile` installs cert-manager on every
   region cluster that runs none. `--set knative.certManager.install=false` keeps it out, and
   `--set knative.certManager.acmeEmail=<address>` lets regions that are reachable from the
   internet get Let's Encrypt certificates ([cert-manager in the regions](#cert-manager-in-the-regions)).
4. **Decide on the runtime patch feed.** It is on by default, and at the deadline of a critical
   or zero-day patch RunR redeploys the projects still behind (`runtimePatches.autoMigrate: true`).
   An API without access to `ghcr.io` reads the feed through a region (`runtimePatches.viaRegion`)
   ([Runtime patches](#runtime-patches)).
5. **Check that the regions' network plugin enforces NetworkPolicies** if you rely on the
   isolation of project pods ([Private ingress and network policies](#private-ingress-and-network-policies)).
6. **With the Billing module:** pay-as-you-go bills start to count previews, scheduled runs and the
   CPU and memory of scale-to-zero sessions ([Metering and bills](#metering-and-bills)).

### Order of operations

1. **Upgrade the release** (step 3 of the [procedure](#procedure)) with the values you decided on:

   ```sh
   helm upgrade runr brf-tech/runr --version 0.6.0 -n runr --reuse-values
   ```

   The pre-upgrade Job runs the migrations of 0.6.0, central and per tenant schema (tenant
   migrations `0067` to `0108`). Among other things, every project gets a trigger list built from
   its configuration (`0077_create_trigger_tables`), and every schedule that exists is pinned to
   UTC ([Schedule triggers get a time zone](#schedule-triggers-get-a-time-zone)). The Deployments
   then roll to the 0.6.0 images.
2. **helm returns** once the bootstrap hook ended (the database steps, seconds): the upgrade itself
   takes about a minute, as before, whatever the regions need.
3. **The Job `runr-region-reconcile-<revision>` brings the regions to 0.6.0 in the background;
   nothing has to be run by hand.** It waits for the bootstrap hook, then runs
   `agent:redeploy --missing`, `region:reconcile` with this release's steps (see below) and
   `secrets:move-to-region`, which moves the secret values still in the database into their
   regions. On a region of 0.5.10 this release's steps roll the region Traefik three times, wait
   for the KIngresses and the internal network's authz and can install cert-manager: about
   5 minutes per region cluster (2½ minutes for the private-ingress move on Kind), up to 10 on a
   slow one. Each attempt lets the command start steps for 80 minutes
   (`bootstrap.regionReconcileSeconds`, 4800 — the worst case of one cluster, every wait of every
   step at its limit, is about 78 minutes) and lets the step running then end, so no change is cut
   in the middle. Raise the value for many region clusters. Watch it:

   ```sh
   kubectl -n runr get jobs -l app=runr-region-reconcile
   kubectl -n runr logs -l app=runr-region-reconcile --tail=-1 --prefix -f
   ```

   An attempt that does not finish — a step failed, a step still waits for something (the authz
   without a ready pod, …), or the deadline left steps — ends with
   `runr-region-reconcile: ERROR region:reconcile did not finish`, and the Job starts the next
   attempt (after 5 minutes when a step failed or waits), which continues where it stopped: every
   step is idempotent. A Helm release of a region Traefik that a killed `helm upgrade` left
   `pending-upgrade` is rolled back and upgraded again once the pending operation is 13 minutes
   old. A region whose cluster does not answer is a warning (`WARNING some region clusters did not
   answer`): the region keeps what it had, and `region:reconcile` brings it up once it answers.

   While it runs and when its last attempt left regions behind, the console shows it in the
   region's status (the region's **General** tab names the steps), the region API in `reconcile`
   and `/api/version` in `region_reconcile`; the platform administrators get a notification. Fix
   what it names and run the command (or `helm upgrade` again, which starts a new Job):

   ```sh
   kubectl -n runr exec deploy/runr-api -- php artisan region:reconcile
   ```

   The steps new in 0.6.0 run in this order: `private-ingress` and `network-policy`
   ([Private ingress and network policies](#private-ingress-and-network-policies)),
   `internal-network`, `tracing` (where tracing is on: the collector's span metrics per Knative
   revision, which canary rollouts read; the collector restarts once), `cert-manager`,
   `external-secrets` and `openbao` (only where a region runs them), `gateway-auth` and
   `gateway-routes` ([Gateways and the API product](#gateways-and-the-api-product)), and
   `global-dns` (only where a region runs a global DNS server).
4. **Add the allow-list entries** of step 2 of [Before the upgrade](#before-the-upgrade), or switch
   the regions you want to look at first to audit (`runr regions set REGION --set
   internal_auth=audit`): the upgrade enforces on every region
   ([Internal network of the regions](#internal-network-of-the-regions)).
5. **The region agent and the queue consumers move to 0.6.0 by themselves.** Up to 0.5.10 RunR
   ran them at `:latest`; from 0.6.0 on they run at the installed version
   (`ghcr.io/brf-tech/runr/agent:0.6.0`, `queue-consumer:0.6.0`, like the API and UI images), and
   `region:reconcile` (step `image-registry`) moves the region agent, the internal network's authz,
   the queue consumers and topic publishers of existing regions to it: each rolls once. The agent
   of 0.6.0 meters previews and the runs of schedule triggers. Regions that pull through a mirror
   of your own need `ghcr.io/brf-tech/runr/agent:0.6.0` and `queue-consumer:0.6.0` there
   (`RUNR_AGENT_IMAGE`, `RUNR_QUEUE_CONSUMER_IMAGE` name other references).

6. **Check the secret values:**
   `kubectl -n runr exec deploy/runr-api -- php artisan secrets:move-to-region --dry-run` lists
   what is still in the database only.
7. **Finalize** once 0.6.0 is confirmed: `--set projectSecrets.finalize=true`. After that, 0.5.10
   cannot be installed again ([Project secrets move into the regions](#project-secrets-move-into-the-regions)).

With Docker Compose, the [update](../compose/README.md#updating) runs the migrations and
`region:reconcile` by hand. Run the secret move after it:

```sh
docker compose exec api php artisan secrets:move-to-region
# once 0.6.0 is confirmed:
docker compose exec api php artisan secrets:move-to-region --finalize
```

### New Helm values

Every new value has a default that works without setting it
([Helm values](helm-values.md)):

| Values | Default | What they do |
| --- | --- | --- |
| `projectSecrets.storage`, `projectSecrets.finalize` | `region`, `false` | Where secret values go and when the upgrade from 0.5.10 is finalized ([projectSecrets](helm-values.md#projectsecrets)) |
| `knative.certManager.install`, `.acmeEmail`, `.acmeServer`, `.chartVersion`, `.imagePrefix` | `true`, empty | cert-manager in the regions: installed where a cluster runs none; the ACME account e-mail (empty: the region CA); the ACME server (empty: Let's Encrypt production); the pinned chart and the reqs mirror's images ([knative](helm-values.md#knative)) |
| `runtimePatches.feed`, `.publicKey`, `.viaRegion`, `.autoMigrate` | the feed `ghcr.io/brf-tech/runr/runtime-patches:feed`, the key it is signed with, empty, `true` | The signed runtime patch feed and what RunR does at a deadline ([runtimePatches](helm-values.md#runtimepatches)) |
| `observability.loadTest.*` | `enabled: true`, `dailyLimit: 20`, `maxUsers: 100`, `maxDurationSeconds: 300`, `maxConcurrentPerRegion: 5` | Load tests of HTTP projects ([observability](helm-values.md#observability)) |
| `globalDns.*` | empty (the defaults in [globalDns](helm-values.md#globaldns): zone `g.<base domain>`, `failover.afterSeconds` 180, `dataLink.port` 443) | Global DNS, the zone's certificate, failover and the data links of global projects |
| `apiProduct.*` | empty (gateway-auth `1.0.0`, keys synced every 10 s, usage kept 400 days) | The API product's gateway-auth ([apiProduct](helm-values.md#apiproduct)) |
| `orgTransfer.*` | empty (transfer image `image-upload:1.1.0`, packages up to 50 GB, kept 168 hours) | Organization export and import ([orgTransfer](helm-values.md#orgtransfer)) |

The internal network, External Secrets Operator, OpenBao, the event gateway's broker sources,
CloudNativePG replicas and the gateway-auth metering proxy have no Helm values: they are set per
region or per project. The environment settings they read (`RUNR_ESO_CHART_REPO`,
`RUNR_OPENBAO_STORAGE_CLASS`, `RUNR_OPENBAO_DB_TTL`, …) go into `extraEnv`
([extraEnv](helm-values.md#extraenv)).

Regions that pull through a mirror of their own need the new images: the reqs mirrors
`ghcr.io/brf-tech/runr/reqs/cert-manager-controller`, `-webhook` and `-cainjector` (`v1.21.2`),
`reqs/k6:1.8.2`, `reqs/external-secrets`, `reqs/openbao`, `reqs/pdns-auth:5.0.8` and
`reqs/nginx:1.27-alpine`, plus `ghcr.io/brf-tech/runr/gateway-auth:1.0.0`,
`ghcr.io/brf-tech/runr/image-upload:1.1.0`, and the agent and queue-consumer images of this release
([Images and Helm charts](regions.md#images-and-helm-charts)).

### Project secrets move into the regions

**In short:** upgrade as usual. The region Job of the upgrade (`runr-region-reconcile-<revision>`)
moves the secret values into the regions. Until
you finalize the upgrade (`--set projectSecrets.finalize=true`), the database keeps an encrypted
copy of every value, so 0.5.10 can be put back safely ([Rolling back to 0.5.10](#rolling-back-to-0510)).
Secret store references can be set once the upgrade is finalized. After finalizing, 0.5.10 must
not be installed again.

RunR's database stops storing the values of project secret variables. From this release a value
goes straight into the Kubernetes Secret `<project-slug>-secret-env` of the project's region and
lives there only. The database keeps the name, a version, who changed it when, and a keyed
fingerprint ([Secret environment variables](security.md#secret-environment-variables)). The Helm
value `projectSecrets.storage` (env `RUNR_SECRET_STORAGE`) is `region` by default.

Values written before the upgrade stay encrypted in `project_secrets` and keep working: every
deploy writes them into the region as before, and the API lists them with `stored_in: database`
(`runr env ls`: `database`). The chart's region Job (`runr-region-reconcile-<revision>`, which
helm does not wait for) moves them after the rollout (`secrets:move-to-region`, time-limited; a
region that does not answer does not hold up the upgrade). Check and repeat by hand:

```sh
kubectl -n runr exec deploy/runr-api -- php artisan secrets:move-to-region --dry-run
kubectl -n runr exec deploy/runr-api -- php artisan secrets:move-to-region
```

For each project, the command writes every value into the project's region Secret (unless the
same value is there already), reads the Secret back and compares each value by its fingerprint. It
covers the central schema and every tenant schema (`--tenant=<slug>` and `--project=<id>` narrow
it), never prints a value, and records a `secrets.moved_to_region` audit event. It is idempotent
and resumable: a project whose region does not answer, or that has no region cluster, keeps its
values and is reported. Run the command again once the region answers. It exits non-zero while
values are left in the database only.

**Until you finalize the upgrade, the database keeps an encrypted copy of every value**: the moved
ones and every value set after the upgrade. That way 0.5.10 can be installed again without losing
them. References to secret stores wait until then ([Rolling back to 0.5.10](#rolling-back-to-0510)).
Finalize once this release is confirmed:

```sh
helm upgrade runr <chart> -n runr --reuse-values --set projectSecrets.finalize=true
# or: kubectl -n runr exec deploy/runr-api -- php artisan secrets:move-to-region --finalize
```

Finalizing checks every value in its region against its fingerprint (and writes it from the copy
when the region lost it), drops the copies and records `secrets.pass_through_finalized`. While a
region does not answer, it exits non-zero and keeps the copies. A fresh installation of this
release is finalized from the start.

- **Postponing the move**: `--set projectSecrets.storage=database` keeps the behaviour of 0.5.10
  for new values (encrypted in the database, written into the region by every deploy). The setting
  is deprecated; switch back to `region` and run the command when you are ready.
- **`APP_KEY`**: keep it, and a copy of it, until the upgrade is finalized. The values and copies
  still in the database are encrypted with it. Database backups taken before finalizing hold the
  values: handle them as secrets.
- **The regions hold the only copy** once the upgrade is finalized. A cluster lost without a backup
  of its Secrets loses the values; its projects' deploys then name the missing values, and you set
  them again. If you need restores that do not depend on RunR, back up the project namespaces'
  Secrets with the cluster, or keep values in a secret store of your own and refer to them
  ([Secret stores](secret-stores.md)).

What else changes for secret variables:

- a new value reaches containers that start afterwards at once, and every container with the next
  deploy (before, only with the next deploy);
- stopping a project and deleting a deployment keep the values Secret; deleting the project, and
  detaching a region from an organization, delete it;
- a deploy fails with the names of values that are not in the region;
- reading a value back reads the region: `503` when it does not answer;
- a region migration copies the values to the new cluster and a rollback copies them back; moving a
  project to another region copies them there;
- previews read the project's Secrets; the copy `<service>-secret-env` a preview of an earlier
  release has is removed by its next deploy.

#### Secret stores

New and optional: secret variables that refer to a value in the organization's own Vault /
OpenBao, AWS Secrets Manager, GCP Secret Manager or Azure Key Vault, read in the region by External
Secrets Operator with the region's identity ([Secret stores](secret-stores.md)). They can be set
once the upgrade is finalized; until then they are refused with `409 upgrade_not_finalized`,
because 0.5.10 could not read them after a rollback. Nothing is installed by the upgrade: the
operator is installed per region when wanted (`regions.manage`), and `region:reconcile` keeps its
chart at the pinned version afterwards. Regions without outside access need the chart repository
(`RUNR_ESO_CHART_REPO`) and the image `ghcr.io/brf-tech/runr/reqs/external-secrets` reachable.

Two permissions are new: `secret_stores.view` (the built-in Developer role has it) and
`secret_stores.manage` (Owner and Admin) — who gets them is in [Permissions](#permissions).

#### OpenBao in the region and dynamic Postgres credentials

New and optional: a region can run an OpenBao that RunR installs and operates
([OpenBao in the region](secret-stores.md#openbao-in-the-region)): `POST
/api/knative-regions/{id}/openbao`, once External Secrets Operator runs there. While it runs, new
secret values of the region's projects go into it (`stored_in: openbao`). Values written before
stay where they are until they are set again, and `secrets:move-to-region` moves values still in
the database into it. Postgres bindings can then be attached with dynamic credentials
([bindings](bindings.md#dynamic-credentials)). Nothing is installed by the upgrade. The nodes pull
`ghcr.io/brf-tech/runr/reqs/openbao`, and the instance needs a volume of the default StorageClass
(`RUNR_OPENBAO_STORAGE_CLASS`, `RUNR_OPENBAO_STORAGE_SIZE`). Back up its volume together with the
region's Secret `runr-openbao-unseal`: RunR keeps neither.

The migration `2026_10_21_210002` (tenant schemas: `0079`) adds `binding_attachments.credential_mode`
(`static` for every existing attachment); `2026_10_21_210001` adds the OpenBao columns of
`region_secret_settings`; `2026_10_22_500001` (tenant schemas: `0105`) adds
`project_secrets.rollback_copy` and marks a fresh installation finalized.

### Internal network of the regions

Projects of a region call each other on the [internal network](internal-network.md): under
`<project>.<organization>.runr.internal`, admitted by the callee's allow list. **Existing regions
enforce it after the upgrade, like new ones: a call between two projects needs an entry on the
callee's allow list.** RunR's own traffic keeps working without a redeploy.

- `region:reconcile` (step `internal-network`) installs the authz (`runr-internal-auth`, two pods in
  the Serving namespace), adds its ForwardAuth to the region Traefik's private entry point (a Helm
  upgrade with the release's values: the Traefik pods roll once), the `runr.internal` rewrite to
  CoreDNS (it reloads its Corefile) and the policy annotations to the Knative Services RunR runs.
  The step says so where CoreDNS is not RunR's to change.
- From then on the region enforces: **enforced on upgrade; switch to audit right after if
  needed.** The allow lists and the region setting `internal_auth` are new in this release, so
  neither can be set on 0.5.10: list the projects that call each other before the upgrade, and
  right after it add the callers to the callees' allow lists (`runr network callers add`, the
  console's Network tab, `network.callers` in `runr.yaml`). An operator who wants to look first
  switches the region to audit right after the upgrade instead (`runr regions set REGION --set
  internal_auth=audit`, the region's General → **Settings**, or the API): calls are admitted,
  and the ones the allow lists would refuse are logged
  ([Enforce and audit](internal-network.md#enforce-and-audit)). Both take effect within ten
  seconds of being saved; until then, calls between projects without an entry are refused.
- RunR's own traffic needs nothing, and nothing is redeployed or restarted: the authz knows a
  caller without a token by its pod's address, so queue consumers keep delivering to their
  project, functions keep calling the model services they are bound to (without a header) and
  the region demo's frontend its backend (the upgrade grants it). The web shell and the model
  playground call with RunR's own token. Workloads get `RUNR_INTERNAL_TOKEN` on their next deploy.
- A caller whose address is hidden — a client of your own behind a proxy, a cluster that rewrites
  pod source addresses — needs the project's token in `X-Runr-Internal-Token`.
- Every deployed project now has its Secret `<project>-secret-env` (it holds the token); before, a
  project without secret variables had none.
- New permission `network.manage` (access mode and allow list), part of the built-in Developer role
  and of the Member role the upgrade to 0.5.0 created for tenant members, while that role is as the
  upgrade left it ([Permissions](#permissions)).
- New organizations cannot take the slug `platform`, the organization name of the projects outside
  any organization. An existing organization with that slug keeps it; its projects and those
  outside any organization then share the organization name, and grants between them are not
  marked as cross-organization.
- Rotating `APP_KEY` changes the regions' signing keys: run `php artisan region:reconcile` and
  redeploy the projects afterwards.

### Private ingress and network policies

The region Traefik no longer serves the cluster-local hosts of Knative Services
(`<service>.<namespace>`, `.svc`, `.svc.cluster.local`) on the entry point its LoadBalancer
publishes: through the region's external address they answer `404`. In-cluster callers reach them
on the private entry point (`traefik-internal`) as before. Two NetworkPolicies admit traffic to
revision pods only from Knative Serving's Traefik, activator and autoscaler
([Cluster-local services and network isolation](regions.md#cluster-local-services-and-network-isolation)).

- `region:reconcile` (steps `private-ingress` and `network-policy`) moves existing regions. Traefik
  is upgraded twice, and its pods roll twice; on Kind the move took 154 s without a failed request
  or queue delivery.
- The policies need a network plugin that enforces NetworkPolicies; a plugin without support
  ignores them.
- Tools of your own that reach project pods directly (a Prometheus scraping their ports, a service
  mesh) need a NetworkPolicy of their own.
- Custom domains, gateway routes and the second address of a base-domain migration hand requests
  on to the project's public host, so they reach public HTTP projects only.

### cert-manager in the regions

Custom domains in TLS mode `cert-manager` no longer need a cert-manager and a ClusterIssuer that
the operator installed by hand ([cert-manager](regions.md#cert-manager)).

- `region:reconcile` (step `cert-manager`) installs `jetstack/cert-manager` v1.21.2 (namespace
  `cert-manager`, images from the reqs mirror) on every region cluster that runs none, and reuses
  a cert-manager the cluster already runs. It never installs a second one, and RunR never removes
  one it did not install. `knative.certManager.install: false` stops the install.
- RunR creates the default ClusterIssuer unless the region setting `cert_manager_cluster_issuer`
  names one: `runr-acme` (Let's Encrypt, HTTP-01 through the region's Traefik) where the region is
  reachable from the internet and an ACME e-mail is set (the region setting
  `cert_manager_acme_email` or `knative.certManager.acmeEmail`), else `runr-ca`, a region CA.
  `cert_manager_issuer_mode` (`auto`, `acme`, `ca`) chooses.
- New custom domains start in TLS mode `cert-manager` where the region issues certificates (before:
  `none`). Existing domains keep their mode.

### Schedule triggers get a time zone

A schedule trigger runs in its `timezone`, or in the organization's time zone when it has none
([Schedules and time zones](triggers.md#schedules-and-time-zones)). **Schedules that exist before
the upgrade keep running in UTC**: the migration sets `timezone: UTC` on them. A schedule created
afterwards without a zone — through the API, runr.yaml, the CLI, MCP, the Terraform provider or
the single-trigger fields (`config.schedule`) — runs in the organization's zone (`Europe/Istanbul`
unless the organization or the platform set another). Name `UTC` where a new schedule must stay in
UTC (`timezone` of the trigger, `config.schedule_timezone` of the project API, `trigger.timezone`
of Terraform's `runr_project`). A CronJob gets `spec.timeZone` with its project's next deploy.

### Triggers and the event gateway

- Every project has a [trigger list](triggers.md). The upgrade builds each project's list from its
  configuration; `config.trigger_type`, `schedule` and `queue_stream` keep working and mirror the
  primary trigger.
- The queue consumer becomes the project's event gateway (same image
  `ghcr.io/brf-tech/runr/queue-consumer`, same Deployment `<slug>-qc`). A project whose only
  gateway trigger is one queue keeps the manifests of earlier versions. A consumer that restarts on
  the new image before its project's next deploy runs in the event gateway's
  [legacy env mode](triggers.md#legacy-env-mode), with the queue consumer's guarantees.
- A project with a schedule next to other triggers gets its ticks from the event gateway as
  CloudEvents (`dev.runr.schedule.tick`) instead of a CronJob pod. A project whose only trigger is a
  schedule keeps its CronJob.
- New permission `topics.manage` (Developer and above).

### Global projects

[Global projects](global-projects.md) are new: nothing exists to migrate from 0.5.10.

- Global DNS is off until an operator enables it per platform region; RunR then runs a PowerDNS
  server there.
- Two scheduled jobs run every minute: `global-projects-failover` (health of the regions,
  automatic failover, rejoin) and `global-projects-data` (replica clusters and data links).
- **Global projects fail over automatically by default**, after 180 s of a failed primary
  (`globalDns.failover.afterSeconds`). Set a project's failover to `manual` (Regions tab,
  `PUT /projects/{project}/global {"failover": "manual"}`, `runr projects global set <project>
  --failover manual`) to keep the primary where it is until you move it.
- An installation that ran a pre-release build of 0.6.0: the upgrade migrates the phase-2 columns
  (central `2026_10_22_300001`, tenant `0095`) and moves the builds the regions' copies kept on
  their own rows into the per-version, per-region image store (`version_region_images`; central
  `2026_10_22_300002`, tenant `0096`). `GET /projects/{project}/global` lists the bindings across
  the regions under `bindings`; those builds called the key `data`. `region:reconcile` (step
  `global-dns`) applies each region's PowerDNS server again, and the server pod restarts once.

### Gateways and the API product

- `region:reconcile` (step `gateway-routes`) rebuilds the routes of every gateway that an earlier
  release built: the Host rewrite per route, one `Method()` matcher per method (a route with
  several methods was not served before) and the direct routes of allowlist and gateway-only
  projects. No deploy is needed. `--dry-run` lists them.
- A gateway is served on the region's function domain, like the project URLs (before: the base
  domain). On a region with a function domain of its own, the gateway's host moves to it. A
  gateway's `custom_domain` gets a route of its own (`gw-<id>-domain`) with a certificate from the
  region's ClusterIssuer.
- The [API product](api-product.md) is new. Routes stay `public` until set to `key` or `jwt`;
  RunR installs gateway-auth in a region with the first such route. New permission
  `api_products.manage`.
- A completed or rolled back base-domain or organization-domain migration moves custom domains,
  domain aliases and gateways at once instead of on the next status check
  ([Changing the base domain](regions.md#changing-the-base-domain)).

### Runtime patches

- The [runtime patch feed](runtimes.md#runtime-patches) is on by default: the API checks
  `ghcr.io/brf-tech/runr/runtime-patches:feed` every five minutes and verifies its signature with
  `runtimePatches.publicKey` ([Runtime patch feed](security.md#runtime-patch-feed)). An API without
  access to `ghcr.io` reads it through a region's registry (`runtimePatches.viaRegion`); an empty
  `runtimePatches.publicKey` turns the feed off.
- **At the deadline of a critical or zero-day patch, RunR redeploys every project still behind**
  onto the newest patch, a pin included, and rolls one back when the new revision does not become
  ready ([Deadlines](runtimes.md#deadlines)). `runtimePatches.autoMigrate: false` or the policy on
  the Patches page (which wins) turns that off.
- Deployments, versions and projects record the runtime image digest they run. Without a patch
  the feed knows — the feed off, a runtime version it does not list, region builds, uploaded
  images, custom runtimes, CaaS — workloads run the version image by its tag as before.
- The PHP runtime images run PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12; a PHP function gets the fixed
  release with its next deploy.

### Metering and bills

With the Billing module, pay-as-you-go bills count more of what runs after the upgrade:

- **Scale-to-zero sessions** count the size their deployment was deployed with (CPU, memory and
  GPUs) for the time the pod ran. Before, they counted 0 vCPU-hours and 0 GB-hours.
- **Runs of schedule triggers** and **preview environments** are metered: previews as a bill line
  of their own (`component: preview`), in a fixed region within the allocation. Both need the
  agent of 0.6.0, which `region:reconcile` moves the regions to (step 5 of the
  [order of operations](#order-of-operations)).
- **Queue-trigger sessions** are filed under `queue`, with the queue multiplier.
- **Queue consumers** can be charged per region and organization; this is **off by default**, so
  the upgrade raises no bill for them ([Queue consumers](billing.md#queue-consumers)).
- Days metered before the upgrade keep their values. `php artisan usage:recalculate FROM [TO]`
  recalculates earlier days of existing deployments: open bills read the new values, closed bills
  do not change.

Without the Billing module the same usage is metered, but not billed.

### Sign-in

The console's sign-in field takes the user name or the e-mail address in any letter case, and
so do `POST /api/auth/login` (`login`) and `runr login --with-password --username`
([Password sign-in](security.md#password-sign-in)). Existing accounts sign in as before. Of
accounts that differ in letter case only, the exactly spelled one signs in; invitations and SSO
sign-ins refuse an address that several such accounts share (`email_ambiguous`) instead of
creating another account. A migration adds (non-unique) indexes on `lower(username)` and
`lower(email)`.

### The API image

The API image is built on PHP 8.3.36. The opcache JIT is off (opcache stays on): under the
thread-safe PHP of the image, the tracing JIT crashed API workers. Session upload progress
tracking is off, `session.use_strict_mode` is on, and the API no longer sends `X-Powered-By`.

### Permissions

New permissions ([Roles and permissions](permissions.md)): `environments.manage`,
`environments.promote`, `network.manage`, `secret_stores.view` and `topics.manage` are part of the
built-in Developer role; `environments.approve`, `secret_stores.manage`, `api_products.manage` and
`org.transfer` are Owner and Admin only (exporting and importing an organization also needs the
Owner role). The built-in roles (Owner, Admin, Developer, Viewer, Billing) have them from the
upgrade on: their permissions come with the release.

The **Member** role that the upgrade to 0.5.0 created for tenant members (multi-tenant) gets the
five Developer permissions as well — a fresh tenant gives its members Developer, so upgraded
members keep step with them. The tenant migration `0108_grant_060_permissions_to_legacy_member_role`
adds them to every Member role that still holds what the upgrade gave it (with or without some of
the five already added). **A Member role your organization changed is left as it is**: add the
permissions it should have in **Administration → Roles** or with
`runr roles update member --add network.manage …`. Other custom roles get new permissions only
when an administrator adds them.

### API changes in 0.6.0

The new endpoints are in [CHANGELOG.md](../CHANGELOG.md). Endpoints that answer or act differently:

- `POST /api/projects/{project}/deployments/{deployment}/rollback` deploys the older version with
  the configuration it ran with; `keep_current_config: true` keeps the current one
  ([Rollback](versioning.md#rollback)). `runr rollback` and the MCP tool `rollback` likewise.
- `POST`, `PUT /api/projects` and deploys refuse a `min_scale` above `max_scale` (`422`).
- `GET /api/projects/{id}/metrics/summary` answers `sessions.total_cpu_ms` and `total_memory_mb_ms`
  as the deployment's size × the sessions' pod time; they were always `0`.
- The project resource carries `triggers`, `runtime_patch`, `config.schedule_timezone` and
  `config.schedule_effective_timezone`; a deployment carries `runtime` (image, digest and patch).
- `POST /api/projects/{project}/domains`: a new domain's `tls_mode` is `cert-manager` where the
  region issues certificates.
- `project.stopped` webhooks and a project's `stopped` state carry `system: "cost_limit"` when the
  cost limit stopped it.
- `GET /api/projects/{project}/secrets` and the other secret endpoints: see
  [the secret API changes](#secret-api-changes) below.

#### Secret API changes

- `GET /api/projects/{project}/secrets` adds `source`, `version`, `stored_in`, `fingerprint`,
  `rollback_copy`, `reference` and `sync` per variable and `storage`; `PUT …/secrets/{name}` also
  takes `{"reference": {…}}` (`409 upgrade_not_finalized` until the upgrade is finalized);
  `POST …/secrets/refresh` is new.
- `GET …/secrets/{name}/value` adds `matches_fingerprint` and can answer `503`
  (`region_unreachable`), `409` (`value_missing`) and `422` (a reference).
- `POST /api/projects` with `config.secret_env_vars` answers `secret_env_error` when the region did
  not take the values (the project is created); `PUT /api/projects/{project}` fails as a whole when
  it cannot write them, or cannot copy the values to the new region of a region change.
- Setting a secret value for a project without a region cluster (no region, the demo region) is
  refused (`422`).
- `/api/secret-stores…`, `/api/knative-regions/{id}/external-secrets` and
  `/api/knative-regions/{id}/openbao` are new; `source` and `stored_in` of a secret variable can be
  `openbao`.
- `POST /api/projects/{project}/bindings` and `PATCH …/bindings/{attachment}` take `credentials`
  (`static`, `dynamic`); an attachment shows `credential_mode`.

### CLI and console

- `runr workflows app signing-key --env` is now `--dotenv`: `--env` names an
  [environment](workflows.md#environments) in every `runr workflows` command. Scripts that wrote
  `--env >> .env` write `--dotenv >> .env`.
- The console's navigation is a main menu with mega menus; the administration pages moved out of
  the avatar menu. The settings URLs did not change (`/settings?tab=<key>`).

### Terraform provider

- Upgrade the provider to 0.6 before or with the API ([Terraform](terraform.md#upgrading-from-05)).
  Provider 0.5 cannot create a `runr_domain` against a 0.6.0 API in a region that issues
  certificates unless the domain sets `tls_mode = "cert-manager"` (the API starts it in
  `cert-manager`, provider 0.5 expects `none`: "inconsistent result after apply").
- A configuration and state of provider 0.5.10 plan no change with provider 0.6: the new
  attributes (`runr_project.triggers`, `network`, `deploy_strategy`, `global`, …) are optional and
  keep RunR's values when left out. Domains created with `none` keep it.

### Rolling back to 0.5.10

0.5.10 builds a project's `<slug>-secret-env` from the database on every deploy: a value that lived
in the region only would be written there as an empty string. That is why the database keeps an
encrypted copy of every value until you finalize the upgrade
([Rolling back to 0.5.10](security.md#rolling-back-to-0510)). **Before finalizing, going back to
0.5.10 is safe**:

1. Put the 0.5.10 chart on the release, on the same database: `helm rollback runr <revision of
   0.5.10> -n runr`, or `helm upgrade runr <the 0.5.10 chart> -n runr --reuse-values`. Where only
   one package version is kept (a marketplace that offers the latest release only), add the 0.5.10
   package again and upgrade the release to it. **Never uninstall** to go back: `helm uninstall`
   deletes the chart's database volume and `runr-secret` with its `APP_KEY`. 0.5.10's migrations
   find nothing to do; the newer tables and columns stay and are ignored, and so are the values it
   does not know (`projectSecrets.*`).
2. 0.5.10 deploys every project from the copies: values that live in the region's Secret or its
   OpenBao reach the workload through `<slug>-secret-env` again. Running workloads keep their
   current pods until then.
3. What 0.5.10 does not have stays where it is and comes back with the next upgrade: environments,
   secret store settings, the region's OpenBao and External Secrets Operator (no reference exists
   before finalizing).
4. Upgrading again later is the normal upgrade: values changed or created on 0.5.10 no longer match
   their fingerprints, count as values in the database and are moved again by the region Job (a
   deploy before that writes them into the region as well).

**After finalizing**, the copies are gone: do not install 0.5.10 again, because its deploys would
write empty secret values. If you must, restore the database backup taken before the upgrade
([Procedure](#procedure), step 1) and set every secret value changed since then again, or keep
0.5.10 from deploying projects with secret variables until their values are set again.

## 0.5.7 → 0.5.8

The Helm chart's production settings and fixes of single sign-on, multi-tenancy, support tickets
and regions. Values of earlier releases, `--reuse-values` included, keep working. Check before the
upgrade:

- **Back up the database first** (step 1 of the [procedure](#procedure)) and keep the dump: this
  upgrade cannot be rolled back with migrations, going back to 0.5.7 is restoring that backup
  ([Rolling back](#rolling-back)).
- **Multi-tenant edition with `--reuse-values`:** add `--set ui.image.multiTenantSuffix=-mt`
  ([Multi-tenant console image](#multi-tenant-console-image)).
- **Inactive and maintenance regions stop serving** after the upgrade; set the regions that should
  keep answering requests active first ([Region status takes effect](#region-status-takes-effect)).
- **SSO providers:** a provider registered with an `http://` callback URL needs the `https://` one
  ([HTTPS URLs](#https-urls-behind-the-ingress-controller)); a Google provider with a Hosted Domain
  admits accounts of that domain only ([Single sign-on](#single-sign-on)).
- **Passwords in `extraEnv`:** move them to the new password values
  ([Passwords](#passwords-of-an-external-database-redis-and-smtp-server)).
- **Switching to the multi-tenant edition** in this upgrade converts the installation into its
  first tenant: give the upgrade `--timeout 20m` ([Multi-tenant installs](#multi-tenant-installs)).
- **Docker Compose:** take the new `Caddyfile` and the new `api.env.example` settings
  ([Docker Compose](#docker-compose)).
- **Billing is a new module, off by default.** The plans and the billing of 0.5.7 are removed;
  their data is archived and converted. Upgrade after the 0.5.7 `charge-schedule` closed the last
  month (the 1st, 02:30), adapt your billing system to the new API and set `BILLING_CURRENCY` to the
  currency of your 0.5.7 cost configuration in this upgrade, also if billing stays off for now
  ([Billing](#billing)).

### HTTPS URLs behind the ingress controller

The gateway keeps the `X-Forwarded-Proto` of the ingress controller instead of overwriting it with
its own `http`, so the API builds `https` URLs:

| URL | Before | Now |
| --- | --- | --- |
| SSO callback of an OAuth / OpenID Connect provider (`/oauth/<provider>/callback`), sent to the provider and shown in the provider settings | `http://<domain>/…` | `https://<domain>/…` |
| MCP server and OAuth metadata (`/mcp`, `/.well-known/oauth-*`) | `http://<domain>/…` | `https://<domain>/…` |

Where a provider has the `http://` callback URL registered, register the `https://` URL (providers
compare it exactly; Google never accepted the `http://` one). SAML service provider URLs (entity
ID, ACS, metadata) are built from `APP_URL`, which is `https://<domain>` already: they stay as they
are and the identity providers need no change.

### The API's disruption budget

`api.pdb.enabled` creates the API's PodDisruptionBudget only with a ReadWriteMany API volume
(`api.storage.accessModes`, new). With the ReadWriteOnce volume of every earlier install the API
pods cannot leave the volume's node, and the budget held a drain of that node forever: the upgrade
removes it, and `helm status` notes it. The API pods stay colocated with the volume as before.

### Replica counts with an autoscaler

With `gateway.hpa`, `api.hpa` or `ui.hpa` on, the chart leaves the replica count of that Deployment
to the HorizontalPodAutoscaler: an upgrade keeps the count the HPA set instead of resetting it to
`*.replicas`.

### Passwords of an external database, Redis and SMTP server

`database.password` / `database.existingSecret`, `redis.password` / `redis.existingSecret` and
`mail.password` / `mail.existingSecret` (with `mail.username`, `mail.encryption`, `redis.username`)
bring these passwords from Secrets. An install that has them in `extraEnv` (`REDIS_PASSWORD`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`) keeps working; move them in the same upgrade and
remove them from `extraEnv`, which lands in the ConfigMap `runr-config`:

```sh
helm upgrade runr brf-tech/runr --version 0.5.8 -n runr --reuse-values \
  --set redis.password='<redis password>' \
  --set mail.username=runr@example.com --set mail.password='<smtp password>' --set mail.encryption=tls \
  --set extraEnv=null
```

(`--set extraEnv=null` removes every `extraEnv` entry: keep others, e.g. `DB_SSLMODE`, with
`--set extraEnv.DB_SSLMODE=require`.) An install with `secret.create: false` sets
`*.existingSecret` instead. An install that used `secret.create: false` only to give an external
database's password can keep doing so.

### Horizon dashboard

`/horizon` answered 403 to everyone in production. Platform administrators and, in the
single-tenant edition, Owners open it with a single-use link: `kubectl -n runr exec deploy/runr-api -- php artisan
runr:horizon-link`, or `POST /api/horizon/link` ([install.md](install.md#horizon-dashboard)). The
Compose gateway routes `/horizon` too ([compose/README.md](../compose/README.md#horizon-dashboard)).

### CORS

The API allows the console's own origin (`https://<domain>`) and `cors.allowedOrigins`; the
development origins (`localhost:5173`, `runr.test`, …) it allowed in production are gone. The
console is same-origin and needs none of them. Browser pages on other origins that call the API
need `cors.allowedOrigins`.

### Multi-tenant console image

With `edition: multi-tenant` the chart runs the multi-tenant console,
`ghcr.io/brf-tech/runr/ui:<tag>-mt` (`ui.image.multiTenantSuffix`, default now `-mt`; with the tag
`latest` the chart's release, `<appVersion>-mt`). Before, the empty default suffix ran the
single-tenant console, without the tenant and SSO pages. `--reuse-values` keeps the empty suffix
of the earlier release, so add it to the upgrade:

```sh
helm upgrade runr brf-tech/runr --version 0.5.8 -n runr --reuse-values \
  --set api.image.tag=0.5.8 --set ui.image.tag=0.5.8 --set ui.image.multiTenantSuffix=-mt
```

A UI image of your own that carries the modules under its plain tag keeps
`ui.image.multiTenantSuffix: ""`. Single-tenant installs are not affected.

### Ticket module

`ticket.enabled` (new, default `true`) sets `TICKET_ENABLED` of the API; `true` is the module state
of every earlier install, also for reused values without the key. Installs deployed with
`runtime/ci.sh deploy` without `--ticket` carry `ticket.enabled=false`: their API now disables the
Ticket module, as their console already did. The console shows the ticket pages when its UI image
is built with `VITE_TICKET_ENABLED=true` ([UI build flags](modules.md#ui-build-flags)). Ticket
attachments move into per-organization directories during the upgrade
([Multi-tenant installs](#multi-tenant-installs)).

### Region status takes effect

An `inactive` or `maintenance` region kept serving its functions: the route of its status block
never matched. After the upgrade, `region:reconcile` in the bootstrap Job applies the block to every
region with Knative Serving ready that is not `active` — in a multi-tenant install the
organizations' own regions too: requests to its function domain, its project custom domains and
the domains of its Knative Services answer `404 region_inactive` or `503 maintenance`; the project
pods keep running ([Region status](regions.md#region-status)). A region created with
`is_active: false` showed `active` and served; the upgrade's migrations (central and tenant
schemas) give it the status `inactive`. List the regions and their status before the upgrade and
set the ones that should keep serving active:

```sh
runr regions ls
runr regions set REGION --set status=active
```

### Request timeouts of the regions

`region:reconcile` sets the region Traefik's read and write timeouts to the longest
`request_timeout` of the regions on the cluster plus 10 seconds and raises Knative's maximum
revision timeout to it, so a `request_timeout` above 120 seconds takes effect. Traefik's pods on
each region cluster roll once during the bootstrap Job ([Request timeout](regions.md#request-timeout)).

### Single sign-on

- A Google provider with a **Hosted Domain** sends it to Google and refuses accounts of another
  domain or of none (personal accounts); before, any Google account signed in. Clear the field to
  admit every Google account.
- An address the provider marks unverified (`email_verified: false`) no longer signs in to the
  account with that e-mail address; accounts linked before keep signing in.
- *Generic OIDC* providers work (every sign-in failed). Each sign-in button names its provider, so
  several providers of one driver can be enabled; OpenID Connect users are identified per provider
  and GitLab users per self-managed instance, and accounts linked earlier keep working
  ([At sign-in](modules.md#at-sign-in)).
- `GET /api/oauth/{driver}/link` links an external account to the signed-in user
  ([Linked accounts](modules.md#linked-accounts)); a client that used it for a sign-in URL calls
  `GET /api/oauth/{driver}/redirect`.
- Multi-tenant: an SSO sign-in started on a tenant's sign-in page starts the console in that
  tenant and admits the tenant's members and platform administrators only.
  `POST /api/oauth/exchange` answers like the password sign-in, with `tenants` and `active_tenant`
  next to the token; a front end of your own that handles `/oauth/callback` starts in
  `active_tenant` ([SSO sign-in handoff](security.md#sso-sign-in-handoff)).

### Multi-tenant installs

- **Platform administrators.** An installation that switches from single-tenant to the
  multi-tenant edition gets its owners and admins as platform administrators: a Tenancy migration
  promotes them when the installation has none ([Platform administrators](tenancy.md#platform-administrators)).
- **Conversion by the bootstrap Job.** In the multi-tenant edition the bootstrap Job runs
  `php artisan tenancy:setup --force` before its administrator check (deadline 1200 seconds instead
  of 720). An installation that ran single-tenant becomes its first tenant (`Default`, the slug of
  the git remote owner); fresh, converted and multi-tenant installations are left as they are.
  Give the upgrade that switches the edition `--timeout 20m`, and read the result with
  `kubectl -n runr logs job/runr-bootstrap`. `--set bootstrap.tenancySetup=false` leaves the
  conversion to you, to see its plan first or choose the name and slug
  ([Converting a single-organization installation](tenancy.md#converting-a-single-organization-installation)).
  From the conversion day the organization's projects are metered and billed in the tenant only,
  and the tenant needs a billing webhook of its own ([Billing after the conversion](tenancy.md#billing-after-the-conversion)).
- **Ticket attachments** move from `support-attachments/<ticket id>/` to
  `support-attachments/<tenant id>/<ticket id>/` during the upgrade.
- **Regions an organization added** are marked as its own (`owner_tenant_id`): the organization
  CPU/RAM ceiling no longer applies to them, and its projects, previews and sandboxes there are
  limited by the region's capacity only.
- **Platform regions reach the organizations' copies.** Status (active, inactive, maintenance),
  migration state and the status checks' results are synced to every organization the region is
  assigned to, so their deploys respect a region in maintenance. Ending a region assignment or
  deleting a platform region removes the organization's copy and tears down its projects there.
- `tenancy:migrate --fresh` drops and recreates the tenant's schema only (it dropped the central
  tables too) and asks for confirmation in production unless `--force`.
- New organizations get the tenant migrations of every module (the Ticket module's waited for the
  next upgrade).
- **Open as administrator** works again for platform administrators, audit-logged
  ([Opening a tenant as platform administrator](tenancy.md#opening-a-tenant-as-platform-administrator)).
- **Organization domains move.** A domain changed after the upgrade keeps the functions answering
  on the previous one and on the new one until DNS of the new `<region>.<domain>` answers, or until
  **Complete now** in the Organization domain card ([Organization domain](tenancy.md#organization-domain));
  domains changed before the upgrade need nothing. An organization's own domain can no longer be
  the platform's base domain, and detaching a region from an organization keeps the platform's own
  domain entry on the region.
- **`region_mode`** (managed or infinite organization quotas) is taken from platform
  administrators only; the value anyone else sends on region create, update or setup is ignored.

### Docker Compose

Update from a checkout of the release tag ([Updating](../compose/README.md#updating)), and:

- take the new `Caddyfile`: it routes `/oauth/<provider>/callback` to the API and the console's own
  `/oauth/callback` page to the console (the API answered that page with 404, so SSO sign-ins
  failed), `/horizon` to the API, and keeps the `X-Forwarded-Proto` and
  `X-Forwarded-For` of a proxy on a private address, so behind the HTTPS proxy of
  [section 7](../compose/README.md#7-https) the API builds `https` URLs;
- new in `api.env.example`: `ADMIN_USERNAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (the migrator creates
  the first administrator while the installation has none; an installation with accounts gets no
  new one), `TRUSTED_PROXY_HOPS` (`2` behind the host proxy) and `SESSION_SECURE_COOKIE=true`
  behind HTTPS;
- `compose/build.sh` builds the UI with the module toggles of `compose/.env`: the next build shows
  the modules the API already runs (`TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`,
  and the new `BILLING_ENABLED`, default `false`);
- new in `api.env.example`: `BILLING_CURRENCY`, `BILLING_WEBHOOK_URL`, `BILLING_WEBHOOK_SECRET`
  (read with `BILLING_ENABLED=true`, [Billing](#billing)).

### Billing

0.5.7 calculated charges per tenant from usage, plans, capacity deals and purchases, in the
multi-tenant edition only. 0.5.8 replaces all of it with the [Billing module](billing.md): one bill
per project and month paid by the project's owner, region prices with effective dates, pay-as-you-go
and fixed regions, shared regions with markup, in both editions. The module is **off by default**
(`billing.enabled`, `BILLING_ENABLED`). The upgrade carries the data forward without losing any:

1. **Before the upgrade** (multi-tenant installs with charges): let the 0.5.7 `charge-schedule`
   close the last month — it runs on the 1st at 02:30. A charge still open at the upgrade is archived
   but not converted (step 4). Note the currency of your cost configuration (Settings → Billing →
   Configuration) and set **`billing.currency`** (`BILLING_CURRENCY`) to it in this upgrade, also
   when billing stays off for now: the upgrade imports the prices as plain numbers in that currency
   (step 3). Take the database backup of the [procedure](#procedure).
2. **Archive.** A Tenancy migration copies every row of `plans`, `tenant_subscriptions`,
   `billing_purchases` (credits, prepaid plan terms, add-ons), `charges`, `capacity_deals` and
   `cost_configs` into `billing_legacy_archive` — one row per record with its table, id, tenant and
   all its columns as JSON — and then drops those tables. The settings `plans_enabled`,
   `billing_store_url` and `billing_usage_thresholds` are removed. `billing_events` stays: its events
   remain readable in the new event log.
3. **Prices.** The Billing module's central migrations run at every start, whether the module is on
   or not (its tenant migrations run with `tenancy:migrate` in every tenant schema). They seed the
   platform's default prices ([default values](billing.md#price-fields)) and turn every active cost
   configuration of the archive into a price version effective from `2000-01-01`: the global
   configuration becomes the platform's default prices, a region's configuration that region's
   prices. Units are converted:

   | 0.5.7 cost configuration | 0.5.8 price |
   |---|---|
   | `cpu_price_per_mc_ms` | `cpu_vcpu_hour` = value × 3 600 000 000 |
   | `memory_price_per_mib_ms` | `memory_gb_hour` = value × 1024 × 3 600 000 |
   | `gpu_price_per_unit_ms` | `gpu_hour` = value × 3 600 000 |
   | `cpu_price_per_core_month`, `memory_price_per_gb_month` | `cpu_vcpu_month`, `memory_gb_month` |
   | `kv_price_per_gb_hour`, `object_price_per_gb_hour`, `postgres_price_per_gb_hour`, `postgres_instance_price_per_hour` | `kv_gb_hour`, `object_gb_hour`, `postgres_gb_hour`, `postgres_instance_hour` |
   | `multiplier_http`, `multiplier_queue`, `multiplier_schedule` | the same multipliers |
   | — | `gpu_month`: the default value |

   A price of `0` in 0.5.7 was a column nobody had set (the columns defaulted to 0), so it becomes
   the default value, not "free" — a missing price is never zero in 0.5.8. Review the imported
   prices in Administration → Billing → Regions & prices and set `0` again where you mean free. Each
   imported version notes the 0.5.7 currency. Prices carry no currency of their own: every number is
   read in `BILLING_CURRENCY`. When the 0.5.7 currency differs from it, the import logs a warning
   (`Billing: the 0.5.7 prices were in EUR, BILLING_CURRENCY is USD …` in the migration job's log),
   and Administration → Billing shows it on top (`warnings` of `GET /api/billing/settings`, code
   `legacy_currency_mismatch`) until `BILLING_CURRENCY` is set to that currency.
4. **Charges.** Every **closed** charge becomes part of a closed **tenant bill** (`kind: tenant`,
   `source: legacy`), one per tenant, month and currency: its line items are the charges' line items
   (each with the charge id and kind in `metadata`), a negative "Credit applied" line when credit was
   drawn, and the total is what was due. These bills stand next to the tenant's 0.5.8 bills: a
   converted charge of the upgrade month does not take the place of that month's tenant bill. `external_ref` values become the bill's
   `payment_reference`; the bills are not marked paid — mark them with
   `PUT /api/billing/tenant-bills/{bill}/paid` if your system settled them. **Void** charges and
   charges that were still **open** are not converted; they stay in the archive.
5. **Webhook.** The billing webhook URL and secret saved in Settings → Plans & Billing are copied to
   the platform's billing settings (Administration → Billing → Webhook). The Helm values
   `billing.webhookUrl` and `billing.webhookSecret` keep working.
6. **Removed values and variables.** `plans.enabled`, `billing.usageThresholds` and
   `billing.storeUrl` are no longer read (reused values that still carry them are ignored), nor are
   `PLANS_ENABLED`, `BILLING_USAGE_THRESHOLDS`, `BILLING_STORE_URL` and `BILLING_DEFAULT_CURRENCY`.
   Set **`billing.currency`** (`BILLING_CURRENCY`) to the currency your prices are in — every price
   and bill of 0.5.8 is in this one currency.
7. **Region assignments** of 0.5.7 stay active (`tenant_regions.status = active`); only regions
   assigned while billing is on wait for the tenant's acceptance (with billing off, offers are
   active). Tenants start with a default markup of 0 %
   ([Shared regions with billing](tenancy.md#shared-regions-with-billing)).
8. **Project owners.** Every project gets an owner: the user who created it (`created_by`).
   Projects created by the system (demo projects) have none until one is set with
   `PUT /api/projects/{project}/owner`; their bills have no owner ("Unassigned" in the rollup). A
   new owner must be a member of the project's organization who sees the project through their own
   role or project access — a platform administrator is not made a tenant project's payer through
   their access to every organization — and the open bill of the month goes to whoever owns the
   project when the month closes ([Who pays](billing.md#who-pays)).
9. **Metering in the core.** Usage is now recorded on single-tenant installations too, from the
   upgrade on (there is no history before it). In multi-tenant installs the tenant schemas already
   held usage records; every metering run copies the running month's usage of tenant projects in
   platform regions to the central database (during the first two days of a month also the month
   before), so the tenant bill of the upgrade month covers the whole month. Metering now counts
   each deployment once with what it ran: deployments record the size they were applied with and
   when they stopped running (`deployments.sizing`, `deployments.ended_at`, new columns in the
   central and every tenant schema), always-on workloads count the time they ran instead of 24
   hours, and a redeploy no longer counts the day's sessions again. The first runs after the
   upgrade recalculate today and yesterday; earlier days of the month keep their 0.5.7 rows (in
   tenants: 0.5.7 metered tenant projects only), where a project redeployed on a day had that day's
   sessions (or its always-on reservation) counted once per deployment. To recalculate them before
   the month's bills close, run once after the upgrade with the first day of the month (at most 93
   days at a time):

   ```bash
   kubectl -n runr exec deploy/runr-api -- php artisan usage:recalculate 2026-10-01
   ```

   Deployments applied before the upgrade have no recorded size. A recalculated day counts each of
   them with the scale mode and size of its own 0.5.7 row of that day — scale to zero: its
   sessions; always on: the reserved size and `min_scale` 0.5.7 recorded, for the time it ran
   instead of 24 hours — never with today's configuration. A deployment without a row of the day
   (it ran for less than one 5-minute 0.5.7 run, or 0.5.7 did not meter the project) gets no
   always-on reservation, only its sessions. Today and yesterday, the days the metering job
   calculates after the upgrade, such a deployment counts with its project's current configuration
   until it is redeployed. The 0.5.7 always-on row of a deployment deleted since keeps only the part
   of the day the project's other deployments in that region did not run
   ([Metering](monitoring.md#metering)).
10. **Your billing system.** The 0.5.7 billing API is gone. What it read and wrote:

    | 0.5.7 | 0.5.8 |
    |---|---|
    | `GET /api/billing/charges`, `GET /api/billing/charges/{charge}` | `GET /api/billing/bills` (project and account bills of the organization), `GET /api/billing/tenant-bills` (the platform's bills to tenants) |
    | `PATCH /api/billing/charges/{charge}` `{external_ref}` | `PUT /api/billing/bills/{bill}/paid` `{paid, paid_at, payment_reference}`, `PUT /api/billing/bills/{bill}/payment-url` |
    | `GET /api/billing/tenants/{tenant}/usage` | `GET /api/billing/usage` (per project) |
    | `billing.period.closed` (per tenant and month) | `bill.closed` (per bill) |
    | plans, subscriptions, purchases, credits, add-ons, one-off charges, void | — (RunR calculates from region prices only) |

    Tokens with `billing.view` / `billing.manage` keep working.
11. **Reading the 0.5.7 records.** With the Billing module on, platform administrators read the
    archive with `GET /api/billing/legacy-records?source=charges&tenant=<id or slug>` (`source`:
    `charges`, `plans`, `tenant_subscriptions`, `billing_purchases`, `capacity_deals`,
    `cost_configs`). Without it, the rows are in the table:

    ```sql
    SELECT source_table, row_id, tenant_id, data FROM billing_legacy_archive WHERE source_table = 'charges' ORDER BY id;
    ```

12. **Switching billing on.** `--set billing.enabled=true --set billing.currency=EUR` (Compose:
    `BILLING_ENABLED=true` in `compose/.env` and a rebuild of the images). The pre-upgrade migration
    job creates the billing tables of every tenant schema — it takes the switch from the new values,
    not from the previous release's ConfigMap — and the bootstrap Job runs `tenancy:migrate` once
    more after it. This holds for the upgrade to 0.5.8 and for any later upgrade that switches
    billing (or tickets) on. Then check the prices, set the billing
    type of each region (all are `payg` until you change them) and connect your invoicing system
    ([Turning billing on](billing.md#turning-billing-on)).

The console's Settings → Plans & Billing, Subscription, Charges and the old Billing page are gone;
with the module on, Administration → Billing, the user menu → My bills and the project's Billing
tab replace them.

### Rolling back

There is no way back from 0.5.8 with migrations: the upgrade archives and drops the 0.5.7 billing
tables, and the bills of 0.5.8 are the books. `php artisan migrate:rollback` across these
migrations stops with `RunR 0.5.8 cannot be rolled back with migrate:rollback … restore the database
backup taken before the upgrade` instead of half-undoing them. To return to 0.5.7:

1. stop everything that writes to the database:
   `kubectl -n runr scale deploy runr-api runr-horizon runr-scheduler runr-reverb --replicas=0`;
2. restore the dump of step 1 of the [procedure](#procedure), taken right before the upgrade:

   ```sh
   kubectl -n runr exec -i deploy/runr-postgres -- pg_restore -U runr -d runr --clean --if-exists < runr-<date>.dump
   ```

3. `helm rollback runr <revision of 0.5.7> -n runr`: the 0.5.7 pods start on the restored database.

With Docker Compose: `docker compose stop api horizon scheduler reverb`, restore the dump into the
`postgres` service the same way, then deploy the 0.5.7 checkout again.

Everything written after the backup — new projects, deployments, usage, bills — is lost with the
restore; roll back soon after the upgrade or not at all.

### API changes

- `GET /api/bindings/{id}/usage` and `GET /api/sandbox-usage` answer quantities only: `final_cost`,
  `raw_cost` and `currency` are gone. Metering no longer calculates money (every new record had
  `0`); what usage costs comes from the [Billing module](billing.md): `GET /api/billing/usage` and
  the bills — bindings and sandboxes are on the account bills (`GET /api/billing/bills?kind=account`).
  `runr bindings get --usage` and `runr sandbox usage` no longer show a COST column.
- Deleting a tenant keeps its project and account bills in the central `billing_archived_bills`,
  read with `GET /api/billing/archived-bills` (platform administrators); when they cannot be copied
  the tenant stays `delete_failed`, also with `?force=1`
  ([Bills of deleted tenants](billing.md#bills-of-deleted-tenants)).
- `PUT /api/knative-regions/{id}/settings` no longer takes `namespace`, which was never read.
- `PUT /api/knative-regions/{id}` with `kubeconfig` checks it like a new region's; it has to reach
  the same cluster.
- `POST /api/knative-regions/{id}/workers` takes an empty list, which releases every node.
- `GET /api/knative-regions/{id}/knative-status` and `GET …/nodes` answer the error of an
  unreachable cluster and keep the kubeconfig; their answers have no `kubeconfig_cleared`.
- The Redis mode of a region is `in_cluster`.
- `GET /api/settings` returns `assignable_regions` to platform administrators only, and
  `tenant_base_domain` and `platform_base_domain` inside an organization.

### Further changes

- Reverb logs every connection and message only with `app.debug: "true"` or `app.env: local`.
- `reverb.replicas` above 1 works: the replicas share broadcasts over Redis.
- `api.port`, `ui.port` and `reverb.port` are the ports the processes listen on (the defaults are
  unchanged).
- With `secret.create: false` and no `ADMIN_PASSWORD` in the Secret, a new install's generated
  administrator password goes to the Secret `runr-admin` instead of the bootstrap Job's log.
- `values.schema.json` validates `images.*` and `supplyChain.*`, the keys the templates read.
- The chart requires Kubernetes 1.23 or later (`kubeVersion`).
- `database.driver` other than `pgsql` stops the upgrade with a message: RunR's pods share one
  PostgreSQL server.
- New, off by default: `networkPolicy.enabled`, `ingress.tls.mode: secret`, `gateway.affinity` /
  `api.affinity` / `ui.affinity`, `database.postgres.storageClassName`.
- A changed base domain migrates the regions that follow it: both domains are served until DNS of
  the new one answers or the migration is completed by hand
  ([Changing the base domain](regions.md#changing-the-base-domain)).
- Region installs after the upgrade use the pinned Prometheus (kube-prometheus-stack 92.10.1) and
  Loki (loki-stack 2.10.3) charts and, without the chart repository, the newest pinned Knative
  version the cluster supports; installed regions keep their versions.
- A removed tracing backend stays removed: the removal sets the region's tracing mode to `off`.

## 0.5.1 → 0.5.2

### Tracing backend

`region:reconcile` (step `tracing`) brings the tracing backend of every region to this release
where tracing is enabled, and installs nothing anywhere else:

| Region | The step |
| --- | --- |
| Tracing mode `managed` (its own setting) | installs or updates the OpenTelemetry Collector and Grafana Tempo |
| Tracing mode `reuse` or `external` | installs or updates the collector |
| Tracing mode `off` | does nothing |
| No tracing setting of its own — Knative Serving installed by 0.4.x, or with `installWithServing: false` | does what an install of Knative Serving does now: the platform's `observability.tracing.defaultMode` (default `managed`) while `observability.tracing.installWithServing` is `true` (the default); nothing otherwise |

It installs a missing backend and updates one whose chart version or image differs from this
release's: chart `open-telemetry/opentelemetry-collector` 0.176.0 with
`ghcr.io/brf-tech/reqs/opentelemetry-collector-contrib:0.162.0`, chart `grafana-community/tempo`
3.2.0 with `ghcr.io/brf-tech/reqs/tempo:3.1.0` — RunR's mirrors of the Docker Hub images 0.5.0
and 0.5.1 pulled directly (`otel/opentelemetry-collector-contrib`, `grafana/tempo`), so regions
no longer depend on Docker Hub's anonymous pull limit for them. It runs what
`php artisan cluster:install-tracing` runs: a Helm upgrade (or install) of the `otel-collector`
and `tempo` releases with their values rendered anew, the span-metrics PodMonitor and Knative's
tracing configuration. The collector and Tempo pods roll once — spans sent while the collector
restarts are dropped, Tempo keeps its traces on its volume — and the region's tracing status is
`installing` until they run. Regions on one cluster share the backend: the first region of the
cluster with tracing enabled decides it.

Tempo chart 3.2.0 applies the retention (`observability.tracing.tempoRetentionHours`, default
72 hours, or the region's own) where Tempo deletes old traces; with chart 3.1.0 the setting did
not reach that part of Tempo, which kept traces for its default of 14 days (the chart's upgrade
notes). After the update traces older than the retention are removed: raise it before the
upgrade to keep them longer.

Before the upgrade:

- `php artisan region:reconcile --dry-run` lists what the step would install or update.
- To keep tracing off on regions without their own setting, set
  `observability.tracing.installWithServing=false` (or `defaultMode=off`) in the upgrade; for one
  region, set its tracing mode to `off` in the console.
- Regions that pull from a registry of your own: mirror the two images there and set
  `observability.tracing.collectorImage` / `tempoImage` (the same versions).

`observability.tracing.collectorChartVersion` and `tempoChartVersion` are empty by default (the
versions above). The values the charts of 0.5.0 and 0.5.1 set, `0.175.1` and `3.1.0`, which
`--reuse-values` keeps, count as empty; set another version to pin one.

### Image namespace ghcr.io/brf-tech/runr

RunR's images are published under `ghcr.io/brf-tech/runr`, so the organization's other packages
never share a name with RunR's. The tags and digests are the ones 0.5.1 published directly under
`ghcr.io/brf-tech` (and releases up to 0.5.0 under `ghcr.io/runrsh`); the `runr-` prefix of the
component images is gone:

| Up to 0.5.0 | 0.5.1 | 0.5.2 |
| --- | --- | --- |
| `ghcr.io/runrsh/reqs/<image>` | `ghcr.io/brf-tech/reqs/<image>` | `ghcr.io/brf-tech/runr/reqs/<image>` |
| `ghcr.io/runrsh/exec-<runtime>` | `ghcr.io/brf-tech/exec-<runtime>` | `ghcr.io/brf-tech/runr/exec-<runtime>` |
| `ghcr.io/runrsh/runr-wrapper` | `ghcr.io/brf-tech/runr-wrapper` | `ghcr.io/brf-tech/runr/wrapper` |
| `ghcr.io/runrsh/runr-agent` | `ghcr.io/brf-tech/runr-agent` | `ghcr.io/brf-tech/runr/agent` |
| `ghcr.io/runrsh/runr-queue-consumer` | `ghcr.io/brf-tech/runr-queue-consumer` | `ghcr.io/brf-tech/runr/queue-consumer` |
| `ghcr.io/runrsh/runr-supply-chain` | `ghcr.io/brf-tech/runr-supply-chain` | `ghcr.io/brf-tech/runr/supply-chain` |
| `ghcr.io/runrsh/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/runr/hf-serve`, `image-upload`, `containerd-shim-spin` |
| `ghcr.io/runrsh/runr/api`, `runr/ui` | `ghcr.io/brf-tech/runr/api`, `runr/ui` | the same as 0.5.1 |

The upgrade moves every reference an existing installation holds, from 0.5.1 and from earlier
releases alike, the way the [0.5.1 move](#image-namespace-ghcriobrf-tech) did: Helm values
(template helper `runr.image`), the database (migration `move_images_to_runr_namespace` and tenant
migration `0055`, then `php artisan images:move-registry` in the bootstrap job), the region
workloads, the region Traefik and the WebAssembly installer (`region:reconcile`: `image-registry`,
`traefik-image`, `wasm-sandbox`). Only RunR's own packages move: another package directly under
`ghcr.io/brf-tech`, a mirror of your own and the region registry's `<registry>/ghcr.io/…` path
stay as they are. The system images policy passes `ghcr.io/brf-tech/runr/**` (`region:reconcile`,
`admission-policy`, before the workloads move) and keeps passing `ghcr.io/brf-tech/**` and
`ghcr.io/runrsh/**` for the pods that keep running with an image of the earlier names.

Two kinds of region workloads move their pod template but keep their running pods, because a
restart costs more than the move; a pod created later — on a new node, or after the pod was deleted
or evicted — starts from `ghcr.io/brf-tech/runr`:

| Workload | Why its pods stay | How it moves |
| --- | --- | --- |
| Node installers of Kata (`kata-installer`, Firecracker's `kata-devmapper`) and gVisor (`gvisor-installer`) in `runr-sandbox` | A new pod re-runs the install on its node; kata-deploy's cleanup and reinstall restart the container runtime | One patch: the new images and the update strategy `OnDelete`, so the DaemonSet controller replaces no pod. A gVisor install, a Kata install that re-runs kata-deploy and Kata's Firecracker setup apply `RollingUpdate` again; Kata's drop-in update keeps `OnDelete` |
| The region's queue Redis (`runr-redis`) | Its streams, consumer groups and the KV bindings' data live in memory only: a new pod starts empty | The Deployment is paused, its ReplicaSet gets the new image (the running pod stays), then the Deployment gets it and is resumed in one patch: the Deployment controller keeps that ReplicaSet and starts no pod. A run that stops half-way leaves it paused (annotation `runr.dev/image-move-paused`); the next run finishes the move |

`region:reconcile` reports them as `moved`, with what keeps running; such a pod runs its image from
the earlier name until it is replaced. A Redis Deployment paused by someone else, or in the middle
of a rollout, is listed as `kept:` and moves at the first run after it is resumed or the rollout is
over.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

A registry of your own that holds copies of RunR's images under their paths (an air-gapped
registry, the CLI's `RUNR_RUNTIME_REGISTRY`) needs them under the new paths: copy them from
`ghcr.io/brf-tech/runr`. Image settings that point into such a registry stay as they are.

## 0.5.0 → 0.5.1

### Image namespace ghcr.io/brf-tech

0.5.2 moves these references on to `ghcr.io/brf-tech/runr`
([Image namespace ghcr.io/brf-tech/runr](#image-namespace-ghcriobrf-techrunr)); an upgrade from
0.5.0 straight to 0.5.2 moves them there in one step.

RunR's images are published under `ghcr.io/brf-tech` — the same image names, tags and digests
that releases up to 0.5.0 published under `ghcr.io/runrsh`. The upgrade moves every reference an
existing installation holds (also one that comes from 0.4.x); only the exact prefix `ghcr.io/runrsh/`
moves, references into other registries — a mirror of your own, the region registry's
`<registry>/ghcr.io/…` path — stay as they are:

| Where | What moves | How |
| --- | --- | --- |
| Helm values | Image values under `ghcr.io/runrsh/` — set at install, or the previous chart's defaults that `--reuse-values` keeps — render as the same image under `ghcr.io/brf-tech/`: API, UI, gateway, PostgreSQL, Redis, Mailpit, init containers, `ai.images`, `bindings.object.image`, `supplyChain.toolsImage`, `images.uploadImage`, `knative.traefikImage` | template helper `runr.image` |
| Database | Runtime versions; runtime plugins (manifest, `runtime.yaml` as submitted and uploaded Dockerfiles — their region builds stay valid); CaaS images and version snapshots; region, binding and supply-chain settings; model services; sandboxes; security reports — the central schema and every tenant schema | migration `move_images_to_brf_tech_registry` and tenant migration `0054`; the bootstrap job runs `php artisan images:move-registry` once more for rows the previous version's pods wrote during the rollout |
| Region workloads | Every workload RunR deployed: the region agent, queue consumers, the registry and its node mirror, Loki, Prometheus, binding data services, image pre-pull, CronJobs and the Knative Services of functions, CaaS projects, model services and hosted MCP servers (the wrapper init container and runtime images included) | `region:reconcile` (`image-registry`): one patch per workload; a Knative Service gets a new revision that takes the traffic once it is ready, the previous revision serves until then; the other workloads roll once, apart from the sandbox node installers and the queue Redis, which keep their running pods (below) |
| Region Traefik | `ghcr.io/runrsh/reqs/traefik` of any version | `region:reconcile` (`traefik-image`): Helm upgrade of the `traefik` release with the same version |
| WebAssembly | The shim image of the `wasm-installer` | `region:reconcile` (`wasm-sandbox`) re-runs the installer: the same binaries, no container runtime restart |
| Admission policy | The system images policy passes `ghcr.io/brf-tech/**` | `region:reconcile` (`admission-policy`), before the workloads move |

The node installers of Kata (with its devmapper) and gVisor — a new pod re-runs the install on its
node, and kata-deploy's cleanup restarts the container runtime — and the region's queue Redis
(`runr-redis`), whose streams live in memory, move their pod template but keep their running pods
(`region:reconcile` does not count the image namespace alone as a Kata change); how is described
under [Image namespace ghcr.io/brf-tech/runr](#image-namespace-ghcriobrf-techrunr). The system
images policy keeps passing `ghcr.io/runrsh` for those pods.

In the console's namespace the chart's PostgreSQL, Redis, gateway and Mailpit roll once with their
moved images, together with the new version's API and UI: PostgreSQL is unavailable for a few
seconds (an API pod that starts meanwhile restarts until it is back) and Redis starts empty — the
usual rule of the rollout window applies, do not deploy or edit projects until it is over.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

Outside the installation:

- **CLI**: `runr` downloads and update checks use the `brf-tech/runr` releases; with Go:
  `go install github.com/brf-tech/runr/cli@latest`.
- **GitHub Action**: `uses: brf-tech/runr/actions/deploy@<tag>`.
- **Terraform**: the provider's address is `brf-tech/runr`. Change `source` in
  `required_providers` and move the state once:
  `terraform state replace-provider registry.terraform.io/runrsh/runr registry.terraform.io/brf-tech/runr`.

## 0.4.x → 0.5.0

### Roles and API tokens

0.4.x had no permission checks for single-tenant users. The upgrade gives every existing user a
role (migration `assign_roles_to_existing_users`):

| 0.4.x | 0.5.0 |
| --- | --- |
| Single-tenant: the administrator account (`ADMIN_USERNAME`, else the oldest user) | Owner |
| Single-tenant: every other user | Admin |
| Multi-tenant: tenant `owner` / `admin` | Owner / Admin |
| Multi-tenant: tenant `member` | the custom role Member (what members could do) |
| Multi-tenant: tenant `viewer` | Viewer (read-only) |

Two things 0.4.x stored but did not enforce are enforced now:

- **Tenant viewers** could change and deploy projects in 0.4.x although the role was named
  Viewer. 0.5.0 keeps them read-only. Give a viewer who needs to work on projects the Member or
  Developer role.
- **API token scopes.** Tokens with `*` keep full access (within their user's role). Tokens with
  0.4.x scopes get those scopes as permissions (`projects:read` → `projects.view`, `logs.view`,
  `metrics.view`, `git.pull`, …; the full list is in [permissions.md](permissions.md#upgrade-from-04x)),
  so a token created with `projects:read` reads but no longer deploys. A token created without
  any scope has no permissions; create a new one with the scopes it needs.

Users the previous version created while the upgrade rolled out get the default role from the
bootstrap job (`php artisan rbac:assign-missing`, safe to run again).

### Secrets at rest

The migration `encrypt_secret_columns` encrypts env vars, version snapshots, region kubeconfigs,
registry and Redis passwords, webhook secrets and the other columns listed in
[security.md](security.md#encryption-at-rest) with `APP_KEY`. Values still in plaintext stay
readable; `kubectl -n runr exec deploy/runr-api -- php artisan secrets:encrypt` encrypts anything
written in plaintext later (it prints `Encrypted 0 plaintext value(s)` when there is nothing
left). On large installs the migration job's 300-second deadline applies to the whole migration
run.

Copies outside the database are not rewritten: the git history of each project on the API
volume (commits of 0.4.x contain `.config.json` with the env values), the code ConfigMaps of
deployed versions and the env of running Knative Services keep their plain values until those
versions are deployed again.

### Secret env vars

0.4.x had plain env vars only; they stay plain after the upgrade and keep their values. To turn
one into a secret, move it in one request and deploy:

```sh
curl -X PUT https://runr.example.com/api/projects/<id> -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"config":{"env_vars":{"PLAIN_VAR":"…"},"secret_env_vars":{"API_TOKEN":"…"}}}'
```

The deploy writes the Kubernetes Secret `<project-slug>-secret-env` in the region and the
Knative Service reads the variable from it (`secretKeyRef`); the value no longer appears in the
Service, the version or the API's project resource ([security.md](security.md#secret-environment-variables)).

### White-label

`branding.*` values apply at the upgrade that sets them (no rebuild): product and company name,
logos (URLs, `data:` URIs or ConfigMap / Secret references), colours (one base colour or the full
`300`–`900` scale) and the login texts — [white-label.md](white-label.md). `GET /api/branding`
shows the result.

### Billing (multi-tenant)

This billing was replaced again in 0.5.8 ([0.5.7 → 0.5.8: Billing](#billing)).

0.5.0 calculates what each tenant owes and leaves invoicing to your billing system, which reads
the amounts through the [billing API](billing.md) and receives signed billing events. The upgrade
migration `turn_payment_records_into_charges` turns the 0.4.x payment records into **charges**:

| 0.4.x payment record | 0.5.0 charge |
| --- | --- |
| `invoice_id` (the billing service's invoice) | `external_ref` |
| capacity-deal record / `Plan …` / `Usage …` record / other | `kind` `capacity_deal` / `plan` / `usage` / `manual` |
| `cancelled` | `void` |
| any other status, period over | `closed`: `total` and `amount_due` = the invoiced total, else the record amount |
| any other status, period running | `open` |

The payment fields (Pay link, due date, paid and refunded amounts) are not carried over — your
billing system holds them. The 0.4.x billing service integration is replaced as follows:

| 0.4.x | 0.5.0 |
| --- | --- |
| RunR created invoices at `billing.gatewayUrl` (`POST /billing/invoices`), polled their status and requested refunds | your system reads closed charges (`GET /api/billing/charges?status=closed`, or the `billing.period.closed` event) and invoices them; refunds happen in your system |
| `billing.gatewayUrl`, `billing.gatewayToken` | not used any more; create an API token with `billing.view` or `billing.manage` for your system ([billing.md](billing.md#api-tokens)) |
| `billing.webhookSecret` signed payment-status calls to `POST /api/billing/webhook` | `billing.webhookSecret` signs the billing events RunR sends to `billing.webhookUrl` — same header format (`X-RunR-Signature`, `X-RunR-Timestamp`) |
| `GET/POST/PATCH /api/tenants/{tenant}/payments` | `GET /api/billing/charges?tenant=…`, `POST /api/billing/tenants/{tenant}/charges`, `PATCH /api/billing/charges/{charge}` |
| `GET /api/tenant/payments` (the tenant's invoices) | `GET /api/tenant/billing` (the tenant's charges, credit and purchases) |

Set `billing.webhookUrl` (and keep or set `billing.webhookSecret`) to receive events; without a
URL the events are kept for `GET /api/billing/events`.

### Region Traefik image

The region Traefik runs `ghcr.io/brf-tech/reqs/traefik:v3.7.4`, RunR's mirror of the
`docker.io/traefik` image its pinned chart runs, so its replicas — and a node that schedules one
later — no longer pull from Docker Hub and its anonymous pull limit. `region:reconcile` moves the
Traefik of existing regions that runs the same Traefik version from Docker Hub (a Helm upgrade
of the `traefik` release with its values reused and only the image set; the Traefik pods roll
once, one at a time) and leaves a Traefik of another version alone. To pull it from a registry
of your own, set `knative.traefikImage` (the same Traefik version) before the upgrade.

### Kata Containers

Of RunR's Kata drop-ins only Dragonball's changed: on regions whose Kata install includes
Dragonball, `region:reconcile` writes the new Dragonball drop-in to the nodes (no kata-deploy run,
no restart); regions with the other hypervisors only keep theirs (from 0.4.1, whose guests still
boot systemd, every drop-in is written once). Dragonball guests get 1 vCPU + the pod's CPU limits
from 0.5.0 on ([kata-hypervisors.md](kata-hypervisors.md#guest-size)); a Dragonball function gets
them at its next deploy.

## Testing the upgrade path

`runtime/ci.sh test-helm-upgrade` repeats the upgrade on a Kind cluster (`runtime/ci.sh
test-upgrade` does the same for a Docker Compose install): it installs the chart and images of
`main` (`--from=<ref>`; the API and UI images of that ref's chart values at its `appVersion`,
built from that ref when they are not public) with the chart defaults, creates data through the old API —
the administrator and a second user, the same cluster as a region (registry, Knative Serving,
Traefik, the region agent), a Python function with two env vars, a full-access and a
`projects:read` token — and runs `helm upgrade --reuse-values` (`--values-mode=reset`:
`--reset-then-reuse-values`) to the checkout's chart and images with a test brand
(`Acme Cloud`, logos as `data:` URIs, a primary `300`–`900` scale) while a prober calls the
function every second. It then checks:

- the pre-upgrade migration succeeded, no migration is pending, `/api/version` answers (it reports
  the chart's `appVersion`) and every deployment runs the new images;
- both users sign in; the administrator is Owner, the other user Admin and still lists projects,
  regions and users;
- env vars are encrypted in the database (`eyJpdiI6…`), the plaintext value is in no row, the
  region kubeconfig is encrypted, the API returns the values, `secrets:encrypt` finds nothing;
- the `*` token works; the `projects:read` token reads projects, gets env values hidden and a 403
  on deploy; its stored scopes are translated;
- the function answered every probe during the upgrade and answers afterwards with its env;
- a variable moved to secret plus a new secret land in `<slug>-secret-env`, the Knative Service
  references them and contains no value, the function reads them after the redeploy;
- the region reports Knative Serving ready and its agent reconnected to the new Reverb;
- `/api/branding` returns the brand (names, palette, logos) and serves the logo from its
  `data:` URI;
- nothing of `ghcr.io/runrsh` is left (the image namespace up to 0.5.0): no workload of the
  cluster — control plane and region, Deployments, StatefulSets, DaemonSets, CronJobs, Knative
  Services — references it, no running pod apart from the ones `region:reconcile` keeps running on
  purpose (the sandbox node installers and the queue Redis), and no database row of the central and
  the tenant schemas (a CaaS project and, multi-tenant, a tenant-schema row of a `ghcr.io/runrsh`
  image are created before the upgrade); the region agent runs `ghcr.io/brf-tech/runr/agent`.

`--edition=multi-tenant` installs `edition: multi-tenant` (UI images with the `-mt` suffix) and,
instead of the region and the function, creates a tenant that the platform administrator owns with
a tenant admin, member and viewer. After the upgrade every one of them signs in to the tenant,
`GET /api/members` shows owner / admin / member / viewer, the tenant has the custom role Member and
the member's permissions include `settings.manage`, `sso.manage` and `projects.deploy`, the viewer
lists projects but gets a 403 on `POST /api/projects`, and `secrets:encrypt` finds no plaintext in
the central or the tenant schema.

`--rollback` (single-tenant) goes back once every check of the upgrade passed
([Back to 0.6.7](#back-to-067)). On the upgraded version it first deploys a project on a runtime only
the checkout's catalog has: `UPG_ROLLBACK_RUNTIME`, by default `java`, through `POST
/api/runtimes/<id>/test-deploy`, offered through the Built-in runtimes setting when
`E2E_EXECUTOR_RUNTIMES` leaves it out. Then it runs `helm rollback` to the installed version's
revision while the prober calls the function, waits for that revision's region Job, and checks:

- the API answers with the installed version, on its images;
- the upgrade's migrations stay recorded, with `runtime_sources` and its columns, and none is pending;
- the region Job completed after its wait for the bootstrap hook, and took the region agent back;
- the region Traefik stays on its chart, with the `warning:` of `traefik-chart`;
- both users sign in, and the function answered every probe and answers and redeploys with its env;
- the runtimes list and the picker answer, without the checkout's new runtimes;
- the new runtime's project answers, is not listed (404), builds and deploys a new version, and the
  API still creates a project on that runtime.

Measured on a 20-core host with Kind 1.32 (main = 0.4.4 → this release, `--reuse-values`): the
upgrade's migration job took 9 s, the new pods were ready 27 s after it started, the bootstrap
job (agent check, `region:reconcile` with every step up to date, `rbac:assign-missing`) 6 s; the
prober's 38 requests during the upgrade all answered 200.
