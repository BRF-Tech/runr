<?php

namespace Modules\Billing\Http\Controllers\Concerns;

use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Period;
use Modules\Tenancy\Models\Tenant;

/**
 * Shared request helpers of the billing controllers.
 */
trait BillingRequests
{
    protected function period(Request $request): Period
    {
        $value = $request->query('period');

        return Period::isKey($value) ? Period::fromKey((string) $value) : Period::current();
    }

    protected function perPage(Request $request, int $default = 50): int
    {
        return max(1, min(200, (int) $request->query('per_page', (string) $default)));
    }

    /** May the user see what usage costs (metered cost, difference)? billing.view holders. */
    protected function canViewCosts(Request $request): bool
    {
        return app(Rbac::class)->allows($request->user(), 'billing.view');
    }

    /** @throws BillingRefusal */
    protected function tenantOrFail(): Tenant
    {
        $tenant = BillingContext::tenant();
        if (! $tenant instanceof Tenant) {
            throw new BillingRefusal('tenant_context_required', __('billing.tenant_context_required'), 404);
        }

        return $tenant;
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $data
     */
    protected function paginated(LengthAwarePaginator $page, array $data, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]] + $extra);
    }

    protected function boolQuery(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }

        return filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
