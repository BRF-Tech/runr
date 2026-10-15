<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The e2e runs a schedule function once by applying its CronJob's Job template as a Job. Read back
 * from the API server as PHP arrays, the empty objects of a runtime plugin's pod spec (the
 * wrapper's `emptyDir: {}`, the init container's `resources: {}`) are lists, which server-side
 * apply refuses ("expected map, got &{[]}"); they are written as objects again.
 */
class E2eCronJobPodSpecTest extends TestCase
{
    public function test_empty_maps_of_the_job_template_are_objects_again(): void
    {
        $cronJob = json_decode(json_encode(['spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => [
            'securityContext' => new \stdClass,
            'initContainers' => [[
                'name' => 'runr-wrapper',
                'image' => 'ghcr.io/runrsh/runr-wrapper:1.1.0',
                'command' => ['/runr-wrapper', 'install', '/runr/runr-wrapper'],
                'resources' => new \stdClass,
            ]],
            'containers' => [[
                'name' => 'handler',
                'args' => [],
                'resources' => ['limits' => ['memory' => '128Mi'], 'requests' => new \stdClass],
            ]],
            'volumes' => [['name' => 'runr-wrapper', 'emptyDir' => new \stdClass], ['name' => 'tmp', 'emptyDir' => ['medium' => 'Memory']]],
        ]]]]]]), true);

        $json = json_encode(E2eTestCommand::jobPodSpecFromCronJob($cronJob));

        $this->assertStringContainsString('"securityContext":{}', $json);
        $this->assertStringContainsString('"name":"runr-wrapper","emptyDir":{}', $json);
        $this->assertStringContainsString('"emptyDir":{"medium":"Memory"}', $json);
        $this->assertStringContainsString('"command":["\/runr-wrapper","install","\/runr\/runr-wrapper"],"resources":{}', $json);
        $this->assertStringContainsString('"requests":{}', $json);
        // empty lists stay lists
        $this->assertStringContainsString('"args":[]', $json);
    }

    public function test_a_cronjob_without_a_template_gives_an_empty_spec(): void
    {
        $this->assertSame([], E2eTestCommand::jobPodSpecFromCronJob([]));
    }
}
