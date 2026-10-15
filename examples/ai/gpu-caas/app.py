"""GPU check for RunR CaaS: reports what PyTorch sees and runs a small inference on it.

GET /        → JSON: cuda available, device name and count, a matmul checksum and the output
               of a tiny fixed network (the same numbers on CPU and GPU)
GET /healthz → "ok"

Runs on CPU too (no GPU requested, or the image started on a node without one) and says so in
the "message" field.
"""

import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import torch

PORT = int(os.environ.get("PORT", "8080"))


def tiny_network() -> torch.nn.Module:
    """A 4 → 3 → 2 network with fixed weights: deterministic output on every device."""
    torch.manual_seed(0)
    net = torch.nn.Sequential(torch.nn.Linear(4, 3), torch.nn.ReLU(), torch.nn.Linear(3, 2), torch.nn.Softmax(dim=-1))
    net.eval()
    return net


DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")
NET = tiny_network().to(DEVICE)


def report() -> dict:
    cuda = torch.cuda.is_available()
    started = time.perf_counter()

    a = torch.arange(256 * 256, dtype=torch.float32, device=DEVICE).reshape(256, 256) / 65536.0
    product = a @ a.T
    with torch.no_grad():
        probabilities = NET(torch.tensor([[1.0, 2.0, 3.0, 4.0]], device=DEVICE))
    if cuda:
        torch.cuda.synchronize()

    return {
        "cuda": cuda,
        "device": torch.cuda.get_device_name(0) if cuda else "cpu",
        "device_count": torch.cuda.device_count() if cuda else 0,
        "torch": torch.__version__,
        "cuda_version": torch.version.cuda,
        "matmul_checksum": round(float(product.sum().item()), 3),
        "inference": [round(float(p), 6) for p in probabilities[0].tolist()],
        "elapsed_ms": round((time.perf_counter() - started) * 1000, 3),
        "message": "running on the GPU" if cuda else "no GPU visible to the container: running on the CPU",
    }


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802 (http.server naming)
        if self.path.startswith("/healthz"):
            self._send(200, "text/plain", b"ok")
            return
        self._send(200, "application/json", json.dumps(report()).encode())

    def _send(self, status: int, content_type: str, body: bytes):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        print("%s - %s" % (self.address_string(), fmt % args), flush=True)


if __name__ == "__main__":
    print(f"gpu-caas listening on :{PORT} ({DEVICE})", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
