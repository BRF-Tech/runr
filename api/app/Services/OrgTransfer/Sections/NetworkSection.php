<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Project;
use App\Models\ProjectInternalCaller;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;

/**
 * extensions/network/network.json (docs/internal-network.md): every exported project's access
 * mode, the source ranges of allowlist, and its allow list as far as it names projects of the same
 * package — by their source id, since their internal names change with the organization.
 *
 * Grants to other organizations' projects are not carried: they belong to the source install's
 * organizations (the plan lists them as a manual step), and so are callers of the organization
 * that are not in the package.
 */
class NetworkSection implements TransferSection
{
    public const FILE = 'network.json';

    public function __construct(private InternalNetworkService $network) {}

    public function key(): string
    {
        return 'network';
    }

    private static function entry(): string
    {
        return PackageFormat::extensionDir('network').'/'.self::FILE;
    }

    public function export(ExportContext $context): void
    {
        $projects = $context->projects();
        $byName = [];
        foreach ($projects as $project) {
            $byName[InternalNetwork::nameOf($project)] = (string) $project->id;
        }

        $rows = [];
        foreach ($projects as $project) {
            $own = InternalNetwork::nameOf($project);
            $callers = [];
            $notCarried = [];
            foreach (ProjectInternalCaller::query()->where('project_id', $project->id)->orderBy('caller')->pluck('caller') as $caller) {
                $caller = (string) $caller;
                if (isset($byName[$caller]) && InternalNetwork::orgOf($caller) === InternalNetwork::orgOf($own)) {
                    $callers[] = $byName[$caller];
                } else {
                    $notCarried[] = $caller;
                }
            }
            $mode = $project->accessMode();
            if ($mode === Project::ACCESS_PUBLIC && $callers === [] && $notCarried === []) {
                continue;
            }
            $rows[(string) $project->id] = [
                'access_mode' => $mode,
                'allowed_cidrs' => $mode === Project::ACCESS_ALLOWLIST ? array_values((array) $project->allowed_cidrs) : [],
                'callers' => $callers,
                'not_carried' => $notCarried,
            ];
        }

        $context->putJson(self::entry(), ['projects' => $rows]);
        $context->count('network', count($rows));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $notCarried = [];
        foreach ((array) (((array) $context->json(self::entry(), []))['projects'] ?? []) as $row) {
            array_push($notCarried, ...array_map('strval', (array) ($row['not_carried'] ?? [])));
        }
        if ($notCarried !== []) {
            $plan->manual('network_callers', __('org_transfer.manual.network_callers'), array_values(array_unique($notCarried)));
        }
    }

    public function import(ImportContext $context): void
    {
        $rows = (array) (((array) $context->json(self::entry(), []))['projects'] ?? []);

        foreach ($rows as $sourceId => $row) {
            $key = (string) $sourceId;
            if ($context->settled('network', $key)) {
                continue;
            }
            $targetId = $context->mapped('project', $key);
            $project = $targetId ? Project::query()->find($targetId) : null;
            if (! $project) {
                continue;
            }

            try {
                $mode = (string) ($row['access_mode'] ?? Project::ACCESS_PUBLIC);
                if ($mode !== Project::ACCESS_PUBLIC) {
                    $this->network->setAccess($project, $mode, (array) ($row['allowed_cidrs'] ?? []));
                }
                foreach ((array) ($row['callers'] ?? []) as $callerSource) {
                    $callerId = $context->mapped('project', (string) $callerSource);
                    $caller = $callerId ? Project::query()->find($callerId) : null;
                    if ($caller) {
                        $this->network->addCaller($project, InternalNetwork::nameOf($caller), $context->userId());
                    }
                }
                $notCarried = array_map('strval', (array) ($row['not_carried'] ?? []));
                if ($notCarried !== []) {
                    $context->manual('network_callers', __('org_transfer.manual.network_callers'), array_map(fn ($c) => "{$project->name}: {$c}", $notCarried));
                }
                $context->record('network', $key, $project->name, $notCarried ? 'manual' : 'created', (string) $project->id);
            } catch (\Throwable $e) {
                $context->record('network', $key, $project->name, 'failed', null, $e->getMessage());
            }
        }
    }
}
