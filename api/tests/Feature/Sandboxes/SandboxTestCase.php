<?php

namespace Tests\Feature\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\FakesSandboxCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Base of the Sandbox API feature tests: single-tenant install (the suite's default), the
 * built-in runtime versions, the fake cluster + exec transport, one region with gVisor and Kata
 * and a signed-in user.
 */
abstract class SandboxTestCase extends TestCase
{
    use FakesSandboxCluster, RefreshDatabase, RegistersRuntimes;

    protected KnativeRegion $region;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBuiltinVersions();
        $this->fakeSandboxCluster();
        $this->region = $this->sandboxRegion();
        $this->user = $this->sandboxUser();
        $this->actingAs($this->user, 'sanctum');
    }

    /** POST /api/sandboxes and return the sandbox (asserting 201). */
    protected function createSandbox(array $input = []): array
    {
        return $this->postJson('/api/sandboxes', $input + ['region' => $this->region->id])
            ->assertCreated()
            ->json('data');
    }

    /** A running sandbox row without going through the API. */
    protected function runningSandbox(array $attributes = []): Sandbox
    {
        $id = (string) Str::ulid();
        $row = Sandbox::create($attributes + [
            'id' => $id,
            'tenant_id' => null,
            'created_by' => (string) $this->user->id,
            'knative_region_id' => $this->region->id,
            'namespace' => 'runr-sbx-default',
            'pod_name' => 'sbx-'.strtolower($id),
            'status' => Sandbox::STATUS_RUNNING,
            'runtime' => 'python',
            'runtime_version' => '3.14',
            'image' => 'ghcr.io/brf-tech/runr/exec-python:3.14',
            'sandbox_class' => 'kata-fc',
            'cpu_m' => 500,
            'memory_mb' => 512,
            'disk_mb' => 1024,
            'pids_limit' => 256,
            'uid' => 100500,
            'read_only_root' => true,
            'network' => ['mode' => 'none', 'egress_allow' => [], 'rules' => []],
            'ttl_seconds' => 900,
            'idle_timeout_seconds' => 600,
            'expires_at' => now()->addMinutes(15),
            'last_activity_at' => now(),
            'started_at' => now(),
        ]);

        // Its pod, running
        $this->k8sPods[$row->namespace.'/'.$row->pod_name] = [
            'metadata' => ['name' => $row->pod_name, 'namespace' => $row->namespace, 'labels' => ['runr.dev/sandbox' => 'true', 'runr.dev/sandbox-id' => strtolower($row->id)], 'creationTimestamp' => now()->subMinutes(5)->toIso8601String()],
            'status' => ['phase' => 'Running', 'containerStatuses' => [['name' => 'sandbox', 'state' => ['running' => []]]]],
        ];

        return $row;
    }
}
