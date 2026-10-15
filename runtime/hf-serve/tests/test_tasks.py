import base64
import math

import pytest

from runr_hf_serve import tasks


def test_mean_pool_accepts_every_feature_extraction_shape():
    assert tasks.mean_pool([[1.0, 3.0], [3.0, 5.0]]) == [2.0, 4.0]          # [tokens][hidden]
    assert tasks.mean_pool([[[1.0, 3.0], [3.0, 5.0]]]) == [2.0, 4.0]        # [1][tokens][hidden]
    assert tasks.mean_pool([0.5, 0.25]) == [0.5, 0.25]                       # already pooled
    with pytest.raises(tasks.InputError):
        tasks.mean_pool([])


def test_l2_normalize_and_cosine():
    assert tasks.l2_normalize([3.0, 4.0]) == [0.6, 0.8]
    assert tasks.l2_normalize([0.0, 0.0]) == [0.0, 0.0]
    assert math.isclose(tasks.cosine([1, 1], [2, 2]), 1.0)
    assert tasks.cosine([0, 0], [1, 0]) == 0.0


def test_to_jsonable():
    class Arr:
        def tolist(self):
            return [1, 2]

    assert tasks.to_jsonable({"a": (1, 2.5), "b": Arr(), "c": float("nan")}) == {"a": [1, 2.5], "b": [1, 2], "c": None}


def test_decode_base64_accepts_data_urls_and_refuses_urls():
    raw = base64.b64encode(b"abc").decode()
    assert tasks.decode_base64(raw, "x") == b"abc"
    assert tasks.decode_base64("data:application/octet-stream;base64," + raw, "x") == b"abc"
    with pytest.raises(tasks.InputError, match="URLs are not fetched"):
        tasks.decode_base64("http://example.com/a.wav", "audio")
    with pytest.raises(tasks.InputError):
        tasks.decode_base64(123, "audio")


def test_generation_kwargs():
    assert tasks.generation_kwargs({}) == {"max_new_tokens": 256, "do_sample": False}
    assert tasks.generation_kwargs({"max_tokens": 8, "temperature": 0}) == {"max_new_tokens": 8, "do_sample": False}
    assert tasks.generation_kwargs({"temperature": 0.7, "top_p": 0.9}) == {
        "max_new_tokens": 256, "do_sample": True, "temperature": 0.7, "top_p": 0.9,
    }


def test_apply_stop_and_parse_size():
    assert tasks.apply_stop("a\nb\nc", "\n") == "a"
    assert tasks.apply_stop("abc END def STOP", ["STOP", "END"]) == "abc "
    assert tasks.apply_stop("abc", None) == "abc"
    assert tasks.parse_size("768x512") == {"width": 768, "height": 512}
    assert tasks.parse_size(None) == {}
    with pytest.raises(tasks.InputError):
        tasks.parse_size("large")
