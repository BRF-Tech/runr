# GPU CaaS example

A container that shows whether it got a GPU and computes on it: it runs a small CUDA kernel
(SAXPY, `y = 3·x + y` over 1,048,576 floats) on the GPU and compares the result element by
element with the same computation on the CPU.

The image is Python's slim image plus [`app.py`](app.py): about 43 MB compressed, 134 MB
unpacked. It needs no CUDA toolkit, PyTorch or pip package. The standard library's `ctypes`
calls the CUDA driver API in `libcuda.so.1`, which the NVIDIA container runtime puts into every
container that gets a GPU. The driver compiles the kernel, embedded as PTX, for the GPU it finds,
so one image runs on every NVIDIA GPU from compute capability 5.0 (Maxwell) on.

## Build

```bash
docker build -t registry.example.com/acme/gpu-caas:1 examples/ai/gpu-caas
docker push registry.example.com/acme/gpu-caas:1
```

The base image is `ghcr.io/brf-tech/runr/reqs/python:3.12-slim`, RunR's mirror of
`docker.io/library/python:3.12-slim`; either one works. On a host with an NVIDIA GPU and the
NVIDIA Container Toolkit, try it before you push:

```bash
docker run --rm --gpus all -p 8080:8080 registry.example.com/acme/gpu-caas:1
curl -s localhost:8080/
```

## Run it on RunR

Create a CaaS project on a region with GPU support (Settings → Regions → (region) → GPU) and
ask for one GPU:

```bash
curl -sS -X POST "$API/projects" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "gpu-check",
  "service_type": "caas",
  "knative_region_id": "'"$REGION"'",
  "config": {
    "image": "registry.example.com/acme/gpu-caas:1",
    "ports": [8080],
    "gpu": {"count": 1}
  }
}'
```

Deploy it and call it:

```bash
curl -s https://gpu-check.<your function domain>/
```

```json
{"cuda": true, "device": "NVIDIA GeForce RTX 4080", "device_count": 1, "compute_capability": "8.9",
 "memory_mb": 16375, "driver_cuda_version": "13.3", "computed_on": "gpu",
 "kernel": "saxpy: y = 3*x + y over 1048576 floats", "checksum": 1612185594,
 "expected_checksum": 1612185594, "result_ok": true, "elapsed_ms": 103.784,
 "message": "a CUDA kernel ran on the GPU and its result matches the CPU's"}
```

| Field | Meaning |
|---|---|
| `cuda`, `device`, `device_count` | the GPU the CUDA driver shows the container (the first one is used) |
| `compute_capability`, `memory_mb` | of that GPU |
| `driver_cuda_version` | the newest CUDA version the node's driver supports |
| `computed_on` | `gpu` when the kernel ran on the GPU, else `cpu` |
| `checksum`, `expected_checksum`, `result_ok` | the sum of the result, the CPU's sum, and whether the result equals the CPU's element by element |

`"cuda": false` with `"message": "no GPU visible to the container ..."` means the pod runs on a
node without a GPU, or without one assigned to it: check the project's GPU settings and the
region's GPU nodes. `"cuda": true` with `"computed_on": "cpu"` means the driver sees the GPU but
the kernel did not run there; the message names the CUDA error. The same image runs without a
GPU, which makes it a handy first test of a new GPU node: deploy it once without `gpu` and once
with it. `GET /healthz` answers `ok`. See [docs/ai.md](../../../docs/ai.md) for GPU nodes,
scheduling and the supported sandbox combinations.
