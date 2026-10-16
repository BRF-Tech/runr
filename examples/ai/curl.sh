#!/usr/bin/env sh
# Request shapes of RunR model services, per engine. Run inside the cluster — from a function,
# or a debug pod in the region's project namespace:
#
#   kubectl -n <project namespace> run curl --rm -it --image=ghcr.io/brf-tech/runr/reqs/curl:latest -- sh
#
# and set the URLs from the bound model's environment (Project → Models shows them):
#   EMBED=http://m-minilm.fn.svc.cluster.local   CHAT=http://m-qwen.fn.svc.cluster.local
# From outside the cluster use the console's playground or POST /api/model-services/{id}/invoke.
set -eu

EMBED="${RUNR_MODEL_EMBED_URL:-http://m-minilm.fn.svc.cluster.local}"
CHAT="${RUNR_MODEL_CHAT_URL:-http://m-qwen.fn.svc.cluster.local}"
EMBED_MODEL="${RUNR_MODEL_EMBED_MODEL:-all-MiniLM-L6-v2}"
CHAT_MODEL="${RUNR_MODEL_CHAT_MODEL:-qwen2-5-0-5b-instruct}"

echo "── Embeddings (TEI, hf-serve feature-extraction): OpenAI-compatible"
curl -sS "$EMBED/v1/embeddings" -H 'Content-Type: application/json' \
  -d "{\"model\": \"$EMBED_MODEL\", \"input\": [\"RunR runs models next to your functions.\"]}"
echo

echo "── Chat (vLLM, TGI, hf-serve text-generation): OpenAI-compatible"
curl -sS "$CHAT/v1/chat/completions" -H 'Content-Type: application/json' \
  -d "{\"model\": \"$CHAT_MODEL\", \"messages\": [{\"role\": \"user\", \"content\": \"Say hello.\"}], \"max_tokens\": 32}"
echo

echo "── TEI native route"
curl -sS "$EMBED/embed" -H 'Content-Type: application/json' -d '{"inputs": "hello"}' || true
echo

# hf-serve (engine "transformers"): one uniform route for every pipeline task.
#   text-classification:          {"inputs": "I love it"}
#   token-classification (NER):   {"inputs": "Ada Lovelace was born in London"}
#   summarization / translation:  {"inputs": "long text ...", "parameters": {"max_length": 60}}
#   zero-shot-classification:     {"inputs": "text", "parameters": {"candidate_labels": ["a", "b"]}}
#   question-answering:           {"inputs": {"question": "...", "context": "..."}}
#   image-classification:         {"inputs": "<base64 image or data URL>"}
#   automatic-speech-recognition: {"inputs": "<base64 wav/flac/mp3>"}
#   text-to-image (diffusers):    {"inputs": "a lighthouse at dawn", "parameters": {"num_inference_steps": 25}}
# Answer: {"model": "<served name>", "task": "<task>", "outputs": ...}
echo "── hf-serve uniform route"
CLASSIFIER="${RUNR_MODEL_SENTIMENT_URL:-http://m-sentiment.fn.svc.cluster.local}"
curl -sS "$CLASSIFIER/predict" -H 'Content-Type: application/json' -d '{"inputs": "I love it"}' || true
echo

echo "── Speech recognition with a local file (hf-serve, whisper)"
ASR="${RUNR_MODEL_ASR_URL:-http://m-whisper-tiny.fn.svc.cluster.local}"
if [ -f sample.wav ]; then
  printf '{"inputs": "%s"}' "$(base64 < sample.wav | tr -d '\n')" \
    | curl -sS "$ASR/predict" -H 'Content-Type: application/json' -d @-
  echo
fi
