<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UserController;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Http\Controllers\TenancyUserController;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalUser;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant leftovers of deleted accounts and memberships (docs/security.md, "Sign-in sessions
 * and API tokens"): a deleted account's copies and role rows go from every organization it
 * belonged to, and tokens:prune removes what earlier releases left behind — the tokens
 * organizations refuse for good and the copies of accounts that no longer exist.
 */
class TenantAccountCleanupTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, WiresTenancy;

    private const SCHEMA = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0003_create_users_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        // What the Tenancy module registers when it is on (this suite runs with it off)
        TenancyServiceProvider::registerAccountCleanup();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** A member of $tenant: the membership and, in its schema, the copy, a role and a group. */
    private function member(Tenant $tenant, User $user, bool $withSchema = true): void
    {
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'developer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $withSchema) {
            return;
        }
        $tenant->run(function () use ($user) {
            TenantLocalUser::withoutEvents(fn () => TenantLocalUser::query()->create([
                'id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'password' => 'x',
            ]));
            RoleAssignment::query()->create(['subject_type' => RoleAssignment::SUBJECT_USER, 'subject_id' => (string) $user->id, 'role' => 'developer']);
            $group = (string) Str::ulid();
            DB::table('rbac_groups')->insert(['id' => $group, 'name' => "ops-{$user->id}", 'created_at' => now(), 'updated_at' => now()]);
            DB::table('rbac_group_user')->insert(['group_id' => $group, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @return array{0: bool, 1: int, 2: int} the copy, role assignments and group memberships of $userId in $tenant */
    private function leftIn(Tenant $tenant, int $userId): array
    {
        return $tenant->run(fn () => [
            TenantLocalUser::query()->whereKey($userId)->exists(),
            RoleAssignment::query()->where('subject_type', RoleAssignment::SUBJECT_USER)->where('subject_id', (string) $userId)->count(),
            DB::table('rbac_group_user')->where('user_id', $userId)->count(),
        ]);
    }

    private function tokenIn(User $user, ?string $tenantId, string $name): int
    {
        $token = $user->createToken($name)->accessToken;
        $token->forceFill(['tenant_id' => $tenantId])->save();

        return (int) $token->getKey();
    }

    // ── A deleted account's copies ──────────────────────────────────────────

    public function test_a_platform_admin_deleting_an_account_removes_its_copies_in_every_organization(): void
    {
        $this->app->bind(UserController::class, TenancyUserController::class);
        $acme = $this->createTenantWithSchema('acme', self::SCHEMA);
        $globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        // An organization whose schema is missing: skipped
        $initech = Tenant::create(['name' => 'Initech', 'slug' => 'initech']);
        $root = $this->platformAdmin();
        $dev = User::factory()->create(['username' => 'dev']);
        $other = User::factory()->create(['username' => 'other']);
        $this->member($acme, $dev);
        $this->member($globex, $dev);
        $this->member($initech, $dev, withSchema: false);
        $this->member($acme, $other);

        $this->signedIn($root)->deleteJson("/api/users/{$dev->id}")->assertOk();

        $this->assertNull(User::query()->find($dev->id));
        $this->assertFalse(DB::connection('central')->table('tenant_users')->where('user_id', $dev->id)->exists());
        $this->assertSame([false, 0, 0], $this->leftIn($acme, $dev->id));
        $this->assertSame([false, 0, 0], $this->leftIn($globex, $dev->id));
        $this->assertSame([true, 1, 1], $this->leftIn($acme, $other->id), 'the other member stays');
    }

    public function test_an_account_its_user_deletes_leaves_no_copy_either(): void
    {
        $acme = $this->createTenantWithSchema('acme', self::SCHEMA);
        $this->platformAdmin();
        $dev = User::factory()->create(['username' => 'dev', 'password' => 'dev-password']);
        $this->member($acme, $dev);

        $this->signedIn($dev)->deleteJson('/api/account', ['password' => 'dev-password'])->assertOk();

        $this->assertNull(User::query()->find($dev->id));
        $this->assertSame([false, 0, 0], $this->leftIn($acme, $dev->id));
    }

    // ── tokens:prune: leftovers of earlier releases ─────────────────────────

    public function test_tokens_prune_removes_the_tokens_organizations_refuse_for_good(): void
    {
        $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $member = User::factory()->create(['username' => 'member']);
        $former = User::factory()->create(['username' => 'former']);
        $root = $this->platformAdmin();
        $this->member($acme, $member, withSchema: false);
        $keep = [
            $this->tokenIn($member, $acme->id, 'ci-acme'),
            $this->tokenIn($member, null, 'api'),
            $this->tokenIn($former, null, 'api'),
            // a platform administrator's token works in an organization they are no member of
            $this->tokenIn($root, $acme->id, 'root-acme'),
        ];
        // Removed from acme before membership removals took the tokens along; an organization deleted
        // before its deletion did
        $formerInAcme = $this->tokenIn($former, $acme->id, 'ci-acme');
        $this->tokenIn($member, 'deleted-tenant', 'ci-old');
        $this->tokenIn($root, 'deleted-tenant', 'root-old');

        // The same rule as IdentifyTenant's: the former member's token is refused there
        config(['tenancy.enabled' => true]);
        $former->withAccessToken(PersonalAccessToken::query()->findOrFail($formerInAcme));
        $request = Request::create('/api/projects', 'GET');
        $request->setUserResolver(fn () => $former);
        try {
            (new IdentifyTenant)->handle($request, fn () => response('ok'));
            $this->fail('a former member\'s token was accepted in acme');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        config(['tenancy.enabled' => false]);

        $this->artisan('tokens:prune')
            ->expectsOutputToContain('Removed 3 token(s) of former organization members or deleted organizations.')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing($keep, PersonalAccessToken::query()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_tokens_prune_removes_organization_copies_of_deleted_accounts(): void
    {
        $acme = $this->createTenantWithSchema('acme', self::SCHEMA);
        Tenant::create(['name' => 'Initech', 'slug' => 'initech']);   // no schema: skipped
        $kept = User::factory()->create(['username' => 'kept']);
        $gone = User::factory()->create(['username' => 'gone']);
        $this->member($acme, $kept);
        $this->member($acme, $gone);
        // Deleted as earlier releases did: the membership cascades, the copy and role rows stay
        User::query()->whereKey($gone->id)->delete();
        $this->assertSame([true, 1, 1], $this->leftIn($acme, $gone->id));

        $this->artisan('tokens:prune')
            ->expectsOutputToContain('Removed 1 organization copy(ies) of deleted users.')
            ->assertSuccessful();

        $this->assertSame([false, 0, 0], $this->leftIn($acme, $gone->id));
        $this->assertSame([true, 1, 1], $this->leftIn($acme, $kept->id));

        // Again: nothing left to remove
        $this->artisan('tokens:prune')
            ->expectsOutputToContain('Removed 0 organization copy(ies) of deleted users.')
            ->assertSuccessful();
    }
}
