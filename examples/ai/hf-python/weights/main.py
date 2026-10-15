"""Load the weights of a bound model yourself (PyTorch runtime, FaaS project).

Bind the model with "Mount the model files" (Project → Models): RunR mounts the model's offline snapshot
read-only and sets RUNR_MODEL_EMBED_PATH to it. Nothing is downloaded at runtime, so this works
in an offline region too. requirements.txt next to this file adds transformers.

POST {"text": "..."} → mean-pooled sentence embedding computed in the function process
"""

import os

import torch
from transformers import AutoModel, AutoTokenizer

PATH = os.environ.get("RUNR_MODEL_EMBED_PATH")

# Loaded once per container, not per request
if PATH:
    TOKENIZER = AutoTokenizer.from_pretrained(PATH, local_files_only=True)
    MODEL = AutoModel.from_pretrained(PATH, local_files_only=True).eval()
else:
    TOKENIZER = MODEL = None


def handler(event, context):
    if MODEL is None:
        return {"statusCode": 501, "body": {"error": "bind a model as EMBED with 'Mount the model files' and redeploy"}}

    text = (event or {}).get("text") or "RunR runs models next to your functions."
    batch = TOKENIZER([text], padding=True, truncation=True, return_tensors="pt")
    with torch.no_grad():
        hidden = MODEL(**batch).last_hidden_state
    mask = batch["attention_mask"].unsqueeze(-1).float()
    vector = (hidden * mask).sum(dim=1) / mask.sum(dim=1)
    vector = torch.nn.functional.normalize(vector, dim=-1)[0]

    return {"statusCode": 200, "body": {"dims": vector.shape[0], "head": [round(v, 6) for v in vector[:4].tolist()]}}
