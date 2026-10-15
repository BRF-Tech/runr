## What does this change?

<!-- What changes for users or operators, and why. Link the issue: "Closes #123". -->

## How was it tested?

<!-- Tests added or updated, and manual checks (region, project type, edition). -->

## Checklist

- [ ] Commit messages follow Conventional Commits (`feat(api): …`, `fix(ui): …`)
- [ ] `php artisan test` passes (API changes)
- [ ] `npm run build` passes (console changes)
- [ ] `helm lint helm/` passes (chart changes)
- [ ] Documentation in `docs/` is updated for changed behaviour, configuration or Helm values
- [ ] New database migrations work on PostgreSQL and SQLite
