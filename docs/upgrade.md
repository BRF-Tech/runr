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
   slow one. Each attempt lets the command start steps for an hour
   (`bootstrap.regionReconcileSeconds`, 3600 — the worst case of one cluster, every wait of every
   step at its limit, is about 57 minutes) and lets the step running then end, so no change is cut
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

Measured on a 20-core host with Kind 1.32 (main = 0.4.4 → this release, `--reuse-values`): the
upgrade's migration job took 9 s, the new pods were ready 27 s after it started, the bootstrap
job (agent check, `region:reconcile` with every step up to date, `rbac:assign-missing`) 6 s; the
prober's 38 requests during the upgrade all answered 200.
