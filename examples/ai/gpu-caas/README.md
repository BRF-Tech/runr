# GPU CaaS example

A container that reports what PyTorch sees and runs a small inference on it: a 256×256 matrix
product and a tiny network with fixed weights, so the results on CPU and GPU can be compared.

```bash
docker build -t registry.example.com/acme/gpu-caas:1 examples/ai/gpu-caas
docker push registry.example.com/acme/gpu-caas:1
```

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
# {"cuda": true, "device": "NVIDIA ...", "device_count": 1, "torch": "2.5.1+cu124", ...}
```

`"cuda": false` with `"message": "no GPU visible to the container ..."` means the pod runs on a
node without a GPU — check the project's GPU settings and the region's GPU nodes. The same image
runs without a GPU, which makes it a handy first test of a new GPU node: deploy it once without
`gpu` and once with it. See [docs/ai.md](../../../docs/ai.md) for GPU nodes, scheduling and the
supported sandbox combinations.
