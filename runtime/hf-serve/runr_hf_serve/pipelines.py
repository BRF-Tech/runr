"""Pipelines transformers 5 no longer ships, with the call and output shapes of the 4.x ones.

- ``summarization``, ``translation``, ``text2text-generation``: an ``AutoModelForSeq2SeqLM``
  with ``generate()``. The model's ``task_specific_params`` apply as before (T5's
  ``summarize: `` prefix and generation defaults; ``translation`` uses the
  ``translation_<src>_to_<tgt>`` entry of ``src_lang`` / ``tgt_lang``, else the first one the model
  defines). Multilingual tokenizers (NLLB, M2M100, mBART) take ``src_lang`` and force the
  ``tgt_lang`` token first. Output: ``[{"summary_text" | "translation_text" | "generated_text": …}]``.
- ``question-answering``: an ``AutoModelForQuestionAnswering``; the context is split into
  overlapping windows and the best span of at most ``max_answer_len`` tokens is answered as
  ``{"score", "start", "end", "answer"}`` (a list for ``top_k`` > 1).
"""

from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Sequence

from .tasks import InputError

SEQ2SEQ_OUTPUT_KEYS = {
    "summarization": "summary_text",
    "translation": "translation_text",
    "text2text-generation": "generated_text",
}
QUESTION_ANSWERING = "question-answering"
TASKS = set(SEQ2SEQ_OUTPUT_KEYS) | {QUESTION_ANSWERING}

# pipeline() arguments that are not model loading arguments
_TOKENIZER_ONLY = ("use_fast",)
_HUB = ("revision", "token", "trust_remote_code")


def load(task: str, source: str, device: str, kwargs: Optional[dict] = None):
    """Tokenizer and model of ``source`` for one of ``TASKS``, wrapped as a pipeline-like callable."""
    from transformers import AutoModelForQuestionAnswering, AutoModelForSeq2SeqLM, AutoTokenizer  # noqa: WPS433

    kwargs = dict(kwargs or {})
    hub = {key: kwargs.pop(key) for key in _HUB if key in kwargs}
    tokenizer_kwargs = {key: kwargs.pop(key) for key in _TOKENIZER_ONLY if key in kwargs}
    model_kwargs = dict(kwargs.pop("model_kwargs", None) or {})
    model_kwargs.update(kwargs)
    if "torch_dtype" in model_kwargs and "dtype" not in model_kwargs:
        model_kwargs["dtype"] = model_kwargs.pop("torch_dtype")

    tokenizer = AutoTokenizer.from_pretrained(source, **hub, **tokenizer_kwargs)
    model_class = AutoModelForQuestionAnswering if task == QUESTION_ANSWERING else AutoModelForSeq2SeqLM
    model = model_class.from_pretrained(source, **hub, **model_kwargs)
    if "device_map" not in model_kwargs:
        model = model.to(device)
    model.eval()

    if task == QUESTION_ANSWERING:
        return QuestionAnsweringPipe(model, tokenizer)
    return Seq2SeqPipe(model, tokenizer, task)


def _to_model(encoded, model):
    device = getattr(model, "device", None)
    if device is not None and hasattr(encoded, "to"):
        return encoded.to(device)
    return encoded


# ── Sequence to sequence ────────────────────────────────────────────────────


def lang_token_id(tokenizer, lang: str) -> Optional[int]:
    """Id of a language code token (NLLB ``deu_Latn``, M2M100 ``de``, mBART ``de_DE``), None when unknown."""
    getter = getattr(tokenizer, "get_lang_id", None)
    if callable(getter):
        try:
            return int(getter(lang))
        except Exception:
            pass
    try:
        token_id = tokenizer.convert_tokens_to_ids(lang)
    except Exception:
        return None
    if not isinstance(token_id, int) or token_id == getattr(tokenizer, "unk_token_id", None):
        return None
    return token_id


class Seq2SeqPipe:
    def __init__(self, model, tokenizer, task: str):
        if task not in SEQ2SEQ_OUTPUT_KEYS:
            raise ValueError(f"{task} is not a sequence-to-sequence task")
        self.model = model
        self.tokenizer = tokenizer
        self.task = task
        self.key = SEQ2SEQ_OUTPUT_KEYS[task]

    def task_params(self, src_lang: Optional[str] = None, tgt_lang: Optional[str] = None) -> dict:
        """The model's ``task_specific_params`` entry for the task (prefix and generation defaults)."""
        config = getattr(self.model, "config", None)
        params = getattr(config, "task_specific_params", None) or {}
        if not isinstance(params, dict):
            return {}
        if self.task != "translation":
            return dict(params.get(self.task) or {})
        if src_lang and tgt_lang and isinstance(params.get(f"translation_{src_lang}_to_{tgt_lang}"), dict):
            return dict(params[f"translation_{src_lang}_to_{tgt_lang}"])
        for key, value in params.items():
            if key.startswith("translation_") and isinstance(value, dict):
                return dict(value)
        return {}

    def __call__(self, inputs: Any, **kwargs: Any) -> list:
        single = isinstance(inputs, str)
        texts = [inputs] if single else inputs
        if not isinstance(texts, list) or not texts or not all(isinstance(t, str) for t in texts):
            raise InputError("inputs must be a string or a non-empty list of strings")

        src_lang = kwargs.pop("src_lang", None)
        tgt_lang = kwargs.pop("tgt_lang", None)
        truncation = kwargs.pop("truncation", True)
        for ignored in ("return_full_text", "return_text", "return_tensors", "clean_up_tokenization_spaces"):
            kwargs.pop(ignored, None)

        params = self.task_params(src_lang, tgt_lang)
        prefix = str(params.pop("prefix", "") or "")
        generate_kwargs = {**params, **kwargs}

        multilingual = hasattr(self.tokenizer, "src_lang")
        if src_lang and multilingual:
            self.tokenizer.src_lang = src_lang
        if tgt_lang and multilingual and "forced_bos_token_id" not in generate_kwargs:
            token_id = lang_token_id(self.tokenizer, tgt_lang)
            if token_id is not None:
                generate_kwargs["forced_bos_token_id"] = token_id

        encoded = self.tokenizer([prefix + t for t in texts], return_tensors="pt", padding=True, truncation=truncation)
        output_ids = self.model.generate(**_to_model(encoded, self.model), **generate_kwargs)
        decoded = self.tokenizer.batch_decode(output_ids, skip_special_tokens=True, clean_up_tokenization_spaces=True)

        per_input = max(1, int(generate_kwargs.get("num_return_sequences") or 1))
        groups = [[{self.key: text.strip()} for text in decoded[i:i + per_input]] for i in range(0, len(decoded), per_input)]
        if per_input == 1:
            return [group[0] for group in groups]
        return groups[0] if single else groups


