# GPU CaaS example: a stdlib Python HTTP server that runs a CUDA kernel through the CUDA driver API
# (ctypes). No CUDA toolkit in the image: the NVIDIA container runtime puts the driver's
# libcuda.so.1 into a GPU container, and the driver compiles the kernel's PTX for its GPU.
#   docker build -t registry.example.com/acme/gpu-caas:1 examples/ai/gpu-caas
# From the reqs mirror of docker.io/library/python (runtime/lib/mirror.sh).
FROM ghcr.io/brf-tech/runr/reqs/python:3.12-slim

# LD_LIBRARY_PATH: where some Kubernetes GPU setups mount the driver's libraries
ENV PYTHONUNBUFFERED=1 \
    PORT=8080 \
    NVIDIA_VISIBLE_DEVICES=all \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility \
    LD_LIBRARY_PATH=/usr/local/nvidia/lib:/usr/local/nvidia/lib64

WORKDIR /app
COPY app.py .

RUN useradd --uid 10001 --create-home app
USER 10001

EXPOSE 8080
CMD ["python", "/app/app.py"]
