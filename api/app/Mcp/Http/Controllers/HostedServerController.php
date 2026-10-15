<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Hosting\CallRecorder;
use App\Mcp\Hosting\GatewayForwarder;
use App\Mcp\Hosting\GatewayIdentity;
use App\Mcp\Hosting\McpTemplates;
use App\Mcp\Http\Resources\HostedServerResource;
use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Mcp\Protocol\JsonRpc;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Rbac\Rbac;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Hosted MCP servers in the console API (docs/mcp.md, "Hosting MCP servers"): the starters, a
 * project's server settings, its gateway key, its call log and the inspector, which talks to the
 * server through the gateway as the signed-in user.
 */
class HostedServerController extends Controller
{
    private const MAX_RAW = 65536;

    public function __construct(
        private readonly McpTemplates $templates,
        private readonly GatewayForwarder $forwarder,
        private readonly CallRecorder $calls,
    ) {}

    /** GET /api/mcp-servers/templates */
    public function templates(Request $request): JsonResponse
    {
        $registry = app(RuntimeRegistry::class);
        $customEnabled = Setting::get('custom_runtimes_enabled', 'false') === 'true';
        $canRegister = Gate::allows('runtimes.manage') || (Gate::allows('runtimes.use_custom') && $customEnabled);

        $data = [];
        foreach ($this->templates->all() as $id => $template) {
            $registered = $registry->row($template['runtime']) !== null;
            $data[] = [
                'id' => $id,
                'name' => $template['name'],
                'description' => $template['description'],
                'service_type' => 'faas',
                'runtime' => $template['runtime'],
                'runtime_registered' => $registered,
                'runtime_available' => $registry->unavailableReason($template['runtime'], null, 'http') === null,
                'can_register_runtime' => ! $registered && $canRegister,
                'runtime_manifest' => $this->templates->manifestText($id),
                'files' => (object) $this->templates->files($id),
                'path' => (string) config('mcp.gateway.default_path', '/mcp'),
            ];
        }

        $data[] = [
            'id' => 'image',
            'name' => 'Container image',
            'description' => 'An MCP server you already have as a container image that serves Streamable HTTP.',
            'service_type' => 'caas',
            'runtime' => null,
            'runtime_registered' => true,
            'runtime_available' => true,
            'can_register_runtime' => false,
            'runtime_manifest' => null,
            'files' => null,
            'path' => (string) config('mcp.gateway.default_path', '/mcp'),
            'port' => 8080,
        ];

        return response()->json(['data' => $data]);
    }

    /** GET /api/mcp-servers — the organization's hosted servers on projects the caller sees. */
    public function index(Request $request): JsonResponse
    {
        $visible = app(Rbac::class)->visibleProjectIds($request->user());

        $servers = HostedServer::query()
            ->where('tenant_id', TenantAccess::currentId())
            ->when($visible !== null, fn ($q) => $q->whereIn('project_id', $visible))
            ->orderBy('handle')
            ->get();

        $projects = Project::query()->whereIn('id', $servers->pluck('project_id')->all())->get()->keyBy('id');

        return response()->json(['data' => $servers
            ->filter(fn (HostedServer $s) => $projects->has($s->project_id))
            ->map(fn (HostedServer $s) => HostedServerResource::make($s, $projects->get($s->project_id), $request))
            ->values()]);
    }

    /** GET /api/projects/{project}/mcp-server */
    public function show(Request $request, Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);
        abort_unless($server !== null, 404, __('mcp.server_not_found'));

