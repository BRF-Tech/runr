# Example runtimes

Four runtime plugins that show the RunR runtime contract from different angles. Each directory
holds a `runtime.yaml` manifest and the handler the manifest's starter template is made of.
The full contract and manifest reference are in [docs/runtimes.md](../../docs/runtimes.md).

| Runtime | Directory | Run mode | Image | What it shows |
|---|---|---|---|---|
| C | [`c/`](c/) | `exec`, raw input | built per function version (gcc, static binary on Alpine) | a compiled language; the wrapper runs the binary once per invocation |
| Rust | [`rust/`](rust/) | `http` | built per function version (rustc, std only) | a binary that is its own HTTP server; schedule runs through the wrapper (`schedule: invoke`) |
| Bash | [`bash/`](bash/) | `exec`, raw input | `ghcr.io/runrsh/reqs/bash:5.2` (the official `bash:5.2`, mirrored) | an interpreted language with zero build steps |
| Q | [`q/`](q/) | `persistent` | built per region from the uploaded Dockerfile | a language nobody has heard of: one long-lived interpreter process answers every request |

Every example answers all three triggers the same way:

- **http**: `GET /?name=RunR` → `Hello, RunR! (from <language>)` (name defaults to `World`)
- **schedule**: prints `tick from <language>`
- **queue**: `processed <message> (from <language>)` — the queue consumer posts the stream entry's
  fields as a JSON object, e.g. `{"msg":"hi"}`

## Validate

```bash
cd api
php artisan runtimes:validate ../examples/runtimes/{c,rust,bash}/runtime.yaml
php artisan runtimes:validate ../examples/runtimes/q/runtime.yaml --files=../examples/runtimes/q/interpreter
```

or against a running platform with the CLI (paths from the repository root):

```bash
runr runtimes validate -f examples/runtimes/c/runtime.yaml
runr runtimes validate -f examples/runtimes/q/runtime.yaml --files examples/runtimes/q/interpreter
```

## Register a runtime

Platform administrators (`runtimes.manage`) register runtimes for everyone; with the
**Custom Runtimes** switch in Settings → Platform on, any member (`runtimes.use_custom`)
registers custom runtimes for their organization. Settings → Runtimes → *Add runtime* takes the
manifest and its files; the CLI does the same (`--files` uploads every file of the directory;
paths from the repository root):

```bash
runr runtimes register -f examples/runtimes/c/runtime.yaml
runr runtimes register -f examples/runtimes/q/runtime.yaml --files examples/runtimes/q/interpreter --wait
```

and so does the API with an API token:

```bash
API=https://runr.example.com/api
TOKEN=...   # Settings → API Tokens

register() {   # register <manifest> [file...]  — files are sent under their base names
  local manifest="$1"; shift
  local files='{}'
  for f in "$@"; do
    files=$(jq --arg n "$(basename "$f")" --rawfile c "$f" '. + {($n): $c}' <<<"$files")
  done
  jq -n --rawfile manifest "$manifest" --argjson files "$files" '{manifest: $manifest, files: $files}' |
    curl -sS -X POST "$API/runtimes" \
      -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
      -H 'Content-Type: application/json' -d @-
}

cd examples/runtimes
register c/runtime.yaml          # C, Rust and Bash: the manifest is all RunR needs
register rust/runtime.yaml
register bash/runtime.yaml
register q/runtime.yaml q/interpreter/{Dockerfile,go.mod,main.go,cgi.go,lexer.go,parser.go,eval.go}
```

Uploaded file names are ConfigMap keys (letters, digits, `.`, `-`, `_`), up to 50 files and
900 KB together. `POST /api/runtimes/validate` with `{"manifest": "...", "files": {...}}` checks a
manifest without saving it and lists every problem with its JSON path.

Registering the Q runtime starts the build of its runtime image in every active region with a
registry (Settings → Runtimes → *Builds* or `runr runtimes builds q` shows the status). After
registering, pick the runtime when creating a project: the editor opens with the manifest's
starter template; saving a C or Rust function builds its image, and a Bash or Q function deploys
with the code mounted. `runr runtimes test-deploy q --region eu-1` deploys the starter template
as a test project.

## End-to-end check

`bash runtime/ci.sh test-runtimes` deploys the C, Q and Bash examples (and the built-in Python
runtime) on a Kind cluster and calls them; see [Testing](../../docs/runtimes.md#testing).
