<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Support\Rbac\Rbac;
use App\Support\Secrets\SecretRedactor;
use App\Support\TenantAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Writes audit events (docs/security.md, "Audit log").
 *
 *   app(AuditLogger::class)->record('auth.sso.login', [
 *       'actor'    => $user,                 // Authenticatable | 'system' | null (= the request's user)
 *       'target'   => $model,                // Model | ['type' => …, 'id' => …, 'name' => …]
 *       'result'   => AuditEvent::RESULT_SUCCESS,
 *       'metadata' => ['provider' => 'google'],
 *   ]);
 *
 * Context — tenant, IP, user agent, request id, route — comes from the current request unless
 * 'context' overrides it (the Reverb shell has no HTTP request). Metadata runs through
 * SecretRedactor; secret values never reach the row. A failure to write is reported, never
 * thrown: auditing does not break the action it records. With forwarding on, every event is
 * also written as one JSON line to the `audit` log channel (SIEM).
 */
class AuditLogger
{
    private const MAX_METADATA_BYTES = 16384;

    private const COLUMNS = [
        'occurred_at', 'tenant_id', 'actor_type', 'actor_id', 'actor_name', 'actor_email', 'auth_method',
        'impersonator_id', 'impersonator_name', 'action', 'result', 'target_type', 'target_id', 'target_name',
        'ip_address', 'user_agent', 'request_id', 'http_method', 'route', 'status_code', 'metadata',
    ];

    public function record(string $action, array $data = []): ?AuditEvent
    {
        if (! config('audit.enabled', true)) {
            return null;
        }

        try {
            $event = new AuditEvent($this->attributes($action, $data));
            $event->save();
        } catch (\Throwable $e) {
            Log::warning('Audit event not recorded', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }

        $this->forward($event);

        return $event;
    }

    /** Actor, tenant and request fields of the current request — what a shell session carries along. */
    public function currentContext(?Request $request = null): array
    {
        $request ??= $this->request();
        $user = $this->requestUser();
        $tenantId = TenantAccess::currentId();

        return array_merge(
            $this->actorFields($user, $request),
            $this->impersonatorFields($user, $tenantId),
            [
                'tenant_id' => $tenantId,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
                'request_id' => $request ? self::requestId($request) : null,
            ],
        );
    }

    /** The request id of $request: the client's X-Request-ID when well-formed, else a new ULID. */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $incoming = (string) $request->headers->get('X-Request-ID', '');
        $id = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $incoming) ? $incoming : (string) Str::ulid();
        $request->attributes->set('request_id', $id);

