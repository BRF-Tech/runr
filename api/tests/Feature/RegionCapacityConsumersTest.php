<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Rules\WithinResourceLimit;
use App\Services\KnativeNodeService;
use App\Services\ResourceThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The consumers of the region capacity measure against what the nodes leave for projects, not
 * against node allocatable: the measured single-node k3s (7250m allocatable, 2715m requested by
 * the platform) admits 4535m.
 */
class RegionCapacityConsumersTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://k3s.test:6443';

    private function region(): KnativeRegion
    {
        $node = [
            'metadata' => ['name' => 'runr-test', 'labels' => ['node-role.kubernetes.io/control-plane' => 'true']],
            'spec' => [],
            'status' => ['allocatable' => ['cpu' => '7250m', 'memory' => '14491804Ki'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ];
        $pod = fn (string $cpu, string $memory, array $labels = []) => [
            'metadata' => ['name' => 'p'.$cpu, 'labels' => $labels],
            'spec' => ['nodeName' => 'runr-test', 'containers' => [['name' => 'c', 'resources' => ['requests' => ['cpu' => $cpu, 'memory' => $memory]]]]],
            'status' => ['phase' => 'Running'],
        ];
        $pods = [
            $pod('1715m', '2Gi'),
            $pod('1000m', '1Gi'),
            $pod('2000m', '2Gi', ['runr/project-id' => '01J9ZOTHERPROJECT000000000']),
        ];

        Http::fake(fn (Request $request) => match ((string) parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/nodes' => Http::response(['items' => [$node]]),
            '/api/v1/pods' => Http::response(['items' => $pods]),
            default => Http::response(['kind' => 'Status', 'code' => 404], 404),
        });

        $region = KnativeRegion::create([
            'name' => 'op',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ]);
        $region->forceFill(['selected_workers' => null, 'total_cpu_m' => 0, 'total_memory_mb' => 0])->save();

        $totals = app(KnativeNodeService::class)->refreshSchedulableCapacity($region);
        $this->assertSame(7250 - 2715, $totals['total_cpu_m']);

        return $region->fresh();
    }

    public function test_admission_measures_against_what_the_platform_leaves(): void
    {
        $region = $this->region();
        // an always-on project (min_scale 1): the always-on budget is the capacity itself
        $rule = fn () => ['cpu' => [new WithinResourceLimit('cpu', null, $region->id, 'http', 1)]];

        // 4400m + 100m queue-proxy = 4500m ≤ 4535m
        $this->assertTrue(Validator::make(['cpu' => '4400m'], $rule())->passes());

        // 4500m + 100m = 4600m: fits node allocatable, but not next to the platform pods
        $validator = Validator::make(['cpu' => '4500m'], $rule());
        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('4535m', $validator->errors()->first('cpu'));
    }

    public function test_thresholds_measure_against_what_the_platform_leaves(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);
        $region = $this->region();

        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '2900m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'min_scale' => 1,
        ]);

        app(ResourceThresholdService::class)->checkRegion($region);

        // 3000m always-on: 66 % of 4535m (41 % of the 7250m allocatable would stay silent)
        $notification = Notification::where('type', 'resource_threshold')->first();
        $this->assertNotNull($notification);
        $this->assertSame('warning', $notification->level);
        $this->assertSame('cpu', $notification->data['resource_type']);
        $this->assertEquals(66.2, $notification->data['percentage']);
    }
}
