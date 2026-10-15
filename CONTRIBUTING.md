# Contributing to RunR

Thanks for your interest in RunR. Bug reports, fixes, documentation and new features are all
welcome. This guide explains how to set up a development environment and how changes get merged.

By participating you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md). Security issues
are reported privately — see [SECURITY.md](SECURITY.md), never in a public issue.

## Ways to contribute

- **Report a bug** — open an issue with the bug template: what you did, what you expected, what
  happened, and your RunR version, edition and Kubernetes / Knative versions.
- **Propose a feature** — open an issue with the feature template before you start larger work, so
  the design can be agreed first.
- **Send a merge request** — small, focused changes are easiest to review. Link the issue it
  resolves.

## Development environment

The full setup is described in [docs/development.md](docs/development.md). The short version:

```bash
git clone https://github.com/runrsh/core.git
cd runr
bash scripts/devready.sh docker      # Docker Compose: API, UI, Reverb, Horizon, PostgreSQL, Redis
```

To deploy functions you need a Kubernetes cluster for a region — a local
[kind](https://kind.sigs.k8s.io/) cluster works (`compose/kind-setup.sh`, see
[docs/development.md](docs/development.md)).

Repository layout:

| Path | Contents |
| --- | --- |
| `api/` | Laravel 12 API (PHP 8.3), modules in `api/Modules` (AuthProvider, Tenancy, Ticket) |
| `vue/` | Vue 3 + Vite console |
| `helm/` | Helm chart of the control plane |
| `runtime/` | Runtime images and manifests, the universal wrapper, the region agent, the queue consumer, the image-upload receiver, `ci.sh` |
| `cli/` | The `runr` CLI (Go) |
| `terraform-provider-runr/` | Terraform provider (Go) |
| `actions/` | GitHub Action `actions/deploy` |
| `sdk/` | Workflow and Sandbox SDKs (Python, TypeScript) |
| `examples/` | Runtime, WebAssembly, AI, MCP and workflow examples |
| `proxy/` | Sentry tunnel (Go) |
| `compose/` | Docker Compose deployment for a single host |
| `docs/` | Documentation ([index](docs/README.md)) |

## Making a change

1. Fork the repository and create a branch from `main` (`fix/…`, `feat/…`, `docs/…`).
2. Keep the change focused; unrelated refactors belong in their own merge request.
3. Add or update tests for behaviour you change.
4. Update the documentation in `docs/` when behaviour, configuration or Helm values change.
5. Make sure the checks below pass, then open a merge request against `main` and fill in the
   template.

### Checks

```bash
# API — the CI job uses SQLite in memory (phpunit.xml)
cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan test

# Console (the Monaco editor needs a larger Node.js heap, as in vue/Dockerfile)
cd vue
npm ci
NODE_OPTIONS=--max-old-space-size=4096 npm run build

# Helm chart
helm lint helm/
```

Go modules (`cli/`, `terraform-provider-runr/`, `runtime/agent`, `runtime/queue-consumer`,
`runtime/wrapper`, `runtime/image-upload`, `proxy/`) are checked in their directory with
`gofmt -l . && go vet ./... && go test -race ./...`. Every suite, including Pint, Larastan,
ESLint, Vitest, Playwright and the end-to-end suites on kind, is described in
[docs/development.md](docs/development.md#testing); CI runs the same commands.

### Code style

- **PHP**: PSR-12, typed properties and return types, Laravel conventions (form requests for
  validation, resources for responses, services for logic). Keep controllers thin.
- **Vue**: `<script setup>` single-file components, Composition API, Pinia stores, Tailwind
  utility classes. User-facing strings go through `vue-i18n` (`vue/src/locales`) in English and
  Turkish.
- **Go**: `gofmt`.
- **Comments** explain why, not what.

### Commit messages

RunR uses [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <summary in the imperative, lower case>

<optional body: what changed and why>
```

Common types: `feat`, `fix`, `docs`, `refactor`, `perf`, `test`, `chore`, `ci`. Common scopes:
`api`, `ui`, `helm`, `runtime`, `agent`, `proxy`, `tenancy`, `sandbox`. The changelog is written
from these messages, so describe the user-visible effect.

### Database migrations

- Base-application migrations live in `api/database/migrations`, tenant-schema migrations in
  `api/Modules/Tenancy/Database/TenantMigrations`.
- Never edit a migration that has been released; add a new one.
- Migrations must run on PostgreSQL (production) and SQLite (tests).

## Review and merge

A maintainer reviews every merge request. Expect questions and change requests — they are part of
the process. Once approved and green, a maintainer merges it. Releases are tagged `vX.Y.Z` and
listed in [CHANGELOG.md](CHANGELOG.md).

## License

RunR is released under the [MIT License](LICENSE). By contributing you agree that your
contributions are licensed under the same terms.