# ── Extractive question answering ───────────────────────────────────────────


def _softmax(logits: Dict[int, float]) -> Dict[int, float]:
    top = max(logits.values())
    exps = {index: math.exp(value - top) for index, value in logits.items()}
    total = sum(exps.values())
    return {index: value / total for index, value in exps.items()}


def best_spans(
    context: str,
    start_logits: Sequence[Sequence[float]],
    end_logits: Sequence[Sequence[float]],
    sequence_ids: Sequence[Sequence[Optional[int]]],
    offsets: Sequence[Sequence[Sequence[int]]],
    top_k: int = 1,
    max_answer_len: int = 15,
    handle_impossible_answer: bool = False,
) -> List[dict]:
    """Best answer spans over every window: start and end probabilities are normalised over the
    window's context tokens (and its first token when no answer is allowed), a span's score is
    their product."""
    candidates: Dict[tuple, float] = {}
    null_score: Optional[float] = None

    for window, ids in enumerate(sequence_ids):
        context_tokens = [i for i, sid in enumerate(ids) if sid == 1]
        if not context_tokens:
            continue
        allowed = context_tokens + ([0] if handle_impossible_answer else [])
        starts = _softmax({i: float(start_logits[window][i]) for i in allowed})
        ends = _softmax({i: float(end_logits[window][i]) for i in allowed})
        if handle_impossible_answer:
            score = starts[0] * ends[0]
            null_score = score if null_score is None else min(null_score, score)

        in_context = set(context_tokens)
        for s in context_tokens:
            for e in range(s, s + max(1, max_answer_len)):
                if e not in in_context:
                    break
                start_char, end_char = int(offsets[window][s][0]), int(offsets[window][e][1])
                if end_char <= start_char:
                    continue
                score = starts[s] * ends[e]
                span = (start_char, end_char)
                if score > candidates.get(span, -1.0):
                    candidates[span] = score

    answers = [
        {"score": score, "start": start, "end": end, "answer": context[start:end]}
        for (start, end), score in candidates.items()
    ]
    if null_score is not None:
        answers.append({"score": null_score, "start": 0, "end": 0, "answer": ""})
    answers.sort(key=lambda a: a["score"], reverse=True)
    return answers[: max(1, int(top_k))]


class QuestionAnsweringPipe:
    def __init__(self, model, tokenizer):
        self.model = model
        self.tokenizer = tokenizer

    def __call__(self, question: Any = None, context: Any = None, top_k: int = 1, max_answer_len: int = 15,
                 max_seq_len: int = 384, doc_stride: int = 128, handle_impossible_answer: bool = False, **_ignored: Any):
        if not isinstance(question, str) or not question.strip() or not isinstance(context, str) or not context.strip():
            raise InputError("question and context must be non-empty strings")

        import torch  # noqa: WPS433

        max_seq_len = max(32, int(max_seq_len))
        encoded = self.tokenizer(
            question,
            context,
            truncation="only_second",
            max_length=max_seq_len,
            stride=max(0, min(int(doc_stride), max_seq_len // 2)),
            return_overflowing_tokens=True,
            return_offsets_mapping=True,
            padding=True,
            return_tensors="pt",
        )
        offsets = encoded["offset_mapping"].tolist()
        sequence_ids = [encoded.sequence_ids(i) for i in range(len(offsets))]
        # only what the model's forward takes (DistilBERT has no token_type_ids)
        names = set(getattr(self.tokenizer, "model_input_names", None) or ("input_ids", "attention_mask"))
        inputs = {key: value.to(self.model.device) for key, value in encoded.items() if key in names}

        with torch.inference_mode():
            output = self.model(**inputs)

        answers = best_spans(
            context,
            output.start_logits.float().cpu().tolist(),
            output.end_logits.float().cpu().tolist(),
            sequence_ids,
            offsets,
            top_k=top_k,
            max_answer_len=int(max_answer_len),
            handle_impossible_answer=bool(handle_impossible_answer),
        )
        return answers[0] if int(top_k) <= 1 else answers
