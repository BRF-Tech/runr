# Executor development

An **executor** (a runtime) is what RunR runs a function's code on: a manifest, `runtime.yaml`,
and per version a runtime image. Python, Node.js, Go, PHP, PyTorch, TensorFlow and the
WebAssembly runtimes are executors of exactly this kind; one for Lua, Zig, Haskell, COBOL or a
language you wrote yourself goes through the same code paths, the same build pipeline and the same
sandbox. This guide walks through building one, testing it, publishing it in a repository of your
own — public or private — and connecting that repository to a RunR installation, where it then
lives the same lifecycle as the built-ins: end of life, deprecation, sunset and patches.

The fastest start is the sample repository
**[BRF-Tech/runr-sample-custom-executor](https://github.com/BRF-Tech/runr-sample-custom-executor)**:
fork it (or use it as a template). It has two executors — Lua, interpreted, in persistent mode
through the universal wrapper, and Zig, compiled, whose binary serves HTTP itself — with their
Dockerfiles, a local contract test, GitHub Actions that test and publish the images and a signed
catalog, and a step-by-step README. This page is the reference behind it; the manifest fields are
specified in [runtimes.md](runtimes.md#manifest-reference).

- [How RunR runs your executor](#how-runr-runs-your-executor)
- [The contract](#the-contract)
- [The manifest](#the-manifest)
- [The runtime image and versions](#the-runtime-image-and-versions)
- [The wrapper or your own server](#the-wrapper-or-your-own-server)
- [Dependencies](#dependencies)
- [Compiled languages](#compiled-languages)
- [Test locally](#test-locally)
- [Publish a repository or a catalog](#publish-a-repository-or-a-catalog)
- [Connect it to RunR](#connect-it-to-runr)
- [Private sources](#private-sources)
- [Signing](#signing)
- [Lifecycle](#lifecycle)
- [Patches for your own executors](#patches-for-your-own-executors)
- [Checklist](#checklist)

## How RunR runs your executor

1. **Save.** Each save of a function is a version. When the executor has a `build` step, or a FaaS
   project contains its dependency file, RunR builds the version: it generates a two-stage
   Dockerfile from the manifest and runs it with Kaniko in the project's region.
2. **Deploy.** RunR renders a Knative Service (HTTP and queue triggers) or a CronJob (schedule
   trigger). Without a build the code is mounted at `/app/code` into the version's image; with a
   build the image contains it.
3. **Invoke.** In `http` mode your process serves requests on `$PORT`. In `exec` and `persistent`
   mode RunR's universal wrapper `runr-wrapper` is the entrypoint and hands every invocation to
   your program over stdin / stdout.

Pods run under the region's sandbox (runc, gVisor or Kata); your manifest only declares which
sandboxes it works under. Details: [How a function runs](runtimes.md#how-a-function-runs).

## The contract

Whatever the mode, your program sees the same environment
([Container environment](runtimes.md#container-environment)): `RUNR_MODE`, `RUNR_TRIGGER`
(`http`, `queue`, `schedule`), `RUNR_HANDLER`, `RUNR_FILENAME`, `RUNR_CODE_DIR` (`/app/code`),
`RUNR_RUNTIME`, `RUNR_RUNTIME_VERSION`, `RUNR_TIMEOUT`, `PORT`, plus `run.env` and the project's
variables. It must answer three triggers:

| Trigger | What arrives |
|---|---|
| `http` | the client's request (http mode), or the [request envelope](runtimes.md#request-envelope) (wrapper modes) |
| `queue` | a CloudEvent in binary mode: `ce-specversion: 1.0`, `ce-type: dev.runr.queue.message`, `ce-source`, `ce-id`, the stream entry's fields as a JSON body; a 2xx answer acknowledges it |
| `schedule` | one tick: `ce-type: dev.runr.schedule.tick`, body `{"trigger":"schedule"}` — through the wrapper (`run.schedule: invoke`), or your image handles `RUNR_MODE=schedule` itself (`native`) |

What it answers: a JSON object with a numeric `statusCode` is a response envelope (`headers`,
`body`, `isBase64Encoded`); anything else is the body with status 200
([Responses](runtimes.md#responses)). Timeouts, body limits (10 MiB), logs (stderr, and in
persistent mode every non-JSON stdout line) and graceful shutdown are the wrapper's; an http-mode
server handles them itself ([Timeouts, limits and logs](runtimes.md#timeouts-limits-and-logs)).

## The manifest

`runtimes/<id>/runtime.yaml`, the directory named like the id:

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/BRF-Tech/runr/main/api/resources/runtimes/runtime.schema.json
apiVersion: runr.sh/v1
kind: Runtime
id: acme-lua                 # one namespace on the platform: pick a distinctive id
name: Lua (Acme)
language: lua                # Monaco editor language
extensions: [lua]            # the first is the entry file's
versions:
  - version: "5.4"           # quoted: a string
    image: ghcr.io/acme/runr/exec-lua:5.4
    default: true
    eol: "2028-05-01"
handler:
  file: main
  function: handler
  pattern: 'function\s+M\.{handler}\s*\('
template:
  files:
    "{file}": |
      local M = {}
      function M.{handler}(event, context) return "Hello from Lua" end
      return M
run:
  mode: persistent           # http | exec | persistent
  command: ["lua", "/opt/runr/runner.lua"]
  workers: 2
```

Every field, its default and its rules: [Manifest reference](runtimes.md#manifest-reference).
Unknown fields are refused, dates and versions are quoted strings, placeholders (`{version}`,
`{file}`, `{handler}`, `{image}`, …) are listed in [Placeholders](runtimes.md#placeholders). A
repository or catalog may also use `versionDiscovery` (versions from your image tags) and `@file`
references in `build.files` (a file next to the manifest, inlined when the source is read).

**Ids.** Runtime ids are one namespace across the platform, because projects store them: the
built-in ids (`python`, `go`, …) are RunR's, and an id another source or organization holds already
is skipped with a problem naming it ([Runtime ids](runtimes.md#runtime-ids)). Prefix yours
(`acme-lua`, `sample-lua`).

## The runtime image and versions

Each version names where its image comes from — exactly one of:

| Field | What it means | When to use it |
|---|---|---|
| `image` | an image you publish | the usual case: your CI builds, tests and pushes it |
| `dockerfile` | RunR builds the image in each region from the files next to the manifest (their total at most 900 KB, 50 files) | small images you do not want to publish |
| `git` | RunR builds it in each region from a Git repository (`url`, `ref`, `path`, `dockerfile`) | a larger build context |
| `upload` | an image uploaded to RunR ([images.md](images.md)) | air-gapped regions |

An image should be small, start fast, pin its base image and contain the interpreter or the
toolchain plus the files your mode needs (a runner script, a server). Use immutable tags for
patches (`5.4.7-r1`) next to the floating version tag (`5.4`) — see
[Patches](#patches-for-your-own-executors). Versions up to 50; removing one from the manifest
retires it (its projects run on the default version). With `versionDiscovery`, RunR lists the clean
`X.Y[.Z]` tags of a repository instead of a static list
([versions](runtimes.md#versions)).

## The wrapper or your own server

**Universal wrapper (`exec`, `persistent`).** Nothing to write but the code that reads a request
and prints an answer:

- `exec`: one process per invocation; stdin is the request envelope as JSON (`run.input: json`) or
  the raw body (`raw`), stdout the answer, per-invocation `RUNR_METHOD`, `RUNR_PATH`, `RUNR_HEADER_*`.
  Cheap for static binaries and shell scripts.
- `persistent`: `run.workers` long-lived processes; each request is one JSON line on stdin, the
  answer one JSON line on stdout. The right choice for an interpreter: load the function once, call
  it per request — what the sample's `runner.lua` does.

RunR puts the wrapper into code-mounted functions with an init container and bakes it into built
images; your image needs no copy of it. Reference: [The universal wrapper](runtimes.md#the-universal-wrapper).

**Your own server (`http`).** The process listens on `$PORT` and answers every path, as the built-in
Python, Node.js and PHP images do — and the sample's Zig binary. Schedule ticks reach it through
the wrapper (`run.schedule: invoke`, needs `run.command`) or your server handles
`RUNR_MODE=schedule` (`native`). With tracing or request replay on, RunR fronts it with the wrapper
as a proxy; you change nothing.

## Dependencies

```yaml
dependencies:
  file: function-dev-1.rockspec          # its presence in a FaaS project triggers a build
  template: |                            # starter content for new FaaS projects
    package = "function"
    ...
  image: "{image}"                       # where install runs (default: the version image)
  install: "cd /build/code && luarocks make --tree /build/rocks function-dev-1.rockspec"
  copy:
    - {from: /build/rocks, to: /app/rocks}
  env:
    LUA_PATH: "/app/rocks/share/lua/5.4/?.lua;;"
```

The project's files are in `/build/code` during `install`; `copy` moves what it produced into the
final image, `env` sets what finds it. A compiled language whose package manager links everything
into the binary needs no `copy` ([Dependencies](runtimes.md#dependencies)).

## Compiled languages

A `build` section makes every saved version a build:

```yaml
build:
  image: "{image}"                       # the toolchain (default: the version image)
  command: "zig build -Doptimize=ReleaseSmall --prefix /build/out"
  runImage: alpine:3.23.8                # the final image, small
  source: false                          # no source code in the final image
  files:                                 # next to the project's code before the build
    build.zig: "@build.zig"              # @file: inlined from the runtime's directory
run:
  mode: http
  command: ["/app/bin/handler"]
```

Everything `command` writes to `/build/out` is copied to `/app` of `runImage`. A `{http, schedule}`
pair in `build.files` picks a file per trigger (the Go built-in's wrappers). Images with a registry
host are pulled directly; names without one go through the region registry's mirror. Details:
[Compiled languages](runtimes.md#compiled-languages).

## Test locally

1. **Validate.** Against a RunR you can reach: `runr runtimes validate -f runtimes/acme-lua/runtime.yaml
   [--files runtimes/acme-lua]` prints every problem with its JSON path (`@file` references are a
   catalog feature: inline them first, as the sample's `scripts/validate.sh --remote` does).
   Offline: `check-jsonschema --schemafile runtime.schema.json runtimes/*/runtime.yaml` with RunR's
   schema (`api/resources/runtimes/runtime.schema.json`), or `php artisan runtimes:validate` in an
   API container.
2. **Run the contract.** Start the image the way RunR does — the code at `/app/code`, the
   `RUNR_*` environment, the wrapper for exec and persistent mode (from
   `ghcr.io/brf-tech/runr/wrapper:1.1.0`) — and call it: an HTTP request, a schedule tick
   (`RUNR_TRIGGER=schedule`, exit 0), a queue CloudEvent (binary mode headers, 2xx). The sample's
   `scripts/contract-test.sh <id>` does all three for every version, also through a function build
   for a compiled executor.
3. **Run a function.** `runr dev` runs a project of your executor in local Docker the way the
   cluster does ([runr dev](cli.md#runtime-plugins-in-runr-dev)).
4. **Deploy a test project.** Once connected (below), Build & Run → Runtimes → **Test deploy**
   (`POST /api/runtimes/{id}/test-deploy`) creates a project from your starter template in a region
   and deploys it.

## Publish a repository or a catalog

RunR reads executors from a **runtime source** ([Runtime sources](runtimes.md#runtime-sources)):

- **A Git repository** — the simplest: push `runtimes/<id>/runtime.yaml` (and for a `dockerfile:`
  version its build context next to it). RunR reads it at a branch or tag; every new commit is a new
  revision.
- **A signed OCI catalog** — one artifact with every manifest, signed with cosign: what RunR's own
  built-ins come from. The catalog is read with one HEAD per check, verified before it is trusted,
  and can carry patches. Format: [OCI catalogs](runtimes.md#oci-catalogs). The sample's
  `scripts/build-catalog.sh` and `scripts/push-signed.sh` build it from the repository and push it
  with `oras` and `cosign`; its publish workflow does it on every push to `main`.

Publish the images first, then the manifests that name them (a catalog in the same workflow after
the image jobs). Images and catalog may be public or private ([Private sources](#private-sources)).

## Connect it to RunR

**Who.** A platform administrator (`runtimes.manage`) connects a source for everyone: its runtimes
are platform runtimes. A member with `runtimes.use_custom` connects one for the organization while
the platform allows custom runtimes (Administration → Regions & settings → Custom Runtimes): its
runtimes are the organization's only.

**Console.** Build & Run → Runtimes → **Sources** → **Add source**: kind (Git or OCI catalog), name,
URL, ref and path (Git), public key (the cosign key of a catalog or patch feed), patch feed,
credentials, interval. **Test connection** shows the revision, each runtime and every problem
before you save; **Sync now** reads it again later. The source's detail lists its problems with the
file and JSON path, its runtimes (each with an enabled toggle) and their lifecycle.

**CLI:**

```bash
# a public Git repository
runr runtimes sources add --name acme --kind git \
  --url https://github.com/acme/runr-executors.git --ref main --path runtimes
# a signed OCI catalog
runr runtimes sources add --name acme-catalog --kind oci-catalog \
  --url ghcr.io/acme/runr/catalog:stable --public-key-file cosign.pub
runr runtimes sources test --kind git --url https://github.com/acme/runr-executors.git
runr runtimes sources sync acme
runr runtimes sources get acme
```

**API:** `POST /api/runtime-sources` ([API](runtimes.md#api)); **MCP:** `runtime_source_connect`;
**Terraform:** the `runr_runtime_source` resource.

A read validates every manifest strictly; a bad one is a problem and does not stop the others
(status `partial`); a source that cannot be read keeps its runtimes as they were.

## Private sources

| What is private | What the source needs |
|---|---|
| The Git repository | an HTTPS token (`--git-token-env GITHUB_TOKEN`, a fine-grained token with read access to the repository is enough) or an SSH deploy key (`--ssh-key-file deploy_key`, `--known-hosts-file known_hosts` to pin the host key) |
| The OCI catalog | registry username and password / token (`--registry-username`, `--registry-password-env`) |
| The runtime images | the same registry credentials, with `--registry-host` naming the registry of the images (required for a Git source) |

RunR keeps the credentials encrypted, never shows them again, and masks them in every error. It
uses the registry credentials for every pull of the source's images on that registry: the function
pods (an image pull Secret it applies into the project namespace), the function and runtime image
builds (Kaniko's docker config), sandboxes, the node pre-pull and version discovery
([Private sources](runtimes.md#private-sources)). On GitHub, a classic token with `read:packages`
or a fine-grained one with package read access works for ghcr.io.

## Signing

Sign a catalog (and a patch feed) so RunR trusts only what you published:

```bash
cosign generate-key-pair                        # cosign.key (a secret) and cosign.pub
oras push ghcr.io/acme/runr/catalog:stable-candidate \
  --artifact-type application/vnd.runr.runtime-catalog.v1+json \
  catalog.json:application/vnd.runr.runtime-catalog.v1+json
cosign sign --key cosign.key --tlog-upload=false ghcr.io/acme/runr/catalog@sha256:<digest>
cosign verify --key cosign.pub --insecure-ignore-tlog ghcr.io/acme/runr/catalog@sha256:<digest>
oras tag ghcr.io/acme/runr/catalog@sha256:<digest> stable
```

Push to a candidate tag, sign by digest, verify, then move the tag, so `stable` only ever names a
signed catalog. Give RunR `cosign.pub` as the source's public key. A source with a key refuses a
catalog that does not verify (status `invalid_signature`) and keeps the last good one; a catalog
without a key is read unsigned but carries no patches.

## Lifecycle

Your executors get the same lifecycle as the built-ins ([Lifecycle](runtimes.md#lifecycle)):

```yaml
deprecated: "2027-01-01"        # the whole runtime: a badge, a notice; still usable (or true)
sunset: "2027-12-31"            # after it: no new projects, no saves or deploys
replacement: acme-lua-next      # what RunR suggests instead
notice: "acme-lua moves to acme-lua-next; see https://github.com/acme/runr-executors"
versions:
  - version: "5.3"
    image: ghcr.io/acme/runr/exec-lua:5.3
    eol: "2028-05-01"           # end of life of the version
    deprecated: "2026-12-01"
    sunset: "2027-06-30"
```

- A version **ends** at the earlier of `eol` and `sunset`: it leaves the picker and its projects
  can no longer be saved or deployed until they move; running deployments are never stopped.
- The owners of its projects hear about it 13, 4 and 1 week(s) before (by mail the last week); a
  deprecation is announced once.
- With `versionDiscovery`, set the lifecycle of discovered versions by label in
  `versionDiscovery.lifecycle`, and an `eol` product for endoflife.date.
- **Removing an executor** from your repository or catalog does not break anything: RunR marks it
  **orphaned** — no new projects, its projects keep running and deploying for the grace period (90
  days by default), which becomes its sunset with the same reminders. Put it back and it is no
  longer orphaned. Prefer `deprecated` + `sunset` with a `replacement`: your users get the dates in
  advance.

## Patches for your own executors

A fixed image of a version — a CVE in the interpreter or the base image — is a **patch**: publish it
under an immutable tag (`5.4.7-r1`, `-r2` for a rebuild of the same upstream release), move the
floating tag (`5.4`) to it, and announce it in a signed patch entry:

```json
{
  "id": "acme-lua-5.4-5.4.7-r1", "runtime": "acme-lua", "version": "5.4", "tag": "5.4.7-r1",
  "image": "ghcr.io/acme/runr/exec-lua", "digest": "sha256:…",
  "level": "critical",
  "reason": {"en": "Fixes CVE-2026-1234 in Lua's string library", "tr": "Lua'nın string kütüphanesindeki CVE-2026-1234'ü düzeltir"},
  "cves": ["CVE-2026-1234"], "url": "https://github.com/acme/runr-executors/security/advisories/1",
  "upstream": "Lua 5.4.7", "published_at": "2026-11-07T10:00:00Z", "deadline_at": "2026-11-21T10:00:00Z"
}
```

Ship it in your signed catalog's `patches`, or in a patch feed of your own (an OCI artifact of
media type `application/vnd.runr.patch-feed.v1+json`, schema in BRF-Tech/runr-runtimes
`schema/patch-feed.v1.json`) whose reference you set on the source — both verified with the
source's key. RunR then treats it like a patch of a built-in
([Runtime patches](runtimes.md#runtime-patches)): workloads run it by digest, projects show it with
its reason, `security` and higher notify, `critical` and `zero-day` have deadlines (the feed's, else
14 / 3 days) after which RunR migrates the projects behind it and rolls a failed migration back.
Patches only apply to the runtimes your source provides; keep the last five patch tags of each
version in your registry (pinnable).

## Checklist

- [ ] distinctive runtime id, the directory named like it
- [ ] versions quoted, one default, an `eol` on each
- [ ] the image pins its base, is small, runs the contract (HTTP, schedule, queue) — contract test green
- [ ] a starter template that works as it is, a `handler.pattern` that matches it
- [ ] `dependencies` if the language has a package manager; `build` for a compiled language
- [ ] `triggers` / `sandbox` narrowed if the executor cannot do all of them
- [ ] validated with RunR's schema; the repository or catalog builds in CI
- [ ] images published before the manifests that name them
- [ ] a signed catalog (and patch feed) with the public key given to RunR, or a Git source
- [ ] private: a read-only token or deploy key, registry credentials with read access only
- [ ] deprecations announced with `deprecated`, `sunset` and `replacement`, not by deleting a runtime
