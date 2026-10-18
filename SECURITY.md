# Security Policy

## Supported versions

Security fixes are released for the latest minor version of RunR. Upgrade to the newest
release to receive them.

| Version | Supported |
| ------- | --------- |
| 0.5.x   | Yes       |
| < 0.5   | No        |

## Reporting a vulnerability

Please report vulnerabilities privately by e-mail to **security@runr.sh**. Do not open a
public issue, pull request or discussion for a suspected vulnerability.

Include as much of the following as you can:

- the affected component (API, console UI, Helm chart, region agent, runtime images, universal
  wrapper, CLI, Terraform provider, GitHub Action, SDKs, Sentry tunnel) and version or commit;
- a description of the issue and its impact;
- steps to reproduce, a proof of concept, or the request/response that shows the problem;
- any configuration that is required (edition, enabled modules, sandbox runtime, ingress setup).

## What to expect

- We acknowledge your report within three business days.
- We confirm the issue, assess its severity and keep you informed while we work on a fix.
- We agree a disclosure date with you. Fixes are published in a release with a
  `Security` entry in [CHANGELOG.md](CHANGELOG.md); with your permission we credit you there.

Please give us a reasonable time to release a fix before you disclose the issue publicly,
and do not access, modify or delete data that is not yours while you investigate.

## Scope

In scope: the code in this repository — the Laravel API and its modules, the Vue console,
the Helm chart, the region agent, the runtime images and the universal wrapper, the `runr` CLI,
the Terraform provider, the GitHub Action, the SDKs and the Sentry tunnel.

Out of scope: vulnerabilities in third-party components (Kubernetes, Knative, Traefik,
gVisor, Kata Containers, PostgreSQL, Redis and others) — report those to their projects —
and findings that need an already compromised cluster administrator account.
