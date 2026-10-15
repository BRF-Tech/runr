<?php

namespace Modules\AuthProvider\Services;

use App\Models\Group;
use App\Models\User;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SsoGroupMapping;

/**
 * SSO group mapping on sign-in (docs/permissions.md):
 *  - groups: every mapped group follows the IdP — the member is added (source "sso") when one of
 *    their external groups maps to it and removed when none does; manual memberships stay
 *  - role: when a mapping of the provider sets a role, the IdP manages the member's
 *    organization-wide role — the matching role with the most permissions, else the provider's
 *    (or the organization's) default role; owners keep their role
 *  - just-in-time users without a role mapping get the provider's default role
 */
class SsoGroupSync
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly AccessManager $access,
    ) {}

    /**
     * External group names of the signed-in subject: the configured claim / SAML attribute, else
     * the common ones (groups, the Entra ID group claim, memberOf).
     *
     * @return list<string>
     */
    public function externalGroups(AuthProvider $provider, mixed $externalUser): array
    {
        $raw = method_exists($externalUser, 'getRaw') ? (array) $externalUser->getRaw() : [];
        $sources = $provider->isSaml()
            ? [(array) ($raw['attributes'] ?? []), (array) ($raw['friendly_attributes'] ?? [])]
            : [$raw];
        $names = $provider->groups_attribute ? [$provider->groups_attribute] : AuthProvider::DEFAULT_GROUPS_ATTRIBUTES;

        $groups = [];
        foreach ($sources as $source) {
            foreach ($source as $key => $values) {
                foreach ($names as $name) {
                    if (strcasecmp((string) $key, $name) !== 0) {
                        continue;
                    }
                    foreach ((array) $values as $value) {
                        if (is_scalar($value) && trim((string) $value) !== '') {
                            $groups[] = trim((string) $value);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($groups));
    }

    public function sync(AuthProvider $provider, User $user, mixed $externalUser, bool $isNew): void
    {
        $org = $this->rbac->organization();
        if (! $org->exists() || ! $org->isMember($user->getKey())) {
            return;
        }

        $mappings = SsoGroupMapping::query()
            ->where(fn ($q) => $q->whereNull('auth_provider_id')->orWhere('auth_provider_id', $provider->getKey()))
            ->get();

        $external = array_map('mb_strtolower', $this->externalGroups($provider, $externalUser));
        $matched = $mappings->filter(fn (SsoGroupMapping $m) => in_array(mb_strtolower(trim($m->external_group)), $external, true));

        $this->syncGroups($user, $mappings, $matched);
        $this->syncRole($provider, $user, $mappings, $matched, $isNew);

        $this->access->changed();
    }

    private function syncGroups(User $user, Collection $mappings, Collection $matched): void
    {
        $mapped = $mappings->pluck('group_id')->filter()->unique()->values();
        if ($mapped->isEmpty()) {
            return;
        }

        $target = $matched->pluck('group_id')->filter()->unique();
        $current = DB::table('rbac_group_user')
            ->where('user_id', $user->getKey())
            ->whereIn('group_id', $mapped)
            ->get(['group_id', 'source'])
            ->keyBy('group_id');

        foreach ($target as $groupId) {
            if (! $current->has($groupId) && ($group = Group::query()->find($groupId))) {
                $this->access->addGroupMembers($group, [$user->getKey()], Group::SOURCE_SSO);
            }
        }

        foreach ($current as $groupId => $membership) {
            if ($membership->source === Group::SOURCE_SSO && ! $target->contains($groupId)) {
                DB::table('rbac_group_user')->where('group_id', $groupId)->where('user_id', $user->getKey())->delete();
            }
        }
    }

    private function syncRole(AuthProvider $provider, User $user, Collection $mappings, Collection $matched, bool $isNew): void
    {
        $org = $this->rbac->organization();
        $current = $org->memberRole($user->getKey());
        if ($current === PermissionCatalog::OWNER) {
            return;
        }

        $assignable = fn (?string $role) => $role && $role !== PermissionCatalog::OWNER && $this->rbac->roleExists($role);
        $roleManaged = $mappings->contains(fn (SsoGroupMapping $m) => $m->role !== null);

        $role = $matched->pluck('role')->filter($assignable)->unique()
            ->sortByDesc(fn (string $r) => count($this->rbac->rolePermissions($r)))
            ->first();

        if ($role === null && ($roleManaged || $isNew)) {
            $role = $assignable($provider->default_role) ? $provider->default_role : ($roleManaged ? $this->access->defaultRole() : null);
        }

        if ($role !== null && $role !== $current) {
            $org->setMemberRole($user->getKey(), $role);
        }
    }
}
