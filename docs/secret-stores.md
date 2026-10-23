# Secret stores

A project secret variable can be a **reference** to a value in one of the organization's own
secret stores — HashiCorp Vault or OpenBao, AWS Secrets Manager, GCP Secret Manager or Azure Key
Vault — instead of a value RunR writes. The value is read in the project's region by External
Secrets Operator and handed to the workload through a Kubernetes Secret. RunR never sees the value,
and the store never gets a credential from RunR: it trusts the **identity of the region** — a
service account of the region's cluster, proven by a short-lived token the cluster signs.

```text
store "vault-prod" (your Vault / OpenBao, AWS, GCP, Azure)
   ▲ trusts: issuer of the region cluster + service account runr-ss-<store id>
   │ login with a 10-minute service account token (Kubernetes / JWT auth, workload identity)
External Secrets Operator (region)  ──fills──▶ Secret <slug>-ext-env ──secretKeyRef──▶ workload
   ▲ ExternalSecret <slug>-ext-env: DB_PASSWORD = vault-prod:apps/orders#db
RunR (control plane): store settings and references only — no value, no credential
```

Values RunR writes itself are described in [Secret environment variables](security.md#secret-environment-variables):
since pass-through they live in the region only as well — in the project's Kubernetes Secret, or in
an [OpenBao that RunR runs in the region](#openbao-in-the-region).

- [External Secrets Operator in a region](#external-secrets-operator-in-a-region)
- [Registering a store](#registering-a-store)
- [The trust a store needs](#the-trust-a-store-needs)
- [Cluster issuer requirements](#cluster-issuer-requirements)
- [Referring to a secret](#referring-to-a-secret)
- [What RunR applies in the region](#what-runr-applies-in-the-region)
- [Status](#status)
- [Lifecycle](#lifecycle)
- [OpenBao in the region](#openbao-in-the-region)
- [Limits](#limits)
- [Permissions and audit](#permissions-and-audit)

## External Secrets Operator in a region

References need External Secrets Operator (ESO) in the project's region — an optional region
feature. Install it on the region's page in the console, or with the API (`regions.manage`; a
platform region of a multi-tenant install only by a platform administrator):

```bash
curl -sS -X POST "$API/knative-regions/$REGION/external-secrets" -H "Authorization: Bearer $TOKEN"
curl -sS "$API/knative-regions/$REGION/external-secrets" -H "Authorization: Bearer $TOKEN"
```

| Field of `GET` | |
|---|---|
| `status` | `not_installed`, `installing`, `ready`, `uninstalling` or `error` |
| `message` | the reason of an error, or the queued step |
| `chart_version` / `release_chart_version` | the chart the region runs / the chart this RunR release pins |
| `installed_at`, `references` | when it was installed; how many project secrets on the region are references |
| `can_manage` | whether the caller may install or remove it |

The install is queued (`202`) and runs Helm from the control plane: the release
`external-secrets` in the namespace `external-secrets`, from `https://charts.external-secrets.io`,
at the chart version RunR pins, with the operator's image from RunR's mirror
(`ghcr.io/brf-tech/runr/reqs/external-secrets`) for the controller, the webhook and the cert
controller (requests 10m CPU and 64 MiB, a 256 MiB memory limit). Only namespaced SecretStores and
ExternalSecrets are processed — ClusterSecretStores, ClusterExternalSecrets and PushSecrets are
off: a store is usable in the namespace RunR renders it in, for the projects RunR renders there.
The status moves to `ready` once every Deployment of the release has its replicas available and
the cluster serves `external-secrets.io/v1`; after 15 minutes without, it is `error`.

| Setting (env of the API) | |
|---|---|
| `RUNR_ESO_CHART_REPO` | the chart repository (a mirror of it for regions without outside access) |
| `RUNR_ESO_CHART_VERSION` | the pinned chart version (changes together with the image) |
| `RUNR_ESO_IMAGE` | the operator's image |
| `RUNR_ESO_REFRESH_INTERVAL` | how often an ExternalSecret reads its store again when the store sets nothing (seconds, default 3600) |

- `php artisan region:reconcile` (run by every Helm upgrade) brings the operator to the chart this
  release pins wherever a region has it (step `external-secrets`); the Secrets it filled stay and
  are refreshed on its next sync. A region without the operator is left alone.
- A [region migration](regions.md#moving-a-region-to-another-cluster) installs it on the new
  cluster when the region had it ready; a rollback gives back the status the old cluster had.
- `DELETE …/external-secrets` removes it — refused (`409`) while projects on the region have
  references: the operator's CRDs go with it, and with them the Secrets it filled — and while the
  region's [OpenBao](#openbao-in-the-region) is installed, whose values reach the workloads through
  it.

## Registering a store

Stores belong to the organization: **Administration → Access & security → Secret stores** in the
console, or the API
(`secret_stores.manage`):

```bash
curl -sS -X POST "$API/secret-stores" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "vault-prod",
  "provider": "vault",
  "auth": "jwt",
  "settings": {"server": "https://vault.example.com:8200", "mount": "secret", "role": "runr-orders"},
  "refresh_interval": 900
}'
```

| Field | |
|---|---|
| `name` | lowercase letters, digits and `-`, at most 63 characters, unique in the organization |
| `provider` | `vault` (HashiCorp Vault or OpenBao), `aws`, `gcp`, `azure` — fixed once created |
| `auth` | `vault`: `kubernetes` (default) or `jwt`; the clouds: `workload_identity` |
| `settings` | the provider's settings below; anything else is refused (`422`) — there is no setting for a token, a password or a key |
| `refresh_interval` | seconds between two reads of a reference (60–86400); empty uses `RUNR_ESO_REFRESH_INTERVAL` |

**`vault`** — HashiCorp Vault or OpenBao, KV secrets engine:

| Setting | |
|---|---|
| `server` | required: the address, e.g. `https://vault.example.com:8200` |
| `mount` | the KV mount (default `secret`) |
| `kv_version` | `v1` or `v2` (default `v2`) |
| `vault_namespace` | the namespace (Vault Enterprise, OpenBao namespaces) |
| `ca_bundle` | the PEM certificates of the server's CA (public; anything but certificates is refused) |
| `auth_mount` | the auth method's mount (default `kubernetes` or `jwt`) |
| `role` | required: the role the region logs in with |
| `audience` | `jwt` only: the audience of the region's tokens (default `vault`) |

**`aws`** — AWS Secrets Manager:

| Setting | |
|---|---|
| `region` | required: e.g. `eu-central-1` |
| `role_arn` | required: the IAM role the region assumes, `arn:aws:iam::<account>:role/<name>` |
| `audience` | the token audience (default `sts.amazonaws.com`) |

**`gcp`** — GCP Secret Manager:

| Setting | |
|---|---|
| `project_id` | required: the Google Cloud project of the secrets |
| `audience` | required: the workload identity provider, `//iam.googleapis.com/projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<provider>` |
| `service_account_email` | a Google service account to impersonate (optional: grant the federated principal access directly instead) |

**`azure`** — Azure Key Vault:

| Setting | |
|---|---|
| `vault_url` | required: `https://<name>.vault.azure.net` |
| `tenant_id` | required: the Microsoft Entra tenant |
| `client_id` | required: the app registration or managed identity that holds the federated credential |

`GET /api/secret-stores` lists the stores with `references` (project secrets that refer to each)
and `regions` (the store's state per region, [Status](#status)), and a `providers` list with each
provider's ways to authenticate and settings. `PUT /api/secret-stores/{id}` changes a store (not
its provider); the regions get the change with the next deploy of a project that uses it, or with
**Verify**.

## The trust a store needs

Each store logs in from a region as the service account `runr-ss-<store id>` (lowercase) in the
region's project namespace. What the store has to trust is on the store's page — **Trust**, per
region — or:

```bash
curl -sS "$API/secret-stores/$STORE/trust?region_id=$REGION" -H "Authorization: Bearer $TOKEN"
runr secret-stores trust vault-prod --region eu-1
```

| Field | |
|---|---|
| `issuer` | the region cluster's service account issuer (from its `/.well-known/openid-configuration`) |
| `publicly_reachable` | whether a cloud provider could fetch the issuer's discovery document: https and no cluster-internal or private address — a hint, the provider's own check decides |
| `jwks_uri`, `jwks` | where the cluster publishes its token signing keys, and the keys (`/openid/v1/jwks`) |
| `pem_keys` | the same public keys as PEM |
| `namespace`, `service_account` | where the store's service account is, and its name |
| `subject` | `system:serviceaccount:<namespace>:runr-ss-<store id>` — the `sub` of its tokens |
| `audience` | the `aud` of its tokens (none for Vault Kubernetes auth) |
| `eso_status` | External Secrets Operator in the region |

After setting the trust up, **Verify** (`POST /api/secret-stores/{id}/verify` with `region_id`)
applies the store's service account and SecretStore in the region and answers with what the
operator says of it: `ready`, or `failed` with the store's error. It needs the operator `ready`
in the region.

The examples use `<namespace>`, `<store id>` and `<issuer>` from that answer; `bao` is OpenBao's
CLI, `vault` takes the same arguments.

### Vault / OpenBao: Kubernetes auth

The store checks each token with the region cluster's TokenReview API, so it has to reach the
region's API server. RunR lets the store's service account create TokenReviews (a
ClusterRoleBinding `runr-ss-<store id>-<namespace>-tokenreview` to `system:auth-delegator`), so
the auth method needs no reviewer token: without `token_reviewer_jwt` the store reviews each token
with the token itself, and no credential is kept anywhere. Configure the auth method with the
cluster's address and CA only:

```bash
bao auth enable kubernetes
bao write auth/kubernetes/config \
  kubernetes_host=https://<api server>:6443 kubernetes_ca_cert=@cluster-ca.crt
bao write auth/kubernetes/role/runr-orders \
  bound_service_account_names=runr-ss-<store id> \
  bound_service_account_namespaces=<namespace> \
  token_policies=orders-read token_ttl=10m
```

Store settings: `auth: kubernetes`, `role: runr-orders` (and `auth_mount` if the method is not
mounted at `kubernetes`).

### Vault / OpenBao: JWT auth

The store checks the token's signature against the cluster issuer's keys — it needs no access to
the cluster. With an issuer the store can reach:

```bash
bao auth enable jwt
bao write auth/jwt/config oidc_discovery_url=<issuer> bound_issuer=<issuer>
```

Otherwise give it the public keys RunR shows (`pem_keys`; repeat the argument for several keys):

```bash
bao write auth/jwt/config bound_issuer=<issuer> jwt_validation_pubkeys=@issuer-key.pem
```

Then the role, bound to the service account's subject and the audience:

```bash
bao write auth/jwt/role/runr-orders role_type=jwt user_claim=sub \
  bound_subject=system:serviceaccount:<namespace>:runr-ss-<store id> \
  bound_audiences=vault \
  token_policies=orders-read token_ttl=10m
```

Store settings: `auth: jwt`, `role: runr-orders` (and `audience` if you bound another one). When
the cluster rotates its signing key, give the store the new keys.

A read policy for both, KV v2 under the mount `secret`:

```hcl
path "secret/data/apps/orders" {
  capabilities = ["read"]
}
```

### AWS Secrets Manager

AWS trusts the issuer as an IAM OIDC identity provider and lets the service account assume a role
with its token (`AssumeRoleWithWebIdentity`). The issuer has to be publicly reachable
([below](#cluster-issuer-requirements)).

```bash
aws iam create-open-id-connect-provider --url <issuer> --client-id-list sts.amazonaws.com
```

The role's trust policy (`<issuer host/path>` is the issuer without `https://`):

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"Federated": "arn:aws:iam::<account>:oidc-provider/<issuer host/path>"},
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {"StringEquals": {
      "<issuer host/path>:sub": "system:serviceaccount:<namespace>:runr-ss-<store id>",
      "<issuer host/path>:aud": "sts.amazonaws.com"
    }}
  }]
}
```

Allow the role `secretsmanager:GetSecretValue` (and `secretsmanager:DescribeSecret`) on the
secrets, and a KMS key's `kms:Decrypt` when they use one. Store settings: `region`, `role_arn`.
RunR sets the role and the audience as annotations of the service account
(`eks.amazonaws.com/role-arn`, `eks.amazonaws.com/audience`).

### GCP Secret Manager

GCP trusts the issuer through a workload identity pool provider. It can fetch the issuer's
discovery document, or take the keys uploaded when the issuer is not reachable from Google:

```bash
gcloud iam workload-identity-pools create runr --location=global
gcloud iam workload-identity-pools providers create-oidc region-eu-1 \
  --location=global --workload-identity-pool=runr \
  --issuer-uri=<issuer> \
  --attribute-mapping=google.subject=assertion.sub
  # without a reachable issuer: also --jwk-json-path=jwks.json (the jwks RunR shows)
```

Grant the federated principal access to the secrets, directly:

```bash
gcloud secrets add-iam-policy-binding orders-db \
  --role=roles/secretmanager.secretAccessor \
  --member=principal://iam.googleapis.com/projects/<number>/locations/global/workloadIdentityPools/runr/subject/system:serviceaccount:<namespace>:runr-ss-<store id>
```

or let it impersonate a Google service account (`roles/iam.workloadIdentityUser` for the same
principal on that account) and set `service_account_email`. Store settings: `project_id`,
`audience` = `//iam.googleapis.com/projects/<number>/locations/global/workloadIdentityPools/runr/providers/region-eu-1`.

### Azure Key Vault

Azure trusts the issuer through a federated identity credential of an app registration or a
user-assigned managed identity. The issuer has to be publicly reachable.

```bash
az identity federated-credential create --name runr-eu-1 \
  --identity-name orders-secrets --resource-group <group> \
  --issuer <issuer> \
  --subject system:serviceaccount:<namespace>:runr-ss-<store id> \
  --audiences api://AzureADTokenExchange
```

Give the identity the **Key Vault Secrets User** role on the vault. Store settings: `vault_url`,
`tenant_id`, `client_id` (of that identity); RunR sets the last two as the service account's
`azure.workload.identity/*` annotations.

## Cluster issuer requirements

Every region cluster signs its service account tokens with its own keys and names itself in them
as the issuer (`--service-account-issuer` of the API server). RunR reads both from the cluster's
API server and shows them per region.

| Store | What it needs of the issuer |
|---|---|
| Vault / OpenBao, Kubernetes auth | nothing public: the store reaches the region's API server (TokenReview) |
| Vault / OpenBao, JWT auth | the discovery URL reachable from the store, or the keys pasted (`jwt_validation_pubkeys`) |
| GCP | a reachable issuer, or the JWKS uploaded to the provider |
| AWS, Azure | an `https` issuer with a publicly trusted certificate that serves `/.well-known/openid-configuration` and the JWKS it names, reachable from the internet |

Managed Kubernetes services offer a public issuer. A self-managed cluster usually has an internal
one (`https://kubernetes.default.svc.cluster.local`); for AWS and Azure, set the API server's
`--service-account-issuer` to a public `https` URL, publish the discovery document and the JWKS
there (static files are enough; `--service-account-jwks-uri` names where the keys are), and
publish the keys again when they rotate. Changing the issuer of a running cluster invalidates the
tokens issued before — plan it with the cluster's other users of service account tokens.

A [region migration](regions.md#moving-a-region-to-another-cluster) puts the region on another
cluster, with its own issuer and keys: the trust page shows the new cluster's once the move has
started. Have the stores trust it, and keep the old trust until the move is completed so a
rollback keeps working.

## Referring to a secret

A reference names a store, a path in it and optionally a key inside the secret:

| Provider | Path | Key |
|---|---|---|
| Vault / OpenBao | the path under the KV mount (`apps/orders`) | a field of the secret |
| AWS Secrets Manager | the secret's name or ARN | a key of a JSON secret |
| GCP Secret Manager | the secret's name | a key of a JSON secret |
| Azure Key Vault | the secret's name (RunR writes it as `secret/<name>`; `key/…` and `cert/…` are kept) | a key of a JSON secret |

Without a key the variable gets the whole value. In the console: the project's environment step →
**Secret variables** → **Add reference**. With the API (`env.edit`):

```bash
curl -sS -X PUT "$API/projects/$PROJECT/secrets/DB_PASSWORD" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"reference": {"store_id": "'"$STORE"'", "path": "apps/orders", "key": "db_password"}}'
```

With the CLI ([cli.md](cli.md#environment-variables-and-secrets)) and the MCP server
(`secret_ref_set`, [mcp.md](mcp.md#tools)):

```bash
runr env ref DB_PASSWORD vault-prod:apps/orders#db_password
```

A reference follows the same naming rules and limits as other secret variables, and a name can move
between a value and a reference: setting one replaces the other. The change reaches the workload
with the next deploy (`requires_deploy: true`); a value the variable had in the region stays there
until that deploy drops it.

## What RunR applies in the region

A deploy of a project with references applies, in the region's project namespace, objects that
hold no value and no credential:

```yaml
apiVersion: v1
kind: ServiceAccount                        # one per store: the identity the store trusts
metadata:
  name: runr-ss-01k7…                       # runr-ss-<store id>
  labels: {app.kubernetes.io/managed-by: runr, runr/secret-source: store, runr/secret-store: 01k7…}
automountServiceAccountToken: false         # no pod runs as it; ESO requests its tokens (10 minutes)
---
apiVersion: external-secrets.io/v1
kind: SecretStore                           # one per store
metadata:
  name: runr-ss-01k7…
spec:
  provider:
    vault:
      server: https://vault.example.com:8200
      path: secret
      version: v2
      auth:
        jwt:
          path: jwt
          role: runr-orders
          kubernetesServiceAccountToken:
            serviceAccountRef: {name: runr-ss-01k7…}
            audiences: [vault]
            expirationSeconds: 600
---
apiVersion: external-secrets.io/v1
kind: ExternalSecret                        # one per project: every reference of it
metadata:
  name: orders-ext-env
  labels: {app.kubernetes.io/managed-by: runr, runr/project-id: 01k7…, runr/secret-source: external}
spec:
  refreshInterval: 900s                     # the shortest of its stores'
  target: {name: orders-ext-env, creationPolicy: Owner}
  data:
    - secretKey: DB_PASSWORD
      remoteRef: {key: apps/orders, property: db_password}
      sourceRef: {storeRef: {name: runr-ss-01k7…, kind: SecretStore}}
```

The operator fills the Secret `<slug>-ext-env` (owned by the ExternalSecret), and the workload
reads each reference through a `secretKeyRef` of it. For a Vault / OpenBao store with Kubernetes
auth the deploy also applies one cluster-scoped object: the ClusterRoleBinding
`runr-ss-<store id>-<namespace>-tokenreview`, which lets the store's service account create
TokenReviews (`system:auth-delegator`) so the store can review its tokens without a reviewer
credential. The objects carry the organization's `runr/tenant` label in a multi-tenant install;
deleting the store removes its SecretStore, service account and binding from the regions where
the operator runs, deleting the organization removes them with the rest of its objects.

## Status

`secrets:sync-status` runs every two minutes (central schema and every tenant schema). Per region
it lists the project namespace's ExternalSecrets (`runr/secret-source=external`) and SecretStores
(`runr/secret-source=store`) and copies their `Ready` condition:

- onto every reference of the project (`sync`): `synced`, `failed` with the store's message, or
  `pending`, and `synced_at`, the operator's last read. The operator reports per ExternalSecret,
  so all references of a project share it. A reference its project has not been deployed with
  since is `pending` ("Not synced yet: deploy the project to apply the reference.");
- onto the store, per region (`regions` of the store): `ready`, `failed` or `pending`.

**Sync now** in the console, `POST /api/projects/{project}/secrets/refresh` or `runr env sync` asks
the operator to read the project's references again right away (its `force-sync` annotation) and
answers with the list and their status. `secrets:sync-status` (and `GET …/external-secrets`) also
moves an operator install from `installing` to `ready`.

## Lifecycle

- A deploy of a project with references needs the operator `ready` in its region; otherwise it
  fails with "Secret store references need External Secrets Operator in region …". A reference to
  a store that was removed fails it too.
- A project without references (any more) has its ExternalSecret removed by the next deploy, and
  the operator's Secret with it.
- Stopping the project, deleting a deployment and deleting the project remove the ExternalSecret;
  the next deploy applies it again. The store's SecretStore and service account stay (other
  projects use them).
- A [preview environment](git-deploy.md) reads the project's `<slug>-ext-env`; its deploy applies
  the project's objects, also before the project's first deploy. A fork without *expose secrets*
  reads none.
- Until an upgrade from 0.5.10 is finalized, setting a reference is refused (`409`,
  `upgrade_not_finalized`): RunR 0.5.10, which a rollback would install again, cannot give a
  reference's value to a workload ([Rolling back to 0.5.10](security.md#rolling-back-to-0510)).
  Fresh installations are finalized from the start.
- Removing a store is refused (`409`, the projects named) while a project secret refers to it;
  otherwise its SecretStore and service account are removed from every region where the operator
  runs.
- Deleting an organization removes its ExternalSecrets, SecretStores and service accounts with
  every other labelled resource of it; a migration rollback removes the projects' ExternalSecrets
  from the cluster it leaves.

## OpenBao in the region

Besides a store of your own, a region can run an OpenBao that **RunR installs and operates** — the
third place a project secret value can live (`source: openbao`), and the issuer of
[dynamic Postgres credentials](bindings.md#dynamic-credentials). While it runs, new secret values
of the region's projects go into it instead of the Kubernetes Secret `<slug>-secret-env`: they are
encrypted at rest by OpenBao, versioned, recorded in OpenBao's audit log, and each project reads
its own values with an identity of its own. RunR still keeps metadata only (name, version, keyed
fingerprint — [pass-through](security.md#pass-through)).

```text
RunR (control plane) ──API server service proxy──▶ OpenBao (runr-openbao, https 8200)
   login: service account runr-control (TokenRequest, 10 min)   KV v2  runr/data/projects/<project id>
   writes values key by key, keeps no token                     database secrets engine (bindings)
                                                                       ▲ Kubernetes auth
External Secrets Operator ── SecretStore runr-bao-<project id> ────────┘ role runr-project-<project id>
   └─fills─▶ Secret <slug>-bao-env ──secretKeyRef──▶ workload
```

It needs [External Secrets Operator](#external-secrets-operator-in-a-region) in the region — the
workloads read OpenBao through it. Install it first; the install answers `422` otherwise, and the
operator cannot be removed while OpenBao is installed (`409`).

### Installing it

On the region's **Secrets** tab in the console, or with the API (`regions.manage`; a platform
region of a multi-tenant install only by a platform administrator):

```bash
curl -sS -X POST "$API/knative-regions/$REGION/openbao" -H "Authorization: Bearer $TOKEN"
curl -sS "$API/knative-regions/$REGION/openbao" -H "Authorization: Bearer $TOKEN"
```

| Field of `GET` | |
|---|---|
| `status` | `not_installed`, `installing`, `ready`, `uninstalling` or `error` |
| `message` | the reason of an error, or the queued step |
| `version` / `release_version` | the OpenBao version the region runs / the one this RunR release pins |
| `installed_at` | when it was installed |
| `default_for_values` | whether new secret values of the region's projects go to OpenBao |
| `values` | how many secret variables of the region's projects live in it, and how many binding attachments get dynamic credentials from it |
| `eso_status` | the status of External Secrets Operator in the region |
| `can_manage` | whether the caller may install, change or remove it |

The install is queued (`202`) and applies RunR's own manifests — no Helm chart — in the namespace
`runr-openbao`:

- a **StatefulSet** `openbao` with one replica: the server (image
  `ghcr.io/brf-tech/runr/reqs/openbao:2.4.1`, `RUNR_OPENBAO_IMAGE`; requests 50m CPU and 128 MiB,
  a 512 MiB memory limit; non-root, read-only root filesystem) and an **unsealer** next to it;
- **file storage** on a volume of `2Gi` (`RUNR_OPENBAO_STORAGE_SIZE`; the cluster's default
  StorageClass, or `RUNR_OPENBAO_STORAGE_CLASS`);
- **TLS**: RunR issues a CA of its own and a server certificate for the Service names (five
  years); the key pair goes into the region's Secret `runr-openbao-tls` only, and RunR keeps the CA
  certificate — public — for the SecretStores that trust it (`caBundle`). The CA's key is dropped;
- the **Service** `openbao` (https 8200), the ConfigMap with the configuration (no UI, `mlock`
  off), the service accounts `openbao` and `runr-control`, and the ClusterRoleBinding
  `runr-openbao-tokenreview` that lets OpenBao review Kubernetes tokens (`system:auth-delegator`).

Once the server answers (at most five minutes), the first install:

1. **initializes** it with one key share — the **unseal key** goes into the region's Secret
   `runr-openbao-unseal` and nowhere else: never into RunR's database, a queue or a log;
2. unseals it;
3. **configures** it with the root token: Kubernetes auth against its own cluster, the KV v2 mount
   `runr/`, the database secrets engine (`database/`), an audit log to the container's output
   (OpenBao HMACs the fields, values never appear in it), and RunR's own policy and role
   `runr-control`;
4. **revokes the root token**.

The unsealer unseals the server after every restart with the key in `runr-openbao-unseal`. A
reinstall — the same cluster, or a second region on it — finds OpenBao initialized: it unseals it
when it is sealed and checks RunR's own login. A TLS certificate with more than 60 days left is
kept.

### How RunR reaches it

Through the region's API server (the service proxy to `https:openbao:8200` in `runr-openbao`): RunR
needs no network path into the cluster. It logs in with **Kubernetes auth as the service account
`runr-control`**: a token of that account from the TokenRequest API, traded for an OpenBao token
that lives ten minutes in the API's memory and is never stored. The policy `runr-control` allows
the projects' values under `runr/data/projects/*`, the projects' policies and roles
(`runr-project-*`) and the database engine's connections and roles of bindings (`runr-*`) — not
the auth methods, the mounts or its own policy: those were set once with the root token.

### Each project's identity

| Object | |
|---|---|
| KV v2 secret `runr/data/projects/<project id>` | the project's values, one key per variable |
| policy and Kubernetes auth role `runr-project-<project id>` | read that one secret and the credentials of the project's [dynamic bindings](bindings.md#dynamic-credentials), nothing else; bound to the service account below in the region's project namespace; tokens live ten minutes |
| ServiceAccount `runr-bao-<project id>` (project namespace) | the identity External Secrets Operator logs in as for the project |
| SecretStore `runr-bao-<project id>` | ESO's Vault provider: `https://openbao.runr-openbao.svc:8200`, mount `runr`, KV v2, RunR's CA, Kubernetes auth with the role and account above |
| ExternalSecret `<slug>-bao-env` | one entry per variable (`projects/<project id>`, the variable as property), fills the Secret of that name; refreshed every five minutes |

The workload reads each variable through a `secretKeyRef` of `<slug>-bao-env`. A deploy applies
these objects, writes the project's policy and role, checks that every value is in OpenBao — a
missing one fails the deploy with its name — and drops the keys of variables removed since.

### Values in OpenBao

- **Where new values go**: while OpenBao is `ready`, new values of the region's projects go to it
  (`default_for_values: true`, the default); `PATCH …/openbao` with `{"default_for_values": false}`
  sends them to the Kubernetes Secret again. Values already written stay where they are until they
  are set again; a variable set again moves, and the next deploy drops it from the other place.
- **Writing** patches the key into the project's KV secret (creating it on the first write) and asks
  the ExternalSecret to sync at once (`force-sync`); a new value reaches every container that starts
  afterwards, every container with the next deploy (`requires_deploy: true`). An OpenBao that does
  not answer fails the request with `503` (`region_unreachable`) and changes nothing.
- **Reading back** (`GET …/secrets/{name}/value`, `secrets.view_values`) reads OpenBao;
  `matches_fingerprint` as for the region's Secret. The list shows `stored_in: openbao`.
- **Removing** a variable removes its key at once when nothing of the project runs, otherwise with
  the next deploy.
- **The database move** (`php artisan secrets:move-to-region`, [upgrade](upgrade.md)) writes the
  values still in RunR's database to where the region keeps new values — OpenBao when it runs there
  and takes them — verifies them by fingerprint and only then clears them; until an upgrade from
  0.5.10 is finalized it keeps them as encrypted copies for a rollback, as it does for every value
  written into OpenBao meanwhile ([Rolling back to 0.5.10](security.md#rolling-back-to-0510)).
- **Deleting the project** destroys its KV secret (every version), its policy and its role.
- **A project moved to another region** has its values copied to where the new region keeps new
  values: its OpenBao, or its Kubernetes Secret when it has none; the variables follow.

### Operations

- `php artisan region:reconcile` (run by every Helm upgrade) brings OpenBao to the image this release
  pins and issues a new TLS certificate when the old one has 60 days or less (step `openbao`): the
  install runs again, the pod restarts and the unsealer unseals it; the data stays on its volume. A
  region without OpenBao is left alone.
- A [region migration](regions.md#moving-a-region-to-another-cluster) installs OpenBao on the new
  cluster (after the operator is ready there) — a new instance with its own unseal key — and copies
  the projects' values into it before the projects are deployed; the old cluster's OpenBao keeps
  running for a rollback. A rollback gives back the status the old cluster had, copies the values
  set meanwhile back, and removes the projects' values, policies and roles from the OpenBao of the
  cluster it leaves. The Kind suite `runtime/ci.sh test-region-move` checks this both ways for
  values in the region's Secret, values in OpenBao and references through External Secrets
  Operator.
- `DELETE …/openbao` removes it — the namespace with its volume, and the TokenReview binding —
  refused (`409`) while secret values of the region's projects live in it or bindings of them get
  dynamic credentials from it.
- One OpenBao per cluster: regions on the same cluster (the same API server) share it, each project
  under its own path, policy and role. Removing it for one region while another region on the
  cluster still has it leaves it running; only the first region's status goes back to
  `not_installed`. The same holds for External Secrets Operator (one release per cluster).

### Security notes

- Whoever can read Secrets in the namespace `runr-openbao` can unseal OpenBao (`runr-openbao-unseal`)
  and impersonate its TLS (`runr-openbao-tls`): keep that namespace to cluster administrators.
- **Back up** the volume (`data-openbao-0`) **and** the Secret `runr-openbao-unseal` together: one
  without the other does not give the values back. RunR keeps neither.
- The root token is revoked after the first configuration. Changing what the install configured
  with it (auth methods, mounts, RunR's policy) needs a new root token, generated with the unseal key
  (`bao operator generate-root`).
- The ExternalSecrets and SecretStores carry the project's and the organization's labels; deleting an
  organization removes them with every other labelled resource of it.

## Limits

- No static credential: a store has no setting for a token, a password, a key or a client secret.
  A store that cannot trust the region's identity cannot be used from RunR.
- RunR cannot read a reference's value back (`GET …/value` answers `422`): read it in the store.
- Namespaced stores only: each store exists as one SecretStore per region namespace.
- RunR only reads from stores; it never writes to them.

## Permissions and audit

| Permission | Built-in roles | |
|---|---|---|
| `secret_stores.view` | Owner, Admin, Developer | the stores and their trust (choosing a store for a reference) |
| `secret_stores.manage` | Owner, Admin | register, change, verify and remove stores |
| `env.edit` | Owner, Admin, Developer | set a reference on a project |
| `regions.manage` | Owner, Admin | install and remove the operator and the region's OpenBao, choose where new values go |

Audited: `secret_store.create`, `secret_store.update`, `secret_store.delete`,
`secret_store.verify` (region and status), `project.secret.set` / `.delete` / `.refresh`,
`region.external_secrets.install`, `region.external_secrets.uninstall`, `region.openbao.install`,
`region.openbao.update` (`default_for_values`), `region.openbao.uninstall`
([security.md](security.md#audited-events)). Store changes are recorded with field names; the
settings hold no secret.

API summary:

| Method | Path | Permission |
|---|---|---|
| `GET` | `/api/secret-stores` | `secret_stores.view` |
| `POST` | `/api/secret-stores` | `secret_stores.manage` |
| `GET` | `/api/secret-stores/{id}` | `secret_stores.view` |
| `PUT` | `/api/secret-stores/{id}` | `secret_stores.manage` |
| `DELETE` | `/api/secret-stores/{id}` | `secret_stores.manage` |
| `GET` | `/api/secret-stores/{id}/trust?region_id=…` | `secret_stores.view` |
| `POST` | `/api/secret-stores/{id}/verify` (`region_id`) | `secret_stores.manage` |
| `GET` | `/api/knative-regions/{id}/external-secrets` | `regions.view` |
| `POST` / `DELETE` | `/api/knative-regions/{id}/external-secrets` | `regions.manage` |
| `GET` | `/api/knative-regions/{id}/openbao` | `regions.view` |
| `POST` / `PATCH` / `DELETE` | `/api/knative-regions/{id}/openbao` | `regions.manage` |
| `PUT` | `/api/projects/{project}/secrets/{name}` with `reference` | `env.edit` |
| `POST` | `/api/projects/{project}/secrets/refresh` | `env.edit` |
