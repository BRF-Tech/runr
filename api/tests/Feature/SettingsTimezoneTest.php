<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\OrgTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Support\TimeZones;
use Tests\TestCase;

/**
 * The organization's time zone (setting `timezone`, OrgTimezone): the timed warmers' crons run in
 * it. Europe/Istanbul until the organization sets its own; settings.manage changes it.
 */
class SettingsTimezoneTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
    }

    public function test_the_default_zone_is_istanbul(): void
    {
        $this->signedIn($this->owner)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Istanbul');

        $this->assertSame(OrgTimezone::DEFAULT, OrgTimezone::get());
    }

    public function test_an_owner_sets_and_resets_the_zone(): void
    {
        $this->signedIn($this->owner)->patchJson('/api/settings', ['timezone' => 'Europe/Berlin'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Berlin');
        $this->assertSame('Europe/Berlin', Setting::get('timezone'));
        $this->assertSame('Europe/Berlin', OrgTimezone::get());

        $this->signedIn($this->owner)->patchJson('/api/settings', ['timezone' => null])
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Istanbul');
        $this->assertNull(Setting::query()->find('timezone'));
    }

    public function test_an_unknown_zone_is_refused(): void
    {
        foreach (['Mars/Olympus', 'UTC+3', 'europe/berlin'] as $zone) {
            $this->signedIn($this->owner)->patchJson('/api/settings', ['timezone' => $zone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['timezone']);
        }
        $this->assertNull(Setting::query()->find('timezone'));
    }

    public function test_only_settings_managers_change_it(): void
    {
        $this->signedIn($this->userWithRole('developer'))->patchJson('/api/settings', ['timezone' => 'UTC'])
            ->assertForbidden();
        $this->assertNull(Setting::query()->find('timezone'));
    }

    public function test_a_stored_zone_php_does_not_know_falls_back_to_the_default(): void
    {
        Setting::set('timezone', 'Nowhere/Land');

        $this->assertSame('Europe/Istanbul', OrgTimezone::get());
        $this->assertTrue(OrgTimezone::isValid('UTC'));
        // backward-compatible names too, as far as this PHP knows them (tests/Support/TimeZones)
        if ($backward = TimeZones::backward()) {
            $this->assertTrue(OrgTimezone::isValid($backward), "backward-compatible {$backward}");
        }
        $this->assertSame(TimeZones::knows('Europe/Kiev'), OrgTimezone::isValid('Europe/Kiev'), 'PHP\'s zone list decides');
        $this->assertFalse(OrgTimezone::isValid(''));
        $this->assertFalse(OrgTimezone::isValid(null));
    }
}