        return $id;
    }

    private function attributes(string $action, array $data): array
    {
        $request = $data['request'] ?? $this->request();

        $actor = $data['actor'] ?? null;
        $fields = match (true) {
            $actor === 'system' => $this->systemActor(),
            $actor instanceof Authenticatable => $this->actorFields($actor, $request),
            is_array($actor) => $actor,
            default => $this->actorFields($this->requestUser(), $request, $data['actor_name'] ?? null),
        };

        $context = $data['context'] ?? [];
        // Request fields only for an actual HTTP request (jobs / commands / the Reverb shell
        // have a placeholder request; the shell passes its own 'context')
        $http = $request !== null && $request->route() !== null;

        $attributes = array_merge([
            'occurred_at' => now(),
            'tenant_id' => array_key_exists('tenant_id', $data) ? $data['tenant_id'] : TenantAccess::currentId(),
            'action' => mb_substr($action, 0, 100),
            'result' => in_array($data['result'] ?? null, AuditEvent::RESULTS, true) ? $data['result'] : AuditEvent::RESULT_SUCCESS,
            'ip_address' => $http ? $request->ip() : null,
            'user_agent' => $http ? (mb_substr((string) $request->userAgent(), 0, 512) ?: null) : null,
            'request_id' => $http ? self::requestId($request) : null,
            'http_method' => $http ? $request->method() : null,
            'route' => $http ? $request->route()->uri() : null,
            'status_code' => $data['status_code'] ?? null,
        ], $fields, $this->target($data['target'] ?? null), $context);

        // AI agents (docs/mcp.md): a request over /mcp — or an API call an MCP tool made — names
        // the MCP auth method with the token, and the tool
        $mcp = $http ? $request->attributes->get('mcp') : null;
        if (is_array($mcp) && empty($data['auth_method']) && ! is_array($actor) && $actor !== 'system') {
            $user = $actor instanceof Authenticatable ? $actor : $this->requestUser();
            $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
            if ($token instanceof PersonalAccessToken) {
                $attributes['auth_method'] = 'mcp:'.$token->getKey();
            }
            if (! empty($mcp['tool'])) {
                $data['metadata'] = ['mcp_tool' => (string) $mcp['tool']] + (array) ($data['metadata'] ?? []);
            }
        }

        $attributes['metadata'] = $this->metadata($data['metadata'] ?? []);
        $attributes['actor_type'] = ($attributes['actor_type'] ?? null) ?: AuditEvent::ACTOR_ANONYMOUS;
        if (! empty($data['auth_method'])) {
            $attributes['auth_method'] = mb_substr((string) $data['auth_method'], 0, 40); // e.g. sso:google
        }

        if (empty($attributes['impersonator_id']) && ! is_array($actor) && $actor !== 'system') {
            $user = $actor instanceof Authenticatable ? $actor : $this->requestUser();
            $attributes = array_merge($attributes, $this->impersonatorFields($user, $attributes['tenant_id']));
        }

        return array_intersect_key($attributes, array_flip(self::COLUMNS));
    }

    /**
     * A platform admin acting inside a tenant they are not a member of — the admin impersonates
     * the tenant (ImpersonateController): the event names the admin as impersonator, the tenant
     * is the one impersonated.
     */
    private function impersonatorFields(?Authenticatable $user, ?string $tenantId): array
    {
        if (! $user || ! $tenantId || ! app(Rbac::class)->isPlatformAdmin($user) || TenantAccess::roleOf($user, $tenantId) !== null) {
            return [];
        }

        return [
            'impersonator_id' => (string) $user->getAuthIdentifier(),
            'impersonator_name' => $user->name ?? $user->email ?? null,
        ];
    }

    private function actorFields(?Authenticatable $user, ?Request $request, ?string $anonymousName = null): array
    {
        if (! $user) {
            return [
                'actor_type' => app()->runningInConsole() && ! app()->runningUnitTests() ? AuditEvent::ACTOR_SYSTEM : AuditEvent::ACTOR_ANONYMOUS,
                'actor_id' => null,
                'actor_name' => $anonymousName ? mb_substr($anonymousName, 0, 255) : null,
                'actor_email' => null,
                'auth_method' => null,
            ];
        }

        $type = AuditEvent::ACTOR_USER;
        $method = 'session';
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        // Sign-in tokens are named "api" (password / SSO login) or "invitation-accept"; any other
        // Sanctum token is an API token the user created (ApiTokenController)
        if ($token instanceof PersonalAccessToken && ! in_array($token->name, ['api', 'invitation-accept'], true)) {
            $type = AuditEvent::ACTOR_API_TOKEN;
            $method = 'api_token:'.$token->getKey();
        }

        return [
            'actor_type' => $type,
            'actor_id' => (string) $user->getAuthIdentifier(),
            'actor_name' => $user->name ?? $user->username ?? null,
            'actor_email' => $user->email ?? null,
            'auth_method' => mb_substr($method, 0, 40),
        ];
    }

    private function systemActor(): array
    {
        return [
            'actor_type' => AuditEvent::ACTOR_SYSTEM,
            'actor_id' => null,
            'actor_name' => 'system',
            'actor_email' => null,
            'auth_method' => null,
        ];
    }

    private function target(mixed $target): array
    {
        if ($target instanceof Model) {
            return [
                'target_type' => AuditCatalog::modelType($target),
                'target_id' => mb_substr((string) $target->getKey(), 0, 191),
                'target_name' => AuditCatalog::displayName($target),
            ];
        }

        if (is_array($target) && ! empty($target['type'])) {
            return [
                'target_type' => mb_substr((string) $target['type'], 0, 60),
                'target_id' => isset($target['id']) && $target['id'] !== '' ? mb_substr((string) $target['id'], 0, 191) : null,
                'target_name' => isset($target['name']) && $target['name'] !== '' ? mb_substr((string) $target['name'], 0, 255) : null,
            ];
        }

        return ['target_type' => null, 'target_id' => null, 'target_name' => null];
    }

    private function metadata(array $metadata): ?array
    {
        $metadata = array_filter(
            (array) SecretRedactor::redact($metadata),
            fn ($v) => $v !== null && $v !== [] && $v !== '',
        );
        if ($metadata === []) {
            return null;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) > self::MAX_METADATA_BYTES) {
            // Keep the summary, drop the bulky change details
            unset($metadata['changes']);
            $metadata['truncated'] = true;
        }

        return $metadata;
    }

    private function forward(AuditEvent $event): void
    {
        if (! config('audit.forward.enabled')) {
            return;
        }

        try {
            Log::channel(config('audit.forward.channel', 'audit'))->info($event->action, ['audit' => $event->toPublicArray()]);
        } catch (\Throwable $e) {
            Log::warning('Audit event not forwarded', ['action' => $event->action, 'error' => $e->getMessage()]);
        }
    }

    private function requestUser(): ?Authenticatable
    {
        try {
            return Auth::guard('sanctum')->user();
        } catch (\Throwable) {
            return null;
        }
    }

    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
