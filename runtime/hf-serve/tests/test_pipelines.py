import contextlib
import sys
import types

import pytest

from runr_hf_serve import pipelines
from runr_hf_serve.tasks import InputError


# ── Fakes of a tokenizer and a seq2seq model ────────────────────────────────


class Encoded(dict):
    """A BatchEncoding stand-in: a dict with to(device)."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.moved_to = None

    def to(self, device):
        self.moved_to = device
        return self


class Seq2SeqTokenizer:
    unk_token_id = 3

    def __init__(self, lang_codes=None, multilingual=False):
        self.calls = []
        self.lang_codes = lang_codes or {}
        if multilingual:
            self.src_lang = "eng_Latn"

    def __call__(self, texts, **kwargs):
        self.calls.append((texts, kwargs))
        return Encoded(input_ids=[[1]] * len(texts))

    def batch_decode(self, ids, **kwargs):
        return [f" out-{i} " for i in ids]

    def convert_tokens_to_ids(self, token):
        return self.lang_codes.get(token, self.unk_token_id)


class Seq2SeqModel:
    device = "cpu"

    def __init__(self, task_params=None):
        self.config = types.SimpleNamespace(task_specific_params=task_params)
        self.generated = []

    def generate(self, **kwargs):
        self.generated.append(kwargs)
        count = len(kwargs["input_ids"]) * int(kwargs.get("num_return_sequences") or 1)
        return list(range(count))


T5_PARAMS = {
    "summarization": {"prefix": "summarize: ", "num_beams": 4, "max_length": 200},
    "translation_en_to_de": {"prefix": "translate English to German: ", "max_length": 300},
    "translation_en_to_fr": {"prefix": "translate English to French: ", "max_length": 300},
}


def test_summarization_applies_the_model_prefix_and_generation_defaults():
    tokenizer, model = Seq2SeqTokenizer(), Seq2SeqModel(T5_PARAMS)
    pipe = pipelines.Seq2SeqPipe(model, tokenizer, "summarization")

    out = pipe("A long text.", max_new_tokens=20, return_full_text=False)

    assert out == [{"summary_text": "out-0"}]
    texts, kwargs = tokenizer.calls[0]
    assert texts == ["summarize: A long text."]
    assert kwargs == {"return_tensors": "pt", "padding": True, "truncation": True}
    generate = dict(model.generated[0])
    generate.pop("input_ids")
    assert generate == {"num_beams": 4, "max_length": 200, "max_new_tokens": 20}


def test_translation_picks_the_language_pair_or_the_first_one():
    tokenizer, model = Seq2SeqTokenizer(), Seq2SeqModel(T5_PARAMS)
    pipe = pipelines.Seq2SeqPipe(model, tokenizer, "translation")

    assert pipe("Hello", src_lang="en", tgt_lang="fr") == [{"translation_text": "out-0"}]
    assert tokenizer.calls[-1][0] == ["translate English to French: Hello"]

    pipe(["Hello", "Bye"])
    assert tokenizer.calls[-1][0] == ["translate English to German: Hello", "translate English to German: Bye"]


def test_multilingual_tokenizers_take_the_languages():
    tokenizer = Seq2SeqTokenizer(lang_codes={"deu_Latn": 42}, multilingual=True)
    model = Seq2SeqModel()
    pipe = pipelines.Seq2SeqPipe(model, tokenizer, "translation")

    pipe("Hello", src_lang="fra_Latn", tgt_lang="deu_Latn")
    assert tokenizer.src_lang == "fra_Latn"
    assert model.generated[-1]["forced_bos_token_id"] == 42

    pipe("Hello", tgt_lang="xx_Unknown")  # unknown code: no forced token
    assert "forced_bos_token_id" not in model.generated[-1]


def test_monolingual_tokenizers_ignore_language_codes():
    tokenizer = Seq2SeqTokenizer(lang_codes={"de": 7})
    model = Seq2SeqModel()
    pipelines.Seq2SeqPipe(model, tokenizer, "translation")("Hello", tgt_lang="de")
    assert "forced_bos_token_id" not in model.generated[-1]


def test_lang_token_id_prefers_get_lang_id():
    tokenizer = types.SimpleNamespace(get_lang_id=lambda lang: 128, convert_tokens_to_ids=lambda t: 1, unk_token_id=3)
    assert pipelines.lang_token_id(tokenizer, "de") == 128


def test_text2text_with_several_return_sequences():
    tokenizer, model = Seq2SeqTokenizer(), Seq2SeqModel()
    pipe = pipelines.Seq2SeqPipe(model, tokenizer, "text2text-generation")

    assert pipe("q", num_return_sequences=2) == [{"generated_text": "out-0"}, {"generated_text": "out-1"}]
    assert pipe(["a", "b"], num_return_sequences=2) == [
        [{"generated_text": "out-0"}, {"generated_text": "out-1"}],
        [{"generated_text": "out-2"}, {"generated_text": "out-3"}],
    ]
    assert tokenizer.calls[-1][0] == ["a", "b"]  # no task params: no prefix


@pytest.mark.parametrize("inputs", [None, 3, [], ["ok", 1]])
def test_seq2seq_inputs_must_be_text(inputs):
    with pytest.raises(InputError):
        pipelines.Seq2SeqPipe(Seq2SeqModel(), Seq2SeqTokenizer(), "summarization")(inputs)


def test_only_seq2seq_tasks():
    with pytest.raises(ValueError):
        pipelines.Seq2SeqPipe(Seq2SeqModel(), Seq2SeqTokenizer(), "fill-mask")


# ── Question answering ──────────────────────────────────────────────────────

CONTEXT = "Paris is the capital of France."
# tokens: [CLS] where is ? [SEP] paris is the capital of france . [SEP]
SEQUENCE_IDS = [None, 0, 0, 0, None, 1, 1, 1, 1, 1, 1, 1, None]
OFFSETS = [(0, 0), (0, 5), (6, 8), (8, 9), (0, 0), (0, 5), (6, 8), (9, 12), (13, 20), (21, 23), (24, 30), (30, 31), (0, 0)]


def logits(peak, size=13, high=8.0):
    return [high if i == peak else 0.0 for i in range(size)]


def test_best_span_maps_tokens_to_characters():
    answers = pipelines.best_spans(CONTEXT, [logits(10)], [logits(10)], [SEQUENCE_IDS], [OFFSETS])
    assert len(answers) == 1
    assert answers[0]["answer"] == "France"
    assert (answers[0]["start"], answers[0]["end"]) == (24, 30)
    assert 0.9 < answers[0]["score"] <= 1.0


def test_best_spans_respect_the_answer_length_and_question_tokens():
    # the start peaks on a question token: never an answer
    answers = pipelines.best_spans(CONTEXT, [logits(2)], [logits(10)], [SEQUENCE_IDS], [OFFSETS], top_k=3, max_answer_len=2)
    assert len(answers) == 3
    # the end peaks on "france": spans of at most two tokens ending there come first
    assert {a["answer"] for a in answers[:2]} == {"of France", "France"}
    assert "Paris is the" not in [a["answer"] for a in answers]
    assert [a["score"] for a in answers] == sorted((a["score"] for a in answers), reverse=True)


def test_windows_are_merged_and_the_best_wins():
    weak = [logits(5, high=1.0)]
    strong = [logits(10, high=9.0)]
    answers = pipelines.best_spans(CONTEXT, weak + strong, weak + strong, [SEQUENCE_IDS, SEQUENCE_IDS], [OFFSETS, OFFSETS], top_k=1)
    assert answers[0]["answer"] == "France"


def test_impossible_answers_when_allowed():
    answers = pipelines.best_spans(CONTEXT, [logits(0, high=20.0)], [logits(0, high=20.0)], [SEQUENCE_IDS], [OFFSETS],
                                   handle_impossible_answer=True)
    assert answers[0] == {"score": answers[0]["score"], "start": 0, "end": 0, "answer": ""}


class FakeTensor:
    def __init__(self, value):
        self.value = value

    def tolist(self):
        return self.value

    def float(self):
        return self

    def cpu(self):
        return self

    def to(self, _device):
        return self


class QaEncoding(dict):
    def sequence_ids(self, index):
        return SEQUENCE_IDS


class QaTokenizer:
    model_input_names = ["input_ids", "attention_mask"]

    def __init__(self):
        self.kwargs = None

    def __call__(self, question, context, **kwargs):
        self.kwargs = kwargs
        return QaEncoding(
            input_ids=FakeTensor([[0] * 13]),
            attention_mask=FakeTensor([[1] * 13]),
            token_type_ids=FakeTensor([[0] * 13]),
            offset_mapping=FakeTensor([OFFSETS]),
            overflow_to_sample_mapping=FakeTensor([0]),
        )


class QaModel:
    device = "cpu"

    def __init__(self):
        self.inputs = None

    def __call__(self, **inputs):
        self.inputs = inputs
        return types.SimpleNamespace(start_logits=FakeTensor([logits(5)]), end_logits=FakeTensor([logits(5)]))


@pytest.fixture
def fake_torch(monkeypatch):
    monkeypatch.setitem(sys.modules, "torch", types.SimpleNamespace(inference_mode=contextlib.nullcontext))


def test_question_answering_pipe(fake_torch):
    tokenizer, model = QaTokenizer(), QaModel()
    pipe = pipelines.QuestionAnsweringPipe(model, tokenizer)

    answer = pipe(question="Where is it?", context=CONTEXT)

    assert answer["answer"] == "Paris"
    assert (answer["start"], answer["end"]) == (0, 5)
    assert sorted(model.inputs) == ["attention_mask", "input_ids"]  # only the model's inputs
    assert tokenizer.kwargs["truncation"] == "only_second"
    assert tokenizer.kwargs["return_overflowing_tokens"] is True
    assert tokenizer.kwargs["stride"] == 128

    several = pipe(question="Where is it?", context=CONTEXT, top_k=2)
    assert isinstance(several, list) and len(several) == 2


@pytest.mark.parametrize("question, context", [("", CONTEXT), ("q", ""), (None, CONTEXT), ("q", 3)])
def test_question_answering_needs_both_texts(fake_torch, question, context):
    with pytest.raises(InputError):
        pipelines.QuestionAnsweringPipe(QaModel(), QaTokenizer())(question=question, context=context)


# ── Loading ─────────────────────────────────────────────────────────────────


class FakeAuto:
    def __init__(self, kind, calls):
        self.kind = kind
        self.calls = calls

    def from_pretrained(self, source, **kwargs):
        self.calls.append((self.kind, source, kwargs))
        if self.kind == "tokenizer":
            return Seq2SeqTokenizer()
        model = Seq2SeqModel()
        model.to = lambda device: (self.calls.append(("to", device)), model)[1]
        model.eval = lambda: self.calls.append(("eval",))
        return model


@pytest.fixture
def fake_auto(monkeypatch):
    calls = []
    module = types.ModuleType("transformers")
    module.AutoTokenizer = FakeAuto("tokenizer", calls)
    module.AutoModelForSeq2SeqLM = FakeAuto("seq2seq", calls)
    module.AutoModelForQuestionAnswering = FakeAuto("qa", calls)
    monkeypatch.setitem(sys.modules, "transformers", module)
    return calls


def test_load_seq2seq_passes_hub_and_model_arguments(fake_auto):
    pipe = pipelines.load("summarization", "org/t5", "cuda", {
        "revision": "abc", "token": "hf_x", "use_fast": False, "torch_dtype": "auto", "model_kwargs": {"low_cpu_mem_usage": True},
    })

    assert isinstance(pipe, pipelines.Seq2SeqPipe)
    assert fake_auto[0] == ("tokenizer", "org/t5", {"revision": "abc", "token": "hf_x", "use_fast": False})
    assert fake_auto[1] == ("seq2seq", "org/t5", {"revision": "abc", "token": "hf_x", "low_cpu_mem_usage": True, "dtype": "auto"})
    assert ("to", "cuda") in fake_auto and ("eval",) in fake_auto


def test_load_question_answering_with_a_device_map(fake_auto):
    pipe = pipelines.load("question-answering", "/models/model", "cpu", {"device_map": "auto"})

    assert isinstance(pipe, pipelines.QuestionAnsweringPipe)
    assert fake_auto[1][0] == "qa"
    assert not any(call[0] == "to" for call in fake_auto)  # accelerate places the weights
