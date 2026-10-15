<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * Broadcasts are best effort: a request whose change is saved answers with its result even when
 * the WebSocket server (Reverb) is unreachable — the failure is reported, the console reloads the
 * resource through the API anyway. Before, every write that broadcast synchronously answered 500
 * ("Pusher error: … Could not resolve host: reverb") although it had been saved.
 */
class BroadcastFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_change_answers_its_result_when_the_websocket_server_is_unreachable(): void
    {
        // The Reverb broadcaster of an install, pointed at a port nothing listens on
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
        ]);
        Broadcast::forgetDrivers();
        Exceptions::fake();

        $owner = User::factory()->create(['username' => 'owner']); // first user: Owner

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/settings', ['registration_enabled' => false])
            ->assertOk()
            ->assertJsonPath('registration_enabled', false);

        $this->assertSame('false', Setting::get('registration_enabled'));
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_every_synchronous_broadcast_is_rescued(): void
    {
        $files = array_merge(glob(app_path('Events/*.php')), glob(base_path('Modules/*/app/Events/*.php')));
        $synchronous = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns) || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cls)) {
                continue;
            }
            $class = $ns[1].'\\'.$cls[1];
            if (is_subclass_of($class, ShouldBroadcastNow::class)) {
                $synchronous[] = $class;
                $this->assertTrue(is_subclass_of($class, ShouldRescue::class), "{$class} broadcasts in the request: it must implement ShouldRescue");
            }
        }

        $this->assertNotEmpty($synchronous, 'no ShouldBroadcastNow event found: the scan is broken');
    }
}
