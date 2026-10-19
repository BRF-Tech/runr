<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Markup;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\Regions;
use Modules\Billing\Support\Versions;

/**
 * A tenant's markup on shared platform regions (docs/billing.md#markup), versioned like prices:
 *
 *  GET    /api/billing/markups            default markup and per region: current, scheduled, history
 *  POST   /api/billing/markups            {region_id?, percent, effective_from (today or later)}
 *  DELETE /api/billing/markups/{markup}   a version not yet in effect
 *
 * Tenant context only. The default markup prefills the acceptance of a new region offer and
 * applies to accepted regions without a markup of their own.
 */
class MarkupController extends Controller
{
    use BillingRequests;

    public function __construct(
        private readonly PriceBook $prices,
        private readonly Regions $regions,
    ) {}

    public function index(): JsonResponse
    {
        $this->tenantOrFail();

        $regionIds = Markup::query()->whereNotNull('region_id')->distinct()->pluck('region_id')->all();

        return response()->json([
            'default' => $this->versions($this->prices->markupVersions(null)),
            'regions' => collect($regionIds)->map(fn (string $id) => [
                'region_id' => $id,
                'region_name' => $this->regions->name($id),
            ] + $this->versions($this->prices->markupVersions($id)))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->tenantOrFail();

        $data = $request->validate([
            'region_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $regionId = $data['region_id'] ?? null;
        if ($regionId !== null && ! $this->regions->isShared($regionId)) {
            throw new BillingRefusal('markup_shared_regions_only', __('billing.markup_shared_regions_only'), 422);
        }

        // The version of the same scope and date is replaced (an upsert on the unique index)
        $markup = Versions::put(Markup::class, $regionId, $data['effective_from'], ['percent' => round((float) $data['percent'], 3), 'created_by' => (string) $request->user()->getAuthIdentifier()]);
        $this->prices->flush();

        return response()->json(['data' => $markup->fresh()?->toApiArray()], 201);
    }

    public function destroy(string $markup): Response
    {
        $this->tenantOrFail();

        $model = Markup::query()->findOrFail($markup);
        if ($model->effective_from->toDateString() <= Carbon::today()->toDateString()) {
            throw new BillingRefusal('markup_in_effect', __('billing.markup_in_effect'), 409);
        }

        $model->delete();
        $this->prices->flush();

        return response()->noContent();
    }

    /**
     * @param  Collection<int, Markup>  $versions
     * @return array{current: array<string, mixed>|null, scheduled: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    private function versions(Collection $versions): array
    {
        $today = Carbon::today()->toDateString();
        $past = $versions->filter(fn (Markup $m) => $m->effective_from->toDateString() <= $today);

        return [
            'current' => $past->last()?->toApiArray(),
            'scheduled' => $versions->filter(fn (Markup $m) => $m->effective_from->toDateString() > $today)->map(fn (Markup $m) => $m->toApiArray())->values()->all(),
            'history' => $past->reverse()->map(fn (Markup $m) => $m->toApiArray())->values()->all(),
        ];
    }
}
