# Built-in runtime TensorFlow, CPU image: ghcr.io/brf-tech/runr/exec-tensorflow:<VERSION>, VERSION =
# the major.minor of the tensorflow-cpu pin in requirements-cpu.txt (Google's CPU-only build of
# TensorFlow, the same `tensorflow` module). The handler server is the Python runtime's
# (server.py, runner.py, entrypoint.sh of runtime/python, the build context "runtime-python"):
#
#   runtime/ci.sh ml-runtimes tensorflow cpu
#   docker build --platform linux/amd64 --build-context runtime-python=runtime/python \
#     -t ghcr.io/brf-tech/runr/exec-tensorflow:2.21 runtime/tensorflow
FROM ghcr.io/brf-tech/runr/reqs/python:3.12-slim
ARG VERSION=2.21

WORKDIR /app

# pip and setuptools of the base image upgraded (advisories); TensorFlow, Keras and the ML
# essentials from PyPI. Bytecode of the stdlib and site-packages is compiled once, in the same
# layer (pip --no-compile, so no second copy): level 0 = entrypoint.sh (python -u, no -O),
# unchecked-hash = used without looking at source mtimes; the slim base ships no stdlib .pyc, so
# without this every cold start compiled each imported module under the CPU limit. pip check and
# the imports (TensorFlow must be VERSION) gate the build.
RUN --mount=type=bind,source=requirements-cpu.txt,target=/tmp/requirements-cpu.txt \
    --mount=type=bind,source=requirements.txt,target=/tmp/requirements.txt \
    pip install --no-cache-dir --no-compile --upgrade pip==26.2.1 setuptools==84.0.0 && \
    pip install --no-cache-dir --no-compile -r /tmp/requirements-cpu.txt -r /tmp/requirements.txt && \
    python -m compileall -q -j 0 --invalidation-mode unchecked-hash \
      "$(python -c 'import sysconfig; print(sysconfig.get_path("stdlib"))')" && \
    pip check && \
    TF_CPP_MIN_LOG_LEVEL=2 python -B -c "import sys, tensorflow as tf, keras, numpy, scipy, pandas, matplotlib, sklearn; \
v = tf.__version__; cuda = tf.sysconfig.get_build_info().get('cuda_version'); print('tensorflow', v, 'cuda', cuda); \
sys.exit(0 if v.startswith('${VERSION}.') and not tf.test.is_built_with_cuda() else 'tensorflow ' + v + ' is not a CPU build of ${VERSION}')"

COPY --from=runtime-python server.py runner.py entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.py

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
