<?php

namespace App\Services\Runtimes\Patches;

use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\Version;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\SchemaColumns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Runtime patches of the projects (docs/runtimes.md#runtime-patches):
 *
 *  - resolve()   the image a code workload runs: the version image by digest — the project's pin
 *                (one of the version's last five patches), else the newest patch — so a deployment
 *                runs exactly the patch RunR recorded for it; the version image as it is (floating
 *                tag) when no patch is known. Applies to the executor image of a version only:
 *                region builds, uploads and CaaS images are not RunR's runtime images.
 *  - state()     up_to_date | patch_available (+ level) | pinned | overdue (a critical / zero-day
 *                patch's deadline passed and the project still runs an older one)
 *  - affected()  projects of the current organization that run behind a patch
 *
 * A project's running patch is what its live deployment applied (projects.runtime_patch_id /
 * runtime_digest, written on activation — recordRunning()); a deployment made before patches
 * existed has none, and every patch published after it went live counts as newer.
 */
class RuntimePatches
{
    /** The API document's shape of state() (the controllers' #[Response] attributes) */
    public const STATE_OPENAPI_TYPE = 'array{state: "up_to_date"|"patch_available"|"pinned"|"overdue", level: "routine"|"security"|"critical"|"zero-day"|null, deadline_at: string|null, deployed: bool, current: array{id: string|null, tag: string|null, level: string|null, digest: string|null, published_at: string|null}|null, latest: array{id: string, tag: string, level: string, digest: string, published_at: string|null}|null, pending: list<string>, pin: array{id: string|null, tag: string|null, digest: string|null, available: bool, by: string|null}|null}';

    /** Project types whose workloads run on a runtime image (CaaS runs its own image). */
    public const CODE_TYPES = ['xaas', 'faas'];

    /** @var array<string, Collection<int, RuntimePatch>> in-feed patches per runtime version, newest first */
    private array $patches = [];

    /** @var array<string, Carbon|null> */
    private array $liveSince = [];

    /** @var array<string, string|null> default version per runtime */
    private array $defaults = [];

    public function flush(): void
    {
        $this->patches = $this->liveSince = $this->defaults = [];
    }

    /**
     * The runtime version a project runs: its runtime_version, else the runtime's default (what
     * ExecutorImage::resolve() deploys for a project without one).
     */
    public function versionOf(Project $project): ?string
    {
        if ($project->runtime_version) {
            return (string) $project->runtime_version;
        }
        if (! $project->runtime) {
            return null;
        }

        return $this->defaultVersion((string) $project->runtime);
    }

    /** The default version of a runtime (executor_images), memoized; null when unknown. */
    private function defaultVersion(string $runtime): ?string
    {
        if (! array_key_exists($runtime, $this->defaults)) {
            try {
                $this->defaults[$runtime] = ExecutorImage::defaultVersion($runtime);
            } catch (\Throwable) {
                $this->defaults[$runtime] = null;
            }
        }

        return $this->defaults[$runtime];
    }

    // ── Patches ─────────────────────────────────────────────────────────────

    /** @return Collection<int, RuntimePatch> the in-feed patches of a runtime version, newest first */
    public function of(?string $runtime, ?string $version): Collection
    {
        if (! $runtime || ! $version) {
            return new Collection;
        }

        $key = "{$runtime}\0{$version}";
        if (! isset($this->patches[$key])) {
            try {
                $this->patches[$key] = RuntimePatch::query()
                    ->where('runtime', $runtime)->where('version', $version)->where('in_feed', true)
                    ->orderByDesc('published_at')->orderByDesc('id')
                    ->get()->values();
            } catch (\Throwable $e) {
                // An install whose migration has not run yet: no patches
                Log::debug('Runtime patches not readable', ['error' => $e->getMessage()]);
                $this->patches[$key] = new Collection;
            }
        }

        return $this->patches[$key];
    }

    public function newest(?string $runtime, ?string $version): ?RuntimePatch
    {
        return $this->of($runtime, $version)->first();
    }

    /** @return Collection<int, RuntimePatch> the patches a project of the version can be pinned to: the newest ones */
    public function pinnable(?string $runtime, ?string $version): Collection
    {
        return $this->of($runtime, $version)->take(max(1, (int) config('runtime_patches.keep', 5)))->values();
    }

    public function find(?string $id): ?RuntimePatch
    {
        return $id ? RuntimePatch::query()->find($id) : null;
    }

    /** A patch of the version by digest (also one the feed no longer lists). */
    public function findByDigest(?string $runtime, ?string $version, ?string $digest): ?RuntimePatch
    {
        if (! $runtime || ! $version || ! $digest) {
            return null;
        }

        return $this->of($runtime, $version)->firstWhere('digest', $digest)
            ?? RuntimePatch::query()->where('runtime', $runtime)->where('version', $version)->where('digest', $digest)
                ->orderByDesc('published_at')->first();
    }

    // ── Images ──────────────────────────────────────────────────────────────

    /**
     * Whether patches apply to $versionImage: it is the version's executor image (by tag), not a
     * region build, an upload or a reference that is already pinned.
     */
    public static function patchable(ExecutorImage $executor, string $versionImage): bool
    {
        $own = (string) $executor->docker_image;
        if ($own === '' || str_contains($versionImage, '@')) {
            return false;
        }

        return self::repositoryOf($versionImage) === self::repositoryOf($own);
    }

    /** "ghcr.io/x/exec-php:8.3" → "ghcr.io/x/exec-php@sha256:…" (the tag goes, the reference keeps its form). */
    public static function byDigest(string $image, string $digest): string
    {
        return self::repositoryOf($image).'@'.$digest;
    }

    private static function repositoryOf(string $image): string
    {
        $image = explode('@', $image, 2)[0];

        return (string) preg_replace('~:[^/:]+$~', '', $image);
    }

    /**
     * The image a workload of $project (null: a sandbox) runs on $executor: $forcedDigest (a
     * rollback), else the project's pin, else the newest patch; $versionImage unchanged when no
     * patch applies. A pin that is no longer among the pinnable patches is not used (pinDropped).
     */
    public function resolve(?Project $project, ExecutorImage $executor, string $versionImage, ?string $forcedDigest = null): ResolvedRuntimeImage
    {
        if (! self::patchable($executor, $versionImage)) {
            return new ResolvedRuntimeImage($versionImage);
        }

        $runtime = (string) $executor->runtime;
        $version = (string) $executor->version;

        $pin = $project ? (string) ($project->getAttribute('runtime_patch_pin') ?? '') : '';
        if ($forcedDigest === null && str_starts_with($pin, 'sha256:')) {
            $forcedDigest = $pin;
        }
        if ($forcedDigest !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $forcedDigest)) {
            $patch = $this->findByDigest($runtime, $version, $forcedDigest);

            return new ResolvedRuntimeImage(self::byDigest($versionImage, $forcedDigest), $forcedDigest, $patch?->id, $patch?->tag);
        }

        $pinDropped = false;
        if ($pin !== '') {
            $patch = $this->pinnable($runtime, $version)->firstWhere('id', $pin);
            if ($patch) {
                return new ResolvedRuntimeImage(self::byDigest($versionImage, $patch->digest), $patch->digest, $patch->id, $patch->tag);
            }
            $pinDropped = true;
        }

        $newest = $this->newest($runtime, $version);
        if (! $newest) {
            return new ResolvedRuntimeImage($versionImage, null, null, null, $pinDropped);
        }

        return new ResolvedRuntimeImage(self::byDigest($versionImage, $newest->digest), $newest->digest, $newest->id, $newest->tag, $pinDropped);
    }

    /**
     * The runtime image of a deployment, recorded on it (runtime_image / runtime_digest /
     * runtime_patch_id) before its manifests are built. A digest already on the deployment (a
     * rollback) is what it runs. A prebuilt version records the base it was built from; CaaS and
     * versions without a runtime image record nothing (null: buildContainer() resolves on its own
     * and reports what is wrong with the version image).
     */
    public function forDeployment(Project $project, Version $version, Deployment $deployment): ?ResolvedRuntimeImage
    {
        if (! in_array($project->service_type, self::CODE_TYPES, true) || ! SchemaColumns::has($deployment, 'runtime_digest')) {
            return null;
        }

        if ($version->build_image) {
            $this->record($deployment, [
                'runtime_image' => null,
                'runtime_digest' => $version->getAttribute('runtime_digest'),
                'runtime_patch_id' => $version->getAttribute('runtime_patch_id'),
            ]);

            return null;
        }

        try {
            $runtimes = app(RuntimeRegistry::class);
            $executor = $runtimes->executorFor($project);
            $versionImage = $runtimes->versionImage($runtimes->forProject($project), $executor, $project->knativeRegion);
        } catch (\Throwable) {
            return null;
        }

        $resolved = $this->resolve($project, $executor, $versionImage, $deployment->runtime_digest ?: null);
        $this->record($deployment, [
            'runtime_image' => mb_substr($resolved->image, 0, 500),
            'runtime_digest' => $resolved->digest,
            'runtime_patch_id' => $resolved->patchId,
        ]);

        if ($resolved->pinDropped) {
            $this->dropPin($project, $resolved);
        }

        return $resolved;
    }

    /**
     * The base image a prebuilt version is built FROM: the version's base pin (a rollback), the
     * project's pin or the newest patch — recorded on the version.
     */
    public function forBuild(Project $project, Version $version, ExecutorImage $executor, string $versionImage): ResolvedRuntimeImage
    {
        $forced = $version->getAttribute('runtime_base_pin') ?: null;
        $resolved = $this->resolve($project, $executor, $versionImage, $forced);

        if (SchemaColumns::has($version, 'runtime_digest')) {
            Version::query()->whereKey($version->getKey())->update([
                'runtime_digest' => $resolved->digest,
                'runtime_patch_id' => $resolved->patchId,
                'runtime_base_pin' => null,
            ]);
            $version->forceFill(['runtime_digest' => $resolved->digest, 'runtime_patch_id' => $resolved->patchId, 'runtime_base_pin' => null])
                ->syncOriginalAttributes(['runtime_digest', 'runtime_patch_id', 'runtime_base_pin']);
        }

        if ($resolved->pinDropped) {
            $this->dropPin($project, $resolved);
        }

        return $resolved;
    }

    /** The image of a sandbox or warm pool: the newest patch of the version by digest. */
    public function forSandbox(ExecutorImage $executor, string $versionImage): string
    {
        return $this->resolve(null, $executor, $versionImage)->image;
    }

    /**
     * A deployment went live: the project now runs its patch (LiveDeployments::activate(), in the
     * activation's transaction).
     */
    public function recordRunning(Deployment $deployment): void
    {
        if (! SchemaColumns::has($deployment, 'runtime_digest')) {
            return;
        }

        $project = new Project;
        $project->setConnection($deployment->getConnectionName());
        if (! SchemaColumns::has($project, 'runtime_digest')) {
            return;
        }

        Project::query()->whereKey($deployment->project_id)->update([
            'runtime_patch_id' => $deployment->runtime_patch_id,
            'runtime_digest' => $deployment->runtime_digest,
        ]);
        unset($this->liveSince[(string) $deployment->project_id]);
    }

    /** @param array<string, mixed> $attributes */
    private function record(Deployment $deployment, array $attributes): void
    {
        // A write of these columns only: the status is the deploy's (Deployment::transition())
        Deployment::query()->whereKey($deployment->getKey())->update($attributes);
        $deployment->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
    }

    /** The pinned patch left the feed's last five: the pin goes, the owner hears why. */
    private function dropPin(Project $project, ResolvedRuntimeImage $resolved): void
    {
        $pin = (string) $project->runtime_patch_pin;
        Project::query()->whereKey($project->getKey())->update(['runtime_patch_pin' => null, 'runtime_patch_pinned_by' => null]);
        $project->forceFill(['runtime_patch_pin' => null, 'runtime_patch_pinned_by' => null])->syncOriginalAttributes(['runtime_patch_pin', 'runtime_patch_pinned_by']);

        Log::warning('Runtime patch pin no longer available: deployed on the newest patch', [
            'project' => $project->slug, 'pin' => $pin, 'patch' => $resolved->patchId,
        ]);
        app(RuntimePatchNotifier::class)->pinDropped($project, $pin, $resolved->tag);
    }

    // ── State ───────────────────────────────────────────────────────────────

    /** Whether runtime patches concern the project at all (a code project with a runtime version). */
    public function applies(Project $project): bool
    {
        return in_array($project->service_type, self::CODE_TYPES, true) && $project->runtime && $this->versionOf($project);
    }

    /** The patch the project's live deployment runs (null: unknown or not a feed patch). */
    public function current(Project $project): ?RuntimePatch
    {
        $id = $project->getAttribute('runtime_patch_id');
        if ($id) {
            return $this->of($project->runtime, $this->versionOf($project))->firstWhere('id', $id) ?? $this->find($id);
        }

        return $this->findByDigest($project->runtime, $this->versionOf($project), $project->getAttribute('runtime_digest'));
    }

    /**
     * Whether the project's live workload runs on something older than $patch. Not deployed (no
     * active deployment) or stopped: nothing runs, nothing is behind.
     */
    public function isBehind(Project $project, RuntimePatch $patch): bool
    {
        return $this->behind($project, $patch, $this->current($project), $this->liveSince($project));
    }

    private function behind(Project $project, RuntimePatch $patch, ?RuntimePatch $current, ?Carbon $liveSince): bool
    {
        if ($liveSince === null || $project->isStopped()) {
            return false;
        }

        $digest = $project->getAttribute('runtime_digest');
        if ($digest !== null && $digest === $patch->digest) {
            return false;
        }
        if ($current !== null) {
            return $current->id !== $patch->id && $patch->published_at->greaterThan($current->published_at);
        }

        // Deployed before the patch was known: what went live before it was published is older
        return $patch->published_at->greaterThan($liveSince);
    }

    /** When the project's active deployment was created (null: none is active). */
    public function liveSince(Project $project): ?Carbon
    {
        if (array_key_exists('live_since', $project->getAttributes())) {
            $value = $project->getAttributes()['live_since'];

            return $value ? Carbon::parse($value) : null;
        }

        $key = (string) $project->getKey();
        if (! array_key_exists($key, $this->liveSince)) {
            $value = Deployment::query()->where('project_id', $key)->where('status', 'active')->max('created_at');
            $this->liveSince[$key] = $value ? Carbon::parse($value) : null;
        }

        return $this->liveSince[$key];
    }

    /**
     * Patch state of a project for the API (ProjectResource runtime_patch, MCP project_get, CLI):
     * null when no patch concerns it (CaaS, a runtime version without patches).
     *
     * @return array{state: string, level: string|null, deadline_at: string|null, deployed: bool, current: array|null, latest: array|null, pending: list<string>, pin: array|null}|null
     */
    public function state(Project $project): ?array
    {
        if (! $this->applies($project)) {
            return null;
        }

        $patches = $this->of($project->runtime, $this->versionOf($project));
        $pin = $this->pinState($project);
        if ($patches->isEmpty() && $pin === null) {
            return null;
        }

        $current = $this->current($project);
        $liveSince = $this->liveSince($project);
        $pending = $patches->filter(fn (RuntimePatch $p) => $this->behind($project, $p, $current, $liveSince))->values();

        $level = $pending->reduce(fn (?string $carry, RuntimePatch $p) => RuntimePatch::higher($carry, $p->level));
        $deadline = $pending->map(fn (RuntimePatch $p) => $p->effectiveDeadline())->filter()->sort()->first();
        $overdue = $pending->contains(fn (RuntimePatch $p) => $p->isOverdue());
        $newest = $patches->first();

        return [
            'state' => match (true) {
                $overdue => 'overdue',
                $pin !== null => 'pinned',
                $pending->isNotEmpty() => 'patch_available',
                default => 'up_to_date',
            },
            'level' => $level,
            'deadline_at' => $deadline?->toIso8601String(),
            'deployed' => $liveSince !== null,
            'current' => $current ? self::summary($current) : ($project->getAttribute('runtime_digest') ? ['id' => null, 'tag' => null, 'level' => null, 'digest' => $project->getAttribute('runtime_digest'), 'published_at' => null] : null),
            'latest' => $newest ? self::summary($newest) : null,
            'pending' => $pending->pluck('id')->all(),
            'pin' => $pin,
        ];
    }

    /** @return array{id: string, tag: string|null, digest: string|null, available: bool, by: string|null}|null */
    public function pinState(Project $project): ?array
    {
        $pin = (string) ($project->getAttribute('runtime_patch_pin') ?? '');
        if ($pin === '') {
            return null;
        }

        if (str_starts_with($pin, 'sha256:')) {
            $patch = $this->findByDigest($project->runtime, $this->versionOf($project), $pin);

            return ['id' => $patch?->id, 'tag' => $patch?->tag, 'digest' => $pin, 'available' => true, 'by' => $project->getAttribute('runtime_patch_pinned_by')];
        }

        $patch = $this->pinnable($project->runtime, $this->versionOf($project))->firstWhere('id', $pin);

        return [
            'id' => $pin,
            'tag' => $patch->tag ?? $this->find($pin)?->tag,
            'digest' => $patch?->digest,
            'available' => $patch !== null,
            'by' => $project->getAttribute('runtime_patch_pinned_by'),
        ];
    }

    /** @return array{id: string, tag: string, level: string, digest: string, published_at: string|null} */
    public static function summary(RuntimePatch $patch): array
    {
        return [
            'id' => $patch->id,
            'tag' => $patch->tag,
            'level' => $patch->level,
            'digest' => $patch->digest,
            'published_at' => $patch->published_at->toIso8601String(),
        ];
    }

    /**
     * Projects of the current organization (the current schema) whose live workload runs behind
     * $patch — not stopped, with an active deployment.
     *
     * @return Collection<int, Project>
     */
    public function affected(RuntimePatch $patch): Collection
    {
        // A project without a runtime version runs the runtime's default one
        $default = $this->defaultVersion($patch->runtime) === $patch->version;
        $projects = Project::query()
            ->where('runtime', $patch->runtime)
            ->where(fn ($q) => $q->where('runtime_version', $patch->version)->when($default, fn ($q) => $q->orWhereNull('runtime_version')))
            ->whereIn('service_type', self::CODE_TYPES)
            ->whereNull('stopped_at')
            ->addSelect(['projects.*', 'live_since' => Deployment::query()->select('created_at')
                ->whereColumn('deployments.project_id', 'projects.id')
                ->where('status', 'active')
                ->orderByDesc('created_at')
                ->limit(1)])
            ->get();

        return $projects->filter(fn (Project $p) => $this->isBehind($p, $patch))->values();
    }
}
