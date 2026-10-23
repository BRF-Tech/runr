<?php

namespace App\Policies;

use App\Models\Gateway;
use App\Models\Project;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gateways belong to the user who created them (one per user and region). Others see them with
 * organization-wide projects.view; changing someone else's gateway (its rate limit, its routes)
 * is region administration (regions.manage). Routing a project through a gateway also needs
 * projects.update on that project. Anyone who may change projects gets a gateway of their own in a
 * region (create).
 */
class GatewayPolicy
{
    public function __construct(private readonly Rbac $rbac) {}

    public function view(Authenticatable $user, Gateway $gateway): bool
    {
        return ($this->owns($user, $gateway) && $this->rbac->allowsAnywhere($user, 'projects.view'))
            || $this->rbac->allows($user, 'projects.view');
    }

    /** One's own gateway in a region (POST /gateways): someone who may change projects. */
    public function create(Authenticatable $user): bool
    {
        return $this->rbac->allowsAnywhere($user, 'projects.update');
    }

    public function update(Authenticatable $user, Gateway $gateway): bool
    {
        return ($this->owns($user, $gateway) && $this->rbac->allowsAnywhere($user, 'projects.update'))
            || $this->rbac->allows($user, 'regions.manage');
    }

    public function route(Authenticatable $user, Gateway $gateway, Project $project): bool
    {
        return $this->update($user, $gateway) && $this->rbac->allows($user, 'projects.update', $project);
    }

    private function owns(Authenticatable $user, Gateway $gateway): bool
    {
        return (string) $gateway->user_id === (string) $user->getAuthIdentifier();
    }
}
