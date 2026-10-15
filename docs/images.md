# Uploaded images

Upload a container image — a `docker save` archive or an OCI image-layout tar — or a WebAssembly
module (`.wasm`) through the console, the API or `runr images push`, and RunR pushes it into a
region's registry. The file travels through RunR to the region, chunk by chunk, so a region that
has no route to the internet (or to any registry outside it) gets its images this way. An uploaded
image is then used like any other:

- as the image of a **CaaS project** (the CaaS form offers the region's uploaded images next to a
  registry reference);
- as the image of a **runtime version** (`upload:` in the runtime manifest, [Runtimes](runtimes.md#versions));
- as the module of a **wasm-exec function** (`wasm.upload`, [WebAssembly](wasm.md#wasm-exec)) or of a
  WebAssembly runtime that ships an interpreter (Q on Wasm, [`examples/wasm/q-exec`](../examples/wasm/q-exec)).

Uploaded container images go through the [supply chain](supply-chain.md) like the images RunR
builds: SBOM, vulnerability scan, signature.

- [How it works](#how-it-works)
- [Formats](#formats)
- [Upload](#upload)
- [Resume and verification](#resume-and-verification)
- [Using uploaded images](#using-uploaded-images)
- [Supply chain and data residency](#supply-chain-and-data-residency)
- [Quota, replacing and deleting](#quota-replacing-and-deleting)
- [Air-gapped regions](#air-gapped-regions)
- [Permissions](#permissions)
- [Audit](#audit)
- [API](#api)
- [Configuration](#configuration)
- [Data model](#data-model)
- [Testing](#testing)

## How it works

```text
console / runr images push / API
  └─ POST /api/images/uploads {knative_region_id, filename, size, sha256, name}
       └─ region: Job image-recv-<id> (runr-image-upload serve) + Service, emptyDir /work
  └─ PUT /api/images/uploads/{id}/chunk?offset=N  (raw bytes, 8 MiB)
       └─ RunR API → Kubernetes API server → service proxy → receiver: written at offset N
          (nothing is stored on the RunR API's disk; the region needs no route to RunR)
  └─ POST /api/images/uploads/{id}/complete
       └─ receiver: size + SHA-256 → format → push into the region registry
          <registry>/runr-uploads/<owner>/<repository>:<tag>  →  digest read back from the registry
  └─ CheckImageUploadJob (every 5 s) reads the receiver's status
       └─ uploaded_images row, audit event, supply-chain run, receiver removed
```

The receiver (`runtime/image-upload`, image `ghcr.io/brf-tech/image-upload`) is one static Go binary
built on go-containerregistry. Its pod has no Kubernetes API access, runs as a non-root user with a
read-only root filesystem and no capabilities, and uses the region's build RuntimeClass — gVisor or
Kata where builds are sandboxed, since archive content from users is unpacked there.

The repository in the registry belongs to the owner of the upload: `runr-uploads/<tenant id>/…` for
an organization of a multi-tenant install, `runr-uploads/platform/…` outside a tenant (single-tenant
installs, and platform administrators in the central console). Users see and use their own owner's
images only.

## Formats

| File | Detected by | Pushed as |
|---|---|---|
| `docker save` archive (Docker 25 and later) | `oci-layout` + `index.json` in the tar | the OCI layout's image or image index, digests unchanged |
| `docker save` archive (older Docker, `manifest.json` only) | `manifest.json` | the image of the archive; the registry gets compressed layers, so the digest is the one of the pushed image |
| OCI image-layout tar (`skopeo copy … oci-archive:`, `oras cp --to-oci-layout`, `crane pull --format=oci` + tar) | `oci-layout` + `index.json` | the layout's image or index as it is |
| any of these, gzip'ed (`.tar.gz`, `.tgz`) | gzip magic | decompressed, then as above |
| WebAssembly module or component (`.wasm`) | `\0asm` | an OCI artifact: one `application/wasm` layer titled `module.wasm`, config `application/vnd.wasm.config.v0+json` — the same file always gives the same digest |
| Spin app (`spin registry push`, then `oras cp --to-oci-layout` + tar) | config `application/vnd.fermyon.spin.application.v1+config` | as it is, kind `spin` |

**Multi-platform images.** An image index is pushed with every platform. `docker save` of a
multi-platform image may carry the layers of the local platform only; the receiver then pushes the
platforms whose blobs are all in the archive — one as that platform's image, several as a new
index — and the uploaded image's note says which. To keep every platform, export the index:

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t myapp:1.0 --output type=oci,dest=myapp.tar .
# or, from a registry you can reach:
skopeo copy --all docker://registry.example.com/myapp:1.0 oci-archive:myapp.tar
```

**Archives with several images.** A layout or archive holding several images is narrowed to the one
named like the upload's tag (`org.opencontainers.image.ref.name` / `io.containerd.image.name`, or
the `RepoTags` of a classic archive); otherwise the upload fails asking for one image per file.

## Upload

**Console:** Settings → Images → **Upload image**: region, file, name (`repository[:tag]`, from the
file name by default) and format (detected). The dialog shows the progress, the verification and
the push; unfinished uploads stay listed with **Resume** and **Abort**.

**CLI** ([CLI](cli.md#images)):

```bash
docker save myapp:1.0 -o myapp.tar
runr images push myapp.tar --region eu-1 --name myapp:1.0
runr images ls
runr images rm myapp:1.0 --region eu-1
```

**API** (any HTTP client; `$API` is `https://<console>/api`):

```bash
size=$(stat -c %s myapp.tar); sum=$(sha256sum myapp.tar | cut -d' ' -f1)
id=$(curl -sS -X POST "$API/images/uploads" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"knative_region_id\":\"$REGION\",\"filename\":\"myapp.tar\",\"size\":$size,\"sha256\":\"$sum\",\"name\":\"myapp:1.0\"}" | jq -r .data.id)
# wait until GET $API/images/uploads/$id answers status "ready", then send 8 MiB chunks:
split -b 8388608 -d myapp.tar part.
offset=0
for part in part.*; do
  curl -sS -X PUT "$API/images/uploads/$id/chunk?offset=$offset" -H "Authorization: Bearer $TOKEN" \
    -H 'Accept: application/json' -H 'Content-Type: application/octet-stream' --data-binary @"$part"
  offset=$((offset + $(stat -c %s "$part")))
done
curl -sS -X POST "$API/images/uploads/$id/complete" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
# poll GET $API/images/uploads/$id until status is "completed" (data.image) or "failed" (data.message)
```

A WebAssembly module goes the same way: `runr images push q.wasm --name q-interpreter:1.0`.

## Resume and verification

- Chunks are written at their offset: they may arrive in any order and be sent again. The
  receiver merges the byte ranges it has; `uploaded_bytes` is the part from byte 0 without a gap —
  where a client goes on after a reload or a broken connection (the console and `runr images push`
  do this by themselves: `push` finds the unfinished upload of the same file by name, region, size
  and SHA-256).
- `complete` is refused until every byte is there (`code: upload_incomplete`, with `uploaded_bytes`).
- The receiver checks the size and the SHA-256 the upload declared. A mismatch reopens the upload
  with nothing received (status `ready`, the message names both digests): send the file again.
- The registry checks every blob against its digest when it is pushed, so a damaged layer fails
  the push; afterwards the receiver reads the tag back and requires the digest of what it pushed.
- An upload not completed within `RUNR_IMAGE_UPLOAD_TTL_HOURS` (24) is aborted and its receiver
  removed (hourly).

## Using uploaded images

| Where | How | Reference |
|---|---|---|
| CaaS project | create form, step 3: **Uploaded image** — the region's container images; or `config.image` in `POST /api/projects` | `image_ref`: `<registry>/runr-uploads/<owner>/<repository>@sha256:…`, pinned by digest |
| Runtime version | `versions: [{version: "5.2", upload: "bash-airgap:5.2"}]`; Settings → Runtimes → **Version from an uploaded image** | resolved per region to the owner's upload of that name, by digest ([Runtimes](runtimes.md#versions)) |
| wasm-exec function | a FaaS file `wasm.upload` with one line, `q-interpreter:1.0` | the module is fetched into the build instead of compiling ([WebAssembly](wasm.md#wasm-exec)) |
| WebAssembly runtime on an uploaded interpreter | a platform runtime whose version is `upload:`, built in `build.image` | the module arrives in the build as `RUNR_WASM_MODULE` |

References into `runr-uploads/` of the region registry are checked: a CaaS project may run an
uploaded image of its own organization in its own region only, and registering a runtime with an
`upload:` version requires that image in every region the runtime is offered in (platform runtimes
name platform uploads, custom runtimes their organization's). A deploy or a build in a region
without the image fails with a message naming the region.

## Supply chain and data residency

- **Scan and signature.** When an upload of a container image finishes, the region's supply-chain
  pipeline runs on it (report subject `uploaded_image`, kind `uploaded`): SBOM, SBOM referrers,
  vulnerability scan, signature and attestations as the region's settings define — the full
  pipeline of a build, since the image is in RunR's own registry. Settings → Images shows the scan;
  re-scans follow the region's interval. A CaaS version that runs an uploaded image gets its own
  report of the same kind, so the policy gate (and *Verify the signature on deploy*) applies to it
  like to a build. The admission policy's `runr-signed-images` covers `<registry>/runr-uploads/**`.
  WebAssembly modules are not scanned (no container filesystem).
- **Data residency.** Starting an upload checks the organization's residency policy for the region
  (action `image.upload`); a refusal answers 422 with `code: region_not_allowed`.

## Quota, replacing and deleting

- **Quota.** An organization (the install, when single-tenant) stores up to `RUNR_IMAGES_QUOTA_GB`
  (50 GiB) of uploaded images over every region: the size of its images plus the declared size of
  uploads still running. An upload that does not fit is refused (`code: quota_exceeded`); replacing
  an image of the same name gives its size back first. Settings → Images and `GET /api/images/quota`
  show the use.
- **Replacing.** Uploading the same `repository:tag` into the same region again replaces the image;
  the manifest it replaced is removed from the registry unless another tag of the owner points at
  the same digest.
- **Deleting.** `DELETE /api/images/{id}` (Settings → Images, `runr images rm`) removes the record
  and the manifest in the region registry (`RegistryImageLifecycleService`, as the build retention
  removes old builds; Zot frees the blobs with its garbage collection). It is refused (409,
  `code: image_in_use`) while a CaaS project or a runtime version still uses the image — the answer
  names them.

## Air-gapped regions

1. On a machine that reaches the image's registry: `docker pull myapp:1.0 && docker save myapp:1.0 -o myapp.tar`
   (or `skopeo copy --all docker://… oci-archive:myapp.tar` for every platform).
2. Bring the file to a machine that reaches the RunR console, and upload it (console, CLI or API).
3. The region pulls nothing from outside: the receiver image itself must be in a registry the
   region reaches — mirror `ghcr.io/brf-tech/image-upload:1.0.0` into the region registry or your own
   mirror and set `RUNR_IMAGE_UPLOAD_IMAGE` (Helm `images.uploadImage`).
4. Run it: a CaaS project on the uploaded image, or a runtime version / wasm-exec function on it.

## Permissions

| Permission | Grants | Built-in roles |
|---|---|---|
| `images.view` | the organization's uploaded images, their scans, the quota; the pickers in the CaaS form and the runtime editor | Owner, Admin, Developer, Viewer |
| `images.upload` | start, resume, complete and abort uploads | Owner, Admin, Developer |
| `images.delete` | delete uploaded images (and their manifests) | Owner, Admin, Developer |

Using an uploaded image in a project or a runtime needs nothing beyond changing that project or
runtime ([Roles and permissions](permissions.md#images)).

## Audit

| Action | When |
|---|---|
| `image.upload.start` | `POST /api/images/uploads` (region, file name, size, format, whether a SHA-256 was given) |
| `image.upload.complete` | `POST …/complete` |
| `image.upload.abort` | `DELETE /api/images/uploads/{id}` |
| `image.delete` | `DELETE /api/images/{id}` (whether the manifest left the registry, what still used it when refused) |
| `image.upload.pushed` | the region pushed the image: name, digest, kind, format, size, the digest it replaced (recorded by `ImageUploadService`) |
| `image.upload.failed` | the push failed, with the region's reason |

Chunks are not audited (one event per upload is enough); the upload's start, completion and result are.

## API

All under `/api`, authenticated with a session or an API token; rules in
`api/config/route_permissions.php`.

| Method & path | Permission | Purpose |
|---|---|---|
| `GET /images?knative_region_id=&kind=image\|wasm\|spin` | images.view | `{data: UploadedImage[], quota: {limit_bytes, used_bytes, images}}` |
| `GET /images/quota` | images.view | `{limit_bytes, used_bytes, images}` |
| `GET /images/{id}` | images.view | `{data: UploadedImage}` |
| `DELETE /images/{id}` | images.delete | `{deleted, registry_removed, id, name}`; 409 `image_in_use` with `usages: {projects, runtimes}` |
| `GET /images/uploads` | images.upload | the open uploads |
| `POST /images/uploads` | images.upload | `{knative_region_id, filename, size, sha256?, name?, format?: auto\|docker\|oci\|wasm}` → 201 `{data: ImageUpload}`; 422 `registry_unavailable` / `quota_exceeded` / `region_not_allowed`, 409 `upload_open` |
| `GET /images/uploads/{id}` | images.upload | `{data: ImageUpload}` (status `preparing`, `ready`, `verifying`, `pushing`, `completed` with `image`, `failed` with `message`, `aborted`) |
| `PUT /images/uploads/{id}/chunk?offset=N` | images.upload | raw body (`application/octet-stream`, at most `chunk_size`) → `{uploaded_bytes, size_bytes}`; 409 `upload_not_ready` |
| `POST /images/uploads/{id}/complete` | images.upload | 202 `{data: ImageUpload}`; 422 `upload_incomplete` |
| `DELETE /images/uploads/{id}` | images.upload | 204 |

An uploaded image:

```json
{
  "id": "01JB…", "name": "myapp:1.0", "repository": "myapp", "tag": "1.0",
  "knative_region_id": "01JA…", "region_name": "eu-1",
  "image_ref": "10.43.0.5:5000/runr-uploads/platform/myapp@sha256:…",
  "tag_ref": "10.43.0.5:5000/runr-uploads/platform/myapp:1.0",
  "digest": "sha256:…", "media_type": "application/vnd.oci.image.index.v1+json",
  "kind": "image", "source_format": "oci", "size_bytes": 52428800, "archive_bytes": 60000000,
  "platforms": ["linux/amd64", "linux/arm64"], "layers": 4, "source_filename": "myapp.tar",
  "note": null, "security": {"status": "completed", "scan_status": "ok", "counts": {"critical": 0, "high": 2}},
  "created_at": "2026-10-11T08:00:00Z"
}
```

## Configuration

| Variable (Helm value) | Default | Meaning |
|---|---|---|
| `RUNR_IMAGE_UPLOAD_IMAGE` (`images.uploadImage`) | `ghcr.io/brf-tech/image-upload:1.0.0` | the receiver image (`runtime/ci.sh image-upload [--push]`) |
| `RUNR_IMAGES_QUOTA_GB` (`images.quotaGb`) | `50` | storage per organization over every region, `0` = unlimited |
| `RUNR_IMAGE_UPLOAD_MAX_GB` (`images.maxUploadGb`) | `20` | largest file of one upload, in GiB |
| `RUNR_IMAGE_UPLOAD_TTL_HOURS` (`images.uploadTtlHours`) | `24` | an upload not completed within this time is aborted |
| `RUNR_IMAGE_UPLOAD_CHUNK_BYTES` (via `extraEnv`) | `8388608` | chunk size the clients use, at least 1 MiB (the API takes request bodies up to 64 MiB) |
| `RUNR_IMAGE_UPLOAD_PUSH_TIMEOUT` (via `extraEnv`) | `3600` | seconds the push may take after the verification, at least 60 |
| `RUNR_IMAGE_UPLOAD_CPU_REQUEST` / `_MEMORY_REQUEST` / `_CPU_LIMIT` / `_MEMORY_LIMIT` (via `extraEnv`) | `100m` / `128Mi` / `2` / `1Gi` | the receiver container |

The `images.*` Helm values are empty by default, which keeps the defaults above
(`api/config/images.php`).

The receiver's emptyDir (and ephemeral-storage request) is twice the file plus 512 MiB — the file
and, for OCI layouts and gzip'ed archives, its extracted copy; a `.wasm` needs the file only. Uploads
need the region's internal registry (`registry_mode: internal`, status ready).

## Data model

| Table | Schema | Holds |
|---|---|---|
| `uploaded_images` | central | owner (`owner_key`: tenant id or `platform`), region, repository, tag, registry repository, digest, media type, kind, format, sizes, platforms, layers, source file, uploader; unique per owner, region, repository and tag |
| `image_uploads` | central | one upload: owner, region, name, file, declared size and SHA-256, bytes received, chunk size, status, receiver, message, the resulting image |

The supply-chain reports of uploaded images are in `image_security_reports` of the central schema.

## Testing

- **Receiver**: `cd runtime/image-upload && go vet ./... && go test ./...` — chunks in any order and
  the contiguous prefix, refused chunks, SHA-256 mismatch, failed pushes; pushes into an in-memory
  OCI registry of `docker save` archives (gzip'ed, several images), OCI layouts, multi-platform
  indexes (complete and partial), `.wasm` modules and the fetch of a module.
- **API**: `cd api && php artisan test tests/Feature/Images tests/Unit/Images tests/Feature/Runtimes/RuntimeUploadSourceTest.php tests/Feature/Wasm/WasmExecTest.php tests/Unit/Wasm/WagiSpinManifestTest.php`
  — the receiver Job and Service, chunk forwarding and resume, complete → push → uploaded image,
  replacing a name, SHA-256 mismatch, failed pushes, abort / expiry, registry / quota / residency
  checks, permissions; listing, deletion with the manifest and the in-use refusal, owner scoping,
  CaaS validation and the supply-chain kind; the runtime `upload` source; wasm-exec builds.
- **CLI**: `cd cli && go test ./internal/cmd ./pkg/runr -run 'Images|NameFromFilename|FindImage|PushImage'`
  against the fake API.
- **UI**: `cd vue && npx vitest run tests/modules/images` and `npx playwright test e2e/images.spec.ts --project=mock`.
- **End to end**: `KIND_LOCK=/tmp/runr-kind.lock bash runtime/ci.sh test-images [--k8s=X.Y.Z] [--no-cleanup]`
  uploads a `docker save` archive and `q.wasm` into a Kind region, runs a CaaS project from the
  upload, a wasm-exec function in C and a Q (Wasm) function, and deletes everything again.
