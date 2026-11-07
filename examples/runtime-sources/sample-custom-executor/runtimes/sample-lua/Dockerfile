# Runtime image of sample-lua: Lua from Alpine's packages, lua-cjson for the protocol, LuaRocks
# for function dependencies, and runner.lua, the worker RunR's universal wrapper starts
# (run.mode: persistent). One Dockerfile for every version; RUNTIME_VERSION is the Lua version:
#   - image versions (5.4): CI builds it with --build-arg RUNTIME_VERSION=<version>, tests it and
#     publishes it under the name the manifest gives (scripts/contract-test.sh, publish.yml)
#   - dockerfile versions (5.3): RunR builds it in each region from the files of this directory,
#     with the version's buildArgs
FROM docker.io/library/alpine:3.24.3

ARG RUNTIME_VERSION=5.4

LABEL org.opencontainers.image.title="RunR sample executor: Lua ${RUNTIME_VERSION}" \
      org.opencontainers.image.description="Lua runtime for RunR (persistent mode through the universal wrapper)" \
      org.opencontainers.image.licenses="MIT"

RUN apk add --no-cache "lua${RUNTIME_VERSION}" "lua${RUNTIME_VERSION}-cjson" "luarocks${RUNTIME_VERSION}" \
 && ln -s "/usr/bin/lua${RUNTIME_VERSION}" /usr/local/bin/lua \
 && ln -s "/usr/bin/luarocks-${RUNTIME_VERSION}" /usr/local/bin/luarocks \
 && mkdir -p /opt/runr /app/code \
 && lua -v | cut -d' ' -f1,2 > /opt/runr/upstream \
 && cat /opt/runr/upstream

COPY runner.lua /opt/runr/runner.lua

WORKDIR /app/code

# Without RunR (docker run -i): one request envelope per stdin line, one JSON answer per line
ENTRYPOINT ["lua", "/opt/runr/runner.lua"]
