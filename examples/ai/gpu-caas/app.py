"""GPU check for RunR CaaS: runs a CUDA kernel on the GPU the container gets and checks its result.

GET /        → JSON: whether CUDA sees a GPU, its name, compute capability and memory, and the
               result of a SAXPY kernel (y = 3·x + y over 1,048,576 floats) run on it, compared
               element by element with the same computation on the CPU
GET /healthz → "ok"

No CUDA toolkit, PyTorch or other dependency in the image: the standard library's ctypes calls
the CUDA driver API (libcuda.so.1, which the NVIDIA container runtime puts into a GPU container),
and the driver compiles the kernel below from PTX for whatever GPU it finds (JIT). Runs on the
CPU too (no GPU requested, or the image started on a node without one) and says why in the
"message" field.
"""

import ctypes
import json
import os
import threading
import time
from array import array
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = int(os.environ.get("PORT", "8080"))

# The computation: y[i] = A·x[i] + y[i] with x[i] = i mod 1024 and y[i] = i mod 7. Every value is a
# small integer, exact in float32, so the GPU's result equals the CPU's bit for bit.
N = 1 << 20
A = 3.0
X = (array("f", range(1024)) * (N // 1024))[:N]
Y0 = (array("f", range(7)) * (N // 7 + 1))[:N]
EXPECTED = array("f", (A * x + y for x, y in zip(X, Y0)))
EXPECTED_CHECKSUM = int(sum(EXPECTED))

# The kernel in PTX: nvcc's output for this CUDA C, with shorter names
#   extern "C" __global__ void saxpy(float a, const float *x, float *y, unsigned int n) {
#     unsigned int i = blockIdx.x * blockDim.x + threadIdx.x;
#     if (i < n) y[i] = a * x[i] + y[i];
#   }
# PTX ISA 6.0 for sm_50: the driver JIT-compiles it for any later GPU.
SAXPY_PTX = b"""
.version 6.0
.target sm_50
.address_size 64

.visible .entry saxpy(
    .param .f32 saxpy_a,
    .param .u64 saxpy_x,
    .param .u64 saxpy_y,
    .param .u32 saxpy_n
)
{
    .reg .pred  %p<2>;
    .reg .f32   %f<5>;
    .reg .b32   %r<6>;
    .reg .b64   %rd<8>;

    ld.param.f32    %f1, [saxpy_a];
    ld.param.u64    %rd1, [saxpy_x];
    ld.param.u64    %rd2, [saxpy_y];
    ld.param.u32    %r2, [saxpy_n];
    mov.u32         %r3, %ctaid.x;
    mov.u32         %r4, %ntid.x;
    mov.u32         %r5, %tid.x;
    mad.lo.s32      %r1, %r3, %r4, %r5;
    setp.ge.u32     %p1, %r1, %r2;
    @%p1 bra        DONE;

    cvta.to.global.u64  %rd3, %rd1;
    mul.wide.u32        %rd4, %r1, 4;
    add.s64             %rd5, %rd3, %rd4;
    ld.global.f32       %f2, [%rd5];
    cvta.to.global.u64  %rd6, %rd2;
    add.s64             %rd7, %rd6, %rd4;
    ld.global.f32       %f3, [%rd7];
    fma.rn.f32          %f4, %f2, %f1, %f3;
    st.global.f32       [%rd7], %f4;

DONE:
    ret;
}
\0"""

# Where the NVIDIA container runtime and the common driver installs put libcuda
LIBCUDA = (
    "libcuda.so.1",
    "/usr/lib/x86_64-linux-gnu/libcuda.so.1",
    "/usr/lib/aarch64-linux-gnu/libcuda.so.1",
    "/usr/lib64/libcuda.so.1",
    "/usr/local/nvidia/lib64/libcuda.so.1",
    "/usr/lib/wsl/lib/libcuda.so.1",
)

CU_DEVICE_ATTRIBUTE_COMPUTE_CAPABILITY_MAJOR = 75
CU_DEVICE_ATTRIBUTE_COMPUTE_CAPABILITY_MINOR = 76


class CudaError(Exception):
    pass


class Gpu:
    """GPU 0 through the CUDA driver API, the kernel loaded into its primary context."""

    def __init__(self):
        self.cuda = None
        for name in LIBCUDA:
            try:
                self.cuda = ctypes.CDLL(name)
                break
            except OSError:
                continue
        if self.cuda is None:
            raise CudaError("no GPU visible to the container (no libcuda.so.1)")

        cuda = self.cuda
        cuda.cuGetErrorName.argtypes = [ctypes.c_int, ctypes.POINTER(ctypes.c_char_p)]
        cuda.cuDeviceGetName.argtypes = [ctypes.c_char_p, ctypes.c_int, ctypes.c_int]
        cuda.cuDeviceTotalMem_v2.argtypes = [ctypes.POINTER(ctypes.c_size_t), ctypes.c_int]
        cuda.cuDevicePrimaryCtxRetain.argtypes = [ctypes.POINTER(ctypes.c_void_p), ctypes.c_int]
        cuda.cuCtxSetCurrent.argtypes = [ctypes.c_void_p]
        cuda.cuModuleLoadData.argtypes = [ctypes.POINTER(ctypes.c_void_p), ctypes.c_char_p]
        cuda.cuModuleGetFunction.argtypes = [ctypes.POINTER(ctypes.c_void_p), ctypes.c_void_p, ctypes.c_char_p]
        cuda.cuMemAlloc_v2.argtypes = [ctypes.POINTER(ctypes.c_uint64), ctypes.c_size_t]
        cuda.cuMemFree_v2.argtypes = [ctypes.c_uint64]
        cuda.cuMemcpyHtoD_v2.argtypes = [ctypes.c_uint64, ctypes.c_void_p, ctypes.c_size_t]
        cuda.cuMemcpyDtoH_v2.argtypes = [ctypes.c_void_p, ctypes.c_uint64, ctypes.c_size_t]
        cuda.cuLaunchKernel.argtypes = [
            ctypes.c_void_p,
            ctypes.c_uint, ctypes.c_uint, ctypes.c_uint,
            ctypes.c_uint, ctypes.c_uint, ctypes.c_uint,
            ctypes.c_uint, ctypes.c_void_p, ctypes.POINTER(ctypes.c_void_p), ctypes.c_void_p,
        ]

        try:
            self.call("cuInit", 0)
        except CudaError as e:
            raise CudaError(f"no GPU visible to the container ({e})") from e
        version, count, device = ctypes.c_int(), ctypes.c_int(), ctypes.c_int()
        self.call("cuDriverGetVersion", ctypes.byref(version))
        self.call("cuDeviceGetCount", ctypes.byref(count))
        if count.value < 1:
            raise CudaError("no GPU visible to the container (CUDA lists none)")
        self.call("cuDeviceGet", ctypes.byref(device), 0)

        name = ctypes.create_string_buffer(256)
        major, minor, memory = ctypes.c_int(), ctypes.c_int(), ctypes.c_size_t()
        self.call("cuDeviceGetName", name, len(name), device)
        self.call("cuDeviceGetAttribute", ctypes.byref(major), CU_DEVICE_ATTRIBUTE_COMPUTE_CAPABILITY_MAJOR, device)
        self.call("cuDeviceGetAttribute", ctypes.byref(minor), CU_DEVICE_ATTRIBUTE_COMPUTE_CAPABILITY_MINOR, device)
        self.call("cuDeviceTotalMem_v2", ctypes.byref(memory), device)
        self.info = {
            "device": name.value.decode(errors="replace"),
            "device_count": count.value,
            "compute_capability": f"{major.value}.{minor.value}",
            "memory_mb": memory.value // (1024 * 1024),
            "driver_cuda_version": f"{version.value // 1000}.{version.value % 1000 // 10}",
        }

        self.context = ctypes.c_void_p()
        self.call("cuDevicePrimaryCtxRetain", ctypes.byref(self.context), device)
        self.call("cuCtxSetCurrent", self.context)
        module, self.saxpy = ctypes.c_void_p(), ctypes.c_void_p()
        self.call("cuModuleLoadData", ctypes.byref(module), SAXPY_PTX)
        self.call("cuModuleGetFunction", ctypes.byref(self.saxpy), module, b"saxpy")
        self.lock = threading.Lock()

    def call(self, function: str, *args):
        result = getattr(self.cuda, function)(*args)
        if result != 0:
            name = ctypes.c_char_p()
            self.cuda.cuGetErrorName(result, ctypes.byref(name))
            raise CudaError(f"{function}: {(name.value or b'CUDA error').decode()} ({result})")

    def run_saxpy(self) -> array:
        """y = A·x + y on the GPU: copy x and y in, launch the kernel, copy y back."""
        size = N * 4
        y = array("f", Y0)
        with self.lock:
            # the primary context is current per thread; every request has its own
            self.call("cuCtxSetCurrent", self.context)
            dx, dy = ctypes.c_uint64(), ctypes.c_uint64()
            self.call("cuMemAlloc_v2", ctypes.byref(dx), size)
            try:
                self.call("cuMemAlloc_v2", ctypes.byref(dy), size)
                try:
                    self.call("cuMemcpyHtoD_v2", dx, X.buffer_info()[0], size)
                    self.call("cuMemcpyHtoD_v2", dy, y.buffer_info()[0], size)
                    a, n = ctypes.c_float(A), ctypes.c_uint32(N)
                    args = (a, dx, dy, n)
                    params = (ctypes.c_void_p * len(args))(*(ctypes.addressof(arg) for arg in args))
                    block = 256
                    self.call("cuLaunchKernel", self.saxpy, (N + block - 1) // block, 1, 1, block, 1, 1, 0, None, params, None)
                    self.call("cuCtxSynchronize")
                    self.call("cuMemcpyDtoH_v2", y.buffer_info()[0], dy, size)
                finally:
                    self.call("cuMemFree_v2", dy)
            finally:
                self.call("cuMemFree_v2", dx)
        return y


try:
    GPU = Gpu()
    GPU_ERROR = ""
except (CudaError, AttributeError) as e:  # AttributeError: a libcuda without a function used here
    GPU, GPU_ERROR = None, str(e)


def report() -> dict:
    started = time.perf_counter()
    out = {"cuda": GPU is not None}
    if GPU is not None:
        out.update(GPU.info)
    else:
        out.update({"device": "cpu", "device_count": 0})

    computed_on, error = "cpu", GPU_ERROR
    if GPU is not None:
        try:
            y = GPU.run_saxpy()
            computed_on = "gpu"
        except CudaError as e:
            error = f"the kernel did not run on the GPU: {e}"
    if computed_on == "cpu":
        y = array("f", (A * x + y0 for x, y0 in zip(X, Y0)))

    ok = y == EXPECTED
    if computed_on == "gpu":
        message = "a CUDA kernel ran on the GPU and its result matches the CPU's" if ok else "the GPU's result differs from the CPU's"
    else:
        message = f"{error}: computed on the CPU"
    out.update({
        "computed_on": computed_on,
        "kernel": f"saxpy: y = {A:g}*x + y over {N} floats",
        "checksum": int(sum(y)),
        "expected_checksum": EXPECTED_CHECKSUM,
        "result_ok": ok,
        "elapsed_ms": round((time.perf_counter() - started) * 1000, 3),
        "message": message,
    })
    return out


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
    where = f"GPU 0: {GPU.info['device']}, compute capability {GPU.info['compute_capability']}" if GPU else f"CPU: {GPU_ERROR}"
    print(f"gpu-caas listening on :{PORT} ({where})", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
