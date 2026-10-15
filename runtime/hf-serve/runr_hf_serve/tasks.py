"""Task-specific input decoding, inference and JSON-safe outputs.

Inputs that are not text — images and audio — arrive as base64 strings or data URLs. URLs are
never fetched: the same request works in a region without internet access.
"""

from __future__ import annotations

import base64
import binascii
import io
import math
from typing import Any, Iterable, List, Optional, Sequence

IMAGE_TASKS = {
    "image-classification",
    "object-detection",
    "image-segmentation",
    "image-to-text",
    "zero-shot-image-classification",
    "depth-estimation",
}
AUDIO_TASKS = {"automatic-speech-recognition", "audio-classification"}
EMBEDDING_TASKS = {"feature-extraction", "sentence-similarity"}
GENERATION_TASKS = {"text-generation", "text2text-generation", "conversational"}
IMAGE_GENERATION_TASKS = {"text-to-image"}


class InputError(ValueError):
    """The request does not fit the model's task (answered with 400)."""


# ── Decoding ────────────────────────────────────────────────────────────────


def decode_base64(value: Any, what: str) -> bytes:
    if not isinstance(value, str) or value == "":
        raise InputError(f"{what} must be a base64 string or a data URL")
    if value.startswith(("http://", "https://")):
        raise InputError(f"URLs are not fetched; send the {what} as base64 or a data URL")
    if value.startswith("data:"):
        _, _, value = value.partition(",")
    try:
        return base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise InputError(f"{what} is not valid base64: {exc}") from exc


def decode_image(value: Any):
    data = decode_base64(value, "image")
    try:
        from PIL import Image  # noqa: WPS433

        image = Image.open(io.BytesIO(data))
        image.load()
    except Exception as exc:  # unreadable image
        raise InputError(f"the image cannot be read: {exc}") from exc
    return image.convert("RGB") if image.mode not in ("RGB", "L") else image


def decode_audio(value: Any):
    """WAV / FLAC / OGG through soundfile; anything else as bytes (decoded by ffmpeg)."""
    data = decode_base64(value, "audio")
    try:
        import soundfile  # noqa: WPS433

        samples, rate = soundfile.read(io.BytesIO(data), dtype="float32", always_2d=False)
        if getattr(samples, "ndim", 1) > 1:
            samples = samples.mean(axis=1)
        return {"raw": samples, "sampling_rate": rate}
    except Exception:
        return data


# ── Outputs ─────────────────────────────────────────────────────────────────


def image_to_base64(image) -> str:
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode("ascii")


def to_jsonable(value: Any) -> Any:
    """Pipeline output → JSON: numpy / torch values become numbers and lists, images base64 PNG."""
    if value is None or isinstance(value, (bool, int, str)):
        return value
    if isinstance(value, float):
        return value if math.isfinite(value) else None
    if isinstance(value, dict):
        return {str(k): to_jsonable(v) for k, v in value.items()}
    if isinstance(value, (list, tuple, set)):
        return [to_jsonable(v) for v in value]
    if hasattr(value, "save") and hasattr(value, "mode") and hasattr(value, "size"):
        return image_to_base64(value)
    if hasattr(value, "detach"):  # torch tensor
        value = value.detach().cpu()
    if hasattr(value, "tolist"):  # numpy array / scalar, torch tensor
        return to_jsonable(value.tolist())
    if hasattr(value, "item"):
        return to_jsonable(value.item())
    return str(value)


# ── Embeddings ──────────────────────────────────────────────────────────────


def _depth(value: Any) -> int:
    depth = 0
    while isinstance(value, list) and value:
        depth += 1
        value = value[0]
    return depth


def mean_pool(features: Any) -> List[float]:
    """Token features of one text ([tokens][hidden] or [1][tokens][hidden]) → one vector."""
    features = to_jsonable(features)
    while _depth(features) > 2:
        features = features[0]
    if _depth(features) == 1:
        return [float(x) for x in features]
    if _depth(features) != 2:
        raise InputError("the model returned no token features")
    width = len(features[0])
    sums = [0.0] * width
    for token in features:
        for i, x in enumerate(token):
            sums[i] += float(x)
    return [s / len(features) for s in sums]


def l2_normalize(vector: Sequence[float]) -> List[float]:
    norm = math.sqrt(sum(x * x for x in vector))
    return [x / norm for x in vector] if norm > 0 else list(vector)


def cosine(a: Sequence[float], b: Sequence[float]) -> float:
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(x * x for x in b))
    if na == 0 or nb == 0:
        return 0.0
    return sum(x * y for x, y in zip(a, b)) / (na * nb)


def embed(model, texts: Iterable[str], normalize: bool = True) -> List[List[float]]:
    vectors = []
    for text in texts:
        if not isinstance(text, str):
            raise InputError("embedding inputs must be strings")
        vector = mean_pool(model.pipe(text))
        vectors.append(l2_normalize(vector) if normalize else vector)
    return vectors


# ── Text generation ─────────────────────────────────────────────────────────


