"""Signatures — the vectors are shared with the engine (PHP) and Node SDK tests."""

from runr_workflows import protocol

T = 1700000000
REQUEST = '{"type":"introspect","protocol":"v1","ts":1700000000}'


def test_known_vectors():
    assert protocol.digest(REQUEST, "rwk_test_key", T) == "838d3d8827a74b4ac397a08960c72044b191644d1fe0a873781bf6af995b239a"
    assert protocol.digest('{"type":"execute","workflow":"orders","steps":{}}', "rwk_other_key", T) == \
        "33a2197f7add68c82a3a73656e8025afed0a2f86401f31caa89bba5990b11ace"


def test_sign_and_parse():
    header = protocol.sign(REQUEST, ["rwk_test_key", "rwk_other_key"], T)
    assert header.startswith("t=1700000000,v1=838d3d88")
    t, v1 = protocol.parse_signature(header)
    assert t == T and len(v1) == 2


def test_verify_tolerance():
    header = protocol.sign(REQUEST, ["rwk_test_key"], T)
    assert protocol.verify(REQUEST, header, ["rwk_test_key"], 300, T + 300)
    assert not protocol.verify(REQUEST, header, ["rwk_test_key"], 300, T + 301)
    assert not protocol.verify(REQUEST, header, ["rwk_test_key"], 300, T - 301)


def test_verify_rejects_tampering_and_wrong_keys():
    header = protocol.sign(REQUEST, ["rwk_test_key"], T)
    assert not protocol.verify(REQUEST + " ", header, ["rwk_test_key"], 300, T)
    assert not protocol.verify(REQUEST, header, ["rwk_wrong"], 300, T)
    assert not protocol.verify(REQUEST, header, [None, ""], 300, T)


def test_rotation():
    header = protocol.sign(REQUEST, ["rwk_new", "rwk_previous"], T)
    assert protocol.verify(REQUEST, header, ["rwk_previous"], 300, T)
    single = protocol.sign(REQUEST, ["rwk_new"], T)
    assert protocol.verify(REQUEST, single, ["rwk_previous", "rwk_new"], 300, T)


def test_malformed_headers():
    for header in ["", "garbage", "t=abc,v1=00", "t=1700000000", "v1=" + "0" * 64]:
        assert not protocol.verify(REQUEST, header, ["rwk_test_key"], 300, T)


def test_encode_is_compact():
    assert protocol.encode({"a": [1, {}], "b": "é"}) == '{"a":[1,{}],"b":"é"}'
