<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The console applies a settings.updated broadcast with settingsStore.update(payload.settings)
 * (vue/src/composables/usePlatformSync.js, useRealtimeNotifications.js): the payload carries a
 * `settings` object, with the values the store would otherwise reset.
 */
class SettingsUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_broadcast_carries_a_settings_object(): void
    {
        $owner = User::factory()->create(['username' => 'owner']); // first user: Owner
        Event::fake([PlatformEvent::class]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/settings', ['registration_enabled' => false, 'gpu_demo_enabled' => true])
            ->assertOk();

        Event::assertDispatched(PlatformEvent::class, function (PlatformEvent $event) {
            if ($event->topic !== 'settings.updated') {
                return false;
            }

            $settings = $event->broadcastWith()['payload']['settings'] ?? null;
            $this->assertIsArray($settings, 'payload.settings');
            $this->assertFalse($settings['registration_enabled']);
            $this->assertTrue($settings['gpu_demo_enabled']);
            foreach (['base_domain', 'custom_runtimes_enabled', 'total_cpu_m', 'total_memory_mb'] as $key) {
                $this->assertArrayHasKey($key, $settings);
            }
            // Region details stay out of the broadcast (the store keeps its own region list)
            $this->assertArrayNotHasKey('regions', $settings);
            $this->assertArrayNotHasKey('executor_images', $settings);
            // The old flat payload is gone
            $this->assertArrayNotHasKey('registration_enabled', $event->payload);

            return true;
        });
    }
}
