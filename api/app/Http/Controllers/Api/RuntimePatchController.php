<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformAdvisory;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Support\Rbac\Rbac;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The runtime patches every signed-in user may see (docs/runtimes.md#runtime-patches):
 *
 *  GET /api/runtime-patches?runtime=&version=   patches of the feed, newest first
 *  GET /api/runtime-patches/banners             what the console keeps on screen until it is
 *                                               resolved: critical / zero-day patches the user's
 *                                               projects run behind; for platform administrators
 *                                               also a newer RunR release and a feed that is off
 *                                               or refused
 */
class RuntimePatchController extends Controller
{
    public function __construct(private readonly RuntimePatches $patches) {}

    #[OpenApiResponse(200, 'The patches of the feed (?runtime=, ?version=), the feed\'s status and how many patches per version are kept', type: 'array{data: list<array{'.RuntimePatch::OPENAPI_FIELDS.'}>, feed: array{enabled: bool, status: "ok"|"invalid_signature"|"invalid_schema"|"unreachable"|"disabled", fetched_at: string|null}, keep: int}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'runtime' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'string', 'max:64'],
        ]);

        $patches = RuntimePatch::query()->where('in_feed', true)
            ->when($data['runtime'] ?? null, fn ($q, $runtime) => $q->where('runtime', $runtime))
            ->when($data['version'] ?? null, fn ($q, $version) => $q->where('version', $version))
            ->orderBy('runtime')->orderBy('version')->orderByDesc('published_at')
            ->get();

        $feed = RuntimePatchFeed::current();

        return response()->json([
            'data' => $patches->map(fn (RuntimePatch $p) => $p->toApi())->values()->all(),
            'feed' => ['enabled' => RuntimePatchFeed::enabled(), 'status' => RuntimePatchFeed::enabled() ? $feed->status : RuntimePatchFeed::DISABLED, 'fetched_at' => $feed->fetched_at?->toIso8601String()],
            'keep' => (int) config('runtime_patches.keep', 5),
        ]);
    }

    #[OpenApiResponse(200, 'Banners for the signed-in user: urgent patches of the projects they see; for platform administrators a newer RunR release and the feed\'s trouble', type: 'array{data: list<array{kind: "runtime_patch", level: string, overdue: bool, patch: array{'.RuntimePatch::OPENAPI_FIELDS.'}, project_count: int, projects: list<array{id: string, name: string}>}|array{kind: "platform_advisory", level: string, running: string, version: string, reason: string, url: string|null, published_at: string|null}|array{kind: "feed_disabled", level: string}|array{kind: "feed_invalid", level: string, status: string, error: string|null}>}')]
    public function banners(Request $request, Rbac $rbac): JsonResponse
    {
        $user = $request->user();
        $visible = $rbac->visibleProjectIds($user, 'projects.view');
        $banners = [];

        $urgent = RuntimePatch::query()->where('in_feed', true)->whereIn('level', RuntimePatch::DEADLINE_LEVELS)
            ->orderByDesc('published_at')->get();
        foreach ($urgent as $patch) {
            $projects = $this->patches->affected($patch)
                ->filter(fn (Project $p) => $visible === null || in_array($p->id, $visible, true))
                ->values();
            if ($projects->isEmpty()) {
                continue;
            }

            $banners[] = [
                'kind' => 'runtime_patch',
                'level' => $patch->level,
                'overdue' => $patch->isOverdue(),
                'patch' => $patch->toApi(),
                'project_count' => $projects->count(),
                'projects' => $projects->take(20)->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ];
        }

        if ($rbac->isPlatformAdmin($user)) {
            $banners = array_merge($banners, $this->platformBanners());
        }

        return response()->json(['data' => $banners])->header('Cache-Control', 'no-store, private');
    }

    /** A newer RunR release (the newest, at the most urgent level of all newer ones) and the feed's own trouble. */
    private function platformBanners(): array
    {
        $banners = [];
        $running = (string) config('app.version');

        $newer = PlatformAdvisory::query()->where('in_feed', true)->get()
            ->filter(fn (PlatformAdvisory $a) => $a->isNewerThan($running))
            ->sort(fn (PlatformAdvisory $a, PlatformAdvisory $b) => version_compare(ltrim($b->version, 'vV'), ltrim($a->version, 'vV')))
            ->values();
        if ($newer->isNotEmpty()) {
            $newest = $newer->first();
            $level = $newer->reduce(fn (?string $carry, PlatformAdvisory $a) => RuntimePatch::higher($carry, $a->level));
            $banners[] = ['kind' => 'platform_advisory', 'level' => $level, 'running' => $running] + $newest->toApi();
        }

        if (! RuntimePatchFeed::enabled()) {
            $banners[] = ['kind' => 'feed_disabled', 'level' => RuntimePatch::SECURITY];
        } else {
            $feed = RuntimePatchFeed::current();
            if (in_array($feed->status, [RuntimePatchFeed::INVALID_SIGNATURE, RuntimePatchFeed::INVALID_SCHEMA], true)) {
                $banners[] = ['kind' => 'feed_invalid', 'level' => RuntimePatch::CRITICAL, 'status' => $feed->status, 'error' => $feed->error];
            }
        }

        return $banners;
    }
}
