# Uploaded images + wasm-exec (docs/images.md, docs/wasm.md#wasm-exec): Kind e2e.
#
# cmd_test_images     — `ci.sh test-images [cmd_test flags]`: Kind + Knative (cmd_test) running only
#                       the images suite (test:e2e --suite=images, E2eTestImagesTrait): uploads a
#                       `docker save` tar of a small HTTP server and q.wasm (the Q interpreter built
#                       with TinyGo as a WASI 0.2 component) through the API in chunks — the receiver Job pushes them
#                       into the region registry — installs WebAssembly support, deploys a CaaS project
#                       from the uploaded image, a wasm-exec function in C (examples/wasm/c-exec, built
#                       in the region with wasi-sdk) and a function of Q (Wasm) (examples/wasm/q-exec,
#                       a platform runtime whose version is the uploaded interpreter), calls the three,
#                       then deletes projects, runtime and images (manifests leave the registry) and
#                       uninstalls WebAssembly support.
# prepare_images_e2e  — builds runr-image-upload (runtime/image-upload) and the wasm-exec build image
#                       (runtime/wasm-exec) and loads both into the Kind cluster, builds the HTTP server
#                       image (runtime/nginx-test) and saves it as echo.tar, compiles q.wasm with the
#                       wasm-exec image's TinyGo, and copies both files and examples/wasm into the API container
#                       (/tmp/runr-images, /tmp/runr-examples/wasm).
#
# KIND_LOCK           — one Kind cluster at a time on a shared host: defaults to /tmp/runr-kind.lock
#                       here (see acquire_kind_lock in test-runtimes.sh).
# IMAGES_E2E_DIR      — where the upload files are prepared on the host (default /tmp/runr-images-e2e).

prepare_images_e2e() {
  local cluster="$1"
  local work="${IMAGES_E2E_DIR:-/tmp/runr-images-e2e}"
  local upload_tag="${REGISTRY}/image-upload:${IMAGE_UPLOAD_VERSION:-1.0.0}"
  local exec_tag="${REGISTRY}/exec-wasm-exec:$(wasm_runtime_version exec)"
  local echo_tag="runr-e2e/echo:upload"

  echo ""
  echo "Uploaded images e2e preparation..."
  mkdir -p "$work"
  # the wasm-exec functions run under the Spin shim (test-wasm.sh)
  if [ -n "$cluster" ]; then
    kind_nodes_dbus "$cluster"
    kind_load_wasm_shim "$cluster"
  fi

  echo "  receiver image: ${upload_tag}"
  docker build -q -t "${upload_tag}" "${SCRIPT_DIR}/image-upload" >/dev/null
  echo "  wasm-exec build image: ${exec_tag}"
  docker build -q --build-arg "IMAGE_UPLOAD_IMAGE=${upload_tag}" -t "${exec_tag}" "${SCRIPT_DIR}/wasm-exec" >/dev/null
  if [ -n "$cluster" ]; then
    kind load docker-image "${upload_tag}" --name "$cluster" >/dev/null
    kind load docker-image "${exec_tag}" --name "$cluster" >/dev/null
    echo "  both loaded into kind/${cluster}"
  fi

  # the file to upload: a docker save archive of a small HTTP server (answers {"status":"ok",...})
  docker build -q -t "${echo_tag}" "${SCRIPT_DIR}/nginx-test" >/dev/null
  docker save "${echo_tag}" -o "${work}/echo.tar"
  echo "  ${echo_tag} → ${work}/echo.tar ($(du -h "${work}/echo.tar" | cut -f1))"

  # the Q interpreter for WASI (examples/runtimes/q/interpreter), built as wasm-exec builds Go: with
  # the TinyGo of the wasm-exec image, a WASI 0.2 component (runr-wasm-exec)
  docker run --rm \
    -v "${SCRIPT_DIR}/../examples/runtimes/q/interpreter:/src:ro" \
    -v "${work}:/out" \
    -u "$(id -u):$(id -g)" -e HOME=/tmp -w /src \
    "${exec_tag}" \
    tinygo build -target=wasip2 -no-debug -o /out/q.wasm . >/dev/null
  echo "  q.wasm → ${work}/q.wasm ($(du -h "${work}/q.wasm" | cut -f1))"

  docker compose exec -T api rm -rf /tmp/runr-images /tmp/runr-examples/wasm >/dev/null 2>&1 || true
  docker compose exec -T api mkdir -p /tmp/runr-examples >/dev/null 2>&1 || true
  docker compose cp "${work}" api:/tmp/runr-images
  docker compose cp "${SCRIPT_DIR}/../examples/wasm" api:/tmp/runr-examples/wasm
  echo "  ${work} → api:/tmp/runr-images, examples/wasm → api:/tmp/runr-examples/wasm"
}

cmd_test_images() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # cmd_test runs prepare_images_e2e for --suite=images (step 4c of test.sh)
  cmd_test --suite=images "$@"
}
