"""Incremental Server-Sent Events decoder (https://html.spec.whatwg.org/multipage/server-sent-events.html).

Feed it the response body in chunks of any size — bytes or text — and it returns the complete
events. Lines may end with ``\\r\\n``, ``\\n`` or ``\\r``; a chunk may end in the middle of a line, of a
``\\r\\n`` pair or of a multi-byte UTF-8 character. Comment lines (``: ping``) are ignored; several
``data:`` lines of one event are joined with ``\\n``.
"""

from __future__ import annotations

import codecs
import json
import re
from dataclasses import dataclass
from typing import Any, Iterable, Iterator, List, Optional, Union

__all__ = ["SSEEvent", "SSEDecoder", "iter_sse"]

_LINE_END = re.compile(r"\r\n|\r|\n")


@dataclass
class SSEEvent:
    """One dispatched event."""

    event: str = "message"
    data: str = ""
    id: Optional[str] = None
    retry: Optional[int] = None

    def json(self) -> Any:
        """The data decoded as JSON (``{}`` for an empty data field)."""
        return json.loads(self.data) if self.data else {}


class SSEDecoder:
    """Turns a stream of chunks into :class:`SSEEvent` objects."""

    def __init__(self) -> None:
        self._decoder = codecs.getincrementaldecoder("utf-8")(errors="replace")
        self._buffer = ""
        self._event: Optional[str] = None
        self._data: List[str] = []
        self._id: Optional[str] = None
        self._retry: Optional[int] = None
        self._last_id: Optional[str] = None
        self._first = True

    @property
    def last_event_id(self) -> Optional[str]:
        return self._last_id

    def feed(self, chunk: Union[bytes, bytearray, str]) -> List[SSEEvent]:
        """Add a chunk; returns the events it completed."""
        text = self._decoder.decode(bytes(chunk)) if isinstance(chunk, (bytes, bytearray)) else chunk
        if not text:
            return []
        if self._first:
            # A UTF-8 byte order mark at the very start of the stream is not part of the first line
            if text.startswith("﻿"):
                text = text[1:]
            self._first = False
        self._buffer += text
        return self._drain(final=False)

    def flush(self) -> List[SSEEvent]:
        """End of stream: process what is left. An event without its closing blank line is still
        dispatched (lenient), so a server that closes right after the last ``data:`` line loses
        nothing."""
        self._buffer += self._decoder.decode(b"", final=True)
        events = self._drain(final=True)
        if self._buffer:
            line, self._buffer = self._buffer, ""
            event = self._line(line)
            if event is not None:
                events.append(event)
        event = self._dispatch()
        if event is not None:
            events.append(event)
        return events

    def _drain(self, final: bool) -> List[SSEEvent]:
        events: List[SSEEvent] = []
        position = 0
        buffer = self._buffer
        while True:
            match = _LINE_END.search(buffer, position)
            if match is None:
                break
            # A "\r" at the very end may be the first half of "\r\n": wait for the next chunk
            if match.group() == "\r" and match.end() == len(buffer) and not final:
                break
            line = buffer[position:match.start()]
            position = match.end()
            event = self._line(line)
            if event is not None:
                events.append(event)
        self._buffer = buffer[position:]
        return events

    def _line(self, line: str) -> Optional[SSEEvent]:
        if line == "":
            return self._dispatch()
        if line.startswith(":"):
            return None
        field, sep, value = line.partition(":")
        if sep and value.startswith(" "):
            value = value[1:]
        if field == "event":
            self._event = value
        elif field == "data":
            self._data.append(value)
        elif field == "id":
            if "\x00" not in value:
                self._id = value
                self._last_id = value
        elif field == "retry":
            if value.isdigit():
                self._retry = int(value)
        return None

    def _dispatch(self) -> Optional[SSEEvent]:
        if not self._data:
            # A block without data lines dispatches nothing (the event name is discarded)
            self._event = None
            self._retry = None
            self._id = None
            return None
        event = SSEEvent(
            event=self._event or "message",
            data="\n".join(self._data),
            id=self._id if self._id is not None else self._last_id,
            retry=self._retry,
        )
        self._event = None
        self._data = []
        self._id = None
        self._retry = None
        return event


def iter_sse(chunks: Iterable[Union[bytes, str]]) -> Iterator[SSEEvent]:
    """Events of an iterable of chunks."""
    decoder = SSEDecoder()
    for chunk in chunks:
        yield from decoder.feed(chunk)
    yield from decoder.flush()
