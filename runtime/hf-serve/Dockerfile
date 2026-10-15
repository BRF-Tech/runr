# RunR hf-serve, CPU image: transformers pipelines (classification, NER, summarization, speech
# recognition, image classification, embeddings, small text generation) plus the mirror and
# upload-receiver helpers. Model services on the "transformers" engine without a GPU run it.
FROM ghcr.io/brf-tech/reqs/python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    HF_HOME=/models/hf \
    HF_HUB_DISABLE_TELEMETRY=1 \
    PORT=8080

# ffmpeg decodes compressed audio (mp3, m4a) for speech recognition; libsndfile reads WAV / FLAC
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg libsndfile1 \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/hf-serve

# pip and setuptools of the base image upgraded (advisories); torch from the CPU wheel index
COPY requirements.txt ./
RUN pip install --upgrade pip==26.2.1 setuptools==84.0.0 \
 && pip install --index-url https://download.pytorch.org/whl/cpu torch==2.14.1 \
 && pip install -r requirements.txt

COPY runr_hf_serve ./runr_hf_serve
RUN python -m compileall -q runr_hf_serve

# Non-root; /models is where the region model volume is mounted (HF cache, snapshots)
RUN useradd --uid 10001 --user-group --create-home --home-dir /home/runr runr \
 && mkdir -p /models/hf \
 && chown -R 10001:10001 /models
USER 10001

EXPOSE 8080
ENTRYPOINT ["python", "-m", "runr_hf_serve"]
CMD ["serve"]
