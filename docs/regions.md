# Regions

A **region** is one Kubernetes cluster that runs functions. The control plane reaches the cluster's
API with the region's kubeconfig, installs what projects need there — Knative Serving with Traefik
as its ingress, an image registry, Prometheus and Loki, the tracing backend and the runr-agent —
and deploys every project of the region into it. The runr-agent runs in the region and connects
back to the control plane over WebSocket: it reports metrics, pod sessions and events
([monitoring.md](monitoring.md#go-agent-runtimeagent)).

This page describes what a cluster needs before it becomes a region, how to connect one, every
setting of a region, region status, moving a region to another cluster, regions in multi-tenant
installs and removing a region. The components RunR installs and how they interact are in
[architecture.md](architecture.md#regions); the `runr regions` commands are in
[cli.md](cli.md#regions).

- [How projects use regions](#how-projects-use-regions)
- [Before you connect a cluster](#before-you-connect-a-cluster)
- [Connecting a region](#connecting-a-region)
- [Region settings reference](#region-settings-reference)
- [Region status](#region-status)
- [Capacity, overcommit and dedicated workers](#capacity-overcommit-and-dedicated-workers)
- [Moving a region to another cluster](#moving-a-region-to-another-cluster)
- [Changing the base domain](#changing-the-base-domain)
- [Regions in multi-tenant installs](#regions-in-multi-tenant-installs)
- [Removing a region](#removing-a-region)
- [API reference](#api-reference)
- [Artisan commands](#artisan-commands)

## How projects use regions

Every project runs in one region, picked when the project is created (`knative_region_id`); a
project can be moved to another region later. The region decides:

- **where the project's workloads run**: in the region's project namespace, which is named like
  the region (a region `eu-2` runs its projects in the namespace `eu-2`);
- **its address**: an HTTP project answers at `https://<project slug>.<region>.<base domain>`.
  The base domain is the platform's (Administration → Platform, **Base Domain**;
  `PATCH /api/settings` with `base_domain`); `<region>.<base domain>` is the region's
  **function domain**;
- **what the project can use**: queue triggers need the region's Redis, builds (runtime plugins
  with a build step, WebAssembly functions) its registry, sandboxed runtimes its gVisor or Kata
  Containers, Wasm functions its WebAssembly support, GPU projects its GPU support, bindings its
  data services;
- **whether the project is admitted**: the region's [status](#region-status) and
  [capacity](#capacity-overcommit-and-dedicated-workers).

In the console, regions are under **Administration → Platform → Knative Regions**; the pencil of a
region opens its tabs (General, Workers, Registry, Operator, Serving, Redis, Data services,
Isolation, GPU, Migration, Supply chain). Changing a region needs `regions.manage` or the
permission of the area ([API reference](#api-reference)).

## Before you connect a cluster

### Kubernetes version

RunR reads the cluster's version when it checks the kubeconfig and refuses a cluster older than
**Kubernetes 1.30**. The minimum is the Helm value `knative.minK8sMinor` (environment variable
`KNATIVE_MIN_K8S_MINOR`, default `30`).

### Knative and Kubernetes versions

RunR installs Knative Serving through the Knative Operator. The Knative versions it offers for a
cluster come from this table (`App\Services\Helpers\KnativeVersionHelper::KNATIVE_K8S_COMPAT`):

| Knative | Kubernetes, at least |
|---|---|
| 1.21 | 1.33 |
| 1.20 | 1.32 |
| 1.19 | 1.32 |
| 1.18 | 1.31 |
| 1.17 | 1.30 |
| 1.16 | 1.29 |
| 1.15 | 1.28 |
| 1.14 | 1.28 |
| 1.13 | 1.27 |

The Serving step offers the up to five newest Knative versions that the cluster supports and the
Knative Operator chart repository has (`GET /api/knative-regions/{id}/compatible-versions`,
`runr regions knative versions`), the newest first and preselected: a Kubernetes 1.32 cluster,
for example, is offered Knative 1.20, 1.19, 1.18, 1.17 and 1.16. The operator's chart version is
picked the same way.

### Nodes

- **Container runtime.** The nodes reach RunR's registry through a node mirror (DaemonSet
  `runr-registry-mirror`) that writes containerd's `hosts.toml` for it. On k3s and rke2
  containerd reads it as it is. On plain containerd the mirror also sets `config_path` in
  `config.toml`, and containerd has to be restarted once: the mirror's log says so, and
  `php artisan registry:rolling-restart <region-id>` restarts it node by node
  ([Artisan commands](#artisan-commands)).
- **Privileged pods.** RunR runs privileged DaemonSets on the nodes — the registry's node mirror
  and a short-lived image pre-pull in the registry namespace, and for sandbox runtimes and
  WebAssembly their installers in `runr-sandbox` — and cAdvisor in `observability` with the host's
  PID namespace and host paths. A cluster that enforces Pod Security `baseline` or `restricted`
  on every namespace has to exempt these namespaces.
- **Sandbox runtimes.** Kata Containers needs containerd (plain, k3s, rke2) or CRI-O nodes and
  `/dev/kvm` ([kata-hypervisors.md](kata-hypervisors.md#hardware-requirements)). WebAssembly
  support: [wasm.md](wasm.md#install-webassembly-support); GPU nodes:
  [ai.md](ai.md#adding-gpu-worker-nodes).
- **Dedicated workers** are optional: without them projects run on every schedulable node
  ([Dedicated workers](architecture.md#dedicated-workers)).

### Storage

RunR's own components ask for these volumes. Where no StorageClass is named the cluster's default
class is used, so the cluster needs a default StorageClass unless every class is named.

| Volume | Size | StorageClass |
|---|---|---|
| Registry (Zot), storage `pvc` | chosen when the region is created; the console and the CLI start with `10Gi` | chosen when the region is created; the console and the CLI preselect the cluster's default class |
| Prometheus (`observability`) | `50Gi`, ReadWriteOnce, 15 days retention | the registry's class; with an S3 or external registry the default class |
| Loki (`observability`) | `20Gi` | as Prometheus |
| Tempo (`observability`, tracing mode `managed`) | `10Gi` (Helm `observability.tracing.tempoStorage`) | as Prometheus |
| Model volume `runr-models`, for model services ([huggingface.md](huggingface.md#model-storage)) | `100` GiB, ReadWriteMany (Helm `ai.modelStorage.*`, per region in the GPU tab) | the default class unless set |
| Data services (`runr-data`, when installed) | KV `2Gi`, object storage `20Gi` and `1Gi` of metadata, Postgres `10Gi` | Helm `bindings.storageClass`; empty: the default class |

Before it installs the registry on a PVC, RunR creates a test volume of the chosen class and waits
60 seconds for it to bind. A registry on S3 needs the bucket instead (endpoint, bucket, region,
access key, secret key).

### Ingress, external address and DNS

The Serving step installs **Traefik** as Knative's ingress (chart `traefik/traefik` 40.3.0, image
`ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4` or the Helm value `knative.traefikImage`) in the
Knative Serving namespace. Its Service is of type **LoadBalancer** and listens on ports 80 and
443: the cluster needs a load-balancer implementation that gives the Service an external IP
address or hostname. RunR reads that address as the region's **external address**
(`external_ip`), at the end of the Serving install and with every status check.

Then create one DNS record for the function domain:

| Record | Points at |
|---|---|
| `*.<region>.<base domain>` | the external address: an `A` record for an IP address, a `CNAME` for a hostname |

The region's General tab shows the record under **DNS Configuration**, the API as
`dns_instruction` of `GET /api/knative-regions/{id}`. Every minute the status check resolves
`dns-check.<region>.<base domain>` from the control plane and shows whether it reaches the
external address (`dns_resolved`): for an IP address, the address is among the answers (A or AAAA,
through any CNAME); for a load balancer's host name, the record is a CNAME to it or resolves to
one of its addresses. The Serving install derives the function domain from the base domain; a
base domain set later reaches the regions without a function domain when it is saved, or with the
next status check. A changed base domain moves the regions with a migration
([Changing the base domain](#changing-the-base-domain)).

HTTPS on the function domain is answered with the region Traefik's default certificate until the
region has a certificate of its own ([Function TLS](#function-tls)). A load balancer in front of
Traefik that sends PROXY protocol or `X-Forwarded-For` has to be named in
`ingress_trusted_ips` ([General](#general)).

### Images and Helm charts

RunR runs Helm in the control plane: for every install the control plane fetches the chart from
its repository and applies it through the cluster's API.

| Chart | Repository |
|---|---|
| Knative Operator | `https://knative.github.io/operator` |
| Traefik | `https://traefik.github.io/charts` |
| Zot (internal registry) | `https://zotregistry.dev/helm-charts` |
| kube-prometheus-stack | `https://prometheus-community.github.io/helm-charts` |
| loki-stack | `https://grafana.github.io/helm-charts` |
| OpenTelemetry Collector, Tempo | `https://open-telemetry.github.io/opentelemetry-helm-charts`, `https://grafana-community.github.io/helm-charts` |
| CloudNativePG, sigstore policy-controller, NVIDIA device plugin and GPU Operator (when installed) | their projects' repositories ([bindings.md](bindings.md#configuration-helm), [supply-chain.md](supply-chain.md#configuration), [ai.md](ai.md#helm-values)) |

The control plane also fetches the Knative Serving CRDs from the Knative release on `github.com`.
The nodes pull the images: RunR's from `ghcr.io/brf-tech/runr` (the agent, the mirrors of
Traefik, Redis, the Collector and Tempo, the function wrapper and the runtimes), the Kaniko build
image, cAdvisor from `gcr.io`, and the images of the charts above from their own registries. The
internal registry is a pull-through cache of `ghcr.io` and Docker Hub, and its node mirror sends
the nodes' Docker Hub pulls through it.

Images of your own reach a region without outside access by upload through RunR
([images.md](images.md#air-gapped-regions)), model weights the same way
([huggingface.md](huggingface.md#air-gapped-regions)).

### Network reachability

| From | To | For |
|---|---|---|
| Control plane | the cluster's API server (the `server` of the kubeconfig's current context) | every install, deploy and status check; Prometheus and Loki queries go through the API server's service proxy when their in-cluster address is not reachable |
| Control plane | the chart repositories and `github.com` | installs |
| Region (runr-agent) | the console's WebSocket, `<base>/ws/agent/<region id>` | metrics, pod sessions, events |
| Region (runr-agent, function pods) | `<base>/api/internal/observability/…` | uptime checks run from the region, request replay captures |
| Nodes | the image registries above | images |

The agent's `<base>` is, in this order: the region setting `agent_ws_url`; RunR's in-cluster
gateway when the region is the cluster RunR runs in; the environment variable
`RUNR_AGENT_WS_URL` (Helm `extraEnv`); the console URL `APP_URL` (`https://` becomes `wss://`).
A cluster that cannot reach the console's public address needs `agent_ws_url`
([General](#general)). The agent authenticates with a token of its region
([monitoring.md](monitoring.md#go-agent-runtimeagent)). Request replay captures go to Helm
`observability.replay.ingestUrl` instead when it is set
([observability.md](observability.md#request-replay)).

The API answers only for the console's host, except under `/api/internal/`: these callbacks from
the regions are also answered for the hosts of the addresses RunR hands out — the in-cluster
gateway, every region's `agent_ws_url` and `app_url`, `RUNR_AGENT_WS_URL`,
`observability.replay.ingestUrl` and `WORKFLOWS_PUBLIC_API_URL` (a changed region setting within
a minute). Any other host gets `400`.

### The kubeconfig

RunR uses the kubeconfig's **current context** and reads from it the cluster's `server` and
`certificate-authority-data` (or `insecure-skip-tls-verify`) and the user's `token`, or
`client-certificate-data` and `client-key-data`: the kubeconfig carries its credentials in these
fields. RunR stores it encrypted, never returns it in API responses and hands it out only through
the kubeconfig download ([security.md](security.md#encryption-at-rest)).

The kubeconfig is RunR's only access to the cluster, also for a region on the cluster RunR runs
in: the control plane's own service account only reads the `kube-system` namespace to recognise
that cluster. Give the kubeconfig **cluster-admin** rights. An install creates namespaces, CRDs
(Knative's, Traefik's, kube-prometheus-stack's), ClusterRoles and ClusterRoleBindings (the
runr-agent's, cAdvisor's, Traefik's Knative networking role and the charts' own), a PriorityClass
(`observability-critical`), an IngressClass, RuntimeClasses, node labels and taints, privileged
DaemonSets and Helm releases in several namespaces, and RunR uses the service proxy and
`pods/exec` (deployment shells, sandboxes).

### k3s, rke2 and local clusters

- **k3s's own Traefik.** When an IngressClass `traefik` belongs to another controller, the
  region's Traefik serves the class `traefik-knative` and leaves the existing one alone.
- **Containerd paths.** The registry's node mirror finds the containerd configuration of k3s and
  rke2 (`/var/lib/rancher/{k3s,rke2}/agent/etc/containerd`) and needs no containerd restart there.
- **A cluster on the same machine.** A kubeconfig whose `server` is `127.0.0.1` or `localhost` and
  that RunR cannot reach that way is rewritten to `host.docker.internal`, without the CA check,
  for a control plane that runs in containers next to a local cluster.
- **RunR's own cluster.** RunR recognises the cluster it runs in by its `kube-system` namespace:
  the agent then connects to the in-cluster gateway, and the console's certificate can serve the
  function domain ([Function TLS](#function-tls)).

## Connecting a region

### In the console

**Administration → Platform → Knative Regions → + Add Region** opens a wizard of seven steps. It
needs `regions.manage` and, for the registry step, `registry.manage`. Once a step is done, the
steps before it are read-only.

1. **Connection.** The region name — lowercase letters, digits and hyphens, starting with a letter
   and ending with a letter or digit, at most 63 characters, unique — and the kubeconfig (upload
   or paste), with the Tenancy module the region mode (Platform users), and with the Billing
   module the region's **billing type** — pay as you go or fixed resources
   ([Billing](#billing)). RunR connects, checks the Kubernetes version and that the cluster has no
   namespace of that name yet. Nothing is created in this step.
2. **Workers & Registry.** The worker nodes to dedicate (optional), the registry and, under
   **Existing monitoring (optional)**, a Prometheus and a Loki already running in the cluster.
   - **Internal Registry (Install Zot)** on a PVC (StorageClass, size) or on S3 (endpoint,
     bucket, region, access key, secret key), or **External Registry** (address, optional
     username and password).
   - Existing monitoring takes in-cluster Service URLs
     (`http(s)://<service>.<namespace>.svc[.cluster.local]:<port>[/path]`) and a Loki tenant ID;
     RunR then installs no Prometheus or Loki of its own for that component. With the Tenancy
     module only Platform users, and tenant owners for their own region, may set them.

   **Save & Next** creates the region: RunR tests the storage (or the external registry, from a
   pod), installs Zot (release `runr-zot` in `runr-registry`, chart `zot/zot` 0.1.125) with its
   node mirror, pre-pulls common images on the nodes, creates the region and its project
   namespace, applies the worker selection and queues RunR's managed Redis.
3. **Operator.** RunR first adopts a Knative Operator and Serving already running in
   `knative-operator` and `knative-serving`. **Install Operator** installs the Knative Operator
   (chart `knative-operator/knative-operator` in the chosen namespace, default `knative-operator`)
   and the Knative Serving CRDs.
4. **Serving.** The namespace (default `knative-serving`) and the Knative version. The install
   applies, in this order: the project namespace's ResourceQuota and LimitRange when an
   organization ceiling is set ([architecture.md](architecture.md#editions)); Traefik; the
   KnativeServing resource with Traefik as ingress; the function domain
   `<region>.<base domain>`; the external address; Prometheus (release `knative`) and Loki
   (`loki-stack`) unless existing ones were given, with a PriorityClass and
   PodDisruptionBudgets; the runr-agent; and the tracing backend
   ([observability.md](observability.md#install-flow)).
5. **Redis.** The Redis of queue-triggered projects: RunR's managed Redis queued in step 2
   (Deployment `runr-redis` in the project namespace), or an external one ([Redis](#redis)).
6. **Sandbox.** The Isolation tab: Kata Containers, gVisor, WebAssembly support and the
   RuntimeClass of each workload kind
   ([Sandbox runtimes and WebAssembly](#sandbox-runtimes-and-webassembly)).
7. **Migration.** The region's move to another cluster
   ([Moving a region to another cluster](#moving-a-region-to-another-cluster)). **Finish** closes
   the wizard.

The Operator and Serving steps can be skipped; a region without Serving runs no HTTP projects.
Then create the DNS record that the General tab shows
([Ingress, external address and DNS](#ingress-external-address-and-dns)).

### With the CLI

```sh
runr regions check eu-2 --kubeconfig eu-2.yaml          # version, nodes, StorageClasses, the namespace
runr regions create eu-2 --kubeconfig eu-2.yaml --registry internal --storage-class fast-ssd --wait
runr regions knative install eu-2 operator --wait
runr regions knative install eu-2 serving --wait
```

Every flag is in [cli.md](cli.md#creating-a-region).

### With the API

`POST /api/registry-setup/start` creates a region together with its registry, as the console
does. It answers 202 with a setup token and runs in the background:

```bash
jq -n --rawfile kc eu-2.yaml '{name: "eu-2", kubeconfig: $kc, nodes: [],
    registry_mode: "internal", storage_type: "pvc",
    pvc_config: {storageClass: "fast-ssd", size: "10Gi"}}' |
  curl -sS -X POST "$API/registry-setup/start" -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' -d @-
# {"setup_token":"…","setup_id":"…"}

curl -sS "$API/registry-setup/$SETUP_TOKEN/status" -H "Authorization: Bearer $TOKEN"
# {"status":"ready","phase":"done","region_id":"01J…",…}     status: pending, installing, ready or error

curl -sS -X POST "$API/knative-regions/$REGION/install-operator" -H "Authorization: Bearer $TOKEN"
curl -sS -X POST "$API/knative-regions/$REGION/install-serving" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"version": "1.20"}'
```

| Field | Value |
|---|---|
| `name`, `kubeconfig` | as in the console |
| `nodes` | the worker nodes to dedicate; `[]` for none |
| `registry_mode` | `internal` with `storage_type` `pvc` (`pvc_config.storageClass`, `pvc_config.size`) or `s3` (`s3_config.endpoint`, `bucket`, `region`, `access_key`, `secret_key`); or `external` (`external_address`, `external_username`, `external_password`) |
| `redis_mode` | `managed` (default), `in_cluster` or `external` (with `redis_address`, `redis_password`), or `none` |
| `prometheus_url`, `loki_url`, `loki_org_id` | existing monitoring, as in the console |
| `region_mode` | with the Tenancy module: `managed` (default) or `infinite` ([Regions in multi-tenant installs](#regions-in-multi-tenant-installs)) |

The status of a setup is kept for 30 minutes; a setup in `error` starts again with
`POST /api/registry-setup/{token}/retry` and the same body. `POST /api/knative-regions` takes
`name`, `kubeconfig`, `nodes`, `is_active` (`false`: the region is created `inactive`) and the
Redis and monitoring fields and creates the region at once; given `registry_mode` and the
registry fields (`registry_storage_type`, `registry_storage_config`, or `registry_address`,
`registry_username`, `registry_password`) it then sets the registry up in the background like
`POST /api/knative-regions/{id}/registry` ([Registry](#registry)). Both refuse (422) a cluster
below the minimum Kubernetes version and a name whose namespace exists on the cluster.

## Region settings reference

One table per area. "Console" names the region's tab (Administration → Platform → Knative
Regions → the region's pencil); the API paths are under `/api`.

### General

| Setting | Default | Change with | What it does |
|---|---|---|---|
| Name | — | set when the region is created | lowercase letters, digits and hyphens, at most 63 characters; also the name of the project namespace. It does not change later |
| Kubeconfig | — | `runr regions set REGION --kubeconfig FILE`; `PUT /knative-regions/{id}` with `kubeconfig` | the credentials RunR reaches the cluster with ([The kubeconfig](#the-kubeconfig)); replace it after a credential rotation. A new kubeconfig is checked like a new region's (it connects, the Kubernetes version is supported) and has to reach the same cluster (the `kube-system` namespace): moving the region to another cluster is a [migration](#moving-a-region-to-another-cluster). When the current kubeconfig no longer answers there is nothing to compare, and the new one is taken |
| Status | `active` | console General → **Region Status**; `runr regions set REGION --set status=…`; `PUT /knative-regions/{id}` with `region_status` | `active`, `inactive` or `maintenance` ([Region status](#region-status)) |
| `request_timeout` | `300` | console General → **Settings**; CLI or API (below) | the longest timeout a project in the region may have, in seconds (1–3600): a project timeout above it is refused (422), and the console's project form stops there. The region's Traefik and Knative follow it ([Request timeout](#request-timeout)) |
| `agent_ws_url` | empty | console General → **Settings**; CLI or API | RunR's address as the region's runr-agent reaches it (`wss://`, `ws://`, `https://` or `http://`; `/ws/agent/<region id>` is appended); also the base of request replay captures. Empty: the order in [Network reachability](#network-reachability). A change deploys the region's runr-agent again with the new address. One agent runs per cluster: on a cluster with several regions it is the agent of the region that deployed it, and that region's setting applies |
| `cert_manager_cluster_issuer` | empty: the environment variable `RUNR_CERT_MANAGER_CLUSTER_ISSUER` (Helm `extraEnv`), else none | console General → **Settings**; CLI or API | the cert-manager ClusterIssuer that issues the certificates of project custom domains in TLS mode `cert-manager`. That mode is offered when the cluster serves `cert-manager.io/v1` and has the issuer; RunR does not install cert-manager |
| `ingress_trusted_ips` | empty: trust nobody | console General → **Settings** (comma-separated); CLI (comma-separated) or API (a list, up to 32) | IP addresses or CIDRs of a load balancer or proxy in front of the region's Traefik that sends PROXY protocol or `X-Forwarded-For`. Without them Traefik records the connecting address as the client and drops client-sent `X-Forwarded-*`. A change reaches Traefik right away (a Helm upgrade in the background); regions on one cluster share its Traefik, which trusts all their addresses |

Set them in the console (General → **Settings**), with `runr regions set REGION --set KEY=VALUE`
([cli.md](cli.md#settings-capacity-and-function-tls)) or with the API, which changes only the keys
it is sent:

```bash
curl -sS -X PUT "$API/knative-regions/$REGION/settings" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"request_timeout": 600, "agent_ws_url": "wss://runr.internal.example.com",
       "ingress_trusted_ips": ["10.0.0.0/24"]}'
```

#### Request timeout

A project's timeout is the `timeoutSeconds` of its Knative revision: Knative ends a request that
runs longer with `504`. For a request to run that long, what is in front of it waits as long:

- **Traefik.** The region Traefik's entry points `web` and `websecure` close a request after their
  read and write timeouts. Both are the longest `request_timeout` of the regions on the cluster
  plus 10 seconds (at least 120 seconds), so Knative's own answer arrives first.
- **Knative.** Knative refuses a revision whose `timeoutSeconds` exceeds its
  `max-revision-timeout-seconds` (600 seconds by default). With a `request_timeout` above 600
  seconds RunR raises it to that value (`spec.config.defaults` of the KnativeServing resource);
  it is never lowered.

A changed `request_timeout` reaches both right away (a Helm upgrade of the region's Traefik in the
background — its pods roll once — and a merge patch of the KnativeServing resource);
`region:reconcile` brings existing regions there with every upgrade.

### Workers and capacity

| Setting | Default | Change with | What it does |
|---|---|---|---|
| Worker nodes | none: projects run on every schedulable node | console Workers → **Apply Selection** (and wizard step 2); `runr regions nodes set REGION NODE…` (`--all` for none); `POST /knative-regions/{id}/workers` with `{"nodes": [...]}` (`[]` for none) | the selected nodes get the label and the taint `runr.dev/dedicated=projects:NoSchedule` and only they count towards the capacity; deselected nodes lose both ([Dedicated workers](architecture.md#dedicated-workers)) |
| `faas_cpu_overcommit` | `3` | console Workers → **Capacity**; `runr regions capacity set`; `PUT /knative-regions/{id}/capacity` | CPU overcommit factor of the FaaS budget, 1–10, two decimals ([architecture.md](architecture.md#region-capacity-and-admission)) |
| `faas_memory_overcommit` | `1.5` | as above | memory overcommit factor, 1–10, two decimals |
| Project namespace | the region name | — | shown read-only in the Workers tab |

### Registry

The registry is chosen when the region is created (wizard step 2, `runr regions create --registry`,
`POST /registry-setup/start`, or `POST /knative-regions` with the registry fields) or set up later
in the region's Registry tab, with `runr regions registry set REGION --registry internal|external`
([cli.md](cli.md#redis-registry-and-skupper)) or with `POST /knative-regions/{id}/registry`
(`registry.manage`), which takes the registry fields of `POST /registry-setup/start`
(`registry_mode`, `storage_type`, `pvc_config`, `s3_config`, `external_address`,
`external_username`, `external_password`). The setup tests the storage or the external registry
from the cluster and installs Zot in the background (`registry_status` `installing`, then `ready`,
or `error` with the reason in `registry_status_message`). An external registry is replaced this
way; an internal one is removed first, because its volume holds the region's images.

| Setting | Values | What it does |
|---|---|---|
| Mode | `internal` or `external` | `internal`: RunR runs Zot (release `runr-zot`, chart `zot/zot` 0.1.125, namespace `runr-registry`, ClusterIP port 5000) as the region's registry and as a pull-through cache of `ghcr.io` and Docker Hub, with the node mirror `runr-registry-mirror`. `external`: builds push to your registry |
| Storage (`internal`) | `pvc`: StorageClass and size; `s3`: endpoint, bucket, region, access key, secret key | where Zot keeps the images |
| Address (`external`) | the registry address, optional username and password | tested from a pod in the cluster before the registry is set up |

`runr regions registry rm REGION` or `DELETE /knative-regions/{id}/registry` (`registry.manage`)
removes the registry: for `internal` the node mirror, the Helm release and the namespace (with
its volume) when RunR created it; for `external` RunR forgets it.

### Knative Operator and Serving

| Setting | Default | Change with | What it does |
|---|---|---|---|
| Operator namespace | `knative-operator` | console Operator → **Install Operator**; `runr regions knative install REGION operator --namespace`; `POST /knative-regions/{id}/install-operator` with `namespace` | where the Knative Operator runs |
| Serving namespace | `knative-serving` | console Serving → install; `… install REGION serving --namespace`; `POST /knative-regions/{id}/install-serving` with `namespace` | where Knative Serving, Traefik and the status responder run |
| Knative version | the newest the cluster supports | the Serving install (`--version`, `version`); afterwards console Serving → **Upgrade**, `runr regions knative upgrade REGION --version`, `POST /knative-regions/{id}/upgrade-serving` with `version` | [Knative and Kubernetes versions](#knative-and-kubernetes-versions); upgrades below |
| Ingress | Traefik | — | the region's Traefik is Knative's ingress |

Installing adopts what already runs on the cluster — another region's Knative on the same
cluster, or one installed before — with every install path, the full install
(`POST /knative-regions/{id}/install-knative`, `runr regions knative install REGION`) included: an
operator whose Deployments are available (and the KnativeServing CRD registered) is recorded as
the region's and not installed again, a Ready KnativeServing is adopted and not applied again. An
operator install that does run (a cluster without a working operator) first removes the previous
operator release; it keeps the Knative Serving CRDs while Knative Services exist on the cluster.

**Upgrade** moves Knative Serving to the next minor version the cluster supports — Knative
upgrades one minor version at a time, so other versions are refused (422). RunR moves the Knative
Operator's Helm release to that minor's chart first when it is older, applies the Serving CRDs of
the version and sets the KnativeServing resource's `spec.version`; the operator then rolls Knative
Serving's components while the region's Knative Services keep running. The Serving status is
`installing` until the operator reports the new version Ready. Regions on one cluster share
Knative Serving, so it is upgraded for all of them.

**Uninstall Serving** (`POST /knative-regions/{id}/uninstall-serving`) deletes the KnativeServing
resource, Traefik — which stays while it serves other Ingresses, such as the console on the same
cluster, unless `remove_traefik` is set — and, once Traefik is gone, the Serving namespace when
RunR created it.
Prometheus, Loki, the runr-agent and the tracing backend stay. **Uninstall Operator**
(`POST /knative-regions/{id}/uninstall-operator`, once Serving is gone) removes the operator and
the Knative Serving CRDs, and with them every Knative Service left on the cluster.
`POST /knative-regions/{id}/uninstall-knative` (`runr regions knative uninstall REGION`) removes
Serving and the operator release in one go and sets both components to `not_installed`.

### Redis

The Redis of queue triggers. A new region gets RunR's managed Redis unless it is created with
another mode (`redis_mode`, `--redis`).

| Mode | What it is |
|---|---|
| `managed` | RunR's Redis: Deployment and Service `runr-redis` in the project namespace (image `ghcr.io/brf-tech/runr/reqs/redis:7-alpine`, one replica, 100m CPU / 128 MiB, no volume), with an optional password (Secret `runr-redis-auth`); address `redis://runr-redis.<namespace>:6379` |
| `in_cluster`, `external` | a Redis you run: its address and an optional password |
| `none` | no Redis (at creation only): the region runs no queue-triggered projects |

Change it in the console's Redis tab (**Auto Installed** or **External Redis**, **Test
Connection**, **Remove Redis**), with `runr regions redis set|test|rm`
([cli.md](cli.md#redis-registry-and-skupper)) or with `POST /knative-regions/{id}/redis`
(`mode`, `address`, `password`), `POST /knative-regions/{id}/test-redis` (a `redis-cli PING` from a
pod in the cluster) and `DELETE /knative-regions/{id}/redis`. Removing deletes RunR's managed
Redis and the region's Redis settings.

### Observability

| Setting | Default | Change with | What it does |
|---|---|---|---|
| Prometheus | RunR's: kube-prometheus-stack (chart 92.10.1), release `knative` in `observability` | when the region is created: wizard step 2, `--prometheus-url`, `prometheus_url`; later console General → **Existing monitoring**, `runr regions set REGION --set prometheus_url=…`, `PUT /knative-regions/{id}/monitoring` | an in-cluster Prometheus to reuse instead. When the cluster runs the Prometheus Operator, RunR adds cAdvisor, its PodMonitors and recording rules (label `app.kubernetes.io/part-of=runr`) for it |
| Loki | RunR's: loki-stack (chart 2.10.3) in `observability` | when the region is created: `--loki-url`, `--loki-org-id`, `loki_url`, `loki_org_id`; later as Prometheus (`loki_url`, `loki_org_id`) | an in-cluster Loki to reuse instead, with its tenant ID |
| Tracing backend | Helm `observability.tracing.defaultMode` (`managed`) | the **Tracing** tab next to Platform; `GET`/`PUT`/`DELETE /observability/regions/{id}/tracing`, `POST …/tracing/install`; `php artisan cluster:install-tracing` | OpenTelemetry Collector and Tempo, or a reused Tempo, an external OTLP endpoint, or off ([observability.md](observability.md#tracing-backend)) |

`PUT /knative-regions/{id}/monitoring` takes `prometheus_url`, `loki_url` and `loki_org_id`
(the keys it is sent; who may set them is as at creation): a URL points the region at that
in-cluster Prometheus or Loki, `null` back at RunR's own, which is installed right away when the
region's Serving is ready (else with the Serving install). A component switched to an existing
instance keeps RunR's own release running — regions on the same cluster may still use it; remove
it with `helm uninstall` when none does. `GET /knative-regions/{id}` shows the region's choice
under `monitoring`.

Removing the tracing backend (`DELETE /observability/regions/{id}/tracing`, the Tracing tab's
**Remove**) sets the region's tracing mode to `off`, so `region:reconcile` does not install it
again; choosing a mode brings it back.

`php artisan cluster:patch-observability [REGION-ID]` applies RunR's Prometheus and Loki values
again; [observability.md](observability.md#install-flow) describes the install.

### Sandbox runtimes and WebAssembly

The console's Isolation tab (wizard step 6); `sandbox.manage`.

| Setting | Values (default) | Change with | What it does |
|---|---|---|---|
| Kata Containers | hypervisors `clh` (default), `qemu`, `dragonball`, `fc`, one to four; `devmapper_pool_gb` 10–1024 (20) for `fc`, chosen with the first Firecracker install and kept by the later ones ([Firecracker and devmapper](kata-hypervisors.md#firecracker-and-devmapper)) | **Install Kata**, and **Add hypervisors** on a working install (the installed ones stay); `POST /knative-regions/{id}/install-sandbox` with `{"runtime": "kata", "hypervisors": [...]}` — the complete list | kata-deploy in `runr-sandbox` and a RuntimeClass `kata-<hypervisor>` per hypervisor; the container runtime of the nodes restarts once ([kata-hypervisors.md](kata-hypervisors.md)) |
| gVisor | — | **Install gVisor**; `install-sandbox` with `{"runtime": "gvisor"}` | the gVisor installer and RuntimeClass `gvisor` |
| Mapping | `runc` (default), `kata-<hypervisor>` of an installed hypervisor, `gvisor` | **Project Type Mapping**; `PUT /knative-regions/{id}/sandbox-mapping` with all four of `sandbox_xaas_runtime`, `sandbox_faas_runtime`, `sandbox_caas_runtime`, `sandbox_build_runtime` | the RuntimeClass of each project type and of build Jobs, from each project's next deploy ([Sandbox runtimes](architecture.md#sandbox-runtimes)) |
| WebAssembly | shims `spin` (required) and `wasmtime`; nodes: every eligible node | the WebAssembly card; `runr regions wasm install`; `POST /knative-regions/{id}/wasm` with `shims`, `nodes` | the Spin shim (and Wasmtime) on the nodes, RuntimeClass `wasmtime-spin-v2` ([wasm.md](wasm.md#install-webassembly-support)) |

`POST /knative-regions/{id}/uninstall-sandbox` with `runtime` and
`DELETE /knative-regions/{id}/wasm` remove them; both are refused while pods still use the
runtime, and the mappings that used a removed Kata Containers or gVisor go back to `runc`.

The [Sandbox API](sandbox-api.md) has its own settings per region (`PUT /sandbox-regions/{id}`,
`sandboxes.manage`, or `runr sandbox regions set`): whether the region offers sandboxes
(`enabled`, default on), `allowed_classes` (default: every installed class), `default_class`
(default: the strongest allowed), `allow_runc` (default off), `default_ttl_seconds` (default 900),
`max_ttl_seconds`, `max_cpu_m` (50–4000), `max_memory_mb` (64–8192) and `max_disk_mb`
(64–10240) ([sandbox-api.md](sandbox-api.md#settings-pools-quotas-metrics),
[cli.md](cli.md#region-settings)).

### GPU and model storage

The console's GPU tab; `regions.gpu.manage`. Every setting is described in
[ai.md](ai.md#installing-gpu-support) and [huggingface.md](huggingface.md#model-storage).

| Setting | Values (default) | API |
|---|---|---|
| GPU support | `device-plugin` (NVIDIA device plugin and GPU Feature Discovery) or `operator` (NVIDIA GPU Operator), in `runr-gpu` | `POST /knative-regions/{id}/gpu/install` with `mode`; `…/gpu/uninstall` |
| Time-slicing | `time_slicing_replicas` 2–16 (off) | install or `PUT /knative-regions/{id}/gpu/settings` |
| RuntimeClass | `runtime_class` `auto`, `nvidia` or `none` (`auto`) | install or settings |
| GPU Operator parts | `operator_driver`, `operator_toolkit` (both on) | install |
| GPU nodes only | `restrict_to_gpu_nodes` (on), `gpu_nodes` | install or settings |
| gVisor nvproxy | `gvisor_nvproxy` (off) | settings ([ai.md](ai.md#supported-sandbox-combinations)) |
| Model volume | `storage_class` (the default class), `size_gi` 1–65536 (100), `access_mode` `ReadWriteMany` or `ReadWriteOnce` (`ReadWriteMany`) | `PUT /knative-regions/{id}/model-storage` |

### Function TLS

The certificate the region's Traefik serves the function domain with. Console: General →
**Function-domain TLS** (once Serving is ready); `runr regions function-tls set`;
`PUT /knative-regions/{id}/function-tls` (`regions.manage`).

| `mode` | What it takes |
|---|---|
| `custom` | `certificate` and `privateKey` (PEM). The key has to match, the certificate must not be expired and its names must cover `*.<region>.<base domain>` |
| `console` | nothing: RunR copies the console's TLS Secret (Helm `ingress.tls.secretName`). Only for a region on the cluster RunR runs in |

RunR writes the Secret `runr-function-tls` and Traefik's `default` TLSStore in the Knative Serving
namespace and records the expiry (`function_tls_expires_at`). A `default` TLSStore that RunR did
not create is replaced only with `replace_existing: true`.

**Remove certificate** (`runr regions function-tls rm`, `DELETE /knative-regions/{id}/function-tls`)
switches function TLS off: RunR deletes its Secret and its `default` TLSStore (a TLSStore it did
not create stays), and the region's Traefik answers HTTPS with its own default certificate again.

### Data services

The console's Data services tab; `regions.data_services.manage`;
`POST`/`DELETE /knative-regions/{id}/data-services/{kv|object|postgres}`. Everything runs in
`runr-data` ([bindings.md](bindings.md#region-setup)).

| Service | Default |
|---|---|
| `kv` | RunR's Redis 7 (`runr-kv`): 512 MB `maxmemory`, `2Gi`; or mode `region`: the region's Redis, which needs a password |
| `object` | RunR's Garage (`runr-objects`): `20Gi` and `1Gi` of metadata; or an external S3 or Garage |
| `postgres` | CloudNativePG (chart 0.26.0 in `cnpg-system`) and the shared cluster `runr-pg-shared`: one instance, `10Gi`, backups off (they need the object storage) |

Removing a service is refused while bindings use it, and deletes its data.

### Supply chain and data location

The console's Supply chain tab; `regions.supply_chain.manage`;
`PUT /knative-regions/{id}/supply-chain`. SBOMs, scanning, the policy gate, signing and the
admission policy of the region are described in [supply-chain.md](supply-chain.md). The same
call sets the region's **data location** (`data_location`, up to 64 characters: letters, digits,
spaces, `_`, `.` and `-`, starting with a letter or digit; the tab's **Data residency** section): the place data residency policies
match ([supply-chain.md](supply-chain.md#data-residency)), and the place a
[migration](#moving-a-region-to-another-cluster) of the region has to stay in.

### Billing

With the [Billing module](billing.md) on, the region's **Billing** tab (`billing.view`; changes
`billing.manage`) and Settings → Billing → Regions & prices show and set:

| Setting | Default | Change with | What it does |
|---|---|---|---|
| Billing type | `payg` | the region wizard's first step; the Billing tab; `PUT /api/billing/regions/{id}` `{billing_type}` | `payg`: projects deploy at once and pay their metered usage at the end of the month. `fixed`: a project requests resources, the region's seller approves, the project pays a monthly price prorated by day and cannot use more ([Fixed resources](billing.md#fixed-resources)). One type per region; it cannot change while projects hold approved fixed resources there (`409 active_fixed_allocations`) |
| Prices | the default prices | the Billing tab; `POST /api/billing/prices` with `region_id` and `effective_from` | hourly prices (vCPU, GB, GPU, binding storage, Postgres instances, trigger multipliers) and monthly prices of fixed resources (vCPU, GB, GPU). Every change is a version with the date it takes effect; a region without its own prices uses the default prices ([Prices](billing.md#prices)) |

A region shared by the platform with a tenant keeps the platform's type and prices; the tenant adds
its markup when it accepts the offer ([Billing with tenancy](billing.md#billing-with-tenancy)).

### Skupper and demo projects

- **Skupper.** Private database connections of [bindings](bindings.md) run over Skupper v2, which
  the cluster's operators install. `GET /knative-regions/{id}/skupper` shows whether the cluster
  runs it, `POST /knative-regions/{id}/skupper/site` creates the site `runr` in the project
  namespace ([cli.md](cli.md#redis-registry-and-skupper)).
- **Demo projects.** General → **Demo Projects** (`POST`/`DELETE /knative-regions/{id}/demo-projects`,
  `runr regions demo install|rm`) deploys four demo projects — a frontend, a backend, a
  queue-triggered e-mail sender and a scheduled statistics job — once Serving, Redis and the
  registry are ready.

## Region status

Every region has a status (`region_status`): `active`, `inactive` or `maintenance`.

| Status | Projects | Requests to the function domain |
|---|---|---|
| `active` | created on, moved to and deployed to the region as usual | answered by the projects |
| `inactive` | creating a project there, moving a project there and every deploy are refused (422 `This region is inactive.`) | every request answers `404 {"error":"region_inactive","message":"Region is inactive."}` |
| `maintenance` | refused the same way (422 `This region is under maintenance.`) | every request answers `503 {"error":"maintenance","message":"Region is under maintenance."}` |

The status changes in the console (General → **Region Status**), with
`runr regions set REGION --set status=maintenance` or with `PUT /api/knative-regions/{id}` and
`{"region_status": "maintenance"}`.

A region that is not `active` runs a small status responder in the Knative Serving namespace
(Deployment and Service `runr-status-responder`, 10m CPU / 16 MiB requested) and an IngressRoute
`runr-region-block-<region>` for the region's Traefik — one per region, as the regions of a
cluster share its Knative Serving namespace. The IngressRoute matches, on HTTP and HTTPS and with
the highest priority, every host the region answers on:

- every host under its function domain (`*.<region>.<base domain>`) and, during a
  [base-domain migration](#changing-the-base-domain), under the domain it moves to;
- the project custom domains routed on the region;
- the domains of the region's Knative Services, such as a tenant's own base domain, and the domain
  a tenant's base domain moves to ([Organization domain](tenancy.md#organization-domain)).

The responder answers 404 or 503 as above. The project pods keep running, so the region answers
again as soon as it is `active`: that change removes the IngressRoute and its Middleware.

The block is applied when the status changes, when Knative Serving of a region that is not active
becomes ready (installed or adopted later) and when the hosts it covers change (a base-domain
migration); `region:reconcile` applies it to every region with Knative Serving ready that is not
active — platform regions and, with the Tenancy module, every tenant's own regions, each in its
tenant's context — and removes a block left on an active one. A region created inactive
(`runr regions create --inactive`, `is_active: false`) has the status `inactive`; the upgrade to
0.5.8 gives rows written before with `is_active: false` the status `inactive`, in the central
schema and in every tenant schema.

A region that is not `active` is also left out of the per-minute metric snapshots, the daily log
retention and the region maintenance commands that act on every active region
([Artisan commands](#artisan-commands)).

## Capacity, overcommit and dedicated workers

The **region capacity** is what the region's nodes leave for RunR workloads; admission splits it
into an always-on budget and a FaaS overcommit budget with one overcommit factor per resource
(`faas_cpu_overcommit`, default 3, and `faas_memory_overcommit`, default 1.5, each 1–10). How the
capacity is measured, what counts against each budget and how the defaults were chosen:
[Region capacity and admission](architecture.md#region-capacity-and-admission).

**Dedicated workers** reserve nodes for project workloads with the taint
`runr.dev/dedicated=projects:NoSchedule` and a matching label; with workers selected, only those
nodes count towards the capacity. What moves onto the workers and what stays on the other nodes:
[Dedicated workers](architecture.md#dedicated-workers).

| Change | Console | CLI | API |
|---|---|---|---|
| Overcommit factors | Workers → **Capacity** | `runr regions capacity set REGION --set faas_cpu_overcommit=4` | `PUT /api/knative-regions/{id}/capacity` |
| Worker selection | Workers → **Apply Selection** | `runr regions nodes set REGION NODE…` | `POST /api/knative-regions/{id}/workers` with `{"nodes": [...]}` |

## Moving a region to another cluster

A region migration keeps the region — its id, name, settings, function domain and projects — and
replaces its cluster. Start it in the console (Migration → **Upload New Kubeconfig**, **Start
Migration**), with `runr regions migration start REGION --kubeconfig FILE`
([cli.md](cli.md#moving-a-region-to-another-cluster)) or with the API:

```bash
jq -n --rawfile kc new-cluster.yaml '{kubeconfig: $kc}' |
  curl -sS -X POST "$API/knative-regions/$REGION/migrate" -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' -d @-
curl -sS "$API/knative-regions/$REGION/migration-status" -H "Authorization: Bearer $TOKEN"
```

A region that is already moving answers 422. A region with a data location
([data residency](supply-chain.md#data-residency)) moves only within it: the request names the new
cluster's location (`data_location` in the API, `--data-location` in the CLI), and it has to be
the region's.

The move runs as a queued job (`MigrateRegionJob`, at most 30 minutes) and sets
`migration_status` as it goes:

1. `pending`: RunR saves the current kubeconfig and external address, and what else the region
   holds for its current cluster (the list is under the rollback below), checks that the new
   cluster answers and switches the region to the new kubeconfig (`in_progress`). A move that
   `failed` can be started again without a rollback; it keeps what the first attempt saved, so a
   rollback still goes back to the cluster the region was on before that attempt, not to the
   half-moved state the failure left.
2. The dedicated workers: the selected nodes that exist on the new cluster get the label and the
   taint; when none of them exists there the selection is cleared (projects run on every
   schedulable node) and is made again in the Workers tab.
3. It installs on the new cluster the Knative Operator and Knative Serving with everything a
   Serving install brings (Traefik, Prometheus and Loki, the runr-agent, the tracing backend); an
   operator or a Ready KnativeServing already running there is kept. Then the managed Redis (an
   `in_cluster` or `external` Redis keeps its address), the internal registry (a Zot already
   serving in the registry namespace is kept; the region's registry address follows the new
   cluster) and Kata Containers and gVisor where the region had them ready. Kata gets the
   region's hypervisors, Firecracker with the thin-pool size its nodes have on the old cluster (for
   a pool set up before RunR recorded the size, the size in the old cluster's `kata-devmapper`
   DaemonSet; the default 20 GiB when that cannot be read); when its install fails on the new
   cluster, Kata is in `error` there, and gVisor is installed all the same.
4. What else the region had: WebAssembly support (on the selected nodes that exist there, else on
   every eligible node), GPU support with its settings, the data services RunR runs (`managed`,
   and KV on the region's Redis; an external object store needs nothing on the cluster), the
   admission policy, function TLS (the console's certificate, or the uploaded one copied from the
   old cluster's Secret), the Skupper site of the project namespace when projects use private
   database connections, and the status block of a region that is not active. A step that fails
   is logged and the move goes on.
5. It adds the region's function domain to Knative's domain configuration on the new cluster.
6. It deploys again what ran on the old cluster: for every project with a live deployment, the
   version that deployment runs, with its deploy overrides. The deploy checks of every deploy
   apply (a [stopped](billing.md#project-stop-and-resume) project is refused, and so is one in a
   fixed region whose [allocation](billing.md#what-a-fixed-project-may-use) it would exceed; the
   reason is logged, and the refused project's deployments are shut down with the reason as their
   message — nothing of it runs on the new cluster, so nothing is metered for it until it is
   deployed again). A stopped project moves with the region and stays stopped — it is deployed
   on the new cluster when it is resumed — and a project that runs nothing is not deployed. In a
   multi-tenant install this covers the projects the organizations run on a platform region too:
   each organization's projects are deployed in its own context, through its copy of the region,
   with the organization's deploy checks. An organization's own region moves inside that
   organization. The live [preview environments](#preview-environments) of the projects are
   deployed on the new cluster too.
7. `dns_check`: point the wildcard DNS record of the function domain at the new cluster's
   external address (`external_ip` and `dns_instruction` of the region,
   `GET /api/knative-regions/{id}`).

### Images the new cluster cannot pull

When the move gives the region another registry — the Zot it installs on the new cluster — the
images in the old cluster's registry stay there, and the new cluster's nodes do not pull from it:
RunR reaches the clusters' API servers only, never one registry from the other. (An external
registry, or a Zot that already served the region on the target cluster, stays, and nothing of
this applies.) Step 6 deals with these images per project:

- **A built version** — a FaaS function with its dependency file, a compiled runtime, a
  WebAssembly function — is built again on the new cluster, into its registry, from the version's
  code: the same Kaniko or WebAssembly build as a save in the editor, so dependencies that are not
  pinned can resolve to newer releases. When the build is done, the project is deployed on the new
  cluster with the version and overrides it ran, through the deploy checks of every deploy (a stop,
  a fixed allocation, data residency, the supply-chain policy gate, which waits for the new
  image's scan when the region's policy asks for one). Until then its deployment keeps running on
  the old cluster, which answers the function domain until DNS points at the new one, with the
  message "Runs on the cluster the region left until it is deployed on the new one: v5 is built
  again there". When the build fails, or a deploy check refuses the project, that deployment is
  shut down like a refused project's, with "Not moved to the new cluster of the region: v5 could
  not be built: …" (or the refusal) as its message.
- **A version without a build of its own on a runtime image RunR built in the region** (a runtime
  version with a `dockerfile` or `git` source, [runtimes.md](runtimes.md#versions)) waits for that
  runtime image: the region's runtime image builds are marked `pending` ("The region moved to
  another cluster: built again into its registry when a project needs it."), the first project
  that needs one starts its build on the new cluster, and the project is deployed the same way
  once the image is ready — or not moved when it is not built within about 17 minutes.
- **A build that was running when the region switched** ran its Kaniko Job on the old cluster: it
  is submitted again on the new cluster, and a git push waiting for it goes on with it. The new
  submission is checked on its own; the checks of the old one stop, so they cannot time the new
  build out from the old one's start.
- **An uploaded image** cannot be built again: RunR keeps no copy of an upload
  ([images.md](images.md#moving-a-region-to-another-cluster)). A CaaS project on one, and a code
  project whose runtime version is an uploaded image, is not moved; its deployment is shut down
  with, e.g., "Not moved to the new cluster of the region: its image myapp:1.0 was uploaded into
  the registry of the cluster the region left; upload it to the region again, select it as the
  project's image and deploy".

A move that completes (DNS, the 48 hours or **Complete Migration**) while a project is still being
built does not wait for it: the project is deployed on the new cluster when its build is done, and
once DNS points at the new cluster it answers there only from then on. Check the projects below
before you point DNS at the new cluster.

`GET /api/knative-regions/{id}/migration-status` says what the move did with each project of the
region, as long as the move can be rolled back (`projects`, never cached; the console's Migration
tab and `runr regions migration status REGION` list it). Projects that run nothing are not
listed. Each row has `project_id`, `project`, `version`, `deployment_status`, `message`,
`organization` — the slug of the organization a platform region's project belongs to in a
multi-tenant install, else `null` — and `outcome`:

| `outcome` | Meaning |
|---|---|
| `moved` | deployed on the new cluster; `deployment_status` is that deployment's |
| `building` | its image is built again on the new cluster; it runs on the old one until it is deployed |
| `deployed` | built again and deployed on the new cluster |
| `not_moved` | refused, or its build or deploy on the new cluster failed; `message` says why |
| `cancelled` | its waiting deploy was replaced by a newer deploy of the project; a deploy of an [older version](#older-versions) while the project still runs on the old cluster takes its place instead |

#### Older versions

The move builds again only what runs. A version built before the move that is deployed later
still names the old cluster's registry, so RunR builds it again into the region's registry first
and deploys it when the build is ready, through the deploy checks of every deploy, instead of a
deployment that fails at the image pull. This applies to every way a version is deployed — the
console's deploy and rollback, `runr deploy --version` and `runr rollback`, the MCP `deploy` and
`rollback` tools, `POST /api/projects/{id}/deploy`, a git push or **Deploy again** whose tree
equals that version, and the resume of a project stopped before the move — during the move and
after it is completed (and to a project moved to another region since its version was built):

- `POST /api/projects/{id}/deploy` answers `202` with `message` and `queued_deploy` instead of a
  deployment: `status` `waiting`, `reason` `registry-rebuild` and the message "v3 is built again
  for this region's registry: its image is in a registry the region's cluster does not pull from
  (built before the region moved to another cluster). It deploys when the build is ready."
  `GET /api/projects/{id}/queued-deploys/{queued_deploy}` follows it: `deployed` with the
  `deployment_id` it started, or `failed` / `cancelled` with the reason in `message`. Data
  residency, a stopped project and a fixed allocation are checked before the build, as for any
  deploy (`409` / `422`).
- The console shows that message, and the version list shows the build; the deployment appears
  when the build is ready. `runr deploy` and `runr rollback` wait for the build and then for the
  deployment ([cli.md](cli.md#deploying)); the MCP tools follow it the same way.
- A git deploy waits in `building` with that message and deploys when the build is ready.
- A resume answers `redeploy_code: version_rebuilding` with the message as `redeploy_error`, and
  the version that ran is deployed with its overrides when the build is ready.

The supply-chain [policy gate](supply-chain.md#policy-gate) runs on the new image when the deploy
starts (it waits for the image's scan when the region's policy asks for one). A deploy that the
deploy checks refuse at that point ends as `failed` with the reason, and the user who asked for
it gets a notification ("Deploy of orders v3 did not start"); a failed build has the usual "Build
failed" notification. A version the policy blocks then is deployed with a policy override, like
any blocked version, once its build is ready.

A deploy of an older version from the console, the CLI, the MCP tools or
`POST /api/projects/{id}/deploy` while the move's own deploy of the project still waits for its
build (`building` in `migration-status`) takes that deploy's place. The project keeps running on
the old cluster until the new deploy starts, and the report follows the new deploy, with its
version: `deployed` once it started — the deployment on the old cluster is replaced like by any
deploy — or, when its build fails or a deploy check refuses it, `not_moved` with the reason. The
deployment on the old cluster is then shut down as a project the move refused, with "Not moved to
the new cluster of the region: …" and the reason, and is neither metered nor counted as running
from then on; a rollback deploys it there again like any project the move did not move.

#### Preview environments

The live [preview environments](git-deploy.md#preview-environments) of the region's projects move
with them: each one is deployed again on the new cluster through the preview path, as its
**Redeploy** does — the stop, a fixed allocation, data residency and the policy gate apply. A
preview version built into the old cluster's registry, or whose build was running on the old
cluster, is built again on the new cluster first (status `building`, "Built again on the new
cluster of the region: its image is in a registry that cluster does not pull from; deployed there
when it is ready."). A preview whose version could not be built is marked `failed` with "Not
moved to the new cluster of the region: the build of its version failed (failed); push a new
commit to the request to build and deploy it there". A preview on a runtime image RunR builds in
the region waits for that image (up to 15 minutes). The old cluster keeps the preview Services
it ran, like the projects'.

### Completing and rolling back the move

The data of the data services stays on the old cluster: the new cluster's are empty, and their
content is moved with the services' own tools (a dump of the Postgres databases, a copy of the
buckets). A private database connection's Skupper link is issued by the database side (an access
token) and is created again for the new cluster ([bindings.md](bindings.md)).

The status check resolves `dns-check.<function domain>` every minute ([Ingress, external address
and DNS](#ingress-external-address-and-dns)); once it reaches the new address the move is
`completed`. It also completes 48 hours after it reached `dns_check`, or when you complete it
(Migration → **Complete Migration**, `runr regions migration complete`,
`POST /api/knative-regions/{id}/complete-migration`; only in `dns_check`). Completing forgets the
old kubeconfig, address and the rest of the saved state; RunR removes nothing from the old
cluster. While a move runs, a banner in the console counts down the 48 hours.

A region whose cluster no longer answers opens in the console on its Migration tab, so it can be
moved to a new cluster from there. The tab asks for the new cluster's data location when the
region has one.

A move in `dns_check` or `failed` can be rolled back (Migration → **Rollback Migration**,
`runr regions migration rollback`, `POST /api/knative-regions/{id}/rollback-migration`): the
region gets its old kubeconfig and external address back, and with them everything else the move
set from the new cluster, as it was before the move — the old cluster is still the way the move
found it:

- the registry address: the old cluster's Zot again, which every redeploy and build of the
  region's projects uses for its images;
- the dedicated workers and the capacity they give;
- the install state of the Knative Operator and Serving, the Prometheus and Loki addresses and the
  runr-agent's token (a Serving install on the new cluster gave its agent a new one; the old
  cluster's agent is accepted again);
- the install state of the managed Redis, Kata Containers (with its hypervisors and Firecracker's
  thin-pool size) and gVisor;
- WebAssembly support with its shims and nodes, GPU support with its settings, nodes and GPU
  inventory, and function TLS;
- the status of the data services RunR runs, of the admission policy and of the tracing backend
  (their settings and credentials are the region's and do not change). A data service or a
  tracing backend that was installed on the new cluster only is not installed after the
  rollback.

A move that was already running when RunR was upgraded to a release that keeps this state has
none of it saved: its rollback gives back the kubeconfig and the external address only.

In a multi-tenant install the copies of a platform region in the organizations it is assigned to
get the restored values with the same sync as the move's ([Regions in multi-tenant
installs](#regions-in-multi-tenant-installs)). Then every project that runs is deployed there
again, the same way as in step 6 — the organizations' projects on a platform region too. What
the move deployed for the projects on the new cluster is removed from there; its installs and
data services stay ([What the rollback removes from the new
cluster](#what-the-rollback-removes-from-the-new-cluster)).

A version built while the region was on the new cluster (a runtime with a build step, a
WebAssembly function) was pushed to the new cluster's registry only, and the old cluster's nodes
cannot pull it. The rollback does not deploy such a version: it deploys the project's last
deployment whose version is in the restored registry again, with its overrides — usually what
the project ran before the move, whose image the old cluster's registry still has — and shuts
the deployments of the version left behind down with the message "Not deployed again on the
cluster the migration rollback restored: v5 was built into the registry of the cluster the
rollback left; v4 runs instead" (the console shows it with the project's deployments; the log has
the same line). To run that code on the restored cluster, deploy that version again: it is
[built again](#older-versions) there first. A project with no such deployment (created on the
new cluster) is removed from the old cluster like a refused one, and from the new cluster with
the other projects; its data services stay there like theirs.

What the move [built again](#images-the-new-cluster-cannot-pull) goes back to the old cluster's
images, which its registry still has: a version built again gets its old image back, and the
rollback deploys it on the old cluster like every other project — also when its build on the new
cluster failed and the move did not move it. So does a version that a deploy during the move
built again ([older versions](#older-versions)). The region's runtime image builds come back as
they were; one made on the new cluster only is built again on the old one when a project needs
it.

A deploy queued since the move started that has not created its deployment yet is cancelled
("Cancelled by the migration rollback: …"): one that still waits for its build, and one that a
worker has claimed and is still checking — that worker then starts nothing. One that has created
its deployment is a deploy like any other: the rollback's redeploy replaces that deployment.

A build still running on the new cluster when the rollback comes — a version saved or pushed
there — would be looked for on the old cluster, which never had its Kaniko Job. It ends with the
build status `cancelled` and "Cancelled by the migration rollback: the build ran on the cluster
the region left. Save a change or push the commit again to build it on the restored cluster." It
is not a failed build: no "Build failed" notification goes out, a git push that waited for it
ends as skipped ("Build cancelled by a region migration rollback" on the provider), and a queued
deploy of it as cancelled. Its Kaniko Job is deleted from the new cluster. A move to another
kubeconfig of the same cluster runs its builds where the region is again, and they go on.

A CaaS project switched to an image uploaded while the region was on the new cluster is not
deployed again on the old cluster, whose registry does not have that image: its deployments are
shut down with "Not deployed again on the cluster the migration rollback restored: its image
myapp:1.0 was uploaded into the registry of the cluster the rollback left; upload it to the
region again, select it as the project's image and deploy", and what it runs on the old cluster
is removed. The uploads made before the move are in the region's registry again.

The old cluster still has the Knative Service of every project that ran before the move, so the
rollback also takes care of the projects with no live deployment that run there: a project the
move did not move (step 6 refused it) is deployed again with the version and overrides it ran
with — metered from when it is ready, the time on the new cluster stays unmetered — and when the
deploy checks still refuse it, or the project was stopped while the region was on the new cluster,
what it runs on the old cluster is removed. A project whose redeploy the rollback itself refuses
is removed from the old cluster the same way; its deployments are shut down with the reason.

The live [preview environments](#preview-environments) are deployed on the old cluster again the
same way: a preview version built into the new cluster's registry, or whose build still runs
there (its Kaniko Job is deleted from the new cluster), is built again on the old one first ("Built
again on the cluster the migration rollback restored: …").

#### What the rollback removes from the new cluster

The functions the move deployed on the new cluster would keep answering there, on that cluster's
own address, with nothing metering them. After the rollback RunR removes from the new cluster:

- the Knative Services of the projects (a WebAssembly function is one too) and of their preview
  environments;
- their CronJobs and queue consumers (and workflow queue bridges), with the Jobs and pods these
  started;
- what they read or built: the code ConfigMaps, the Secrets of the projects' secret environment
  variables and of their queue consumers, and the build Jobs with their source ConfigMaps.

It finds them in the region's project namespace by the labels RunR sets on them
(`runr/project-id`, `runr/preview-of`), for the region's projects only: in a multi-tenant install
those of every organization on a platform region too, and a project created while the region ran
on the new cluster. Another region's projects in a namespace of the same name are left alone.

What stays on the new cluster:

- what the move installed there: Knative with Traefik, Prometheus and Loki, the runr-agent and the
  tracing backend, the managed Redis, the registry with its images, Kata Containers, gVisor,
  WebAssembly and GPU support, the admission policy;
- the data services with their volumes, credentials and data: they may hold what was written while
  the region ran there. Copy what you need back to the old cluster's services, then remove them
  yourself;
- the projects' `<project>-bindings` Secrets (the data services' credentials), private database
  connections, custom domain routes and certificates, and the project namespace, which holds them.

Nothing is removed when the cluster the rollback leaves is the restored one: the same API server
(a move to another kubeconfig of the same cluster, or a move that failed before it switched), or
another address of it — RunR compares the UIDs of the two clusters' `kube-system` namespaces. Nor
when the region is being moved again by the time the removal runs.

The removal runs in the background (`RemoveLeftClusterWorkloadsJob`: three attempts, at once, a
minute later and five minutes after that; it carries the new cluster's kubeconfig, which the
region no longer holds, encrypted on the queue): the rollback does not wait for the new cluster
and is done when that cluster does not answer. The rollback's answer says so — "Migration rolled back. The project functions the move
deployed on the new cluster are removed from it in the background (its installs and data services
stay); a notification tells how it went." — and the operator who rolled back gets a notification:

- "Left cluster cleanup of eu: removed 3 project workloads", with what was removed under it
  ("Removed from the cluster the rollback left: 3 project workloads (Knative Services orders,
  orders-pr-4; CronJob reports-trigger) and 2 objects they read or built (ConfigMaps, Secrets).
  Its installs and data services stay."), or "… nothing removed" with the reason;
- "Left cluster cleanup of eu failed", with the reason — e.g. "the cluster the rollback left does
  not answer: …" — and what to remove yourself: the Knative Services, CronJobs and queue consumers
  labelled `runr/project-id` or `runr/preview-of` with the region's projects in the project
  namespace of that cluster.

The API log has the same lines (`RegionMigrate: Left cluster cleanup of eu …`).

## Changing the base domain

The platform's base domain (Administration → Platform, **Base Domain**; `PATCH /api/settings`
with `base_domain`) gives every region that follows it its function domain
`<region>.<base domain>`. A region follows the base domain while its function domain
(`knative_domain` of its settings) is `<region>.<base domain>` or not set yet; a region with a
function domain of its own keeps it whatever the base domain is.

- **The first base domain** goes to every region with Knative Serving that has no function
  domain: Knative's domain configuration and the region's `knative_domain`.
- **A changed base domain** starts a migration on every region that follows the old one
  (`domain_migration_status` `dns_check`). The region keeps its function domain — its projects'
  addresses do not change yet — and also answers on `<region>.<new base domain>`
  (`domain_migration_target` of `GET /api/knative-regions/{id}`): a small proxy in the project
  namespace (Deployment, Service and IngressRoutes `runr-domain-alias`, 10m CPU / 32 MiB
  requested per pod, two pods) sends every `<project>.<region>.<new base domain>` to the project's
  Knative Service. Create the wildcard DNS record of the new domain, pointing at the same external
  address.
- **Completion.** The status check (every minute) and the scheduler (hourly) check
  `dns-check.<region>.<new base domain>`; once it reaches the region's external address the new
  domain becomes the function domain: Knative's domain configuration and the KnativeServing
  resource name it, the region's `knative_domain` is it — project URLs, function TLS, the status
  block and request replay follow — and the old domain is no longer served. Without that DNS
  answer both stay served. Migration → **Complete domain migration**,
  `runr regions migration complete REGION --domain` or
  `POST /api/knative-regions/{id}/complete-domain-migration` completes it at once (for DNS the
  control plane resolves differently from the clients).
- **Rollback.** Setting the base domain back to the old one during the migration ends it: the
  region keeps its function domain and the new one is no longer served. A third base domain moves
  the second address to `<region>.<third base domain>`; the function domain stays.
- **A cleared base domain** removes the function domain from the regions that follow it.

During the migration HTTPS on the new domain is answered with the certificate the region Traefik
serves by default — the [function certificate](#function-tls) when one is set. An uploaded
certificate whose names cover both domains (`*.<region>.<old base domain>` and
`*.<region>.<new base domain>`) serves both; one of the old domain only is replaced after the
completion. Custom domains with a CNAME to a project's default
host (`<project>.<region>.<old base domain>`) point it at the new default host before the
completion. With the Tenancy module a tenant's own base domain is handled by the tenant's
settings and moves the same way — on the platform regions through an alias of the tenant's own,
on its own regions through this migration ([Organization domain](tenancy.md#organization-domain)).

## Regions in multi-tenant installs

With the Tenancy module ([tenancy.md](tenancy.md)) a region is either a platform region or a
tenant-owned region.

**Platform regions** are connected by Platform users outside any tenant and live in the central
schema. A Platform user assigns a platform region to a tenant with a CPU and memory quota; the
assignment copies the region into the tenant's schema without its kubeconfig, and RunR reads the
kubeconfig from the platform region whenever it acts on the cluster for the tenant. Tenant users
see only the regions assigned to their tenant, as a summary (name, mode, their own project count,
the GPU summary) without cluster, component or capacity details; the kubeconfig download and the
region's capacity budgets answer 403. Platform regions are read-only for Tenant users. With the
Billing module on, an assignment is an offer: the region is copied into the tenant only after a
tenant administrator accepted it with a markup
([Shared regions with billing](tenancy.md#shared-regions-with-billing)).

| Call (Platform users only) | What it does |
|---|---|
| `GET /api/tenants/{tenant}/regions` | The tenant's regions with quota, usage and the quota's capacity budgets |
| `POST /api/tenants/{tenant}/regions` | Assign a region: `region_id`, `cpu_quota_m`, `memory_quota_mb` |
| `PATCH /api/tenants/{tenant}/regions/{region}` | Change the quota |
| `PUT /api/tenants/{tenant}/regions/{region}/gpu-quota` | The GPU quota: `gpu_quota`, `null` no limit, `0` no GPUs ([ai.md](ai.md#gpu-quota)) |
| `DELETE /api/tenants/{tenant}/regions/{region}` | End the assignment: the tenant's projects in the region are taken down, their deployments shut down and their region cleared |

A quota is the tenant's share of the region capacity: on a `managed` region the quotas of all
tenants add up to at most the region capacity (and the organization ceiling, Helm `quota.cpu` /
`quota.memoryGiB`, when one is set, [architecture.md](architecture.md#editions)); an assignment
or change above that answers 422. A quota of 0 sets no limit for that resource and
does not count towards the sum. When the request leaves a quota out and an organization ceiling
is set, the quota is the ceiling or what is still free on the region, whichever is smaller. How
admission splits a quota into the always-on and the FaaS overcommit budget:
[Region capacity and admission](architecture.md#region-capacity-and-admission).

**Region mode** (`region_mode`) decides whether quotas apply on a platform region:

| Mode | Quotas | Who sees the region |
|---|---|---|
| `managed` (default) | CPU, memory and GPU admission check the tenant's quota; assignments are checked against the capacity | the tenants it is assigned to |
| `infinite` | no CPU, memory or GPU quota check, no capacity check on assignment | the tenants it is assigned to |

The mode is set with `region_mode` on `POST /api/knative-regions`, `POST /api/registry-setup/start`
(the console wizard's first step) or `PUT /api/knative-regions/{id}`; the console shows it on the
region's General tab. Only platform administrators set it: from anyone else the field is ignored
(the region keeps its mode, a new one is `managed`). Platform users are not held to tenant quotas
on any region.

**Tenant-owned regions** are connected inside a tenant by its users with `regions.manage` (owners
and admins by default, [permissions.md](permissions.md#infrastructure)); they live in the
tenant's own schema and are never assigned to other tenants. Every route that changes a region or
hands out its kubeconfig checks who may do it (`region.mutable`):

| Region | Platform users | Tenant users |
|---|---|---|
| Platform region | change it outside any tenant | read-only (403) |
| Their tenant's own region | change it from within that tenant's context | change it with `regions.manage`, `sandbox.manage` or `registry.manage` (and the route's own permission) |

`region:reconcile` brings the tenant-owned regions up to date too: the cluster steps for the
active ones, the [status block](#region-status) for every one with Knative Serving ready.

## Removing a region

Removing a region — the trash button in the console's region list, offered once the region has
no projects, `runr regions rm REGION` or `DELETE /api/knative-regions/{id}` — deletes RunR's
record of the region. **Nothing is removed
from the cluster.** Everything RunR installed keeps running there, the projects' Knative Services
included, and they keep answering on the function domain while DNS points at the cluster. The
region's runr-agent is turned away from then on: its token belongs to a region RunR no longer
knows.

In RunR's database the region's projects stay, without a region; the region's metric snapshots,
deployment sessions, gateways and log retention setting are deleted with it (with the Tenancy
module also its tenant assignments), and its bindings stay with the region cleared. Closed bills
keep the region's name in their line items.

To take a cluster out of service completely:

1. Delete the region's projects, or move them to another region.
2. Remove what RunR installed, from the region's pages in the console or with the API, while the
   region still exists:

   | What | Console | API |
   |---|---|---|
   | Demo projects | General → **Demo Projects** | `DELETE /knative-regions/{id}/demo-projects` |
   | Data services (after their bindings) | Data services | `DELETE /knative-regions/{id}/data-services/{service}` |
   | GPU support | GPU → **Remove** | `POST /knative-regions/{id}/gpu/uninstall` |
   | WebAssembly, Kata Containers, gVisor | Isolation | `DELETE /knative-regions/{id}/wasm`, `POST /knative-regions/{id}/uninstall-sandbox` |
   | Admission policy | Supply chain | `DELETE /knative-regions/{id}/supply-chain/admission` |
   | Function TLS | General → **Remove certificate** | `DELETE /knative-regions/{id}/function-tls` |
   | Tracing backend | Tracing tab | `DELETE /observability/regions/{id}/tracing` |
   | Redis | Redis → **Remove Redis** | `DELETE /knative-regions/{id}/redis` |
   | Knative Serving and Traefik, then the operator | Serving → **Uninstall Serving**, Operator → **Uninstall Operator** | `POST /knative-regions/{id}/uninstall-serving` (`remove_traefik`), then `…/uninstall-operator` |
   | Registry | Registry → **Remove registry** | `DELETE /knative-regions/{id}/registry` (`runr regions registry rm`) |

3. Remove the region.
4. Remove what is left with `kubectl` and `helm`: the `observability` namespace (Helm releases
   `knative` and `loki-stack`, cAdvisor, the runr-agent and its Secret `runr-agent-token`), the
   project namespace (the region name), whichever of `runr-registry`, `runr-sandbox`,
   `runr-sbx-*`, `runr-data`, `cnpg-system`, `runr-supply-chain`, `cosign-system` and `runr-gpu`
   remain, the ClusterRoles and ClusterRoleBindings `runr-agent` and `cadvisor-runr`, the
   PriorityClass `observability-critical`, and on the nodes the labels `runr.dev/dedicated`,
   `node-role.kubernetes.io/knative-worker`, `runr.dev/kvm` and `runr.dev/wasm*`, the taint
   `runr.dev/dedicated` and the `hosts.toml` files the registry's node mirror wrote into
   containerd's configuration.

## API reference

Every route is under `/api` and needs a bearer token or a console session. The permissions are
those of [permissions.md](permissions.md#infrastructure) (`api/config/route_permissions.php`);
`a|b` means either.
Every route that changes a region — every `POST`, `PUT` and `DELETE` below except the four
kubeconfig previews and the registry tests — and the kubeconfig download also pass the region
mutability check ([Regions in multi-tenant installs](#regions-in-multi-tenant-installs)).

**Region**

| Method and path | Permission | Purpose |
|---|---|---|
| `POST /knative-regions/validate-kubeconfig` | `regions.manage` | Connect with a kubeconfig and check the Kubernetes version; returns the version |
| `POST /knative-regions/preview-nodes` | `regions.manage` | Worker nodes of the kubeconfig's cluster |
| `POST /knative-regions/preview-storage-classes` | `regions.manage` | StorageClasses of the cluster and which is the default |
| `POST /knative-regions/check-namespace-preview` | `regions.manage` | Whether a namespace exists on the cluster |
| `GET /knative-regions` | `regions.view` | Every region |
| `GET /knative-regions/{id}` | `regions.view` | One region: components, capacity, DNS instruction, settings |
| `POST /knative-regions` | `regions.manage` | Create a region ([Connecting a region](#connecting-a-region)) |
| `PUT /knative-regions/{id}` | `regions.manage` | `region_status`, a new `kubeconfig`, with the Tenancy module `region_mode` (platform administrators) |
| `DELETE /knative-regions/{id}` | `regions.manage` | Remove the region record ([Removing a region](#removing-a-region)) |
| `GET /knative-regions/{id}/kubeconfig` | `regions.manage` | Download the kubeconfig |
| `PUT /knative-regions/{id}/settings` | `regions.manage` | [General settings](#general) |
| `PUT /knative-regions/{id}/monitoring` | `regions.manage` | The region's Prometheus and Loki ([Observability](#observability)) |
| `GET /knative-regions/{id}/capacity` | `regions.view` | Capacity budgets and overcommit factors |
| `PUT /knative-regions/{id}/capacity` | `regions.manage` | Overcommit factors |
| `GET /knative-regions/{id}/nodes` | `regions.view` | Worker nodes with capacity and selection |
| `POST /knative-regions/{id}/workers` | `regions.manage` | Select the dedicated workers |
| `POST /knative-regions/{id}/migrate` | `regions.manage` | Start a migration to another cluster |
| `GET /knative-regions/{id}/migration-status` | `regions.view` | Migration status and, while the move can be rolled back, what it did with each project (`projects`, [Images the new cluster cannot pull](#images-the-new-cluster-cannot-pull)) |
| `POST /knative-regions/{id}/complete-migration` | `regions.manage` | Complete a migration in `dns_check` |
| `POST /knative-regions/{id}/rollback-migration` | `regions.manage` | Roll back a migration in `dns_check` or `failed`; the projects' workloads leave the new cluster in the background ([what is removed](#what-the-rollback-removes-from-the-new-cluster)) |
| `POST /knative-regions/{id}/complete-domain-migration` | `regions.manage` | Complete a base-domain migration ([Changing the base domain](#changing-the-base-domain)) |
| `POST /knative-regions/{id}/demo-projects` | `regions.manage` | Deploy the demo projects |
| `DELETE /knative-regions/{id}/demo-projects` | `regions.manage` | Remove the demo projects |

**Knative**

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/knative-status` | `regions.view` | Checks the cluster now: whether RunR reaches it and Knative Serving and the region's Traefik are ready (`runr regions status`; `?force=1` also while an install runs or after an error). An unreachable cluster answers the error and is recorded as unreachable; the kubeconfig stays |
| `GET /knative-regions/{id}/compatible-versions` | `regions.view` | Knative versions the cluster supports ([Knative and Kubernetes versions](#knative-and-kubernetes-versions)) |
| `GET /knative-regions/{id}/check-namespace?name=` | `regions.view` | Whether a namespace exists on the cluster |
| `GET /knative-regions/{id}/component-status` | `regions.view` | Recorded status of the operator, Serving and the sandbox runtimes, with namespaces, version and sandbox mapping |
| `POST /knative-regions/{id}/install-operator` | `regions.manage` | Install the Knative Operator (`namespace`), or adopt one running there |
| `POST /knative-regions/{id}/install-serving` | `regions.manage` | Install Knative Serving (`namespace`, `version`), or adopt a Ready one running there |
| `POST /knative-regions/{id}/install-knative` | `regions.manage` | Install the operator and Serving, adopting what runs on the cluster |
| `POST /knative-regions/{id}/upgrade-serving` | `regions.manage` | Upgrade Knative Serving to the next minor version (`version`) |
| `POST /knative-regions/{id}/uninstall-knative` | `regions.manage` | Remove Serving and the operator (`remove_traefik`) |
| `POST /knative-regions/{id}/uninstall-serving` | `regions.manage` | Remove Serving (`remove_traefik`) |
| `POST /knative-regions/{id}/uninstall-operator` | `regions.manage` | Remove the operator and the Knative CRDs |
| `POST /knative-regions/{id}/detect-components` | `regions.manage` | Adopt an operator and Serving already running on the cluster |
| `PUT /knative-regions/{id}/function-tls` | `regions.manage` | [Function TLS](#function-tls) |
| `DELETE /knative-regions/{id}/function-tls` | `regions.manage` | Switch function TLS off |

**Redis, registry, Skupper**

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/redis-status` | `regions.view` | The Redis of queue triggers |
| `POST /knative-regions/{id}/test-redis` | `regions.manage` | Check from a pod in the cluster that a Redis answers |
| `POST /knative-regions/{id}/redis` | `regions.manage` | Install or connect the Redis |
| `DELETE /knative-regions/{id}/redis` | `regions.manage` | Remove it |
| `POST /registry-setup/start` | `regions.manage` and `registry.manage` | Create a region together with its registry; returns a setup token |
| `GET /registry-setup/{token}/status` | `regions.view` | Progress of that setup |
| `POST /registry-setup/{token}/retry` | `regions.manage` and `registry.manage` | Start a failed setup again |
| `POST /registry-setup/test-pvc`, `test-s3`, `test-external` | `registry.manage` | Check a StorageClass, a bucket or an external registry from a pod in the cluster |
| `GET /knative-regions/{id}/registry-status` | `regions.view` | The region's registry |
| `POST /knative-regions/{id}/registry` | `registry.manage` | Set up the registry of an existing region ([Registry](#registry)) |
| `DELETE /knative-regions/{id}/registry` | `registry.manage` | Remove it |
| `GET /knative-regions/{id}/skupper` | `regions.view` | Whether the cluster runs Skupper v2, and the site of the project namespace |
| `POST /knative-regions/{id}/skupper/site` | `regions.manage` | Create that site ([bindings.md](bindings.md)) |

**Sandbox runtimes, WebAssembly, GPU**

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/sandbox-status` | `regions.view` | gVisor and Kata Containers status |
| `GET /knative-regions/{id}/kata-shims` | `regions.view` | Kata hypervisors and the nodes with KVM (`?refresh=1` checks the nodes again) |
| `POST /knative-regions/{id}/install-sandbox` | `sandbox.manage` | Install gVisor or Kata Containers |
| `POST /knative-regions/{id}/uninstall-sandbox` | `sandbox.manage` | Remove one |
| `PUT /knative-regions/{id}/sandbox-mapping` | `sandbox.manage` | RuntimeClass per workload kind |
| `GET /knative-regions/{id}/wasm` | `regions.view` | WebAssembly support |
| `POST /knative-regions/{id}/wasm` | `sandbox.manage` | Install it (`shims`, `nodes`) |
| `DELETE /knative-regions/{id}/wasm` | `sandbox.manage` | Remove it |
| `GET /sandbox-regions` | `sandboxes.view` | Sandbox API settings of every region |
| `PUT /sandbox-regions/{id}` | `sandboxes.manage` | Sandbox API settings of one region |
| `GET /knative-regions/{id}/gpu` | `regions.view` | GPU support and inventory |
| `POST /knative-regions/{id}/gpu/install` | `regions.gpu.manage` | Install GPU support |
| `POST /knative-regions/{id}/gpu/uninstall` | `regions.gpu.manage` | Remove it |
| `PUT /knative-regions/{id}/gpu/settings` | `regions.gpu.manage` | GPU settings |
| `GET /knative-regions/{id}/model-storage` | `regions.view` | The model volume |
| `PUT /knative-regions/{id}/model-storage` | `regions.gpu.manage` | Its StorageClass, size and access mode |

**Data services, observability, supply chain**

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/data-services` | `regions.view\|bindings.view` | KV, object storage and Postgres of the region |
| `POST /knative-regions/{id}/data-services/{kv\|object\|postgres}` | `regions.data_services.manage` | Install or change one |
| `DELETE /knative-regions/{id}/data-services/{kv\|object\|postgres}` | `regions.data_services.manage` | Remove one |
| `GET /observability/regions/{id}/tracing` | `regions.view` | The tracing backend |
| `PUT /observability/regions/{id}/tracing` | `regions.observability.manage` | Its mode and settings (`install: true` installs it too) |
| `POST /observability/regions/{id}/tracing/install` | `regions.observability.manage` | Install it |
| `DELETE /observability/regions/{id}/tracing` | `regions.observability.manage` | Remove it |
| `GET /knative-regions/{id}/supply-chain` | `regions.view` | Supply-chain settings and the data location |
| `PUT /knative-regions/{id}/supply-chain` | `regions.supply_chain.manage` | Change them |
| `GET /knative-regions/{id}/supply-chain/public-key` | `regions.view` | The signing public key |
| `POST /knative-regions/{id}/supply-chain/signing-key` | `regions.supply_chain.manage` | Generate a signing key |
| `DELETE /knative-regions/{id}/supply-chain/signing-key/retired` | `regions.supply_chain.manage` | Forget retired keys |
| `POST /knative-regions/{id}/supply-chain/scanner-db/refresh` | `regions.supply_chain.manage` | Refresh the vulnerability database |
| `GET /knative-regions/{id}/supply-chain/runtime-images` | `regions.view` | Reports of the runtime images built in the region |
| `GET /knative-regions/{id}/supply-chain/runtime-images/{report}/sbom` | `regions.view` | The SBOM of one |
| `GET /knative-regions/{id}/supply-chain/admission/preview` | `regions.supply_chain.manage` | What installing the admission policy applies |
| `POST /knative-regions/{id}/supply-chain/admission` | `regions.supply_chain.manage` | Install the admission policy |
| `DELETE /knative-regions/{id}/supply-chain/admission` | `regions.supply_chain.manage` | Remove it |

With the Tenancy module, the tenant assignment routes are listed in
[Regions in multi-tenant installs](#regions-in-multi-tenant-installs).

## Artisan commands

Run them in the API container (`php artisan …`; with Compose `docker compose exec api php artisan …`).

| Command | What it does |
|---|---|
| `region:reconcile [--region=ID] [--dry-run]` | Brings existing regions up to date with the running release — the active platform regions and, with the Tenancy module, every tenant's own active regions; the steps are listed in [architecture.md](architecture.md#regions). Then the status block of every region with Knative Serving ready, platform regions and tenants' own regions alike ([Region status](#region-status)). The Helm chart's bootstrap Job runs it after every install and upgrade ([upgrade.md](upgrade.md#procedure)) |
| `knative:check-status` | Queues the status check of every region with a kubeconfig that is not installing or uninstalling: cluster reachability, the Knative components, the external address, the region capacity, the DNS check, the DNS step of a migration and of a base-domain migration. The scheduler runs it every minute |
| `regions:discover-ips` | Reads the external address of the Traefik of every region with Knative Serving ready again |
| `agent:redeploy [REGION-ID] [--missing] [--force]` | Deploys the runr-agent again with a new token, on every active region with Knative Serving ready or on one; it resolves the agent URL again. `--missing`: only regions without a token; a cluster that runs no agent, or the agent of another region, is skipped unless `--force` ([monitoring.md](monitoring.md#go-agent-runtimeagent)) |
| `gpu:refresh [--region=ID] [--all]` | Refreshes the GPU inventory of the active regions; the scheduler runs it every two minutes ([ai.md](ai.md#how-runr-detects-gpu-nodes)) |
| `cluster:install-tracing [REGION-ID] [--mode=managed\|reuse\|external\|off]` | Installs the tracing backend on one region or on every active region with Knative Serving ([observability.md](observability.md#commands)) |
| `cluster:patch-observability [REGION-ID]` | Applies the Prometheus and Loki Helm values again, on one region or every active region ([observability.md](observability.md#install-flow)) |
| `registry:redeploy-mirror [REGION-ID]` | Deploys the registry's node mirror DaemonSet again, on every active region with the internal registry or on one |
| `registry:rolling-restart REGION-ID [--timeout=300] [--in-place]` | Restarts containerd on the region's worker nodes (the dedicated workers when selected) one at a time: cordon, drain, restart, wait until Ready (`--timeout` seconds), uncordon. Needed on plain containerd after the mirror changed `config.toml`; k3s and rke2 need no restart. A node that runs RunR itself, or the only schedulable node, is not drained; `--in-place` restarts containerd there without draining |
