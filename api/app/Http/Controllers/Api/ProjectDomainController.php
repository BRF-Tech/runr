<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Project\StoreProjectDomainRequest;
use App\Http\Requests\Project\UpdateProjectDomainTlsRequest;
use App\Http\Resources\ProjectDomainResource;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectEnvironment;
use App\Services\CertManager\CertManagerService;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentResolver;
use App\Services\ProjectDomainService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Custom domains per project: a customer host name routed to the
 * project's Knative Service on its region, after DNS ownership verification.
 *
 * @tags Projects
 */
class ProjectDomainController extends Controller
{
    public function __construct(private readonly ProjectDomainService $service) {}

    /** Domains of the project's own workload, or of one environment with ?environment= (docs/environments.md#domains). */
    public function index(Request $request, Project $project): JsonResponse
    {
        $target = DeployTarget::environment($project, EnvironmentResolver::resolve($project, $request->query('environment')));
        $domains = $target->domains()->orderBy('created_at')->get()
            ->each(fn (ProjectDomain $d) => $d->setRelation('project', $target));

        return response()->json([
            'data' => ProjectDomainResource::collection($domains)->resolve(),
            'meta' => $this->meta($target),
        ]);
    }

    public function store(StoreProjectDomainRequest $request, Project $project): JsonResponse
    {
        // environment: the domain routes to that environment's workload
        $target = DeployTarget::environment($project, EnvironmentResolver::resolve($project, $request->input('environment')));
        if ($reason = $this->service->ineligibilityReason($target)) {
            return response()->json(['message' => $reason], 422);
        }

        $domain = $this->service->add($target, $request->validated('domain'));

        return response()->json(['data' => (new ProjectDomainResource($domain))->resolve()], 201);
    }

    public function verify(Project $project, ProjectDomain $domain): JsonResponse
    {
        $domain->setRelation('project', $this->targetOf($project, $domain));

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
        $domain->setRelation('project', $this->targetOf($project, $domain));
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
        $domain->setRelation('project', $this->targetOf($project, $domain));

        $failed = $this->service->removeFromRegion($domain);
        if ($failed) {
            $message = __('api.domain.remove_failed', ['objects' => implode(', ', $failed)]);
            $domain->update(['status_message' => mb_substr($message, 0, 2000)]);

            return response()->json(['message' => $message], 422);
        }

        $domain->delete();

        return response()->json(null, 204);
    }

    /** The workload a domain routes to: its environment's, else the project's own. */
    private function targetOf(Project $project, ProjectDomain $domain): Project
    {
        $environmentId = $domain->getAttribute('environment_id');

        return DeployTarget::environment($project, $environmentId ? ProjectEnvironment::query()->find($environmentId) : null);
    }

    /**
     * The CA certificate (PEM) of the project's region, when its custom-domain certificates come
     * from the region CA: what clients trust to accept them. Never the key. ?environment= (a name
     * or id): the CA of the region that environment runs in (docs/environments.md#domains).
     *
     * @response Response<"", 200>
     */
    #[OpenApiResponse(200, 'The region CA\'s certificate (PEM, as an attachment)', mediaType: 'application/x-pem-file', type: 'string')]
    #[OpenApiResponse(404, 'The region has no CA', type: 'array{message: string}')]
    #[OpenApiResponse(422, 'The region\'s cluster did not answer', type: 'array{message: string}')]
    public function caCertificate(Request $request, Project $project): Response|JsonResponse
    {
        $target = DeployTarget::environment($project, EnvironmentResolver::resolve($project, $request->query('environment')));

        return CertManagerController::pemDownload(app(CertManagerService::class), $target->knativeRegion);
    }

    private function meta(Project $project): array
    {
        $reason = $this->service->ineligibilityReason($project);
        $certManager = $this->service->certManager($project->knativeRegion);

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            // the issuer when TLS mode cert-manager is offered; cert_manager says why it is not
            'cert_manager_issuer' => $certManager['available'] ? $certManager['issuer'] : null,
            'cert_manager' => [
                'available' => $certManager['available'],
                'status' => $certManager['status'],
                'issuer' => $certManager['issuer'],
                // acme (publicly trusted), ca (the region CA: GET …/domains/ca-certificate) or own
                'kind' => $certManager['kind'],
                'reason' => $certManager['reason'],
                'ca_download' => $certManager['ca'],
                // a new domain starts in this mode (cert-manager where the region issues certificates)
                'default_mode' => app(CertManagerService::class)->defaultTlsMode($project->knativeRegion),
            ],
            'default_host' => $this->service->defaultHost($project),
            'dns_targets' => $this->service->dnsTargets($project),
        ];
    }
}
