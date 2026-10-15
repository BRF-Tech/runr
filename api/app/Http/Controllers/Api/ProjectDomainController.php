<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Project\StoreProjectDomainRequest;
use App\Http\Requests\Project\UpdateProjectDomainTlsRequest;
use App\Http\Resources\ProjectDomainResource;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\ProjectDomainService;
use Illuminate\Http\JsonResponse;

/**
 * Custom domains per project: a customer host name routed to the
 * project's Knative Service on its region, after DNS ownership verification.
 *
 * @tags Projects
 */
class ProjectDomainController extends Controller
{
    public function __construct(private readonly ProjectDomainService $service) {}

    public function index(Project $project): JsonResponse
    {
        $domains = $project->domains()->orderBy('created_at')->get()
            ->each(fn (ProjectDomain $d) => $d->setRelation('project', $project));

        return response()->json([
            'data' => ProjectDomainResource::collection($domains)->resolve(),
            'meta' => $this->meta($project),
        ]);
    }

    public function store(StoreProjectDomainRequest $request, Project $project): JsonResponse
    {
        if ($reason = $this->service->ineligibilityReason($project)) {
            return response()->json(['message' => $reason], 422);
        }

        $domain = $this->service->add($project, $request->validated('domain'));

        return response()->json(['data' => (new ProjectDomainResource($domain))->resolve()], 201);
    }

    public function verify(Project $project, ProjectDomain $domain): JsonResponse
    {
        $domain->setRelation('project', $project);

        $active = $this->service->verify($domain);
        $data = (new ProjectDomainResource($domain))->resolve();

        if ($active) {
            return response()->json(['message' => __('api.domain.active'), 'data' => $data]);
        }

        return response()->json([
            'message' => $domain->status_message ?: __('api.domain.verify_failed'),
            'data' => $data,
        ], 422);
    }

    public function updateTls(UpdateProjectDomainTlsRequest $request, Project $project, ProjectDomain $domain): JsonResponse
    {
        $domain->setRelation('project', $project);
        $validated = $request->validated();

        $this->service->setTls($domain, $validated['mode'], $validated['certificate'] ?? null, $validated['privateKey'] ?? null);
        $data = (new ProjectDomainResource($domain))->resolve();

        if ($domain->status === ProjectDomain::STATUS_ERROR) {
            return response()->json(['message' => $domain->status_message, 'data' => $data], 422);
        }

        return response()->json(['message' => __('api.domain.tls_updated'), 'data' => $data]);
    }

    public function destroy(Project $project, ProjectDomain $domain): JsonResponse
    {
        $domain->setRelation('project', $project);

        $failed = $this->service->removeFromRegion($domain);
        if ($failed) {
            $message = __('api.domain.remove_failed', ['objects' => implode(', ', $failed)]);
            $domain->update(['status_message' => mb_substr($message, 0, 2000)]);

            return response()->json(['message' => $message], 422);
        }

        $domain->delete();

        return response()->json(null, 204);
    }

    private function meta(Project $project): array
    {
        $reason = $this->service->ineligibilityReason($project);

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'cert_manager_issuer' => $this->service->clusterIssuer($project->knativeRegion),
            'default_host' => $this->service->defaultHost($project),
            'dns_targets' => $this->service->dnsTargets($project),
        ];
    }
}
