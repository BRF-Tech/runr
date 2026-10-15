<?php

namespace Tests\Unit;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\InstanceSizes;
use App\Services\Helpers\ResourceReservation;
use App\Services\Manifests\BuildManifests;
use App\Services\Manifests\ContainerManifests;
use App\Services\Manifests\QueueConsumerManifests;
use Modules\Tenancy\Services\TenantQuotaService;
use PHPUnit\Framework\TestCase;

/**
 * (quota model option C): a project's admitted reservation is its instance size ×
 * max(1, min_scale) plus the queue-proxy per Knative Service pod and a queue consumer for queue
 * projects; schedule projects run one CronJob pod without queue-proxy. max_scale is not admitted.
 */
class ResourceReservationTest extends TestCase
{
    private const QP_CPU = KnativeConstants::QUEUE_PROXY_CPU_M;

    private const QP_MEM = KnativeConstants::QUEUE_PROXY_MEMORY_MB;

    public function test_queue_proxy_is_100m(): void
    {
        $this->assertSame(100, KnativeConstants::QUEUE_PROXY_CPU_M);
        $this->assertSame(100, KnativeConstants::QUEUE_PROXY_MEMORY_MB);
    }

    public function test_admission_counts_max_one_min_scale_instances(): void
    {
        // min_scale 0 (scale-to-zero) still admits one instance
        $this->assertSame(
            ['cpu_m' => 250 + self::QP_CPU, 'memory_mb' => 256 + self::QP_MEM],
            TenantQuotaService::projectReservation(250, 256, 'http', 0),
        );
        $this->assertSame(
            ['cpu_m' => 250 + self::QP_CPU, 'memory_mb' => 256 + self::QP_MEM],
            TenantQuotaService::projectReservation(250, 256, 'http', 1),
        );
        // min_scale 3: three warm instances, each with its own queue-proxy
        $this->assertSame(
            ['cpu_m' => 3 * (250 + self::QP_CPU), 'memory_mb' => 3 * (256 + self::QP_MEM)],
            TenantQuotaService::projectReservation(250, 256, 'http', 3),
        );
    }

    public function test_max_scale_is_not_part_of_admission(): void
    {
        $withMax = ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256, 'min_scale' => 0, 'max_scale' => 50], 'xaas');

        $this->assertSame(ResourceReservation::forSize(250, 256, 'http', 0), $withMax);
    }

    public function test_schedule_projects_have_no_queue_proxy_and_one_pod(): void
    {
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 256], TenantQuotaService::podReservation(250, 256, 'schedule'));
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 256], TenantQuotaService::projectReservation(250, 256, 'schedule', 3));
    }

    public function test_queue_projects_add_one_queue_consumer(): void
    {
        $this->assertSame(
            [
                'cpu_m' => 2 * (250 + self::QP_CPU) + KnativeConstants::QUEUE_CONSUMER_CPU_M,
                'memory_mb' => 2 * (256 + self::QP_MEM) + KnativeConstants::QUEUE_CONSUMER_MEMORY_MB,
            ],
            TenantQuotaService::projectReservation(250, 256, 'queue', 2),
        );
    }

    public function test_queue_consumer_manifest_uses_the_counted_constants(): void
    {
        $size = [
            'cpu' => KnativeConstants::QUEUE_CONSUMER_CPU_M.'m',
            'memory' => KnativeConstants::QUEUE_CONSUMER_MEMORY_MB.'Mi',
        ];

        $this->assertSame(['requests' => $size, 'limits' => $size], QueueConsumerManifests::resources());
    }

    public function test_a_config_without_size_uses_the_type_default(): void
    {
        $micro = InstanceSizes::PRESETS['micro'];
        $small = InstanceSizes::PRESETS['small'];

        $this->assertSame(
            ResourceReservation::forSize(250, 256),
            ResourceReservation::forConfig(['trigger_type' => 'http'], 'xaas'),
        );
        $this->assertSame(
            ResourceReservation::forSize(500, 512),
            ResourceReservation::forConfig((object) ['cpu' => null, 'memory_mb' => null], 'caas'),
        );

        // The manifests fall back to the same sizes
        $this->assertSame($micro['cpu'], ContainerManifests::resources((object) [])['limits']['cpu']);
        $this->assertSame($micro['memory_mb'].'Mi', ContainerManifests::resources((object) [])['limits']['memory']);
        $this->assertSame($small['cpu'], ContainerManifests::resources((object) [], 'caas')['requests']['cpu']);
        $this->assertSame($small['memory_mb'].'Mi', ContainerManifests::resources((object) [], 'caas')['requests']['memory']);
    }

    public function test_reference_sizes_and_defaults(): void
    {
        $this->assertSame(['nano', 'micro', 'small', 'medium', 'large', 'xlarge'], array_keys(InstanceSizes::PRESETS));
        $this->assertSame(['cpu' => '250m', 'memory_mb' => 256], InstanceSizes::defaultFor('xaas'));
        $this->assertSame(['cpu' => '250m', 'memory_mb' => 256], InstanceSizes::defaultFor('faas'));
        $this->assertSame(['cpu' => '500m', 'memory_mb' => 512], InstanceSizes::defaultFor('caas'));
    }

    public function test_cpu_quantities_parse_to_millicores(): void
    {
        $this->assertSame(250, ResourceReservation::parseCpu('250m'));
        $this->assertSame(500, ResourceReservation::parseCpu('0.5'));
        $this->assertSame(2000, ResourceReservation::parseCpu('2'));
        $this->assertSame(0, ResourceReservation::parseCpu(null));
    }

    public function test_build_containers_set_request_equal_to_limit(): void
    {
        $kaniko = BuildManifests::kanikoResources();
        $init = BuildManifests::initResources();

        $this->assertSame($kaniko['requests'], $kaniko['limits']);
        $this->assertSame($init['requests'], $init['limits']);
        $this->assertSame(['cpu' => '1000m', 'memory' => '2048Mi'], $kaniko['limits']);
        $this->assertSame(['cpu' => '50m', 'memory' => '64Mi'], $init['limits']);
    }
}
