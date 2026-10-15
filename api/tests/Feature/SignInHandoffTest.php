<?php

namespace Tests\Feature;

use App\Models\SignInHandoff;
use App\Models\User;
use App\Services\Auth\SignInHandoffService;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * SSO callbacks hand the console a one-time code, never the API token (docs/security.md):
 * stored as a hash, the token encrypted, exchanged once within 60 seconds.
 */
class SignInHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['username' => 'jane']);
    }

    private function registerExchangeRoute(): void
    {
        // The suite runs with the AuthProvider module off: its public routes as it registers them
        Router::middleware('api')->prefix('api')->group(base_path('Modules/AuthProvider/routes/public.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();
    }

    public function test_only_a_hash_of_the_code_and_the_encrypted_token_are_stored(): void
    {
        $code = app(SignInHandoffService::class)->issue($this->user, '42|plain-api-token');

        $row = DB::table('sign_in_handoffs')->first();
        $this->assertSame(hash('sha256', $code), $row->code_hash);
        $this->assertTrue(SecretCipher::isEncrypted($row->token));
        $this->assertStringNotContainsString('plain-api-token', $row->token);
        $this->assertSame((string) $this->user->id, $row->user_id);
    }

    public function test_a_code_is_exchanged_once(): void
    {
        $service = app(SignInHandoffService::class);
        $code = $service->issue($this->user, '42|plain-api-token');

        $this->assertSame(['token' => '42|plain-api-token', 'user_id' => (string) $this->user->id], $service->redeem($code));
        $this->assertNull($service->redeem($code), 'second exchange');
        $this->assertSame(0, SignInHandoff::count());
    }

    public function test_an_expired_or_unknown_code_gives_nothing(): void
    {
        $service = app(SignInHandoffService::class);
        $code = $service->issue($this->user, 'token');

        $this->travel(SignInHandoffService::TTL_SECONDS + 1)->seconds();
        $this->assertNull($service->redeem($code));

        $this->assertNull($service->redeem('unknown-code'));
        $this->assertNull($service->redeem(''));
        $this->assertNull($service->redeem(str_repeat('x', 500)));
    }

    public function test_issuing_prunes_expired_codes(): void
    {
        $service = app(SignInHandoffService::class);
        $service->issue($this->user, 'old');
        $this->travel(5)->minutes();

        $service->issue($this->user, 'new');

        $this->assertSame(1, SignInHandoff::count());
    }

    public function test_the_exchange_endpoint(): void
    {
        $this->registerExchangeRoute();
        $code = app(SignInHandoffService::class)->issue($this->user, '42|plain-api-token');

        $response = $this->postJson('/api/oauth/exchange', ['code' => $code])
            ->assertOk()
            ->assertExactJson(['token' => '42|plain-api-token', 'user_id' => (string) $this->user->id]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->postJson('/api/oauth/exchange', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/oauth/exchange', [])->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
