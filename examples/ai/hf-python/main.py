"""RunR Python function using two bound models (Project → Models):

- EMBED: an embedding model (TEI, or hf-serve with feature-extraction)
- CHAT:  a text-generation model (vLLM, TGI or hf-serve)

RunR injects RUNR_MODEL_<ALIAS>_URL (the model service inside the cluster) and
RUNR_MODEL_<ALIAS>_MODEL (the served model name). Only the standard library is used.

POST {"text": "..."}                       → embedding size and the first values
POST {"action": "chat", "text": "..."}     → the chat model's answer
"""

import json
import os
import urllib.error
import urllib.request


def _post(url: str, payload: dict, timeout: float = 120) -> dict:
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def _model(alias: str) -> tuple:
    url = os.environ.get(f"RUNR_MODEL_{alias}_URL")
    if not url:
        raise LookupError(f"no model bound as {alias}: bind one in the project's Models tab and redeploy")
    return url.rstrip("/"), os.environ.get(f"RUNR_MODEL_{alias}_MODEL", "")


def embed(texts):
    url, name = _model("EMBED")
    result = _post(f"{url}/v1/embeddings", {"model": name, "input": texts})
    return [item["embedding"] for item in result["data"]]


def chat(text: str) -> str:
    url, name = _model("CHAT")
    result = _post(f"{url}/v1/chat/completions", {
        "model": name,
        "messages": [
            {"role": "system", "content": "Answer in one short sentence."},
            {"role": "user", "content": text},
        ],
        "max_tokens": 64,
        "temperature": 0,
    })
    return result["choices"][0]["message"]["content"]


def handler(event, context):
    event = event if isinstance(event, dict) else {}
    text = event.get("text") or "RunR runs models next to your functions."

    try:
        if event.get("action") == "chat":
            return {"statusCode": 200, "body": {"answer": chat(text)}}

        vector = embed([text])[0]
        return {"statusCode": 200, "body": {"dims": len(vector), "head": vector[:4]}}
    except LookupError as exc:
        return {"statusCode": 501, "body": {"error": str(exc)}}
    except urllib.error.HTTPError as exc:
        return {"statusCode": 502, "body": {"error": f"model answered {exc.code}", "detail": exc.read().decode("utf-8", "replace")[:500]}}
    except urllib.error.URLError as exc:
        return {"statusCode": 503, "body": {"error": f"model not reachable: {exc.reason}"}}
