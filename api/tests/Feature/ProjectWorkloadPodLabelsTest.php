<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Version;
use App\Services\FunctionBuildService;
use App\Services\KnativeNodeService;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\QueueConsumerManifests;
use Tests\TestCase;

/**
 * Region capacity subtracts the requests of every pod that is not a RunR project workload
 * (KnativeNodeService). Each kind of project pod must therefore carry runr/project-id on the
 * POD — Job / Deployment / CronJob labels do not reach the pods by themselves.
 */
class ProjectWorkloadPodLabelsTest extends TestCase
{
    private const PROJECT = '01J9ZPROJECT00000000000000';

    private function project(): Project
    {
        $project = new Project;
        $project->forceFill(['id' => self::PROJECT, 'name' => 'orders', 'slug' => 'orders', 'service_type' => 'faas']);

        return $project;
    }

    private function assertProjectPod(array $podTemplate): void
    {
        $this->assertSame(self::PROJECT, $podTemplate['metadata']['labels']['runr/project-id'] ?? null);
        $this->assertTrue(KnativeNodeService::isProjectWorkload(['metadata' => $podTemplate['metadata']]));
    }

    private function callPrivate(object $service, string $method, array $args): array
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }

    public function test_build_job_pods_carry_the_project_label(): void
    {
        $faas = $this->callPrivate(app(FunctionBuildService::class), 'kanikoJob', [
            'build-orders-v2', 'fn', 'build-orders-v2', 'registry:5000/runr-builds/orders:v2', null, $this->project(), ['main.py'], false,
        ]);
        $this->assertProjectPod($faas['spec']['template']);
        $this->assertSame('true', $faas['spec']['template']['metadata']['labels']['runr/build']);
        $this->assertSame($faas['metadata']['labels'], $faas['spec']['template']['metadata']['labels']);

        // A runtime that runs through the universal wrapper gets it in the build context too
        $wrapped = $this->callPrivate(app(FunctionBuildService::class), 'kanikoJob', [
            'build-orders-v2', 'fn', 'build-orders-v2', 'registry:5000/runr-builds/orders:v2', null, $this->project(), ['main.c'], true,
        ]);
        $this->assertProjectPod($wrapped['spec']['template']);
        $this->assertSame(['copy-src', 'runr-wrapper'], array_column($wrapped['spec']['template']['spec']['initContainers'], 'name'));
    }

    public function test_queue_consumer_and_cron_pods_carry_the_project_label(): void
    {
        $consumer = QueueConsumerManifests::deployment($this->project(), 'fn', 'orders-consumer', 'consumer:1', []);
        $this->assertProjectPod($consumer['spec']['template']);

        $version = (new Version)->forceFill(['number' => 3]);
        $cron = KnativeManifests::cronJob('orders-cron', 'fn', '*/5 * * * *', [], [], ['containers' => []], $this->project(), $version);
        $this->assertProjectPod($cron['spec']['jobTemplate']['spec']['template']);
    }

    /** The consumer runs where the region's project workloads run: on its dedicated workers. */
    public function test_the_queue_consumer_runs_on_the_dedicated_workers(): void
    {
        $shared = QueueConsumerManifests::deployment($this->project(), 'fn', 'orders-consumer', 'consumer:1', []);
        $this->assertArrayNotHasKey('nodeSelector', $shared['spec']['template']['spec']);
        $this->assertArrayNotHasKey('tolerations', $shared['spec']['template']['spec']);

        $dedicated = QueueConsumerManifests::deployment($this->project(), 'fn', 'orders-consumer', 'consumer:1', [], true);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $dedicated['spec']['template']['spec']['nodeSelector']);
        $this->assertSame([[
            'key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule',
        ]], $dedicated['spec']['template']['spec']['tolerations']);
    }
}
