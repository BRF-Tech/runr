# Internal network and access modes

Every project of a region can call the region's other projects inside the cluster, under a name
of its own — and only the projects it allows may call it. This page covers both sides of a
project's network:

- **Internal network.** A project has an internal name `<project>.<organization>` and the host
  `<project>.<organization>.runr.internal`. Projects are isolated from each other, also inside one
  organization: a project admits calls from itself, from RunR and from the projects on its
  **allow list**. Another organization's project needs a **grant** on that list, which the
  platform can switch off. A call from a project's own pod is known as that project by its
  address; a caller elsewhere sends the project's token, `RUNR_INTERNAL_TOKEN`, in the
  `X-Runr-Internal-Token` header. **Every region enforces this, also one upgraded from an earlier
  release: a call between two projects needs an entry on the callee's allow list.**
- **Access mode.** Who reaches the project from outside the cluster: anyone (`public`, the
  default), the addresses of an IP allow list (`allowlist`) or nobody (`internal`: the project has
  no public URL and is reached on the internal network only).

Both take effect at once, without a redeploy. Change them in the console (the project's
**Network** tab), with `runr network`, the API, the MCP tools or the `network` section of
`runr.yaml`; reading needs `projects.view`, changing `network.manage`
([permissions.md](permissions.md#configuration)).

## Calling another project

| | |
|---|---|
| Internal name | `<project>.<organization>`: the project's name (its slug) and its organization's slug. Projects outside any organization — every project of a single-tenant install — belong to `platform` |
| Host | `<project>.<organization>.runr.internal`, plain HTTP on port 80. The cluster DNS sends `*.runr.internal` to the region Traefik's private entry point |
| Caller | the project of the pod the call comes from: RunR's pods of a project — its Knative revisions, the runs of a schedule trigger, the queue consumer of a queue trigger — carry its id (label `runr/project-id`), and the authz knows them by their address. A caller that is no such pod (a tool of your own, a pod behind a proxy that hides its address) sends the token |
| Token | `RUNR_INTERNAL_TOKEN` in every workload of the project, from the project's Secret `<project>-secret-env` (never as a value in the spec); sent in `X-Runr-Internal-Token` it names the project wherever the call comes from, and wins over the address |
| Own name | `RUNR_INTERNAL_NAME` |

The project `checkout` calls the project `orders` of its own organization `acme`, once `orders`
has `checkout` on its allow list:

```python
import urllib.request

req = urllib.request.Request("http://orders.acme.runr.internal/reserve", data=b'{"sku": "A-1"}',
                             headers={"Content-Type": "application/json"})
print(urllib.request.urlopen(req).read())
```

```js
const res = await fetch('http://orders.acme.runr.internal/reserve', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ sku: 'A-1' }),
})
```

From somewhere that is no pod of the project, with the token:

```sh
curl -H "X-Runr-Internal-Token: $RUNR_INTERNAL_TOKEN" http://orders.acme.runr.internal/
```

The Knative Service's cluster-local hosts (`<service>.<namespace>`, `.svc`, `.svc.cluster.local`,
[regions.md](regions.md#cluster-local-services-and-network-isolation)) are checked the same way;
`runr.internal` names the project by its name instead of its Knative Service.

What the callee gets: the request without the caller's token (a callee could otherwise replay it)
and the verified caller in `X-Runr-Caller` (`checkout.acme`, or `runr:system` for RunR). What the
caller gets when the call is refused, before the request reaches the callee:

| Status | When |
|---|---|
| `401` | no token from an address that is no pod of a project; a token of another region or another key, a malformed or expired one |
| `403` | the caller is not on the callee's allow list, or it is another organization's project and the platform switched such grants off |

Both answer `{"error": "<reason>"}`. A host RunR does not manage (a Knative Service it did not
deploy) is passed through as before.

## Allow lists

A project admits calls from

- **itself**: a function that calls its own URL, a queue consumer delivering to its project;
- **RunR** (`runr:system`): the web shell's wake-up and keep-alive and the model playground, which
  sign a token valid for two minutes per call;
- **the projects on its allow list.**

The list starts empty. Add a project of the same organization by its name, another organization's
project by its internal name `<project>.<organization>` — a **grant** to that organization, marked
as such in the console and the API. The callee decides who may call it; the caller needs nothing
but its token.

```sh
runr network callers add checkout          # a project of this organization
runr network callers add billing.globex    # another organization's project: a grant
runr network callers                       # the list
runr network callers rm billing.globex
```

A renamed project gets the new name in its organization's allow lists; a deleted one is removed
from them (a later project of the same name does not inherit its grants). Allow lists in other
organizations keep naming it by its old name.

**Cross-organization grants** are allowed unless a platform administrator switches them off
(Administration → Settings → **Cross-organization grants**, outside any organization; API `PATCH
/settings` with `internal_cross_tenant_grants: false`). Switched off, no new grant can be added
and the existing ones stop admitting calls at once; they work again when the setting is switched
back on.

A change reaches the project's region at once and is checked within ten seconds (the authz reads
the policies every ten seconds).

### Model services, queue triggers and previews

- **Model services** ([huggingface.md](huggingface.md#binding-models-to-functions)) are cluster-local
  Knative Services on the internal network too. Their allow list is the projects bound to them; a
  binding added or removed reaches the region at once. A bound project calls the service as
  before, at `RUNR_MODEL_<ALIAS>_URL` without any header: its pods are known by their address.
- **Queue triggers and the event gateway** ([triggers.md](triggers.md)) deliver each event as the
  project: its pod's address, and the project's `RUNR_INTERNAL_TOKEN` in `X-Runr-Internal-Token`.
- **Environments** ([environments.md](environments.md)) are workloads of their own on the internal
  network: the environment `staging` of `shop` is `shop-staging.<organization>` at
  `shop-staging.<organization>.runr.internal`, with its own `RUNR_INTERNAL_TOKEN`. It follows the
  project's access mode, and its allow list is the project's in its own environment: `billing` on
  `shop`'s list lets `billing-staging` call `shop-staging` — staging calls staging, never
  production. Its pods (labels `runr/environment-of` and `runr.dev/internal-name`) are known by
  their address as the environment, never as the project's own workload.
- **Preview environments** ([git-deploy.md](git-deploy.md)) answer as their project (its allow
  list applies) on their cluster-local hosts; they have no `runr.internal` host. A preview of a
  pull request from the same repository gets the project's token, a fork preview without the
  "expose secrets" setting gets none.

## Access modes

| Mode | Public URL, custom domains, gateway route | Internal network |
|---|---|---|
| `public` (default) | answer everyone | yes |
| `allowlist` | answer the IP addresses and CIDR ranges of `allowed_cidrs` (up to 64); others get `403` | yes |
| `internal` | none: the Knative Service is cluster-local, the public URL stops answering (`404`), custom domains are not routed (they show why) and a gateway route leaves the project out | yes |

```sh
runr network set --access allowlist --allow-cidr 203.0.113.0/24 --allow-cidr 198.51.100.7
runr network set --access internal
runr network set --access public
```

- **allowlist** checks the client's address where the request enters the region: on the public
  host and on every custom domain and gateway route of the project, before any other middleware.
  Behind a load balancer the region trusts (`ingress_trusted_ips`,
  [regions.md](regions.md#general)) the client is the first address of `X-Forwarded-For`, counted
  from the right, that is not one of the trusted ones; without trusted addresses it is the
  connection's own.
- **internal** suits a backend other projects call: an API behind a public front end, a worker
  only queue messages reach. A project with the `workflow` trigger stays public (the durable
  workflows engine calls it on its public URL); internal is refused for it. The console asks
  before it takes a public URL away.

The access mode is independent of the internal network: an `allowlist` or `internal` project
still admits only its allow list on the internal network, and a `public` project's internal host
is not public.

## Enforce and audit

Every region **enforces** the internal network — a new one and one upgraded from a release before
it alike: a call the callee's allow list does not admit gets `401` / `403`. After the upgrade,
**a call between two projects needs an entry on the callee's allow list**. Allow lists are new in
this release, so list the calls before the upgrade and add the entries right after it (they take
effect within ten seconds); until then the calls are refused. RunR's own traffic needs nothing: a queue consumer delivering to its project, a
function calling the model service it is bound to and the region demo's frontend calling its
backend (granted by the upgrade) keep working without a redeploy, and RunR's own calls (web
shell, model playground) carry their token.

The region setting `internal_auth` (Administration → Platform → Knative Regions → the region →
General → **Settings**; `runr regions set REGION --set internal_auth=audit`; API
`PUT /knative-regions/{id}/settings`) can relax that:

| `internal_auth` | A call the allow list refuses |
|---|---|
| unset or `enforce` | gets `401` / `403` |
| `audit` | is admitted, and the authz logs it |

`audit` is for an operator who wants to look before the allow lists are complete. It is an
explicit choice: the upgrade enforces on every region, so switch a region to `audit` right after
the upgrade if needed (the setting is new in this release and cannot be set before; the authz
reads the mode every ten seconds):

```sh
runr regions set eu-1 --set internal_auth=audit
``` The authz logs one JSON
line per call it would refuse, `"decision":"audit"`:

```sh
kubectl -n knative-serving logs deploy/runr-internal-auth --since=24h | grep '"decision":"audit"'
# {"caller":"checkout.acme","decision":"audit","host":"orders.fn.svc.cluster.local","reason":"checkout.acme is not on the allow list of orders.acme","via":"address",…}
```

Add the callers the lines name to the callees' allow lists and set the region back to `enforce`.
`401` lines name callers that are no pod of a project and sent no token: clients of your own
inside the cluster. `"via"` says how the caller was known: `token` or `address`.

## How it works

```
caller pod ──► Traefik, entry point internal ──► ForwardAuth runr-internal-auth ──► callee
               (traefik-internal, port 80)        token + allow list → 200 / 401 / 403
```

- **The authz** is the region agent in authz mode: Deployment `runr-internal-auth` in the Serving
  namespace, two replicas on different nodes where possible, a PodDisruptionBudget, port 8090. It
  reads the policy of every Knative Service RunR manages (label `runr.dev/internal-network`,
  annotations `runr.dev/internal-name`, `-org`, `-callers`, `-kind`), the modes and the
  cross-organization switch (ConfigMap `runr-internal-network`) and each region's signing key
  (Secret `runr-internal-network`), every ten seconds. Its ServiceAccount reads Knative Services
  and pods cluster-wide and only that ConfigMap and Secret.
- **Callers without a token** are known by their address. The authz watches the pods labelled
  `runr/project-id` (RunR puts the label on every pod it runs for a project) and takes the
  caller's address from the connection Traefik received — the private entry point trusts no
  forwarded header, so a caller cannot name another address. The pod's project is its label
  `runr.dev/internal-name` (pods of this release) or the project its Knative Service belongs to
  (pods deployed before). Pods on the host network, finished pods and preview environments
  identify nobody. The address is a pod's own: the network plugin routes the answer back to it.
  Where something hides it — a proxy in front of the caller, a cluster that rewrites pod source
  addresses — the call needs the token.
- **The private entry point** `internal` of the region Traefik asks the authz for every request
  (entry-point middlewares `runr-internal-auth`, a ForwardAuth, and `runr-internal-strip`, which
  removes the token header). It serves the cluster-local hosts and the `runr.internal` hosts —
  one IngressRoute `runr-internal-<service>` per project — and is not on the LoadBalancer.
- **DNS.** CoreDNS answers `<project>.<organization>.runr.internal` with the address of
  `traefik-internal`: a `rewrite` before the `kubernetes` plugin in `kube-system/coredns`, or, where
  the Corefile imports `coredns-custom` (k3s and some managed clusters), the key
  `runr-internal.override` there. Where CoreDNS is not RunR's to change — no `kube-system/coredns`
  ConfigMap, or no `kubernetes` plugin in it — the install says so; add a rewrite of
  `*.runr.internal` to `traefik-internal.<serving namespace>.svc.cluster.local` to the cluster DNS,
  or call the cluster-local hosts.
- **Tokens** are `rit1.<payload>.<signature>`: the payload names the project and the region, the
  signature is an HMAC-SHA256 with the region's key, which RunR derives from its `APP_KEY` and the
  region's id. A workload's token does not expire; RunR's own expire after two minutes. Rotating
  `APP_KEY` ([security.md](security.md#key-rotation)) changes every region's key: run
  `php artisan region:reconcile` and redeploy the projects. Treat the token like a password: it
  calls in the project's name.
- **Access mode allowlist** is an `ipAllowList` Middleware `runr-access-<service>` and two
  IngressRoutes `runr-access-<service>` (web) and `runr-access-<service>-tls` (websecure) above
  Knative's own route of the public host; custom domains and gateway routes of the project get the
  same Middleware first. **internal** labels the Knative Service
  `networking.knative.dev/visibility: cluster-local`.

The authz answers every internal call: with both replicas down, the private entry point answers
`500` until one is back. The calls of the public routes do not pass it.

## Regions

A region connected with this release gets the internal network with Knative Serving: the authz, its
keys and modes, the entry-point middlewares and the DNS rewrite. `region:reconcile` (run by the
Helm chart's Job `runr-region-reconcile-<revision>` after every upgrade, step `internal-network`) brings regions of earlier
releases there, and they enforce afterwards like a new one:

1. the authz, its keys and modes, the Middlewares and the DNS rewrite — the authz ready, with the
   pods of the projects listed, before anything refers to it;
2. every Knative Service RunR runs gets its policy — the internal name, the project's allow list
   and access mode from the database, a model service its bound projects, the region demo's
   backend its frontend — and every project its `runr.internal` route;
3. then the region Traefik's private entry point gets the Middlewares, once `private-ingress` has
   moved it (a Helm upgrade with the release's values; the Traefik pods roll once): enforcement
   starts with every policy in place.

Nothing is redeployed or restarted for it: workloads deployed before keep working as their
project by their pods' address, and get `RUNR_INTERNAL_TOKEN` and the `runr.dev/internal-name`
label on their next deploy. Calls between projects without an allow-list entry are refused from
step 3 on.

## API

| Method and path | Permission | |
|---|---|---|
| `GET /api/projects/{project}/network` | `projects.view` | access mode, ranges, internal name / host / URL, organization, the header and env var names, whether cross-organization grants are allowed, whether the region enforces, the allow list |
| `PUT /api/projects/{project}/network` | `network.manage` | `{"access_mode": "public" \| "allowlist" \| "internal", "allowed_cidrs": [...]}` |
| `POST /api/projects/{project}/network/callers` | `network.manage` | `{"caller": "checkout"}` or `{"caller": "billing.globex"}` → `201` |
| `DELETE /api/projects/{project}/network/callers/{caller}` | `network.manage` | `204`, `404` when the caller is not on the list |

```sh
curl -sS "$API/projects/$PROJECT/network" -H "Authorization: Bearer $TOKEN"
# {"data":{"access_mode":"public","allowed_cidrs":[],"internal_name":"orders.acme",
#   "internal_host":"orders.acme.runr.internal","internal_url":"http://orders.acme.runr.internal",
#   "organization":"acme","token_header":"X-Runr-Internal-Token","token_env":"RUNR_INTERNAL_TOKEN",
#   "cross_tenant_grants":true,"enforced":true,
#   "callers":[{"caller":"checkout.acme","organization":"acme","cross_tenant":false,"created_at":"…"}]}}
```

A change the region could not take (it was unreachable) is saved and answers `200` / `201` with a
`warning`; the project's next deploy applies it. The project resource carries `access_mode` and
`internal_url`; an internal project's `endpoint_url` is `null`.

Validation (`422`): an unknown mode, `allowlist` without ranges, a range that is no IP address or
CIDR, `internal` for a workflow project; a caller that is the project itself, no project of the
organization, no `<project>.<organization>`, or another organization's project while grants are
switched off.

## CLI, MCP and runr.yaml

```sh
runr network                       # access mode, internal URL, allowed callers
runr network set --access internal
runr network callers add checkout
runr network callers rm billing.globex --yes
```

MCP tools ([mcp.md](mcp.md)): `network_get`, `network_set`, `network_caller_add`,
`network_caller_remove`.

In `runr.yaml` ([cli.md](cli.md#runryaml)), `runr deploy` and a git push apply the section — a
push only when the pusher has `network.manage` (otherwise it warns). `callers` replaces the allow
list; leave it out to keep the list as it is:

```yaml
network:
  access: allowlist              # public | allowlist | internal
  allow_cidrs: [203.0.113.0/24]  # allowlist only
  callers: [checkout, billing.globex]
```
