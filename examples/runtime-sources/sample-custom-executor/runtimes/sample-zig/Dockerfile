# Runtime image of sample-zig: the Zig toolchain from Alpine's packages. RunR builds every saved
# version of a function FROM this image (build.image: "{image}" in runtime.yaml) and copies the
# static binary into a small run image (build.runImage), so this image never runs a function itself.
# RUNTIME_VERSION is the Zig version of the manifest (0.15); the build fails when Alpine's package
# is another one. CI builds it with --build-arg RUNTIME_VERSION=<version>, tests it and publishes it
# under the name the manifest gives (scripts/contract-test.sh, publish.yml).
FROM docker.io/library/alpine:3.23.8

ARG RUNTIME_VERSION=0.15

LABEL org.opencontainers.image.title="RunR sample executor: Zig ${RUNTIME_VERSION}" \
      org.opencontainers.image.description="Zig toolchain for RunR function builds (http mode)" \
      org.opencontainers.image.licenses="MIT"

# The global cache lives in the image: function builds reuse what the warm-up below compiled
ENV ZIG_GLOBAL_CACHE_DIR=/opt/zig-cache

RUN apk add --no-cache zig \
 && case "$(zig version)" in "${RUNTIME_VERSION}".*) ;; *) echo "Alpine's zig is $(zig version), not ${RUNTIME_VERSION}" >&2; exit 1 ;; esac \
 && mkdir -p /opt/runr \
 && echo "Zig $(zig version)" > /opt/runr/upstream \
 && cat /opt/runr/upstream

# Warm-up: one build of a stub function with RunR's build files compiles the build runner of
# build.zig and compiler_rt into the global cache, which every function build would do first.
# The tests of runr.zig run here too: an image whose build files fail them is not built.
COPY build.zig runr.zig runr_server.zig /tmp/warm/
RUN cd /tmp/warm \
 && zig fmt --check build.zig runr.zig runr_server.zig \
 && printf 'const std = @import("std");\nconst runr = @import("runr.zig");\npub fn handler(_: std.mem.Allocator, _: runr.Request) !runr.Response {\n    return runr.Response.text("warm");\n}\n' > main.zig \
 && echo 'pub const handler = @import("main.zig").handler;' > runr_entry.zig \
 && zig build test --summary none \
 && zig build -Doptimize=ReleaseSmall --prefix /tmp/warm/out --summary none \
 && cd / && rm -rf /tmp/warm

WORKDIR /build/code
