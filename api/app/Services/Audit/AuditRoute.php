<?php

namespace App\Services\Audit;

use Closure;

/**
 * How one API route is audited (AuditCatalog::routes()).
 *
 *   action      action name, or fn (Request, ?array $json): string
 *   targetType  e.g. "project"
 *   targetId    "param:<route parameter>" (a bound model also gives the name), "response:<path>",
 *               "input:<key>", or fn (Request, ?array $json): ?string
 *   targetName  same sources as targetId (optional)
 *   meta        fn (Request, ?array $json): array — non-secret details (SecretRedactor runs over it)
 *   actor       "login" (POST /auth/login: the user from the response, else the login name) or
 *               "response:<path>" (unauthenticated routes that sign a user in)
 */
final class AuditRoute
{
    public function __construct(
        public readonly string|Closure $action,
        public readonly ?string $targetType = null,
        public readonly string|Closure|null $targetId = null,
        public readonly string|Closure|null $targetName = null,
        public readonly ?Closure $meta = null,
        public readonly ?string $actor = null,
    ) {}
}
