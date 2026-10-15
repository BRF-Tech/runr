<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Models\KnativeRegion;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\ZotRegistryService;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * The region setup wizard matches registry.progress / region.created / region.create_failed to
 * the setup it started by `setup_id` (answered by POST /registry-setup next to setup_token).
 * region.created once carried no id at all, so the wizard took any region created during its
 * setup as its own; the setup token itself is masked on the platform channel (SecretRedactor).
 */
class RegistrySetupEventsTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n  name: c\ncontexts:\n- context:\n    cluster: c\n    user: u\n  name: c\ncurrent-context: c\nusers:\n- name: u\n  user:\n    token: t\n";

    private function config(): array
    {
        return [
            'name' => 'eu-1',
            'kubeconfig' => self::KUBECONFIG,
            'registry_mode' => 'external',
            'external_address' => 'registry.example.com',
            'external_username' => 'ci',
            'external_password' => 'pw',
        ];
    }

    /** @return array<string, list<array<string, mixed>>> topic => broadcast payloads (after redaction) */
    private function broadcasts(): array
    {
        $out = [];
        Event::dispatched(PlatformEvent::class)->each(function (array $args) use (&$out) {
            $event = $args[0];
            $out[$event->topic][] = $event->broadcastWith()['payload'];
        });

        return $out;
    }

    private function runJob(string $token, ZotRegistryService $zot): void
    {
        $status = Mockery::mock(KnativeStatusService::class);
        $status->shouldReceive('createProjectNamespace')->andReturnNull();
        $redis = Mockery::mock(KnativeRedisService::class);
        $redis->shouldReceive('queueInitialInstall')->andReturnNull();
        $this->app->instance(KnativeRedisService::class, $redis);

        (new InstallRegistryAndCreateRegionJob($token, $this->config()))
            ->handle($zot, $status, Mockery::mock(KnativeNodeService::class));
    }

    public function test_the_setup_id_is_public_and_derived_from_the_token(): void
    {
        $id = InstallRegistryAndCreateRegionJob::setupId('3f1c0a52-7d1e-4f6b-9c1a-2b8e5d7f9a10');

        $this->assertSame($id, InstallRegistryAndCreateRegionJob::setupId('3f1c0a52-7d1e-4f6b-9c1a-2b8e5d7f9a10'));
        $this->assertNotSame($id, InstallRegistryAndCreateRegionJob::setupId('another-token'));
        $this->assertStringNotContainsString('3f1c0a52', $id);
        $this->assertFalse(SecretRedactor::isSecretKey('setup_id'), 'setup_id is not masked on the platform channel');
    }

    public function test_region_created_carries_the_setup_id(): void
    {
        Event::fake([PlatformEvent::class]);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('testExternalRegistry')->andReturn(['success' => true, 'message' => 'ok']);

        $this->runJob('setup-token-1', $zot);

        $region = KnativeRegion::where('name', 'eu-1')->firstOrFail();
        $events = $this->broadcasts();
        $setupId = InstallRegistryAndCreateRegionJob::setupId('setup-token-1');

        $this->assertCount(1, $events['region.created'] ?? []);
        $this->assertSame($setupId, $events['region.created'][0]['setup_id']);
        $this->assertSame($region->id, $events['region.created'][0]['region_id']);

        foreach ($events['registry.progress'] ?? [] as $progress) {
            $this->assertSame($setupId, $progress['setup_id']);
            $this->assertArrayNotHasKey('token', $progress, 'the setup token stays off the platform channel');
        }
        $this->assertNotEmpty($events['registry.progress'] ?? [], 'the registry test phase is broadcast');
    }

    public function test_a_failed_setup_carries_the_setup_id(): void
    {
        Event::fake([PlatformEvent::class]);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('testExternalRegistry')->andThrow(new \RuntimeException('registry unreachable'));

        $this->runJob('setup-token-2', $zot);

        $failed = $this->broadcasts()['region.create_failed'] ?? [];
        $this->assertCount(1, $failed);
        $this->assertSame(InstallRegistryAndCreateRegionJob::setupId('setup-token-2'), $failed[0]['setup_id']);
        $this->assertArrayNotHasKey('token', $failed[0]);
        $this->assertSame(0, KnativeRegion::where('name', 'eu-1')->count());
    }
}
