<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditCatalog;
use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRoute;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit trail of the API (docs/security.md, "Audit log"). Outermost middleware of the `api` group,
 * so it sees the final response of everything inside — authentication, tenant identification,
 * throttling, validation, the controller:
 *
 *  - a route of AuditCatalog is recorded under its action, with its target, non-secret details
 *    and the model changes the request made (AuditChanges)
 *  - any other route answered 403 is recorded as authorization.denied
 *
 * Also gives every API request a request id (X-Request-ID response header, in every audit event
 * of the request).
 */
class RecordAuditTrail
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AuditChanges $changes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('audit.trail')) {
            return $next($request); // group applied twice (module routes repeat `api`)
        }
        $request->attributes->set('audit.trail', true);

        $requestId = AuditLogger::requestId($request);
        $this->changes->start();

        try {
            $response = $next($request);
        } finally {
            $changes = $this->changes->stop();
        }

        $response->headers->set('X-Request-ID', $requestId);

        try {
            $this->recordFor($request, $response, $changes);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function recordFor(Request $request, Response $response, array $changes): void
    {
        if (in_array($request->method(), ['OPTIONS', 'HEAD'], true)) {
            return;
        }

        $status = $response->getStatusCode();
        $entry = AuditCatalog::forRequest($request);

        if (! $entry) {
            if ($status === 403) {
                $this->audit->record('authorization.denied', [
                    'result' => AuditEvent::RESULT_DENIED,
                    'target' => $this->firstBoundModel($request),
                    'status_code' => $status,
                    'metadata' => ['message' => $this->message($response)],
                ]);
            }

            return;
        }

        // Not signed in (expired token…) is not an audited decision — except for the login itself
        if ($status === 401 && $entry->actor !== 'login') {
            return;
        }

        $isLogin = $entry->actor === 'login';

        // POST /auth/login answering 202 = second factor requested; the follow-up is the event
        if ($isLogin && $status === 202) {
            return;
        }

        $json = $this->json($response);
        $action = $entry->action instanceof Closure ? (string) ($entry->action)($request, $json) : $entry->action;
        $result = match (true) {
            $status < 400 => AuditEvent::RESULT_SUCCESS,
            $isLogin && $status === 401 => AuditEvent::RESULT_FAILURE, // wrong credentials
            in_array($status, [401, 403, 419, 429], true) => AuditEvent::RESULT_DENIED,
            default => AuditEvent::RESULT_FAILURE,
        };

        $metadata = $entry->meta ? (array) ($entry->meta)($request, $json) : [];
        if ($isLogin) {
            $metadata['method'] = 'password';
        }
        if ($status >= 400) {
            $metadata['reason'] = $isLogin && $status === 401 ? 'invalid_credentials' : $this->reason($status);
        }
        if ($changes) {
            $metadata['changes'] = $changes;
        }

        $data = [
            'result' => $result,
            'status_code' => $status,
            'target' => $this->target($entry, $request, $json),
            'metadata' => $metadata,
        ];

        if ($isLogin) {
            if ($user = $this->userFrom($json['user']['id'] ?? null)) {
                $data['actor'] = $user;
                $data['tenant_id'] = $json['active_tenant']['id'] ?? null;
            } else {
                // Who tried: the login name as typed (never the password)
                $data['actor_name'] = mb_substr((string) $request->input('login'), 0, 255);
            }
        } elseif (is_string($entry->actor) && str_starts_with($entry->actor, 'response:')) {
            if ($user = $this->userFrom($this->fromJson($json, substr($entry->actor, 9)))) {
                $data['actor'] = $user;
            }
        }

        $this->audit->record($action, $data);
    }

    private function target(AuditRoute $entry, Request $request, ?array $json): ?array
    {
        if (! $entry->targetType) {
            return null;
        }

        $id = $this->resolve($entry->targetId, $request, $json);
        $name = $this->resolve($entry->targetName, $request, $json);

        if ($id instanceof Model) {
            $name = $name ?? AuditCatalog::displayName($id);
            $id = $id->getKey();
        }
        if ($name instanceof Model) {
            $name = AuditCatalog::displayName($name);
        }

        if ($id === null && $name === null) {
            return ['type' => $entry->targetType];
        }

        return [
            'type' => $entry->targetType,
            'id' => is_scalar($id) ? (string) $id : null,
            'name' => is_scalar($name) ? (string) $name : null,
        ];
    }

    private function resolve(string|Closure|null $source, Request $request, ?array $json): mixed
    {
        if ($source === null) {
            return null;
        }
        if ($source instanceof Closure) {
            return $source($request, $json);
        }

        [$kind, $key] = array_pad(explode(':', $source, 2), 2, '');

        return match ($kind) {
            'param' => $request->route($key),
            'input' => is_scalar($v = $request->input($key)) ? $v : null,
            'response' => $this->fromJson($json, $key),
            default => null,
        };
    }

    /** A value at $path of the response JSON, with or without the resource "data" wrapper. */
    private function fromJson(?array $json, string $path): mixed
    {
        if (! $json || $path === '') {
            return null;
        }

        $value = data_get($json, $path);
        if ($value === null) {
            $value = str_starts_with($path, 'data.') ? data_get($json, substr($path, 5)) : data_get($json, 'data.'.$path);
        }

        return is_scalar($value) ? $value : null;
    }

    private function json(Response $response): ?array
    {
        $type = (string) $response->headers->get('Content-Type');
        if (! str_contains($type, 'json')) {
            return null;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '' || strlen($content) > 2_000_000) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function userFrom(mixed $id): ?User
    {
        if (! is_scalar($id) || $id === '') {
            return null;
        }

        try {
            // Users live in the central schema (multi-tenant) or the only one
            $model = new User;
            if (config('database.connections.central')) {
                $model->setConnection('central');
            }
            /** @var Builder<User> $query */
            $query = $model->newQuery();
            $user = $query->whereKey($id)->first();

            return $user instanceof User ? $user : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstBoundModel(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    private function reason(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 419 => 'session_expired',
            $status === 422 => 'invalid',
            $status === 429 => 'throttled',
            $status >= 500 => 'error',
            default => 'http_'.$status,
        };
    }

    private function message(Response $response): ?string
    {
        $message = $this->json($response)['message'] ?? null;

        return is_string($message) ? mb_substr($message, 0, 255) : null;
    }
}
