# GitHub Action: RunR deploy

`actions/deploy` in this repository deploys a RunR project from a GitHub Actions workflow. It
installs the [`runr` CLI](cli.md), runs `runr deploy` and waits until the new version
serves. The first run creates the project from `runr.yaml`; later runs apply config changes,
upload the code as a new version and deploy it.

## Quick start

1. Create a token that can deploy and store it as the repository secret `RUNR_TOKEN`:

   ```sh
   runr tokens create github-actions --preset deploy --expires 90d
   ```

   Use `--preset deploy-create` when the workflow should create the project on its first run.

2. Add the workflow:

   ```yaml
   # .github/workflows/deploy.yml
   name: deploy
   on:
     push:
       branches: [main]

   concurrency: deploy-production   # one deployment at a time

   jobs:
     deploy:
       runs-on: ubuntu-latest
       environment:
         name: production
         url: ${{ steps.runr.outputs.url }}
       steps:
         - uses: actions/checkout@v4

         - id: runr
           uses: brf-tech/runr/actions/deploy@v0.6.0
           with:
             api-url: https://runr.example.com
             token: ${{ secrets.RUNR_TOKEN }}
             path: ./functions/orders     # directory with runr.yaml

         - name: Smoke test
           run: curl -fsS "${{ steps.runr.outputs.url }}"
   ```

## Container projects

Build and push the image, then deploy that tag:

```yaml
jobs:
  deploy:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/build-push-action@v6
        with:
          push: true
          tags: ghcr.io/acme/web:${{ github.sha }}

      - uses: brf-tech/runr/actions/deploy@v0.6.0
        with:
          api-url: https://runr.example.com
          token: ${{ secrets.RUNR_TOKEN }}
          project: web
          image: ghcr.io/acme/web:${{ github.sha }}
```

The region's cluster must be able to pull the image (a public image, or the project's image
pull secret).

## Several projects

```yaml
    strategy:
      matrix:
        service: [orders, billing, notify]
    steps:
      - uses: actions/checkout@v4
      - uses: brf-tech/runr/actions/deploy@v0.6.0
        with:
          api-url: ${{ vars.RUNR_API_URL }}
          token: ${{ secrets.RUNR_TOKEN }}
          path: services/${{ matrix.service }}
```

## Secret variables

