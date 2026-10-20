# Changelog

All notable changes to RunR are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Version numbers are the Helm chart versions; a release whose application images
did not change says so.

## [Unreleased]

## [0.5.9] - 2026-10-20

### Upgrade notes

- **The chart's API and UI images follow the chart version.** An upgrade from 0.5.8 with
  `--reuse-values` keeps the image tags stored in the release — `latest`, the default of the charts
  up to 0.5.8, or the version an install set with `--set api.image.tag=…` — and with them the old
  images. Add `--set api.image.tag= --set ui.image.tag=` to that upgrade once, so both tags follow
  the chart from then on, or set them explicitly on every upgrade
  ([Procedure](docs/upgrade.md#procedure)).

### Added

- **e2e: uptime checks from a region** (`runtime/ci.sh test-observability`, `obs-uptime-region`).
  An uptime check with location "regions" is fetched and run by the region's agent, which calls
  RunR at the API container's address on the Kind network; its results must arrive with the
  region's name as location and the agent's log must have no `RunR answered 400` — the 0.5.7
  refusal of the agent's host that 0.5.8 fixed.
- **Queued deploys in the API.** A deploy of a version that is built again for its region's
  registry answers `202` with `message` and `queued_deploy` instead of a deployment;
  `GET /api/projects/{id}/queued-deploys/{queued_deploy}` (`projects.view`) follows it to
  `deployed` with its `deployment_id`, or to `failed` / `cancelled` with the reason. The MCP
  `deploy` and `rollback` tools follow it until the deployment starts
  ([Older versions](docs/regions.md#older-versions)).
- **`runr deploy` and `runr rollback` follow a queued deploy** — the build, then the deployment it
  starts — within `--timeout` (`--no-wait`: `queued_deploy` in `-o json`, `queued_deploy_id=` and
  `status=building` in `--output-env`); the Go client's `Deploy` does so too, and `StartDeploy`
  returns either ([cli.md](docs/cli.md#deploying)).
- **`runr regions migration status` lists what the move did with each project** — project,
  organization, outcome, version, deployment status and message — while the move can be rolled
  back (`projects` in `-o json`; [cli.md](docs/cli.md#moving-a-region-to-another-cluster)).

### Changed

- **Helm: `api.image.tag` and `ui.image.tag` default to the chart's `appVersion`** instead of
  `latest`. `helm install --version X` runs the images of RunR X and `helm rollback` rolls them back
  with the chart; a tag that is set, `latest` included, is used as it is. The multi-tenant edition
  runs `<appVersion>-mt` by default, and for the tag `latest`, which has no multi-tenant variant, as
  before. The install and upgrade instructions no longer pin the image tags next to `--version`.
  `runtime/ci.sh deploy` sets the tag `latest` of the images it builds explicitly.

### Fixed

- **A refused deploy of an older version during a region move left the project running on the old
  cluster.** While the move's deploy of a project waits for its build on the new cluster, a deploy
  of an older version replaces it. When that deploy's build failed or a deploy check refused it,
  nothing shut down the project's deployment on the cluster the region left: it stayed live and
  metered, and `migration-status` showed the project as `cancelled`. Such a deploy now takes the
  move's place (`queued_deploys.replaces_reason`, in the tenant schemas too): when it fails, that
  deployment is shut down with "Not moved to the new cluster of the region: …" and the reason, as
  for a project the move refused, and the report shows `not_moved` with the reason; when it
  starts, the report shows `deployed` with its version
  ([Older versions](docs/regions.md#older-versions)).
- **Helm: the agent of RunR's own cluster got 400 after each token rotation.** About every 48
  minutes Kubernetes renews the API pod's service account volume with a new token: it points the
  volume at a new directory and deletes the old one. PHP's realpath cache kept resolving the
  volume's files into the deleted directory for up to two minutes, so the API lost its namespace,
  the in-cluster gateway (`runr-gateway.<namespace>.svc.cluster.local`) dropped out of the hosts the
  region callbacks accept, and the agent's fetch of its uptime checks got 400 once or twice
  (`uptime: fetching checks: RunR answered 400`). RunR's own token failed to read in that window
  too, and a check whether a region is RunR's own cluster made then answered "no" for five minutes.
  A failed read of the service account volume is now retried with that cache cleared. The in-cluster
  gateway and the configured addresses never depend on the database or the cache, and the lookup of
  the regions' `agent_ws_url` and `app_url` keeps no empty or failed result. Upgrading the API is
  enough ([regions](docs/regions.md#network-reachability)).
- **A git deploy replaced before it went live stayed "pending" on the provider.** Such a deploy
  ends as skipped, but no commit status followed. It now ends the commit status "runr" as
  "Replaced by a newer deploy" ("Stopped before it went live" when the project was stopped):
  `canceled` on GitLab, `warning` on Forgejo and Gitea, `success` on GitHub, whose commit statuses
  have no canceled state (`error` would mark a commit as broken that nothing failed in, and a late
  report could paint a live commit of the same SHA red); the GitHub deployment it started becomes
  `inactive` ([Status on the provider](docs/git-deploy.md#status-on-the-provider)).
- **A region migration rollback left projects serving without metering.** A project the move did
  not move (its redeploy refused, its deployments shut down) still had its Knative Service on the
  old cluster, which the rollback made the region's cluster again: it served with no live
  deployment. The rollback now deploys such a project again with the version and overrides it ran
  with, metered from when it is ready; when the deploy checks still refuse it, or the project was
  stopped while the region was on the new cluster, its workloads are removed from the restored
  cluster. A project whose redeploy the rollback itself refuses is removed from there too, its
  deployments shut down with "Not deployed again on the cluster the migration rollback restored"
  ([Moving a region to another cluster](docs/regions.md#moving-a-region-to-another-cluster)).
- **A region migration rollback kept the new cluster's registry address.** The move points the
  region at the Zot it installs on the new cluster; the rollback gave back only the kubeconfig and
  the external address, so every redeploy and build of the region's projects on the restored
  cluster used the new cluster's registry. A move now keeps what the region held for its old
  cluster (`knative_regions.migration_old_state`, in the tenant schemas too) and the rollback
  restores it before the projects are deployed again: the registry address, the dedicated workers
  and capacity, the runr-agent's token (the old cluster's agent was refused after a rollback), and
  the install state of Knative, Redis, the sandbox runtimes, WebAssembly, GPU support and function
  TLS. The copies of a platform region in its organizations get the restored values. A move
  started before the upgrade has no kept state: its rollback restores the kubeconfig and the
  external address only
  ([Moving a region to another cluster](docs/regions.md#moving-a-region-to-another-cluster)).
- **Moving a platform region left the organizations' projects on it behind.** In a multi-tenant
  install the move and its rollback deployed only the projects of the platform's own schema; the
  projects the organizations run on the region, in their own schemas, kept their old deployments
  and were deployed on neither cluster. Both now deploy them too, each organization's in its own
  context through its copy of the region, with the same stop and admission checks; the rollback
  also deploys again or removes an organization's project the move did not move. An
  organization's own region still moves inside that organization
  ([Moving a region to another cluster](docs/regions.md#moving-a-region-to-another-cluster)).
- **A region migration installed Kata Containers with Cloud Hypervisor only.** A region on
  QEMU, Dragonball or Firecracker got `clh` on the new cluster. The move now installs the region's
  hypervisors. A Kata install that fails on the new cluster leaves Kata in `error` there instead
  of `ready` with the old cluster's hypervisors, and no longer keeps gVisor from being installed.
- **A region migration redeployed built versions the new cluster cannot pull.** A version built
  for the region (dependencies, a compile step, WebAssembly) has its image in the old cluster's
  registry, which the new cluster's nodes are not set up for, and the move deployed it there all
  the same. When the move gives the region another registry, such a version is now built again
  on the new cluster and deployed there once the build is done, through the deploy checks of
  every deploy; until then the project keeps running on the old cluster, and a build or deploy that
  fails leaves it not moved, with the reason as its deployment's message. A version without a build
  of its own on a runtime image RunR built in the region waits for that image to be built again
  there; a build running when the region switches is submitted again on the new cluster. The new
  deploy-after-build step (`queued_deploys`, in the tenant schemas too) deploys a version once its
  build, its runtime image and its supply-chain checks are ready, and is replaced by any newer
  deploy of the project. `GET /api/knative-regions/{id}/migration-status` now lists what the move
  did with each project (`projects`: moved, building, deployed, not moved — with the reason —, or
  cancelled), and the console's Migration tab shows it
  ([Images the new cluster cannot pull](docs/regions.md#images-the-new-cluster-cannot-pull)).
- **Uploaded images of a moved region were offered and deployed as if they were there.** An upload
  is in the registry of the cluster it was pushed to only, and RunR keeps no copy of it, so a move
  cannot bring it along. Each uploaded image now records that registry (`registry_address`; the
  upgrade fills it in): one the region left shows `in_registry: false`, a CaaS project on it is not
  moved (and, after a rollback, one switched to an image uploaded on the new cluster is not deployed
  again on the old one), with a message that says to upload the image to the region again;
  choosing such an image for a CaaS project, a runtime version or a wasm-exec function finds it
  not uploaded in the region. Uploading the same name again puts it into the region's registry
  ([Moving a region to another cluster](docs/images.md#moving-a-region-to-another-cluster)).
- **Builds running on the new cluster at a migration rollback ended as failed builds.** Their
  check looked for the Kaniko Job on the restored cluster and failed them with "Build Job not
  found", announced as a build failure. They now end with the new build status `cancelled` and
  "Cancelled by the migration rollback: …", without a failure notification; a git push waiting for
  one ends as skipped, and the Job is deleted from the cluster the region left. A version the move
  built again gets the image back that the old cluster's registry has, and the runtime image
  builds come back as they were
  ([Completing and rolling back the move](docs/regions.md#completing-and-rolling-back-the-move)).
- **A region migration set Firecracker's thin-pool up with the default 20 GiB.** The size chosen
  at the Kata install was not stored, so the move reinstalled `fc` on the new cluster with the
  default, and adding hypervisors to a working install sent the default along too. The region now
  records the size its nodes were set up with (`sandbox_kata_devmapper_pool_gb`, in the tenant
  schemas too) and every later install uses it: adding hypervisors, `region:reconcile`, the move
  (for a pool set up before this release, the size in the old cluster's `kata-devmapper`
  DaemonSet), and the rollback restores it with the region's other state. Uninstalling Kata clears
  it ([Firecracker and devmapper](docs/kata-hypervisors.md#firecracker-and-devmapper)).
- **A migration rollback deployed versions the old cluster cannot pull.** A version built while
  the region was on the new cluster has its image in the new cluster's registry only; the rollback
  deployed it on the old cluster, where its pods could not start. The rollback now deploys the
  project's last deployment whose version is in the restored registry again (usually what ran
  before the move), with its overrides, and shuts the deployments of the other version down with
  "… was built into the registry of the cluster the rollback left; vN runs instead". A project
  with no such deployment is removed from the restored cluster.
- **A failed region migration started again lost the old cluster.** A move in `failed` could be
  started again without a rollback, and the new attempt saved the half-moved region — the new
  cluster's kubeconfig included — as the state to roll back to. A move started again now keeps
  what its first attempt saved.
- **A migration rollback kept the new cluster's status for data services, the admission policy
  and tracing.** The move now also keeps the status of the region's data services, admission
  policy and tracing backend, and the rollback restores them; a data service or tracing backend
  installed on the new cluster only is not installed after the rollback.
- **Two deploys created at the same instant could both stay active.** When the older one went
  live before the newer one was created, nothing replaced it (metering already counted it only up
  to the newer one's start). A deployment now becomes active under its project's row lock, only
  while no newer deployment of the project is live, and its activation replaces every older live
  deployment of the project in the same transaction: the readiness check, a CronJob project and
  a demo region alike. At most one deployment of a project is active.
- **Older versions of a moved region failed at the image pull.** A region migration builds again
  only the version each project runs; a version built before the move still names the old
  cluster's registry, so deploying it later — a deploy or rollback in the console, the CLI or the
  MCP tools, `POST /api/projects/{id}/deploy`, a git push or **Deploy again** whose tree equals
  it, the resume of a project stopped before the move — started pods that could not pull their
  image. Such a version is now built again into the region's registry first and deployed when the
  build is ready, through the deploy checks of every deploy, with "vN is built again for this
  region's registry: … It deploys when the build is ready." (the deploy answers with a queued
  deploy, a git deploy waits in `building`, a resume answers `redeploy_code: version_rebuilding`).
  A deploy the checks refuse once the version is built notifies the user who asked for it; a
  migration rollback gives a version built again this way its old image back
  ([Older versions](docs/regions.md#older-versions)).
- **A region migration left the preview environments on the old cluster.** The previews of
  pull/merge requests kept running on the cluster the region left, and their next deploy went to
  the new cluster with images it cannot pull. The move now deploys every live preview again on the
  new cluster through the preview path (as **Redeploy** does), building a preview version again
  first when its image is in the old cluster's registry or its build ran there, and marks a preview
  whose build had failed as failed with the reason; a rollback does the same on the old cluster
  and deletes the preview builds still running on the new one. A preview on a runtime image RunR
  builds in the region now waits for that image instead of failing
  ([Preview environments](docs/regions.md#preview-environments)).
- **A migration rollback did not cancel a queued deploy that was starting.** A deploy waiting for
  its build that a worker had just claimed (status `starting`) was left alone, and could create a
  deployment on the cluster the rollback left. The worker now claims the deployment it creates
  first: the rollback cancels every deploy queued since the move that has not created its
  deployment yet — the worker then starts nothing — and one that has is replaced by the rollback's
  redeploy like any other deployment
  ([Completing and rolling back the move](docs/regions.md#completing-and-rolling-back-the-move)).
- **A build submitted again by a region migration could be failed by the old build's checks.** The
  checks of the build that ran on the old cluster kept polling the version and failed it with
  "Build timeout" at the old build's deadline, while the build submitted on the new cluster still
  ran. The checks of a build (a version's and a preview's) now follow their own submission
  (`versions.build_token`) and stop when the version is submitted again.

## [0.5.8] - 2026-10-19

### Upgrade notes

- **Read [0.5.7 → 0.5.8](docs/upgrade.md#057--058) before the upgrade.** In short: a multi-tenant
  install upgraded with `--reuse-values` adds `--set ui.image.multiTenantSuffix=-mt`; inactive and
  maintenance regions stop serving once `region:reconcile` applies their status block; SSO
  providers need the `https://` callback URL, and a Google provider with a Hosted Domain admits
  that domain only; switching to the multi-tenant edition converts the installation into its first
  tenant (`--timeout 20m`); a Compose install takes the new `Caddyfile`.
- **Billing is rebuilt as the optional Billing module** (off by default). Plans, subscriptions,
  credits, purchases, add-ons, capacity deals and charges of 0.5.7 are archived in
  `billing_legacy_archive` and their tables dropped; the cost configuration becomes the platform's
  prices and closed charges become closed tenant bills. Set `BILLING_CURRENCY` to the currency of
  your 0.5.7 cost configuration in the upgrade: the import runs also with billing off and warns
  when they differ ([Billing](docs/upgrade.md#billing)).
- **Back up the database before the upgrade.** 0.5.8 cannot be rolled back with migrations (they
  stop with a message instead of half-undoing the billing changes); going back to 0.5.7 is restoring
  that backup ([Rolling back](docs/upgrade.md#rolling-back)).

### Added

- **Billing module** (`api/Modules/Billing`, `vue/src/modules/billing`; off by default, Helm
  `billing.enabled`, `BILLING_ENABLED`). RunR calculates one bill per project and calendar month,
  paid by the project's owner, and an account bill per user for bindings, sandboxes and model
  services; bills close daily at 02:30 after their month and are never recalculated. Every region
  has one billing type: **pay as you go** (metered vCPU-hours, GB-hours, GPU-hours, binding
  storage, sandboxes) or **fixed** (a project requests vCPU, memory and GPUs, sees the monthly
  price before submitting, the region's seller approves, the price is prorated by day from the
  approval and the project cannot exceed its allocation — its worst case, every pod up to a
  required `max_scale` with a deploy's overrides, its previews and GPUs, is checked at every
  deploy, resume, preview, region migration and size change, and a request or approval smaller
  than what runs is refused until the project scales down, one while it runs without `max_scale`
  until it is set and redeployed; administrators see the metered cost
  next to the fixed price). Prices per region and default prices are versions with an effective date and
  never missing: region → tenant default → platform default → built-in defaults seeded on the first
  start. With tenancy the platform bills each tenant for the shared platform regions; a shared region
  is an offer the tenant's administrators accept with a markup (default markup prefilled, changes
  with an effective date), and only then can its users use it. RunR only calculates: an external
  invoicing system reads bills, usage, the rollup per owner and fixed requests through
  `/api/billing/…`, sets `payment_url` and the paid status of each bill and receives signed events
  (`bill.closed`, `bill.paid`, `bill.payment_url_set`, `fixed_request.approved` / `.rejected`,
  `region_share.accepted`, `project.stopped`, …). New permissions `billing.approve`,
  `projects.billing`; `billing.view` / `billing.manage` and the Billing role now exist in both
  editions ([docs/billing.md](docs/billing.md)).
- **How the Billing module counts.** Amounts are exact — decimal arithmetic, each line rounded once,
  half up, to the currency's minor unit, the bill's total the sum of its lines — and the quote of
  fixed resources is the bill's own calculation. Days billing was off are not billed: the bills
  open before end with the last active day, a month inside the off period gets none, billing starts
  again on the day it comes back. A single-organization installation converted into its first
  tenant bills every project once, in the tenant, from the conversion day. Fixed allocations end
  with their project (deleted or moved) also while billing is off, and with their tenant on the day
  it is deleted. Billing webhooks never reach private or cluster-internal addresses — a tenant's
  never, the platform's only with `observability.alerts.allowPrivateTargets` — checked on save and
  before every delivery. Accepting a region offer writes the markup and activates the share in one
  step, announced after it succeeded ([docs/billing.md](docs/billing.md#turning-billing-off-and-on)).
- **Project owner.** Every project has an owner (`owner_id`, the creator by default), the payer of
  its bills — also of the running month's bill, which belongs to whoever owns the project when the
  month closes; the owner or a holder of `projects.manage_access` transfers it to a member of the
  organization who sees the project (a platform administrator's access to every organization does
  not count), who is notified (`PUT /api/projects/{project}/owner`, audited as
  `project.owner.transfer`) ([docs/billing.md](docs/billing.md#who-pays)).
- **Project stop and resume.** Administrators (inside a tenant, its administrators) and platform
  administrators stop a project with a required reason — its workloads and previews are shut
  down, deploys queued before the stop do nothing and every deploy path (console, git pushes,
  previews, region migrations) is refused until it is resumed, which redeploys the running version
  with its overrides through every deploy check — with the new permission `projects.stop`
  (`POST /api/projects/{project}/stop` / `resume`, audited; the reason is shown to the project's
  members). A stop by a platform administrator is the platform's: the console says so, and only a
  platform administrator resumes it; a platform administrator takes an organization's stop over with
  **Stop for the platform** on the project page
  ([docs/billing.md](docs/billing.md#project-stop-and-resume)).
- **The console hides the Ticket and Billing pages at run time** when the API reports the module
  off (`features.tickets` / `features.billing` of `GET /api/branding`), so the published UI images
  are built with both screens.
- **Bills of deleted tenants are kept.** A tenant's project and account bills live in its schema,
  which tenant deletion drops; the deletion job now copies them first into the central
  `billing_archived_bills` — line items, amounts, payer, paid status, payment link, closing time and
  the tenant's id, slug and name (open bills with the estimate of that moment). When the copy fails
  the tenant stays `delete_failed`, also with Force delete. Platform administrators read them with
  `GET /api/billing/archived-bills` (`?tenant=<id or slug>`) and `GET /api/billing/archived-bills/{id}`
  ([docs/billing.md](docs/billing.md#bills-of-deleted-tenants)).

- **Helm: the API volume can be ReadWriteMany.** `api.storage.accessModes` (default
  `[ReadWriteOnce]`, the mode of every earlier install) sets the access modes of
  `runr-api-storage`. The API, Horizon, scheduler and Reverb pods are colocated on the volume's
  node only with ReadWriteOnce; with `[ReadWriteMany]` (and a ReadWriteMany StorageClass) they run
  on any node. An upgrade that asks an existing claim for other access modes or another
  StorageClass stops with a message instead of the API server's "spec is immutable";
  [docs/install.md](docs/install.md#moving-the-api-volume-to-readwritemany) moves an existing
  volume. `database.postgres.storageClassName` sets the StorageClass of the bundled PostgreSQL's
  volume.
- **Helm: passwords of an external database, Redis and SMTP server from Secrets.**
  `database.password`, `redis.password` and `mail.password` are written to the chart's Secret;
  `*.existingSecret` / `*.existingSecretPasswordKey` reference a Secret of yours. The pods read them
  as Secret env vars (`DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`), never from the ConfigMap;
  `mail.username`, `mail.encryption` (`starttls` / `tls`) and `redis.username` complete the
  settings, and the bundled Redis requires a configured password. The generated `APP_KEY` and
  `REVERB_APP_SECRET` stay with the chart and are kept on every upgrade: an external database no
  longer needs `secret.create=false`. `extraEnv` entries keep working
  ([upgrade note](docs/upgrade.md#passwords-of-an-external-database-redis-and-smtp-server)).
- **Helm: NetworkPolicies.** `networkPolicy.enabled` (default off) adds policies that allow exactly
  the connections of [docs/install.md](docs/install.md#network-policies) — ingress controller →
  gateway (`networkPolicy.gatewayFrom`), gateway → UI / API / Reverb, the API, Horizon, scheduler,
  Reverb and hook pods → database, Redis, SMTP, Reverb, DNS and outbound to the regions
  (`networkPolicy.egress`, `networkPolicy.extraEgress`) — and deny the rest.
- **Horizon dashboard for administrators.** `/horizon` admits platform administrators and, in the
  single-tenant edition, Owners, signed in with a single-use link (valid 5 minutes, stored as a
  hash) from `php artisan runr:horizon-link` or `POST /api/horizon/link` (audited as
  `horizon.link.create`). The session it starts is checked against the account on every request
  ([docs/install.md](docs/install.md#horizon-dashboard)). Before, the dashboard answered 403 to
  everyone in production, and its gate would have let any signed-in user in.
- **Helm: `ingress.tls.mode: secret`** references a `kubernetes.io/tls` Secret you manage
  (`ingress.tls.secretName`); the legacy `mode: ""` keeps working. `gateway.affinity`,
  `api.affinity` and `ui.affinity` replace `placement.affinity` for one component.
- **Helm: more than one Reverb replica.** With `reverb.replicas` above 1 the replicas share
  broadcasts over Redis (`REVERB_SCALING_ENABLED`); shell, sandbox exec and agent sessions stay on
  the replica their WebSocket reached.
- **Helm: `cors.allowedOrigins`** for browser pages on other origins that call the API.
- **Helm: `kubeVersion: ">=1.23.0-0"`** in `Chart.yaml`, the minimum of the APIs the templates use
  (`autoscaling/v2`).
- **Linked accounts in Account settings.** With the AuthProvider module, Account settings list
  the external accounts a user signs in with: **Link <provider>** connects another OAuth or
  OpenID Connect account — also one with another e-mail address — and **Unlink** removes one. The
  provider's callback hands the console a one-time code that only the user who started the link
  can confirm (`POST /api/oauth/link/complete`); an account linked to another user is refused.
  `GET /api/oauth/{driver}/link`, which answered a plain sign-in URL, now links. Audited as
  `social_account.link` ([docs/modules.md](docs/modules.md#linked-accounts)).
- **Platform administrators open an organization as its administrator.** The tenant switcher or
  Settings → Tenants → **Open as administrator** opens any organization; a bar names it until
  **Stop**. Opening and leaving are audit-logged (`impersonation.start`, `impersonation.stop`).
  `POST /api/impersonate/{tenant}` and `POST /api/impersonate/stop` work again, and
  `GET /api/tenant/current` reports `impersonating`, and `member` for each organization
  ([docs/tenancy.md](docs/tenancy.md#opening-a-tenant-as-platform-administrator)).
- **Organization domain in the console.** Members with `settings.manage` set and clear their
  organization's own domain in Settings → Organization domain; `GET /api/settings` returns
  `tenant_base_domain` and `platform_base_domain` inside an organization
  ([docs/tenancy.md](docs/tenancy.md#organization-domain)).
- **Support ticket assignment and tags in the console.** The staff view of a ticket assigns it to
  a staff member and edits its tags; `GET /api/admin/support-tickets/staff` lists the assignee
  choices ([docs/modules.md](docs/modules.md#ticket)).
- **`runr whoami` names the organization** the session works in (`organization` in its JSON
  output); a platform administrator outside any organization sees `platform (no organization)`.
- **The CLI shows stopped projects and refusal codes.** `runr status` and `runr projects get` say
  who stopped a project (the platform or the organization), when and why (`stopped` in their JSON
  output), `runr projects ls` lists it as `stopped`, and an error the API answers with a code prints
  the code after the message, e.g. `(project_stopped)` or `(fixed_allocation_exceeded)`
  ([docs/cli.md](docs/cli.md#output-errors-and-exit-codes)).
- **Helm: the bootstrap Job converts an installation that ran single-tenant.** In the multi-tenant
  edition it runs `php artisan tenancy:setup --force` before the administrator check, so switching
  `edition` to `multi-tenant` makes the installation its first tenant (`Default`, the slug of the
  git remote owner); fresh, converted and multi-tenant installations are left alone, and a
  conversion that stopped continues on the next run. `bootstrap.tenancySetup=false` leaves the
  conversion to the operator
  ([docs/tenancy.md](docs/tenancy.md#converting-a-single-organization-installation)).

### Changed

- **Usage metering is part of the core** and runs on every installation, single-tenant included
  (`usage-aggregation` every 5 minutes, central and every tenant schema). It records quantities
  only; the cost columns of the usage records are no longer filled. Usage of tenant projects in
  platform regions is copied to the central database for the platform's bills to tenants. An
  always-on deployment counts its reservation for the time it ran that day with the size it was
  deployed with (`deployments.sizing`, `deployments.ended_at`), no longer today's configuration for
  24 hours; `min_scale` ≥ 1 counts as always-on, as documented. `php artisan usage:recalculate`
  calculates earlier days again; a deployment applied before the upgrade keeps the scale mode and
  size its 0.5.7 record of the day shows, never today's configuration
  ([docs/monitoring.md](docs/monitoring.md#metering)).
- **Sharing a platform region with a tenant is an offer while billing is on**: the region reaches
  the tenant's users once a tenant administrator accepted it. Existing assignments stay active, and
  with billing off offers are active at once (pending ones within five minutes) and stay active when
  billing returns ([docs/tenancy.md](docs/tenancy.md#shared-regions-with-billing)).
- **White-label keeps branding, self-service signup and the onboarding wizard**, without plans:
  the wizard assigns the first region with the default signup quota.

- **The source is public** at <https://github.com/BRF-Tech/runr>. The single-host install starts
  from a checkout of the release tag ([compose/README.md](compose/README.md)).
- **CORS follows the console's domain.** The API allowed a fixed list of development origins in
  every environment; it now allows the origin of `APP_URL` / `FRONTEND_URL` (Helm: `https://<domain>`)
  plus `CORS_ALLOWED_ORIGINS` (Helm: `cors.allowedOrigins`), and the development servers only with
  `APP_ENV=local`. The console is same-origin and unaffected.
- **A changed organization domain moves like the platform base domain.** The functions keep their
  URLs and answer on the new domain too — through an alias of the organization's own on platform
  regions, through the base-domain migration on its own regions — until DNS of the new
  `<region>.<domain>` answers, or until **Complete now** in the Organization domain card
  (`PATCH /api/settings` `{"complete_domain_migration": true}`). Setting the previous domain back
  rolls the move back; `GET /api/settings` shows it as `tenant_domain_migration`
  ([docs/tenancy.md](docs/tenancy.md#organization-domain)).

### Fixed

- **Helm: uptime checks run in the region that is RunR's own cluster.** Its agent calls the API
  through the in-cluster gateway (`runr-gateway.<namespace>.svc.cluster.local`), a host the API
  answered with 400 (`uptime: fetching checks: RunR answered 400`), so checks with location
  "regions" never ran there; the replay captures of that cluster's function pods, and the callbacks
  of any region whose agent address is not the console's host (`agent_ws_url`,
  `RUNR_AGENT_WS_URL`), failed the same way. The region callbacks under `/api/internal/` now also
  accept the hosts of the addresses RunR hands out: the in-cluster gateway, every region's
  `agent_ws_url` and `app_url`, `RUNR_AGENT_WS_URL`, `observability.replay.ingestUrl` and
  `WORKFLOWS_PUBLIC_API_URL`; every other route still answers only for the console's host.
  Upgrading the API is enough, the agents keep their address
  ([regions](docs/regions.md#network-reachability)).
- **Helm: switching a module on in an upgrade creates its tables in every tenant schema.** The
  pre-upgrade migration Job ran before Helm applied the new ConfigMap and so migrated with the
  previous release's module switches: an upgrade that turned billing (or tickets) on left the tenant
  schemas without the module's tables. The migration and bootstrap Jobs now take `TENANCY_ENABLED`,
  `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`, `BILLING_ENABLED` and `BILLING_CURRENCY` from the values,
  the Billing and Ticket modules register their tenant migrations whether switched on or not, and
  the bootstrap Job runs `tenancy:migrate` after its module switches as well
  ([upgrade](docs/upgrade.md#procedure)).
- **A redeploy no longer counts the day's usage again.** Metering kept a usage record per
  deployment but filled each with all of the project's sessions of the day, so a project redeployed
  (or moved, or switched between scale modes or triggers) N times had its morning counted N times,
  and an always-on project got another full day of reservation per deploy. Every session and every
  second of a reservation now counts once, and a recalculated day gives the same totals. The run
  for yesterday during the first hour of a day no longer applies today's state: a project first
  deployed after midnight has no usage the day before, and a resize after midnight does not change
  it ([upgrade note](docs/upgrade.md#billing)).
- **A redeploy while the previous deploy is still starting leaves one live deployment.** A deploy
  replaced only the active deployment: one still starting went live next to the new one once its
  readiness check found the project's Knative Service ready, and both were metered. Every live
  deployment of the project is now replaced (console, CLI, API, git pushes, resumes, runtime test
  deploys), the readiness check of a replaced one ends it without activating it, and a git deploy
  replaced before it went live ends as `skipped` instead of `deploying`.
- **Helm: SSO callback and MCP URLs are `https`.** The gateway overwrote the ingress controller's
  `X-Forwarded-Proto` with `http`, so the API sent `http://` SSO redirect URIs (which Google
  rejects) and published `http://` MCP metadata. It now keeps the header of a trusted ingress
  controller. Providers registered with the `http://` callback need the `https://` one; SAML URLs
  came from `APP_URL` and do not change ([upgrade note](docs/upgrade.md#https-urls-behind-the-ingress-controller)).
- **Helm: `api.pdb` no longer blocks node drains.** With the ReadWriteOnce API volume the API pods
  cannot leave the volume's node, so the budget held a drain forever; it is created only with a
  ReadWriteMany volume, and `helm status` says when it is skipped.
- **Helm: upgrades keep the replica count of an autoscaler.** The Deployments of the gateway, API
  and UI set `replicas` on every upgrade, resetting what their HorizontalPodAutoscaler had scaled
  to; with the HPA on, the chart leaves the count to it.
- **Helm: `api.port`, `ui.port` and `reverb.port` take effect.** Octane, the UI's Caddy and Reverb
  listened on 80, 80 and 8080 whatever the values said; they listen on the configured ports
  (`RUNR_API_PORT`, `RUNR_UI_PORT`, `REVERB_SERVER_PORT`), the defaults unchanged.
- **Reverb logs every message only while debugging.** It ran with `--debug` in production; now only
  with `APP_DEBUG=true` or `APP_ENV=local`.
- **A generated administrator password is never logged.** With `secret.create=false` and no
  `ADMIN_PASSWORD`, the seeder printed the generated password into the bootstrap Job's log; the Job
  now stores it in the Secret `runr-admin` (it may read and patch that one Secret) and `helm status`
  prints the command that reads it. The seeder shows a password it generates only on an interactive
  terminal; the Compose migrator still prints it once on the console that runs it.
- **Helm: `values.schema.json` validates `images.*` and `supplyChain.*`**, the keys the templates
  read; it checked `api.images` and `api.supplyChain`, which nothing reads (still accepted).
- **A Compose install has a first administrator.** `docker compose run --rm migrator` ran the
  seeders, which create accounts only in a local development environment: a production install
  ended without any account. The migrator now creates the first administrator while the
  installation has none, with the rules of the Helm chart's bootstrap job: `ADMIN_USERNAME`,
  `ADMIN_EMAIL` and `ADMIN_PASSWORD` from `api.env` (new in `api.env.example`; defaults `admin`
  and `admin@example.com`); with an empty `ADMIN_PASSWORD` it generates a password and prints it
  once at the end of its output, never to the log. Nothing happens when that account exists,
  another administrator exists (without the Tenancy module's administrator flag: any user) or the
  e-mail address belongs to another account, so the migrator stays safe to run after every update
  and an existing install with users gets no new account. `php artisan runr:bootstrap-admin` does
  the same by hand
  ([compose/README.md](compose/README.md#5-start-the-services-and-create-the-administrator)).
- **HTTPS for the Compose install.** The guide served plain HTTP only; the example host proxy
  configurations were not part of it and disagreed with the default port. Section 7 of
  [compose/README.md](compose/README.md#7-https) sets up a Caddy on the host for the console
  domain (Let's Encrypt or an own certificate) and, for a region whose ingress only that host
  reaches (a kind cluster), the wildcard function domain: `Caddyfile-host` forwards to the gateway
  on `127.0.0.1:8080` (`PORT` in `.env`), `Caddyfile-wildcard` is appended to it. The gateway
  (`compose/Caddyfile`) now keeps the `X-Forwarded-Proto` and `X-Forwarded-For` of a proxy on a
  private address instead of overwriting them, so behind the HTTPS proxy the API builds `https`
  URLs (the SSO callback URL among them) and, with `TRUSTED_PROXY_HOPS=2` (new in
  `api.env.example`, default `1`), records the client's address; clients that connect to the
  gateway directly are handled as before. The examples of `APP_URL`, `FRONTEND_URL` and
  `SANCTUM_STATEFUL_DOMAINS` match the default port.
- **Compose module toggles reach the console.** `compose/build.sh` built the UI without the module
  flags, so `TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED` and `TICKET_ENABLED` switched the API only.
  The build now reads them from `compose/.env` (an environment variable of the same name takes
  precedence), with the defaults of `docker-compose.yml`, and builds the UI with the modules the
  API enables; `compose/deploy.sh` reads them from the server's `.env`. The guide sets the toggles
  before the build ([compose/README.md](compose/README.md#1-choose-the-modules)). An existing
  install keeps its `.env`; its next build shows the modules its API already runs.
- **The Compose gateway serves the Horizon dashboard.** `/horizon` went to the console; it reaches
  the API as on the Helm gateway, signed in with the single-use link of
  `php artisan runr:horizon-link` or `POST /api/horizon/link`
  ([compose/README.md](compose/README.md#horizon-dashboard)).
- **SSO callbacks reach the API.** The sign-in callback of an OAuth / OpenID Connect provider is
  the API's `/oauth/<provider>/callback`. The Helm chart's gateway sent it to the UI, so a provider
  sign-in ended on the console's start page; the Compose gateway sent every `/oauth/*` path to the
  API, the console's own `/oauth/callback` page that completes the sign-in included, which then
  answered 404. Both gateways now route `/oauth/<provider>/callback` to the API and leave
  `/oauth/callback` to the console; `ci/check-helm-chart.sh` checks the chart's route.
- **`edition: multi-tenant` runs the multi-tenant console.** The modules of the console are built
  into its image, and the chart appended `ui.image.multiTenantSuffix` (default empty) to the UI tag
  of the multi-tenant edition, so `--set edition=multi-tenant` alone ran the single-tenant console
  without the tenant and SSO pages. The default suffix is now `-mt`, the published multi-tenant UI
  images (`ghcr.io/brf-tech/runr/ui:<version>-mt`); with the default tag `latest`, which has no
  multi-tenant variant, the multi-tenant edition runs the chart's release (`<appVersion>-mt`).
  Single-tenant installs and installs that set the suffix keep their image. `helm upgrade
  --reuse-values` keeps the empty suffix of an earlier release: add
  `--set ui.image.multiTenantSuffix=-mt` to that upgrade. A UI image of your own that carries the
  modules under its plain tag needs `ui.image.multiTenantSuffix=""`.
- **Helm value `ticket.enabled`.** The Ticket module (support tickets) had no chart value: the API
  always enabled it, and `runtime/ci.sh deploy --no-ticket` (its default) did not reach the API.
  `ticket.enabled` sets `TICKET_ENABLED` for every API container and hook job; its default `true`
  is the module state every earlier install had, also for reused values without the key. Installs
  deployed with `ci.sh deploy` without `--ticket` carry `ticket.enabled=false`: their API now
  disables the module, as their console already did. The console shows the ticket pages when its
  UI image is built with `VITE_TICKET_ENABLED=true`.
- **OpenID Connect sign-in.** The *Generic OIDC* provider failed at every sign-in: no `oidc`
  driver existed. RunR now signs in against the identity provider's discovery document with the
  authorization code flow, PKCE, `state` and `nonce`, verifies the ID token (signature against the
  published keys, issuer, audience, expiry, nonce) and reads e-mail, name and the groups claim of
  SSO group mapping from it and the userinfo response
  ([docs/modules.md](docs/modules.md#openid-connect)).
- **Google hosted domain.** The provider's Hosted Domain was not sent to Google, so any Google
  account could sign in. The sign-in now asks for the domain (`hd`) and refuses an account whose
  `hd` claim is another domain or missing.
- **SSO sign-in on a tenant's sign-in page.** The redirect used the platform's provider settings
  and the callback the tenant's: a provider that exists only in the tenant failed. Redirect and
  callback now use the same provider of the same organization.
- **SAML button on the sign-in page.** It called an OAuth route that excludes SAML and failed; it
  now starts at the provider's SAML login.
- **Several providers of one driver.** A sign-in always took one, arbitrary enabled provider of its
  driver. The sign-in page's button now names its provider (`?provider=<id>` on
  `/api/oauth/{driver}/redirect`; without it the first by order, then name), so two OpenID Connect
  identity providers, or a gitlab.com and a self-managed GitLab, can be enabled side by side.
  OpenID Connect users are identified per provider and GitLab users per self-managed instance;
  accounts linked earlier keep working. A self-managed GitLab's Instance URL without a trailing
  slash works.
- **Sign-in errors at the provider.** A cancelled sign-in or a code the provider refuses returned an
  error page; the sign-in page now shows the reason. A provider address marked unverified
  (`email_verified: false`) is no longer matched to an existing account.
- **An SSO sign-in from a tenant's sign-in page starts the console in that tenant.** The one-time
  code of the OAuth, OpenID Connect and SAML handoff names the tenant, and in a multi-tenant
  install `POST /api/oauth/exchange` answers like the password sign-in, with `tenants` and
  `active_tenant` next to the token. A tenant's page admits its members and platform
  administrators only; anyone else gets 403 and the session is revoked. The session is not pinned
  to the tenant, so the console still switches organizations
  ([docs/security.md](docs/security.md#sso-sign-in-handoff)).
- **An SSO sign-in after another session** replaces that session's organization and brand in the
  console with the ones of the new sign-in.
- **Converting a single-organization installation into a multi-tenant one.**
  `php artisan tenancy:setup` migrated the new tenant schema with the central migrations (the next
  `tenancy:migrate` of every upgrade failed, and a second run created every central table in every
  tenant schema) and copied nine tables. It now creates the schema with the tenant migrations and
  copies every table the tenant schema has, in foreign-key order with the ids they had, gives every
  account its organization role as membership, assigns and syncs the regions, binds the
  organization's central rows (sandboxes, git installations and repository links, MCP, status
  pages) and API tokens to it and moves `storage/git` and the git remotes into the
  organization's directories. The central schema keeps its rows. `--dry-run` shows the plan and
  changes nothing; a conversion that stopped continues where it stopped; a finished conversion, a
  fresh installation and an installation with organizations are left alone. The slug defaults to
  the git remote owner, so existing git remotes keep their URLs
  ([docs/tenancy.md](docs/tenancy.md#converting-a-single-organization-installation)).
- **An installation switched to the multi-tenant edition has platform administrators.** The
  Tenancy migrations added `users.is_admin` as false for every account and the chart's bootstrap
  creates an administrator only on installs without accounts. A new Tenancy migration makes the
  owners and admins of the single organization platform administrators when the installation has
  none (nothing changes where one exists), `tenancy:setup` promotes them too, and
  `tenancy:setup --admin=<account>` makes any account one.
- **`tenancy:migrate --fresh` empties the tenant's schema only.** It ran `migrate:fresh` in tenant
  context, whose search path includes `public`: the central tables were dropped too. It now drops
  and recreates the tenant's schema, and asks for confirmation in production unless `--force`.
- **New organizations get every module's tenant migrations.** The console, self-service signups
  and `tenancy:create-tenant` ran only Tenancy's tenant migrations; the Ticket module's waited for
  the next upgrade. They now run the same migrations as `tenancy:migrate`.
- **Single-tenant installs have a support staff view.** Members whose role holds `tickets.manage`
  (Owner, Admin, custom roles) list, answer, assign and tag tickets in Settings → Support Tickets;
  it was shown to platform administrators only, which single-tenant installs do not have.
- **Ticket attachments are kept per organization.** Ticket ids are per organization, so their
  files shared `support-attachments/<ticket id>/`. Files now go to
  `support-attachments/<tenant id>/<ticket id>/`, a download serves only files recorded on that
  ticket of the requesting organization (internal-note files to support staff only), and the
  upgrade moves each organization's existing files into its directory.
- **Changing an organization's domain keeps the platform's domain entry.** The previous domain was
  read with the platform's as fallback, so an organization's first domain removed the platform's
  `<region>.<platform domain>` entry from the regions; the platform's own domain and malformed
  host names are refused.
- **`GET /api/settings` shows the capacity and allocations of every platform region**
  (`assignable_regions`) to platform administrators only.
- **Deleting an organization removes what it kept outside its schema:** its sandboxes (pods
  deleted in the region), warm pools and sandbox quota, uploads in progress and uploaded images
  (registry manifests included), custom runtimes with their registry images, hosted MCP servers
  with their calls and sessions, git installations and repository links, status page lookups, its
  branding override and assets, its support ticket attachments, its notifications and the
  settings of its own regions. The audit log and billing records are kept; a sandbox pod or image
  that cannot be removed keeps the organization as `delete_failed` for a retry
  ([docs/tenancy.md](docs/tenancy.md#tenant-lifecycle)).
- **Organizations create projects, previews and sandboxes on their own regions.** The
  organization quota check refused a region the organization added itself, because it has no
  quota assignment; own regions are limited by their capacity only.
- **Regions an organization adds are recorded as its own** (`owner_tenant_id`): the
  installation's organization CPU/RAM ceiling is no longer applied to an organization's own
  cluster. The upgrade marks existing ones.
- **A platform region's status reaches the organizations.** Its status (active, inactive,
  maintenance) and migration state are synced to the organizations' copies, so their deploys
  respect a region in maintenance.
- **The status checks of a platform region reach the organizations' copies.** The installation,
  cluster and DNS status the status jobs write stayed in the platform's row; only changes made
  through the region API were synced.
- **Ending a region assignment or deleting a platform region removes the organization's copy.**
  The organization's projects there are torn down and stay without a region, its API gateways are
  removed from the region, and the region leaves its list.
- **Detaching a platform region from an organization, or deleting an organization without a domain
  of its own, removed the platform's `<region>.<base domain>` entry** from the region's Knative
  domain configuration; only the organization's entries are removed now.
- **Reading an unreachable cluster removed its kubeconfig.** `GET /api/knative-regions/{id}/knative-status`
  (`runr regions status`) and `GET …/nodes` (the Workers tab) set the region's kubeconfig to
  null on any error, so a network hiccup left the region without access to its cluster. They now
  answer the error and record the cluster as unreachable; the kubeconfig stays.
- **The full Knative install deleted the Knative Services of a cluster that already ran Knative.**
  `POST …/install-knative` (`runr regions knative install REGION`) installed the operator
  without an adoption check, and the operator install starts by removing the previous operator
  and the Knative Serving CRDs. It now adopts a running operator and a Ready Knative Serving like
  the component installs; the operator install itself adopts a running operator, and its cleanup
  of a broken install keeps the CRDs while Knative Services exist
  ([docs/regions.md](docs/regions.md#knative-operator-and-serving)).
- **A changed base domain did not move the regions.** The new `<region>.<base domain>` was added
  next to the old one (Knative serves only one), the regions' function domain — project URLs,
  function TLS, the status block, request replay — kept the old one, the DNS check never ran,
  the old domain was removed after 48 hours whatever DNS said, and the first base domain
  replaced a region's own domain. A changed base domain now migrates the regions that follow it:
  both domains are served (the new one by `runr-domain-alias` in the project namespace) until DNS
  of the new one answers the region's external address, or the migration is completed by hand
  (Migration tab, `runr regions migration complete REGION --domain`,
  `POST …/complete-domain-migration`); then the new domain becomes the function domain. Setting
  the old base domain back rolls the migration back; a region with a domain of its own is never
  touched ([docs/regions.md](docs/regions.md#changing-the-base-domain)).
- **Dedicated workers could not be cleared.** `POST …/workers` refused an empty list, so
  `runr regions nodes set REGION --all` and deselecting every node in the Workers tab failed with
  422. An empty list releases every node.
- **The console's "In-cluster" Redis was refused.** The Redis tab sent the mode `in-cluster`; the
  API takes `in_cluster`.
- **Upgrade Serving did nothing.** The Serving tab's Upgrade answered "already installed". It
  upgrades Knative Serving to the next minor version the cluster supports
  (`POST …/upgrade-serving`, `runr regions knative upgrade REGION --version`): the operator
  follows when it is older, the version's CRDs are applied, then the KnativeServing resource
  ([docs/regions.md](docs/regions.md#knative-operator-and-serving)).
- **The region mode chosen in the wizard was dropped.** `POST /api/registry-setup/start` (the
  console wizard) ignored `region_mode`; with the Tenancy module it is stored like on
  `POST /api/knative-regions`.
- **`region_mode` is a platform administrator's choice.** In a multi-tenant install anyone else's
  `region_mode` (managed or infinite organization quotas) is ignored on region create and update
  and on `POST /api/registry-setup/start`, so an organization cannot lift the quotas of a region.
- **Region settings had no console form, and `agent_ws_url` needed a manual redeploy.** The
  General tab has the settings form (request timeout, agent address, cert-manager ClusterIssuer,
  trusted proxies); a changed `agent_ws_url` deploys the region's agent again. `PUT …/settings`
  no longer takes `namespace`, which was never read ([docs/regions.md](docs/regions.md#general)).
- **`request_timeout` did not raise the function timeout.** The region Traefik cut every response
  after 120 s and Knative refused a revision timeout above 600 s, whatever the setting (default
  300, up to 3600). Traefik's read and write timeouts follow the longest setting of the regions on
  the cluster plus 10 s, and Knative's maximum revision timeout is raised to it — at install, on a
  settings change and with `region:reconcile` (Traefik's pods roll once)
  ([docs/regions.md](docs/regions.md#request-timeout)).
- **Registry, monitoring and function TLS could not change after a region was created.**
  `POST …/registry` (the Registry tab, `runr regions registry set`) sets up the registry of an
  existing region, and `POST /api/knative-regions` with registry fields sets it up instead of only
  storing them; `PUT …/monitoring` (General tab, `runr regions set --set prometheus_url=…`)
  switches between an existing Prometheus / Loki and RunR's own; `DELETE …/function-tls`
  (`runr regions function-tls rm`) switches function TLS off; the Isolation tab adds Kata
  hypervisors to a working install ([docs/regions.md](docs/regions.md#region-settings-reference)).
- **A region created inactive was refused deploys while it showed active.** `is_active: false`
  (`runr regions create --inactive`) left `region_status` active; it is `inactive` now, and the
  upgrade fixes existing regions.
- **Inactive and maintenance regions kept serving.** The status block's route used Traefik v2 rule
  syntax, which never matches on the region Traefik, covered only `*.<function domain>`, was
  shared by the regions of a cluster and was applied only on a status change. It now matches with
  Traefik v3 syntax every host of the region — function domain, project custom domains, the
  domains of its Knative Services — one route per region, and it is applied when the region's
  Serving becomes ready and by `region:reconcile`, which also removes a leftover from an active
  region ([docs/regions.md](docs/regions.md#region-status)).
- **`region:reconcile` keeps the status block of the organizations' own regions.** It applied and
  removed the block of platform regions only; it now does both for every organization's own
  regions too, in the organization's context. The tenant schemas get the `region_status` fix of
  regions created with `is_active: false`.
- **A region moved to another cluster lost part of its setup.** A migration now also brings the
  dedicated workers that exist on the new cluster, WebAssembly and GPU support, the data services
  RunR runs, the admission policy, function TLS, the Skupper site and the status block; the
  Migration tab sends the new cluster's data location, and a region whose cluster does not answer
  can be opened in the console to move it
  ([docs/regions.md](docs/regions.md#moving-a-region-to-another-cluster)).
- **The DNS check never passed behind a load balancer with a host name.** It compared
  `gethostbyname()` with the external address, so `dns_resolved` stayed false and a migration to
  such a cluster waited the full 48 hours. A CNAME to the load balancer, or one of its addresses,
  now counts.
- **A removed tracing backend came back.** `DELETE /api/observability/regions/{id}/tracing` kept
  the region's tracing mode and `region:reconcile` installed the backend again; the removal sets
  the mode to `off`.
- **`uninstall-knative` left the region showing Knative installed.** The operator and Serving
  statuses stayed ready; they are reset to `not_installed`.
- **A replaced kubeconfig was not checked.** `PUT /api/knative-regions/{id}` with `kubeconfig`
  (`runr regions set --kubeconfig`) took any value; it is checked like a new region's and has to
  reach the same cluster ([docs/regions.md](docs/regions.md#general)).
- **The fallback Knative version needed Kubernetes 1.32.** Without the chart repository RunR
  installed Knative 1.20 on any cluster; it installs the newest pinned Knative version the
  cluster supports. RunR's Prometheus (kube-prometheus-stack 92.10.1) and Loki (loki-stack
  2.10.3) charts are pinned instead of the newest the repository has.
- **The region-mode hint of the wizard said every tenant gets unlimited access** to an
  `infinite` region; only the tenants it is assigned to use it.

### Removed

- **Plans and the 0.5.7 billing**: plans and plan limits, plan renewal, subscriptions, trials,
  credits, purchases, add-ons, capacity deals, cost configurations, charges, usage thresholds and
  the store link, with Settings → Plans & Billing, Settings → Subscription, Settings → Charges and
  the old cost overview; the endpoints `/api/admin/plans`, `/api/admin/billing-settings`,
  `/api/plans/public`, `/api/tenant/subscription`, `/api/tenant/billing`, `/api/cost-configs`,
  `/api/tenants/{tenant}/billing/*`, `/api/tenants/{tenant}/capacity-deals`,
  `/api/regions/{region}/profit-loss` and the 0.5.7 `/api/billing/tenants`, `/charges` and
  `/purchases` routes; the Helm values `plans.enabled`, `billing.usageThresholds`,
  `billing.storeUrl` and the variables `PLANS_ENABLED`, `BILLING_USAGE_THRESHOLDS`,
  `BILLING_STORE_URL`, `BILLING_DEFAULT_CURRENCY` (now `BILLING_CURRENCY`). The data is kept in
  `billing_legacy_archive` ([docs/upgrade.md](docs/upgrade.md#billing)).
- **Cost fields of the core usage endpoints**: `final_cost`, `raw_cost` and `currency` of
  `GET /api/bindings/{id}/usage` and `GET /api/sandbox-usage`, which were 0 for every record since
  metering records quantities only, and the COST column of `runr bindings get --usage` and
  `runr sandbox usage` (which now totals time, CPU and memory hours). What usage costs comes from the
  Billing module: `GET /api/billing/usage` and the bills
  ([upgrade note](docs/upgrade.md#api-changes)).

- **Helm: SQLite is not a chart option.** `database.driver: sqlite` never worked with the chart
  (no database file on the API volume, one database per pod); the API, Horizon, scheduler and
  Reverb pods share one PostgreSQL server, and any other driver stops the install with a message.

## [0.5.7] - 2026-10-17

### Changed

- **The CLI downloads from runr.sh.** `curl -fsSL https://runr.sh/install.sh | sh` installs the
  `runr` CLI on Linux and macOS (amd64, arm64): it reads the latest release from
  `https://runr.sh/dl/cli/latest.txt` (`RUNR_VERSION` pins one), checks the archive's SHA-256
  against the release's `checksums.txt` and installs into `/usr/local/bin` when that is writable,
  else into `~/.local/bin` (`RUNR_INSTALL_DIR` sets another directory). The archives of every
  platform, Windows included, are under `https://runr.sh/dl/cli/<tag>/`.
  `runr version --check-update` (and `update_check: true`) reads the latest release from that
  `latest.txt` ([docs/cli.md](docs/cli.md#install)).
- **Terraform provider address `runr.sh/brf-tech/runr`.** runr.sh serves Terraform's provider
  registry protocol: with `source = "runr.sh/brf-tech/runr"`, `terraform init` downloads the
  archive for the platform and checks it against the release's `SHA256SUMS`, signed with the RunR
  release key (key ID `2588846B241BC095`). A configuration that used `brf-tech/runr` changes
  `source` and moves its state once with
  `terraform state replace-provider registry.terraform.io/brf-tech/runr runr.sh/brf-tech/runr`
  ([docs/terraform.md](docs/terraform.md#install)).
- **The GitHub Action downloads the CLI from runr.sh.** `cli-base-url` defaults to
  `https://runr.sh/dl/cli` and `version: latest` is the tag in `<cli-base-url>/latest.txt`; the
  `github-token` input is gone (a workflow that still sets it gets an "unexpected input"
  warning) ([docs/github-action.md](docs/github-action.md#inputs)).
- **Documentation on runr.sh.** The documentation is published at <https://runr.sh/docs/> and the
  security policy at <https://runr.sh/security/>.
- **The Helm chart comes from BRF's public Helm repository.** The chart `runr` is installed
  without credentials: `helm repo add brf-tech https://helm.brf.sh`, then
  `helm upgrade --install runr brf-tech/runr --set domain=…`; the install, upgrade, key rotation
  and white-label examples use the chart `brf-tech/runr` instead of `./helm` of a repository checkout
  ([README](README.md#kubernetes-helm), [docs/upgrade.md](docs/upgrade.md#procedure)).

## [0.5.6] - 2026-10-17

### Changed

- **The CLI's own API token expires.** `runr login --with-password` created its
  `runr-cli@<host>` API token without an expiry. The token now expires 90 days after the login;
  `--token-ttl` sets another lifetime (`30d`, `720h`; `0` or `never` keeps a token without
  expiry). `runr login` and `runr whoami` show when it expires (`token_expires_at` in their JSON
  output, and in the context of the config file); once it has expired, a command the API refuses
  says that the token of the context expired and to log in again, and `runr logout` no longer
  tries to revoke it. A new `runr login` into a context revokes the token an earlier
  `runr login --with-password` created for it, as `runr logout` does: logging in again replaces
  a token from an earlier release with an expiring one. `--expires` of `runr tokens create` and
  `--token-ttl` refuse a fractional day count such as `1.5d` instead of reading it as `1d`
  ([docs/cli.md](docs/cli.md#log-in)).

### Fixed

- **`ci.sh ml-runtimes --push` pushed untested images.** The push built the PyTorch and
  TensorFlow images in a separate builder and pushed them right away; the smoke test, and on a GPU
  host its GPU check, ran for local builds only. `--push` now builds every image into the local
  Docker, smoke-tests it like a local build (with the GPU check where `docker run --gpus all` gives
  a GPU, required with `ML_SMOKE_GPU=1`) and pushes only once every image passed: `docker push` of
  the image the smoke test ran, its image ID checked again before the push. `--push --no-smoke` is
  refused ([docs/runtimes.md](docs/runtimes.md#ml-runtimes)).
- **A deleted user's tokens stayed in the database.** An administrator deleting a user
  (`DELETE /api/users/{id}`) left the user's sign-in sessions and API tokens behind; no request
  could use them without the user, but the rows stayed. Deleting a user — by an administrator or,
  as before, by the user (`DELETE /api/account`) — now deletes every sign-in session and API token
  of the user in the same transaction as the account, then the user's MCP server sessions. In the
  multi-tenant edition, a deleted account's copies in the organizations it belonged to go as well,
  with its groups and role assignments there (only the memberships went before); a member removed
  from an organization loses the API tokens they created in it (these work in that organization
  only; sign-in sessions and other organizations' tokens stay), and deleting an organization
  deletes the tokens created in it. The daily `php artisan tokens:prune` also removes the tokens
  of users that no longer exist and, in the multi-tenant edition, the tokens created in an
  organization that no longer exists or whose user is no longer a member (a platform
  administrator's token stays), and the organizations' copies and role rows of users that no
  longer exist; an organization whose schema is missing is skipped. The rows earlier deletions
  left go after the upgrade without any action
  ([docs/security.md](docs/security.md#sign-in-sessions-and-api-tokens)).

## [0.5.5] - 2026-10-17

### Security

- **Signing out did not end the session.** From 0.3.0 to 0.5.4, `POST /api/auth/logout` answered
  "Logged out." and deleted the session's token, but the API's session tracking (client address,
  user agent and device of a token), which runs after every request, saved the deleted token
  again: the token stayed valid. The same kept the sign-in session alive that
  `runr login --with-password` ends once it has created its API token. The session tracking now
  updates the token's row and never re-creates it, and writes only when the address, user agent
  or device changed. Revoking sessions, deleting API tokens and disabling or deleting the account
  deleted their tokens as intended; tests now cover each of these paths.
- **Sign-in sessions expire.** A sign-in session — a password sign-in of the console or the CLI,
  an SSO sign-in, a self-service signup, an accepted invitation — ends 30 days after the sign-in
  (`RUNR_LOGIN_TOKEN_TTL_DAYS`, Helm value `auth.loginTokenTtlDays`; `0` keeps sessions until
  they are signed out). They had no expiry before. The lifetime counts from the sign-in for the
  sessions that exist at the upgrade as well: those older than 30 days end with the upgrade,
  among them the sessions that were signed out but stayed valid, and their users sign in again.
  Operators need to do nothing. A session signed out during the 30 days before the upgrade stays
  valid until it is 30 days old; its user can end it at once under **Account → Active Sessions**
  (**Revoke**). API tokens keep the expiry they were created with.
- **Changing the password ends the user's other sign-in sessions** (their browsers are signed
  out); the session that changed it and the API tokens stay.
- `php artisan tokens:prune`, scheduled daily, removes the sign-in sessions that ended and API
  tokens 30 days after they expired. API tokens cannot be named `invitation-accept` (nor `api`, as
  before): these names mark sign-in sessions, and the API token list no longer shows the session
  of an accepted invitation, which **Account → Active Sessions** lists
  ([docs/security.md](docs/security.md#sign-in-sessions-and-api-tokens)).

## [0.5.4] - 2026-10-17

### Upgrade notes

- **The last region workloads on RunR's earlier image names move too.** `region:reconcile` (step
  `image-registry`) left the node installers of Kata (with Firecracker's devmapper setup) and
  gVisor and the region's queue Redis on `ghcr.io/runrsh` / `ghcr.io/brf-tech` until their next
  install. Their pod templates now move to `ghcr.io/brf-tech/runr` as well, and their running pods
  keep running: the installer DaemonSets get the new images together with the update strategy
  `OnDelete` (a new pod would re-run the install on its node; kata-deploy's cleanup and reinstall
  restart the container runtime); the queue Redis, whose streams, consumer groups and KV bindings'
  data live in memory only, keeps its pod — the Deployment is paused while its ReplicaSet takes the
  new image, then takes it as well and is resumed without starting a pod. A pod created later (a
  new node, a deleted or evicted pod) starts from `ghcr.io/brf-tech/runr`. A gVisor install, a Kata
  install that re-runs kata-deploy and Kata's Firecracker setup apply `RollingUpdate` again; Kata's
  rollout status counts the pinned installer pods by their config hash, and its in-place drop-in
  update keeps them pinned ([docs/upgrade.md](docs/upgrade.md#image-namespace-ghcriobrf-techrunr)).
- **hf-serve 1.1.1.** The defaults move to `ghcr.io/brf-tech/runr/hf-serve:1.1.1` and
  `…:1.1.1-cuda` (`ai.images.hfServe`, `ai.images.hfServeCuda`). Installations that set these
  values, for example to a registry mirror of an air-gapped region, update them and mirror the new
  images.
- **Components on dedicated workers restart once.** `region:reconcile` (step
  `dedicated-worker-toleration`) gives the registry, Prometheus, Loki, Tempo and the data services
  the toleration of the dedicated-worker taint where a pod of them runs on a dedicated worker; that
  pod restarts once, on the same node (below, Fixed). Components on the other nodes keep running
  as they are. `php artisan region:reconcile --dry-run` lists them.
- **`exec-tensorflow:2.21-gpu` is published again under the same tag** (below, Fixed). A function
  on `tensorflow` `2.21-gpu` runs on the new image from its next deploy: Knative resolves the tag
  to the new digest for the new revision. Installations that mirror RunR's images copy the tag
  again.

### Changed

- **hf-serve starts a cached model without the Hub.** In online mode hf-serve asked the Hub at
  every start, also when the pinned commit was already on the model volume, so a start from zero
  depended on the Hub's reachability and latency. After a load of a pinned commit it now records
  the load next to the cached repository (per engine, task and pipeline arguments), and a later
  start with the same settings loads the cached snapshot directly; when that does not load, it
  loads from the Hub as before. A revision that is a branch or tag is still resolved by the Hub at
  each start ([docs/huggingface.md](docs/huggingface.md#online-mode)).
- **The GPU case of `ci.sh test-ai` leaves Docker's default runtime alone.** The Kind worker with
  the GPU gets the driver's files that `nvidia-container-cli list` names, mounted read-only (Kind's
  privileged nodes see the host's `/dev/nvidia*` already), so a host with the NVIDIA driver and
  Container Toolkit runs the case as it is configured. Where the NVIDIA runtime is Docker's default
  runtime and accepts device requests as volume mounts, the worker gets the GPU that way, as
  before ([docs/ai.md](docs/ai.md#testing)).
- **The GPU case of `ci.sh test-ai` needs far less disk.** Its check image is the new GPU CaaS
  example (below) instead of CUDA PyTorch (3.3 GB compressed), it goes into the Kind GPU worker
  only instead of every node, and the host's copy is removed once the worker has it. `kind load`
  writes its temporary archives to `RUNR_E2E_TMPDIR` (default `/var/tmp`) instead of `/tmp`,
  which is a RAM-backed tmpfs on some hosts. Before the cluster is created the suite adds up what
  the run writes, from the built images' sizes and per Kind node, plus `RUNR_E2E_GPU_RESERVE_GB`
  (default 12) for the rest; it does not start when Docker's root has less free and prints both
  figures. This replaces the fixed `RUNR_E2E_GPU_MIN_FREE_GB`. The case now also asserts that the
  container ran the kernel on the GPU and returned the CPU's result
  ([docs/ai.md](docs/ai.md#testing)).
- **The GPU CaaS example runs a CUDA kernel in a 43 MB image.** `examples/ai/gpu-caas` was CUDA
  PyTorch with a small inference: 3.3 GB compressed, about 9 GB unpacked. It is now Python's slim
  image with a standard-library server that runs a SAXPY kernel through the CUDA driver API (the
  driver compiles the kernel's PTX for its GPU, so no CUDA toolkit is in the image) and compares
  the result with the CPU's: 43 MB compressed, 134 MB unpacked, for NVIDIA GPUs from compute
  capability 5.0 on. The answer keeps `cuda`, `device` and `device_count`; the PyTorch fields give
  way to `compute_capability`, `memory_mb`, `driver_cuda_version`, `computed_on`, `checksum`,
  `expected_checksum` and `result_ok` ([examples/ai/gpu-caas](examples/ai/gpu-caas/README.md)).
- **`ci.sh ml-runtimes` smoke-tests the `-gpu` images on the GPU.** On a host where `docker run
  --gpus all` gives containers a GPU, the handler of a `-gpu` image's smoke test runs with it, over
  HTTP and as a scheduled run: in both, the framework has to see the GPU and run a mean and a
  matrix product on it. Without a GPU the CUDA build is checked as before and the output says the
  GPU check was skipped; `ML_SMOKE_GPU=1` requires it, `ML_SMOKE_GPU=0` leaves it out
  ([docs/runtimes.md](docs/runtimes.md#ml-runtimes)).
- **Pinned helm and kubectl in the API image.** The image took kubectl from the latest stable
  Kubernetes release at build time, so two builds of one tag could differ. helm v3.16.0 and kubectl
  v1.35.9 are now pinned and checked against their published SHA-256 for amd64 and arm64, and the
  downloads retry on network errors.
- **`ci.sh test-ai` keeps etcd in memory.** The suite sets `KIND_ETCD_IN_MEMORY=1` unless it is
  set: etcd keeps its data on the Kind control-plane's tmpfs. On a GPU host whose disk other jobs
  kept busy, the region setup's Knative Operator install failed with `etcdserver: request timed
  out`; with etcd in memory the same run got past it. `KIND_ETCD_IN_MEMORY=0` puts etcd on the
  disk again ([docs/ai.md](docs/ai.md#testing)).

- **Organization quotas overcommit scale-to-zero workloads.** In the multi-tenant edition an
  organization's CPU and memory quota on a region splits into the same two budgets as the region
  capacity, with the region's overcommit factors. Always-on projects and model services, the Kata
  pod overhead, queue consumers, sandboxes and warm pool pods count in full against the quota; one
  pod of every scale-to-zero project, model service and git deploy preview counts against quota ×
  the factor (CPU 3, memory 1.5 by default) minus what the always-on part holds. A factor of 1 on
  a region keeps the quotas there hard. The quotas on a region still add up to at most its
  capacity, stored quotas stay as they are, and billing is unchanged. A refusal (422) names the
  budget that is used up (`budget`: `always_on` or `faas`) and what it has free
  ([docs/architecture.md](docs/architecture.md#region-capacity-and-admission)).

### Fixed

- **The region registry stopped after a worker selection.** Zot installed before a region's
  workers were selected had no toleration of the dedicated-worker taint. Its node-bound volume
  (local-path, k3s's default class) keeps it on its node, and when that node was selected as a
  dedicated worker (`runr.dev/dedicated=projects:NoSchedule`), the next Zot pod — after the
  restart of `region:reconcile`'s `registry-sync-config`, the roll of `image-registry`,
  `registry:rolling-restart` or a Helm upgrade — stayed Pending ("untolerated taint" and "volume
  node affinity conflict"): builds failed and the registry pre-warm Job gave up after 600 s.
  Prometheus, Loki, Tempo and the data services (KV Redis, Garage, the CloudNativePG clusters) had
  the same exposure. All of them now tolerate the taint and prefer the nodes that are not
  dedicated workers. The worker selection adds the toleration to such a component whose pod runs
  on a selected node, and `region:reconcile` (new first step `dedicated-worker-toleration`) to
  those on any dedicated worker; a pod Pending on the taint is replaced, as a StatefulSet does not
  replace a pod that never became ready. The registry restart of `registry-sync-config` carries
  the toleration as well ([docs/architecture.md](docs/architecture.md#dedicated-workers)).
- **The registry and Knative after deselecting workers.** A registry installed while dedicated
  workers were selected, its node mirror and Knative's system deployments select the workers by
  the label `runr.dev/dedicated=projects`. Once the last worker was deselected their next pods found
  no node, and a registry whose volume sat on a deselected node could not start there again. The
  worker selection and `region:reconcile` (step `dedicated-worker-toleration`) now move such a
  registry to any node (the toleration kept, the other nodes preferred, a Pending pod replaced),
  apply the node mirror for every node once no worker is left — projects then run on any node and
  pull from the registry there — and drop Knative's nodeSelector
  ([docs/architecture.md](docs/architecture.md#dedicated-workers)).
- **RunR's own pods on a node selected as a dedicated worker.** When RunR runs in a cluster it
  also uses as a region, the node holding PostgreSQL's volume or the API storage could be selected
  as a dedicated worker; its taint then kept the next pods (a restart, an upgrade) from starting
  there. Every pod of the chart — the Deployments and the migration and bootstrap hook Jobs — now
  tolerates `runr.dev/dedicated=projects:NoSchedule` and prefers the nodes that are not dedicated
  workers (`placement.tolerateDedicatedWorkers`, on by default). New values
  `placement.tolerations`, `placement.affinity` and `placement.nodeSelector` apply to every pod; the
  chart adds its toleration, preference and the API storage colocation to them
  ([docs/architecture.md](docs/architecture.md#control-plane)).
- **The registry pre-warm Job's log.** While the registry did not answer, every try printed
  wget's "can't connect to remote host: Connection refused". The Job now prints one line per wait
  instead: `waiting for registry http://… to answer: next try in 4 s (2 s waited)`.
- **Executor images in the Kind cluster of `ci.sh test`.** The preload of the default executor
  images matched `brf-tech/…` and loaded none since RunR's images moved to
  `ghcr.io/brf-tech/runr/`. It loads the default general runtimes from there again; an ML runtime
  (several GB) is pulled by the node that runs it.
- **The TensorFlow `-gpu` runtime ran on the CPU.** In `exec-tensorflow:2.21-gpu` TensorFlow
  registered no GPU and ran every function on the CPU, without an error: it opens the CUDA
  libraries of NVIDIA's pip packages by soname through its RUNPATH, which lacks the cuSOLVER
  directory, and one library it cannot open makes it skip all GPUs. The image now puts every
  `site-packages/nvidia/*/lib` directory into the dynamic loader's cache, whatever the entrypoint
  and a function's own `LD_LIBRARY_PATH`, and its build fails unless every library there is in the
  cache.
- **diffusers model services and `dtype`.** hf-serve passed the dtype to diffusers as
  `torch_dtype`, which diffusers 0.41 deprecates (removed in 1.0); with a `dtype` in
  `RUNR_PIPELINE_KWARGS` the load failed, because diffusers refuses both arguments together, and a
  dtype name such as `"float16"` fell back to float32. hf-serve 1.1.1 passes `dtype` to both
  engines, takes `torch_dtype` of the pipeline arguments as `dtype`, and gives diffusers the torch
  dtype a name stands for.
- **pip's dependency conflict in hf-serve's GPU image.** The PyTorch base image ships `spin` 0.18,
  a developer CLI that requires click below 8.4, and hf-serve's server brings click 8.5: the build
  printed pip's dependency-conflict error and `pip check` failed in the image. hf-serve
  1.1.1-cuda leaves `spin` out, which nothing in the image uses, and its build fails unless
  `pip check` passes.
- **GPU nodes of `ci.sh test-ai`.** Every Kind node counted as a GPU node, because all of them are
  privileged and see `/dev/nvidia0`: on a GPU host each would have got the NVIDIA Container
  Toolkit, the GPU label and a `--gpu-node` argument. Only Kind nodes where `nvidia-smi -L` lists a
  GPU count now — the worker with the driver.
- **Resource bars on overcommitted regions.** The projects page added up the projects'
  reservations in the browser against the region capacity and capped the bar at 100 %, so a region
  holding more scale-to-zero reservations than its capacity looked full while it still admitted
  projects. "Total Resources" now shows the always-on and the FaaS overcommit budget per resource
  as the API admits against them, summed over the regions and per region, with the bars of
  Settings → Regions → (region) → Workers. In an organization a platform region shows the
  organization's quota there, split the same way (`capacity` with `capacity_scope: "quota"` in
  `GET /api/settings`), and no longer shows as unreachable.
- **An organization's quota usage seen by platform administrators.** Tenants → (tenant) → Quotas
  (`GET /api/tenants/{id}/regions`) counted the projects of the organization the administrator
  was working in, not those of the organization opened. It now counts in that organization's own
  schema and shows its quota's always-on and FaaS overcommit budgets (`capacity` per region) with
  the bars of the projects page.
- **Quota refusals for a resource without a quota.** A 422 of the organization quota (and a
  sandbox's `quota_region`) reported 9223372036854775807 as what was left of a resource with quota
  0. It now gives `remaining_cpu_m` / `remaining_memory_mb` only for the resources with a quota and
  names only the resource that is short.
- **The disk check of `ci.sh test-ai` counts the whole run.** It counted hf-serve and the GPU image
  per Kind node plus a 12 GiB reserve, and let a GPU run start with 23 GiB free under Docker's
  root; that run was down to 5 GiB free by its first AI case, with the other AI cases, GPU support
  and the GPU case still ahead. The check now also counts each Kind node's own data (1 GiB:
  Kubernetes' images, copied into every node), the Compose stack (3 GiB) and, unless
  `KIND_PRELOAD=0`, the preloaded images on every node and the host (1.4 GiB each): about 35 GiB
  with the default Kind cluster, 25 GiB with `KIND_WORKERS=1 KIND_PRELOAD=0`. Its refusal names
  both options ([docs/ai.md](docs/ai.md#testing)).
- **A Kind e2e run stopped from outside cleans up.** `ci.sh test`, its suites (`test-ai`,
  `test-runtimes`, …) and `test-helm-upgrade` remove their Kind cluster, Compose project, Kind lock
  and Cloud Provider KIND also when they are stopped: on TERM or HUP at once, also in the middle of
  a step, and on INT (Ctrl-C) once the running command has stopped. A run whose output pipe was
  stopped together with it ended at the cleanup's first line and left all of them behind; INT to
  the run while a command ran could let it go on with its next step; a stop during `docker compose
  up` left the Compose stack; and a run stopped while it waited for the Kind lock deleted the
  cluster of the same name that the lock holder used. The cleanup touches only what the run
  created, and when the run's output is gone it writes to `${TMPDIR:-/tmp}/<cluster>-cleanup.log`
  ([docs/development.md](docs/development.md#how-it-works)).
- **A short hiccup of the Kubernetes API server no longer fails the Knative Operator install.**
  The operator's Helm install failed on its first error, and with it the region setup: on a
  cluster whose etcd could not keep up with a busy disk, Helm got `etcdserver: request timed out`
  once and the region had no Knative. The install is now tried again on transient errors of the
  API server — etcd timeouts and leader changes, 5xx and 429 answers, timeouts and dropped
  connections on the way to it — five tries in all, 2, 4, 8 and 16 s apart, each retry in the
  operator's install log. Any other failure, Helm's own `--wait` running out included, fails the
  install at once, as before.

## [0.5.3] - 2026-10-16

### Fixed

- **Tempo crash loop on storage that ignores fsGroup.** With a storage driver that does not apply
  the pod's `fsGroup`, Tempo's volume stayed owned by root and Tempo restarted with
  "mkdir /var/tempo/traces: permission denied". Tempo gets an init container `fix-permissions`
  that hands `/var/tempo` to Tempo's user, as Loki's does; `region:reconcile` (step `tracing`) adds
  it to installed backends. A Tempo pod that crash-loops with the earlier template kept its
  StatefulSet from rolling: the step replaces it, and the new pod starts with the init container.
- **Tracing requests on small regions.** On a single-node region, Knative's own pods stayed
  Pending on "Insufficient cpu" once the tracing backend was installed. The collector and Tempo
  request 50m CPU each (100m before), in line with the other region add-ons; the limits are
  unchanged. `region:reconcile` (step `tracing`) moves installed backends to these requests, and
  the region capacity subtracts them like every other platform pod.
- **Registry pre-warm while the registry restarts.** The `registry-prewarm` Job started before
  `image-registry` rolled the registry, so every image failed with "Connection refused", and the
  failed Job kept later reconciles from starting another one for 24 hours. The step now runs last
  in `region:reconcile`; the Job waits for the registry to answer before it starts and before each
  further try of an image (three tries, backing off), a failed pod is retried twice, and a Job
  that failed is deleted and started again by the next reconcile.
- **`runr regions` showed 0 projects to a tenant.** A platform region assigned to a tenant
  answered without `project_count` in the tenant's view (`GET /api/knative-regions`). It now counts
  the tenant's own projects there.

## [0.5.2] - 2026-10-16

### Upgrade notes

- **Image namespace ghcr.io/brf-tech/runr.** Stored image references move automatically, from
  0.5.1 (`ghcr.io/brf-tech/<name>`) and from earlier releases (`ghcr.io/runrsh/<name>`): Helm
  values (`runr.image`), the database (migration `move_images_to_runr_namespace`, tenant migration
  `0055`, `images:move-registry` in the bootstrap job) and the region workloads (`region:reconcile`).
  A registry of your own that holds copies of RunR's images under their paths needs them under the
  new paths: copy them from `ghcr.io/brf-tech/runr`
  ([docs/upgrade.md](docs/upgrade.md#image-namespace-ghcriobrf-techrunr)).
- **ML runtime versions.** PyTorch 2.2 / 2.3 and TensorFlow 2.15 / 2.16 and their `-gpu`
  versions, listed by the local and demo seeders only and never published as images, leave the
  catalog (migration `retire_unpublished_ml_runtime_versions`). Projects that name one keep
  running as deployed and take the runtime's default version at their next deploy.
  `executor:sync` lists the published versions: PyTorch `2.14` / `2.14-gpu`, TensorFlow `2.21` /
  `2.21-gpu` ([docs/runtimes.md](docs/runtimes.md#ml-runtimes)).
- **Tracing backend on existing regions.** `region:reconcile` (step `tracing`) installs the
  OpenTelemetry Collector and Grafana Tempo where tracing is enabled and the backend is missing —
  the region's own tracing mode, or for a region without one (Knative Serving installed by 0.4.x)
  the platform's `observability.tracing.defaultMode` while `installWithServing` is on — and moves
  installed backends to collector chart 0.176.0 (collector 0.162.0) and Tempo chart 3.2.0
  (Tempo 3.1.0); the collector and Tempo pods roll once. Tempo chart 3.2.0 applies
  `tempoRetentionHours` (default 72 hours) to the deletion of old traces, which chart 3.1.0 left
  at Tempo's 14 days. To keep tracing off on regions without their own setting, set
  `observability.tracing.installWithServing=false` in the upgrade
  ([docs/upgrade.md](docs/upgrade.md#tracing-backend)).

### Added

- **PyTorch and TensorFlow runtime images.** `ghcr.io/brf-tech/runr/exec-pytorch:2.14` (torch 2.14.1,
  torchvision 0.29.1) and `…:2.14-gpu` (the same torch for CUDA 12.6),
  `ghcr.io/brf-tech/runr/exec-tensorflow:2.21` (TensorFlow 2.21.0, CPU build) and `…:2.21-gpu`
  (CUDA 12): Python 3.12 with Keras (TensorFlow), NumPy, SciPy, pandas, Matplotlib, scikit-learn
  and Pillow, on the Python runtime's handler server, for linux/amd64. `runtime/ci.sh ml-runtimes
  [--push] [--list]` builds them; a build fails unless `pip check` passes and the framework and
  its CUDA build match the tag, and a local build answers a tensor-op handler over HTTP and as a
  scheduled run. `executor:sync` reads a `X.Y-gpu` tag as the GPU version of `X.Y`. The node
  image pre-pull and the region registry pre-warm leave the ML runtime images out: the nodes
  their functions run on pull them
  ([docs/runtimes.md](docs/runtimes.md#ml-runtimes)).
- **Billing events, newest first.** `GET /api/billing/events?order=desc` lists the newest events
  first; `before=<event id>` bounds the list as `after=` does, and `meta.next_before` continues a
  newest-first list (`meta.next_after` an oldest-first one) ([docs/billing.md](docs/billing.md)).
  The console's billing settings read their 20 newest events with one request.
- **Registry pre-warm.** `region:reconcile` has the internal registry of each region fetch the base
  images of the release's function builds before a first build needs them (step
  `registry-prewarm`, a Job in the registry namespace) ([docs/runtimes.md](docs/runtimes.md#compiled-languages)).
- **Any sandbox command on a terminal.** `POST /api/sandboxes/{id}/exec/ws` takes `tty`, `cols`
  and `rows`: the command runs on a terminal of that size, so shells, REPLs, editors and pagers
  work as in a terminal window. `{"type":"resize","cols","rows"}` frames change the size,
  `stdin_close` sends Ctrl+D, `TERM` defaults to `xterm-256color`, and the start frame and the
  answer say whether the command has a terminal. Without `tty` the exec WebSocket works as
  before ([docs/sandbox-api.md](docs/sandbox-api.md#exec)).
- **`runr sandbox exec -it`.** `-t` runs the command on a terminal over the exec WebSocket; with
  `-it` on a terminal runr's terminal is raw while the command runs (every key, Ctrl+C included,
  goes to the command), the size follows the window (`SIGWINCH` on Linux and macOS, the console
  size on Windows), the terminal is restored on every way out and runr exits with the command's
  exit code ([docs/cli.md](docs/cli.md#sandboxes)).
- **Exec WebSocket in the sandbox SDKs.** Python `Sandbox.exec_ws()` / `AsyncSandbox.exec_ws()`
  and Node `sandbox.execWs()` / `runCodeWs()` run a command over the exec WebSocket: input goes
  to the command while it runs (`send_stdin` / `sendStdin`, `close_stdin` / `closeStdin`), the
  output arrives as events or through `wait()`, `kill()` stops the command, and with a terminal
  (`tty`, `cols`, `rows`) `resize()` changes its size. The Python SDK depends on `websockets` 13
  or newer; the Node SDK uses the runtime's `WebSocket` (Node.js 22+, Bun, Deno) or its own
  client on Node.js 18 and 20 and keeps no dependencies
  ([sdk/sandbox/python](sdk/sandbox/python/README.md#interactive-commands-the-exec-websocket),
  [sdk/sandbox/node](sdk/sandbox/node/README.md#websocket-exec)).
- **`runr metrics live`** reads what a deployment uses right now — CPU, memory and requests per
  second from the region's Prometheus, with the averages of the last hour (`--quick` without
  them) — for the live deployment or `--deployment`, once or every `--watch` interval, `-o json`
  ([docs/cli.md](docs/cli.md#recorded-metrics)).
- **Region lifecycle in the CLI.** `runr regions` creates, checks, changes and removes regions
  (`create`, `check`, `get`, `set`, `rm`, `status`), saves a region's kubeconfig to a file only
  its owner can read (`kubeconfig`; `--stdout` prints it) and chooses the worker nodes projects
  run on (`nodes set`). It installs, adopts and removes Knative (`knative install|uninstall|detect`)
  and manages the queue Redis, the image registry (also regions created with one, and checks of
  their storage), the move to another cluster (`migration`), function-domain TLS, Skupper sites,
  the FaaS overcommit factors (`capacity`), demo projects and WebAssembly support
  (`wasm [status]|install|uninstall`). Kubeconfigs, passwords and keys come from files or stdin
  and never reach the trace; removals ask for confirmation unless `--yes`, and `--wait` follows
  background installs ([docs/cli.md](docs/cli.md#regions)).
- **Mirror fallback for Docker Hub's pull limit.** When Docker Hub refuses a copy, `ci.sh
  mirror-reqs` and `mirror-bases` copy the image from `mirror.gcr.io` or
  `public.ecr.aws/docker`, by the digest Docker Hub reports for it, so the copy is the same image
  (`REQS_UPSTREAMS` sets the sources and their order). `mirror-reqs` takes image names to copy
  only those and exits 1 when a copy fails.

### Changed

- **Build deadline.** Function build Jobs (Kaniko and WebAssembly toolchain builds) run up to
  `BUILD_DEADLINE_SECONDS`, 900 s by default; the build checks and the stale-build check follow
  it. A build that runs out of time while the region registry still fetches its base image is
  built once more, and its `build_error` says so meanwhile.
- **Sandbox start.** A starting sandbox pod is read every 150 ms (`SANDBOX_START_POLL_MS`, 100–200)
  for its first 30 s and once a second after that, so a sandbox is reported running at most one
  interval after its pod runs ([docs/sandbox-api.md](docs/sandbox-api.md)).
- **Warm pools.** A pod a pool refill starts can be claimed as soon as it runs; the next per-minute
  refill no longer has to mark it warm first ([docs/sandbox-api.md](docs/sandbox-api.md#warm-pool)).
- **Model docblocks.** A `migrate` runs `ide-helper:models --write --reset` only with `APP_ENV=local`
  and `IDE_HELPER_POST_MIGRATE=true`, so migrations in test containers leave the models alone
  ([docs/development.md](docs/development.md#migrations)).
- **Tracing images from the reqs mirror.** The region collector and Tempo run
  `ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib:0.162.0` and
  `ghcr.io/brf-tech/runr/reqs/tempo:3.1.0`, RunR's copies of the Docker Hub images; the Helm values
  `observability.tracing.collectorImage` / `tempoImage` point them at a registry of your own.
  `collectorChartVersion` / `tempoChartVersion` are empty by default: the versions RunR pins.
- **Build base images from the reqs mirror.** The WebAssembly build images, the Spin shim build,
  hf-serve's GPU image and the GPU images of the pytorch and tensorflow runtimes build on
  `ghcr.io/brf-tech/runr/reqs` copies of their Docker Hub bases (TinyGo, Node.js, Python, Rust,
  PyTorch, CUDA). `ci/check-dockerfiles.sh` (CI job `check:build-scripts`) fails on a Docker Hub
  base image.
- **Image builds pull their base images.** Every image build of `runtime/ci.sh` (local and
  `--push`, the release tars of `build-deploy`) and of `compose/build.sh` / `compose/deploy.sh`
  runs with `--pull`, so an older copy of a base image in the build host's Docker is never built
  on.
- **Runtime versions from the image registry.** `executor:sync` lists the versions of the
  built-in runtimes from the tags of their images under `ghcr.io/brf-tech/runr` (the registry's
  OCI distribution API, read anonymously) instead of Docker Hub's `runrsh/exec-*` repositories, so
  every version published in RunR's image namespace reaches the catalog, PyTorch and TensorFlow
  included. `versionDiscovery.repository` names the whole image repository
  (`ghcr.io/brf-tech/runr/exec-python`); one without a registry host is on Docker Hub
  ([docs/runtimes.md](docs/runtimes.md#versions)).
- **Image namespace ghcr.io/brf-tech/runr.** RunR publishes every image under
  `ghcr.io/brf-tech/runr`, so the organization's other packages never share a name with RunR's:
  `reqs/<image>`, `exec-<runtime>`, `hf-serve`, `image-upload`, `containerd-shim-spin` keep their
  names there, `runr-wrapper`, `runr-agent`, `runr-queue-consumer` and `runr-supply-chain` become
  `wrapper`, `agent`, `queue-consumer` and `supply-chain`, and `runr/api` and `runr/ui` stay where
  they are. `runtime/ci.sh` pushes to `REGISTRY=ghcr.io/brf-tech/runr` by default, `runr dev`
  pulls from it, and the system images policy passes `ghcr.io/brf-tech/runr/**`.

### Security

- **ML runtimes.** `pip-audit` finds no known vulnerability in the packages of the four ML runtime
  images. The PyTorch 2.3 package set they replace had 20 advisories in torch (among them
  CVE-2025-32434) and the TensorFlow 2.16 set one in protobuf that TensorFlow 2.16's
  `protobuf < 5` pin cannot take the fix of; pip and setuptools are upgraded to 26.2.1 and 84.0.0.
  The PyTorch weights example (`examples/ai/hf-python/weights`) takes transformers 5.20.

### Fixed

- **Registry leftovers.** Deleting a runtime deletes its images (`runr-runtimes/<id>`) from the
  registries of the regions it was built in. Builds no longer open an upload before they are
  built, so a failed build leaves no empty repository, and a daily job hands build repositories
  without a tag to Zot's garbage collection, which removes them.
- **Sandbox API e2e.** The Kata guest-size check creates its sandboxes with `cpu` and `memory`, so
  they have the size whose vCPUs it checks.
- **Upgrade hook while PostgreSQL restarts.** An upgrade that restarts the control plane's
  PostgreSQL (a new image, or its volume moving to another node) failed in the post-upgrade
  `runr-bootstrap` hook, which started before the database accepted connections. The hook now
  waits up to 4 minutes for the database, and its deadline is 10 minutes.
- **WebAssembly build images on a docker-container builder.** A buildx builder with the
  docker-container driver (`ci.sh wasm --push`, CI) gives every build step
  `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=unix:///dev/otel-grpc.sock`, and Spin 4.1 exited with
  "Failed to initialize telemetry: invalid URI". The build steps of `runtime/wasm-*` run Spin with
  `OTEL_SDK_DISABLED=true` (a build argument: the images do not carry it).
- **`--push` exited 1 after a successful push.** `ci.sh build`, `queue-consumer`, `wrapper`,
  `build-agent`, `test-image` and `supply-chain` ended with a test that failed when pushing; they
  exit 0 once the push is done.

## [0.5.1] - 2026-10-16

### Upgrade notes

- **Region capacity admission.** Scale-to-zero projects and model services no longer hold the
  region capacity: each counts one pod against a FaaS overcommit budget of capacity × 3 (CPU) and
  × 1.5 (memory), set per region. Every existing region gets these defaults; a factor of 1 keeps
  every reservation against the capacity. Sandbox and model service pods are no longer subtracted
  as platform pods, so a region's capacity rises by what they request at its next status check
  ([docs/architecture.md](docs/architecture.md#region-capacity-and-admission)).
- **Image namespace `ghcr.io/brf-tech`.** RunR's images are published under `ghcr.io/brf-tech` —
  the same names, tags and digests as under `ghcr.io/runrsh` up to 0.5.0 — and the upgrade moves
  every reference an existing installation holds (exact prefix only; other registries stay):
  image values of the Helm release, also the ones `--reuse-values` keeps (chart helper
  `runr.image`); stored references in the central and every tenant schema (migration
  `move_images_to_brf_tech_registry`, `php artisan images:move-registry` in the bootstrap job);
  and the workloads in the regions (`region:reconcile`). Knative Services get a new revision that
  takes the traffic once it is ready; the other workloads roll once. The node installers of Kata
  and gVisor and the region's in-memory queue Redis keep running as they are, a restart would cost
  more than the move ([docs/upgrade.md](docs/upgrade.md#image-namespace-ghcriobrf-tech)).
- **Terraform.** The provider's address is `brf-tech/runr`: change `source` and run
  `terraform state replace-provider registry.terraform.io/runrsh/runr registry.terraform.io/brf-tech/runr`
  once.
- **Model images.** The defaults move to TEI 1.9.4 (`ai.images.teiCpu` `…:cpu-1.9.4`,
  `ai.images.teiGpu` `…:{arch}1.9.4`) and hf-serve 1.1.0 (`ghcr.io/brf-tech/hf-serve:1.1.0`,
  `…:1.1.0-cuda`). Installations that set these values, for example to a registry mirror of an
  air-gapped region, update them and mirror the new images: TEI 1.5 can no longer download
  models from the Hugging Face Hub.

### Added

- **Why a model service does not start.** While a model service deploys, RunR reads its pods
  and shows Kubernetes' reason next to the status — `CrashLoopBackOff` with the engine's last
  output, `OOMKilled` with the memory limit, `ImagePullBackOff` / `ErrImagePull` with the image
  and the registry's answer, `Unschedulable` with the scheduler's message — in the console, the
  API (`status_reason`, details in `status_message`) and the MCP tools
  ([docs/huggingface.md](docs/huggingface.md#status-reasons)).
- **Model image pre-pull.** Setting up a region's model storage pulls the hf-serve image onto
  the nodes projects run on, and creating or deploying a model service pulls its engine image
  onto the nodes it can run on (GPU nodes for GPU services), so a first start no longer waits
  for a half-gigabyte image ([docs/huggingface.md](docs/huggingface.md#image-pre-pull)).
- **TEI on Blackwell GPUs.** Compute capability 10.0, 12.0 and 12.1 get TEI's `100-`, `120-`
  and `121-` images; any other GPU gets `cuda-`, which picks its kernels when it starts. TEI
  services answer with their served name in `/v1/embeddings`.

### Changed

- **Region capacity: always-on and FaaS overcommit budgets.** Always-on projects
  (`min_scale` ≥ 1 or `scale_to_zero: false`) reserve (size + queue-proxy + Kata overhead) ×
  `max(1, min_scale)` against the region capacity without overcommit, together with the Kata pod
  overhead of scale-to-zero projects, queue consumers, always-on model services, sandboxes and
  warm pool pods. Scale-to-zero projects and model services count one pod (size + queue-proxy)
  against capacity × the region's overcommit factor, minus the always-on reservations.
  Refusals name the budget and what is still free.
- **Overcommit settings.** `GET` / `PUT /api/knative-regions/{id}/capacity` (`regions.view` /
  `regions.manage`) shows both budgets and sets `faas_cpu_overcommit` and
  `faas_memory_overcommit` (1–10); Settings → Regions → (region) → Workers shows and sets them.
  The region resource carries the factors and a `capacity` object; `used_cpu_m` /
  `used_memory_mb` now include the sandboxes and may exceed the total on an overcommitted region.
  Capacity alerts measure the fuller budget. Audit event `region.capacity.update`.
- **Sandbox API.** A sandbox that needs a new pod, and every warm pool pod, needs room on the
  region's always-on budget; otherwise the create is refused with `quota_capacity`.
- **Repository.** The repository is [github.com/brf-tech/runr](https://github.com/brf-tech/runr): Go module paths
  `github.com/brf-tech/runr/…`, the GitHub Action `brf-tech/runr/actions/deploy`, CLI release
  downloads and update checks, the Terraform provider `brf-tech/runr` (mirror repository
  `brf-tech/terraform-provider-runr`), chart and SDK metadata.
- **Images.** Images under `ghcr.io/brf-tech`: the API and UI, the runtime and WebAssembly build images, the
  wrapper, region agent, queue consumer, image-upload, supply-chain, hf-serve and Spin shim images
  and the `reqs` mirror; the CLI's `runr dev` and every region component use them.
- **Region reconcile.** `region:reconcile` has two more steps: `admission-policy` brings the supply-chain admission
  policy of a region to this release's image globs, `image-registry` moves region workloads to
  RunR's image namespace; `traefik-image` also moves a Traefik of another version from
  `ghcr.io/runrsh`, and `wasm-sandbox` re-runs the WebAssembly installer for its shim image.
- **Model sizes count the weights an engine loads.** A Hub repository that holds one model in
  several formats (safetensors, PyTorch, TensorFlow, Flax, ONNX, OpenVINO) counts one of them:
  a 268 MB model gets 1536 MiB on CPU instead of 3328 MiB. Hub models already in the library are
  sized again the next time a one-click deploy reuses them
  ([docs/huggingface.md](docs/huggingface.md#engines-per-task)).
- **Model service threads follow the CPU limit.** Model service containers get
  `OMP_NUM_THREADS` / `MKL_NUM_THREADS` = the CPU limit rounded up, and hf-serve reads its cgroup
  quota when they are missing: a 1-CPU hf-serve service no longer runs one PyTorch thread per
  node CPU.
- **hf-serve 1.1.0 restarts on a failed model load.** It writes the reason to the termination
  log and exits, so the pod retries with back-off and the service status shows why, instead of
  staying unready.

### Security

- Dependency updates for published advisories: the console's code editor takes monaco-editor 0.57
  (its bundled DOMPurify 3.4.15) and the console builds with Tailwind CSS 4 (it looks as before;
  braces, micromatch, chokidar, fast-glob and postcss-selector-parser leave the build tooling), the
  workflow SDK tests with Vitest 4 (development needs npm 11), every Go module requires go1.26.9
  (GO-2026-6603 … 6617 in net/http, HTTP/2, net/textproto and crypto/tls) and the CLI takes
  `golang.org/x/sys` 0.48. `npm audit` reports one low advisory: DOMPurify's `IN_PLACE` mode in
  the copy bundled with Monaco, which does not use that mode. With Tailwind CSS 4 the console
  needs Safari 16.4, Chrome 111, Firefox 128 or newer.
- **hf-serve 1.1.0** (the `transformers` / `diffusers` engine and the mirror and upload helpers):
  PyTorch 2.5.1 → 2.14.1 (19 advisories, among them CVE-2025-32434), transformers 4.49 → 5.20
  (24), Starlette 0.52 → 1.8 with FastAPI 0.143 (5), huggingface_hub 1.33, pip 26.2 and
  setuptools 84 in the image (8); the GPU image is built on PyTorch 2.14.1 with CUDA 12.6 and
  diffusers 0.41. `pip-audit` finds no known vulnerability in the new image; hf-serve's tests run
  on pytest 9.1.

### Fixed

- **Kata overhead of the deployed RuntimeClass.** Capacity counted a project's Kata pod overhead
  from the region's current sandbox mapping; it now follows the RuntimeClass the project (or model
  service) was last deployed with, so a mapping change counts from each workload's next deploy.
- **Model services and sandboxes counted twice.** The capacity refresh subtracted model service
  and sandbox pods as platform pods while admission counted them as well (a region showed 3800m
  used of 3405m). They are counted once now, from their records.
- **TEI embedding services crash-looped** with "relative URL without a base": the Hugging Face
  Hub answers downloads with a relative redirect that TEI 1.5 cannot follow. RunR runs TEI 1.9.4.

## [0.5.0] - 2026-10-15

### Upgrade notes

- **Upgrading.** Upgrade with `helm upgrade` of the running release (`--reuse-values` works), never
  uninstall and install again; back up the database and do not edit projects or deploy while the
  upgrade rolls out ([docs/upgrade.md](docs/upgrade.md)). `ci.sh test-helm-upgrade` repeats the
  upgrade from `main` on a Kind cluster.
- **Roles and permissions.** In the single-tenant edition the administrator account becomes Owner
  and every other user Admin (0.4.x had no permission checks); in the multi-tenant edition tenant
  `owner`, `admin` and `viewer` map to the built-in roles of the same name, and tenant `member`
  maps to a custom role **Member**, created in every tenant that has members: what members could
  do before (the Developer permissions without `models.deploy` and the hosted MCP server
  permissions, plus `settings.manage` and `sso.manage`). Tenant viewers, who could change and
  deploy projects in 0.4.x, are read-only now. API tokens with `*` are unchanged; tokens with
  0.4.x scopes (`projects:read`, `deployments:write`, …) are translated to permissions, also when
  an older client still sends them — and are enforced now, so a `projects:read` token no longer
  deploys. Users the previous version creates while the upgrade rolls out get the default role
  from the bootstrap job (`php artisan rbac:assign-missing`). See [docs/permissions.md](docs/permissions.md#upgrade-from-04x).
- **Secrets at rest.** The upgrade migrations encrypt every stored secret in the central and every
  tenant schema with `APP_KEY`. Keep a copy of the key — without it the encrypted columns cannot
  be read. Rows written in plaintext by an older pod during the rollout are encrypted on their
  next save, or run `php artisan secrets:encrypt` once the rollout is done; after a key change,
  `php artisan secrets:encrypt --rotate` re-encrypts with the new key
  ([docs/security.md](docs/security.md#encryption-at-rest)).
- **Supply chain.** Regions that exist at the upgrade keep SBOM generation and scanning off, so an
  upgrade never starts Jobs whose tools image a region cannot pull; enable them per region in the
  region's Supply chain tab. New regions start with both on
  ([docs/supply-chain.md](docs/supply-chain.md)).
- **Private broadcast channels.** Every WebSocket channel is private. A custom client that
  subscribes to `platform`, `notifications`, `deployment.{projectId}` or the ticket channels must
  authorize the subscription with `POST /api/broadcasting/auth` and the user's API token; a
  user's own notifications arrive on `notifications.{userId}`
  ([docs/security.md](docs/security.md#real-time-channels)).
- **SSO code exchange.** OAuth, OIDC and SAML sign-ins redirect to
  `/oauth/callback?code=<one-time code>` instead of carrying the API token in the URL. A custom
  front end exchanges the code (valid for 60 seconds, one use) with `POST /api/oauth/exchange`
  ([docs/security.md](docs/security.md#sso-sign-in-handoff)).
- **New endpoints on the console domain.** The Helm gateway and the Compose Caddyfile route
  `/git/*`, `/mcp`, `/mcp/*`, `/oauth2/*` and `/.well-known/oauth-*` to the API, streamed. A proxy
  of your own in front of the console must do the same, without buffering or compressing them
  (with ingress-nginx: `proxy-buffering: "off"` and a `proxy-read-timeout` of at least 900 through
  `ingress.annotations`). The MCP server and its OAuth server, including dynamic client
  registration, are on by default (`MCP_ENABLED`, `MCP_OAUTH_ENABLED`,
  `MCP_OAUTH_OPEN_REGISTRATION`); `gitDeploy.enabled: false` turns the git remotes and provider
  webhooks off.
- **Per-region features.** GPU support, WebAssembly support and the data services of bindings
  are installed per region when you need them; KV, object and Postgres bindings are refused in a
  region until its data service is ready. Custom runtimes stay off until a holder of
  `runtimes.manage` switches **Custom Runtimes** on (Settings → Platform). Existing tenants get
  no GPU limit (`gpu_quota` empty).
- **Triggers.** The `workflow` trigger type runs durable workflows; the `event` trigger type is
  rejected by validation (422). Existing rows of either type stay readable and deletable.
- **Billing (multi-tenant).** Payment records become charges (`turn_payment_records_into_charges`):
  the billing service's invoice id is kept as `external_ref`, cancelled records are void, records
  of an ended period are closed with their invoiced (else recorded) amount. RunR no longer calls a
  billing service to create invoices, poll payment status or refund: your billing system reads
  closed charges through the billing API or the `billing.period.closed` event and invoices them.
  `billing.gatewayUrl` and `billing.gatewayToken` are not used any more; `billing.webhookSecret`
  now signs the billing events sent to the new `billing.webhookUrl`. `/api/tenants/{tenant}/payments`
  and `/api/tenant/payments` give way to `/api/billing/charges` and `/api/tenant/billing`
  ([docs/upgrade.md](docs/upgrade.md#billing-multi-tenant)).
- **Defaults.** The first administrator's default e-mail is `admin@example.com` (`ADMIN_EMAIL`,
  Helm `admin.email`); existing installs keep theirs. `api/.env.docker` ships without an
  `APP_KEY` (`scripts/devready.sh` generates one) and `compose/deploy.sh` takes its target from
  `DEPLOY_SERVER`, `DEPLOY_USER` and `DEPLOY_DIR`.

### Added

#### Functions and runtimes

- Pluggable runtimes ([docs/runtimes.md](docs/runtimes.md)): every language is a plugin — a
  `runtime.yaml` manifest plus an image — that runs in `http`, `exec` or `persistent` mode; in
  `exec` and `persistent` mode the universal wrapper `runr-wrapper` turns HTTP requests, queue
  CloudEvents and schedule ticks into invocations over stdin / stdout. The built-in runtimes are
  plugins of the same kind; platform administrators register platform runtimes and
  organizations custom ones (Settings → Runtimes, `/api/runtimes`, permissions
  `runtimes.manage`, `runtimes.use_custom`), with manifest validation, runtime image builds and
  test deploys. Example runtimes for C, Rust, Bash and the toy language Q in `examples/runtimes`;
  `ci.sh test-runtimes`.
- WebAssembly functions ([docs/wasm.md](docs/wasm.md)): Rust, Go (TinyGo), JavaScript /
  TypeScript and Python as Spin components on the runwasi Spin shim. **Install** in the
  region's Isolation tab puts the shim on the selected nodes, creates the `wasmtime-spin-v2`
  RuntimeClass and turns on the Knative features Wasm functions need; the runtimes `wasm-rust`, `wasm-go`,
  `wasm-js` and `wasm-python` then appear in that region's runtime picker. `ci.sh test-wasm`.
- RunR's build of the Spin shim, containerd-shim-spin **v0.26.0-runr.1**
  (`ghcr.io/runrsh/containerd-shim-spin`, `runtime/wasm-shim-spin`, `ci.sh wasm-shim`): upstream
  v0.26.0 with its runwasi library giving the Linux containers of a Wasm pod — Knative's
  queue-proxy — the environment of their pod spec, which the upstream releases do not; built from
  pinned sources and toolchains, Apache-2.0 with LICENSE and NOTICE in the image. The
  `wasm-installer` takes it from the image (init container) and checks it against the image's
  SHA256SUMS ([docs/wasm.md](docs/wasm.md#the-spin-shim-runr-installs)).
- `region:reconcile` brings the shims on the nodes of a region with WebAssembly support up to the
  release: when the region's `wasm-installer` installs other shim binaries or is missing, it
  queues the region's WebAssembly install with the same shims and nodes, which replaces the
  binaries in place (the container runtime restarts only where RunR's configuration block
  changes).
- `wasm-exec` runtime ([docs/wasm.md](docs/wasm.md#wasm-exec)): any WASI command — a preview 1
  module from C / C++ (wasi-sdk) or Zig, a WASI 0.2 component from Go (TinyGo with wasm-tools), a
  project `build.sh` or an uploaded `.wasm` — on Spin's WAGI executor (the request on stdin and in CGI variables, the response on stdout); runtime
  manifests on the Spin shim may declare `wagi`. Examples `examples/wasm/c-exec` and
  `examples/wasm/q-exec`.
- Uploaded images ([docs/images.md](docs/images.md)): `docker save` archives, OCI image-layout
  tars and `.wasm` modules uploaded through Settings → Images, `POST /api/images/uploads` or
  `runr images push` into a region's registry in resumable chunks — also for regions without
  internet access. Uploaded images run as CaaS images, as runtime versions (`upload:` source) and
  as `wasm-exec` modules, get the supply-chain pipeline of a build and count against a
  per-organization storage quota. Permissions `images.view`, `images.upload`, `images.delete`;
  Helm values `images.*`.

#### Deploy

- Git deploy ([docs/git-deploy.md](docs/git-deploy.md)): every project has a git remote
  `https://<console>/git/<organization>/<project>.git` (smart HTTP, an API token as password); a
  push to the deploy branch becomes a version with its commit, applies `runr.yaml`, builds and
  deploys. Projects connect to repositories on GitHub (GitHub App), GitLab or Forgejo / Gitea
  with push-to-deploy, commit statuses and a deploy history; monorepos use a root directory.
  Permissions `git.pull`, `git.push`, `git.manage`, `git_providers.manage`; Helm values
  `gitDeploy.*`.
- Preview environments per pull / merge request: `<project>-pr-<number>` on the function domain
  with environment overrides and scale-to-zero, a PR comment kept up to date, removal on close,
  merge or after a TTL, a per-project limit and the organization quota; secrets reach forks only
  when allowed. Permission `previews.manage`.
- `runr` CLI ([docs/cli.md](docs/cli.md)): log in, scaffold (`runr init`), run locally on the
  runtime images (`runr dev`, also for runtime plugins) and deploy (`runr deploy` with
  `runr.yaml`), plus commands for every feature of this release — secret variables, git deploy
  and previews, runtimes, workflows, bindings, models, sandboxes, uploaded images, traces, uptime
  checks and alerts, MCP clients and hosted MCP servers, supply-chain reports, data residency,
  members, roles, groups and the audit log. Releases for Linux, macOS and Windows.
- Terraform provider `terraform-provider-runr` ([docs/terraform.md](docs/terraform.md)) for
  projects, environment variables (write-only secrets with `value_wo`), domains, deployments,
  bindings, runtimes, workflow triggers, model services, hosted MCP servers, roles, groups,
  project access and branding.
- GitHub Action `actions/deploy` ([docs/github-action.md](docs/github-action.md)): deploys a
  project with the CLI on push and runs pull request preview environments.

#### Data

- Bindings ([docs/bindings.md](docs/bindings.md)): managed KV (a Redis ACL user and key prefix),
  object storage (a Garage bucket or an external S3 endpoint), Postgres (CloudNativePG, shared or
  dedicated cluster) and external credentials, attached to projects with an env prefix and
  injected through the Secret `<project>-bindings`; credential rotation with a rolling restart,
  deletion with retention and restore, health, usage metering and per-organization quotas.
  Console: project Bindings tab, Settings → Data, region Data services. Permissions
  `bindings.view`, `bindings.manage`, `bindings.view_credentials`,
  `regions.data_services.manage`; Helm values `bindings.*`.
- Durable workflows ([docs/workflows.md](docs/workflows.md)): code-first, step-based workflows
  whose progress survives crashes, redeploys and long sleeps — memoized steps with retries and
  backoff, sleeps, waits for events, child workflows and events — started by the API, events,
  cron, queue messages or other workflows. Python (`runr-workflows`) and TypeScript
  (`@runr/workflows`) SDKs on a language-neutral
  [protocol](docs/workflows-protocol.md); a project Workflows tab with run timelines and an Events
  view. Permissions `workflows.view`, `workflows.run`, `workflows.manage`, `events.send`.

#### AI

- GPU workers ([docs/ai.md](docs/ai.md)): GPU nodes, products, GPU memory and allocation per
  region; **Install GPU support** puts the NVIDIA device plugin (with GPU Feature Discovery and
  optional time-slicing) or the NVIDIA GPU Operator on a region. Permission `regions.gpu.manage`.
- GPU workloads: `config.gpu` (`count`, `type`, `memory_min`) on CaaS projects and on functions
  of GPU runtime versions — GPU limits, node selection by product and memory, runc or gVisor with
  nvproxy — admitted against the region's GPU nodes and the tenant GPU quota, and metered as
  GPU time in the usage aggregation.
- Hugging Face models ([docs/huggingface.md](docs/huggingface.md)): a Hub browser, one-click model
  services (vLLM, TGI, TEI, RunR's `hf-serve` for transformers / diffusers, or a custom image)
  online or offline, offline copies in the region's model volume (mirror Jobs, chunked uploads
  and imports for air-gapped regions), a playground, and model bindings that give functions in
  any language `RUNR_MODEL_<ALIAS>_*` environment variables. Permissions `models.view`,
  `models.deploy`, `models.manage`; examples in `examples/ai`.
- RunR MCP server ([docs/mcp.md](docs/mcp.md)): a built-in Model Context Protocol server at
  `/mcp` (Streamable HTTP, sessions, progress for long-running tools) with tools, resources and
  prompts over the RunR API; the tools a client sees follow its token's permissions and every
  call is audited. MCP clients connect with an API token or through OAuth 2.1 (dynamic client
  registration, PKCE, a consent page in the console). Console: Settings → AI agents (MCP).
- Hosted MCP servers: any HTTP project served at `/mcp/servers/<slug>` behind a gateway with
  public, RunR-token or OAuth access, a signed caller identity, a call log and an inspector in the
  project's MCP tab; Python and TypeScript starters as the runtimes `mcp-python` and `mcp-node`
  (`examples/mcp`). Permissions `mcp.servers.manage`, `mcp.servers.call`.
- Sandbox API ([docs/sandbox-api.md](docs/sandbox-api.md)): isolated sandboxes for untrusted code
  on your own cluster — pods under the region's strongest sandbox runtime (Kata, else gVisor) with
  Pod Security `restricted`, default-deny networking with an optional egress allowlist and
  resource limits. Exec as JSON, Server-Sent Events or WebSocket, background processes, files, a
  code interpreter, a console terminal, pause / resume, TTL and idle expiry, warm pools,
  organization quotas and usage metering. Python (`runr-sandbox`) and TypeScript
  (`@runr/sandbox`) SDKs; permissions `sandboxes.view`, `sandboxes.create`, `sandboxes.exec`,
  `sandboxes.manage`; Helm values `sandboxApi.*`.

#### Security and enterprise

- Roles and permissions ([docs/permissions.md](docs/permissions.md)): one permission catalog for
  every API route, built-in roles (Owner, Admin, Developer, Viewer, Billing), custom roles,
  groups, per-project access and API tokens limited to a selection of their owner's permissions.
  SSO providers (OIDC, SAML) map identity-provider groups to RunR groups and roles and provision
  members just in time; `RBAC_DEFAULT_ROLE` sets the role of new members.
- Encryption at rest for every stored secret ([docs/security.md](docs/security.md)): environment
  variable values, version snapshots, region kubeconfigs, Redis / registry passwords and S3
  credentials, cold-storage credentials, provider credentials and white-label keys, with key
  rotation through `APP_PREVIOUS_KEYS` and `php artisan secrets:encrypt --rotate`. Helm values
  `secret.appKey`, `secret.previousAppKeys`.
- Secret environment variables: write-only values per project, delivered through the Kubernetes
  Secret `<project>-secret-env` and `secretKeyRef` entries; reading a value back needs
  `secrets.view_values` (`runr env set --secret`, `secret = true` in Terraform).
- Audit log: an append-only record of sign-ins, account and token changes, project, deploy and
  rollback, shell, domain, gateway, region, runtime, member, impersonation, settings, billing and
  SSO actions, branding, signup, plan and billing-integration changes (field names only, never
  secret values) and authorization denials, with filters,
  CSV / JSON export, a daily retention prune
  (Helm `audit.retentionDays`, default 365 days) and optional JSON-line forwarding to a SIEM
  (Helm `audit.siemForwarding`). Console: Settings → Audit log; permissions `audit.view`,
  `audit.export`.
- Software supply chain ([docs/supply-chain.md](docs/supply-chain.md)): an SBOM (SPDX and
  CycloneDX), a vulnerability scan (also with an offline database) and a cosign signature with
  the region's key for every image RunR builds or receives; a policy gate before deploy, re-scans
  of deployed images and signed-image admission in the region. WebAssembly functions (Spin apps)
  are signed and verified like images; they get no SBOM or scan, and the gate does not require
  one of them. Permissions `security.view`, `security.override_policy`,
  `regions.supply_chain.manage`; Helm values `supplyChain.*`.
- Data residency: organizations limited to allowed regions and data locations, enforced on
  projects, deploys, bindings, model services, uploads and pushes (permission
  `tenants.residency.manage`, multi-tenant edition).

#### Operations

- Observability ([docs/observability.md](docs/observability.md)): an OpenTelemetry Collector and
  Grafana Tempo per region (or an existing backend), auto-instrumentation of functions, a Traces
  tab with search and waterfall, request replay of captured failed requests with a response
  diff, uptime checks run by the region agent, a public status page per organization, and alert
  channels and rules. Permissions `traces.view`, `replay.manage`, `uptime.manage`,
  `alerts.manage`, `status_page.manage`, `regions.observability.manage`; Helm values
  `observability.*`.
- White-label ([docs/white-label.md](docs/white-label.md)): the installation's brand (name,
  logos, colours, login texts, e-mails) without a rebuild and, in the multi-tenant edition, per
  tenant; self-service signup with e-mail verification, a domain allowlist, invite-only mode,
  CAPTCHA and terms, a tenant per signup with an onboarding wizard; optional plans (off by
  default) with monthly / yearly prices, trial days, limits (CPU, memory, projects, GPUs,
  invocations per month, code storage) and feature flags. Helm values `branding.*`, `signup.*`,
  `plans.*`; permissions `branding.manage`, `signup.manage`.
- Billing ([docs/billing.md](docs/billing.md), multi-tenant): RunR calculates what each tenant
  owes and leaves invoicing and payment to the operator's billing system. Charges per tenant and
  calendar month — metered usage priced with the cost configuration (functions, model services,
  bindings, sandboxes), plan fees, capacity deals and one-off charges — close after the month with
  final line items, the credit they draw and the amount due. The billing API (`/api/billing/…`)
  reads usage of any period, charges, plans and tenants, changes a tenant's plan, records
  purchases made elsewhere with their reference (credit, prepaid plan terms, add-ons that raise
  plan limits) and stores the billing system's reference on charges; signed billing events
  (`billing.period.closed`, `billing.plan.changed`, `billing.usage.threshold`,
  `billing.charge.voided`) go to a webhook with retries and stay readable in an event log.
  Platform administrators connect a system with an API token limited to `billing.view` or
  `billing.manage`; tenants see their plan, limits, charges, credit and purchases in the console
  and a link to the operator's store. Helm values `billing.webhookUrl`, `billing.webhookSecret`,
  `billing.usageThresholds`, `billing.storeUrl`.
- Scheduled lifecycle tasks ([docs/monitoring.md](docs/monitoring.md)): `close-stale-sessions`
  (every ten minutes) closes deployment sessions open for more than 30 minutes without a reported
  end, in the central schema and every tenant schema, and the pod's real end later corrects a
  session closed too early; `capacity-deal-expiry` (hourly, multi-tenant) sets active capacity
  deals past their end date to `expired`.
- Tests and CI: Vitest and Playwright suites for the console, ESLint, Pint and Larastan for the
  API, Go tests for the agent, the queue consumer, the wrapper, the CLI, the Terraform provider
  and the Sentry tunnel, and end-to-end suites on kind (`runtime/ci.sh test-*`), run as quality,
  security and e2e gates in GitLab CI and GitHub Actions; ESLint fails on warnings, and the API and
  console tests on coverage below their floor ([docs/development.md](docs/development.md)).

### Changed

- Documentation lives in `docs/` in English, with an index ([docs/README.md](docs/README.md)) and
  an architecture overview; MIT `LICENSE`, `CONTRIBUTING.md`, `SECURITY.md`,
  `CODE_OF_CONDUCT.md`, issue and merge request templates; English comments in the Helm
  `values.yaml`.
- The console, page title, favicon, API docs and image labels carry the RunR name and wordmark.
- API tokens carry permissions of the catalog instead of the 0.4.x scopes (see Upgrade notes).
- The development Compose network is `runr_default`.
- API caching ([docs/architecture.md](docs/architecture.md#api-caching-and-request-bodies)): the
  trace endpoints answer `Cache-Control: no-store` without an `ETag`, so neither the response
  cache nor a proxy keeps a trace that is still receiving spans; the public status page
  (`public, max-age=30`) and `GET /api/branding` (`public, max-age=60`) keep their directive next
  to the `ETag`, so a proxy or CDN may cache them.
- Code and file contents (editor saves, runtime files, sandbox file writes, MCP tool arguments)
  are no longer trimmed by the API: they are stored byte for byte.
- Audit events list the added, removed and changed environment variable names in
  `metadata.changes[].env_vars`, never values ([docs/security.md](docs/security.md#audit-log)).
- `PUT /api/tenants/{tenant}/security-policy` drops repeated region ids and locations instead of
  refusing the request.
- The API test suite sets `memory_limit=1G` and `APP_URL=http://localhost` itself
  (`api/phpunit.xml`), whatever the `.env` says.
- Text files are stored and checked out with LF on every platform (root `.gitattributes`).
- The region Traefik runs its image from RunR's mirror (`ghcr.io/runrsh/reqs/traefik:v3.7.4`)
  instead of Docker Hub, like RunR's other third-party images; `region:reconcile` moves existing
  regions running the same Traefik version, and `knative.traefikImage` (`KNATIVE_TRAEFIK_IMAGE`)
  names another registry ([docs/upgrade.md](docs/upgrade.md#region-traefik-image)). The Bash and
  Rust example runtimes use the mirrored `bash:5.2` and `rust:1.83-alpine`.

### Security

- Cached API responses are served only to the user they were cached for; an unauthenticated
  request no longer receives a cached project list.
- Every WebSocket channel is private and authorized with the user's permissions; a user's
  notifications go to their own channel and internal ticket notes to a ticket-manager channel.
- SSO callbacks (OAuth, OIDC, SAML) redirect with a one-time code instead of the API token in the
  URL.
- Broadcasts mask environment variable values and secret fields; version and deployment
  broadcasts carry no environment variables; the git snapshot of a version lists environment
  variable names only; queue payloads with kubeconfigs or credentials and the shell session
  handoff are encrypted; the SSO client secret and linked-account OAuth tokens are never
  returned by the API.
- The queue consumer reads the Redis address and password from the Secret `<slug>-qc-redis`, and
  the signing key of a workflows project and the queue bridge's sink URL reach the workload from
  Kubernetes Secrets, instead of plain values in the spec.
- Tenant invitation tokens are stored as SHA-256 hashes; links already sent keep working.
- A tenant's copy of a platform region no longer double-encrypts the Redis and registry
  passwords.
- The development Sentry tunnel (`proxy/`) forwards envelopes only to the host of a configured
  DSN; without one it refuses them instead of relaying them to whatever host they name.
- Dependency updates for published advisories: the API's Composer packages (Laravel 12.70 with
  Horizon 5.50, whose workers declare the `queue:work` options Laravel 12.70 added, Guzzle,
  Symfony 7.4, league/commonmark, phpseclib and others: `composer audit` reports none),
  `golang.org/x/net` and gRPC in the region agent and the Terraform provider, Go 1.26 for the
  agent, queue consumer, wrapper and test images, and Pillow, FastAPI, Starlette, transformers,
  huggingface_hub, safetensors and diffusers in `hf-serve`. `TRUSTED_PROXIES=private_ranges` keeps
  meaning the private, loopback and link-local networks: newer Symfony releases add publicly
  routable prefixes to their private-subnet list, which RunR no longer follows.
- An IPv4-mapped peer address (`::ffff:a.b.c.d`, how dual-stack listeners such as Octane /
  FrankenPHP report IPv4 clients) is matched against `TRUSTED_PROXIES` as its IPv4 address: a
  mapped private peer is a trusted proxy, a mapped public peer is the client, and its
  `X-Forwarded-For`, `-Proto` and `-Host` are not used.
- The notification list, its unread count, marking read and deleting cover the signed-in user's
  own notifications and the shared ones only; a member no longer sees or clears another user's
  (the result of a job someone else started, each admin's copy of a ticket notification).
- Revoking a session that is not the user's answers 404 and no longer signs out the console that
  holds that token.

### Fixed

- An offline model copy whose Job waits for a model volume that cannot be bound (for example a
  `ReadWriteMany` claim the local-path provisioner cannot provision) shows **Pending** with the
  reason from the claim's events instead of **Copying** with no message, and so do the offline
  services waiting for it; the region's model volume status gives the same reason.

- Dragonball (Kata runtime-rs) guests get 1 vCPU + the pod's CPU limits like Cloud Hypervisor and
  QEMU guests; with kata-deploy 3.28 they had 1 vCPU whatever the limit. Every workload RunR writes
  on Dragonball carries the vCPU count as Kata pod annotations, which Dragonball's drop-in enables
  on containerd nodes ([docs/kata-hypervisors.md](docs/kata-hypervisors.md#guest-size)); existing
  Dragonball functions get it on their next deploy.
- The login page no longer reloads itself endlessly: the console subscribed to the (now private)
  broadcast channels while signed out, the 401 of the channel authorization sent the browser to
  the login page again, and the API's rate limit started answering 429. The console subscribes
  once a session exists.
- Installing Knative Serving on a region whose Traefik trusts no proxy (no
  `ingress_trusted_ips`, the default) no longer fails at the Traefik release with
  `ports.web.proxyProtocol.trustedIPs: Invalid type. Expected: array, given: object`.
- A build or readiness check that outruns its own 30 s limit (a slow region API server) checks
  again instead of marking the build or the deployment failed; the overall timeouts are unchanged.
- A change the console hears about live (saved code, settings, deployment status, notifications,
  support tickets) no longer answers 500 while the WebSocket server (Reverb) is unreachable: the
  change was saved all the same, and a client that retried saved it twice. The failed broadcast is
  reported in the log.
- Migrating a region to a new cluster reinstalls the region's managed Redis there.
- A plain HTTP request on the shell or agent WebSocket route is answered with 400 instead of
  failing.
- `runr:patch-cluster-observability` waits for the Cilium compatibility DaemonSet instead of
  logging a failure.
- Log search highlights queries that contain `<`, `>` or `&`; a container command with repeated
  or surrounding spaces no longer sends empty arguments.
- On SQLite (the Valet development setup) the usage aggregation added another usage record of the
  day on every run instead of updating it, and billing summed them.
- Under the Turkish locale, upper-cased labels turned the i of English terms into İ (KNATİVE
  BÖLGELERİ, BASE DOMAİN, BELLEK (MİB)); the terms keep English capitals now.
- The development Sentry tunnel no longer crashes, at every start, on a queued envelope whose DSN
  has no http(s) scheme; it pauses between retries while Sentry answers 5xx, refuses envelopes
  over 10 MiB (413) instead of forwarding them cut off, and its health check fails while Redis is
  down.

### Removed

- Leftover AI screens (DAG visualizer, GPU metrics, agent observability, blueprint editor) and
  dead code paths of the AI project types retired in 0.4.0; their rows keep a read-only detail
  page with delete. A deployment of a retired type fails instead of being marked active, and
  region migrations skip them.
- The duplicate Node.js Sentry tunnel (the Go implementation in `proxy/` ships).
- The unused setting `KNATIVE_MIN_K8S_MAJOR` (Helm `knative.minK8sMajor`).

## [0.4.4] - 2026-10-01

### Added

- Hardware virtualization check for Kata: a short-lived, unprivileged DaemonSet (non-root, no capabilities, the node's `/dev` read-only) checks every Kata target node for the CPU's `vmx`/`svm` flag and `/dev/kvm` or the KVM module, and records the result as the node label `runr.dev/kvm=true|false` and the annotation `runr.dev/kvm-probe`. Node Feature Discovery `cpu-cpuid` labels are used for nodes RunR has not checked; new nodes are checked in the background.
- The console's Kata card shows hardware virtualization on the region's nodes: without a KVM-capable node Kata is shown as unavailable (no Install button, no hypervisor list, not offered in the project-type mapping) and an existing Kata install gets a warning to move its mappings to gVisor or runc; on mixed clusters the card names the nodes Kata pods run on.

### Changed

- Every Kata RuntimeClass also selects `runr.dev/kvm=true`, so on mixed clusters Kata pods are only scheduled on nodes that can start them. `region:reconcile` updates the nodeSelector of existing RuntimeClasses in place (no restart) and prints a warning for Kata installs on regions without KVM; they are never uninstalled automatically.

### Fixed

- Kata no longer installs "successfully" on nodes that cannot run virtual machines (for example cloud VMs without nested virtualization), where its pods never started: the install API returns 422 for Kata when no target node has KVM, gVisor and runc are unaffected, and a working Kata install stays ready.

## [0.4.3] - 2026-10-01

### Fixed

- Adding a hypervisor (for example Firecracker) to a ready Kata install no longer breaks it when the addition fails before the nodes' Kata configuration or the RuntimeClasses change: the region stays ready with its previous hypervisors and the `kata-*` mappings keep working; the install log ends with the error, the job completes with a warning and the console shows it on the ready card.
- Firecracker's devmapper setup no longer takes a stale pod's result from an earlier install for its own: the previous `kata-devmapper` DaemonSet is deleted with its pods first, and RunR only waits on pods of the current DaemonSet and pod template.
- Installing Firecracker again after a rollback or cleanup sets up the devmapper thin pool again instead of failing with "thin-pool runr-thinpool is not active": `runr-devmapper.service` is stopped and reset before it is removed, and the install restarts it.

## [0.4.2] - 2026-10-01

### Changed

- queue-proxy gets a 1-CPU limit over its 100m request on every Knative Service RunR renders (all project types and triggers, runc, gVisor and Kata), so it can use idle node CPU while a pod starts and under load. Project pods become Burstable instead of Guaranteed; admission, tenant quotas, capacity checks and the organization ceiling count requests only. Revisions from before the upgrade get the limit on their next deploy.
- Kata guests on every hypervisor boot the Kata agent as init instead of systemd; the image's `sysctl.d` settings and `RLIMIT_NOFILE` 1048576 are still applied, and Dragonball gets the cgroup v2 kernel parameters its guest needs.
- A change to RunR's Kata configuration drop-ins is written on every node in place by a short-lived DaemonSet instead of running kata-deploy again, so it no longer restarts the container runtime; 0.4.1 installs are recognised and updated without a restart.
- The organization ceiling's ResourceQuota limits `requests.cpu` only (the `limits.cpu` entry is dropped); `region:reconcile` updates existing regions.

### Fixed

- Region capacity admission checks the effective size (the value sent, else the stored one, else the service type's default) on create, update, deploy, scaling and trigger changes and region moves; projects created or deployed without an explicit size skipped the check before. The region demo checks that all four demo projects fit before creating any of them.
- The Kata and gVisor install log is kept after the install, with the result appended as its last line; the console shows the log of a ready runtime in a collapsed "Install log" section and the whole log on an error.
- Firecracker's devmapper check accepts containerd 2.x config dumps. A failed setup no longer crash-loops its DaemonSet: the error is shown in the region's Kata status, and the thin pool, unit and containerd config of a first setup are removed again without a restart.
- A failed live-progress broadcast (for example while the WebSocket server restarts) no longer fails sandbox installs and other jobs; the install log and the job result are stored either way.
- `region:reconcile` Helm upgrades pin the deployed chart version, read from `helm list`, and leave a release alone when that version cannot be determined, instead of upgrading to the newest chart in the repository.
- Single-tenant installs no longer run the Tenancy module's migrations, which logged `Database connection [central] not configured` on every migrate.

## [0.4.1] - 2026-09-30

### Added

- Firecracker (`fc`) as a Kata hypervisor: RunR sets up containerd's devmapper snapshotter on the nodes (sparse thin pool under `/var/lib/runr/devmapper`, boot unit, containerd config) before kata-deploy runs. The Kata card has a thin-pool size field (`devmapper_pool_gb`), and all four hypervisors (`clh`, `qemu`, `dragonball`, `fc`) can be installed together. The card states that installing or uninstalling Kata restarts the container runtime on every node and asks for confirmation.
- `region:reconcile` brings existing Kata installs to the current configuration: RuntimeClass-only changes are applied in place, an installer change queues the region's Kata install once and reports the container runtime restarts it causes.

### Changed

- Kata installs: a region is ready only after kata-deploy has finished on every target node (20-minute timeout naming the pending nodes); RunR's runtimes keep the shim, VMM and virtiofsd inside the pod's cgroup with fixed VM sizing; the `kata-<hypervisor>` RuntimeClasses declare their pod overhead; unknown hypervisors are rejected (422); uninstall is refused while pods run with a Kata RuntimeClass; node detection covers every node, including dedicated workers.
- Projects mapped to a Kata runtime reserve that RuntimeClass's pod overhead per pod in tenant quota admission, region capacity validation and usage reporting.
- Region capacity is node allocatable minus the requests of platform pods, so admission and tenant quotas no longer count CPU and memory that system pods already request; build Job pods count with their project.
- On regions with the internal registry, builds pull their base images through it: the registry also caches `ghcr.io` (`region:reconcile` updates existing registries). Kaniko is pinned to `v1.28.5`.
- Python runtime images ship precompiled bytecode for the standard library, so a cold start does not compile the modules it imports; the PyTorch and TensorFlow image builds are fixed.
- Queue workers block on Redis instead of sleeping between polls, and build and deployment readiness checks start 1 s after submit and repeat every 2 s (previously 5 s).

### Fixed

- Function dependencies are installed in the executor image the function runs on, so native npm modules, compiled wheels and bytecode match its interpreter; Composer resolves PHP packages for the runtime's PHP version.
- Log retention deletes from the region's own Loki (RunR-managed or reused, also for remote regions) through the Kubernetes API proxy and logs refused deletes; a RunR-managed Loki is configured to accept deletes (`region:reconcile` applies it, restarting Loki once), and `keep_last_n_deployments` protects the logs of the newest deployments.

### Security

- The region Traefik trusts PROXY protocol and `X-Forwarded-*` headers only from the new region setting `ingress_trusted_ips` (default: nobody); before, any client could set the client IP seen by functions, the gateway rate limit and the API. `region:reconcile` updates existing installs.
- The API takes the client IP from known proxy hops (`TRUSTED_PROXIES`, `TRUSTED_PROXY_HOPS`; chart values `clientIp.trustedProxies` and `clientIp.proxyHops`) instead of trusting every proxy, so a spoofed `X-Forwarded-For` changes neither the client IP nor its rate-limit bucket.

## [0.4.0] - 2026-09-30

### Added

- SAML 2.0 single sign-on: SP-initiated login with SP metadata, login and ACS endpoints (also per tenant), optionally signed AuthnRequests and strict response checks (InResponseTo, single use, destination, audience, issuer, validity window, signed assertion). Configured under Auth Providers; the login page shows a SAML button.
- Custom domains for HTTP-triggered projects: ownership is verified with a TXT record and the routing record is checked; TLS per domain is the region's default certificate, an uploaded PEM pair or a cert-manager certificate from the region's ClusterIssuer. A Domains tab on the project page lists the records to create.
- Private connections from functions to Managed PostgreSQL through Skupper v2 (detected on the region, never installed by RunR): credentials are kept only in a Secret and exposed as `DATABASE_URL` and `PG*` environment variables; new "Private connections" project tab.
- API rate limiting per tenant, per user and per IP for unauthenticated calls, plus a login limiter, with JSON 429 responses and `Retry-After` / `X-RateLimit-*` headers (`API_RATE_LIMIT_*`, `AUTH_RATE_LIMIT_PER_MINUTE`); shared gateways can set an optional Traefik rate limit.
- External billing integration (chart values `billing.gatewayUrl`, `billing.gatewayToken`, `billing.webhookSecret`; off when empty): closed periods are invoiced through the billing gateway, payment status arrives through a signed webhook, and admins can refund; tenants see their payments and invoices in the console.
- Organization resource ceiling: chart values `quota.cpu` and `quota.memoryGiB` become a ResourceQuota and LimitRange in each region's project namespace; tenant quotas on a region cannot add up to more than the region capacity or the ceiling.
- `region:reconcile [--region=] [--dry-run]`, run by the Helm bootstrap job on every upgrade (time-limited, never blocks the upgrade), brings existing regions to the release's region configuration: Knative revision GC and deployment settings, removal of the old Prometheus NodePort, organization ceiling.

### Changed

- Instance sizes: six reference sizes (nano to xlarge) are offered as presets when creating, editing and cloning projects; new XaaS and FaaS projects default to micro (250m / 256 MiB), CaaS to small (500m / 512 MiB). A project reserves its size × max(1, `min_scale`) plus queue-proxy (now 100m), its queue consumer or schedule pod, with one formula for quota admission, region capacity and usage; existing projects are grandfathered.
- Tenant deletion runs as a queued job (202): it tears down the tenant's projects, domains, labelled workloads and the agent of tenant-owned regions, and drops the database only when every teardown succeeded (or with `force`). The tenant list shows "Deleting…" and "Deletion failed" with Retry and Force delete actions; writes to a tenant being deleted return 409.

### Removed

- The AI project types (AaaS, AgentFlow, MaaS) can no longer be created, changed or deployed; existing rows still list and can be deleted. Tenant plan labels are removed from the API, the CLI and the console.

### Fixed

- Registry retention keeps the newest ready builds plus every image still in use (active deployments, live Knative revisions, CronJobs, queue consumers); pruned versions are marked "pruned" and deploying one returns 422.
- Tenant-owned regions store the monitoring and function-TLS settings introduced in 0.3.9, so Prometheus, Loki and cAdvisor are installed on them like on platform regions; optional Prometheus scrape resources no longer fail a Serving install.

### Security

- Platform regions are read-only for tenant users: region-changing endpoints (including Skupper site setup) and the kubeconfig download require a platform admin, or the owner or admin role on a tenant-owned region. Reused Prometheus and Loki URLs must be in-cluster Services of the region's cluster, are read through the region's Kubernetes API proxy, and only platform admins or the tenant owner may set them; the region setup wizard accepts them together with a Loki tenant ID (`X-Scope-OrgID`).
- Function-domain TLS checks that the certificate matches the key, is unexpired and covers `*.<function domain>`; it refuses a Traefik TLSStore `default` in another namespace and replaces one RunR did not create only on explicit opt-in (a checkbox in the console). Console mode reads the console TLS Secret of the active ingress TLS mode.
- The `runr-api` ServiceAccount keeps only the two reads it performs (the `kube-system` namespace and the console TLS Secret), and pods that need no token no longer mount one. The generated admin password is no longer written to logs, and `deploy.sh` installs get a generated one.

## [0.3.9] - 2026-09-30

### Added

- Regions can reuse an existing Prometheus and/or Loki instead of installing RunR's monitoring stack: a `prometheus_url` or `loki_url` given at region creation marks that component as unmanaged. Existing regions keep their RunR-managed stack.
- Function-domain TLS per region (`PUT /api/knative-regions/{id}/function-tls`): upload a certificate and key for the region's function hosts, served through the region Traefik's default TLSStore with the key kept only in a Kubernetes Secret, or reuse the console certificate when the region runs on the console's cluster. Regions that never use it are unchanged.
- Build image retention for the internal registry: after each successful build the newest `registry.retain_versions` (default 10) build tags are kept, and a deleted project's images are removed. External registries are never touched.

### Fixed

- Deleting a tenant tears down every project's workloads (Knative Services, CronJobs, queue consumers) and storage before the tenant database is dropped.

### Security

- The control-plane ClusterRole is narrowed to the Knative resource kinds RunR uses (`services` in `serving.knative.dev` and `knativeservings`), without wildcards or grants on Knative's internal API groups.

## [0.3.8] - 2026-09-30

### Changed

- Knative Serving keeps a bounded number of non-active revisions (time-based retention with a hard ceiling) instead of every revision.
- queue-proxy runs with CPU and memory request equal to limit, so project pods get Guaranteed QoS; quota and capacity accounting include this per-pod overhead.

### Fixed

- The max scale field in the console accepts values up to 1000, as the API does.
- The region demo's contact form shows the backend's message instead of a generic "Sent!".

### Security

- Prometheus is no longer exposed through an unauthenticated NodePort (30090); RunR reads it in-cluster or through the Kubernetes API proxy and removes the old NodePort Service.
- No default `admin123` administrator password: when `ADMIN_PASSWORD` is unset, a random password is generated and printed once.

## [0.3.7] - 2026-09-29

### Fixed

- Removing the region demo inside a tenant deletes the demo projects instead of failing with an SQL error.
- Seeding the region demo is refused while the region's demo projects still exist, so the demo is never created twice.

## [0.3.6] - 2026-09-29

### Added

- Console TLS modes in the chart (`ingress.tls.mode`): `letsencrypt` (cert-manager ClusterIssuer, default `letsencrypt-prod`), `custom` (uploaded certificate and key, validated at install) and `none` (the ingress controller's default certificate). An empty mode keeps the previous behaviour, also with `helm upgrade --reuse-values`.
- Chart values `storage.application` and `storage.database` for the API and PostgreSQL volume sizes, used by the Advanced settings of marketplace install forms.

### Changed

- New regions get a managed Redis by default, so queue triggers and the region demo work right away; `redis_mode` (`managed`, `in_cluster`, `external` or `none`) with `redis_address` and `redis_password` selects another setup.

### Fixed

- Saving the files of a FaaS project whose handler is not `main.*` no longer fails with "Main file (main.php) cannot be deleted".
- The region demo's frontend calls its backend through the in-cluster service address (no public DNS or publicly trusted certificate needed), and all demo components use the tenant-aware queue stream name.

## [0.3.5] - 2026-09-29

### Added

- The queue consumer retries failed deliveries with exponential backoff (`RETRY_BACKOFF`, `RETRY_BACKOFF_MAX`), moves a message to a dead-letter stream (`DLQ_STREAM`, default `<stream>-dlq`) after `MAX_RETRIES` (default 5) attempts, claims the pending messages of restarted consumers and reports its counters on `GET /stats`.

### Changed

- Project, code and deploy input is validated against Kubernetes limits with clear messages: project name (DNS label of at most 44 characters, unique), cron syntax, environment variable names and size, timeout up to the region's request timeout, CPU quantity and code size (below 1 MB); the console shows the same hints. Deploy-time `cpu`, `memory_mb` and `timeout_seconds` overrides are applied to the workload.

### Fixed

- gVisor installs the sidecar binaries (`gvisor-bin/`) that current runsc releases require; gVisor pods failed with "sidecar gvisor_sentry not usable" before. Earlier installs are completed on the next install run.
- Uninstalling gVisor, or a failed first install, cleans the nodes: RunR's runsc configuration is removed from containerd (checked with `containerd config dump` before the file is replaced), the runtime is restarted only where needed and the runsc binaries are removed; uninstall is refused while gVisor pods run.
- Tenant projects on a platform region run with the sandbox runtime the region maps to their project type.
- Deleting a project removes everything it runs (Knative Service, CronJob, queue consumer, code ConfigMaps) whatever its deployment status; a redeploy only removes what the new deployment does not reuse.
- Queued jobs no longer end in unresolved states: jobs run once unless they are safe to repeat, long jobs are not handed to a second worker, and failed deploys, builds and readiness checks mark the deployment or build as failed with the reason.
- Usage metering works without a cost config (zero cost in `BILLING_DEFAULT_CURRENCY`, default USD), and one tenant's failure no longer stops the others.
- A scheduled task interrupted by a pod restart runs again once its overlap lock expires, after 10 to 120 minutes depending on its frequency, instead of 24 hours.

## [0.3.4] - 2026-09-29

### Changed

- `registry:rolling-restart` restarts containerd in place (no eviction) when no target node can be drained, for example when RunR runs on every node or the cluster has a single schedulable node; when only some nodes were skipped it prints the `--in-place` command for them.

### Fixed

- The in-cluster agent URL uses the gateway port again; the Kubernetes service-link variable `RUNR_GATEWAY_PORT` had turned it into port 0. The settings are now `RUNR_AGENT_GATEWAY_SERVICE` and `RUNR_AGENT_GATEWAY_PORT`.
- gVisor installs download the sha512-verified release tarball, as upstream no longer publishes the individual binaries; the installer is ready only after runsc is loaded, and a failed install removes the `gvisor` RuntimeClass again.
- Each deploy is applied once: the deployment listeners were registered twice, which created an extra Knative revision per deploy and ran teardowns twice.
- Region capacity comes from the schedulable nodes when no dedicated workers are selected, so tenant quotas can be set on such regions; the Serving version is read from the KnativeServing CR for manual and adopted installs.
- Helm install and upgrade run pending tenant migrations in the multi-tenant edition; a failure stops the upgrade before new code runs on an old tenant schema.

## [0.3.3] - 2026-09-29

### Added

- `imagePullSecretsFromDefaultServiceAccount` (on by default): every pod inherits the pull secrets attached to the install namespace's `default` ServiceAccount, as marketplace installers set them up, also on `--reuse-values` upgrades. `imagePullSecrets` accepts names or `{name: …}` entries and also applies to PostgreSQL and Redis.
- Agent connections without public DNS: the agent uses the region setting `agent_ws_url`, else the in-cluster gateway when the region is RunR's own cluster, else `RUNR_AGENT_WS_URL`, else `APP_URL`.
- gVisor and Kata installers support containerd 2.x and k3s / RKE2 (configuration drop-ins or templates, restarting the k3s / RKE2 unit); CRI-O and mixed runtimes are refused up front, and a node's error becomes the region's sandbox status message.

### Changed

- `registry:rolling-restart` never drains RunR's own node or the only node of a single-node cluster (`--in-place` restarts containerd there without cordon or drain); k3s and RKE2 need no restart.
- The region Traefik chart is pinned to 40.3.0. When another controller owns the `traefik` IngressClass, the region Traefik installs without one, and uninstalling Serving keeps a Traefik that still serves other Ingresses (`remove_traefik: true` removes it anyway).

### Fixed

- The registry mirror works on containerd 2.x and k3s / RKE2 without producing an invalid containerd config (existing duplicate keys are repaired); the Zot chart is pinned and its PVC size and storage class are honoured; cAdvisor uses the distribution's containerd socket.
- Serving counts as ready only with a ready KnativeServing CR; `GET /api/knative-regions/{id}` no longer returns 500; failed Knative installs record their error status and log.

### Security

- Each region's agent authenticates with its own random token, of which only the SHA-256 hash is stored; requests without a token or with an unknown or wrong one are rejected. Agents deployed before 0.3.3 are redeployed with `php artisan agent:redeploy`, which the chart's bootstrap hook runs on upgrade.
- Production installs of the multi-tenant edition no longer seed a test login and demo tenants; the bootstrap hook creates the configured admin unless an administrator already exists.

## [0.3.2] - 2026-09-28

Chart-only release; the application images stay at 0.3.0.

### Fixed

- Marketplace installs create the administrator from the install form: a new `runr-bootstrap` post-install / post-upgrade hook seeds it while the users table is empty and changes nothing once any user exists (logins failed with 401 before). `bootstrap.enabled: false` turns it off.

## [0.3.1] - 2026-09-28

Chart-only release; the application images stay at 0.3.0.

### Changed

- The `fix-permissions` and `fix-pgdata` init containers use `ghcr.io/runrsh/reqs/busybox` instead of pulling `busybox` from Docker Hub; configurable with `initImage.repository` and `initImage.tag`.

## [0.3.0] - 2026-09-28

Initial tagged release.

### Added

- Projects on Knative Serving in three types: Code Snippet (XaaS, a single file), Code Project (FaaS, multiple files with a web IDE and platform-internal Git versioning) and Container (CaaS, any container image).
- HTTP, schedule (cron) and Redis-stream queue triggers, with scale to zero and configurable minimum and maximum scale.
- Runtimes for Python, Node.js, Go and PHP, plus PyTorch and TensorFlow images; builds run as Kaniko Jobs, and interpreted code without dependencies deploys straight from a ConfigMap.
- Every saved change creates a Version, and any Version can be deployed; deployments have logs, live metrics and a browser shell.
- Regions: connect a Kubernetes cluster by kubeconfig; RunR installs the Knative Operator and Serving, Traefik, Prometheus, Loki and Redis, can place projects on dedicated workers, and moves a region to a new cluster (cluster migration with rollback).
- An internal Zot registry per region (PVC or S3 storage) with a node registry mirror, or an external registry.
- Sandbox runtimes mapped per project type and region: runc by default, gVisor or Kata Containers.
- A runr-agent per region reports metrics, sessions and events; the console updates live over WebSockets.
- Multi-tenant edition: tenants with their own database schema, invitations, per-region quotas, usage metering and cost configuration.
- Accounts with two-factor authentication, session management and API tokens with permissions; sign-in through Google, GitHub, Microsoft, GitLab and OIDC providers.
- API gateways with routes, in-app notifications and support tickets.
- Helm chart with a single-tenant / multi-tenant edition, an install form (`values.schema.json`), generated secrets and a Traefik ingress; a Docker Compose setup for development.

[Unreleased]: https://github.com/brf-tech/runr/compare/v0.5.9...main
[0.5.9]: https://github.com/brf-tech/runr/releases/tag/v0.5.9
[0.5.8]: https://github.com/brf-tech/runr/releases/tag/v0.5.8
[0.5.7]: https://github.com/brf-tech/runr/releases/tag/v0.5.7
[0.5.6]: https://github.com/brf-tech/runr/releases/tag/v0.5.6
[0.5.5]: https://github.com/brf-tech/runr/releases/tag/v0.5.5
[0.5.4]: https://github.com/brf-tech/runr/releases/tag/v0.5.4
[0.5.3]: https://github.com/brf-tech/runr/releases/tag/v0.5.3
[0.5.2]: https://github.com/brf-tech/runr/releases/tag/v0.5.2
[0.5.1]: https://github.com/brf-tech/runr/releases/tag/v0.5.1
[0.5.0]: https://github.com/brf-tech/runr/releases/tag/v0.5.0
