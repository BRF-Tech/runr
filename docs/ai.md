# AI on RunR: GPU workers, GPU containers and models

RunR is a serverless platform, and its AI support stays serverless: you add a worker node with
an NVIDIA GPU to a region's cluster, RunR finds it, and any container or function that asks for
a GPU is scheduled there, scales to zero when idle and is billed by the GPU time it used. Next to
that, models from the Hugging Face Hub deploy with one click as model services that every
function — Python, Node.js, Go, C or anything else that speaks HTTP — calls inside the cluster,
online or fully offline. RunR is not an agent framework: it runs models and the code that uses
them, nothing in between.

| What | Where |
|---|---|
| GPU worker nodes, GPU support install, GPU scheduling, GPU containers (CaaS), GPU runtime versions, sandboxes, quotas, billing, sizing | this document |
| One-click Hugging Face models, engines per task, online / offline / air-gapped, gated models, binding models to functions | [huggingface.md](huggingface.md) |
| Runnable examples (GPU CaaS image, model calls from Python / Node / Go / curl) | [`examples/ai/`](../examples/ai/) |

- [Adding GPU worker nodes](#adding-gpu-worker-nodes)
- [How RunR detects GPU nodes](#how-runr-detects-gpu-nodes)
- [Installing GPU support](#installing-gpu-support)
- [GPU containers (CaaS)](#gpu-containers-caas)
- [GPU runtime versions (XaaS / FaaS)](#gpu-runtime-versions-xaas--faas)
- [Supported sandbox combinations](#supported-sandbox-combinations)
- [Quotas and billing](#quotas-and-billing)
- [Sizing guidance](#sizing-guidance)
- [Permissions](#permissions)
- [API](#api)
- [Helm values](#helm-values)
- [Testing](#testing)

## Adding GPU worker nodes

A GPU node is an ordinary worker of the region's cluster with an NVIDIA GPU. Two ways to prepare
it:

| Node has | Install in RunR | What runs on the node |
|---|---|---|
| the NVIDIA driver and the [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html) | **Device plugin** (default) | the NVIDIA device plugin, GPU Feature Discovery and Node Feature Discovery |
| only the GPU (bare OS) | **GPU Operator** | the GPU Operator installs the driver and the container toolkit itself, then the device plugin |

Preparing a node for the device plugin (Ubuntu / Debian, containerd):

```bash
# 1. NVIDIA driver (distribution package or NVIDIA's installer); check it:
nvidia-smi -L
# 2. NVIDIA Container Toolkit
curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
  | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
  | sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit
# 3a. containerd: register the NVIDIA runtime (optionally as the default runtime)
sudo nvidia-ctk runtime configure --runtime=containerd --set-as-default
sudo systemctl restart containerd
```

**k3s and RKE2** detect the toolkit on start and register a runtime called `nvidia` *next to*
runc, not as the default: GPU pods have to ask for it with `runtimeClassName: nvidia`. RunR
handles that — with RuntimeClass **auto** (the default of the install dialog) it creates the
RuntimeClass `nvidia` on k3s / RKE2 clusters and every GPU pod under runc uses it. Restart the
k3s agent after installing the toolkit (`sudo systemctl restart k3s-agent`, or `k3s` on a server
node) so it picks the runtime up.

Then join the node to the cluster as usual. If the region uses dedicated workers (Settings →
Regions → Workers), select the GPU node there too: RunR taints and labels dedicated workers, and
GPU pods carry the same toleration and node selector as every project pod.

## How RunR detects GPU nodes

RunR reads every node of the region (`GpuInventory`):

| Source | Read as |
|---|---|
| `status.allocatable["nvidia.com/gpu"]` (+ `nvidia.com/gpu.shared`) | schedulable GPU units; with time-slicing each physical GPU counts `replicas` times |
| `status.capacity["nvidia.com/gpu"]` | the device plugin works on the node |
| `nvidia.com/gpu.product` | GPU type, e.g. `NVIDIA-L4`, `NVIDIA-GeForce-RTX-4050-Laptop-GPU` |
| `nvidia.com/gpu.memory` | GPU memory in MiB |
| `nvidia.com/gpu.count`, `nvidia.com/gpu.replicas` | physical GPUs, time-slicing replicas |
| `nvidia.com/gpu.compute.major` / `.minor` | compute capability (`8.9`), also used to pick the TEI GPU image |
| `nvidia.com/cuda.driver-version`, `nvidia.com/cuda.runtime-version` (or their `.major/.minor/.rev` labels) | driver and CUDA versions |
| `feature.node.kubernetes.io/pci-10de.present`, `nvidia.com/gpu.present` | NVIDIA hardware is there — a node with it but no GPU units is listed as *hardware without plugin* |
| `spec.taints` | shown per node (GPU pods tolerate `nvidia.com/gpu` and `sku=gpu`) |

The labels come from GPU Feature Discovery, which both install modes run. RunR also sums the GPUs
that running pods request — per node and per owner (project, model service, anything else) —
into the region's allocation.

The inventory refreshes:

- every two minutes for regions with GPU support or GPU nodes (`php artisan gpu:refresh`,
  scheduled), every 30 minutes for the others (`AI_GPU_REFRESH_IDLE_MINUTES`, set via
  `extraEnv`) — a GPU node added to any region shows up on its own;
- after an install, uninstall or settings change;
- on demand: **Refresh** in the region's GPU tab (`GET /api/knative-regions/{id}/gpu?refresh=1`),
  or `php artisan gpu:refresh --region=<id>` / `--all`. A refresh is always read from the cluster:
  the API's response cache skips requests with `refresh`, and the answer carries
  `Cache-Control: no-store`.

A cluster whose nodes already advertise `nvidia.com/gpu` — a device plugin installed by the
cluster administrator, a managed Kubernetes GPU node pool — counts as GPU support in mode
**external**: projects can use the GPUs right away and RunR leaves that installation alone.

**Administration → Platform → Knative Regions → (region) → GPU** shows the summary (GPU nodes, total, allocatable,
allocated, free), the GPU products, every GPU node with product, memory, count / allocatable /
allocated, sharing replicas, driver and CUDA, whether the device plugin runs and whether NVIDIA
hardware was found without it, the install status and log, and which project types can use GPUs
under the region's sandboxes.

## Installing GPU support

**Install GPU support** in the GPU tab (or `POST /api/knative-regions/{id}/gpu/install`) runs in
the background and logs every step in the tab.

| Option | Values | Default | Meaning |
|---|---|---|---|
| `mode` | `device-plugin`, `operator` | `device-plugin` | see [Adding GPU worker nodes](#adding-gpu-worker-nodes) |
| `time_slicing_replicas` | empty / 1, 2–16 | empty | share every GPU between this many pods |
| `runtime_class` | `auto`, `nvidia`, `none` | `auto` | the RuntimeClass GPU pods use under runc |
| `operator_driver` | `true` / `false` | `true` | GPU Operator installs the driver |
| `operator_toolkit` | `true` / `false` | `true` | GPU Operator installs the NVIDIA Container Toolkit |
| `restrict_to_gpu_nodes` | `true` / `false` | `true` | the device plugin and GPU Feature Discovery run only on the GPU nodes ([GPU nodes only](#gpu-nodes-only)) |
| `gpu_nodes` | node names | empty | the GPU nodes; empty = detected automatically |

What it puts on the cluster, in the namespace `runr-gpu`:

- **Device plugin**: the Helm chart `nvidia-device-plugin` (repository
  `https://nvidia.github.io/k8s-device-plugin`, version `ai.gpu.devicePluginChartVersion`,
  release `nvidia-device-plugin`) with GPU Feature Discovery and Node Feature Discovery enabled,
  tolerating every taint (dedicated RunR workers and tainted GPU nodes alike), priority class
  `system-node-critical`. Node Feature Discovery finds the nodes with an NVIDIA PCI device; the
  plugin runs there.
- **GPU Operator**: the Helm chart `gpu-operator` (repository `https://helm.ngc.nvidia.com/nvidia`,
  version `ai.gpu.operatorChartVersion`, release `gpu-operator`) with the driver and the toolkit
  as chosen, `operator.runtimeClass: nvidia`, DaemonSets tolerating every taint. Building the
  driver takes a while per node; the tab shows the GPUs once the nodes advertise them.
- **RuntimeClass `nvidia`** (handler `nvidia`) for the device plugin mode when `runtime_class`
  resolves to `nvidia`; the GPU Operator creates its own.
- **Knative pod spec features** GPU and model workloads need, on the KnativeServing resource (or
  the `config-features` ConfigMap without the Knative Operator):
  `kubernetes.podspec-nodeselector`, `-affinity`, `-tolerations`, `-runtimeclassname`,
  `-persistent-volume-claim`, `-persistent-volume-write`, `-init-containers`, `-emptydir`. They
  stay enabled when the worker selection changes and are applied again when Knative Serving is
  (re)installed on a GPU region.

### GPU nodes only

With `restrict_to_gpu_nodes` (on by default) the device plugin and GPU Feature Discovery
DaemonSets select `nvidia.com/gpu.present=true` (nodeSelector and node affinity), and RunR puts
that label on the GPU nodes:

- the nodes named in `gpu_nodes`, or
- with `gpu_nodes` empty, every node that shows an NVIDIA GPU: an `nvidia.com/gpu` resource, GPU
  Feature Discovery labels, `nvidia.com/gpu.present=true`, or the Node Feature Discovery label
  `feature.node.kubernetes.io/pci-10de.present=true`. `gpu:refresh` labels GPU nodes that join
  later (for example once Node Feature Discovery has reported their NVIDIA device).

Nodes without a GPU never run the plugin, also on mixed clusters. On clusters where every node
reports the host's NVIDIA device — Kind and other nested setups that share the host's PCI view —
name the GPU nodes in `gpu_nodes`. RunR marks the labels it sets (annotation
`runr.dev/gpu-node-label`) and removes only those again (a node leaving `gpu_nodes`, uninstall);
labels set by the cluster administrator stay. With the GPU Operator and `gpu_nodes`, every other
node gets `nvidia.com/gpu.deploy.operands=false`, so the operator's operands run on the named
nodes only. `restrict_to_gpu_nodes: false` keeps the charts' own node selection.

### RuntimeClass resolution

| Choice | Device plugin mode | GPU Operator mode |
|---|---|---|
| `auto` | `nvidia` on k3s / RKE2, none on other runtimes | `nvidia` |
| `nvidia` | `nvidia` | `nvidia` |
| `none` | none — the node's default runtime is the NVIDIA runtime | none |

With `nvidia` the device plugin pods themselves run with that RuntimeClass too (they need the
NVIDIA runtime to see the GPUs on k3s / RKE2).

### Time-slicing

With `time_slicing_replicas` = N the device plugin offers every physical GPU N times
(`sharing.timeSlicing`, `renameByDefault: false`, so pods still request `nvidia.com/gpu`). N pods
then share one GPU's compute in turns and its memory as a common pool. Trade-offs:

- no memory isolation — a pod can use the whole GPU memory and make the others fail;
- no fault isolation — a GPU error affects every pod on that GPU;
- each pod gets a share of the compute, not a fixed slice.

Time-slicing suits many small models and functions that use the GPU briefly (embeddings,
classification, small vision models). Give large models a whole GPU (replicas empty).
Changing the replicas re-applies the chart (`PUT /api/knative-regions/{id}/gpu/settings`).

### Uninstall

**Remove GPU support** uninstalls the Helm release RunR installed and the RuntimeClass it
created, and sets the region back to mode `none`. Running GPU pods keep the GPUs they hold until
they stop; new GPU pods stay pending. GPU support in mode `external` belongs to the cluster
administrator and is not removed.

## GPU containers (CaaS)

A CaaS project asks for GPUs in its config:

```json
{
  "name": "gpu-check",
  "service_type": "caas",
  "knative_region_id": "01J9ZK8E5V6G2Y3N4P5Q6R7S8T",
  "config": {
    "image": "registry.example.com/acme/gpu-caas:1",
    "ports": [8080],
    "gpu": {"count": 1, "type": "NVIDIA-L4", "memory_min": 16000}
  }
}
```

| Field | Required | Meaning |
|---|---|---|
| `gpu.count` | yes | GPUs per instance, 1–8 (`0` removes the GPU on an update) |
| `gpu.type` | | a GPU product of the region (`nvidia.com/gpu.product`); the project runs only on nodes with that GPU |
| `gpu.memory_min` | | minimum GPU memory in MiB (`nvidia.com/gpu.memory`) |

The console's project form offers the products of the region's GPU nodes and their memory.

What the Knative Service revision gets (`GpuScheduling`):

| Pod spec / annotation | Value |
|---|---|
| `containers[0].resources.limits["nvidia.com/gpu"]` | `gpu.count` |
| `nodeSelector["nvidia.com/gpu.product"]` | `gpu.type` (merged with the dedicated-worker selector) |
| `affinity.nodeAffinity` (required) | `nvidia.com/gpu.memory` `Gt` `memory_min − 1` |
| `tolerations` | `nvidia.com/gpu` Exists NoSchedule, `sku=gpu` NoSchedule (plus the dedicated-worker toleration) |
| `runtimeClassName` | see [Supported sandbox combinations](#supported-sandbox-combinations) |
| env `NVIDIA_DRIVER_CAPABILITIES` | `compute,utility` unless the container sets it |
| `serving.knative.dev/progress-deadline` | `1800s` (`ai.gpu.progressDeadline`): CUDA images and model weights are large; the first start may take longer than Knative's default |
| `autoscaling.knative.dev/scale-to-zero-pod-retention-period` | `5m` (`ai.gpu.scaleToZeroRetention`): an idle GPU pod stays warm for the next request, then scales to zero |

A new GPU project gets a default timeout of 300 s (`ai.gpu.defaultTimeout`) instead of 30 s,
at most the region's request timeout (Administration → Platform → Knative Regions → (region) → General).
Scale to zero stays available for GPU projects. The first request after scale to zero waits for
the node to start the container — pulling a CUDA image the first time on a node and loading
weights into GPU memory. For latency-sensitive services, set **min scale 1** to keep one
instance warm; that instance holds its GPU (and reserves it in the quota) all the time.

The GPU CaaS example [`examples/ai/gpu-caas`](../examples/ai/gpu-caas/) reports the GPU the
container sees (`"cuda": true`, the device name) and runs a small CUDA kernel on it, checked
against the CPU — a first test of a new GPU node, in an image of about 43 MB.

## GPU runtime versions (XaaS / FaaS)

Code projects get GPUs through their runtime version:

- the built-in **PyTorch** and **TensorFlow** runtimes have `-gpu` versions (`2.14-gpu`,
  `2.21-gpu`: CUDA 12 images, `gpu_required` in the catalog;
  [ML runtimes](runtimes.md#ml-runtimes));
- a runtime manifest can declare `resources.gpu: {count, resource}` ([runtimes.md](runtimes.md#resources)).

A project on such a version is a GPU project: it asks for the runtime's GPU count (1) unless
`config.gpu` sets another count, type or memory, and it is scheduled exactly like a GPU CaaS
project (limits, node selector, affinity, tolerations, RuntimeClass, annotations). `config.gpu`
on a runtime version without a GPU is refused with a hint to pick a GPU version.

```json
{"name": "torch-infer", "service_type": "faas", "runtime": "pytorch", "runtime_version": "2.14-gpu",
 "knative_region_id": "01J9ZK8E5V6G2Y3N4P5Q6R7S8T", "config": {"handler": "main.handler"}}
```

## Supported sandbox combinations

GPU pods run under the RuntimeClass the region maps the project type to (Administration → Platform → Knative Regions → (region) →
Isolation). Model services run under the region's CaaS mapping.

| Sandbox of the project type | GPU workloads | RuntimeClass of the pod |
|---|---|---|
| runc (no sandbox) | supported | `nvidia` where the region's GPU support uses it, else none |
| gVisor with nvproxy | supported when runsc on the GPU nodes runs with nvproxy and the region's **gVisor nvproxy** setting is on | `gvisor` |
| gVisor without nvproxy | not offered (no GPU access inside the sandbox) | — |
| Kata Containers (any hypervisor) | not offered (RunR does not set up GPU passthrough into Kata VMs) | — |
| a runtime's own RuntimeClass (platform runtimes, e.g. a WebAssembly shim) | as that runtime class provides | the runtime's |

gVisor's nvproxy is a runsc setting on the nodes (`nvproxy = "true"` in the runsc
configuration of the GPU nodes); turn on **gVisor nvproxy** in the region's GPU settings once
it is in place (`PUT /api/knative-regions/{id}/gpu/settings {"gvisor_nvproxy": true}`).

The combination is checked when a project is created or changed, at deploy and when a model
service is created or deployed: a GPU project on a region that maps its type to Kata (or to
gVisor without nvproxy) is refused with a message that names the type and what to map it to.
Map GPU project types to runc or gVisor (nvproxy) on GPU regions.

Each check also covers the region's GPUs: no GPU nodes (or NVIDIA hardware without GPU
support), an unknown `type` (the message lists the region's products), a `memory_min` no GPU
has, or more GPUs per instance than the largest node offers.

## Quotas and billing

### GPU quota

In the multi-tenant edition every tenant has a **GPU quota per region**
(`tenant_regions.gpu_quota`) next to its CPU and memory quota:

| `gpu_quota` | Meaning |
|---|---|
| `null` (default) | no GPU limit |
| `0` | no GPUs on this region |
| N | at most N GPUs reserved |

A workload reserves **`count × max(1, min_scale)`** GPUs from the moment it exists — like CPU and
memory, the reservation is claimed when a project is created, not when it runs. Projects count
with their `config.gpu` (or their GPU runtime version), model services with their `gpu.count`
while they are not stopped. Admission:

| When | Checked |
|---|---|
| project create | the requested GPUs fit the remaining quota |
| project update | when `config.gpu`, `min_scale` or the region changes (the project's own reservation excluded) |
| deploy | the project's GPUs fit (a lowered quota applies at the next deploy) |
| model service create / start / change of a running one | GPUs, plus CPU / memory against the organization quota: an always-on service in full, a scale-to-zero one with one pod against quota × the region's overcommit factor ([Organization quotas](architecture.md#region-capacity-and-admission)) |

Region capacity (every edition): the region's CPU and memory (`total_cpu_m` / `total_memory_mb`)
are shared by the projects, the sandboxes **and the model services that are not stopped** —
project admission (create, update, deploy), model service admission (create with deploy, start,
change of a running service; one that does not grow always passes), the region's used figures and
the capacity alerts count them all. A model service that keeps pods running (`min_scale` ≥ 1 or
`scale_to_zero: false`) holds the always-on budget; a scale-to-zero one counts one pod against
the FaaS overcommit budget ([Region capacity and admission](architecture.md#region-capacity-and-admission)).
Its Kata pod overhead follows the RuntimeClass it was deployed with. Stopped model services
reserve nothing.

Platform administrators are exempt, as with CPU and memory quotas; regions in mode *infinite*
have no quota. Platform administrators set the quota in Tenants → (tenant) → Regions or with

```bash
curl -sS -X PUT "$API/tenants/$TENANT/regions/$REGION/gpu-quota" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"gpu_quota": 4}'
```

`GET /api/tenants/{tenant}/regions` lists `gpu_quota` per region; members see their quota and
use with `GET /api/models/gpu-quota?knative_region_id=<id>` → `{"quota": 4, "used": 1, "remaining": 3}`.

### Billing

Metering records the GPU time of every workload (GPU-unit milliseconds, GPUs × ms); with the
[Billing module](billing.md) on, it is billed at the region's `gpu_hour` price (pay as you go), or a
fixed project requests GPUs with its other resources at the region's `gpu_month` price
([Fixed resources](billing.md#fixed-resources)):

| Workload | GPU-unit milliseconds per day |
|---|---|
| scale-to-zero project | Σ sessions `duration_ms × max(1, pod_count)` × GPUs per pod |
| project with custom scale (always on) | `min_scale × GPUs × 86 400 000` |
| model service | metered: every minute `models:meter` adds the time each running pod ran since the previous sample (at most two intervals) × its GPUs |

`usage_records` carry `gpu_count`, `total_gpu_unit_ms` and `source` (`project` or `model`);
GPU-hours are `total_gpu_unit_ms / 3 600 000`. A project's GPU time is on the project's bill; a
model service's on the account bill of the user who created it
([Account bills](billing.md#account-bills)).

## Sizing guidance

Guidance, derived from the model size — the weights an engine loads, one format of the
repository ([how RunR counts them](huggingface.md#engines-per-task)); measure your own workload
before you fix sizes.

- **GPU memory**: the weights (file size of the safetensors / bin files in the precision you
  serve) plus headroom for activations and, for text generation, the KV cache. RunR's deploy
  dialog shows a hint of weights × 1.2 + 1 GiB for vLLM / TGI / diffusers and + 512 MiB for the
  other engines, and suggests `memory_min` accordingly. Long contexts and large batches need more
  KV cache.
- **Node memory**: engines load the weights through host memory; RunR's default for GPU engines
  is weights × 1.3 + 2 GiB (at least 4 GiB, at most 64 GiB). CPU engines get weights × 2.5 +
  768 MiB (TEI: × 2 + 512 MiB).
- **One GPU or several**: a model that does not fit one GPU's memory can be split across GPUs of
  one node (`gpu.count` > 1: vLLM tensor parallelism, TGI shards). A request never spans nodes.
- **Time-slicing** fits several small models on one GPU — keep the sum of their memory below the
  GPU's memory, since nothing enforces it.
- **CPU or GPU**: embeddings, classification, NER and small speech or vision models run on CPU;
  text generation above a few GB of weights and image generation want a GPU (RunR offers CPU
  text generation up to 3 GiB of weights).
- **CPU threads**: model services get `OMP_NUM_THREADS` / `MKL_NUM_THREADS` = the CPU limit
  rounded up, so PyTorch runs as many threads as the limit allows instead of one per node CPU
  (throttled most of the time). More CPU means more threads; set the variables in `env_vars` to
  choose otherwise.
- **Cold start**: size of the image and of the weights dominates. RunR pre-pulls model images
  onto the nodes when model storage is set up and when a service is created
  ([image pre-pull](huggingface.md#image-pre-pull)). Keep one instance warm (min scale 1) for
  interactive use, scale to zero for batch and occasional use.

## Permissions

| Ability | Grants |
|---|---|
| `regions.gpu.manage` | install / remove GPU support, GPU settings (time-slicing, RuntimeClass, gVisor nvproxy), the region's model volume settings |
| `models.view` | browse the Hub, list models, snapshots, model services and bindings, logs, playground |
| `models.deploy` | create, change, start, stop and delete model services; mirror and upload models; bind models to projects |
| `models.manage` | the organization's Hugging Face token; delete models and offline copies; imports from the model volume or an OCI artifact |

They are catalog permissions ([Roles and permissions](permissions.md#models)): Owner and Admin
hold all four, Developer `models.view` and `models.deploy`, Viewer `models.view`; custom roles
pick what they need. Binding a model service to a project also needs `projects.update` on that
project. The region routes carry `region.mutable` as well: in a multi-tenant install platform
regions are managed by the platform administrators, a tenant's own regions by its owners and
admins.

## API

All under `/api`, with a session or an API token. Region GPU endpoints:

| Method & path | Ability | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/gpu[?refresh=1]` | regions.view | GPU status (below); `refresh=1` reads the cluster first |
| `POST /knative-regions/{id}/gpu/install` | regions.gpu.manage | `{mode, time_slicing_replicas?, runtime_class?, operator_driver?, operator_toolkit?, restrict_to_gpu_nodes?, gpu_nodes?}` → 202 `{message, gpu}` |
| `POST /knative-regions/{id}/gpu/uninstall` | regions.gpu.manage | → 202 `{message, gpu}`; 422 when RunR did not install it |
| `PUT /knative-regions/{id}/gpu/settings` | regions.gpu.manage | `{time_slicing_replicas?, runtime_class?, gvisor_nvproxy?, restrict_to_gpu_nodes?, gpu_nodes?}` → 200, or 202 when the chart is re-applied |
| `GET /knative-regions/{id}/model-storage` | regions.view | model volume settings and status ([huggingface.md](huggingface.md#model-storage)) |
| `PUT /knative-regions/{id}/model-storage` | regions.gpu.manage | `{storage_class?, size_gi?, access_mode?}` |
| `PUT /tenants/{tenant}/regions/{region}/gpu-quota` | platform admin | `{gpu_quota: int\|null}` |
| `GET /models/gpu-quota?knative_region_id=` | models.view | `{quota, used, remaining}` |

GPU status:

```json
{
  "mode": "device-plugin",
  "status": "ready",
  "status_message": "[10:02:11] Node runtime: k3s (containerd://2.0.4-k3s2)\n[10:02:12] Creating RuntimeClass nvidia (handler nvidia)...\n…",
  "settings": {"time_slicing_replicas": 4, "runtime_class": "nvidia", "runtime_class_choice": "auto",
               "gvisor_nvproxy": false, "operator_driver": true, "operator_toolkit": true,
               "restrict_to_gpu_nodes": true, "gpu_nodes": null},
  "summary": {"gpu_nodes": 1, "total": 1, "allocatable": 4, "allocated": 1, "available": 3,
              "products": [{"product": "NVIDIA-L4", "memory_mb": 23034, "count": 1, "allocatable": 4, "nodes": 1}],
              "hardware_without_plugin": []},
  "nodes": [{"name": "gpu-1", "ready": true, "product": "NVIDIA-L4", "memory_mb": 23034, "count": 1,
             "capacity": 4, "allocatable": 4, "allocated": 1, "replicas": 4, "compute_capability": "8.9",
             "driver_version": "550.54.15", "cuda_version": "12.4", "has_device_plugin": true,
             "hardware_detected": true, "taints": []}],
  "allocation": {"total": 1, "by_node": {"gpu-1": 1}},
  "sandbox": {
    "xaas": {"runtime_class": null, "gpu": "ok", "message": null},
    "faas": {"runtime_class": "gvisor", "gpu": "unsupported", "message": "This region runs FAAS projects under gVisor without nvproxy, …"},
    "caas": {"runtime_class": null, "gpu": "ok", "message": null}
  },
  "detected_at": "2026-10-11T10:04:00+00:00"
}
```

`mode`: `none`, `device-plugin`, `operator`, `external`; `status`: `not_installed`, `installing`,
`ready`, `error`, `uninstalling`. Every region in `GET /knative-regions` carries a compact
`gpu: {mode, status, total, allocatable, available, products}` used by the project forms.

Projects: `config.gpu` in `POST /projects` and `PUT /projects/{id}`; `config.gpu` in the project
resource (`null` without GPUs).

## Helm values

```yaml
ai:
  huggingface:
    endpoint: https://huggingface.co   # Hub API (a mirror with the same API works)
    browse: true                       # false: the console does not call the Hub (air-gapped)
  images:
    hfServe: ghcr.io/brf-tech/runr/hf-serve:1.1.1
    hfServeCuda: ghcr.io/brf-tech/runr/hf-serve:1.1.1-cuda
    vllm: docker.io/vllm/vllm-openai:v0.6.6
    tgi: ghcr.io/huggingface/text-generation-inference:3.0.1
    teiCpu: ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4
    teiGpu: "ghcr.io/huggingface/text-embeddings-inference:{arch}1.9.4"
    oras: ghcr.io/oras-project/oras:v1.2.0
  gpu:
    devicePluginChartVersion: "0.17.0"
    operatorChartVersion: "v24.9.2"
    defaultTimeout: 300          # s, GPU projects and model services
    progressDeadline: 1800s      # revision start-up deadline
    scaleToZeroRetention: 5m     # idle GPU pods stay this long
  modelStorage:
    sizeGi: 100
    accessMode: ReadWriteMany
    storageClass: ""
```

They become the `AI_*` environment of the API (`api/config/ai.php`). For air-gapped
installations point the images at a registry the regions reach; the device plugin and GPU
Operator charts are fetched by the RunR API, their images by the nodes (`nvcr.io`).

## Testing

```bash
# API: unit and feature tests (GPU inventory, install manifests, GPU scheduling, quotas, usage,
# Hub proxy, engines, model service manifests, mirror / import Jobs, uploads, bindings)
cd api && php artisan test tests/Unit/Ai tests/Feature/Ai

# hf-serve (fake pipelines, no torch needed)
cd runtime/hf-serve && pip install -r requirements-test.txt && python -m pytest tests

# UI: component specs and the Playwright flows against a fake API
cd vue && npx vitest run src/components/region src/components/project src/components/models src/views src/api src/utils
cd vue && npx playwright test e2e/ai.spec.ts

# End to end on Kind + Knative: tiny model online and offline on CPU, NetworkPolicy, binding,
# optional TEI and GPU cases
KIND_LOCK=/tmp/runr-kind.lock runtime/ci.sh test-ai
RUNR_E2E_TEI=1 runtime/ci.sh test-ai          # + TEI CPU embeddings
RUNR_E2E_GPU=0 runtime/ci.sh test-ai          # never the GPU case
```

`test-ai` builds the hf-serve CPU image and loads it into Kind; the CPU cases download
`hf-internal-testing/tiny-random-bert` from the Hub, so the Kind nodes need internet access.
`KIND_LOCK` (default `/tmp/runr-kind.lock` for this suite) keeps one Kind cluster at a time on a
shared host. `KIND_ETCD_IN_MEMORY` defaults to `1` for this suite: etcd keeps its data on the Kind
control-plane's tmpfs, so the Kubernetes API answers in time while the region's Helm installs run
on a busy disk (`KIND_ETCD_IN_MEMORY=0` puts it on the disk). `kind load` writes its temporary
image archive to `RUNR_E2E_TMPDIR` (default `/var/tmp`, which is on disk where `/tmp` may be a
RAM-backed tmpfs; `$TMPDIR` when `/var/tmp` is not writable).

The GPU case runs when the host can give a Kind node a GPU, and is skipped with the reason
printed otherwise:

- `nvidia-smi -L` lists a GPU;
- the NVIDIA Container Toolkit: `nvidia-container-cli list` names the driver's files and the host
  has the NVIDIA device nodes (`/dev/nvidiactl`) — or, where it is configured that way, the
  NVIDIA runtime is Docker's default runtime (`docker info` → Default Runtime: nvidia) and
  `/etc/nvidia-container-runtime/config.toml` has `accept-nvidia-visible-devices-as-volume-mounts = true`.

The GPU case then builds the image of [`examples/ai/gpu-caas`](../examples/ai/gpu-caas/)
(Python's slim image and a CUDA kernel run through the driver API, about 43 MB compressed) and
checks the disk before the cluster is created. Under Docker's root it needs:

- `RUNR_E2E_GPU_RESERVE_GB` (default 12) for the images the Kind nodes pull for the region
  (registry, Knative, Redis, tracing, GPU support) and the rest of the run;
- 3 GiB for the Compose stack (the API images, their base images and build cache);
- per Kind node 1 GiB of its own (Kubernetes' images, copied into each node) plus each image
  the suite loads, counted from the built images' sizes (`docker save` archive and unpacked
  layers) for every node that gets it: hf-serve on all nodes, the GPU image on the GPU worker
  only;
- with the preload on (unless `KIND_PRELOAD=0`), 1.4 GiB of preloaded images per Kind node and
  on the host;
- `kind load`'s temporary archive, or that much in `RUNR_E2E_TMPDIR` when that is on another
  filesystem.

With the default Kind cluster (a control-plane, two workers and the GPU worker) and hf-serve
1.1.1 this comes to about 35 GiB; with `KIND_WORKERS=1 KIND_PRELOAD=0` (three nodes), about
25 GiB. When the run would not fit, `test-ai` does not start and prints what it needs and what
is free; `RUNR_E2E_GPU=0` runs the CPU cases without the GPU worker.

The suite never changes the host's Docker or containerd configuration. It adds a Kind worker with
the GPU: the driver's files that `nvidia-container-cli list` names (what the NVIDIA runtime puts
into a GPU container), mounted read-only at the same paths, while Kind's privileged nodes see the
host's `/dev/nvidia*` already; with the NVIDIA runtime as Docker's default, `/dev/null` mounted at
`/var/run/nvidia-container-devices/all` instead (NVIDIA's nvkind approach). The GPU nodes are the
Kind nodes where `nvidia-smi -L` lists a GPU — every Kind node sees the device nodes, only that
worker has the driver. The suite installs the NVIDIA Container Toolkit inside that Kind node
only, labels it `nvidia.com/gpu.present=true` and loads the GPU image into it (only there; the
host's copy is removed afterwards). It installs GPU support (device plugin) through the RunR API
for that node and deploys `examples/ai/gpu-caas` with `config.gpu: {count: 1}`, expecting the
container to see the GPU (`"cuda": true`), run the CUDA kernel on it (`"computed_on": "gpu"`) and
return the result the CPU computes (`"result_ok": true`). `bash runtime/test/ai_gpu_test.sh`
checks this setup without Docker or a GPU.
