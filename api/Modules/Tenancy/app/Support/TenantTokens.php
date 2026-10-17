<?php

namespace Modules\Tenancy\Support;

use App\Mcp\Models\McpSession;
use App\Models\User;
use App\Support\Auth\AccountPruner;
use App\Support\Auth\UserTokens;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * API tokens created in an organization work in that organization only (tenant_id, see
 * IdentifyTenant), so they go with the membership: a member removed from an organization loses
 * the tokens they created in it, and a deleted organization takes every token created in it — each
 * in the transaction that removes the membership or the organization, then the MCP sessions in
 * it. Sign-in sessions (no tenant_id) and the tokens of other organizations stay.
 *
 * As an AccountPruner (tokens:prune) it removes the tokens earlier releases left behind: those
 * IdentifyTenant refuses for good (refused()).
 */
final class TenantTokens implements AccountPruner
{
    public static function removeMember(TenantUser $membership): void
    {
        $tenantId = (string) $membership->getAttribute('tenant_id');
        $userId = $membership->getAttribute('user_id');

        $membership->getConnection()->transaction(function () use ($membership, $tenantId, $userId) {
            UserTokens::query($userId, $membership->getConnectionName())->where('tenant_id', $tenantId)->delete();
            $membership->delete();
        });

        McpSession::query()->where('tenant_id', $tenantId)->where('user_id', (string) $userId)->delete();
    }

    /** The organization's row; its tenant_users and tenant_regions rows cascade on tenant_id. */
    public static function deleteTenant(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getTenantKey();

        $tenant->getConnection()->transaction(function () use ($tenant, $tenantId) {
            /** @var class-string<PersonalAccessToken> $model */
            $model = Sanctum::$personalAccessTokenModel;
            $model::on($tenant->getConnectionName())->where('tenant_id', $tenantId)->delete();
            $tenant->delete();
        });

        McpSession::query()->where('tenant_id', $tenantId)->delete();
    }

    /**
     * Tokens created in an organization that is gone for them: the organization no longer exists,
     * or the token's user is no longer a member of it and is no platform administrator — the
     * tokens IdentifyTenant refuses (403). An administrator's token keeps working in an
     * organization they are no member of (IdentifyTenant::handleAdmin), and one of a deleted
     * organization would act outside any organization: it goes as well.
     *
     * @return Builder<PersonalAccessToken>
     */
    public static function refused(): Builder
    {
        $tokens = UserTokens::ofUsers();
        $table = $tokens->getModel()->getTable();
        $tenants = (new Tenant)->getTable();
        $members = (new TenantUser)->getTable();
        $users = (new User)->getTable();

        return $tokens
            ->whereNotNull("{$table}.tenant_id")
            ->where(fn (Builder $q) => $q
                ->whereNotExists(fn ($s) => $s->selectRaw('1')->from($tenants)->whereColumn("{$tenants}.id", "{$table}.tenant_id"))
                ->orWhere(fn (Builder $q) => $q
                    ->whereNotExists(fn ($s) => $s->selectRaw('1')->from($members)
                        ->whereColumn("{$members}.tenant_id", "{$table}.tenant_id")
                        ->whereColumn("{$members}.user_id", "{$table}.tokenable_id"))
                    ->whereNotExists(fn ($s) => $s->selectRaw('1')->from($users)
                        ->whereColumn("{$users}.id", "{$table}.tokenable_id")
                        ->where("{$users}.is_admin", true))));
    }

    public function label(): string
    {
        return 'token(s) of former organization members or deleted organizations';
    }

    public function prune(): int
    {
        return self::refused()->delete();
    }
}
