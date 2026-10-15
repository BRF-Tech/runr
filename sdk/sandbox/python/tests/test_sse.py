"""Unit tests of the incremental SSE decoder."""

from runr_sandbox.sse import SSEDecoder, SSEEvent, iter_sse


def decode_all(*chunks):
    decoder = SSEDecoder()
    events = []
    for chunk in chunks:
        events.extend(decoder.feed(chunk))
    events.extend(decoder.flush())
    return events


def test_a_simple_event():
    events = decode_all(b'event: stdout\ndata: {"data":"hi"}\n\n')
    assert events == [SSEEvent(event="stdout", data='{"data":"hi"}')]
    assert events[0].json() == {"data": "hi"}


def test_an_event_without_a_name_is_a_message():
    assert decode_all("data: x\n\n")[0].event == "message"


def test_several_data_lines_are_joined_with_newlines():
    events = decode_all("data: one\ndata: two\ndata:three\n\n")
    assert events[0].data == "one\ntwo\nthree"


def test_comments_are_ignored():
    events = decode_all(": ping\n\n: another\nevent: exit\n: in between\ndata: {}\n\n")
    assert [e.event for e in events] == ["exit"]


def test_crlf_and_cr_line_endings():
    assert decode_all("event: a\r\ndata: 1\r\n\r\n")[0] == SSEEvent("a", "1")
    assert decode_all("event: b\rdata: 2\r\r")[0] == SSEEvent("b", "2")


def test_chunks_split_anywhere_byte_by_byte():
    stream = 'event: stdout\r\ndata: {"data":"héllo ✓"}\r\n\r\nevent: exit\ndata: {"exit_code":0}\n\n'.encode("utf-8")
    events = decode_all(*[stream[i:i + 1] for i in range(len(stream))])
    assert [e.event for e in events] == ["stdout", "exit"]
    assert events[0].json() == {"data": "héllo ✓"}


def test_a_cr_lf_pair_split_across_chunks_is_one_line_end():
    events = decode_all(b"data: a\r", b"\n\r", b"\n")
    assert events == [SSEEvent("message", "a")]


def test_multibyte_utf8_split_across_chunks():
    raw = "data: çğü\n\n".encode("utf-8")
    events = decode_all(raw[:7], raw[7:8], raw[8:])
    assert events[0].data == "çğü"


def test_only_one_leading_space_is_stripped():
    assert decode_all("data:   indented\n\n")[0].data == "  indented"


def test_a_field_without_colon_has_an_empty_value():
    events = decode_all("data\n\n")
    assert events == [SSEEvent("message", "")]


def test_a_block_without_data_dispatches_nothing():
    assert decode_all("event: lonely\n\n") == []


def test_id_and_retry():
    events = decode_all("id: 7\nretry: 1500\ndata: x\n\ndata: y\n\n")
    assert events[0].id == "7" and events[0].retry == 1500
    # The last event id carries over to later events
    assert events[1].id == "7" and events[1].retry is None


def test_an_invalid_retry_is_ignored():
    assert decode_all("retry: soon\ndata: x\n\n")[0].retry is None


def test_unterminated_last_event_is_dispatched_on_flush():
    events = decode_all('event: exit\ndata: {"exit_code":1}')
    assert events == [SSEEvent("exit", '{"exit_code":1}')]


def test_a_byte_order_mark_at_the_start_is_skipped():
    events = decode_all(b"\xef\xbb\xbfdata: x\n\n")
    assert events[0].data == "x"


def test_text_chunks_work_too():
    assert decode_all("data: a\n", "\n")[0].data == "a"


def test_iter_sse_over_an_iterable():
    events = list(iter_sse([b"data: 1\n\n", b"data: 2\n\n"]))
    assert [e.data for e in events] == ["1", "2"]


def test_empty_json_data_decodes_to_an_empty_dict():
    assert SSEEvent("x", "").json() == {}
