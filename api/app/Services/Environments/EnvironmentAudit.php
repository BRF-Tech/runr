<?php

namespace App\Services\Environments;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Environment events. What happens through the API is audited by its route (AuditCatalog:
 * environment.create, environment.promotion.approve, …) — record() only logs it; what the platform
 * does on its own — a push to a mapped branch deploying an environment, an approved promotion's
 * deployment ending — is an audit event of its own (system(), AuditCatalog::EXPLICIT_ACTIONS).
 */
final class EnvironmentAudit
{
    /** @param  array<string, mixed>  $meta */
    public static function record(string $action, ?Project $project, ?ProjectEnvironment $environment, array $meta = []): void
    {
        Log::info('environments: '.$action, self::subject($project, $environment) + ['meta' => $meta]);
    }

    /** @param  array<string, mixed>  $meta */
    public static function system(string $action, ?Project $project, ?ProjectEnvironment $environment, array $meta = []): void
    {
        self::record($action, $project, $environment, $meta);

        app(AuditLogger::class)->record($action, [
            'actor' => 'system',
            'target' => ['type' => 'project', 'id' => $project?->getKey(), 'name' => $project?->name],
            'metadata' => ['environment' => $environment->name ?? ProjectEnvironment::PRIMARY_NAME] + $meta,
        ]);
    }

    /** @return array<string, mixed> */
    private static function subject(?Project $project, ?ProjectEnvironment $environment): array
    {
        return [
            'project_id' => $project?->getKey(),
            'project' => $project?->slug,
            'environment_id' => $environment?->getKey(),
            'environment' => $environment?->name,
        ];
    }
}
