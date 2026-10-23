<?php

namespace App\Services\OrgTransfer\Secrets;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Services\Secrets\ProjectSecrets;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretBackends;
use App\Services\Secrets\SecretScope;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Support\Facades\Log;

/**
 * Secret variables where they live since pass-through (docs/security.md, docs/secret-stores.md):
 * values in the project's region — its Kubernetes Secret or its OpenBao — read through the
 * region's secret backends, values an older install still keeps encrypted in project_secrets read
 * through the model, references to the organization's secret stores exported as the store's name,
 * path and key. Written through ProjectSecrets, the path of the API: into the target project's
 * region, then the metadata. A region that does not answer leaves its names unreadable (export)
 * or not set (import); nothing else of the transfer stops.
 */
final class ProjectSecretValues implements SecretValues
{
    public function __construct(
        private readonly ProjectSecrets $secrets,
        private readonly SecretBackends $backends,
    ) {}

    public function read(Project $project): array
    {
        $rows = ProjectSecret::query()->where('project_id', $project->id)->with('store')->orderBy('name')->get();
        $values = [];
        $references = [];
        $unreadable = [];

        $inRegion = [];
        foreach ($rows as $secret) {
            if ($secret->isExternal()) {
                $references[$secret->name] = array_filter([
                    'store' => $secret->store?->name,
                    'path' => $secret->remote_path,
                    'key' => $secret->remote_key,
                ], fn ($v) => $v !== null && $v !== '');

                continue;
            }
            if ($secret->isHeldInDatabase()) {
                // A value encrypted with a key this installation no longer has cannot be read
                $value = rescue(fn () => $secret->getAttribute('value'), null, false);
                is_string($value) ? $values[$secret->name] = $value : $unreadable[] = $secret->name;

                continue;
            }
            $inRegion[] = $secret;
        }

        if ($inRegion !== []) {
            $scope = SecretScope::forProject($project);
            foreach ($this->backends->partition(collect($inRegion)) as $source => $part) {
                if ($part->isEmpty()) {
                    continue;
                }
                $names = $part->pluck('name')->map(fn ($n) => (string) $n)->all();
                try {
                    $read = $this->backends->for($source)->readValues($scope, $names);
                } catch (SecretBackendException $e) {
                    Log::warning('Org export: secret values not read from the region', ['project' => $project->slug, 'error' => $e->getMessage()]);
                    $read = [];
                }
                foreach ($names as $name) {
                    isset($read[$name]) ? $values[$name] = $read[$name] : $unreadable[] = $name;
                }
            }
        }

        ksort($values);
        sort($unreadable);

        return ['values' => $values, 'references' => $references, 'unreadable' => $unreadable];
    }

    public function write(Project $project, array $values, mixed $userId): array
    {
        $values = array_filter($values, fn (string $v) => $v !== '');
        if ($values === []) {
            return [];
        }

        $errors = ProjectSecretWriter::errors($project, $values, ProjectSecretWriter::plainEnv($project), 'secrets');
        foreach (array_keys($errors) as $field) {
            unset($values[substr((string) $field, strlen('secrets.'))]);
        }

        try {
            return $this->secrets->setValues($project, $values, $userId);
        } catch (SecretBackendException $e) {
            Log::warning('Org import: secret values not written to the region', ['project' => $project->slug, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function writeReferences(Project $project, array $references, mixed $userId): array
    {
        $missing = [];
        foreach ($references as $name => $reference) {
            $store = is_string($reference['store'] ?? null)
                ? SecretStore::query()->where('name', $reference['store'])->first()
                : null;
            $path = is_string($reference['path'] ?? null) ? $reference['path'] : '';
            if (! $store || $path === '') {
                // a store of the source organization that does not exist here: a manual step
                $missing[] = (string) $name;

                continue;
            }
            try {
                $this->secrets->setReference($project, (string) $name, $store, $path, is_string($reference['key'] ?? null) ? $reference['key'] : null, $userId);
            } catch (SecretBackendException) {
                // refused until the upgrade from 0.5.10 is finalized (RollbackCopies): a manual step
                $missing[] = (string) $name;
            }
        }

        return $missing;
    }
}
