<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Support\BillingContext;

/**
 * The billing records of 0.5.7 kept by the upgrade (docs/upgrade.md): charges, plans,
 * subscriptions, purchases, capacity deals and cost configurations, as they were.
 *
 *  GET /api/billing/legacy-records   ?source=<table> &tenant=<tenant id> &per_page=
 */
class LegacyRecordController extends Controller
{
    use BillingRequests;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'source' => ['sometimes', 'string', 'max:64'],
            'tenant' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $connection = DB::connection(BillingContext::central());
        if (! $connection->getSchemaBuilder()->hasTable('billing_legacy_archive')) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $this->perPage($request), 'total' => 0]]);
        }

        $page = $connection->table('billing_legacy_archive')
            ->when($request->filled('source'), fn ($q) => $q->where('source_table', (string) $request->query('source')))
            ->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', (string) $request->query('tenant')))
            ->orderBy('id')
            ->paginate($this->perPage($request));

        $data = collect($page->items())->map(fn ($row) => [
            'id' => $row->id,
            'source_table' => $row->source_table,
            'row_id' => $row->row_id,
            'tenant_id' => $row->tenant_id,
            'data' => is_string($row->data) ? (json_decode($row->data, true) ?? $row->data) : $row->data,
            'archived_at' => $row->archived_at,
        ])->all();

        return $this->paginated($page, $data);
    }
}
