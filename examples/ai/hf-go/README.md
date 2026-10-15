# Hugging Face models from a Go function

[`main.go`](main.go) is a RunR Go function (XaaS) that sends a text to the model bound as
`EMBED` and returns the embedding's size and first values. Bind an embedding model service
(for example `sentence-transformers/all-MiniLM-L6-v2` on TEI) in the project's **Models** tab,
paste the file into the editor and deploy.

```bash
curl -s -X POST https://<function>.<your function domain>/ -d '{"text": "hello"}'
# {"dims": 384, "head": [...]}
```

Any language works the same way: read `RUNR_MODEL_<ALIAS>_URL` and send HTTP requests to it —
see [`../curl.sh`](../curl.sh) for the request shapes of every engine and
[docs/huggingface.md](../../../docs/huggingface.md) for the full reference.
