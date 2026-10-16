# Git deploy

RunR deploys straight from git. Every project has its own git remote on the console domain —
`git push runr main` creates a new version and deploys it — and a project can be connected to a
repository on GitHub, GitLab or Forgejo/Gitea instead: a push to the deploy branch deploys, and
every pull/merge request gets its own preview environment with its own URL.

- [The RunR git remote](#the-runr-git-remote)
- [What a push does](#what-a-push-does)
- [runr.yaml](#runryaml)
- [Which files become the version](#which-files-become-the-version)
- [Monorepos](#monorepos)
- [Connecting a provider repository](#connecting-a-provider-repository)
- [GitHub App setup](#github-app-setup)
- [GitLab](#gitlab)
- [Forgejo and Gitea](#forgejo-and-gitea)
- [Branch rules](#branch-rules)
- [Preview environments](#preview-environments)
- [Status on the provider](#status-on-the-provider)
- [Deploy history](#deploy-history)
- [Permissions](#permissions)
- [Security](#security)
- [API](#api)
- [CLI](#cli)
- [MCP tools](#mcp-tools)
- [Configuration and Helm values](#configuration-and-helm-values)
- [Upgrading an existing install](#upgrading-an-existing-install)
- [How it works](#how-it-works)
- [Testing](#testing)

## The RunR git remote

The remote URL is shown on the project's **Git** tab:

```text
https://<console>/git/<organization>/<project>.git
```

`<organization>` is the organization's slug in a multi-tenant install and `runr` (Helm value
`gitDeploy.remoteOwner`) in a single-tenant install; `<project>` is the project's slug
(its id works too). The console URL in front is `GIT_CONSOLE_URL`, else `FRONTEND_URL`, else
`APP_URL`.

```sh
git remote add runr https://console.example.com/git/runr/orders-api.git
git push runr main
```

Git asks for credentials: the user name can be anything, the password is an
[API token](permissions.md#api-tokens) (Settings → API Tokens) that carries `git.push` — or
`git.pull` for clone and fetch only. A token without scopes (full access) works within its owner's
permissions. Use a credential helper to keep it:

```sh
git config --global credential.https://console.example.com.helper store
```

A token can also go into the URL as the user name with an empty password
(`https://<token>@console.example.com/git/...`) — handy in CI, never in a shared shell history.

The first fetch of a project that already has versions finds its latest version committed on
the deploy branch ("Import <project> v<N> from RunR"), so `git clone` starts from the code that
runs. The remote speaks git's smart HTTP protocol (git 1.6.6 or newer); shallow clones, partial
clones (`--filter`) and protocol v2 work.

A project connected to a provider repository keeps its RunR remote **read-only**: it can be
cloned, a push is answered with `remote error: <project> deploys from <repository>; push there`.

## What a push does

A push to the **deploy branch** (default `main`, set on the Git tab):

1. is accepted as long as it is a fast-forward — `git push --force` is refused
   (`non-fast-forward`) unless the project allows force pushes; the deploy branch cannot be
   deleted;
2. prints what happens next (`RunR: 3f2c1aa on main received. A new version is created and
   deployed.` and the project URL);
3. becomes a new **version** of the project: the [runr.yaml](#runryaml) is applied, the
   [files](#which-files-become-the-version) of the commit are saved exactly like the browser
   editor saves them, and the version records the commit (SHA, branch, author, message);
4. is built when the runtime needs a build (compiled languages, dependency files) and, with
   **auto-deploy** on, deployed once it is ready. With auto-deploy off the version waits for a
   deploy from the Deployments tab. The deploy goes through the same checks as one from the
   console: [data residency](supply-chain.md#data-residency) and the
   [policy gate](supply-chain.md#policy-gate). While the security scan or signature of the
   version still runs, the deploy waits for it and then continues; a version the policy blocks
   is not deployed (the reason is in the deploy history and the commit status) and can be
   deployed from the console with a policy override.

A push whose tree equals the latest version creates nothing and deploys nothing ("No changes"),
unless that version is not live — then it is deployed. Pushes to other branches are stored on
the remote and deploy nothing. **Deploy again** on the Git tab deploys the head of the deploy
branch once more.

Code saved in the browser editor is committed on top of the deploy branch of the remote
("Saved in the RunR editor as v8"), so `git pull` brings it down and your next push stays a
fast-forward. When the editor saved after your last pull, the push is refused like any
non-fast-forward push: pull (or rebase) and push again.

## runr.yaml

A `runr.yaml` (or `runr.yml`) at the root of the pushed tree is the same project file the
[CLI](cli.md#runryaml) uses; the server reads the keys it knows and ignores the others. Only
the keys present are applied — a key left out keeps the value the project has:

| Key | Applied as |
| --- | --- |
| `name`, `type`, `runtime` | compared with the project; a difference is a warning (a push never renames or retypes a project) |
| `region` | compared; a push never moves a project (use the console or `runr deploy`) |
| `runtime_version` | the project's runtime version, when the runtime has it |
| `description` | the project's description |
| `handler` | faas: `file.function`; xaas: the function name |
| `file` | xaas: the script |
| `image`, `command`, `args`, `ports` | caas projects |
| `resources.cpu`, `.memory_mb`, `.timeout_seconds` | checked against the region's capacity (and the organization quota) |
| `scale.min`, `.max`, `.to_zero` | scaling |
| `trigger.type`, `.schedule`, `.queue` | the project's trigger |
| `env` | merged into the project's variables: listed keys are set, others stay; non-secret values only |
| `ignore` | [files](#which-files-become-the-version) that are not part of the version |
| `dev` | local only (`runr dev`), ignored |

Every value goes through the validation of the project's Edit dialog. A value that does not
pass (an unknown runtime version, a size above the region capacity, a cron expression that is
not one) is left out with a warning on the deploy — the rest of the file still applies. The
warnings and the applied keys are shown on the deploy in the Git tab's history.

A container (`caas`) project has no code: a push applies its `runr.yaml` (image, env, scale, …)
as a new config version and deploys it.

## Which files become the version

The same files `runr deploy` uploads:

- never: `.git`, `.hg`, `.svn`, `.runr`, `.idea`, `.vscode`, `.DS_Store`, `Thumbs.db`,
  `node_modules`, `vendor`, `__pycache__`, `*.pyc`, `.venv`, `venv`, `.env`, `.env.*`,
  `.gitignore`, `.runrignore`, `runr.yaml` and `runr.yml`;
- the patterns of `ignore:` in `runr.yaml` and the lines of a `.runrignore` file next to it. A
  pattern without `/` matches any path element (`tests`, `*.md`); with `/` it matches the path
  (`docs/*.md`);
- other hidden files (a path element starting with `.`) are left out and listed on the deploy;
- symlinks and submodules are left out and listed.

A **faas** project's files form a flat set (each file becomes a key of the project's
ConfigMap): files in subdirectories, files that are not UTF-8 text and empty files fail the
deploy with the list of offending paths — move them, or add them to `ignore`. A version holds
at most 1,000 files and 1,000,000 bytes of names plus contents (`GIT_MAX_IMPORT_FILES` sets the
file count, `GIT_MAX_IMPORT_BYTES` can only lower the byte limit). The handler file must exist
and hold the handler function. An **xaas** project takes its one script; other files are
listed as left out.

## Monorepos

Set the **root directory** on the Git tab (`services/api`): only that directory becomes the
project's version, its paths relative to it. The `runr.yaml` is read from the root directory,
else from the repository root. Several RunR projects can be connected to the same provider
repository, each with its own root directory; one push deploys every project whose deploy
branch it updates.

## Connecting a provider repository

The providers are added once in **Settings → Git providers**: in the single-tenant edition by
whoever holds `git_providers.manage`, in the multi-tenant edition by the platform administrators
(every organization shares the providers; `git_providers.manage` exists in the single-tenant
edition only). Then, on a project's Git tab, **Provider repository**:

| Provider | How the project connects |
| --- | --- |
| GitHub | the organization's installation of the GitHub App (**Install the app** links one) |
| GitLab | **Sign in with GitLab** (when the provider has an OAuth application) or an access token |
| Forgejo / Gitea | an access token |

**Show repositories** lists what the credentials can deploy from; **Connect** checks that the
repository can be read, stores the credentials encrypted and registers the webhook (GitLab and
Forgejo/Gitea; the GitHub App brings its own). One repository per project; **Disconnect**
removes the webhook, the previews and the connection — the project keeps its versions.

## GitHub App setup

1. On GitHub: Settings → Developer settings → GitHub Apps → **New GitHub App** (for an
   organization: the organization's settings).
2. In RunR: Settings → Git providers → **Add provider** → GitHub. Enter a name, generate the
   **webhook secret**, and save once to see the URLs to enter at GitHub (or fill in everything
   at once and copy the URLs afterwards).
3. In the GitHub App settings:
   - **Callback URL** and **Setup URL**: `https://<console>/api/git/github/setup`; tick
     **Request user authorization (OAuth) during installation** and **Redirect on update**;
   - **Webhook URL**: `https://<console>/api/git/webhooks/github/<provider id>`, **Webhook
     secret**: the generated secret;
   - **Repository permissions**: Contents *read*, Metadata *read*, Commit statuses *read and
     write*, Deployments *read and write*, Pull requests *read and write*;
   - **Subscribe to events**: Push, Pull request.
4. Generate a **private key** and a **client secret** on GitHub and enter them with the **App
   ID**, the **app slug** (the last part of `https://github.com/apps/<slug>`) and the **client
   ID** in RunR.

For GitHub Enterprise Server, the web URL is the server's address (`https://github.example.com`);
the API URL is derived (`/api/v3`) unless entered.

Installing: **Install the app** on the Git tab opens GitHub's installation page; after
installing (or adding repositories to an existing installation) GitHub sends the user back to
the Git tab. RunR links an installation to the organization only when GitHub confirms, through
the user authorization of the installation, that this GitHub user can access it — an
installation id in a URL is never trusted on its own.

## GitLab

Settings → Git providers → **Add provider** → GitLab: `https://gitlab.com` or a self-managed
instance's address.

- **Access token**: a personal, group or project access token with the `api` scope and the
  Maintainer role on the project (RunR creates the project hook with it).
- **Sign in with GitLab** (optional): create an application in GitLab (User/Group/Admin settings
  → Applications) with the scope `api`, confidential, the redirect URI
  `https://<console>/api/git/oauth/gitlab/callback`, and enter its application ID and secret as
  client ID / client secret. RunR refreshes the OAuth token when it expires.

RunR registers a project hook for push and merge request events with a random secret token
(`X-Gitlab-Token`), at `https://<console>/api/git/webhooks/gitlab/<connection id>`.

## Forgejo and Gitea

Settings → Git providers → **Add provider** → Forgejo / Gitea with the instance's address. A
project connects with a personal access token with read and write access to repositories (the
webhook, commit statuses and source archives) and read access to the user; the preview comment
goes through the issue comments API, which a scoped token reaches with write access to issues.
RunR registers a webhook (push, pull request, pull request sync) signed with HMAC-SHA256 at
`https://<console>/api/git/webhooks/forgejo/<connection id>`.

## Branch rules

| Event | Result |
| --- | --- |
| push to the deploy branch | production deploy (as [above](#what-a-push-does)) |
| push to another branch, branch deleted | nothing (a request from that branch gets a preview) |
| pull/merge request opened, reopened, new commits | preview, when previews are on |
| pull/merge request closed or merged | the preview is deleted |
| other events (labels, reviews, title changes) | nothing |

## Preview environments

Turn on **Previews of pull requests** on the Git tab. Every open pull/merge request then gets:

- its own Knative Service `<project>-pr-<number>` in the project's region (`<project>` is the
  project's slug, shortened so the name stays within 63 characters), with its own URL
  `https://<project>-pr-<number>.<function domain>` — the same domain scheme as the project
  (a project on a gateway route gets its preview on the function domain);
- the request's head commit, built like a version of the project (preview versions are kept
  apart: they never show up in the project's versions and never become its latest version);
- the project's environment variables, with the **environment overrides** of the Git tab set
  over them (non-secret `KEY=value` lines) and `RUNR_PREVIEW_PR=<number>`;
- scale to zero (at most three pods), so an idle preview costs nothing.

On every new commit the preview is rebuilt and redeployed; RunR keeps one comment on the
request up to date with the status, commit and URL. Closing or merging the request deletes the
preview; a preview without a new commit for **Delete after** hours (default 72, Helm value
`gitDeploy.previews.ttlHours`, at most `GIT_PREVIEW_MAX_TTL_HOURS`) is deleted by the hourly
cleanup; **Delete** on the Git tab removes it at once (a new commit creates it again). Turning
previews off deletes every live preview of the project.

Rules:

- **Limit**: at most **Live previews at most** previews per project (default 3, Helm value
  `gitDeploy.previews.limit`; a project may set up to `gitDeploy.previews.maxLimit`, default 20);
  a further request gets "Not deployed" with the reason in its comment until another preview is
  gone.
- **Forks**: requests from forks get no preview unless **Previews of requests from forks** is
  on. Even then a fork's preview runs **without the project's secret variables** — every
  variable backed by a Kubernetes Secret (`secretKeyRef`, `envFrom` secrets, private database
  connections) is removed from it — unless **Give fork previews the secret variables** is on.
- **Triggers**: previews exist for HTTP-triggered code projects (faas, xaas).
- **Residency and policy**: a preview is a deployment in the project's region: the
  organization's data residency (`preview` entry point) and the region's policy gate apply as
  for production; a preview waits for the security checks of its version like a push does.
- **Quota**: in a multi-tenant install a preview's reservation (one pod of the project's size
  that scales to zero) counts against the FaaS overcommit budget of the organization quota on the
  region, next to the projects ([Organization quotas](architecture.md#region-capacity-and-admission));
  a preview that does not fit is not deployed.

## Status on the provider

RunR reports every deploy back:

- a **commit status** `runr` (context name: `GIT_STATUS_CONTEXT`) on the commit — pending while
  the version is created, built and deployed, success when it is live (or nothing changed, or
  the version was created with auto-deploy off), failure with the reason; the link opens the
  project's Git tab (a ready preview links its URL);
- on GitHub, a **deployment** (Deployments API) per production deploy (`production`) and per
  preview (`preview/pr-<n>`, transient) with the URL as environment URL; a deleted preview's
  deployment becomes inactive;
- for previews, the **comment** on the pull/merge request.

## Deploy history

The Git tab lists every git deploy — production and preview — with time, commit (SHA linked to
the provider), branch, author, message, the version it created and its status; a row opens the
details: the reason of a failure, the runr.yaml keys applied, warnings and the files left out.
The Versions list shows each version's commit message; the commit of every git-created version
is available from `GET /api/projects/{project}/git/versions`.

## Permissions

| Permission | Allows |
| --- | --- |
| `git.pull` | clone and fetch the remote; see the Git tab, history and previews |
| `git.push` | push to the remote; Deploy again |
| `git.manage` | deploy branch, root directory, auto-deploy, force pushes; connect and disconnect repositories |
| `previews.manage` | preview settings (together with `git.manage`, which every change of the Git tab settings needs); redeploy and delete previews |
| `git_providers.manage` | Settings → Git providers; single-tenant edition only (in multi-tenant installs the platform administrators configure the providers) |

`git.push`, `git.manage` and `previews.manage` imply `git.pull`. The Developer role holds the
four project permissions, the Viewer role `git.pull`; see
[Roles and permissions](permissions.md#git--previews).

## Security

- **Authentication of the remote**: API tokens only (never account passwords, so two-factor
  authentication cannot be bypassed), checked against the token's scopes and its owner's
  permissions on the project; expired tokens and disabled accounts are refused. Unknown
  organizations and projects the token cannot read answer 404. Failed sign-ins are limited per
  client IP (`GIT_AUTH_ATTEMPTS_PER_MINUTE`, default 30).
- **Push limits**: one push sends at most `gitDeploy.maxPushMb` (default 50 MB,
  `receive.maxInputSize`); objects are checked (`receive.fsckObjects`).
- **Webhooks** are authenticated by their signature or token only: GitHub
  `X-Hub-Signature-256` (HMAC-SHA256 with the app's webhook secret), GitLab `X-Gitlab-Token`
  (the connection's random secret), Forgejo/Gitea `X-Forgejo-Signature` / `X-Gitea-Signature`
  (HMAC-SHA256). Comparisons are constant-time; a rejected webhook is audited. Each delivery is
  processed once (`git_webhook_deliveries`, kept 30 days): a replayed delivery has no effect.
  The webhook and callback endpoints accept at most 600 requests per minute per client IP.
- **Secrets at rest**: the GitHub App private key, client secrets, webhook secrets and access
  tokens are encrypted with the application key and never returned by the API (the provider
  list says only whether one is stored). GitHub installation tokens (valid one hour) are cached
  encrypted for 50 minutes.
- **GitHub installations** are linked to an organization only after GitHub confirms the
  installing user's access ([above](#github-app-setup)); the OAuth state of every round trip
  is encrypted, bound to the user, organization, project and provider, and valid 15 minutes.
- **Code from forks** runs in its own preview service, scaled to zero, without secrets by
  default and only when the project allows fork previews at all.
- **Secrets of previews**: a preview gets the project's secret env vars in its own Kubernetes
  Secret `<project>-pr-<number>-secret-env`, referenced with `secretKeyRef` — never as values in
  the service spec — and deleted with the preview. A preview of a fork gets no Secret unless
  the project allows it (`fork_secrets`). The preview env overrides are stored encrypted, like
  every env var.
- **No caching**: the Git tab, deploy history and preview responses are sent with
  `Cache-Control: no-store` (live state, env override values).
- **Audit**: every change is in the audit log ([audited events](security.md#audited-events)), with field
  and env var names but never values or tokens:

  | Action | Recorded for |
  | --- | --- |
  | `git.push` | refs updated on the RunR remote (before/after per ref); a push without `git.push`, or to a project connected to a provider repository, is recorded with result `denied` |
  | `git.settings.update` | `PUT /api/projects/{project}/git` (changed fields, preview env names) |
  | `git.redeploy` | `POST /api/projects/{project}/git/redeploy` |
  | `git.repo.connect`, `git.repo.disconnect` | connecting / disconnecting a provider repository |
  | `git.installation.link` | a GitHub App installation linked to the organization |
  | `git.preview.redeploy`, `git.preview.delete` | previews redeployed / deleted by hand |
  | `git_provider.create`, `git_provider.update`, `git_provider.delete` | Settings → Git providers |
  | `git.webhook.rejected` | a webhook with a bad signature or token |

  The same actions are raised as `GitDeployAudited` events for integrations.

## API

| Method and path | Permission | |
| --- | --- | --- |
| `GET /api/projects/{project}/git` | `git.pull` | remote URL, settings, connection, providers, abilities |
| `PUT /api/projects/{project}/git` | `git.manage` (preview keys also `previews.manage`) | `deploy_branch`, `root_dir`, `auto_deploy`, `allow_force_push`, `previews_enabled`, `preview_limit`, `preview_ttl_hours`, `preview_env`, `fork_previews`, `fork_secrets` |
| `GET /api/projects/{project}/git/deploys` | `git.pull` | deploy history (`kind`, `per_page`) |
| `GET /api/projects/{project}/git/versions` | `git.pull` | commit of each git-created version |
| `POST /api/projects/{project}/git/redeploy` | `git.push` | deploy the deploy branch head again |
| `POST /api/projects/{project}/git/repos` | `git.manage` | repositories of `provider_id` with `installation_id` / `access_token` / `token_ref`, `search` |
| `POST /api/projects/{project}/git/connect` | `git.manage` | connect `repository` (full name or id) with the same credentials |
| `DELETE /api/projects/{project}/git/connect` | `git.manage` | disconnect |
| `GET /api/projects/{project}/git/installations` | `git.pull` | GitHub App installations of the organization |
| `POST /api/projects/{project}/git/github/install` | `git.manage` | GitHub App installation URL |
| `POST /api/projects/{project}/git/gitlab/authorize` | `git.manage` | GitLab sign-in URL |
| `GET /api/projects/{project}/previews` | `git.pull` | previews (`all=1`: deleted ones too) |
| `POST /api/projects/{project}/previews/{preview}/redeploy` | `previews.manage` | deploy a preview again |
| `DELETE /api/projects/{project}/previews/{preview}` | `previews.manage` | delete a preview |
| `GET`, `POST /api/git-providers`, `PUT`, `DELETE /api/git-providers/{provider}` | `git_providers.manage` | providers (secret fields write-only, empty = keep) |
| `POST /api/git/webhooks/github/{provider}`, `…/gitlab/{connection}`, `…/forgejo/{connection}` | signature | provider webhooks |
| `GET /api/git/github/setup`, `GET /api/git/oauth/gitlab/callback` | signed state | browser round trips |
| `GET /git/{org}/{project}.git/info/refs`, `POST …/git-upload-pack`, `POST …/git-receive-pack` | API token (Basic) | the git remote |

## CLI

`runr git` ([CLI: git deploy](cli.md#git-deploy-runr-git)) works with the same routes and
permissions:

```sh
runr git                                    # remote, settings, repository, the last deploys
runr git remote --add                       # git remote add runr https://<console>/git/<org>/<project>.git
runr git set --branch release --root-dir services/api --auto-deploy=false
runr git connect --provider github --repo acme/orders --previews
runr git previews ls                        # also: runr previews
runr git providers                          # git_providers.manage
```

## MCP tools

The RunR MCP server ([AI agents](mcp.md)) offers git deploy to agents: `git_status` (the remote
URL and how to push to it), `git_settings_update`, `git_deploys_list`, `git_redeploy` (follows
the rollout), `git_repositories_list`, `git_repo_connect`, `git_repo_disconnect`,
`previews_list`, `preview_redeploy`, `preview_delete` and `git_providers_list` — through the
routes above, with the same permissions. Registering a provider (GitHub App, GitLab application)
and the browser steps of connecting a repository stay in the console.

## Configuration and Helm values

| Helm value | Environment | Default | |
| --- | --- | --- | --- |
| `gitDeploy.enabled` | `GIT_DEPLOY_ENABLED` | `true` | off: the remote and the webhooks answer 404, runr-gateway has no `/git` route |
| `gitDeploy.maxPushMb` | `GIT_MAX_PUSH_MB` | `50` | largest push (stays below the API's 64 MB request limit) |
| `gitDeploy.remoteOwner` | `GIT_REMOTE_OWNER` | `runr` | owner segment of remote URLs (single organization) |
| `gitDeploy.webhookBaseUrl` | `GIT_WEBHOOK_BASE_URL` | the console URL | where providers reach the webhooks and callbacks |
| `gitDeploy.previews.limit` | `GIT_PREVIEW_LIMIT` | `3` | default preview limit of a project |
| `gitDeploy.previews.ttlHours` | `GIT_PREVIEW_TTL_HOURS` | `72` | default preview lifetime without a commit |
| `gitDeploy.previews.maxLimit` | `GIT_PREVIEW_MAX_LIMIT` | `20` | highest limit a project may set |
| via `extraEnv` | `GIT_PREVIEW_MAX_TTL_HOURS` | `720` | longest lifetime a project may set |
| via `extraEnv` | `GIT_MAX_IMPORT_FILES` / `GIT_MAX_IMPORT_BYTES` | `1000` / `1000000` | limits of one version's code (the byte limit can only be lowered) |
| via `extraEnv` | `GIT_AUTH_ATTEMPTS_PER_MINUTE` | `30` | failed sign-ins on the remote per client IP and minute |
| via `extraEnv` | `GIT_STATUS_CONTEXT` | `runr` | commit status context |
| via `extraEnv` | `GIT_CONSOLE_URL` | `FRONTEND_URL`, else `APP_URL` | console URL in links and remote URLs |
| via `extraEnv` | `GIT_REMOTES_PATH` | `<storage>/git-remotes` | bare repositories (on the API storage volume) |
| via `extraEnv` | `GIT_PROVIDER_HTTP_TIMEOUT` | `20` | seconds per provider API call |
| via `extraEnv` | `GIT_BACKEND_TIMEOUT` | `300` | seconds a git transfer may take |
| via `extraEnv` | `GIT_BINARY` | `git` | the git executable of the API image |

The remotes live on the API's storage volume next to the internal repositories, so the
`api-storage` volume carries them across upgrades.

## Upgrading an existing install

- The migration adds the git deploy tables; nothing changes for a project until it is used
  through git. A project's remote is created on the first fetch or push and starts with the
  project's latest version committed on the deploy branch.
- Defaults of every project: deploy branch `main`, auto-deploy on, force pushes refused,
  previews off (fork previews and fork secrets off).
- `gitDeploy.enabled` defaults to `true`: the gateway routes `/git` to the API. Set it to
  `false` to keep the remotes and the webhooks switched off.
- The built-in Developer role holds `git.pull`, `git.push`, `git.manage` and
  `previews.manage`, the Viewer role `git.pull`; the Member role the upgrade creates for 0.4.x
  tenant members holds the four, and 0.4.x token scopes translate to `git.pull`
  (`projects:read`) and `git.pull`, `git.push` (`projects:write`). Custom roles get the git
  permissions when they are added to them.

## How it works

- **Remote**: every project has its own bare repository
  (`git-remotes/<organization>/<project id>.git`; `<organization>` is `_` in the single-tenant
  edition). The API serves it with `git http-backend`:
  the request body is spooled to a temporary file and handed to the backend, its CGI response
  is streamed back; per-request git configuration sets the fast-forward rule, the size limit and
  whether pushes are allowed; small hooks print the push summary and protect the deploy branch.
  This keeps one process model (the API pods, which already mount the storage volume and run
  git for the internal repositories), one authentication (API tokens and permissions) and the
  organization context of the request.
- **Import**: after `git-receive-pack` finished, the API compares the branch heads; a new head
  of the deploy branch is imported by a queued job (one per project at a time): the tree is read
  with one `cat-file` batch, the runr.yaml applied, the files committed into the organization's
  internal repository through the editor's code path, which creates the version and its build.
- **Providers**: webhooks are verified and recorded, then a queued job fetches the commit's
  source archive (GitHub tarball, GitLab and Forgejo archive; read as a stream, only the root
  directory is kept in memory) and runs the same import. Previews store their tree as a commit
  under `refs/runr/previews/<n>` of the project's remote and build from it.
- **Deploys** use the regular pipeline (Kaniko builds in the region, Knative Service); a
  preview's manifests are the project's manifests with the preview's name, labels
  (`runr/preview-of`, `runr.dev/preview`) and environment.

## Testing

| Suite | Command |
| --- | --- |
| API feature and unit tests (`tests/Feature/GitDeploy`, `tests/Unit/GitDeploy`) | `cd api && php artisan test --filter=GitDeploy` |
| UI component tests | `cd vue && npx vitest run tests/components/git` |
| UI end-to-end (mocked API) | `cd vue && npx playwright test e2e/git-deploy.spec.ts --project=mock` |
| Kind end-to-end: `git push runr main`, a throwaway Forgejo, a pull request preview | `KIND_LOCK=/tmp/runr-kind.lock runtime/ci.sh test-git-deploy` |
