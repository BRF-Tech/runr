# Software supply chain and data residency

RunR knows every image it runs: what is inside (SBOM), which known vulnerabilities it carries
(scan), who built it (signature) — and it can refuse to deploy or to admit images that do not
meet a region's policy. Tenants (organizations) can be pinned to regions and data locations.

- [Overview](#overview)
- [Where it runs](#where-it-runs)
- [SBOM](#sbom)
- [Vulnerability scanning](#vulnerability-scanning)
- [Offline and air-gapped scanning](#offline-and-air-gapped-scanning)
- [Policy gate](#policy-gate)
- [Signing](#signing)
- [Admission policy](#admission-policy)
- [Data residency](#data-residency)
- [Permissions](#permissions)
- [Audit](#audit)
- [API](#api)
- [UI](#ui)
- [Configuration](#configuration)
- [Data model](#data-model)
- [KVKK and GDPR](#kvkk-and-gdpr)
- [Testing](#testing)

## Overview

```
build ready ──► supply-chain Job in the region (tools image)
 (Version /      1. sbom    syft → SPDX JSON + CycloneDX JSON
  RuntimeBuild   2. attach  oras → both SBOMs as OCI referrers of the image digest
  model event)   3. scan    trivy → vulnerabilities (+ cosign-vuln predicate)
                 4. sign    cosign sign <image>@<digest> with the region key, then cosign verify
                 5. attest  cosign attest --type spdxjson (SBOM) and --type vuln (scan)
            ──► pod log (line protocol) ──► RunR: report, SBOM documents, counts, findings
deploy ──► data residency ──► policy gate (findings, required scan, signature) ──► Knative
pod    ──► admission (sigstore policy-controller): only images signed by the region key
```

What runs for which image:

| Image | Steps |
|---|---|
| Function version built by RunR (`<registry>/runr-builds/<project>:v<N>`) | sbom, attach, scan, sign, attest |
| Runtime image built by RunR from a Dockerfile / git source (`<registry>/runr-runtimes/…`) | sbom, attach, scan, sign, attest |
| CaaS image (any registry, GPU CaaS included), when the region scans external images | sbom, scan — RunR never writes to a registry it does not own |
| Uploaded container image (`<registry>/runr-uploads/…`, [Images](images.md)) — when the upload finishes, and for every CaaS version that runs it | sbom, attach, scan, sign, attest — the image is in RunR's own registry, whatever the setting for external images |
| AI model service image (engine image or the service's own image, [AI](ai.md)), when the region scans external images | sbom, scan — like a CaaS image |
| Spin app built by RunR (a function of a WebAssembly runtime, `<registry>/runr-builds/<project>:v<N>`, [WebAssembly](wasm.md)) | sign — sbom, attach, scan and attest are recorded as skipped with the reason (see below) |
| Code-mounted function (no build) | none: it runs the runtime's own image with the code mounted; that image is a platform image (built-in or platform runtime) |

Each step follows the region's settings: SBOM on/off, scan on/off, signing on with a ready key,
attestations on/off. A failed step does not stop the steps that do not depend on it; every
step's result is stored with its message.

**WebAssembly.** syft and Trivy read container filesystems; a Spin app is a WebAssembly OCI
artifact (a Wasm layer per component and the locked app as config, no filesystem), and an uploaded
module is a single Wasm file. So neither gets an SBOM or a vulnerability scan:

- A Spin app RunR built is **signed** with the region key like any other build (cosign signs any
  OCI artifact by digest), so *Verify the signature on deploy* and the admission policy apply to it
  as to a container image. Its report (image kind `wasm`, Security tab: *Spin app (Wasm)*) has
  the SBOM, referrers, scan and attestation steps as `skipped` with that reason, and no re-scan.
- The policy gate does not require a scan of a Spin app — *Require a finished scan* applies only
  where the region scans that kind of image, like a CaaS image in a region that scans no external
  images — and with no scan there are no CRITICAL / HIGH counts to hold against the limits. A
  region with an enforced gate and a required scan deploys signed Spin apps; an unsigned one is
  blocked by the signature rule.
- Uploaded modules ([Images](images.md)) are not signed either: the version that runs one is a
  Spin app built in the region, and that build is what gets signed.

**New and existing regions.** A region created after the upgrade to this release starts with
SBOM and scan **on** (the model defaults of `region_supply_chain_settings`). Every region that
exists when the upgrade runs gets a settings row with SBOM **off** and scan **off**: the migration
`2026_10_10_400001_keep_supply_chain_off_for_existing_regions` writes it for every region of the
central schema, the tenant migration `0047_keep_supply_chain_off_for_own_regions` for the
tenant-owned regions of every tenant. With SBOM, scan and signing off no supply-chain Job runs for
the region, so an upgrade never starts Jobs whose image a region cannot pull; an administrator
turns the steps on in Region → Supply chain once the tools image is reachable from the region.
Everything else starts the same way in both cases: signing, the policy gate, the admission policy
and the scanning of external images are off, the vulnerability DB mode is `online`
(Region → Supply chain, see [UI](#ui)).

## Where it runs

Every step runs in the region, next to the registry that holds the image, as one Kubernetes
Job of the **tools image** `ghcr.io/brf-tech/runr-supply-chain` (`runtime/supply-chain`): Trivy's
image plus syft, cosign and oras (release binaries verified against their published checksums)
and the driver script `runr-supply-chain`. Build it with `runtime/ci.sh supply-chain [--push]`;
regions without internet pull it through their registry mirror or a mirror of your own
(`RUNR_SUPPLY_CHAIN_IMAGE`).

- Namespace: `runr-supply-chain` in each region (never the project namespace: the signing key is
  out of reach of project workloads and the admission policy does not apply to these Jobs).
- The Jobs need no Kubernetes API access (`automountServiceAccountToken: false`), run with the
  region's build RuntimeClass (gVisor / Kata when builds are sandboxed — untrusted image content
  is unpacked here) and with `allowPrivilegeEscalation: false`.
- Registry access: plain HTTP to RunR's internal Zot; an external registry with credentials gets a
  docker config Secret (`runr-supply-chain-registry`) mounted read-only.
- Results come back through the pod log, one line each:
  `RUNR_STEP <step> <ok|failed|skipped> [base64 message]`, `RUNR_DIGEST <sha256:…>`,
  `RUNR_FILE <name> <base64(gzip)>`, `RUNR_DONE`. Tool output stays in files inside the Job.
  Documents larger than `RUNR_SUPPLY_CHAIN_MAX_INLINE_BYTES` (gzip'ed, default 6 MiB) stay in the
  registry only.
- Deadline: `RUNR_SUPPLY_CHAIN_JOB_DEADLINE` (default 1800 s); RunR polls the Job every 5 s.

## SBOM

syft writes the SBOM of the image digest in two formats:

- **SPDX JSON** (SPDX 2.3) — also attached as a signed in-toto attestation (`cosign attest --type
  spdxjson`) when the region signs.
- **CycloneDX JSON** (1.6).

Both are attached to the image in the region registry as **OCI referrers** (`oras attach`,
artifact types `application/spdx+json` and `application/vnd.cyclonedx+json`; Zot serves them
through the OCI referrers API), so any OCI tool finds them next to the image:

```bash
oras discover --plain-http <registry>/runr-builds/orders-api@sha256:…
cosign verify-attestation --key runr-eu-1-cosign.pub --type spdxjson --insecure-ignore-tlog <image>@<digest>
```

RunR stores both documents with the report (gzip'ed) and shows a summary: package count,
packages per ecosystem (purl type), the most frequent licenses and the generator. Downloads:
Security tab of the project, or `GET /api/projects/{project}/versions/{version}/sbom?format=spdx-json|cyclonedx-json`
(the response carries `X-Content-SHA256`).

## Vulnerability scanning

Trivy scans the image (`trivy image --scanners vuln`) by digest. RunR stores:

- counts per severity (CRITICAL, HIGH, MEDIUM, LOW, UNKNOWN), and the CRITICAL / HIGH counts of
  findings that have a fixed version;
- the findings — advisory id, severity, package, installed version, fixed version, title and an
  advisory link (Trivy's, else NVD for CVEs, GitHub for GHSA ids) — deduplicated per package and
  version, most severe and fixable first, up to `RUNR_SUPPLY_CHAIN_MAX_FINDINGS` (default 2000;
  the counts always cover every finding);
- the Trivy version and the build time of the vulnerability DB the scan used.

**Re-scans.** New advisories appear for images that did not change. Every hour RunR re-scans each
image that a live deployment runs (and each ready runtime image and uploaded container image) whose last scan is older than the
region's re-scan interval (default 24 h, `0` turns it off). A re-scan keeps the SBOM, the
referrers and the signature, and attaches the new scan as a fresh vuln attestation. Anyone with
`security.view` on the project can start one from the Security tab.

**CaaS images.** With *Also scan external CaaS images* a region produces an SBOM and a scan of the
image each CaaS version deploys — when the version is created, or at the first deploy.

## Offline and air-gapped scanning

The vulnerability database is an OCI artifact; a region can keep its own copy:

| Mode | Scans read | RunR refreshes |
|---|---|---|
| `online` (default) | Trivy's default sources (`mirror.gcr.io`) at scan time | — |
| `mirror` | `<registry>/runr-supply-chain/trivy-db:2` and `…/trivy-java-db:1` | every `RUNR_TRIVY_DB_REFRESH_HOURS` (default 12) from `RUNR_TRIVY_DB_SOURCE` / the region's source, while the region reaches it |
| `offline` | the same copy in the region registry | never by itself; *Refresh now* when a connection is available, or push the copy yourself |

Populating an air-gapped region from a connected machine:

```bash
oras cp mirror.gcr.io/aquasec/trivy-db:2 <registry>/runr-supply-chain/trivy-db:2 --to-plain-http
oras cp mirror.gcr.io/aquasec/trivy-java-db:1 <registry>/runr-supply-chain/trivy-java-db:1 --to-plain-http
```

The mirror Job copies both DBs, reads the copy back the way scans do and records the DB's build
time; the region's Supply chain tab shows it with the last refresh. The tools image itself and the
policy-controller image come through the region's registry mirror or your own mirror.

## Policy gate

Every deploy (`POST /api/projects/{project}/deploy`) passes the gate of the project's region:

| Setting | Effect |
|---|---|
| Mode `off` / `warn` / `enforce` | `enforce` blocks, `warn` deploys and audits the violations, `off` checks nothing |
| Max. critical / Max. high | block when the version's CRITICAL / HIGH count is above it (empty = no limit) |
| Count only fixable findings | count only findings with a fixed version |
| Require a finished scan | no deploy without a scan (only where the region scans that kind of image; never for a Spin app, see [WebAssembly](#overview)) |

A tenant can have its own, stricter gate (Tenants → Residency): for each field the stricter value
of region and tenant wins.

Signature verification is separate from the mode: with signing and *Verify the signature on
deploy* on, a version RunR built (Spin apps included) deploys only with a signature from one of
the region's trusted keys (the current key or a retired one that is still listed).

A refused deploy answers HTTP 422 with a message that names the region and every violated rule,
for example:

```json
{
  "message": "Deploy blocked by the supply-chain policy of region eu-1: 2 CRITICAL vulnerabilities (allowed: 0). Fix the findings and build again, or deploy with a policy override and a reason.",
  "code": "supply_chain_policy",
  "violations": [{"rule": "critical", "count": 2, "limit": 0, "enforced": true, "message": "2 CRITICAL vulnerabilities (allowed: 0)"}],
  "can_override": true,
  "report": {"counts": {"critical": 2, "high": 4, "medium": 1, "low": 0, "unknown": 0}, "…": "…"}
}
```

`code: supply_chain_scan_pending` means the scan or the signature of the version is still
running: deploy again when it finished (the Security tab shows the progress).

**Model services.** Deploying an AI model service (create with deploy, deploy, redeploy after a
change) passes data residency and the same gate on the service's image — counts and the required
scan; no signature rule, the image is external. A refused service is left stopped with the reason
as its status message; the override works the same way (audited, `security.override_policy`).
`GET /api/model-services/{service}/security` returns the image's report.

**Override.** A user with `security.override_policy` deploys a blocked version with
`override_policy: true` and `override_reason` (at least 3 characters). The override is stored
with the user, the reason, the violated rules and the deployment, listed on the project's
Security tab and audited. A running scan is waited for, not overridden.

## Signing

Each region has its own cosign key pair; keyless signing is off (no Fulcio certificate, no
transparency log upload — `--tlog-upload=false`).

- **Generation** runs in the region: a Job of the tools image calls
  `cosign generate-key-pair k8s://runr-supply-chain/runr-cosign-key` with a random password RunR
  created, so cosign writes the encrypted private key, the public key and the password straight
  into the Secret. The Job's ServiceAccount has a Role for Secrets of that namespace only; it is
  removed after the run, as is the password Secret.
- **Storage**: the Secret in the region is the working copy. RunR keeps an encrypted copy of the
  private key and its password (secret columns encrypted at rest with the application key,
  [Security](security.md#encryption-at-rest)) to restore the
  Secret when a region moves to a new cluster or the Secret is deleted. The private key and the
  password are never part of an API response or a log line.
- **Signing** happens after the scan: `cosign sign` of the image **digest**, then `cosign verify`
  with the public key; the report stores the key fingerprint (SHA-256 of the DER public key).
  `cosign attest` adds the SPDX SBOM (`spdxjson`) and the scan (`vuln`) as signed attestations.
- **Rotation**: *Rotate key* generates a new pair; the previous public key stays trusted as
  *retired* (gate and admission) until you remove the retired keys.
- **Verification by anyone**: download the public key (`GET /api/knative-regions/{region}/supply-chain/public-key`)
  and run `cosign verify --key runr-eu-1-cosign.pub --insecure-ignore-tlog=true <image>@<digest>`.

## Admission policy

Optionally per region, RunR installs **sigstore policy-controller** (Helm release
`runr-policy-controller` in `cosign-system`) and labels the region's project namespace
`policy.sigstore.dev/include=true` — the only namespace the webhook looks at
(`failurePolicy: Fail`).

Why policy-controller and not Kyverno `verifyImages`: one webhook Deployment instead of Kyverno's
admission, background, cleanup and reports controllers; purpose-built for cosign keys and
attestations; opt-in per namespace by label, so system namespaces stay untouched; no general
policy engine to keep in step with RunR.

ClusterImagePolicies (an image must pass every policy whose globs match it):

| Policy | Images | Authority |
|---|---|---|
| `runr-signed-images` | `<registry>/runr-builds/**`, `<registry>/runr-runtimes/**`, `<registry>/runr-uploads/**` (uploaded images, signed after their scan) | the region's trusted keys (current + retired); optionally the signed SPDX attestation (*Also require the signed SBOM attestation*) |
| `runr-system-images` | RunR's own images: `ghcr.io/brf-tech/**` (also through the registry mirror), Knative (`gcr.io/knative-releases/**`), Kaniko, the runtime wrapper, the queue consumer, built-in and platform runtime images and the toolchain images their WebAssembly builds run, the AI engine images of `config/ai.php` (vLLM, TGI, TEI, hf-serve), Skupper routers, plus the region's extra exemptions | static pass |
| `runr-caas-images` | CaaS registries of the allow list (`docker.io/library` → `index.docker.io/library/**`) | static pass (*Only listed registries*) or one of the trusted CaaS public keys (*Listed registries, signed by a trusted key*) |

Before any policy, the webhook resolves every image tag to a digest on the image's registry
(with the pod's pull secrets) and pins it; an image it cannot resolve is refused in both modes.
Images no policy matches: admitted while CaaS images are unrestricted (*Any registry*), refused
otherwise. Custom runtime images run code-mounted are not exempt — build them from a Dockerfile
or git source (signed in the region) or list them as exemptions.

Mode *Warn* admits every resolvable image and reports violations as admission warnings — useful before
enforcing. Changed settings (mode, exemptions, CaaS policy, keys) update an installed policy;
*Remove* unlabels the namespace first, then removes the policies and the release. *Show
policies* in the Supply chain tab (`GET …/supply-chain/admission/preview`) shows exactly what is
applied.

Platform settings of the policy-controller release (Helm `supplyChain.policyController.*`):
`chartVersion` (`RUNR_POLICY_CONTROLLER_CHART_VERSION`, default `0.10.5`), `helmRepo`
(`RUNR_POLICY_CONTROLLER_HELM_REPO`, default `https://sigstore.github.io/helm-charts`) and
`extraArgs` (`RUNR_POLICY_CONTROLLER_EXTRA_ARGS`, webhook flags, e.g. `disable-tuf=true` for
regions without internet; key-based verification needs no TUF root). The release namespace is
`RUNR_POLICY_CONTROLLER_NAMESPACE` (default `cosign-system`, via `extraEnv`).

## Data residency

A tenant can be pinned to **regions** and/or **data locations** (Tenants → Residency):

- *Allowed regions* — region ids;
- *Allowed data locations* — labels such as `TR` or `EU-DE`; a region carries its location in
  Region → Supply chain → *Data location* (case-insensitive match). A new region in an allowed
  location is usable without editing every tenant;
- *Default region* — preselected for new projects (must be allowed);
- *Enforce data residency* — off keeps the lists without applying them. Empty lists restrict
  nothing.

**One check for every entry point.** `App\Services\Residency\RegionPolicy` is the only place that
decides; features call it before they place tenant data or workloads in a region:

```php
use App\Services\Residency\RegionPolicy;

app(RegionPolicy::class)->authorize($region, RegionPolicy::SANDBOX_CREATE); // 422 when refused
if (!app(RegionPolicy::class)->allows($regionId, RegionPolicy::PREVIEW)) { … }
```

| Entry point | Action |
|---|---|
| `POST /api/projects` | `project.create` |
| `PUT /api/projects/{project}` with another region | `project.move` |
| `POST /api/projects/{project}/deploy` | `deploy` |
| `POST /api/projects/{project}/private-connections` | `binding.create` |
| `POST /api/projects/{project}/models` (model binding; the bound service's region) | `binding.create` |
| `POST /api/model-services`, `PATCH` of a running service, `POST /api/model-services/{service}/deploy` | `model_service.deploy` |
| `POST /api/models/{model}/snapshots` (model weights into a region) | `model.snapshot` |
| `POST /api/images/uploads` (an uploaded image into a region registry, [Images](images.md)) | `image.upload` |
| previews, sandboxes | `preview`, `sandbox.create` (constants for those features) |
| `POST /api/knative-regions/{region}/migrate` | region migration: see below |

A refusal answers 422 with `code: region_not_allowed`, the action and a field error on
`knative_region_id`, and is audited (`residency.denied`). Outside tenant context (single-tenant
installs, platform administrators in the central console) nothing is restricted.

**Region migration.** A region with a data location keeps it: moving the region to a new cluster
(`POST /api/knative-regions/{region}/migrate`) requires `data_location` in the request, equal to
the region's location (`code: region_location_mismatch` otherwise, audited as
`residency.migration_denied`). The region keeps its id, so every tenant pinned to it stays within
its location.

**Region pickers.** `GET /api/residency` returns the current organization's policy, every region
with `allowed`, and the default region; the project wizard greys out regions that are not allowed
and preselects the default.

## Permissions

Catalog permissions ([Roles and permissions](permissions.md)):

| Permission | Scope | Grants | Built-in roles |
|---|---|---|---|
| `security.view` | project | Security tab, reports, findings, SBOM downloads, scans | Owner, Admin, Developer, Viewer |
| `security.override_policy` | project | deploying a version the policy gate blocks (with a reason) | Owner, Admin |
| `regions.supply_chain.manage` | organization | a region's supply-chain settings, signing key, scanner DB, admission policy; the organization's policy gate | Owner, Admin |
| `tenants.residency.manage` | organization (multi-tenant) | the organization's data residency | Owner, Admin |

Reading a region's supply-chain status, public key and runtime image reports needs
`regions.view`. Region-changing routes also pass `region.mutable` (platform regions are managed by
platform administrators, tenant-owned regions by the tenant's owner / admin). Outside the platform
administration a tenant policy is reachable for the current organization only.

## Audit

Everything is written to the audit log ([Security](security.md#audited-events)); no event carries a
private key, a password or a registry credential.

Requests, recorded by the route catalog with their outcome and the model changes they made
(settings, tenant policy, overrides — secret columns by name only):

| Action | Request |
|---|---|
| `region.supply_chain.update` | `PUT …/supply-chain` (changed fields; gate, signing, admission, CaaS, DB mode and location values) |
| `region.signing_key.request` | `POST …/supply-chain/signing-key` (generate or rotate) |
| `region.signing_key.retired_remove` | `DELETE …/supply-chain/signing-key/retired` |
| `region.scanner_db.refresh` | `POST …/supply-chain/scanner-db/refresh` |
| `region.admission.install`, `region.admission.remove` | `POST` / `DELETE …/supply-chain/admission` |
| `security.scan.request` | `POST …/versions/{version}/security/scan` |
| `tenant.residency.update` | `PUT /tenants/{tenant}/security-policy` |
| `deployment.create` | `POST …/deploy`, with `policy_override` when an override was requested |

Decisions and region jobs, recorded where they happen (`App\Services\SupplyChain\SupplyChainAudit`,
also dispatched as `App\Events\SupplyChainAuditEvent`):

| Action | Result | When |
|---|---|---|
| `deploy.policy_blocked` | denied | the policy gate refused a deploy (violated rules) |
| `deploy.policy_warned` | success | gate mode warn let a deploy through with violations |
| `deploy.policy_overridden` | success | a blocked deploy went through with an override (reason, rules, override id) |
| `residency.denied` | denied | data residency refused a request (action, location) |
| `residency.migration_denied` | denied | a pinned region was to migrate without its data location |
| `image.signed` | success | an image digest was signed with the region key (fingerprint) |
| `region.signing_key.generated`, `.rotated`, `.failed` | success / failure | key generation in the region (fingerprints) |
| `region.admission.installed`, `.removed`, `.failed` | success / failure | admission policy lifecycle |
| `region.scanner_db.refreshed` | success | vulnerability DB copied into the region registry |

## API

All under `/api`, authenticated; the rules are in `api/config/route_permissions.php`.

| Method | Path | Ability |
|---|---|---|
| GET | `/projects/{project}/security` | security.view |
| GET | `/projects/{project}/versions/{version}/security` | security.view |
| POST | `/projects/{project}/versions/{version}/security/scan` | security.view |
| GET | `/projects/{project}/versions/{version}/sbom?format=spdx-json\|cyclonedx-json` | security.view |
| GET | `/projects/{project}/deployments/{deployment}/security` | security.view |
| GET | `/knative-regions/{region}/supply-chain` | regions.view |
| PUT | `/knative-regions/{region}/supply-chain` | regions.supply_chain.manage |
| POST | `/knative-regions/{region}/supply-chain/signing-key` | regions.supply_chain.manage |
| DELETE | `/knative-regions/{region}/supply-chain/signing-key/retired` | regions.supply_chain.manage |
| GET | `/knative-regions/{region}/supply-chain/public-key` | regions.view |
| POST | `/knative-regions/{region}/supply-chain/scanner-db/refresh` | regions.supply_chain.manage |
| POST / DELETE | `/knative-regions/{region}/supply-chain/admission` | regions.supply_chain.manage |
| GET | `/knative-regions/{region}/supply-chain/admission/preview` | regions.supply_chain.manage |
| GET | `/knative-regions/{region}/supply-chain/runtime-images` (+ `/{report}/sbom`) | regions.view |
| GET | `/model-services/{service}/security` (+ `/sbom`) | models.view |
| GET | `/residency` | authenticated |
| GET / PUT | `/tenants/{tenant}/security-policy` | tenants.residency.manage (residency fields), regions.supply_chain.manage (gate fields) |

`PUT /tenants/{tenant}/security-policy` drops repeated entries of `allowed_region_ids` and
`allowed_locations` instead of refusing them.

`POST /projects/{project}/deploy` accepts `override_policy` and `override_reason`.

## UI

- **Project → Security**: the region's policy; per version the image, SBOM (package count),
  severity badges and signature; *Details* with the deploy decision, the SBOM summary and
  downloads, scanner metadata, signature fingerprint and the findings table (filter by severity,
  text, fixable; advisory links); deployed versions with their badges; policy overrides.
- **Deploy** of a blocked version: the reasons and — with the permission — an override with a
  reason.
- **Region → Supply chain**: pipeline switches, vulnerability DB mode and refresh, policy gate,
  signing key (generate / rotate / public key / retired keys), admission policy (install, update,
  remove, preview), CaaS policy, data location, runtime images built in the region.
- **Tenants → Residency**: allowed regions and locations, default region, organization gate.

## Configuration

| Variable (Helm `supplyChain.*`) | Default |
|---|---|
| `RUNR_SUPPLY_CHAIN_ENABLED` (`enabled`) | `true` — supply-chain runs after builds |
| `RUNR_SUPPLY_CHAIN_IMAGE` (`toolsImage`) | `ghcr.io/brf-tech/runr-supply-chain:1.0.0` |
| `RUNR_SUPPLY_CHAIN_NAMESPACE` (`jobNamespace`) | `runr-supply-chain` |
| `RUNR_SUPPLY_CHAIN_RESCAN_HOURS` (`rescanHours`) | `24` |
| `RUNR_TRIVY_DB_SOURCE` / `RUNR_TRIVY_JAVA_DB_SOURCE` (`trivyDbSource` / `trivyJavaDbSource`) | `mirror.gcr.io/aquasec/trivy-db:2` / `…/trivy-java-db:1` |
| `RUNR_TRIVY_DB_REFRESH_HOURS` (`trivyDbRefreshHours`) | `12` |
| `RUNR_POLICY_CONTROLLER_HELM_REPO` / `_CHART_VERSION` / `_EXTRA_ARGS` (`policyController.helmRepo` / `.chartVersion` / `.extraArgs`) | `https://sigstore.github.io/helm-charts` / `0.10.5` / none |
| `RUNR_POLICY_CONTROLLER_NAMESPACE` (via `extraEnv`) | `cosign-system` |
| `RUNR_SUPPLY_CHAIN_JOB_DEADLINE`, `_CPU_REQUEST`, `_MEMORY_REQUEST`, `_CPU_LIMIT`, `_MEMORY_LIMIT`, `_SCRATCH` (via `extraEnv`) | 1800 s, 250m, 512Mi, 2, 3Gi, 20Gi |
| `RUNR_SUPPLY_CHAIN_MAX_FINDINGS`, `RUNR_SUPPLY_CHAIN_MAX_INLINE_BYTES` (via `extraEnv`) | 2000, 6 MiB |

Everything else is set per region in the UI or with `PUT /api/knative-regions/{region}/supply-chain`.

## Data model

| Table | Schema | Holds |
|---|---|---|
| `region_supply_chain_settings` | central | per region: switches, scanner DB, gate, signing key (public key, fingerprint, private key + password as secret columns encrypted at rest, retired keys), admission, CaaS policy, data location |
| `tenant_security_policies` | central | per tenant: residency (allowed regions / locations, default region, enforced) and the tenant gate |
| `image_security_reports` | central + each tenant schema | one per image (version, runtime build or uploaded image): step statuses, digest, counts, findings, SBOM summary, scanner, signature fingerprint |
| `image_sbom_documents` | central + each tenant schema | SPDX / CycloneDX documents (gzip + base64) with size and SHA-256 |
| `security_policy_overrides` | central + each tenant schema | overrides: user, reason, violations, deployment |

Reports of tenant projects live in the tenant's schema; runtime image reports, uploaded image
reports and the settings in the central schema.

## KVKK and GDPR

Capabilities that support data protection obligations (KVKK — Turkish Personal Data Protection
Law No. 6698 — and the EU GDPR):

- **Data localization**: tenants pinned to regions and data locations; every placement of tenant
  workloads and data (projects, deployments, previews, sandboxes, bindings) is checked by one
  policy; a region with a data location only migrates to a cluster in the same location.
- **Records of processing / accountability**: audit events for policy changes, refusals,
  overrides (with the reason and the person), key lifecycle and admission changes.
- **Security of processing** (GDPR Art. 32, KVKK Art. 12): SBOMs for every image, continuous
  vulnerability scanning with re-scans of running images, signed images verified at deploy and at
  pod admission, keys encrypted at rest, signing keys kept per region.
- **Supply-chain transparency**: SPDX and CycloneDX SBOMs downloadable per version and attached
  to the image in the registry, for inventory, license and incident analysis.

## Testing

- API (PHPUnit): `api/tests/Unit/SupplyChain` (Trivy parser, SBOM summary, log protocol, image
  references, Job / Secret manifests) and `api/tests/Feature/SupplyChain` (pipeline against a fake
  cluster, policy gate and override, residency at every entry point, region and tenant APIs,
  signing key, admission, scanner DB mirror, re-scans) — `php artisan test --filter=SupplyChain`.
- UI (Vitest): `vue/tests/modules/supply-chain`; Playwright: `vue/e2e/supply-chain.spec.ts`.
- Kind e2e: `KIND_LOCK=/tmp/runr-kind.lock runtime/ci.sh test-supply-chain` builds a function and
  checks its SBOM, scan, signature and attestations, the referrers and `.sig` / `.att` tags in
  Zot, installs the admission policy (signed function admitted, unsigned image denied), deploys
  the Rust example of `examples/wasm` as a Spin app under an enforced gate that requires a scan
  (signed, SBOM and scan skipped with the reason, deployed without an override, admitted and
  answering; WebAssembly support is installed for it and removed afterwards, the `wasm-rust` build
  image is built and loaded like in `test-wasm`), the policy gate with an override and the data
  location pin of region migration.
