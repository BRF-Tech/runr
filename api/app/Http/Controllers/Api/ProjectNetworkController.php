<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\ProjectInternalCaller;
use App\Rules\IpOrCidr;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A project's network (docs/internal-network.md): its access mode (public, public from an IP
 * allow list, or internal only) and the projects allowed to call it on the region's internal
 * network — the same organization's, or another organization's by a grant.
 *
 * @tags Projects
 */
class ProjectNetworkController extends Controller
{
    /** The API document's shape of an allow-list entry (caller()) */
    private const CALLER_TYPE = 'array{caller: string, organization: string, cross_tenant: bool, created_at: string|null}';

    /** The API document's shape of the project's network (data()) */
    private const NETWORK_TYPE = 'array{access_mode: "public"|"allowlist"|"internal", allowed_cidrs: list<string>, internal_name: string, internal_host: string, internal_url: string, organization: string, token_header: string, token_env: string, cross_tenant_grants: bool, enforced: bool|null, callers: list<'.self::CALLER_TYPE.'>}';

    public function __construct(private readonly InternalNetworkService $network) {}

    /** The project's access mode, internal name and host, and its allow list */
    #[OpenApiResponse(200, 'The project\'s network', type: 'array{data: '.self::NETWORK_TYPE.'}')]
    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => $this->data($project)]);
    }

    /** Access mode: public | allowlist (allowed_cidrs) | internal */
    #[OpenApiResponse(200, 'The project\'s network after the change; warning: what the change could not apply yet', type: 'array{message: string, warning?: string, data: '.self::NETWORK_TYPE.'}')]
    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'access_mode' => ['required', 'string', Rule::in(Project::ACCESS_MODES)],
            'allowed_cidrs' => ['nullable', 'array', 'max:64', 'required_if:access_mode,'.Project::ACCESS_ALLOWLIST],
            'allowed_cidrs.*' => ['required', 'string', 'max:64', 'distinct', new IpOrCidr],
        ]);

        $this->network->setAccess($project, $data['access_mode'], $data['allowed_cidrs'] ?? null);
        // deleted while the request ran: 404, as for an unknown project
        $fresh = $project->fresh() ?? throw $project->notFound();

        return response()->json(array_filter([
            'message' => __('api.network.updated'),
            'warning' => $this->network->warning,
            'data' => $this->data($fresh),
        ], fn ($v) => $v !== null));
    }

    /**
     * Allows a project to call this one: a project of this organization (id, name or internal
     * name) or another organization's project by its internal name <project>.<organization>.
     */
    #[OpenApiResponse(201, 'The new allow-list entry; warning: what the change could not apply yet', type: 'array{warning?: string, data: '.self::CALLER_TYPE.'}')]
    public function addCaller(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['caller' => ['required', 'string', 'max:120']]);

        $row = $this->network->addCaller($project, $data['caller'], (string) $request->user()?->getAuthIdentifier());

        return response()->json(array_filter([
            'warning' => $this->network->warning,
            'data' => $this->caller($project, $row),
        ], fn ($v) => $v !== null), 201);
    }

    public function removeCaller(Project $project, string $caller): JsonResponse
    {
        abort_unless($this->network->removeCaller($project, $caller), 404);

        return response()->json(null, 204);
    }

    private function data(Project $project): array
    {
        $name = InternalNetwork::nameOf($project);

        return [
            'access_mode' => $project->accessMode(),
            'allowed_cidrs' => $project->allowed_cidrs ?? [],
            'internal_name' => $name,
            'internal_host' => InternalNetwork::hostFor($name),
            'internal_url' => 'http://'.InternalNetwork::hostFor($name),
            'organization' => InternalNetwork::orgOf($name),
            'token_header' => InternalNetwork::HEADER,
            'token_env' => InternalNetwork::ENV_TOKEN,
            'cross_tenant_grants' => InternalNetwork::crossTenantGrantsEnabled(),
            'enforced' => $project->knativeRegion ? InternalNetwork::modeOf($project->knativeRegion) === InternalNetwork::MODE_ENFORCE : null,
            'callers' => $project->internalCallers()->get()->map(fn (ProjectInternalCaller $row) => $this->caller($project, $row))->values()->all(),
        ];
    }

    /** @return array{caller: string, organization: string, cross_tenant: bool, created_at: string|null} */
    private function caller(Project $project, ProjectInternalCaller $row): array
    {
        $org = InternalNetwork::orgOf($row->caller);

        return [
            'caller' => $row->caller,
            'organization' => $org,
            'cross_tenant' => $org !== InternalNetwork::orgOf(InternalNetwork::nameOf($project)),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
