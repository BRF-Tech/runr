# RunR API

The RunR control-plane API: a Laravel 12 application (PHP 8.3) served by Octane on FrankenPHP.
The same image also runs the queue workers (Horizon), the scheduler and the WebSocket server
(Reverb); `docker/entrypoint.sh` selects the role from `CONTAINER_ROLE`.

| Path | Contents |
| --- | --- |
| `app/` | Controllers, form requests, resources, models, jobs, listeners and services (Knative, Helm, Kubernetes, registry, sandbox, builds) |
| `Modules/` | Optional modules: `Tenancy`, `AuthProvider`, `Ticket` (nwidart/laravel-modules) |
| `routes/api.php` | REST API; `routes/console.php` holds the scheduled tasks |
| `database/` | Migrations, factories and seeders (tenant-schema migrations live in `Modules/Tenancy/Database/TenantMigrations`) |
| `docker/` | Entrypoint and server configuration of the image |
| `tests/` | PHPUnit feature and unit tests |

## Tests

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

The test suite runs on SQLite in memory (`phpunit.xml`).

See the [repository README](../README.md), [docs/architecture.md](../docs/architecture.md) and
[docs/development.md](../docs/development.md).
