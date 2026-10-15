from urllib.parse import parse_qs, urlsplit

from spin_sdk import http
from spin_sdk.http import Request, Response


class HttpHandler(http.Handler):
    """Every trigger reaches the component as an HTTP request: queue messages and schedule
    ticks arrive as CloudEvents (the ce-type header names the event)."""

    async def handle_request(self, request: Request) -> Response:
        event = request.headers.get("ce-type", "")
        if event == "dev.runr.schedule.tick":
            body = "tick from Python (Wasm)"
        elif event == "dev.runr.queue.message":
            body = f"processed {(request.body or b'').decode()} (from Python, Wasm)"
        else:
            name = parse_qs(urlsplit(request.uri).query).get("name", ["World"])[0]
            body = f"Hello, {name}! (from Python, Wasm)"
        print(body)
        return Response(200, {"content-type": "text/plain"}, bytes(body, "utf-8"))