`secrets` takes one `KEY=VALUE` per line and stores them as
[secret environment variables](security.md#secret-environment-variables) of the project
before the deploy, so the new deployment runs with them. Take the values from repository or
environment secrets:

```yaml
      - uses: brf-tech/runr/actions/deploy@v0.6.0
        with:
          api-url: https://runr.example.com
          token: ${{ secrets.RUNR_TOKEN }}
          secrets: |
            DATABASE_URL=${{ secrets.DATABASE_URL }}
            STRIPE_KEY=${{ secrets.STRIPE_KEY }}
```

- Every value is masked in the log (`::add-mask::`) and handed to
  `runr env set --secret --no-deploy KEY` on stdin, never as a command-line argument, so it
  shows up in no process list and no trace.
- Blank lines and `#` comments are skipped; spaces around the key and the value are removed;
  an empty value or an invalid name fails the step (exit code 2).
- Secrets are write-only: the values go straight into the project's region, RunR keeps no copy
  and lists their names (`runr env ls`), never their values. Secrets set by earlier runs and not
  listed any more stay; remove them with `runr env unset NAME`. A value that already lives in a
  secret store of your organization does not have to pass through the workflow at all: refer to
  it once with `runr env ref` ([secret stores](secret-stores.md)).
- When the first run creates the project (`runr env set` finds no project, exit code 4), the
  action sets the secrets right after that deploy and deploys once more, so the live
  deployment has them from the first run on; the outputs are those of the second deploy.

## Preview deployments of pull requests

RunR has preview environments built in: connect the project to the repository through the
GitHub App and turn previews on, and every pull request gets its own service and URL, rebuilt
on each commit and deleted when the request is closed or merged — no workflow needed
([git deploy](git-deploy.md#preview-environments)):

```sh
runr git connect --provider github --repo acme/orders --previews -p orders-api
```

RunR reports each preview on the pull request (commit status, a GitHub deployment
`preview/pr-<n>` with the URL, one comment kept up to date).

When the code reaches RunR through this action instead (a build step in the workflow, a
repository that is not connected), a workflow can deploy each pull request as a project of
its own and remove it when the request closes. The preview project is named after the
request (`orders-pr-<number>`): the workflow writes that name into `runr.yaml`, so the first
run creates the project and later commits deploy new versions of it.

```yaml
# .github/workflows/preview.yml
name: preview
on:
  pull_request:
    types: [opened, reopened, synchronize, closed]

concurrency: preview-${{ github.event.number }}

env:
  PREVIEW: orders-pr-${{ github.event.number }}

jobs:
  deploy:
    # pull requests from forks get no secrets in GitHub Actions
    if: github.event.action != 'closed' && github.event.pull_request.head.repo.full_name == github.repository
    runs-on: ubuntu-latest
    environment:
      name: pr-${{ github.event.number }}
      url: ${{ steps.runr.outputs.url }}
    steps:
      - uses: actions/checkout@v4

      - name: Name the preview project
        run: sed -i "s/^name: .*/name: ${PREVIEW}/" functions/orders/runr.yaml

      - id: runr
        uses: brf-tech/runr/actions/deploy@v0.6.0
        with:
          api-url: ${{ vars.RUNR_API_URL }}
          token: ${{ secrets.RUNR_PREVIEW_TOKEN }}
          path: ./functions/orders
          message: "PR #${{ github.event.number }} ${{ github.event.pull_request.head.sha }}"
          secrets: |
            DATABASE_URL=${{ secrets.PREVIEW_DATABASE_URL }}

  remove:
    if: github.event.action == 'closed' && github.event.pull_request.head.repo.full_name == github.repository
    runs-on: ubuntu-latest
    env:
      RUNR_API_URL: ${{ vars.RUNR_API_URL }}
      RUNR_TOKEN: ${{ secrets.RUNR_PREVIEW_TOKEN }}
      RUNR_VERSION: v0.6.0
    steps:
      - name: Install the runr CLI
        run: |
          curl -fsSL "https://runr.sh/dl/cli/${RUNR_VERSION}/runr_linux_amd64.tar.gz" \
            | sudo tar -xz -C /usr/local/bin runr
      - name: Delete the preview project
        run: runr projects delete "$PREVIEW" --yes || [ $? -eq 4 ]   # 4: it was never created
```

- The token creates and deletes the preview projects:
  `runr tokens create github-previews --preset deploy-create --ability projects.delete --expires 90d`.
- A preview project starts with the variables of `runr.yaml` only; secret variables come from
  the `secrets` input (preview values, not production ones).
- `concurrency` keeps the deploys of one request in order, and a close after a running deploy
  waits for it.

## Inputs

| Input | Default | Description |
|---|---|---|
| `api-url` | (required) | RunR console or API URL |
| `token` | (required) | RunR API token |
| `project` | name in `runr.yaml` | Project name or id |
| `path` | `.` | Project directory (holds `runr.yaml` and the code) |
| `image` | | Container image to deploy (caas projects) |
| `region` | | Region of a project the first deploy creates |
| `message` | `<workflow> <short sha>` | Version message |
| `wait` | `true` | Wait until the deployment is ready; `false` returns once it started |
| `timeout` | `10m` | How long to wait for the build and the deployment |
| `version` | `latest` | CLI release: `latest` or a tag such as `v0.6.0` (pin it for reproducible runs) |
| `cli-base-url` | `https://runr.sh/dl/cli` | Download base of the CLI archives, for a mirror: `<base>/latest.txt` (tag of the latest release), `<base>/<tag>/runr_<os>_<arch>.tar.gz` and `<base>/<tag>/checksums.txt` |
| `secrets` | | Secret environment variables, one `KEY=VALUE` per line, stored write-only before the deploy |
| `args` | | Extra arguments for `runr deploy`, split on whitespace |

## Outputs

| Output | Description |
|---|---|
| `url` | Public URL of the project (empty for schedule and queue triggers) |
| `project` | Project name |
| `project-id` | Project id |
| `version` | Deployed version label, e.g. `v7` |
| `version-id` | Deployed version id |
| `deployment-id` | Deployment id |
| `status` | `active`, or `pending` with `wait: false` |

The action also adds a summary (project, version, URL) to the job page.

## How it works

1. **Resolve** the CLI release: `latest` is the tag in `<cli-base-url>/latest.txt`
   (`https://runr.sh/dl/cli/latest.txt`), a tag is used as given; the runner's OS (Linux,
   macOS, Windows) and architecture (x64, ARM64) pick the archive.
2. **Cache**: the CLI is cached with `actions/cache` per release, OS and architecture, so
   later runs skip the download.
3. **Install**: the archive and the release's `checksums.txt` are downloaded from
   `<cli-base-url>/<tag>/`; the archive's SHA-256 must match before it is unpacked.
4. **Secrets** (with `secrets`): each `KEY=VALUE` is stored with
   `runr env set --secret --no-deploy` (values masked, passed on stdin); a project the first
   deploy is about to create gets them right after that deploy, followed by a second deploy.
5. **Deploy**: `runr deploy` runs with `RUNR_API_URL` and `RUNR_TOKEN` from the inputs (the
   token is masked in the log) and `--output-env "$GITHUB_OUTPUT"`, which writes the outputs.
   The CLI's exit code fails the step: 3 for a rejected token or a permission the token
   lacks, 5 for invalid input, 6 for a failed build or deployment, 7 for a timeout (see
   [exit codes](cli.md#output-errors-and-exit-codes)).

The scripts need `bash`, `curl` and `tar` (`unzip` or PowerShell on Windows); GitHub-hosted
runners have them. Self-hosted runners behind a proxy use `cli-base-url` with a mirror of
the release archives.

## Token scopes

A token with the `deploy` preset holds exactly what `runr deploy` calls:

| Permission | Used for |
|---|---|
| `projects.view` | find the project, read versions and deployment status |
| `regions.view` | resolve `region` of `runr.yaml` |
| `projects.update` | apply `runr.yaml` config changes and `image` |
| `env.edit` | apply the `env` of `runr.yaml` and the `secrets` input (includes reading the variables, `env.view`) |
| `code.edit` | upload the code as a new version |
| `projects.deploy` | start the deployment |
| `projects.create` | (`deploy-create`) create the project on the first run |

A caas-only workflow (`image` input) works without `code.edit`; a `runr.yaml` without `env` and a
workflow without `secrets` work without `env.edit`. Keep the token in a secret,
give it an expiry and rotate it with `runr tokens create` / `runr tokens rm`.

## Other CI systems

The action is a thin wrapper around `runr deploy`; any CI can do the same with
`RUNR_API_URL`, `RUNR_TOKEN` and `--output-env`.

### GitLab CI/CD

Store `RUNR_API_URL` and `RUNR_TOKEN` as CI/CD variables (Settings → CI/CD → Variables; mask
the token), then add a job to `.gitlab-ci.yml`:

```yaml
deploy:
  stage: deploy
  image: alpine:3.21
  variables:
    RUNR_VERSION: v0.6.0
  before_script:
    - apk add --no-cache curl tar
    - curl -fsSL https://runr.sh/install.sh | sh     # installs $RUNR_VERSION
  script:
    - runr deploy services/orders --message "$CI_COMMIT_SHORT_SHA" --output-env deploy.env
  artifacts:
    reports:
      dotenv: deploy.env
  environment:
    name: production
    url: $url
```

## Testing the action

`bash actions/deploy/test/run.sh` runs the action's scripts against a fake release served
from `file://` (archive, `checksums.txt`) and a stub CLI: release resolution, install with
checksum verification (a tampered archive is refused), the arguments of `runr deploy`, the
outputs and the job summary.