        return response()->json(['data' => HostedServerResource::make($server, $project, $request)]);
    }

    /** PUT /api/projects/{project}/mcp-server — serve the project as an MCP server, or change it. */
    public function upsert(Request $request, Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);

        $data = $request->validate([
            'access_mode' => [$server ? 'sometimes' : 'required', 'string', Rule::in(HostedServer::ACCESS_MODES)],
            'path' => ['sometimes', 'string', 'max:200', 'regex:#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#'],
            'audit_calls' => 'sometimes|boolean',
        ]);

        if (! $project->isSupportedType()) {
            abort(422, __('mcp.unsupported_type'));
        }
        if (self::triggerType($project) !== 'http') {
            return response()->json([
                'message' => __('mcp.http_trigger_required'),
                'errors' => ['trigger_type' => [__('mcp.http_trigger_required')]],
            ], 422);
        }

        $created = $server === null;
        $server ??= HostedServer::newFor($project, ['created_by' => (string) $request->user()->getAuthIdentifier()]);
        $server->fill($data);
        $server->save();

        return response()->json([
            'data' => HostedServerResource::make($server->fresh(), $project, $request),
            'created' => $created,
        ], $created ? 201 : 200);
    }

    /** DELETE /api/projects/{project}/mcp-server — the project stops being an MCP server. */
    public function destroy(Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);
        abort_unless($server !== null, 404, __('mcp.server_not_found'));

        HostedServerCall::query()->where('mcp_server_id', $server->id)->delete();
        $server->delete();

        return response()->json(null, 204);
    }

    /** POST /api/projects/{project}/mcp-server/rotate-key — takes effect with the next deploy. */
    public function rotateKey(Request $request, Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);
        abort_unless($server !== null, 404, __('mcp.server_not_found'));

        $server->rotateKey();

        return response()->json([
            'data' => HostedServerResource::make($server->fresh(), $project, $request),
            'message' => __('mcp.key_rotated', ['days' => HostedServer::ROTATION_GRACE_DAYS]),
        ]);
    }

    /** GET /api/projects/{project}/mcp-server/calls */
    public function calls(Request $request, Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);
        abort_unless($server !== null, 404, __('mcp.server_not_found'));

        $data = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'outcome' => ['sometimes', Rule::in(HostedServerCall::OUTCOMES)],
            'method' => 'sometimes|string|max:100',
        ]);

        $page = HostedServerCall::query()
            ->where('mcp_server_id', $server->id)
            ->when($data['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($data['method'] ?? null, fn ($q, $v) => $q->where('method', $v))
            ->orderByDesc('occurred_at')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (HostedServerCall $c) => $c->toPublicArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{project}/mcp-server/inspector — one JSON-RPC message to the server
     * through the gateway as the signed-in user (auth "console"); the answer's messages (JSON or
     * SSE) come back whole.
     */
    public function inspector(Request $request, Project $project): JsonResponse
    {
        $server = HostedServer::forProject($project);
        abort_unless($server !== null, 404, __('mcp.server_not_found'));

        $data = $request->validate([
            'message' => 'required|array',
            'session_id' => 'nullable|string|max:200',
            'protocol_version' => 'nullable|string|max:20',
        ]);
        if (JsonRpc::kind($data['message']) === JsonRpc::INVALID) {
            return response()->json(['message' => __('mcp.message_invalid'), 'errors' => ['message' => [__('mcp.message_invalid')]]], 422);
        }

        $body = JsonRpc::encode($data['message']);
        if (strlen($body) > (int) config('mcp.max_request_bytes', 4 * 1024 * 1024)) {
            return response()->json(['message' => __('mcp.message_too_large')], 413);
        }

        $user = $request->user();
        $token = $user->currentAccessToken();
        $identity = new GatewayIdentity(GatewayIdentity::AUTH_CONSOLE, $user, TenantAccess::currentId(), $token instanceof PersonalAccessToken ? $token : null);
        $rpc = CallRecorder::describe($body);

        $result = $this->forwarder->forward($server, $project, 'POST', $body, array_filter([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'Mcp-Session-Id' => $data['session_id'] ?? null,
            'MCP-Protocol-Version' => $data['protocol_version'] ?? null,
            'User-Agent' => 'RunR inspector',
        ]), $identity, null, [
            'X-Forwarded-For' => $request->ip(),
            'X-Forwarded-Proto' => $request->getScheme(),
            'X-Forwarded-Host' => $request->getHost(),
            'X-Request-ID' => AuditLogger::requestId($request),
        ], 60);

        $session = $result->headers['Mcp-Session-Id'] ?? ($data['session_id'] ?? null);

        if (! $result->reachable()) {
            $this->calls->record($server, $project, $request, $rpc, $identity, 502, HostedServerCall::UNREACHABLE, $result->error, $result->durationMs, $session, strlen($body), null);

            return response()->json(['message' => $result->error, 'status' => 502], 502);
        }

        $raw = $result->isStream() ? GatewayForwarder::readAll($result->stream, GatewayForwarder::MAX_BODY_BYTES) : (string) $result->body;
        $messages = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $messages = is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : CallRecorder::sseMessages($raw);
        }

        [$outcome, $error] = CallRecorder::outcome($result->status, $raw);
        $this->calls->record($server, $project, $request, $rpc, $identity, $result->status, $outcome, $error, $result->durationMs, $session, strlen($body), strlen($raw));

        return response()->json([
            'status' => $result->status,
            'session_id' => $session,
            'content_type' => $result->headers['Content-Type'] ?? null,
            'messages' => $messages,
            'raw' => strlen($raw) > self::MAX_RAW ? substr($raw, 0, self::MAX_RAW) : $raw,
            'duration_ms' => $result->durationMs,
            'method' => $rpc['method'],
            'name' => $rpc['name'],
        ])->header('Cache-Control', 'no-store');
    }

    private static function triggerType(Project $project): ?string
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };

        return $config?->trigger_type ?: 'http';
    }
}
