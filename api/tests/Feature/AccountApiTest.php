<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Self-service account (/api/account…): profile, avatar, password, two-factor authentication,
 * signed-in sessions (API tokens), disabling and deleting the account, and the notification
 * preferences — always the signed-in user's own.
 */
class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    /** A 1×1 PNG (no GD needed to build it). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.test', 'password' => 'correct horse']);
    }

    /** Requests with a real Sanctum token of the user (sessions are tokens). */
    private function as(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }

    private function token(string $name = 'web'): string
    {
        return $this->user->createToken($name)->plainTextToken;
    }

    public function test_the_profile_is_shown_and_updated(): void
    {
        User::factory()->create(['username' => 'grace', 'email' => 'grace@example.test']);
        $this->as($this->token());

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('username', 'ada')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('two_factor_secret');

        $this->patchJson('/api/account/profile', ['name' => 'Ada Lovelace', 'bio' => 'Analyst', 'phone' => '+44 1'])
            ->assertOk()
            ->assertJsonPath('message', __('api.account.profile_updated'))
            ->assertJsonPath('user.name', 'Ada Lovelace')
            ->assertJsonPath('user.bio', 'Analyst');

        // another user's username / email, an invalid username
        $this->patchJson('/api/account/profile', ['username' => 'grace', 'email' => 'grace@example.test'])
            ->assertStatus(422)->assertJsonValidationErrors(['username', 'email']);
        $this->patchJson('/api/account/profile', ['username' => 'ada lovelace'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        // keeping one's own is fine
        $this->patchJson('/api/account/profile', ['username' => 'ada', 'email' => 'ada@example.test'])->assertOk();
    }

    public function test_an_avatar_is_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $token = $this->token();
        $this->as($token);
        $png = fn (string $name) => UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));

        $this->post('/api/account/avatar', ['avatar' => $png('me.png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('avatar_url', Storage::disk('public')->url("avatars/{$this->user->id}.png"));
        Storage::disk('public')->assertExists("avatars/{$this->user->id}.png");

        // a new one replaces the file of the old one
        $this->user->update(['avatar_path' => "avatars/{$this->user->id}.webp"]);
        Storage::disk('public')->put("avatars/{$this->user->id}.webp", 'old');
        $this->as($token)->post('/api/account/avatar', ['avatar' => $png('me.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing("avatars/{$this->user->id}.webp");

        $this->post('/api/account/avatar', ['avatar' => UploadedFile::fake()->createWithContent('me.txt', 'not an image')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->deleteJson('/api/account/avatar')->assertOk()->assertJsonPath('message', __('api.account.avatar_removed'));
        Storage::disk('public')->assertMissing("avatars/{$this->user->id}.png");
        $this->assertNull($this->user->fresh()->avatar_path);
    }

    public function test_the_password_is_changed_with_the_current_one(): void
    {
        $this->as($this->token());

        $this->postJson('/api/account/password', ['current_password' => 'wrong', 'password' => 'battery staple', 'password_confirmation' => 'battery staple'])
            ->assertStatus(422)->assertJsonPath('message', __('api.account.wrong_password'));
        $this->postJson('/api/account/password', ['current_password' => 'correct horse', 'password' => 'battery staple', 'password_confirmation' => 'other'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/account/password', ['current_password' => 'correct horse', 'password' => 'correct horse', 'password_confirmation' => 'correct horse'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/account/password', ['current_password' => 'correct horse', 'password' => 'battery staple', 'password_confirmation' => 'battery staple'])
            ->assertOk()->assertJsonPath('message', __('api.account.password_changed'));
        $this->assertTrue(Hash::check('battery staple', $this->user->fresh()->password));
    }

    public function test_two_factor_authentication_is_set_up_confirmed_and_disabled(): void
    {
        $this->as($this->token());

        $setup = $this->postJson('/api/account/2fa/setup')->assertOk();
        $secret = $setup->json('secret');
        $this->assertStringContainsString('otpauth://totp/', urldecode($setup->json('qr_url')));
        $this->assertSame($secret, decrypt($this->user->fresh()->two_factor_secret));
        $this->assertNull($this->user->fresh()->two_factor_confirmed_at, 'not active before a code is confirmed');

        $this->postJson('/api/account/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422)->assertJsonPath('message', __('api.account.invalid_2fa_code'));
        $this->postJson('/api/account/2fa/confirm', ['code' => '12'])->assertStatus(422)->assertJsonValidationErrors('code');

        $confirmed = $this->postJson('/api/account/2fa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonPath('message', __('api.account.2fa_enabled'));
        $codes = $confirmed->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotNull($this->user->fresh()->two_factor_confirmed_at);
        $this->assertSame($codes, json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true));

        // new recovery codes replace the old ones
        $renewed = $this->postJson('/api/account/2fa/recovery-codes')->assertOk()->json('recovery_codes');
        $this->assertCount(8, $renewed);
        $this->assertNotSame($codes, $renewed);
        $this->assertSame($renewed, json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true));

        $this->deleteJson('/api/account/2fa', ['password' => 'wrong'])->assertStatus(422)->assertJsonPath('message', __('api.account.wrong_password'));
        $this->deleteJson('/api/account/2fa', ['password' => 'correct horse'])->assertOk()->assertJsonPath('message', __('api.account.2fa_disabled'));
        $fresh = $this->user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_sessions_are_listed_and_revoked_but_never_the_current_one(): void
    {
        $current = $this->token('laptop');
        $this->token('phone');
        $this->token('tablet');
        $other = User::factory()->create()->createToken('theirs');
        $currentId = PersonalAccessToken::findToken($current)->id;

        $sessions = $this->as($current)->getJson('/api/account/sessions')->assertOk()->json('sessions');
        $this->assertEqualsCanonicalizing(['laptop', 'phone', 'tablet'], array_column($sessions, 'name'), 'only the user\'s own');
        $this->assertSame(['laptop'], array_column(array_filter($sessions, fn ($s) => $s['is_current']), 'name'));

        $this->deleteJson("/api/account/sessions/{$currentId}")
            ->assertStatus(422)->assertJsonPath('message', __('api.account.cannot_revoke_current'));

        Event::fake([PlatformEvent::class]);
        $phone = collect($sessions)->firstWhere('name', 'phone')['id'];
        $this->deleteJson("/api/account/sessions/{$phone}")->assertOk()->assertJsonPath('message', __('api.account.session_revoked'));
        $this->assertNull(PersonalAccessToken::find($phone));
        Event::assertDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'session.revoked' && $e->payload['token_ids'] === [$phone]);

        // another user's session is not the user's to revoke — and no event signs its browser out
        $this->deleteJson("/api/account/sessions/{$other->accessToken->id}")->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($other->accessToken->id));
        Event::assertNotDispatched(PlatformEvent::class, fn (PlatformEvent $e) => in_array($other->accessToken->id, $e->payload['token_ids'] ?? [], true));

        $this->postJson('/api/account/sessions/revoke-all')->assertOk()->assertJsonPath('message', __('api.account.all_sessions_revoked'));
        $this->assertSame([$currentId], $this->user->tokens()->pluck('id')->all());
        $this->assertNotNull(PersonalAccessToken::find($other->accessToken->id));
    }

    public function test_a_disabled_account_keeps_no_other_session_and_is_refused(): void
    {
        $current = $this->token('laptop');
        $this->token('phone');

        $this->as($current)->postJson('/api/account/disable')->assertOk()->assertJsonPath('message', __('api.account.disabled'));

        $fresh = $this->user->fresh();
        $this->assertTrue($fresh->is_disabled);
        $this->assertNotNull($fresh->disabled_at);
        $this->assertSame(['laptop'], $fresh->tokens()->pluck('name')->all());

        $this->as($current)->getJson('/api/account')
            ->assertForbidden()->assertJsonPath('message', __('api.account.account_disabled'));
    }

    public function test_the_account_is_deleted_with_the_password(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put("avatars/{$this->user->id}.png", 'png');
        $this->user->update(['avatar_path' => "avatars/{$this->user->id}.png"]);
        $current = $this->token();
        $this->token('phone');

        $this->as($current)->deleteJson('/api/account', ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('message', __('api.account.wrong_password'));
        $this->assertNotNull($this->user->fresh());

        $this->deleteJson('/api/account', ['password' => 'correct horse'])->assertOk()->assertJsonPath('message', __('api.account.deleted'));
        $this->assertNull(User::find($this->user->id));
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->user->id)->count());
        Storage::disk('public')->assertMissing("avatars/{$this->user->id}.png");
    }

    public function test_notification_preferences_default_to_on_and_are_saved(): void
    {
        $this->as($this->token());

        $defaults = $this->getJson('/api/account/notification-preferences')->assertOk()->json('preferences');
        $this->assertCount(12, $defaults, 'six types (billing too) × in-app and email');
        $this->assertSame([true], array_values(array_unique(array_column($defaults, 'enabled'))));

        $this->putJson('/api/account/notification-preferences', ['preferences' => [
            ['type' => 'build', 'channel' => 'email', 'enabled' => false],
            ['type' => 'demo', 'channel' => 'in_app', 'enabled' => false],
        ]])->assertOk()->assertJsonPath('message', __('api.account.preferences_updated'));
        // saved again: updated, not duplicated
        $this->putJson('/api/account/notification-preferences', ['preferences' => [['type' => 'demo', 'channel' => 'in_app', 'enabled' => true]]])->assertOk();

        $this->assertSame(2, NotificationPreference::where('user_id', $this->user->id)->count());
        $saved = collect($this->getJson('/api/account/notification-preferences')->json('preferences'));
        $this->assertFalse($saved->first(fn ($p) => $p['type'] === 'build' && $p['channel'] === 'email')['enabled']);
        $this->assertTrue($saved->first(fn ($p) => $p['type'] === 'demo' && $p['channel'] === 'in_app')['enabled']);
        $this->assertTrue($saved->first(fn ($p) => $p['type'] === 'build' && $p['channel'] === 'in_app')['enabled']);

        $this->putJson('/api/account/notification-preferences', ['preferences' => [['type' => 'unknown', 'channel' => 'sms', 'enabled' => 'yes']]])
            ->assertStatus(422)->assertJsonValidationErrors(['preferences.0.type', 'preferences.0.channel', 'preferences.0.enabled']);
    }
}
