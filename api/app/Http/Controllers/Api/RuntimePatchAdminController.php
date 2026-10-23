<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformAdvisory;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Services\GitDeploy\TenantContext;
use App\Services\Observability\TenantScope;
use App\Services\Runtimes\Patches\PatchFeedSync;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The platform administrators' Patches page (docs/runtimes.md#the-patches-page), all @platform:
 *
 *  GET  /api/admin/runtime-patches                       feed status (last check / fetch, signature),
 *                                                        policy, patches with the projects behind each
 *                                                        per organization, RunR releases
 *  POST /api/admin/runtime-patches/check                 read the feed now
 *  PUT  /api/admin/runtime-patches/policy                {auto_migrate} automatic migration at deadlines
 *  POST /api/admin/runtime-patches/{patch}/remind        notify everyone it concerns again
 *  POST /api/admin/runtime-patches/{patch}/force         migrate every project behind it now
 *  PUT  /api/admin/runtime-patches/{patch}/deadline      {deadline_at} extend (or move) the deadline
 */
class RuntimePatchAdminController extends Controller
{
    public function __construct(
        private readonly RuntimePatches $patches,
        private readonly RuntimePatchMigrations $migrations,
        private readonly RuntimePatchNotifier $notifier,
    ) {}

    #[OpenApiResponse(200, 'The Patches page: the feed, every patch with the projects behind it per organization, the advisories of newer RunR releases', type: 'array{data: array{feed: '.RuntimePatchFeed::OPENAPI_TYPE.', key_configured: bool, running_version: string, patches: list<array{'.RuntimePatch::OPENAPI_FIELDS.', announced_at: string|null, removed_at: string|null, overdue: bool, affected: int, organizations: list<array{tenant_id: string|null, name: string|null, affected: int, migrations: array<string, int>}>}>, advisories: list<array{version: string, level: string, reason: string, url: string|null, published_at: string|null, newer: bool}>}}')]
    public function index(): JsonResponse
    {
        $patches = RuntimePatch::query()
            ->where(fn ($q) => $q->where('in_feed', true)->orWhere('removed_at', '>=', now()->subDays(30)))
            ->orderBy('runtime')->orderBy('version')->orderByDesc('published_at')
            ->get();

        $exposure = $this->exposure($patches->where('in_feed', true)->values()->all());

        return response()->json(['data' => [
            'feed' => RuntimePatchFeed::current()->toApi(),
            'key_configured' => trim((string) config('runtime_patches.public_key')) !== '',
            'running_version' => (string) config('app.version'),
            'patches' => $patches->map(fn (RuntimePatch $p) => $p->toApi() + [
                'announced_at' => $p->announced_at?->toIso8601String(),
                'removed_at' => $p->removed_at?->toIso8601String(),
                'overdue' => $p->isOverdue(),
                'affected' => array_sum(array_column($exposure[$p->id] ?? [], 'affected')),
                'organizations' => $exposure[$p->id] ?? [],
            ])->values()->all(),
            'advisories' => PlatformAdvisory::query()->where('in_feed', true)->orderByDesc('published_at')->get()
                ->map(fn (PlatformAdvisory $a) => $a->toApi() + ['newer' => $a->isNewerThan((string) config('app.version'))])->values()->all(),
        ]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Checks the feed now.
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 200>
     */
    #[OpenApiResponse(200, 'The feed after the check', type: 'array{data: '.RuntimePatchFeed::OPENAPI_TYPE.'}')]
    #[OpenApiResponse(502, 'The feed did not verify or could not be read: its status and error', type: 'array{data: '.RuntimePatchFeed::OPENAPI_TYPE.'}')]
    public function check(PatchFeedSync $sync): JsonResponse
    {
        $feed = $sync->sync(force: true);

        return response()->json(['data' => $feed->toApi()], in_array($feed->status, [RuntimePatchFeed::OK, RuntimePatchFeed::DISABLED], true) ? 200 : 502);
    }

    public function policy(Request $request): JsonResponse
    {
        $data = $request->validate(['auto_migrate' => ['required', 'boolean']]);

        $feed = RuntimePatchFeed::current();
        $feed->forceFill(['auto_migrate' => (bool) $data['auto_migrate']])->save();

        return response()->json(['data' => $feed->toApi()]);
    }

    public function remind(RuntimePatch $patch): JsonResponse
    {
        return response()->json(['data' => ['told' => $this->notifier->announce($patch, remind: true)]]);
    }

    public function force(Request $request, RuntimePatch $patch): JsonResponse
    {
        if (! $patch->in_feed) {
            return response()->json(['message' => __('runtime_patches.errors.no_patch'), 'code' => 'no_patch'], 422);
        }

        $started = 0;
        $initiator = (string) $request->user()->getAuthIdentifier();
        TenantScope::each(function () use ($patch, $initiator, &$started) {
            $started += $this->migrations->migrateAffected($patch, RuntimePatchMigration::FORCE, $initiator);
        });

        return response()->json(['data' => ['started' => $started]], 202);
    }

    public function deadline(Request $request, RuntimePatch $patch): JsonResponse
    {
        if (! $patch->hasDeadline()) {
            return response()->json(['message' => __('runtime_patches.errors.no_deadline'), 'code' => 'no_deadline'], 422);
        }

        $data = $request->validate(['deadline_at' => ['required', 'date', 'after:now']]);

        $patch->forceFill([
            'deadline_extended_to' => $data['deadline_at'],
            'deadline_extended_by' => (string) $request->user()->getAuthIdentifier(),
        ])->save();

        return response()->json(['data' => $patch->toApi()]);
    }

    /**
     * Projects behind each patch, per organization (the central schema first, then every tenant):
     * [{tenant_id, name, affected, migrations: {status: count}}].
     *
     * @param  list<RuntimePatch>  $patches
     * @return array<string, list<array<string, mixed>>>
     */
    private function exposure(array $patches): array
    {
        $result = [];
        if ($patches === []) {
            return $result;
        }

        TenantScope::each(function (?string $tenantId) use ($patches, &$result) {
            $name = $tenantId !== null ? TenantContext::find($tenantId)?->name : null;
            $tracked = Schema::hasTable('runtime_patch_migrations');

            foreach ($patches as $patch) {
                $affected = $this->patches->affected($patch)->count();
                $migrations = $tracked
                    ? RuntimePatchMigration::query()->where('patch_id', $patch->id)
                        ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
                        ->map(fn ($n) => (int) $n)->all()
                    : [];
                if ($affected === 0 && $migrations === []) {
                    continue;
                }

                $result[$patch->id][] = ['tenant_id' => $tenantId, 'name' => $name, 'affected' => $affected, 'migrations' => $migrations];
            }
        });

        return $result;
    }
}
