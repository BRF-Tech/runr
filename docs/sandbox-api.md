# Sandbox API

The Sandbox API runs untrusted code — the code an AI agent writes, a user's notebook cell, a
build step of a CI system — in an isolated sandbox on your own cluster. A sandbox is a pod that
lives for minutes or hours: you create it, run commands and code in it, move files in and out,
start background processes, and remove it again. It runs under the strongest isolation the region
offers (a Kata Containers micro-VM or gVisor), without network access unless you allow it, as an
unprivileged user with its own CPU, memory, disk and process limits.

- [Concepts](#concepts)
- [Security model](#security-model)
- [Quick start](#quick-start)
- [API reference](#api-reference)
  - [Errors](#errors) · [The sandbox object](#the-sandbox-object) · [Lifecycle](#lifecycle) ·
    [Exec](#exec) · [Terminal](#terminal) · [Code interpreter](#code-interpreter) ·
    [Background processes](#background-processes) · [Files](#files) ·
    [Settings, pools, quotas, metrics](#settings-pools-quotas-metrics)
- [SDKs](#sdks)
- [Example: an AI agent code interpreter](#example-an-ai-agent-code-interpreter)
- [MCP tools](#mcp-tools)
- [Quotas and usage](#quotas-and-usage)
- [Warm pool](#warm-pool)
- [Permissions and API tokens](#permissions-and-api-tokens)
- [Audit](#audit)
- [UI](#ui)
- [Configuration](#configuration)
- [Operations](#operations)
- [Testing](#testing)

## Concepts

| Term | Meaning |
|---|---|
| **Sandbox** | one pod (`sbx-<id>`, a plain Pod with `restartPolicy: Never`, not a Knative Service) running a runtime image — `python`, `nodejs`, any runtime of the [catalog](runtimes.md) — or any image you name. PID 1 is a `/bin/sh` sleep loop; everything else runs through the API. |
| **Sandbox class** | the RuntimeClass the pod runs with: `kata-fc`, `kata-clh`, `kata-dragonball`, `kata-qemu` (a VM per sandbox with its own kernel), `gvisor` (a user-space kernel), or `runc` (the host kernel; only where a region allows it). |
| **Region** | a cluster RunR manages. The sandbox classes a region offers are the sandbox runtimes (gVisor, Kata Containers) installed on it in Administration → Regions & settings → Knative Regions → (region) → Isolation — an infrastructure task that needs `sandbox.manage`, not a Sandbox API permission; its Sandbox API settings narrow them. |
| **Organization** | a tenant on a multi-tenant installation, the whole installation otherwise. Every organization's sandboxes live in a namespace of their own per region: `runr-sbx-<organization>` (`runr-sbx-default` on a single-tenant installation). |
| **TTL** | how long a sandbox may live (`ttl_seconds`, default 900). Extending it sets a new expiry from now, up to the region's maximum lifetime counted from the start. |
| **Idle timeout** | a running sandbox without activity (commands, files, terminal, keepalive) for `idle_timeout` seconds (default 600; 0 = off) is removed. |
| **Warm pool** | pre-started generic sandboxes of one runtime/version and size, handed to a create request with the same spec. |
| **Quota** | per organization: sandboxes at once, their CPU and memory together, sandbox seconds per month, warm pool size, allowed classes. |

States: `pending` (the pod is starting) → `running` ⇄ `paused` → `terminating` → `terminated`,
or `failed` when the pod could not start or ended on its own. `end_reason` says why a sandbox
ended: `deleted`, `ttl`, `idle`, `quota`, `failed` or `lost` (the pod disappeared).

A start fails (`failed`, `error` says why) when the pod reports an error it does not recover from
(an image pull error, a container that cannot be created), when the container runtime failed to
create the pod sandbox three times (kubelet events `FailedCreatePodSandBox`, read every 5 s: a Kata
VM that does not boot, a runtime handler the node lacks; the error is the runtime's), or when it
is still not running `start_timeout` × 3 after the request (the background start; the error names
the pod's last warning event). A failed sandbox no longer counts towards the organization's
quota.

## Security model

Every layer holds on its own; together they keep code in a sandbox away from the cluster, the
other sandboxes and RunR.

| Layer | Mechanism |
|---|---|
| Kernel | **Kata Containers** (Firecracker, Cloud Hypervisor, Dragonball, QEMU): the sandbox has a kernel of its own in a micro-VM. **gVisor**: system calls are served by a user-space kernel. A sandbox gets the region's strongest allowed class — Kata (fc > clh > dragonball > qemu) before gVisor. **runc** shares the host kernel and is offered only where the region's settings allow it explicitly, never as a silent fallback: a region without Kata or gVisor refuses sandboxes until an admin opts in. |
| Namespace | one per organization and region, created on first use. Pod Security Admission `restricted` is enforced on it, so nothing in it can run privileged, as root, with host namespaces, host paths or extra capabilities — whoever creates the pod. |
| Network | NetworkPolicy `runr-sandbox-default-deny` selects every pod of the namespace with no ingress and no egress. `network: "none"` (the default) stays at that. An egress allowlist adds a policy for that one pod: DNS to the cluster DNS plus the listed addresses / CIDRs and ports; host names are resolved by the API when the network is applied. The cloud metadata ranges (`169.254.0.0/16`, `fd00:ec2::/32`) are cut out of every allowed block and can never be allowed. Inbound traffic is always denied. NetworkPolicies need a CNI that enforces them (Calico, Cilium, kube-router — k3s' default —, kindnet, …). |
| Identity | `automountServiceAccountToken: false`, `enableServiceLinks: false`: no Kubernetes credentials and no service addresses in the pod. |
| User | `runAsNonRoot` with a UID of its own per sandbox (from `100000`–`165535`; `runAsGroup` and `fsGroup` the same), so per-UID kernel limits apply per sandbox even under runc. |
| Privileges | `allowPrivilegeEscalation: false`, capabilities `drop: [ALL]`, seccomp `RuntimeDefault`, `privileged: false`, no host network / PID / IPC. |
| File system | read-only root file system (default; `read_only_root: false` turns it off). Writable: `/workspace` (working directory), `/output`, `/tmp`, `/home/sandbox` — `emptyDir` volumes limited to the sandbox's disk. |
| Resources | CPU, memory and ephemeral storage with requests = limits; `pids_limit` (default 256) as RLIMIT_NPROC on every process the API starts. |
| Lifetime | the reaper removes sandboxes at their TTL or idle timeout; independently, the pod's `activeDeadlineSeconds` (the region's maximum lifetime + warm time + grace) makes the kubelet stop a pod RunR never came back to. |
| Secrets | the request's `env` is stored encrypted at rest (`sandboxes.env`, see [security](security.md)) and reaches the pod through the Kubernetes Secret `sbx-<id>-env` and `secretKeyRef` entries — never as a value in the pod spec, the audit log or an API response (`env_keys` lists the names only). A claimed warm pod, whose spec predates the request, gets the same values as `/home/sandbox/.runr/env.sh` (mode 0600) over the exec channel, sourced by every command. The Secret is deleted with the sandbox; the reaper removes orphaned ones. |
| Data residency | an organization with a residency policy ([supply chain](supply-chain.md#data-residency)) creates sandboxes and warm pools only in its allowed regions; elsewhere the request answers 422 with `code: region_not_allowed` and is recorded as `residency.denied`. The default region is the first allowed one, and a pool in a region the policy no longer allows is drained. |
| Caching | every Sandbox API response is sent with `Cache-Control: no-store`, so command output and file contents stay out of the API's response cache, proxies and browsers. |
| Access | every endpoint is authenticated (session or API token) and scoped to the organization; another organization's sandbox answers 404. Kubeconfigs stay on the RunR side: commands reach the pod through `kubectl exec` from the API, with the region kubeconfig in a 0600 temp file deleted afterwards. |

## Quick start

```bash
API=https://runr.example.com/api
TOKEN=...   # Administration → API Tokens

# Create a Python sandbox (waits until it runs)
SBX=$(curl -sS -X POST "$API/sandboxes" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"runtime": "python", "ttl_seconds": 600}' | jq -r .data.id)

# Run a command
curl -sS -X POST "$API/sandboxes/$SBX/exec" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"cmd": ["python3", "-c", "print(6 * 7)"]}'
# {"data":{"exit_code":0,"stdout":"42\n","stderr":"","timed_out":false,"duration_ms":…,"truncated":false}}

# Stream the output of a longer command
curl -sS -N -X POST "$API/sandboxes/$SBX/exec" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: text/event-stream' \
  -d '{"cmd": "for i in 1 2 3; do echo step $i; sleep 1; done"}'

# Remove it
curl -sS -X DELETE "$API/sandboxes/$SBX" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

## API reference

All endpoints are under `/api`, authenticated with a session or an API token
(`Authorization: Bearer <token>`). On a multi-tenant installation `X-Tenant-ID: <id>` picks the
organization (the user's default organization otherwise). Send `Accept: application/json`.

### Errors

Errors are JSON: `{"message": "…", "reason": "…", "errors": {"field": ["…"]}}` (`errors` for
input problems).

| Status | `reason` | When |
|---|---|---|
| 401 | | not signed in / no valid token |
| 403 | — / `forbidden` | the ability is not granted / a platform-level call by someone who is not a platform administrator |
| 404 | `not_found`, `tenant_gone` | unknown sandbox (or another organization's), unknown file or process; the sandbox's organization no longer exists |
| 409 | `not_running`, `runtime_image_building`, `region_unavailable` | the sandbox is not in a state for the call (`status` says which); a runtime image is being built in the region; the sandbox's region is gone or has no kubeconfig |
| 413 | `file_too_large` | upload / download above the limit |
| 422 | `invalid`, `network_invalid`, `class_unavailable`, `region_unavailable`, `runtime_unavailable`, `quota_concurrent`, `quota_cpu`, `quota_memory`, `quota_seconds`, `quota_region`, `quota_capacity`, `quota_pool`, `file_op_failed`, `process_failed` | input, availability or quota refusals; organization quota refusals carry `limits` and `usage`; `quota_capacity`: the region's always-on capacity budget is used up |
| 422 | (`code: region_not_allowed`) | the organization's data residency policy does not allow the region |
| 429 | | rate limit (`Retry-After`) |
| 502 | `cluster_error`, `start_failed`, `files_failed` | the cluster refused the call, the sandbox could not start (`data` is the failed sandbox), or the create request's files could not be written |

### The sandbox object

```json
{
  "id": "01jb2x7k3m9q4r5s6t7v8w9xyz",
  "status": "running",
  "region": {"id": "01j…", "name": "eu-1"},
  "runtime": "python", "runtime_version": "3.14", "image": "ghcr.io/brf-tech/runr/exec-python:3.14",
  "sandbox_class": "kata-fc", "isolation": "kata",
  "resources": {"cpu": "500m", "cpu_m": 500, "memory_mb": 512, "disk_mb": 1024, "pids_limit": 256},
  "network": {"mode": "allowlist", "egress_allow": ["pypi.org:443"]},
  "read_only_root": true,
  "env_keys": ["OPENAI_API_KEY"],
  "metadata": {"agent": "planner"},
  "ttl_seconds": 900, "idle_timeout_seconds": 600,
  "expires_at": "2026-10-10T12:15:00+00:00", "idle_expires_at": "2026-10-10T12:10:00+00:00",
  "last_activity_at": "2026-10-10T12:00:00+00:00",
  "created_at": "2026-10-10T12:00:00+00:00", "started_at": "2026-10-10T12:00:01+00:00",
  "paused_at": null, "ended_at": null, "end_reason": null,
  "from_pool": true, "start_ms": 640,
  "error": null, "created_by": "1", "tenant_id": null,
  "urls": {"terminal_ws": "/ws/shell", "exec_ws": "/ws/sandbox-exec"}
}
```

`isolation` is the family of `sandbox_class` (`kata`, `gvisor`, `runc`). `start_ms` is the time from
the create request to the running sandbox (env and files written), `from_pool` whether it came
from a warm pool.

### Lifecycle

| Method & path | Ability | Purpose |
|---|---|---|
| `POST /sandboxes` | `sandboxes.create` | create; 201 running, 202 still starting (`wait: false`, or longer than `start_timeout`; poll `GET`), 502 could not start |
| `GET /sandboxes` | `sandboxes.view` | list: `status=active` (default: pending, running, paused) / `running` / `paused` / `pending` / `ended` / `all`, `region`, `runtime`, `metadata[key]=value`, `page`, `per_page` (≤ 200) → `{data, meta: {total, page, per_page}}`; platform admins outside an organization add `all_tenants=1` |
| `GET /sandboxes/{id}` | `sandboxes.view` | one sandbox |
| `DELETE /sandboxes/{id}` | `sandboxes.create` | remove (pod and egress policy); idempotent |
| `POST /sandboxes/{id}/timeout` | `sandboxes.create` | `{ttl_seconds}`: new expiry = now + ttl_seconds, at most the region's maximum lifetime from the start |
| `POST /sandboxes/{id}/keepalive` | `sandboxes.exec` | reset the idle timer |
| `POST /sandboxes/{id}/pause` | `sandboxes.exec` | freeze every process (SIGSTOP); commands answer 409 until resume; the TTL keeps running, the idle timer does not apply |
| `POST /sandboxes/{id}/resume` | `sandboxes.exec` | thaw (SIGCONT) |
| `PUT /sandboxes/{id}/network` | `sandboxes.create` | `{network}`: replace the network (host names resolved again) |
| `GET /sandboxes/{id}/logs` | `sandboxes.view` | activity log `{events: [{id, type, message, data, user_id, created_at}]}`; `pod=1` adds the pod's phase, conditions, Kubernetes events and container log |

Create body — every field is optional:

| Field | Default | Meaning |
|---|---|---|
| `runtime`, `version` | `python`, its default version | a runtime of the catalog (`GET /runtimes`), offered in the region |
| `image` | | any image instead of a runtime (`sandbox.allow_custom_images`); it needs `/bin/sh`, and `tar` for file transfer |
| `cpu` | `500m` | `"500m"`, `0.5`, `"1"` (50m up to the region's per-sandbox limit) |
| `memory` | 512 | MiB, or `"512Mi"`, `"1Gi"` (64 MiB up to the limit) |
| `disk` | 1024 | MiB or a quantity: ephemeral storage and the size of each writable directory |
| `ttl_seconds` | the region's default (900) | 10 up to the region's maximum (86400) |
| `idle_timeout` | 600 | seconds without activity; 0 = off |
| `network` | `"none"` | `"none"` or `{"egress_allow": ["10.0.0.0/8", "203.0.113.7", "pypi.org", "files.pythonhosted.org:443", "[2001:db8::1]:443"]}` — addresses, CIDRs or host names, each with an optional port (TCP and UDP); up to 50 entries |
| `env` | | `{"NAME": "value"}`: environment of every command (names `[A-Za-z_][A-Za-z0-9_]*`, up to 100) |
| `files` | | `[{"path": "/workspace/main.py", "content_base64": "…", "mode": "0644"}]`: written before the sandbox is returned (up to 200 files, 10 MiB together) |
| `region` | the first region with sandboxes enabled | region id or name |
| `sandbox_class` | `auto` | `auto` (the region's default, else the strongest allowed), `kata` (the strongest Kata hypervisor), or a class name |
| `read_only_root` | true | read-only root file system |
| `pids_limit` | 256 | process limit (16–4096) |
| `metadata` | | your labels (up to 50 keys), filterable in the list |
| `wait` | true | wait until the sandbox runs |
| `start_timeout` | 120 | seconds to wait (5–600); the start then finishes in the background (202) |

### Exec

`POST /sandboxes/{id}/exec` (`sandboxes.exec`)

```json
{"cmd": ["python3", "train.py"], "stdin": "…", "timeout": 60, "env": {"EPOCHS": "3"}, "cwd": "/workspace", "stream": false}
```

| Field | Meaning |
|---|---|
| `cmd` | a string (run by `/bin/sh -c`) or an argv list |
| `code` + `language` | instead of `cmd`: run code with the language's interpreter (see [Code interpreter](#code-interpreter)) |
| `stdin` / `stdin_base64` | standard input as text / bytes (up to 10 MiB) |
| `timeout` | seconds (default 60, up to 3600); at the timeout the process is killed (`timed_out: true`, exit code 137) |
| `env` | extra environment for this command (on top of the sandbox's `env`) |
| `cwd` | working directory (default `/workspace`) |
| `stream` | `true` (or `Accept: text/event-stream`): Server-Sent Events |

JSON result: `{"data": {"exit_code", "stdout", "stderr", "timed_out", "duration_ms", "truncated"}}`.
Output that is not valid UTF-8 comes as `stdout_base64` / `stderr_base64`; output above 10 MiB is
cut (`truncated: true`).

**Server-Sent Events** — each output chunk as it is written:

```text
event: start
data: {"exec_id":"01jb…","sandbox_id":"01jb…"}

event: stdout
data: {"data":"epoch 1/3\n"}

event: stderr
data: {"data_base64":"/w=="}

event: exit
data: {"exit_code":0,"timed_out":false,"duration_ms":15234,"truncated":false}
```

Output events carry `data` (UTF-8 text) or `data_base64` (bytes that are not UTF-8). A failure ends
the stream with `event: error` / `{"message", "reason"}`. A `: ping` comment line every 15 s keeps
idle connections open. A client that disconnects stops the command.

**WebSocket** — `POST /sandboxes/{id}/exec/ws` with the same body (no `stream`) returns a one-time
token valid for 60 s: `{"data": {"token", "url": "/ws/sandbox-exec?token=…", "exec_id", "expires_in": 60, "tty"}}`.
Connect to `wss://<console host><url>`:

| Direction | Frame (JSON text) |
|---|---|
| server → client | `{"type":"start","exec_id","tty"}`, `{"type":"stdout","data":"<base64>"}`, `{"type":"stderr","data":"<base64>"}`, `{"type":"exit","exit_code","timed_out","duration_ms","killed"}`, `{"type":"error","message"}` |
| client → server | `{"type":"stdin","data":"<base64>"}`, `{"type":"stdin_close"}`, `{"type":"kill"}`, `{"type":"resize","cols","rows"}` |

The WebSocket is the way for interactive programs that read stdin while they run; the connection
closes after the `exit` frame. `kill` stops the command (`killed: true`, exit code 137).

**A terminal for any command** — `"tty": true` in the body runs the command on a terminal
(`kubectl exec -t`), so shells, REPLs, editors and pagers behave as in a terminal window:

| Field | Meaning |
|---|---|
| `tty` | run the command on a terminal (default `false`) |
| `cols`, `rows` | initial size of the terminal, 1–1000 (default 80 × 24) |

With a terminal:

- the command's stdout and stderr are merged by the terminal and arrive as `stdout` frames,
  terminal-formatted (`\r\n` line ends, escape sequences); `stderr` frames carry messages of the
  exec bridge itself;
- `{"type":"resize","cols":120,"rows":40}` changes the size (the command gets `SIGWINCH`); frames
  that arrive within 100 ms are applied once, with the last size; without a terminal they are ignored;
- typed input goes in `stdin` frames as a terminal sends it (Enter is `\r`, Ctrl+C is `\x03`);
  `stdin_close` sends Ctrl+D, which ends the input of a program that reads the terminal line by
  line;
- `TERM` is `xterm-256color` unless the sandbox's or the request's `env` sets it;
- the `timeout` applies as without a terminal; the exit code is the command's.

`"tty": false` (the default) keeps stdout and stderr apart and binary-safe. `runr sandbox exec
-it` runs a command with a terminal ([CLI](cli.md#sandboxes)).

### Terminal

`POST /sandboxes/{id}/terminal` (`sandboxes.exec`) `{cols, rows}` → `{"data": {"session_id", "url": "/ws/shell?token=…"}}`:
a one-time session (120 s) for the console's [web shell](web-shell.md) WebSocket — a TTY `/bin/sh`
in the sandbox with the sandbox env loaded (`$ENV`). Protocol: client `{input: base64}` and
`{resize: {cols, rows}}`, server `{connected: true, pod}`, `{output: base64}`, `{error}`. The
terminal closes after 5 minutes without input or output; a client that keeps the sandbox busy
calls `keepalive` meanwhile.

### Code interpreter

`POST /sandboxes/{id}/run` (`sandboxes.exec`)

```json
{"code": "import pandas as pd\nprint(pd.__version__)", "language": "python", "timeout": 60, "env": {}, "cwd": "/workspace", "stream": false}
```

The code is written to a file in the sandbox and run with the language's interpreter — the first
of the candidates the image has:

| `language` (aliases) | Interpreter |
|---|---|
| `python` (`py`, `python3`) | `python3`, `python` |
| `javascript` (`js`, `node`, `nodejs`) | `node` |
| `typescript` (`ts`) | `tsx`, `ts-node`, `node --experimental-strip-types` |
| `bash` (`shell`), `sh` | `bash`, `sh` |
| `php`, `ruby` (`rb`), `go` (`golang`) | `php`, `ruby`, `go run` |
| a runtime id | a runtime of the catalog whose manifest runs a file through an `exec`-mode command (`run.command` with `{file}`, no build step), e.g. the Bash example runtime |

Without `language` the sandbox's runtime decides (`python`, `nodejs` → `javascript`, `php`, `go`, …).

Result:

```json
{"data": {
  "language": "python", "exit_code": 0, "stdout": "2.2.3\n", "stderr": "",
  "timed_out": false, "duration_ms": 812, "truncated": false,
  "files_changed": [{"path": "/workspace/report.csv", "size": 2048}, {"path": "/output/chart.png", "size": 31877}],
  "outputs": [{"path": "/output/chart.png", "mime": "image/png", "size": 31877, "data_base64": "iVBORw0KGgo…"}]
}}
```

- `files_changed`: files under `/workspace` and `/output` the run created or changed (up to 1000).
- `outputs`: rich outputs — image files (`png`, `jpg`, `jpeg`, `gif`, `svg`, `webp`) the run wrote to
  `/output`, base64, up to 10 MiB together. `RUNR_OUTPUT_DIR=/output` and `MPLBACKEND=Agg` are set,
  so `plt.savefig(os.environ["RUNR_OUTPUT_DIR"] + "/chart.png")` is all a matplotlib chart needs.
- `stream: true` (or `Accept: text/event-stream`): the exec events, then `event: result` with the
  structured result (without stdout / stderr, which were streamed).

### Background processes

| Method & path | Ability | Purpose |
|---|---|---|
| `POST /sandboxes/{id}/processes` | `sandboxes.exec` | `{cmd, env?, cwd?}` → 201 `{data: Process}`; the process is detached (nohup, and setsid where the image has it) and outlives the request |
| `GET /sandboxes/{id}/processes` | `sandboxes.exec` | every process with its state read from the sandbox |
| `GET /sandboxes/{id}/processes/{process}` | `sandboxes.exec` | one process |
| `GET /sandboxes/{id}/processes/{process}/logs` | `sandboxes.exec` | `tail_bytes` (default 65536) → `{stdout, stderr, truncated}`; `follow=1` streams them as SSE (`stdout`, `stderr`, `exit`) until the process ends |
| `DELETE /sandboxes/{id}/processes/{process}` | `sandboxes.exec` | `signal=TERM` (TERM, KILL, INT, HUP, QUIT, USR1, USR2, STOP, CONT) to the process group |

Process: `{"id", "sandbox_id", "pid", "command", "cwd", "status": "running|exited|killed", "exit_code", "started_at", "ended_at"}`.
`{process}` in paths is the process `id`, not the OS pid.

### Files

All file endpoints need `sandboxes.exec`; paths are absolute (`..` is resolved). Writable places
are `/workspace`, `/output`, `/tmp` and `/home/sandbox`.

| Method & path | Purpose |
|---|---|
| `GET /sandboxes/{id}/files?path=/workspace` | directory listing `{path, is_dir, entries: [{name, path, type: file\|dir\|symlink\|other, size, mode, modified_at}], truncated}` (directories first; a file path describes the file) |
| `GET /sandboxes/{id}/files/content?path=…` | the file's bytes (`application/octet-stream`, `X-RunR-File-Size`); `format=json` → `{path, size, content_base64}` |
| `PUT /sandboxes/{id}/files/content?path=…` | write: the raw body (any non-JSON content type; `mode=0755` in the query), or JSON `{content_base64, mode?}`; parent directories are created |
| `DELETE /sandboxes/{id}/files?path=…&recursive=1` | delete a file, an empty directory, or a tree with `recursive=1` |
| `POST /sandboxes/{id}/files/mkdir` | `{path}`: create a directory with its parents |
| `GET /sandboxes/{id}/files/archive?path=/workspace/src` | `application/x-tar` of the directory's contents (entries relative to it) |
| `PUT /sandboxes/{id}/files/archive?path=/workspace` | extract a tar body (`application/x-tar`) into the directory (created when needed) |

Uploads and downloads are limited to 50 MiB each (`sandbox.files.max_upload_bytes`,
`max_download_bytes`; 413 above).

### Settings, pools, quotas, metrics

| Method & path | Ability | Purpose |
|---|---|---|
| `GET /sandbox-regions` | `sandboxes.view` | per region: `enabled`, `installed_classes`, `allowed_classes` (null = all installed), `effective_classes` (what the organization gets), `default_class`, `effective_default_class`, `allow_runc`, `default_ttl_seconds`, `max_ttl_seconds`, `max_cpu_m`, `max_memory_mb`, `max_disk_mb`, `can_manage` |
| `PUT /sandbox-regions/{regionId}` | `sandboxes.manage` + the region's owner | any of the fields above (`allowed_classes` may name `kata` for every hypervisor); runc can be the default only with `allow_runc` |
| `GET /sandbox-pools` | `sandboxes.view` | the organization's pools: `{id, region, runtime, runtime_version, sandbox_class, cpu_m, memory_mb, disk_mb, size, is_enabled, warm, starting, hits, misses, last_error, tenant_id, created_at}` |
| `POST /sandbox-pools` | `sandboxes.manage` | `{region, runtime, version?, size, cpu?, memory?, disk?, sandbox_class?}` → 201 (refill starts right away) |
| `PUT /sandbox-pools/{pool}` | `sandboxes.manage` | `{size?, is_enabled?}` |
| `DELETE /sandbox-pools/{pool}` | `sandboxes.manage` | 204; its warm pods are removed |
| `GET /sandbox-quotas` | `sandboxes.view` | `{tenant_id, limits, usage}` of the current organization |
| `GET /sandbox-quotas/tenants` | `sandboxes.manage` (platform) | the defaults row (`tenant: null`) and every organization `{tenant, limits, usage, custom}` |
| `PUT /sandbox-quotas/{tenantId \| default}` | `sandboxes.manage` (platform) | set limits (`max_concurrent`, `max_cpu_m`, `max_memory_mb`, `max_sandbox_seconds_month`, `max_pool_size`, `allowed_classes`) |
| `DELETE /sandbox-quotas/{tenantId \| default}` | `sandboxes.manage` (platform) | back to the defaults |
| `GET /sandbox-metrics` | `sandboxes.view` | `window=1h\|24h\|7d\|30d`, `region` → `{window, active, warm, pool: {hits, misses, hit_rate}, starts: {pool_hit, cold}, start_time: {pool_hit, cold}}`, each start-time histogram `{count, sum_ms, buckets: [{le_ms, count}]}` (cumulative; `le_ms` 100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, `null` = +Inf) |
| `GET /sandbox-metrics/prometheus` | `sandboxes.manage` (platform) | Prometheus text format: `runr_sandbox_start_seconds` (histogram, labels `region`, `pool_hit`), `runr_sandbox_pool_claims_total` (`region`, `pool`, `runtime`, `result` hit / miss), `runr_sandboxes` (gauge, `region`, `status`) |
| `GET /sandbox-usage` | `sandboxes.view` | `from`, `to` (dates; default this month) → daily records `{record_date, region_id, sandbox_count, sandbox_seconds, cpu_mc_ms, memory_mib_ms}` — quantities only; what they cost is on the account bills of the [Billing module](billing.md#account-bills) |

"Platform" means every user of a single-tenant installation, platform administrators otherwise.

## SDKs

Two clients cover the sandbox endpoints — lifecycle, exec and streaming, the exec WebSocket
(with a terminal), code runs, files, background processes, activity logs and terminal sessions.
Region settings, warm pools, quotas and metrics are managed through the API, the CLI or
Build & Run → Sandbox settings. Both clients read `RUNR_API_URL` (`https://runr.example.com`; `/api` is
appended), `RUNR_API_KEY` and, on multi-tenant installations, `RUNR_TENANT_ID`. The CLI is
`runr sandbox` ([CLI](cli.md#sandboxes)); it uses the exec WebSocket for `-i` on a terminal,
`--ws` and `-t`.

**Python** — `pip install runr-sandbox` ([`sdk/sandbox/python`](../sdk/sandbox/python/README.md)), sync and asyncio:

```python
from runr_sandbox import Sandbox

with Sandbox.create(runtime="python", network=["pypi.org:443", "files.pythonhosted.org:443"]) as sb:
    sb.files.write("/workspace/data.csv", "x,y\n1,2\n3,4\n")
    print(sb.exec("pip install --user pandas").exit_code)

    for event in sb.exec_stream(["python3", "-u", "train.py"]):
        if event.type in ("stdout", "stderr"):
            print(event.text, end="")

    result = sb.run_code("import pandas as pd\nprint(pd.read_csv('data.csv').sum())")
    print(result.stdout, [f.path for f in result.files_changed])

    server = sb.processes.start("python3 -m http.server 8000")
    sb.processes.kill(server.id)
# the sandbox is removed when the block ends
```

**Node / TypeScript** — `npm install @runr/sandbox` ([`sdk/sandbox/node`](../sdk/sandbox/node/README.md)), Node 18+, no dependencies:

```ts
import { Sandbox } from '@runr/sandbox'

await using sb = await Sandbox.create({ runtime: 'nodejs', ttlSeconds: 600 })

await sb.files.write('/workspace/main.js', 'console.log(process.version)')
const r = await sb.exec(['node', 'main.js'])
console.log(r.exitCode, r.stdout)

for await (const ev of sb.execStream('npm --version && sleep 1 && echo done')) {
  if (ev.type === 'stdout') process.stdout.write(ev.text)
}

const tree = await sb.files.downloadDirectory('/workspace')   // { 'main.js': Uint8Array }
// `await using` removes the sandbox at the end of the scope
```

**Exec WebSocket** — for programs that read stdin while they run, both SDKs open an exec
WebSocket session: Python `sb.exec_ws(cmd, tty=False, cols=None, rows=None, …)` (sync and
asyncio), Node `sb.execWs(cmd, { tty, cols, rows, … })` (and `runCodeWs(code, { language })`).
The session sends input (`send_stdin` / `sendStdin`), ends it (`close_stdin` / `closeStdin`),
changes the terminal's size (`resize(cols, rows)`) and stops the command (`kill()`); its output
arrives as `stdout` / `stderr` events and one `exit` event (`killed` after a kill), or `wait()`
returns the result. With a terminal, stdout and stderr arrive together as `stdout`:

```python
with sb.exec_ws(["python3", "-i"], tty=True, cols=100, rows=30) as session:
    session.send_stdin("print(6 * 7)\r")
    session.close_stdin()                     # Ctrl+D ends the REPL
    print(session.wait().stdout)
```

```ts
await using session = await sb.execWs(['bash'], { tty: true, cols: process.stdout.columns, rows: process.stdout.rows })
process.stdout.on('resize', () => session.resize(process.stdout.columns, process.stdout.rows))
session.sendStdin('ls -la\r')
session.closeStdin()
for await (const ev of session) if (ev.type === 'stdout') process.stdout.write(ev.data)
```

The Python SDK carries the connection with `websockets`; the Node SDK uses the runtime's
`WebSocket` (Node.js 22+, Bun, Deno) or its own client on Node.js 18 and 20.

Both SDKs map the error reasons to exception classes (`NotFoundError`, `ConflictError`,
`QuotaError`, `AuthError`, …), poll a 202 create until the sandbox runs, and ignore a 404 on
close.

## Example: an AI agent code interpreter

An agent gets a `run_python` tool. One sandbox serves the whole conversation, so state (variables
in files, installed packages, uploaded data) survives between tool calls; the tool returns what the
code printed plus the charts it drew, and the sandbox goes away with the conversation.

```python
import base64
import os
from runr_sandbox import Sandbox

RUN_PYTHON_TOOL = {
    "name": "run_python",
    "description": "Run Python code in an isolated sandbox. Files persist in /workspace between calls. "
                   "Save charts to /output (for example plt.savefig('/output/chart.png')).",
    "input_schema": {
        "type": "object",
        "properties": {"code": {"type": "string", "description": "Python source code"}},
        "required": ["code"],
    },
}


class CodeInterpreter:
    def __init__(self):
        self.sandbox = Sandbox.create(
            runtime="python",
            ttl_seconds=3600,
            idle_timeout=900,
            cpu="1", memory="2Gi", disk="4Gi",
            network=["pypi.org:443", "files.pythonhosted.org:443"],   # pip only
            metadata={"purpose": "code-interpreter"},
        )

    def run_python(self, code: str) -> dict:
        """The tool handler: its return value is the tool result the model sees."""
        result = self.sandbox.run_code(code, language="python", timeout=120)
        return {
            "exit_code": result.exit_code,
            "timed_out": result.timed_out,
            "stdout": result.stdout[-20_000:],
            "stderr": result.stderr[-20_000:],
            "files_changed": [f.path for f in result.files_changed],
            "images": [{"path": o.path, "media_type": o.mime, "data": base64.b64encode(o.data).decode()} for o in result.outputs],
        }

    def upload(self, local_path: str) -> str:
        remote = "/workspace/" + os.path.basename(local_path)
        with open(local_path, "rb") as f:
            self.sandbox.files.write(remote, f.read())
        return remote

    def close(self):
        self.sandbox.close()


# In the agent loop: when the model calls run_python(code), answer with interpreter.run_python(code)
interpreter = CodeInterpreter()
try:
    print(interpreter.run_python("import math\nprint(math.factorial(20))")["stdout"])
finally:
    interpreter.close()
```

What the model's code can do is bounded by the sandbox: its own kernel (Kata) or a user-space
kernel (gVisor), an unprivileged user, the CPU / memory / disk / process limits above, no
Kubernetes credentials, and with this allowlist nothing on the network but the package index. A
runaway loop ends at the tool's timeout; a forgotten sandbox at its idle timeout or TTL.

## MCP tools

AI agents drive sandboxes through the RunR MCP server ([AI agents](mcp.md#tools)) without an
SDK: `sandboxes_list`, `sandbox_get`, `sandbox_create` (waits until the sandbox runs),
`sandbox_exec`, `sandbox_run_code`,
`sandbox_file_read` / `sandbox_file_write` / `sandbox_files_list` / `sandbox_file_delete`,
`sandbox_archive_download` / `sandbox_archive_upload` (a directory as a base64 tar),
`sandbox_process_start` / `sandbox_processes_list` / `sandbox_process_logs` /
`sandbox_process_stop`, `sandbox_lifecycle` (keepalive, timeout, pause, resume),
`sandbox_network`, `sandbox_logs`, `sandbox_quota` and `sandbox_destroy`. They call the routes
above with the agent's token, so the same permissions, quotas and audit events apply.

## Quotas and usage

Each organization has (0 = unlimited):

| Limit | Default | Enforced |
|---|---|---|
| `max_concurrent` | 20 | sandboxes alive at once (pending, running, paused); checked on create |
| `max_cpu_m`, `max_memory_mb` | 8000, 16384 | their CPU / memory together; checked on create |
| `max_sandbox_seconds_month` | 0 | run time of all sandboxes in the calendar month (UTC); create is refused once used up and the reaper stops running sandboxes (`end_reason: quota`) |
| `max_pool_size` | 10 | warm pool pods of the organization together; checked when pools are created or resized |
| `allowed_classes` | all | sandbox classes the organization may use, within what the region allows |

The defaults come from `sandbox.quota.*` (Helm `sandboxApi.quota`) and can be overridden for every
organization (the defaults row) and per organization (Build & Run → Sandbox settings → Quotas, or
`PUT /sandbox-quotas/{tenantId}`). Admission is serialized per organization, so parallel creates
cannot both take the last slot. With tenancy, a sandbox's CPU and memory — the Kata RuntimeClass
overhead and warm pods included — also count against the organization's region quota, the same
quota its projects use, in full on its always-on budget
([Organization quotas](architecture.md#region-capacity-and-admission)). The namespace gets a pod-count ResourceQuota (concurrency + pool size) as a
backstop.

**Region capacity**: a sandbox pod runs until it ends, so every live sandbox and warm pod holds
its CPU and memory plus the pod overhead of its class on the region's always-on capacity budget,
next to the always-on projects and model services, without overcommit
([Region capacity and admission](architecture.md#region-capacity-and-admission)). A sandbox that
needs a new pod is refused with `quota_capacity` when that budget has no room left; one served
from a warm pod is not checked again (the warm pod holds its share already), and a pool stops
starting warm pods at the limit (`last_error` says why).

**Usage metering**: every five minutes the usage of today (and of yesterday during the first hour
of a day) is aggregated per organization × region × creator × day into `sandbox_usage_records`:
number of sandboxes, sandbox seconds, and reserved resources × time in the units of the project
usage records (millicore·ms, MiB·ms). With the [Billing module](billing.md) on, they are priced
with the region's vCPU-hour and GB-hour prices (multiplier 1) on the **account bill** of the user
who created the sandboxes, one line per region ("Sandboxes (eu-1)"; [Account bills](billing.md#account-bills)).
Warm pods are metered from their claim on: idle pool capacity is not charged. `GET /sandbox-usage`
answers the quantities; the money is in the Billing module's bills and `GET /api/billing/usage`.

## Warm pool

A pool keeps `size` generic sandboxes of one runtime/version, size and class started in its
organization's namespace of a region (Build & Run → Sandbox settings → Warm pools, or `POST /sandbox-pools`).
A create request claims a warm pod when it asks for the same region, runtime, version, CPU, memory,
disk and class, with no custom image, the default PID limit and a read-only root file system. The
claim is an atomic `warm → claiming` update of the pod's row, so two requests never get the same pod;
then the pod's labels change, its egress policy and env Secret are applied, the env file and the
request's files are written into it, and the sandbox runs — with the pod's id (`RUNR_SANDBOX_ID` stays
valid). Every command sources the env file, so a claimed pod sees the same environment a cold one
gets through `secretKeyRef`.

The pool is refilled asynchronously after every claim or miss and every minute. A pod the refill
starts is warm, and can be claimed, as soon as it runs: RunR reads it on the start poll (every
150 ms) for up to a minute, so the replacement of a claimed pod is ready about one pod start after
the claim. Pods that do not start are replaced (the reason is shown as `last_error`), surplus pods go when the size is lowered,
pods of a class or image the pool no longer uses are replaced, and warm pods older than
`sandbox.pool.max_age_seconds` (3600) are recycled, so a claimed pod keeps its full lifetime before
the pod deadline. A disabled pool is drained.

Every sandbox records `start_ms` and `from_pool`; `GET /sandbox-metrics` and the Prometheus endpoint
expose pool hits / misses and start-time histograms for pool hits and cold starts, per region.

## Permissions and API tokens

| Ability | Grants |
|---|---|
| `sandboxes.view` | list and read sandboxes, their activity log, regions, pools, the organization's quota, metrics and usage |
| `sandboxes.create` | create, delete, extend (TTL) sandboxes, change their network |
| `sandboxes.exec` | exec, code runs, terminal, files, processes, keepalive, pause / resume |
| `sandboxes.manage` | region sandbox settings (with the region's ownership: platform regions for platform admins, an organization's own regions for its owner / admin), warm pools; platform-level: every organization's quotas, Prometheus metrics |

They are catalog permissions ([permissions](permissions.md), group **Sandboxes**). Built-in
roles: Owner and Admin hold all four; Developer holds `sandboxes.view`, `sandboxes.create` and
`sandboxes.exec`; Viewer holds `sandboxes.view`. Custom roles pick them like any other
permission. Platform administrators hold them everywhere.

`sandboxes.manage` is not `sandbox.manage`: the latter (group *Infrastructure*) installs and
removes the sandbox runtimes of a region — gVisor, Kata Containers, WebAssembly shims — and maps
project types to them (Administration → Regions & settings → Knative Regions → (region) → Isolation). The Sandbox API uses whatever
a region has installed and never needs `sandbox.manage`. Every sandbox
route has its rule in `config/route_permissions.php`; changing a region's sandbox settings also needs
the region's ownership (`region.mutable`: platform regions are the platform administrators', an
organization's own regions its owners' and admins'), and the quotas of every organization and the
Prometheus metrics need a platform administrator on a multi-tenant installation.

Every endpoint works with an API token (`Authorization: Bearer …`) — the way agents and CI use it.
Tokens carry scopes (Administration → API Tokens): an agent's token with `sandboxes.view`, `sandboxes.create`
and `sandboxes.exec` can run code and nothing else.

## Audit

The sandbox endpoints are part of the audit log (Administration → Audit log, [security](security.md)):
every request below is recorded with actor, organization, result, target (`sandbox`, `sandbox_pool`,
`region`, `tenant`) and non-secret details — command lines, paths and sizes, never command output,
file contents or env values.

| Action | Recorded for | Details |
|---|---|---|
| `sandbox.create` | `POST /sandboxes` | runtime, version, image, region, class, network mode, env names, number of files, from_pool |
| `sandbox.delete` | `DELETE /sandboxes/{id}` | |
| `sandbox.expire` | the reaper (actor `system`) | reason (`ttl`, `idle`, `quota`, `lost`, `failed`) |
| `sandbox.exec` | `POST /sandboxes/{id}/exec`, `…/exec/ws` | command line (up to 1000 characters) or language + code size, streaming / WebSocket |
| `sandbox.run` | `POST /sandboxes/{id}/run` | language, code size |
| `sandbox.terminal.request`, `shell.session.open`, `shell.session.close` | `POST /sandboxes/{id}/terminal`, the web shell | session id, pod |
| `sandbox.file.upload`, `sandbox.file.download` | `PUT` / `GET …/files/content`, `…/files/archive` | path, bytes, archive |
| `sandbox.file.delete`, `sandbox.file.mkdir` | `DELETE …/files`, `POST …/files/mkdir` | path, recursive |
| `sandbox.process.start`, `sandbox.process.kill` | `POST …/processes`, `DELETE …/processes/{process}` | command, process id, signal |
| `sandbox.timeout.update`, `sandbox.network.update`, `sandbox.pause`, `sandbox.resume` | lifecycle calls | new TTL, network |
| `sandbox.region.update`, `sandbox.pool.create` / `.update` / `.delete`, `sandbox.quota.update` / `.reset` | settings | changed fields |

`POST /sandboxes/{id}/keepalive` only resets the idle timer and is not recorded. Each sandbox also
keeps an activity log of its own (`GET /sandboxes/{id}/logs`: lifecycle, commands with exit code and
duration, file transfers), and `App\Events\SandboxActivity` (`action`, `sandboxId`, `tenantId`,
`userId`, `details`) is dispatched for each of these actions.

## UI

- **Sandboxes** (Build & Run → Sandboxes, `/sandboxes`): the organization's sandboxes with status,
  runtime, isolation, region, resources, age and time left; filters by region and status; extend,
  pause / resume, terminal and kill per row; **Create sandbox** (runtime or image, sizes, TTL, idle
  timeout, class, egress allowlist, env, metadata). The detail drawer has the overview, the terminal
  (the console's xterm shell), a file browser (navigate, preview, upload, new folder, delete,
  download a file or a folder as tar), processes, a code runner with image outputs, and the activity
  log with the pod's status and events.
- **Build & Run → Sandbox settings**: region settings (enabled, allowed classes, default class, runc opt-in,
  default / maximum TTL, per-sandbox CPU / memory / disk limits) — read-only for regions the user
  cannot manage; warm pools (add, resize, enable, delete, warm / starting counts, hits / misses);
  quotas (the organization's limits and usage; for platform admins every organization, editable);
  metrics (pool hit rate and start-time histograms by window).

## Configuration

`api/config/sandbox.php`, set through the environment (Helm: `sandboxApi.*` → the `runr-config`
ConfigMap):

| Variable | Helm value | Default | Meaning |
|---|---|---|---|
| `SANDBOX_API_ENABLED` | `sandboxApi.enabled` | true | register the endpoints and jobs |
| `SANDBOX_NAMESPACE_PREFIX` | `sandboxApi.namespacePrefix` | `runr-sbx` | namespace `<prefix>-<organization>` |
| `SANDBOX_DEFAULT_RUNTIME` | `sandboxApi.defaultRuntime` | `python` | runtime of a create without runtime / image |
| `SANDBOX_ALLOW_CUSTOM_IMAGES` | `sandboxApi.allowCustomImages` | true | allow `image` |
| `SANDBOX_DEFAULT_CPU_M`, `_MEMORY_MB`, `_DISK_MB` | `sandboxApi.defaults.cpuM`, `.memoryMb`, `.diskMb` | 500, 512, 1024 | default size |
| `SANDBOX_DEFAULT_TTL`, `SANDBOX_DEFAULT_IDLE_TIMEOUT` | `sandboxApi.defaults.ttlSeconds`, `.idleTimeoutSeconds` | 900, 600 | default TTL / idle timeout |
| `SANDBOX_MAX_CPU_M`, `_MEMORY_MB`, `_DISK_MB`, `SANDBOX_MAX_TTL` | `sandboxApi.limits.maxCpuM`, `.maxMemoryMb`, `.maxDiskMb`, `.maxTtlSeconds` | 4000, 8192, 10240, 86400 | per-sandbox ceilings (a region may lower them) |
| `SANDBOX_QUOTA_CONCURRENT`, `_CPU_M`, `_MEMORY_MB`, `_SECONDS_MONTH`, `_POOL_SIZE` | `sandboxApi.quota.maxConcurrent`, `.maxCpuM`, `.maxMemoryMb`, `.maxSandboxSecondsMonth`, `.maxPoolSize` | 20, 8000, 16384, 0, 10 | organization quota defaults |
| `SANDBOX_EXEC_TIMEOUT`, `SANDBOX_EXEC_MAX_TIMEOUT` | `sandboxApi.exec.defaultTimeout`, `.maxTimeout` | 60, 3600 | command timeout default / maximum |
| `SANDBOX_MAX_UPLOAD_BYTES`, `SANDBOX_MAX_DOWNLOAD_BYTES` | `sandboxApi.files.maxUploadBytes`, `.maxDownloadBytes` | 52428800 each | file transfer limits |
| `SANDBOX_POOL_MAX_AGE` | `sandboxApi.poolMaxAgeSeconds` | 3600 | warm pods are recycled after this |
| `SANDBOX_RETENTION_DAYS` | `sandboxApi.retentionDays` | 30 | ended sandboxes (rows, processes, events) are removed after this |
| `SANDBOX_START_TIMEOUT` | via `extraEnv` | 120 | default `start_timeout` |
| `SANDBOX_START_POLL_MS` | via `extraEnv` | 150 | how often a starting pod is read (100–200 ms; after 30 s once a second), so a sandbox runs at most one interval after its pod |
| `SANDBOX_DEFAULT_PIDS`, `SANDBOX_MAX_PIDS` | via `extraEnv` | 256, 4096 | default / maximum `pids_limit` |
| `SANDBOX_DEFAULT_READ_ONLY_ROOT` | via `extraEnv` | true | default `read_only_root` |
| `SANDBOX_MAX_CREATE_FILES_BYTES` | via `extraEnv` | 10485760 | the create request's `files` together |
| `SANDBOX_EXEC_MAX_OUTPUT`, `SANDBOX_EXEC_MAX_STDIN` | via `extraEnv` | 10485760 each | exec output (then `truncated`) and stdin |
| `SANDBOX_RUN_MAX_OUTPUTS_BYTES` | via `extraEnv` | 10485760 | rich outputs of a code run together |
| `SANDBOX_UID_MIN`, `SANDBOX_UID_MAX` | via `extraEnv` | 100000, 165535 | UID range of sandboxes |
| `SANDBOX_KUBECTL` | via `extraEnv` | `kubectl` | the kubectl binary of the API image |

The region kubeconfig RunR already holds must allow it to manage namespaces, pods, `pods/exec`,
NetworkPolicies and ResourceQuotas — the access RunR uses for its own installs.

## Operations

- **Jobs** (the scheduler): `sandbox-reaper` every minute (TTL, idle and month-quota expiry; stuck
  starts and deletes; pods that are gone or ended; orphan pods and egress policies labelled
  `runr.dev/sandbox` without a live sandbox; retention), `sandbox-pool-refill` every minute,
  `sandbox-usage-metering` every five minutes.
- **On demand**: `php artisan sandboxes:maintain [--reap] [--refill] [--meter]` runs them now.
- **Labels**: every sandbox pod carries `app.kubernetes.io/managed-by=runr`, `runr.dev/sandbox=true`,
  `runr.dev/sandbox-id`, `runr.dev/tenant`, `runr.dev/sandbox-state` (`warm` / `claimed`) and, for warm
  pods, `runr.dev/sandbox-pool`: `kubectl get pods -A -l runr.dev/sandbox=true`.
- **Metrics**: scrape `GET /api/sandbox-metrics/prometheus` with a platform admin's API token.
- **Data**: the tables are central (`sandboxes`, `sandbox_processes`, `sandbox_events`,
  `sandbox_pools`, `sandbox_region_settings`, `sandbox_quotas`, `sandbox_usage_records`), keyed by
  `tenant_id`.

## Testing

- **API**: `cd api && php artisan test tests/Unit/Sandboxes tests/Feature/Sandboxes` — pod,
  namespace and NetworkPolicy manifests per class, class resolution, egress parsing, exec wrappers,
  tar round trip, the lifecycle API against an in-memory Kubernetes API, quotas, the reaper, warm
  pool claim and refill, exec as JSON and SSE through a fake exec transport, files, code runs,
  processes, metering and the settings endpoints.
- **UI**: `cd vue && npx vitest run src/modules/sandboxes/__tests__` and
  `npx playwright test e2e/sandboxes.spec.ts` (mocked API).
- **SDKs**: `cd sdk/sandbox/python && pip install -e '.[test]' && pytest`;
  `cd sdk/sandbox/node && npm install && npm test` — both against in-process fake servers.
- **End to end**: `bash runtime/ci.sh test-sandbox-api` creates a Kind cluster, starts the API with
  docker compose and runs `php artisan test:e2e --suite=sandbox-api`: gVisor installed through the
  API, runc opted in, the lifecycle with the pod spec checked in the cluster, exec / SSE / timeout,
  a code run with an image output, files with a tar round trip, processes, pause / resume, network
  none, TTL expiry through the reaper, a warm pool claim and a quota refusal; it prints the start
  times of cold and pool-claimed sandboxes and the time from the claim until the pool is warm
  again. gVisor runs inside the Kind nodes (runsc and its
  containerd shim from RunR's installer; the install takes 3.5–5 minutes there, measured in WSL 2).
  Kata runs only where the host has hardware virtualization — the CPU's vmx / svm flag and a
  `/dev/kvm` that opens (detected; `SANDBOX_KATA=0|1` overrides) — otherwise its checks are
  reported as skipped. Afterwards the runr CLI of the checkout runs on a pseudo-terminal against the
  same stack (`runtime/lib/sandbox_tty_client.py`): `runr sandbox exec -it` gets a terminal of the
  window's size, follows a window change, reads typed lines, passes Ctrl+C to the command, exits
  with the command's exit code and restores the terminal; `-t` with output to a pipe still runs on
  a terminal (`SANDBOX_CLI_E2E=0` leaves this out).
  `KIND_LOCK=/tmp/runr-kind.lock` serializes Kind clusters on a shared host, as for
  `test-runtimes`. Kata runs install Cloud Hypervisor, QEMU and Dragonball and check each guest's
  vCPUs; a hypervisor the install reports unavailable is skipped with the install's reason, a Kata
  sandbox that does not start prints its pod and the kubelet's events, and after the e2e the Kata
  errors in containerd's log on each Kind node are printed. The Kind nodes get a host-like
  `/dev/shm` first (`KIND_NODE_SHM`, default 4g; Docker's 64 MiB holds no QEMU guest —
  [Kata hypervisors → /dev/shm](kata-hypervisors.md#devshm-qemu)) and D-Bus (`KIND_NODE_DBUS`; Kind's
  node image has none, which Dragonball needs — [Kata hypervisors → D-Bus](kata-hypervisors.md#d-bus-dragonball)).
  `RUNR_E2E_SANDBOX_CASES=kata`
  runs only the Kata install and cases, then the quota case.
