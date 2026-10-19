<?php

namespace Modules\Ticket\Support;

use App\Models\User;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Who works the support queue of a single-tenant install: the members whose role holds
 * tickets.manage (owners and admins by default, and custom roles granting it). Where the users
 * table has is_admin (the Tenancy module's column), the platform administrators are staff too.
 */
final class TicketStaff
{
    /** @return Collection<int, User> */
    public static function users(): Collection
    {
        $model = new User;
        $hasAdminFlag = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'is_admin');
        $rbac = app(Rbac::class);

        return User::all()
            ->filter(fn (User $user) => ($hasAdminFlag && $user->getAttribute('is_admin')) || $rbac->allows($user, 'tickets.manage'))
            ->sortBy('name')
            ->values();
    }

    /**
     * The assignee choices of the staff view.
     *
     * @param  Collection<int, User>  $users
     * @return list<array{id: int|string, name: string|null, email: string|null}>
     */
    public static function present(Collection $users): array
    {
        return $users->map(fn (User $u) => ['id' => $u->getKey(), 'name' => $u->name, 'email' => $u->email])->values()->all();
    }
}
