<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Binding;
use App\Models\Gateway;
use App\Models\Group;
use App\Models\KnativeRegion;
use App\Models\Role;
use App\Services\OrgTransfer\Contracts\MemberInvitations;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Facades\DB;

/**
 * organization/organization.json: the organization's settings (default role), custom roles,
 * groups with their organization-wide role, the members (emails + roles, only when the user
 * asked for them — never credentials, never two-factor or SSO identities) and the regions the
 * package's projects, bindings and gateways ran in (for the import's region mapping).
 *
 * Import: custom roles and groups are created unless one of the same key / name exists (then
 * left as they are); permissions this installation does not know are dropped with a warning.
 * Members are invited only when the user asks for it (invite_members) and the installation can
 * invite (multi-tenant); else they are listed as a manual step. The Owner role is never handed
 * out by an import: owners of the source are invited as admins.
 */
class OrganizationSection implements TransferSection
{
    public function __construct(private AccessManager $access, private Rbac $rbac) {}

    public function key(): string
    {
        return 'organization';
    }

    public function export(ExportContext $context): void
    {
        $roles = Role::query()->orderBy('key')->get()->map(fn (Role $r) => [
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'permissions' => (array) $r->permissions,
        ])->all();

        $groups = Group::query()->orderBy('name')->get()->map(fn (Group $g) => [
            'id' => (string) $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'role' => $g->orgRole(),
        ])->all();

        $members = null;
        if (! empty($context->options['include_members'])) {
            $groupNames = Group::query()->pluck('name', 'id');
            $memberships = DB::table('rbac_group_user')->get(['group_id', 'user_id'])
                ->groupBy(fn ($row) => (string) $row->user_id);

            $members = $this->rbac->organization()->members()->map(fn ($m) => [
                'email' => $m->email,
                'name' => $m->name,
                'role' => $m->role,
                'groups' => collect($memberships->get((string) $m->id, []))
                    ->map(fn ($row) => $groupNames[$row->group_id] ?? null)->filter()->values()->all(),
            ])->filter(fn ($m) => ! empty($m['email']))->values()->all();
            $context->count('members', count($members));
        }

        $context->putJson(PackageFormat::ORGANIZATION, [
            'settings' => ['default_role' => $this->access->defaultRole()],
            'roles' => $roles,
            'groups' => $groups,
            'members' => $members,
            'regions' => $this->regions($context),
        ]);
        $context->count('roles', count($roles));
        $context->count('groups', count($groups));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $data = (array) $context->json(PackageFormat::ORGANIZATION, []);

        foreach ((array) ($data['roles'] ?? []) as $role) {
            $exists = Role::query()->where('key', (string) ($role['key'] ?? ''))->exists() || PermissionCatalog::isBuiltinRole((string) ($role['key'] ?? ''));
            $plan->organization['roles'][] = ['key' => $role['key'] ?? null, 'name' => $role['name'] ?? null, 'status' => $exists ? 'exists' : 'create'];
        }
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $exists = Group::query()->where('name', (string) ($group['name'] ?? ''))->exists();
            $plan->organization['groups'][] = ['name' => $group['name'] ?? null, 'role' => $group['role'] ?? null, 'status' => $exists ? 'exists' : 'create'];
        }

