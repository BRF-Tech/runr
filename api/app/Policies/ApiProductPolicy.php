<?php

namespace App\Policies;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Gateway;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Consumers and plans of the API product (docs/api-product.md#permissions). Those of a gateway
 * follow the gateway (GatewayPolicy): whoever may see it sees them, whoever may change it manages
 * them. Those of the organization (gateway_id null) are accepted on every gateway and need
 * api_products.manage — to see and to change them.
 */
class ApiProductPolicy
{
    public function __construct(private readonly Rbac $rbac) {}

    public function view(Authenticatable $user, ApiConsumer|ApiPlan $model): bool
    {
        $gateway = $model->gateway;

        return $gateway ? Gate::forUser($user)->allows('view', $gateway) : $this->manageOrganization($user);
    }

    public function update(Authenticatable $user, ApiConsumer|ApiPlan $model): bool
    {
        $gateway = $model->gateway;

        return $gateway ? Gate::forUser($user)->allows('update', $gateway) : $this->manageOrganization($user);
    }

    public function delete(Authenticatable $user, ApiConsumer|ApiPlan $model): bool
    {
        return $this->update($user, $model);
    }

    /** A new consumer / plan of $gateway, or of the organization (null). */
    public function create(Authenticatable $user, ?Gateway $gateway = null): bool
    {
        return $gateway ? Gate::forUser($user)->allows('update', $gateway) : $this->manageOrganization($user);
    }

    public function manageOrganization(Authenticatable $user): bool
    {
        return $this->rbac->allows($user, 'api_products.manage');
    }
}