def chat_prompt(tokenizer, messages: Any) -> str:
    if not isinstance(messages, list) or not messages:
        raise InputError("messages must be a non-empty list of {role, content}")
    for message in messages:
        if not isinstance(message, dict) or not isinstance(message.get("content"), str):
            raise InputError("every message needs a role and a string content")
    template = getattr(tokenizer, "chat_template", None) if tokenizer is not None else None
    if template:
        return tokenizer.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
    lines = [f"{m.get('role', 'user')}: {m['content']}" for m in messages]
    return "\n".join(lines) + "\nassistant:"


def count_tokens(tokenizer, text: str) -> int:
    if tokenizer is not None:
        try:
            return len(tokenizer(text)["input_ids"])
        except Exception:
            pass
    return len(text.split())


def generation_kwargs(body: dict) -> dict:
    kwargs = {"max_new_tokens": int(body.get("max_tokens") or body.get("max_new_tokens") or 256)}
    temperature = body.get("temperature")
    if temperature is not None and float(temperature) > 0:
        kwargs["do_sample"] = True
        kwargs["temperature"] = float(temperature)
        if body.get("top_p") is not None:
            kwargs["top_p"] = float(body["top_p"])
    else:
        kwargs["do_sample"] = False
    return kwargs


def apply_stop(text: str, stop: Any) -> str:
    if not stop:
        return text
    stops = [stop] if isinstance(stop, str) else [s for s in stop if isinstance(s, str)]
    cut = len(text)
    for s in stops:
        if s and s in text:
            cut = min(cut, text.index(s))
    return text[:cut]


def generate(model, prompt: str, kwargs: dict) -> str:
    call = dict(kwargs)
    if model.task == "text-generation":
        call["return_full_text"] = False
    out = model.pipe(prompt, **call)
    if isinstance(out, list) and out:
        out = out[0]
    if isinstance(out, dict):
        return str(out.get("generated_text", out.get("summary_text", out.get("translation_text", ""))))
    return str(out)


# ── Images ──────────────────────────────────────────────────────────────────


def text_to_image(model, prompt: Any, parameters: Optional[dict] = None) -> list:
    if not isinstance(prompt, str) or not prompt.strip():
        raise InputError("the prompt must be a non-empty string")
    result = model.pipe(prompt, **(parameters or {}))
    images = getattr(result, "images", result)
    if not isinstance(images, list):
        images = [images]
    return [image_to_base64(image) for image in images]


def parse_size(size: Any) -> dict:
    if not size:
        return {}
    try:
        width, height = (int(x) for x in str(size).lower().split("x", 1))
    except ValueError as exc:
        raise InputError("size must look like 512x512") from exc
    return {"width": width, "height": height}


# ── /predict ────────────────────────────────────────────────────────────────


def predict(model, inputs: Any, parameters: Optional[dict] = None) -> Any:
    """The uniform route: task-specific call, JSON-safe output."""
    parameters = dict(parameters or {})
    task = model.task

    if inputs is None:
        raise InputError("inputs is required")

    if task in IMAGE_GENERATION_TASKS:
        return [{"image": image} for image in text_to_image(model, inputs, parameters)]

    if task == "sentence-similarity":
        if not isinstance(inputs, dict) or not isinstance(inputs.get("source_sentence"), str):
            raise InputError('inputs must be {"source_sentence": "...", "sentences": ["..."]}')
        sentences = inputs.get("sentences") or []
        if not isinstance(sentences, list) or not sentences:
            raise InputError("sentences must be a non-empty list of strings")
        source, *others = embed(model, [inputs["source_sentence"], *sentences])
        return [cosine(source, other) for other in others]

    if task == "feature-extraction":
        texts = [inputs] if isinstance(inputs, str) else inputs
        if not isinstance(texts, list):
            raise InputError("inputs must be a string or a list of strings")
        pooling = str(parameters.pop("pooling", "mean")).lower()
        if pooling == "none":
            return to_jsonable([model.pipe(text, **parameters) for text in texts])
        vectors = embed(model, texts, normalize=bool(parameters.pop("normalize", True)))
        return vectors[0] if isinstance(inputs, str) else vectors

    if task == "question-answering":
        if not isinstance(inputs, dict) or "question" not in inputs or "context" not in inputs:
            raise InputError('inputs must be {"question": "...", "context": "..."}')
        return to_jsonable(model.pipe(question=inputs["question"], context=inputs["context"], **parameters))

    if task == "zero-shot-classification":
        if "candidate_labels" not in parameters:
            raise InputError("parameters.candidate_labels is required")
        return to_jsonable(model.pipe(inputs, **parameters))

    if task in IMAGE_TASKS:
        images = [decode_image(v) for v in inputs] if isinstance(inputs, list) else decode_image(inputs)
        return to_jsonable(model.pipe(images, **parameters))

    if task in AUDIO_TASKS:
        audio = [decode_audio(v) for v in inputs] if isinstance(inputs, list) else decode_audio(inputs)
        return to_jsonable(model.pipe(audio, **parameters))

    if task == "text-generation" and isinstance(inputs, str):
        parameters.setdefault("return_full_text", False)

    return to_jsonable(model.pipe(inputs, **parameters))
