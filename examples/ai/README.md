# AI examples

| Example | What it shows |
|---|---|
| [`gpu-caas/`](gpu-caas/) | a CaaS image on CUDA PyTorch: reports the GPU it sees and runs a small inference (`config.gpu: {count: 1}`) |
| [`hf-python/`](hf-python/) | a Python function using bound Hugging Face models over HTTP (embeddings, chat) and a PyTorch function loading mounted weights |
| [`hf-node/`](hf-node/) | the same from Node.js with `fetch` |
| [`hf-go/`](hf-go/) | embeddings from a Go function |
| [`curl.sh`](curl.sh) | the request shapes of every serving engine (OpenAI-compatible routes, TEI, hf-serve's uniform `/predict`) |

The examples bind models under the aliases `EMBED` (an embedding model) and `CHAT` (a
text-generation model). RunR sets `RUNR_MODEL_EMBED_URL`, `RUNR_MODEL_EMBED_MODEL`, … on the
function at deploy; the calls stay inside the cluster, so the examples run unchanged in an
offline region.

Guides: [docs/ai.md](../../docs/ai.md) (GPU workers, GPU CaaS, quotas) and
[docs/huggingface.md](../../docs/huggingface.md) (one-click models, online / offline /
air-gapped, bindings).
