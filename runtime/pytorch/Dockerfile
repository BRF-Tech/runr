# Built-in runtime PyTorch, CPU image: ghcr.io/brf-tech/runr/exec-pytorch:<VERSION>, VERSION = the
# major.minor of the torch pin in requirements-torch.txt. The handler server is the Python
# runtime's (server.py, runner.py, entrypoint.sh of runtime/python, the build context
# "runtime-python"):
#
#   runtime/ci.sh ml-runtimes pytorch cpu
#   docker build --platform linux/amd64 --build-context runtime-python=runtime/python \
#     -t ghcr.io/brf-tech/runr/exec-pytorch:2.14 runtime/pytorch
FROM ghcr.io/brf-tech/runr/reqs/python:3.12-slim
ARG VERSION=2.14

WORKDIR /app

# pip and setuptools of the base image upgraded (advisories); torch and torchvision from the
# PyTorch CPU wheel index, the ML essentials (requirements.txt) from PyPI. Bytecode of the stdlib
# and site-packages is compiled once, in the same layer (pip --no-compile, so no second copy):
# level 0 = entrypoint.sh (python -u, no -O), unchecked-hash = used without looking at source
# mtimes; the slim base ships no stdlib .pyc, so without this every cold start compiled each
# imported module under the CPU limit. pip check and the imports (torch must be VERSION) gate
# the build.
RUN --mount=type=bind,source=requirements-torch.txt,target=/tmp/requirements-torch.txt \
    --mount=type=bind,source=requirements.txt,target=/tmp/requirements.txt \
    pip install --no-cache-dir --no-compile --upgrade pip==26.2.1 setuptools==84.0.0 && \
    pip install --no-cache-dir --no-compile \
      --index-url https://download.pytorch.org/whl/cpu -r /tmp/requirements-torch.txt && \
    pip install --no-cache-dir --no-compile -r /tmp/requirements.txt && \
    python -m compileall -q -j 0 --invalidation-mode unchecked-hash \
      "$(python -c 'import sysconfig; print(sysconfig.get_path("stdlib"))')" && \
    pip check && \
    python -B -c "import sys, torch, torchvision, numpy, scipy, pandas, matplotlib, sklearn; \
v = torch.__version__; print('torch', v, 'cuda', torch.version.cuda); \
sys.exit(0 if v.startswith('${VERSION}.') and torch.version.cuda is None else 'torch ' + v + ' is not a CPU build of ${VERSION}')"

COPY --from=runtime-python server.py runner.py entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.py

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
