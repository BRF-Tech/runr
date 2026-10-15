ARG VERSION=2.3
FROM ghcr.io/runrsh/reqs/python:3.12-slim
# An ARG declared before FROM is not visible inside the stage without this line
ARG VERSION

WORKDIR /app

# PyTorch CPU + ML essentials. Bytecode of the stdlib and site-packages is compiled once, in the
# same layer (pip --no-compile, so no second copy): level 0 = entrypoint.sh (python -u, no -O),
# unchecked-hash = used without looking at source mtimes. The slim base ships no stdlib .pyc,
# so without this every cold start compiled each imported module under the CPU limit.
RUN pip install --no-cache-dir --no-compile \
    torch==${VERSION}.0+cpu torchvision torchaudio \
    --index-url https://download.pytorch.org/whl/cpu && \
    pip install --no-cache-dir --no-compile numpy scipy pandas matplotlib scikit-learn && \
    python -m compileall -q -j 0 --invalidation-mode unchecked-hash \
      "$(python -c 'import sysconfig; print(sysconfig.get_path("stdlib"))')"

COPY server.py runner.py entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.py

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
