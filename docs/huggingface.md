# Hugging Face models

Pick a model on the Hugging Face Hub, press **Deploy**, and RunR runs it as a **model service**:
a cluster-local Knative Service with the right serving engine, on CPU or GPU, scaling to zero
when idle. Bind it to a project and every function — in any language — calls it over HTTP with
the URL RunR puts in its environment. A model service runs **online** (downloads from the Hub
into the region's model cache once) or **offline** (serves a copy kept in the region, with no
network access at all); regions without internet access get their models by upload or import.

GPU nodes, GPU scheduling, quotas and billing are in [ai.md](ai.md).

- [One-click flow](#one-click-flow)
- [Engines per task](#engines-per-task)
- [Advanced settings](#advanced-settings)
- [Online mode](#online-mode)
- [Offline mode](#offline-mode)
- [Model storage](#model-storage)
- [Air-gapped regions](#air-gapped-regions)
- [Gated and private models](#gated-and-private-models)
- [Binding models to functions](#binding-models-to-functions)
- [Playground and logs](#playground-and-logs)
- [hf-serve reference](#hf-serve-reference)
- [Usage metering](#usage-metering)
- [CLI](#cli)
- [API](#api)
- [Status reasons](#status-reasons)
- [Troubleshooting](#troubleshooting)

## One-click flow

1. **Models → Browse Hub**: search the Hub by name, filter by task (pipeline tag) and library, sort
   by downloads, likes, trending or last update. RunR's API calls the Hub for the console
   (`GET /api/models/hub/search`), with the organization's token when one is set.
2. **Model card**: the summary of the README, size of the weights an engine loads, parameter
   count, license, gated flag, tags, and the engines that serve the model with RunR's pick and
   size.
3. **Deploy**: choose the region, **online** or **offline**, CPU or GPU, the engine (**auto** =
   RunR's pick), resources and scaling; Deploy adds the model to the organization's library
   pinned to the commit its revision points to, creates the model service and deploys it.
4. **Services**: the service goes `deploying` → `ready` (offline: `waiting_snapshot` first while
   the region's copy is made). The detail shows the internal URL, status message, pods and logs;
   when Kubernetes cannot start the pods, the status carries the reason
   ([status reasons](#status-reasons)).
5. **Playground**: a form for the model's task — chat, embeddings, classification, NER, fill-mask,
   question answering, summarization, translation, speech recognition (audio file), image
   classification (image file), text-to-image — sent through the API to the service.
6. **Bind**: Project → **Models** → bind the service; on the project's next deploy its
   containers get `RUNR_MODEL_<ALIAS>_URL` and friends ([binding](#binding-models-to-functions)).

The same with the API:

```bash
curl -sS -X POST "$API/model-services" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "hub_id": "sentence-transformers/all-MiniLM-L6-v2",
  "knative_region_id": "01J9ZK8E5V6G2Y3N4P5Q6R7S8T",
  "mode": "online"
}'
```

## Engines per task

| Engine | Devices | API | Image (Helm `ai.images.*`) | Tasks |
|---|---|---|---|---|
| `vllm` | GPU | OpenAI-compatible (`/v1/chat/completions`, `/v1/completions`, `/v1/models`) | `docker.io/vllm/vllm-openai:v0.6.6` | text-generation |
| `tgi` | GPU | OpenAI-compatible Messages API, `/generate`, `/info` | `ghcr.io/huggingface/text-generation-inference:3.0.1` | text-generation |
| `tei` | CPU, GPU | `/embed`, OpenAI-compatible `/v1/embeddings`, `/rerank`, `/predict` | `…/text-embeddings-inference:cpu-1.9.4`; GPU `…:{arch}1.9.4` | feature-extraction, sentence-similarity |
| `transformers` | CPU, GPU | RunR hf-serve: `/predict` + OpenAI-compatible routes for its tasks | `ghcr.io/brf-tech/runr/hf-serve:1.1.1` (CPU), `…:1.1.1-cuda` (GPU) | text-classification, token-classification, summarization, translation, text2text-generation, fill-mask, question-answering, zero-shot-classification, automatic-speech-recognition, image-classification, object-detection, feature-extraction, sentence-similarity, text-generation |
| `diffusers` | GPU | RunR hf-serve: `/predict`, `/v1/images/generations` | `ghcr.io/brf-tech/runr/hf-serve:1.1.1-cuda` | text-to-image |
| `custom` | CPU, GPU | whatever the image serves | your image | any |

How **auto** picks (`EngineCatalog::recommended()`):

| Task | CPU | GPU |
|---|---|---|
| text-generation | `transformers` — offered up to 3 GiB of weights | `vllm` (TGI as the alternative) |
| feature-extraction, sentence-similarity | `tei` when the Hub tags the model `text-embeddings-inference` or it is a sentence-transformers model, else `transformers` | same |
| text-to-image | — | `diffusers` (diffusers models) |
| other transformers pipelines | `transformers` | `transformers` |
| anything else | `custom` (bring an image) | `custom` |

The device defaults to GPU for text generation when the region has GPUs and for GPU-only
engines, otherwise CPU. The TEI GPU image is chosen by the GPU's compute capability: 7.5
`turing-`, 8.0 (no prefix), 8.6 `86-`, 8.9 `89-`, 9.0 `hopper-`, Blackwell 10.0 `100-`, 12.0
`120-`, 12.1 `121-`, and `cuda-` (the image that picks its kernels when it starts) for any other
GPU. Default resources follow the model size ([sizing guidance](ai.md#sizing-guidance)); the
deploy dialog shows them and the warnings RunR found (`large_for_cpu`, `gpu_required`,
`unsupported_library`, `unknown_size`, `gated`, `gated_needs_token`).

The model size is the size of the weights an engine loads, not of every file in the
repository. A Hub repository often holds the same model in several formats (safetensors,
PyTorch `.bin`, TensorFlow, Flax, ONNX, OpenVINO); RunR counts one of them:

| Repository | Counted |
|---|---|
| transformers / sentence-transformers | the root's safetensors, else its PyTorch checkpoint (`.bin`, `.pt`), else TensorFlow / Flax; only the transformers file names (`model.safetensors`, `model-00001-of-00002.safetensors`) when they exist, so a second copy such as `consolidated.safetensors` is not counted; sentence-transformers module folders (`2_Dense/`) are |
| diffusers | one checkpoint per component folder (`unet/`, `vae/`, `text_encoder/` …), the plain file before a variant such as `.fp16` |
| ONNX only | `model.onnx` with its external data, else the largest `.onnx` |
| GGUF only | the largest `.gguf` |

Training state (`optimizer.pt`, `training_args.bin`) and OpenVINO files never count. A Hub
model added to the library before this rule is sized again the next time a one-click deploy
reuses it.

What each engine gets (online → Hub id and pinned commit, offline → `/models/model`):

| Engine | Arguments / environment |
|---|---|
| vllm | `--model <ref> --served-model-name <served name> --host 0.0.0.0 --port 8080 [--revision <sha>] [--tensor-parallel-size <GPUs>]` |
| tgi | `MODEL_ID=<ref>`, `REVISION`, `PORT=8080`, `HOSTNAME=0.0.0.0`, `NUM_SHARD=<GPUs>` (more than one GPU) |
| tei | `--model-id <ref> --served-model-name <served name> --hostname 0.0.0.0 --port 8080 [--revision <sha>]` |
| transformers, diffusers | `RUNR_MODEL_ID` / `RUNR_MODEL_REVISION` or `RUNR_MODEL_PATH`, `RUNR_TASK`, `RUNR_ENGINE`, `RUNR_SERVED_NAME`, `RUNR_DEVICE` (`cpu` / `cuda`), `PORT=8080` |
| custom | `RUNR_MODEL_ID` / `RUNR_MODEL_REVISION` or `RUNR_MODEL_PATH` |

Every engine container also gets the Hub client environment of its mode, a readiness probe on
the engine's health path (`/health`, hf-serve `/healthz`), port 8080, the requested CPU /
memory (request = limit) and:

- `OMP_NUM_THREADS` and `MKL_NUM_THREADS` = the CPU limit rounded up (`1500m` → 2). PyTorch,
  MKL and OpenMP otherwise start one thread per CPU of the node; under a smaller limit those
  threads spend most periods throttled. A value in the service's `env_vars` wins.
- `terminationMessagePolicy: FallbackToLogsOnError`: an engine that exits without a
  termination message leaves its last log lines, which the service status shows.

## Advanced settings

| Field | Default | Meaning |
|---|---|---|
| `engine` | `auto` | an engine of the table above |
| `device` | RunR's pick | `cpu` or `gpu` |
| `gpu` | `{count: 1}` on GPU | `{count, type, memory_min}` as for projects ([ai.md](ai.md#gpu-containers-caas)) |
| `cpu`, `memory_mb` | from the model size | request = limit |
| `min_scale`, `max_scale`, `scale_to_zero` | 0, 1, true | Knative autoscaling; min scale 1 keeps one instance warm |
| `timeout_seconds` | 300 | request timeout |
| `image` | the engine's | replaces the engine image (a mirror, a newer engine release) |
| `args` | | appended to the engine's arguments (`["--max-model-len", "4096"]`); the only arguments of a `custom` image |
| `env_vars` | | extra env vars; they win over RunR's (stored encrypted at rest, [security](security.md)) |
| `port` | 8080 | the port the image listens on (custom images) |
| `health_path` | the engine's | readiness probe path (custom images: none = TCP) |
| `served_name` | the model's name | the OpenAI `model` name (vLLM `--served-model-name`, hf-serve `RUNR_SERVED_NAME`) |

```json
{
  "ai_model_id": "01J9ZKA1B2C3D4E5F6G7H8J9K0",
  "knative_region_id": "01J9ZK8E5V6G2Y3N4P5Q6R7S8T",
  "mode": "online", "device": "gpu", "engine": "vllm",
  "gpu": {"count": 1, "memory_min": 16000},
  "args": ["--max-model-len", "8192", "--dtype", "bfloat16"],
  "min_scale": 1
}
```

## Online mode

"Open circuit": the engine downloads the model from the Hub when it starts and keeps it in the
region's [model volume](#model-storage), so a model is downloaded once per region and
organization, not at every start.

| What | Value |
|---|---|
| model volume sub-path | `hf/<tenant>` mounted at `/models/hf` (`default` on single-tenant installations) |
| env | `HF_HOME=/models/hf`, `HF_HUB_CACHE` / `HUGGINGFACE_HUB_CACHE=/models/hf/hub`, `HF_HUB_DISABLE_TELEMETRY=1`, `DO_NOT_TRACK=1` |
| token | `HF_TOKEN` and `HUGGING_FACE_HUB_TOKEN` from the Secret `runr-hf-token` (+ `-<tenant>`), key `HF_TOKEN`, optional |
| Hub mirror | `HF_ENDPOINT` when `ai.huggingface.endpoint` is not huggingface.co |
| revision | the commit the model was pinned to when it was added |
| init container | `models-init` (busybox) makes the cache folders writable for every engine's user (vLLM, TGI and TEI run as root, hf-serve as uid 10001) |

Online mode needs a model from the Hub (`source: hub`); uploaded and imported models run
offline.

hf-serve (the `transformers` and `diffusers` engines) starts a model it has loaded before
without a request to the Hub: after a load of the pinned commit it records the load next to the
cached repository (`/models/hf/hub/models--<org>--<name>/.runr-complete/`, per engine, task and
pipeline arguments), and a later start with the same settings loads the cached snapshot directory
directly. A start from zero then depends on the model volume only, not on the Hub's reachability
or latency. When the cached snapshot does not load (files removed from the volume), hf-serve loads
from the Hub as on the first start.

## Offline mode

"Closed circuit": the model service serves a copy of the model kept in the region and never
reaches the network.

1. **Snapshot**: the first offline service of a model in a region (or **Mirror to region** in the
   library, `POST /api/models/{id}/snapshots`) starts a mirror Job that runs
   `huggingface_hub.snapshot_download` of the pinned commit into
   `snapshots/<tenant>/<model>/<revision>` of the model volume, writes `.runr-snapshot.json`
   (files, sizes, resolved commit) and reports the size. Until it is ready the service shows
   `waiting_snapshot`; it deploys as soon as the snapshot is ready.
2. **Service**: the snapshot folder is mounted **read-only** at `/models/model`, the engine is
   pointed at that path, and `HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`,
   `HF_DATASETS_OFFLINE=1` are set.
3. **No egress**: a NetworkPolicy `<service>-egress` selects the service's pods
   (`runr.sh/model-service: <id>`) with policy type Egress and allows DNS only (UDP / TCP 53 to
   kube-dns). The pods receive requests as usual; they open no connection of their own. The
   region's CNI must enforce NetworkPolicies (Cilium, Calico, …).

The mirror Job (`model-mirror-<snapshot>`) uses the hf-serve image, the organization's token for
gated / private models, the region's Hub endpoint, a deadline of
`AI_MIRROR_DEADLINE_SECONDS` (7200 s) and is removed a day after it finished. Its logs are in
the library (`GET /api/models/{id}/snapshots/{snapshot}/logs`).

## Model storage

Every region has one model volume: the PersistentVolumeClaim `runr-models` in the region's
project namespace, created with the first model service, mirror, upload or import.

| Sub-path | Holds |
|---|---|
| `hf/<tenant>/` | Hugging Face cache of online services |
| `snapshots/<tenant>/<model>/<revision>/` | offline copies (`local` for uploads and imports) |
| `imports/<tenant>/` | drop zone for [imports from the volume](#import-from-the-model-volume) |

Pods mount sub-paths only: an organization never sees another one's files.

Why a volume and not the registry (OCI artifacts): engines read model directories from a file
system, so a registry copy would have to be unpacked onto a volume before every start — twice
the disk and a slower cold start; multi-GB weights are the kind of blob registry retention
trims; and every Knative version mounts PVCs. OCI artifacts are still supported as the transport
into air-gapped regions: an [import](#import-from-an-oci-artifact) pulls one into the volume.

Settings (region → GPU → Model storage, `PUT /api/knative-regions/{id}/model-storage`):

| Setting | Default | Meaning |
|---|---|---|
| `storage_class` | the cluster default | storage class of the claim |
| `size_gi` | 100 (`ai.modelStorage.sizeGi`) | size; can grow later (where the storage class allows expansion), never shrink |
| `access_mode` | `ReadWriteMany` | RWX: pods on any node (NFS, CephFS, Longhorn RWX, …); `ReadWriteOnce` for single-node clusters (local-path) |

Storage class and access mode are fixed once the claim exists. `GET` answers
`{pvc_name, storage_class, size_gi, access_mode, status: absent|pending|bound|error, message, capacity}`.
While the claim is not bound, `message` says why: the newest Warning event of the claim (for
example `ProvisioningFailed` of a `ReadWriteMany` claim the local-path provisioner can not
provision, or a storage class that does not exist), else its newest event. A mirror or import
Job waiting for such a claim shows its snapshot as `pending` with the same message, and the
offline services waiting for the snapshot carry it too; once the claim is bound the snapshot is
`running` again.

### Image pre-pull

The hf-serve image is about half a gigabyte; pulling it was most of a new service's first answer.
RunR pulls model images onto the nodes before a service needs them, with the same mechanism as
the region's image pre-pull at registry setup (a DaemonSet that runs `crictl pull` on each node,
removed once every node has the images, or after 30 minutes):

| When | Images | Nodes |
|---|---|---|
| model storage is set up (`PUT …/model-storage`, or the region's first model volume) | the CPU hf-serve image (CPU services, mirror Jobs, upload receivers) | the nodes projects run on (the dedicated workers when the region has them) |
| a model service is created or deployed | its engine image and, online, the cache init image | CPU: as above; GPU: GPU nodes (of the requested GPU type) |

The DaemonSet runs in the region's registry namespace (internal registry), else in the AI
namespace (`ai.gpu.namespace`), and pulls the references the pods use, so a region registry
that mirrors the images serves the pre-pull too. Custom engine images are not pre-pulled.

## Air-gapped regions

A region without internet access gets its models in one of three ways; all of them end as an
offline snapshot, served exactly like a mirrored one. The console itself may have no Hub access
either: `ai.huggingface.browse: false` hides the Hub browser (its endpoints answer 503) and the
library offers upload and import.

### Upload through the console or the API

**Library → Upload** asks for a name, the task and the files (a folder of a model: config,
tokenizer, weights) and uploads them in chunks with progress and resume. Through the API:

```bash
API=https://runr.example.com/api
AUTH="Authorization: Bearer $TOKEN"
REGION=01J9ZK8E5V6G2Y3N4P5Q6R7S8T
DIR=./all-MiniLM-L6-v2            # the model files (config.json, tokenizer.json, model.safetensors, ...)

# 1. The model (source upload)
MODEL=$(curl -sS -X POST "$API/models" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"source": "upload", "name": "minilm", "task": "feature-extraction"}' | jq -r .id)

# 2. The upload: every file with its relative path, size and sha256
FILES=$(cd "$DIR" && find . -type f | sed 's#^\./##' | while read -r f; do
  jq -n --arg p "$f" --argjson s "$(stat -c%s "$f")" --arg h "$(sha256sum "$f" | cut -d' ' -f1)" '{path: $p, size: $s, sha256: $h}'
done | jq -s .)
UPLOAD=$(jq -n --arg r "$REGION" --argjson f "$FILES" '{knative_region_id: $r, files: $f}' \
  | curl -sS -X POST "$API/models/$MODEL/uploads" -H "$AUTH" -H 'Content-Type: application/json' -d @-)
ID=$(echo "$UPLOAD" | jq -r .id); CHUNK=$(echo "$UPLOAD" | jq -r .chunk_size)

# 3. Wait for the receiver in the region
until [ "$(curl -sS "$API/models/$MODEL/uploads/$ID" -H "$AUTH" | jq -r .status)" = ready ]; do sleep 3; done

# 4. The chunks: raw bytes, ?path=&offset=
cd "$DIR" && find . -type f | sed 's#^\./##' | while read -r f; do
  size=$(stat -c%s "$f"); i=0
  while [ $((i * CHUNK)) -lt "$size" ]; do
    dd if="$f" bs="$CHUNK" skip="$i" count=1 status=none \
      | curl -sS -f -X PUT "$API/models/$MODEL/uploads/$ID/chunk?path=$(jq -rn --arg p "$f" '$p|@uri')&offset=$((i * CHUNK))" \
          -H "$AUTH" -H 'Content-Type: application/octet-stream' --data-binary @- >/dev/null
    i=$((i + 1))
  done
done

# 5. Verify and finish: the snapshot is ready
curl -sS -X POST "$API/models/$MODEL/uploads/$ID/complete" -H "$AUTH" | jq .snapshot
```

How it works: starting the upload creates the snapshot and a short-lived receiver pod
(`model-recv-<upload>`, hf-serve `receive`) in the region with the snapshot folder mounted. Every
chunk (at most `chunk_size`, 8 MiB by default) goes through the RunR API and the Kubernetes API
server to the receiver, which writes it at its offset — chunks may come in any order and be
repeated, nothing is kept on the RunR API's disk, and the region needs no inbound route of its
own. **Complete** has the receiver check every file's size (and sha256 when given), write the
snapshot marker, and the receiver goes away. Uploads expire after `AI_UPLOAD_TTL_HOURS` (24 h,
`models:gc`); up to 500 files per upload.

### Import from the model volume

Copy the files into `imports/<tenant>/<folder>` of the region's model volume (`default` as the
tenant on single-tenant installations) — for example with a helper pod:

```yaml
apiVersion: v1
kind: Pod
metadata: {name: models-copy, namespace: runr-projects}
spec:
  containers:
    - name: copy
      image: ghcr.io/brf-tech/runr/reqs/busybox:latest
      command: ["sleep", "3600"]
      volumeMounts: [{name: models, mountPath: /imports, subPath: imports/default}]
  volumes:
    - name: models
      persistentVolumeClaim: {claimName: runr-models}
```

```bash
kubectl -n runr-projects cp ./all-MiniLM-L6-v2 models-copy:/imports/minilm
kubectl -n runr-projects delete pod models-copy
curl -sS -X POST "$API/models" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"source": "pvc", "name": "minilm", "task": "feature-extraction", "path": "minilm", "knative_region_id": "'"$REGION"'"}'
```

An import Job copies the folder into the model's snapshot. `path` must stay below the
organization's import folder.

### Import from an OCI artifact

Push the model files as an OCI artifact with [ORAS](https://oras.land) — into the region's
registry or any registry the region pulls from — and import it:

```bash
cd ./all-MiniLM-L6-v2
# every top-level entry (files and folders such as 1_Pooling/) becomes a layer; pull restores them
oras push registry.example.com/models/minilm:v1 $(ls -A)
# the region's in-cluster registry speaks plain HTTP: add --plain-http (e.g. through kubectl port-forward)

curl -sS -X POST "$API/models" -H "$AUTH" -H 'Content-Type: application/json' -d '{
  "source": "oci", "name": "minilm", "task": "feature-extraction",
  "reference": "registry.example.com/models/minilm:v1",
  "knative_region_id": "'"$REGION"'"
}'
```

The import Job pulls with `oras pull` into the snapshot (with the region registry's credentials
and `--plain-http` for its in-cluster registry; other registries anonymously). References carry
a tag or a `@sha256:` digest.

## Gated and private models

1. Accept the model's terms on huggingface.co with the account the token belongs to.
2. **Models → Settings → Hugging Face token** (`PUT /api/models/hub-token`, ability
   `models.manage`): a read token. `?validate=1` checks it with the Hub first.
3. RunR stores the token encrypted (`APP_KEY`), never returns it (only its last characters),
   and writes it into every region where the organization runs model services or keeps
   snapshots as the Secret `runr-hf-token` (+ `-<tenant>`). Online services and mirror Jobs read
   `HF_TOKEN` from it; changing or removing the token updates the Secrets.

The Hub browser uses the token too, so private models of the account appear in search and model
info.

## Binding models to functions

Project → **Models** → bind a model service of the project's region under an alias. On the
project's next deploy every container of the project gets, per binding `ALIAS`:

| Variable | Value |
|---|---|
| `RUNR_MODEL_<ALIAS>_URL` | `http://<service>.<namespace>.svc.cluster.local` |
| `RUNR_MODEL_<ALIAS>_MODEL` | the served model name (OpenAI `model` field) |
| `RUNR_MODEL_<ALIAS>_API` | `openai` (vLLM, TGI), `tei`, `runr` (hf-serve), `custom` |
| `RUNR_MODEL_<ALIAS>_TASK` | the pipeline tag |
| `RUNR_MODEL_<ALIAS>_OPENAI_BASE` | `<url>/v1` — engines with OpenAI-compatible routes (vLLM, TGI, TEI, hf-serve text generation / embeddings / images) |
| `RUNR_MODEL_<ALIAS>_PATH` | `/models/<alias>` — the model's offline snapshot, mounted read-only (**Mount the model files**, `mount_weights`; needs a ready snapshot in the project's region) |
| `RUNR_MODELS` | the aliases, comma-separated |

Aliases match `^[A-Z][A-Z0-9_]{0,31}$` (default: from the service name, `minilm` → `MINILM`).
Model services are cluster-local, so a project binds services of its own region. A variable the
project sets itself wins. Bindings apply on the next deploy; the Models tab shows the variables
and copyable snippets.

```bash
curl -sS -X POST "$API/projects/$PROJECT/models" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"ai_model_service_id": "01J9ZKC3…", "alias": "EMBED"}'
```

Python (standard library):

```python
import json, os, urllib.request

def handler(event, context):
    url, name = os.environ["RUNR_MODEL_EMBED_URL"], os.environ["RUNR_MODEL_EMBED_MODEL"]
    req = urllib.request.Request(f"{url}/v1/embeddings", method="POST",
                                 headers={"Content-Type": "application/json"},
                                 data=json.dumps({"model": name, "input": [event.get("text", "hello")]}).encode())
    with urllib.request.urlopen(req, timeout=120) as res:
        vector = json.load(res)["data"][0]["embedding"]
    return {"statusCode": 200, "body": {"dims": len(vector)}}
```

Node.js:

```javascript
exports.handler = async (event) => {
  const res = await fetch(`${process.env.RUNR_MODEL_CHAT_URL}/v1/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: process.env.RUNR_MODEL_CHAT_MODEL,
      messages: [{ role: 'user', content: event.text || 'Say hello.' }],
      max_tokens: 64,
    }),
  });
  const out = await res.json();
  return { statusCode: 200, body: { answer: out.choices[0].message.content } };
};
```

Go:

```go
payload, _ := json.Marshal(map[string]interface{}{
    "model": os.Getenv("RUNR_MODEL_EMBED_MODEL"),
    "input": []string{text},
})
resp, err := http.Post(os.Getenv("RUNR_MODEL_EMBED_URL")+"/v1/embeddings", "application/json", bytes.NewReader(payload))
```

curl (from a pod in the region):

```bash
curl -sS "$RUNR_MODEL_CHAT_URL/v1/chat/completions" -H 'Content-Type: application/json' \
  -d "{\"model\": \"$RUNR_MODEL_CHAT_MODEL\", \"messages\": [{\"role\": \"user\", \"content\": \"Say hello.\"}]}"
```

Loading the weights yourself (PyTorch runtime, **Mount the model files** on): nothing is downloaded at
runtime, so this works in an offline region.

```python
import os
from transformers import AutoModel, AutoTokenizer

PATH = os.environ["RUNR_MODEL_EMBED_PATH"]
tokenizer = AutoTokenizer.from_pretrained(PATH, local_files_only=True)
model = AutoModel.from_pretrained(PATH, local_files_only=True).eval()
```

Complete examples: [`examples/ai/`](../examples/ai/) — `hf-python/`, `hf-node/`, `hf-go/`,
`curl.sh` (aliases `EMBED` and `CHAT`).

## Playground and logs

The playground sends one request through the RunR API (`POST /api/model-services/{id}/invoke`)
and the Kubernetes API server to the Knative activator, which wakes a service scaled to zero.
Allowed paths per engine:

| Engine | Paths |
|---|---|
| vllm | `/v1/chat/completions`, `/v1/completions`, `/v1/embeddings`, `/v1/models`, `/health` |
| tgi | `/v1/chat/completions`, `/v1/completions`, `/generate`, `/info`, `/health` |
| tei | `/embed`, `/v1/embeddings`, `/rerank`, `/predict`, `/info`, `/health` |
| transformers, diffusers | `/predict`, `/v1/embeddings`, `/v1/chat/completions`, `/v1/completions`, `/v1/images/generations`, `/v1/models`, `/info`, `/healthz` |
| custom | any path |

```json
POST /api/model-services/{id}/invoke
{"path": "/v1/embeddings", "body": {"model": "minilm", "input": ["hello"]}}

→ {"status": 200, "duration_ms": …, "content_type": "application/json",
   "body": {"object": "list", "data": [{"object": "embedding", "index": 0, "embedding": [0.012, …]}], "model": "minilm"}}
```

Logs: the last lines of the engine container of up to five pods
(`GET /api/model-services/{id}/logs?tail=200` → `{pods: [{name, phase, ready, logs}]}`).

## hf-serve reference

`runtime/hf-serve` is RunR's server for the `transformers` and `diffusers` engines (PyTorch
2.14, transformers 5.20; the GPU image adds diffusers 0.41 on CUDA 12.6). It loads one pipeline at
start in the background — `/healthz` answers 503 while loading and 200 when ready — and serves
one request at a time per pod. When the model fails to load, hf-serve writes the reason
(`the model failed to load: <error>`) to the termination log and exits with status 1: the pod
restarts with back-off, so a passing Hub error heals on the next start, and the service shows
`CrashLoopBackOff` with that reason ([status reasons](#status-reasons)).

Threads: hf-serve runs as many PyTorch threads as its CPU limit allows — `OMP_NUM_THREADS` when
set (RunR sets it from the service's CPU), else the container's cgroup CPU quota rounded up,
at most the CPUs it sees — and exports `OMP_NUM_THREADS` / `MKL_NUM_THREADS` before PyTorch
starts. Without a limit PyTorch keeps its default.

Summarization, translation, text2text-generation and question answering, which transformers 5
no longer ships as pipelines, run in hf-serve itself with the same inputs and outputs (and the
model's `task_specific_params`, such as T5's prefixes).

| Variable | Meaning |
|---|---|
| `RUNR_MODEL_ID`, `RUNR_MODEL_REVISION` | Hub repository and commit (online) |
| `RUNR_MODEL_PATH` | local model directory; wins over the id (offline) |
| `RUNR_TASK` | pipeline tag (`text-to-image` by default for `diffusers`) |
| `RUNR_ENGINE` | `transformers` or `diffusers` |
| `RUNR_SERVED_NAME` | the OpenAI `model` name |
| `RUNR_DEVICE` | `auto`, `cpu`, `cuda` |
| `RUNR_PIPELINE_KWARGS` | JSON object passed to `pipeline()` / `from_pretrained()` (e.g. `{"dtype": "float16"}`; `torch_dtype`, the earlier name, is passed as `dtype`) |
| `PORT` | 8080 |
| `HF_TOKEN` | gated / private repositories |
| `OMP_NUM_THREADS` | PyTorch / OpenMP threads (default: the CPU limit) |
| `RUNR_TERMINATION_LOG` | where a load failure is written (`/dev/termination-log`) |

| Route | Body → answer |
|---|---|
| `POST /predict` | `{"inputs": …, "parameters": {…}}` → `{"model", "task", "outputs"}` |
| `POST /v1/embeddings` | OpenAI embeddings (feature-extraction, sentence-similarity; mean pooling, L2-normalized) |
| `POST /v1/chat/completions`, `POST /v1/completions` | OpenAI completions (text-generation, text2text-generation; `stream: false`) |
| `POST /v1/images/generations` | `{"prompt", "size": "512x512", "n", "num_inference_steps", "guidance_scale", "negative_prompt", "seed"}` → `{"created", "data": [{"b64_json"}]}` |
| `GET /v1/models`, `GET /info`, `GET /healthz` | model list, settings and status, health |

`/predict` inputs per task: text for classification, NER, summarization, translation,
fill-mask, text generation; `{"question", "context"}` for question answering;
`parameters.candidate_labels` for zero-shot classification;
`{"source_sentence", "sentences"}` for sentence similarity (cosine scores); base64 strings or
data URLs for images and audio — URLs are never fetched, so requests work offline;
`parameters.pooling: "none"` returns raw token features for feature extraction. Text-to-image
outputs `[{"image": "<base64 PNG>"}]`.

The image also runs the mirror Jobs (`python -m runr_hf_serve mirror --repo … --revision … --dest …`)
and the upload receiver (`receive --root …`).

## Usage metering

`models:meter` (every minute) adds, for every running pod of a model service, the time since the
previous sample (at most two minutes, so a missed run does not over-count; a pod started since
then counts from its start) to the service's day: replica, GPU-unit, CPU (millicore) and memory
(MiB) milliseconds of the engine container's requests. In the multi-tenant edition the usage
aggregation prices them with the region's cost config and records them as usage of source
`model` ([billing](ai.md#billing)).

## CLI

`runr models` covers the same flow from a terminal
([CLI reference](cli.md#models-and-model-services)):

```bash
runr models search minilm --task sentence-similarity        # the Hugging Face Hub
runr models deploy sentence-transformers/all-MiniLM-L6-v2 --region eu-1
runr models invoke all-minilm-l6-v2 --data '{"model": "all-minilm-l6-v2", "input": ["hello"]}'
runr models bind all-minilm-l6-v2 --alias EMBED -p orders-api
runr models upload ./all-MiniLM-L6-v2 --model minilm --task feature-extraction --region eu-1   # air-gapped
runr models hub-token set --token-stdin --validate < token.txt
runr models gpu-quota --region eu-1
```

Other subcommands: `ls`, `get`, `info`, `add`, `rm`, `engines`, `snapshot`, `snapshots`
(`rm`, `logs`), `services` (`ls`, `get`, `update`, `start`, `stop`, `logs`, `bindings`, `rm`),
`unbind`, `bindings`.

## API

All under `/api`. Abilities in [ai.md](ai.md#permissions).

| Method & path | Ability | Purpose |
|---|---|---|
| `GET /models/engines` | view | engines and, per task, engines and defaults |
| `GET /models/hub/search?search=&task=&library=&sort=downloads\|likes\|trending\|updated&limit=&cursor=` | view | `{data: [{id, author, task, library, downloads, likes, gated, private, last_modified, tags}], next_cursor}` |
| `GET /models/hub/info?id=&revision=&knative_region_id=` | view | model info with `engines`, `suggested`, `warnings` |
| `GET` / `PUT` / `DELETE /models/hub-token` | view / manage / manage | `{configured, hint, updated_at}`; `PUT {token, validate?}` |
| `GET /models` | view | `{data: AiModel[], abilities: {view, deploy, manage, hub}}` |
| `POST /models` | deploy (pvc / oci: manage) | `{source: hub, hub_id, revision?, name?}` or `{source: upload\|pvc\|oci, name, task, library?, path?, reference?, knative_region_id?}` → 201 |
| `GET` / `PATCH` / `DELETE /models/{model}` | view / deploy / manage | `PATCH {name?, task?, library?}`; delete → 409 while services use it |
| `GET /models/{model}/snapshots` | view | offline copies |
| `POST /models/{model}/snapshots` | deploy (pvc / oci models: manage) | `{knative_region_id, force?}` → 202 (mirror; again for a failed one) |
| `DELETE /models/{model}/snapshots/{snapshot}` | manage | → 204; 409 while running services use it |
| `GET /models/{model}/snapshots/{snapshot}/logs` | view | `{logs}` of the mirror / import Job |
| `POST /models/{model}/uploads` | deploy | `{knative_region_id, files: [{path, size, sha256?}]}` → 201 upload |
| `GET /models/{model}/uploads/{upload}` | view | upload status |
| `PUT /models/{model}/uploads/{upload}/chunk?path=&offset=` | deploy | raw body → `{path, uploaded_bytes}` |
| `POST /models/{model}/uploads/{upload}/complete` | deploy | `{upload, snapshot}`; 422 lists incomplete files |
| `DELETE /models/{model}/uploads/{upload}` | deploy | abort |
| `GET /model-services[?knative_region_id=]` | view | `{data: ModelService[], abilities}` |
| `POST /model-services` | deploy | `{ai_model_id \| hub_id+revision?, knative_region_id, name?, mode?, device?, engine?, gpu?, cpu?, memory_mb?, min_scale?, max_scale?, scale_to_zero?, timeout_seconds?, image?, args?, env_vars?, port?, health_path?, served_name?, deploy?}` → 201 |
| `GET` / `PATCH` / `DELETE /model-services/{id}` | view / deploy / deploy | a change of a running service redeploys it; delete → 409 while projects bind it |
| `POST /model-services/{id}/deploy`, `/stop` | deploy | start (quota-checked) / stop (the Knative Service goes, the settings stay) |
| `GET /model-services/{id}/logs?tail=` | view | `{pods}` |
| `POST /model-services/{id}/invoke` | view | `{path, method?, body}` → `{status, duration_ms, content_type, body}` (60 per minute) |
| `GET /model-services/{id}/bindings` | view | projects that use it |
| `GET /projects/{project}/models` | view | `{data: Binding[], available: [{id, name, engine, status}]}` |
| `POST /projects/{project}/models` | deploy | `{ai_model_service_id, alias?, mount_weights?}` → 201 |
| `PATCH` / `DELETE /projects/{project}/models/{binding}` | deploy | `{alias?, mount_weights?}` |

A model (`AiModel`):

```json
{
  "id": "01J9ZKA1B2C3D4E5F6G7H8J9K0", "name": "all-minilm-l6-v2", "source": "hub",
  "hub_id": "sentence-transformers/all-MiniLM-L6-v2", "revision": "main",
  "revision_sha": "8b3219a92973c328a8e22fadcfa821b5dc75636a",
  "task": "sentence-similarity", "library": "sentence-transformers", "license": "apache-2.0",
  "gated": false, "private": false, "size_bytes": 90868376, "parameters": 22713728,
  "card_summary": "# all-MiniLM-L6-v2\nThis is a sentence-transformers model: …",
  "tags": ["sentence-transformers", "pytorch", "text-embeddings-inference"], "source_ref": null,
  "created_at": "2026-10-11T09:00:00+00:00",
  "snapshots": [{"id": "01J9ZKB…", "ai_model_id": "01J9ZKA…", "knative_region_id": "01J9ZK8…", "region_name": "eu-1",
                 "source": "hub", "revision": "8b3219a92973c328a8e22fadcfa821b5dc75636a", "status": "ready",
                 "message": null, "size_bytes": 91226112, "files_count": 14,
                 "started_at": "2026-10-11T09:01:00+00:00", "completed_at": "2026-10-11T09:01:40+00:00"}],
  "services_count": 1
}
```

A model service:

```json
{
  "id": "01J9ZKC3D4E5F6G7H8J9K0A1B2", "name": "all-minilm-l6-v2", "service_name": "m-all-minilm-l6-v2",
  "ai_model_id": "01J9ZKA1B2C3D4E5F6G7H8J9K0",
  "model": {"id": "01J9ZKA…", "name": "all-minilm-l6-v2", "hub_id": "sentence-transformers/all-MiniLM-L6-v2",
            "task": "sentence-similarity", "library": "sentence-transformers", "size_bytes": 90868376, "source": "hub"},
  "knative_region_id": "01J9ZK8E5V6G2Y3N4P5Q6R7S8T", "region_name": "eu-1",
  "engine": "tei", "device": "cpu", "mode": "offline", "api": "tei", "gpu": null,
  "cpu": "1", "memory_mb": 1024, "min_scale": 0, "max_scale": 1, "scale_to_zero": true, "timeout_seconds": 300,
  "image": null, "args": null, "env_vars": null, "port": null, "health_path": null,
  "served_name": "all-minilm-l6-v2", "status": "ready", "status_message": null, "status_reason": null,
  "url": "http://m-all-minilm-l6-v2.runr-projects.svc.cluster.local",
  "openai_base": "http://m-all-minilm-l6-v2.runr-projects.svc.cluster.local/v1",
  "playground": {"kind": "embeddings", "path": "/v1/embeddings"},
  "cold_start": {"size_bytes": 90868376, "suggest_min_scale": false},
  "bindings_count": 1, "deployed_at": "2026-10-11T09:02:00+00:00", "created_at": "2026-10-11T09:00:00+00:00"
}
```

Statuses: `stopped`, `waiting_snapshot`, `deploying`, `ready`, `failed`. A binding:

```json
{
  "id": "01J9ZKD…", "project_id": "01J9ZKE…", "project": null, "ai_model_service_id": "01J9ZKC…",
  "alias": "EMBED", "mount_weights": false,
  "env": {
    "RUNR_MODEL_EMBED_URL": "http://m-all-minilm-l6-v2.runr-projects.svc.cluster.local",
    "RUNR_MODEL_EMBED_MODEL": "all-minilm-l6-v2",
    "RUNR_MODEL_EMBED_API": "tei",
    "RUNR_MODEL_EMBED_TASK": "sentence-similarity",
    "RUNR_MODEL_EMBED_OPENAI_BASE": "http://m-all-minilm-l6-v2.runr-projects.svc.cluster.local/v1"
  },
  "service": {"id": "01J9ZKC…", "name": "all-minilm-l6-v2", "…": "…"},
  "applies_on_next_deploy": true
}
```

An upload:

```json
{"id": "01J9ZKF…", "ai_model_id": "01J9ZKA…", "knative_region_id": "01J9ZK8…", "status": "ready",
 "chunk_size": 8388608, "files": [{"path": "model.safetensors", "size": 90868376, "uploaded_bytes": 16777216}],
 "snapshot_id": "01J9ZKB…", "message": null, "expires_at": "2026-10-12T09:00:00+00:00"}
```

Upload statuses: `preparing` (receiver starting), `ready`, `completing`, `completed`, `failed`,
`aborted`.

## Status reasons

While a service deploys, RunR reads its pods on every readiness check (every 15 s). When
Kubernetes cannot start them, `status_reason` names why and `status_message` gives the details;
the console shows the reason next to the status. Kubernetes keeps retrying, so the service stays
`deploying` until it becomes ready, Knative gives up on the revision or the start-up deadline
passes (`failed`, the reason kept). A reason that clears takes its message with it.

| `status_reason` | `status_message` | What to do |
|---|---|---|
| `CrashLoopBackOff` | restarts, last exit code and reason, and the engine's last output (its termination message or log tail) | read the output: a model the engine cannot load, a wrong engine for the model, a Hub error |
| `OOMKilled` | as above, with the memory limit | raise `memory_mb` |
| `ImagePullBackOff`, `ErrImagePull`, `InvalidImageName` | the image and the registry's answer | check the image reference (`ai.images.*`, the service's `image`) and that the region reaches its registry |
| `CreateContainerConfigError`, `CreateContainerError`, `RunContainerError` | Kubernetes' message | a missing Secret or ConfigMap, a broken image |
| `Unschedulable` | the scheduler's message (for example `Insufficient memory`) | free capacity, lower `memory_mb` / `cpu`, check GPU and dedicated-worker placement |

## Troubleshooting

| Status / message | Meaning | What to do |
|---|---|---|
| service `waiting_snapshot` | the offline copy of the model is being made in the region | wait; the library shows the mirror's status and logs |
| snapshot `failed` with a gated / not found hint | the Hub refused the mirror | accept the model's terms, set the token, mirror again |
| service `deploying` for long without a reason | image pull or model download / load | check the logs; large images and weights take a while on a node the first time (model storage set up ahead [pre-pulls](#image-pre-pull) hf-serve); the start-up deadline is 1800 s |
| service `deploying` with a reason | Kubernetes cannot start the pods | see [status reasons](#status-reasons) |
| service `failed`: "did not become ready within the start-up deadline" | the engine never answered its health check | logs: out of memory (raise `memory_mb`), GPU memory (raise `gpu.memory_min`, use a smaller model or more GPUs), wrong engine for the model |
| 422 "Region … has no GPU nodes" / "… without GPU support" | GPU requested where no node offers one | install GPU support in the region's GPU tab or deploy on CPU |
| 422 GPU quota exceeded | the organization's GPU quota on the region is used up | stop a GPU service, lower min scale, or ask the platform administrator |
| 422 "runs … under Kata Containers" / "gVisor without nvproxy" | the region's sandbox for CaaS does not run GPUs | map CaaS to runc or gVisor with nvproxy |
| 422 "Online mode downloads from the Hugging Face Hub" | an uploaded / imported model in online mode | use offline mode |
| 503 "This console does not browse the Hugging Face Hub" | `ai.huggingface.browse` is off | upload or import models |
| playground 502 "no ready revision" | the service is not ready | wait for `ready` |
| a function sees no `RUNR_MODEL_*` | bindings apply on the next deploy; services of other regions are not bound | redeploy the project; bind a service of the project's region |
| upload 409 "The upload is preparing" | the receiver pod is starting | poll the upload until `ready` |
| upload complete 422 | a file is incomplete or its sha256 differs | re-send the chunks of the listed files, complete again |
| offline service reaches nothing | by design: egress is denied except DNS | call other services from the function, not from the model |
