"""RunR Python HTTP Runtime — wraps user handler as HTTP server."""

import importlib.util
import json
import os
import sys
import traceback
from http.server import HTTPServer, BaseHTTPRequestHandler

CODE_DIR = "/app/code"
HANDLER_NAME = os.environ.get("RUNR_HANDLER", "handler")
FILENAME = os.environ.get("RUNR_FILENAME", "main.py")

sys.path.insert(0, CODE_DIR)


def load_handler():
    filepath = os.path.join(CODE_DIR, FILENAME)
    module_name = os.path.splitext(FILENAME)[0]

    spec = importlib.util.spec_from_file_location(module_name, filepath)
    module = importlib.util.module_from_spec(spec)
    sys.modules[module_name] = module
    spec.loader.exec_module(module)

    fn = getattr(module, HANDLER_NAME, None)
    if fn is None:
        raise RuntimeError(f"Handler '{HANDLER_NAME}' not found in {FILENAME}")
    return fn


handler_fn = load_handler()


class RequestHandler(BaseHTTPRequestHandler):
    def _handle(self):
        # Knative probe — do not pass it to the handler
        if self.headers.get("K-Network-Probe") or self.headers.get("K-Kubelet-Probe"):
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"ok")
            return

        # CORS preflight
        if self.command == "OPTIONS":
            self.send_response(204)
            self.send_header("Access-Control-Allow-Origin", "*")
            self.send_header("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, PATCH, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
            self.send_header("Access-Control-Max-Age", "86400")
            self.end_headers()
            return

        content_length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(content_length).decode("utf-8") if content_length else ""

        try:
            event = json.loads(body) if body else {}
        except json.JSONDecodeError:
            event = {"body": body}

        context = {
            "method": self.command,
            "path": self.path,
            "headers": dict(self.headers),
        }

        try:
            result = handler_fn(event, context)

            if isinstance(result, dict):
                status = result.get("statusCode", 200)
                custom_headers = result.get("headers", {})
                resp_body = result.get("body", result)
            else:
                status = 200
                custom_headers = {}
                resp_body = result

            # Case-insensitive header merge: user headers override defaults
            defaults = {"Access-Control-Allow-Origin": "*", "Content-Type": "application/json"}
            headers = dict(defaults)
            lower_defaults = {k.lower(): k for k in defaults}
            for k, v in custom_headers.items():
                orig = lower_defaults.get(k.lower())
                if orig:
                    del headers[orig]
                headers[k] = v

            self.send_response(status)
            for k, v in headers.items():
                self.send_header(k, v)
            self.end_headers()

            ct = next((v for k, v in headers.items() if k.lower() == "content-type"), "")
            if isinstance(resp_body, str) and "application/json" not in ct:
                self.wfile.write(resp_body.encode("utf-8"))
            else:
                self.wfile.write(json.dumps(resp_body, ensure_ascii=False).encode("utf-8"))

        except Exception:
            tb = traceback.format_exc()
            print(tb, file=sys.stderr)
            self.send_response(500)
            self.send_header("Access-Control-Allow-Origin", "*")
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": tb}).encode("utf-8"))

    do_GET = _handle
    do_POST = _handle
    do_PUT = _handle
    do_DELETE = _handle
    do_PATCH = _handle
    do_OPTIONS = _handle

    def log_message(self, fmt, *args):
        print(f"[runr] {args[0]} {args[1]} {args[2]}")


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    server = HTTPServer(("0.0.0.0", port), RequestHandler)
    print(f"RunR Python runtime listening on :{port}")
    server.serve_forever()
