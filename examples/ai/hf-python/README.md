# Hugging Face models from a Python function

Two examples of a RunR Python function using models deployed from **Models** (one click from
the Hugging Face browser) and bound to the project in its **Models** tab.

| File | Runtime | What it does |
|---|---|---|
| [`main.py`](main.py) | Python (XaaS) | calls the model services over HTTP with the standard library: embeddings through `EMBED`, a chat answer through `CHAT` |
| [`weights/main.py`](weights/main.py) | PyTorch (FaaS, with [`requirements.txt`](weights/requirements.txt)) | loads the weights of the model bound as `EMBED` with **Mount the model files** from `RUNR_MODEL_EMBED_PATH` and computes the embedding in the function itself |

## Set up

1. **Models → Browse Hub**: deploy `sentence-transformers/all-MiniLM-L6-v2` (task
   `sentence-similarity`, engine TEI or transformers, CPU) and a chat model (for example
   `Qwen/Qwen2.5-0.5B-Instruct`; vLLM or TGI on a GPU, or transformers on CPU).
2. Project → **Models**: bind the embedding service as `EMBED` and the chat service as `CHAT`.
   For `weights/main.py` tick **Mount the model files** (the model needs an offline snapshot in
   the project's region: Models → Library → **Mirror to region**).
3. Paste `main.py` into the editor and deploy. Bindings are applied on deploy.

## Call it

```bash
curl -s -X POST https://<function>.<your function domain>/ -d '{"text": "hello"}'
# {"dims": 384, "head": [...]}

curl -s -X POST https://<function>.<your function domain>/ -d '{"action": "chat", "text": "What is RunR?"}'
# {"answer": "..."}
```

## Environment of a bound model

| Variable | Example |
|---|---|
| `RUNR_MODEL_EMBED_URL` | `http://m-minilm.runr-projects.svc.cluster.local` |
| `RUNR_MODEL_EMBED_MODEL` | `all-MiniLM-L6-v2` (the `model` field of OpenAI-style requests) |
| `RUNR_MODEL_EMBED_API` | `tei`, `openai`, `runr` or `custom` |
| `RUNR_MODEL_EMBED_TASK` | `sentence-similarity` (the pipeline tag) |
| `RUNR_MODEL_EMBED_OPENAI_BASE` | `http://m-minilm.runr-projects.svc.cluster.local/v1` |
| `RUNR_MODEL_EMBED_PATH` | `/models/embed` (with **Mount the model files** only) |
| `RUNR_MODELS` | `EMBED,CHAT` (every alias of the project) |

The calls stay inside the cluster; an offline region runs these examples unchanged. The full
reference is in [docs/huggingface.md](../../../docs/huggingface.md).
