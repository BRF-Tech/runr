<?php

namespace App\Services\Ai\Models;

use App\Jobs\Ai\StartModelSnapshotJob;
use App\Models\AiModel;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Services\Ai\Hub\HubClient;
use App\Services\Ai\Hub\HubException;
use Illuminate\Validation\ValidationException;

/**
 * Adding models to the organization: a Hub model is pinned to the commit its revision points to
 * (so every mirror and online start serves the same files) with its task, library, license,
 * gated flag, size and card summary; an uploaded model is a name and a task; an import names
 * the folder of the model volume (pvc) or the OCI artifact (oci) and the region it is in, and
 * starts that import right away.
 */
class ModelLibrary
{
    public function __construct(
        private HubClient $hub,
        private ModelStorage $storage,
        private SnapshotService $snapshots,
    ) {}

    /**
     * @throws HubException the Hub does not know the model (or cannot be reached and no task was given)
     * @throws ValidationException
     */
    public function add(array $data, ?string $userId): AiModel
    {
        return match ($data['source']) {
            'hub' => $this->addHub($data, $userId),
            'pvc', 'oci' => $this->addImport($data, $userId),
            default => AiModel::create([
                'name' => $data['name'] ?? AiModel::uniqueName(AiModel::nameFromHubId((string) ($data['task'] ?? 'model'))),
                'source' => 'upload',
                'revision' => 'local',
                'task' => $data['task'] ?? null,
                'library' => $data['library'] ?? null,
                'created_by' => $userId,
            ]),
        };
    }

    /** The organization's model for a Hub id + revision (added on first use: one-click deploy). */
    public function findOrAddHub(string $hubId, ?string $revision, ?string $userId): AiModel
    {
        $revision = $revision ?: 'main';
        $existing = AiModel::where('source', 'hub')->where('hub_id', $hubId)->where('revision', $revision)->first();

        return $existing ?? $this->addHub(['hub_id' => $hubId, 'revision' => $revision], $userId);
    }

    public function delete(AiModel $model): void
    {
        foreach ($model->snapshots as $snapshot) {
            $snapshot->update(['status' => AiModelSnapshot::STATUS_DELETING]);
            $this->snapshots->delete($snapshot);
        }

        $model->delete();
    }

    private function addHub(array $data, ?string $userId): AiModel
    {
        $hubId = trim((string) $data['hub_id']);
        $revision = (string) ($data['revision'] ?? 'main') ?: 'main';
        HubClient::assertId($hubId);

        try {
            $info = $this->hub->info($hubId, $revision);
        } catch (HubException $e) {
            // A console without Hub access may still add a Hub model for regions that reach the Hub
            if ($e->status() !== 503 || empty($data['task'])) {
                throw $e;
            }
            $info = null;
        }

        return AiModel::create([
            'name' => $data['name'] ?? AiModel::uniqueName(AiModel::nameFromHubId($hubId)),
            'source' => 'hub',
            'hub_id' => $info['id'] ?? $hubId,
            'revision' => $revision,
            'revision_sha' => $info['sha'] ?? (preg_match('/^[0-9a-f]{40}$/', $revision) ? $revision : null),
            'task' => $data['task'] ?? ($info['task'] ?? null),
            'library' => $data['library'] ?? ($info['library'] ?? null),
            'license' => isset($info['license']) ? mb_substr((string) $info['license'], 0, 100) : null,
            'gated' => (bool) ($info['gated'] ?? false),
            'private' => (bool) ($info['private'] ?? false),
            'size_bytes' => $info['size_bytes'] ?? null,
            'parameters' => $info['parameters'] ?? null,
            'card_summary' => $info['card_summary'] ?? null,
            'metadata' => $info ? [
                'tags' => $info['tags'],
                'downloads' => $info['downloads'],
                'likes' => $info['likes'],
                'files' => count($info['files']),
            ] : null,
            'created_by' => $userId,
        ]);
    }

    private function addImport(array $data, ?string $userId): AiModel
    {
        $regionId = (string) ($data['knative_region_id'] ?? '');
        $region = $regionId !== '' ? KnativeRegion::find($regionId) : null;
        if (! $region) {
            throw ValidationException::withMessages(['knative_region_id' => [__('ai.models.import_region_required')]]);
        }

        if ($data['source'] === 'pvc' && $this->storage->importPath((string) $data['path']) === null) {
            throw ValidationException::withMessages(['path' => [__('ai.models.import_path_invalid', ['root' => $this->storage->importsSubPath()])]]);
        }

        $model = AiModel::create([
            'name' => $data['name'] ?? AiModel::uniqueName(AiModel::nameFromHubId((string) ($data['source'] === 'oci' ? preg_replace('/[:@].*$/', '', (string) $data['reference']) : $data['path']))),
            'source' => $data['source'],
            'revision' => 'local',
            'task' => $data['task'] ?? null,
            'library' => $data['library'] ?? null,
            'source_ref' => $data['source'] === 'oci' ? (string) $data['reference'] : trim((string) $data['path'], '/'),
            'created_by' => $userId,
        ]);

        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $model->id,
            'knative_region_id' => $region->id,
            'source' => $model->source,
            'revision' => 'local',
            'path' => $this->storage->snapshotSubPath($model, 'local'),
            'status' => AiModelSnapshot::STATUS_PENDING,
        ]);
        StartModelSnapshotJob::dispatch($snapshot->id);

        return $model;
    }
}
