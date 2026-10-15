<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Tenant invitation tokens are stored as their SHA-256 hash and compared in constant time; the
 * migration hashes the tokens of invitations sent before (docs/security.md).
 */
class TenantInvitationTokenTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // The suite runs with the Tenancy module off: its public routes the way it registers them
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function invitation(string $storedToken, array $attributes = []): TenantInvitation
    {
        return TenantInvitation::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'email' => Str::random(8).'@example.test',
            'role' => 'developer',
            'token' => $storedToken,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function test_only_the_hash_is_stored_and_the_plain_token_finds_the_invitation(): void
    {
        [$plain, $hash] = TenantInvitation::newToken();
        $invitation = $this->invitation($hash);

        $this->assertSame(64, strlen($plain));
        $this->assertSame(hash('sha256', $plain), $hash);
        $this->assertSame($hash, DB::connection('central')->table('tenant_invitations')->where('id', $invitation->id)->value('token'));

        $this->assertTrue(TenantInvitation::findByPlainToken($plain)?->is($invitation));
        $this->assertNull(TenantInvitation::findByPlainToken($hash), 'the stored hash is not a token');
        $this->assertNull(TenantInvitation::findByPlainToken('nope'));
        $this->assertArrayNotHasKey('token', $invitation->toArray());

        $invitation->forceFill(['accepted_at' => now()])->save();
        $this->assertNull(TenantInvitation::findByPlainToken($plain), 'an accepted invitation is used up');
    }

    public function test_the_public_preview_takes_the_plain_token_only(): void
    {
        [$plain, $hash] = TenantInvitation::newToken();
        $this->invitation($hash, ['email' => 'new@example.test']);

        $this->getJson("/api/invitations/{$plain}")
            ->assertOk()
            ->assertJsonPath('email', 'new@example.test')
            ->assertJsonPath('tenant', 'Acme');

        $this->getJson("/api/invitations/{$hash}")->assertNotFound();
        $this->postJson("/api/invitations/{$hash}/accept", ['name' => 'X', 'password' => 'secret-password'])->assertNotFound();
    }

    public function test_the_migration_hashes_the_tokens_of_open_invitations_once(): void
    {
        $legacyPlain = Str::random(64);
        $legacy = $this->invitation($legacyPlain, ['email' => 'legacy@example.test']);
        [, $hash] = TenantInvitation::newToken();
        $current = $this->invitation($hash, ['email' => 'current@example.test']);

        $migration = require base_path('Modules/Tenancy/Database/Migrations/2026_10_10_200001_hash_tenant_invitation_tokens.php');
        $migration->up();

        $stored = fn (TenantInvitation $i) => DB::connection('central')->table('tenant_invitations')->where('id', $i->id)->value('token');
        $this->assertSame(hash('sha256', $legacyPlain), $stored($legacy));
        $this->assertSame($hash, $stored($current), 'a hashed token is left alone');

        // The link already sent keeps working
        $this->assertTrue(TenantInvitation::findByPlainToken($legacyPlain)?->is($legacy));

        $migration->up();
        $this->assertSame(hash('sha256', $legacyPlain), $stored($legacy), 'running again changes nothing');
    }
}
