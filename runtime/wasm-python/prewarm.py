# Componentized once while the build image is built (componentize-py and the Spin SDK exercised).
from spin_sdk import http
from spin_sdk.http import Request, Response


class HttpHandler(http.Handler):
    async def handle_request(self, request: Request) -> Response:
        return Response(200, {"content-type": "text/plain"}, b"prewarm")