        $members = (array) ($data['members'] ?? []);
        $current = $this->rbac->organization()->members()->pluck('email')->map(fn ($e) => strtolower((string) $e))->all();
        $invitations = app(MemberInvitations::class);
        foreach ($members as $member) {
            $email = strtolower((string) ($member['email'] ?? ''));
            $plan->organization['members'][] = [
                'email' => $email,
                'role' => self::importRole((string) ($member['role'] ?? '')),
                'status' => in_array($email, $current, true) ? 'member' : ($invitations->supported() && $context->choices->inviteMembers ? 'invite' : 'manual'),
            ];
        }
        $toInvite = array_values(array_filter($plan->organization['members'], fn ($m) => $m['status'] !== 'member'));
        if ($toInvite && ! ($invitations->supported() && $context->choices->inviteMembers)) {
            $plan->manual('members', __('org_transfer.manual.members'), array_map(fn ($m) => "{$m['email']} ({$m['role']})", $toInvite));
        }
    }

    public function import(ImportContext $context): void
    {
        $data = (array) $context->json(PackageFormat::ORGANIZATION, []);
        $actor = $context->user;
        if ($actor === null) {
            return;
        }

        // Custom roles first: groups and members may hold them
        foreach ((array) ($data['roles'] ?? []) as $role) {
            $key = (string) ($role['key'] ?? '');
            if ($key === '' || $context->settled('role', $key)) {
                continue;
            }
            if (PermissionCatalog::isBuiltinRole($key) || Role::query()->where('key', $key)->exists()) {
                $context->record('role', $key, $role['name'] ?? $key, 'exists', $key);

                continue;
            }
            $known = array_values(array_filter((array) ($role['permissions'] ?? []), fn ($p) => $p === PermissionCatalog::WILDCARD || PermissionCatalog::exists((string) $p)));
            if (count($known) < count((array) ($role['permissions'] ?? []))) {
                $context->warn(__('org_transfer.import.role_permissions_dropped', ['role' => $role['name'] ?? $key]));
            }
            try {
                $created = $this->access->createRole($actor, ['key' => $key, 'name' => (string) ($role['name'] ?? $key), 'description' => $role['description'] ?? null, 'permissions' => $known]);
                $context->record('role', $key, $created->name, 'created', $created->key);
            } catch (\Throwable $e) {
                $context->record('role', $key, $role['name'] ?? $key, 'failed', null, $e->getMessage());
            }
        }

        foreach ((array) ($data['groups'] ?? []) as $group) {
            $source = (string) ($group['id'] ?? $group['name'] ?? '');
            $name = (string) ($group['name'] ?? '');
            if ($source === '' || $name === '' || $context->settled('group', $source)) {
                continue;
            }
            $existing = Group::query()->where('name', $name)->first();
            if ($existing) {
                $context->record('group', $source, $name, 'exists', (string) $existing->id);

                continue;
            }
            try {
                $created = Group::query()->create(['name' => $name, 'description' => $group['description'] ?? null]);
                $role = $group['role'] ?? null;
                if (is_string($role) && $role !== '' && $role !== PermissionCatalog::OWNER && $this->rbac->roleExists($role)) {
                    $this->access->setGroupRole($actor, $created, $role);
                }
                $context->record('group', $source, $name, 'created', (string) $created->id);
            } catch (\Throwable $e) {
                $context->record('group', $source, $name, 'failed', null, $e->getMessage());
            }
        }

        $default = $data['settings']['default_role'] ?? null;
        if (is_string($default) && $default !== PermissionCatalog::OWNER && ! $context->settled('setting', 'default_role')) {
            try {
                if ($this->rbac->roleExists($default)) {
                    $this->access->setDefaultRole($actor, $default);
                    $context->record('setting', 'default_role', 'default_role', 'created', $default);
                } else {
                    $context->record('setting', 'default_role', 'default_role', 'skipped', null, __('org_transfer.import.role_missing', ['role' => $default]));
                }
            } catch (\Throwable $e) {
                $context->record('setting', 'default_role', 'default_role', 'failed', null, $e->getMessage());
            }
        }

        $this->members($context, (array) ($data['members'] ?? []));
    }

    /** @param list<array<string, mixed>> $members */
    private function members(ImportContext $context, array $members): void
    {
        if ($members === []) {
            return;
        }

        $invitations = app(MemberInvitations::class);
        $invite = $invitations->supported() && $context->choices->inviteMembers;
        $current = $this->rbac->organization()->members()->pluck('email')->map(fn ($e) => strtolower((string) $e))->all();
        $manual = [];

        foreach ($members as $member) {
            $email = strtolower(trim((string) ($member['email'] ?? '')));
            if ($email === '' || $context->settled('member', $email)) {
                continue;
            }
            $role = self::importRole((string) ($member['role'] ?? ''));
            if (in_array($email, $current, true)) {
                $context->record('member', $email, $email, 'exists');

                continue;
            }
            if (! $invite || $context->user === null) {
                $manual[] = "{$email} ({$role})";
                $context->record('member', $email, $email, 'manual', null, __('org_transfer.manual.members'));

                continue;
            }
            try {
                $result = $invitations->invite($email, $this->rbac->roleExists($role) ? $role : $this->access->defaultRole(), $context->user);
                $context->record('member', $email, $email, $result === 'invited' || $result === 'added' ? 'created' : 'exists', null, $result);
            } catch (\Throwable $e) {
                $context->record('member', $email, $email, 'failed', null, $e->getMessage());
            }
        }

        if ($manual) {
            $context->manual('members', __('org_transfer.manual.members'), $manual);
        }
    }

    /** The Owner role is not handed out by an import: owners come back as admins. */
    private static function importRole(string $role): string
    {
        return $role === PermissionCatalog::OWNER || $role === '' ? 'admin' : $role;
    }

    /** @return list<array<string, mixed>> */
    private function regions(ExportContext $context): array
    {
        $ids = $context->projects()->pluck('knative_region_id')
            ->merge(Binding::query()->whereNotNull('knative_region_id')->pluck('knative_region_id'))
            ->merge(Gateway::query()->pluck('knative_region_id'))
            ->filter()->map(fn ($id) => (string) $id)->unique()->values();

        return KnativeRegion::query()->whereIn('id', $ids->all())->orderBy('name')->get()
            ->map(fn (KnativeRegion $r) => [
                'id' => (string) $r->id,
                'name' => $r->name,
                'location' => $r->getAttribute('location'),
                'registry_address' => $r->registry_address,
                'is_demo' => (bool) $r->is_demo,
            ])->values()->all();
    }
}
