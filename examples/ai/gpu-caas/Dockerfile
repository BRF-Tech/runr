# GPU CaaS example: CUDA PyTorch + a stdlib HTTP server (no other dependencies).
#   docker build -t registry.example.com/acme/gpu-caas:1 examples/ai/gpu-caas
FROM docker.io/pytorch/pytorch:2.5.1-cuda12.4-cudnn9-runtime

ENV PYTHONUNBUFFERED=1 \
    PORT=8080 \
    NVIDIA_VISIBLE_DEVICES=all \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility

WORKDIR /app
COPY app.py .

RUN useradd --uid 10001 --create-home app
USER 10001

EXPOSE 8080
CMD ["python", "/app/app.py"]
