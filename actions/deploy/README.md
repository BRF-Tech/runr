# RunR deploy action

Deploys a RunR project from a GitHub Actions workflow with the `runr` CLI: the action
downloads the CLI release (checked against the release's `checksums.txt`, cached between
runs), runs `runr deploy` and waits until the new version serves. The first run creates the
project from `runr.yaml`; later runs apply config changes, upload the code as a new version
and deploy it.

## Usage

```yaml
name: deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - id: runr
        uses: brf-tech/runr/actions/deploy@v0.5.3
        with:
          api-url: https://runr.example.com
          token: ${{ secrets.RUNR_TOKEN }}
          path: ./functions/hello      # directory with runr.yaml

      - run: curl -fsS "${{ steps.runr.outputs.url }}"
```

### Container projects (caas)

Build and push the image, then deploy that tag:

```yaml
      - uses: docker/build-push-action@v6
        with:
          push: true
          tags: ghcr.io/acme/web:${{ github.sha }}

      - uses: brf-tech/runr/actions/deploy@v0.5.3
        with:
          api-url: https://runr.example.com
          token: ${{ secrets.RUNR_TOKEN }}
          project: web
          image: ghcr.io/acme/web:${{ github.sha }}
```

### Token

Create a dedicated token and store it as the repository secret `RUNR_TOKEN`:

```sh
runr tokens create github-actions --preset deploy --expires 90d
```

### Secret variables

`secrets` takes one `KEY=VALUE` per line. The action stores them as secret environment
variables of the project (write-only: RunR lists their names, never their values) before the
deploy, so the new deployment runs with them. Each value is masked in the log and handed to
`runr env set --secret` on stdin, never as a command-line argument. Blank lines and `#`
comments are skipped; spaces around the key and the value are removed.

```yaml
      - uses: brf-tech/runr/actions/deploy@v0.5.3
        with:
          api-url: https://runr.example.com
          token: ${{ secrets.RUNR_TOKEN }}
          secrets: |
            DATABASE_URL=${{ secrets.DATABASE_URL }}
            STRIPE_KEY=${{ secrets.STRIPE_KEY }}
```

When the first run creates the project, the secrets are set right after that deploy and the
action deploys once more, so the live deployment has them from the first run on. The token
needs `env.edit` (part of the `deploy` preset).

## Inputs

| Input | Default | Description |
|---|---|---|
| `api-url` | (required) | RunR console or API URL |
| `token` | (required) | RunR API token |
| `project` | name in `runr.yaml` | Project name or id |
| `path` | `.` | Project directory (holds `runr.yaml` and the code) |
| `image` | | Container image to deploy (caas) |
| `region` | | Region of a project the first deploy creates |
| `message` | `<workflow> <short sha>` | Version message |
| `wait` | `true` | Wait until the deployment is ready |
| `timeout` | `10m` | How long to wait for the build and the deployment |
| `version` | `latest` | runr CLI release (`latest` or a tag such as `v0.5.3`) |
| `cli-base-url` | GitHub releases | Download base of the CLI archives (mirror) |
| `github-token` | `github.token` | Token for the latest-release lookup |
| `args` | | Extra arguments for `runr deploy` |
| `secrets` | | Secret environment variables, one `KEY=VALUE` per line, set before the deploy |

## Outputs

| Output | Description |
|---|---|
| `url` | Public URL (empty for schedule and queue triggers) |
| `project`, `project-id` | Project name and id |
| `version`, `version-id` | Deployed version label (`v7`) and id |
| `deployment-id` | Deployment id |
| `status` | `active`, or `pending` with `wait: false` |

The action also writes a short summary to the job page.

## Runners

Linux, macOS and Windows runners on x64 and ARM64. The scripts need `bash`, `curl` and
`tar` (`unzip` or PowerShell on Windows), which GitHub-hosted runners have.

## Tests

`bash actions/deploy/test/run.sh` runs the scripts against a fake release served from
`file://` and a stub CLI.

More: [docs/github-action.md](../../docs/github-action.md) (GitLab CI as well) and
[docs/cli.md](../../docs/cli.md).
