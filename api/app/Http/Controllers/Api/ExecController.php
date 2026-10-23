<?php

namespace App\Http\Controllers\Api;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Environments\DeployTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ExecController extends Controller
{
    /**
     * Start a new exec session.
     * Validates access, stores session config in Redis, returns a one-time
     * token instantly. Pod discovery + wake-up happens on the WS side
     * (ShellController → shell:wake subprocess) for real-time progress.
     */
    public function start(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        if (! in_array($deployment->status, ['active', 'running'])) {
            abort(422, __('api.shell.requires_active'));
        }

        // An environment's deployment runs its own workload <slug>-<env>, maybe in another region
        // (docs/environments.md); its pods have no runr/project-id, shell:wake finds them by service
        $target = DeployTarget::project($project, $deployment);
        $region = $target->knativeRegion;

        if (! $region || ! $region->kubeconfig) {
            abort(422, __('api.shell.no_kubeconfig'));
        }

        if ($region->is_demo) {
            abort(422, __('api.shell.demo_not_allowed'));
        }

        $deployment->load('version');

        $sessionId = Str::uuid()->toString();
        $namespace = $region->project_namespace ?? $region->name ?? 'runr';
        $cols = max(1, (int) $request->input('cols', 80));
        $rows = max(1, (int) $request->input('rows', 24));

        // Encrypted: the session config carries the region kubeconfig (docs/security.md); `audit`
        // is who opened the session — the Reverb shell records open/close under it
        Redis::setex("exec:config:{$sessionId}", 120, Crypt::encryptString(json_encode([
            'audit' => app(AuditLogger::class)->currentContext($request) + [
                'target' => ['type' => 'deployment', 'id' => $deployment->id, 'name' => $project->name],
                'session_id' => $sessionId,
            ],
            'kubeconfig' => $region->kubeconfig,
            'namespace' => $namespace,
            'project_slug' => $target->slug,
            'project_id' => $project->id,
            'version_number' => $deployment->version?->number,
            'serving_namespace' => $region->knative_serving_namespace ?? 'knative-serving',
            // RunR signs its wake-up and keep-alive calls on the region's internal network
            'region_id' => $region->id,
            'cols' => $cols,
            'rows' => $rows,
            'keep_alive' => [
                'service_name' => $target->slug,
                'namespace' => $namespace,
            ],
        ])));

        return response()->json([
            'session_id' => $sessionId,
        ]);
    }
}
