<?php

namespace Tests\Unit\Environments;

use App\Models\Binding;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Bindings\BindingManifests;
use App\Services\Environments\EnvironmentConfig;
use App\Services\Environments\EnvironmentManifests;
use App\Services\Environments\EnvironmentProject;
use App\Services\KnativeNodeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pieces of environments that need no cluster: names, env layering, the "what will change"
 * diff (names only), the labels of an environment's workloads and the binding NetworkPolicy that
 * admits them.
 */
class EnvironmentUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_are_dns_labels_and_some_are_reserved(): void
    {
        foreach (['dev', 'staging', 'qa-eu', 'e2'] as $name) {
            $this->assertMatchesRegularExpression(ProjectEnvironment::NAME_PATTERN, $name);
            $this->assertFalse(ProjectEnvironment::isReservedName($name), $name);
        }
        foreach (['Staging', '-dev', 'dev-', '2dev', str_repeat('a', 21)] as $name) {
            $this->assertDoesNotMatchRegularExpression(ProjectEnvironment::NAME_PATTERN, $name);
        }
        foreach (['production', 'trigger', 'qc', 'pr-7', 'eu-pr-12', 'v3', 'bindings'] as $name) {
            $this->assertTrue(ProjectEnvironment::isReservedName($name), $name);
        }
    }

    public function test_the_workload_name_leaves_room_for_the_longest_suffix(): void
    {
        $short = (new Project)->forceFill(['slug' => 'shop']);
        $this->assertSame('shop-staging', ProjectEnvironment::workloadNameFor($short, 'staging'));

        $long = (new Project)->forceFill(['slug' => str_repeat('a', 40).'-org1']);
        $name = ProjectEnvironment::workloadNameFor($long, 'staging');
        $this->assertLessThanOrEqual(Project::MAX_SLUG_LENGTH, strlen($name));
        $this->assertStringEndsWith('-staging', $name);
    }

    public function test_env_vars_merge_by_name_and_null_removes_one(): void
    {
        $this->assertSame(
            ['A' => '1', 'B' => 'staging', 'D' => '4'],
            EnvironmentConfig::mergeEnv(['A' => '1', 'B' => '2', 'C' => '3'], ['B' => 'staging', 'C' => null, 'D' => 4]),
        );
    }

    public function test_the_diff_names_env_vars_and_secrets_never_their_values(): void
    {
        $diff = EnvironmentConfig::diff(
            ['image' => 'app:1', 'min_scale' => 0, 'scale_to_zero' => true, 'env_vars' => ['A' => 'one', 'B' => 'two'], '_secret_names' => ['DB_PASSWORD']],
            ['image' => 'app:2', 'min_scale' => '0', 'scale_to_zero' => '1', 'env_vars' => ['A' => 'ONE', 'C' => 'three'], '_secret_names' => ['DB_PASSWORD', 'API_KEY']],
        );

        $byField = collect($diff)->keyBy('field');
        $this->assertSame(['image', 'env_vars', 'secrets'], $byField->keys()->all(), 'equal values of another type are no change');
        $this->assertSame(['from' => 'app:1', 'to' => 'app:2'], collect($byField['image'])->only(['from', 'to'])->all());
        $this->assertSame(['field' => 'env_vars', 'added' => ['C'], 'removed' => ['B'], 'changed' => ['A']], $byField['env_vars']);
        $this->assertSame(['API_KEY'], $byField['secrets']['added']);
        $this->assertStringNotContainsString('three', json_encode($diff));
        $this->assertStringNotContainsString('ONE', json_encode($diff));
    }

    public function test_an_environment_s_workloads_carry_its_labels_instead_of_the_project_s(): void
    {
        $project = (new Project)->forceFill(['id' => '01J9ZPROJECT00000000000000', 'slug' => 'shop', 'name' => 'shop', 'service_type' => 'caas']);
        $environment = (new ProjectEnvironment)->forceFill(['id' => '01J9ZENVIRONMENT0000000000', 'name' => 'staging', 'workload_name' => 'shop-staging', 'is_primary' => false]);
        $project->setRelation('knativeRegion', null)->setRelation('containerConfig', null)
            ->setRelation('secrets', new Collection)->setRelation('privateDatabaseBindings', new Collection);
        $view = EnvironmentProject::of($project, $environment);

        $manifests = EnvironmentManifests::transform([
            ['apiVersion' => 'serving.knative.dev/v1', 'kind' => 'Service', 'metadata' => ['name' => 'shop-staging', 'labels' => ['runr/project-id' => $project->id]],
                'spec' => ['template' => ['metadata' => ['labels' => ['runr/project-id' => $project->id]], 'spec' => ['containers' => [['name' => 'app', 'env' => [['name' => 'A', 'value' => '1']]]]]]]],
            ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => ['name' => 'shop-staging-qc', 'labels' => ['runr/project-id' => $project->id]],
                'spec' => ['selector' => ['matchLabels' => ['runr/project-id' => $project->id, 'runr/component' => 'queue-consumer']],
                    'template' => ['metadata' => ['labels' => ['runr/project-id' => $project->id, 'runr/component' => 'queue-consumer']], 'spec' => ['containers' => [['name' => 'consumer']]]]]],
            ['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'shop-staging-v3', 'labels' => ['runr/project-id' => $project->id]], 'data' => []],
        ], $view);

        [$service, $consumer, $configMap] = $manifests;
        $pod = $service['spec']['template']['metadata']['labels'];
        $this->assertArrayNotHasKey('runr/project-id', $pod);
        $this->assertSame('01J9ZENVIRONMENT0000000000', $pod['runr/environment-id']);
        $this->assertSame($project->id, $pod['runr/environment-of']);
        $this->assertSame('staging', $pod['runr/environment']);
        $this->assertSame([['name' => 'A', 'value' => '1'], ['name' => 'RUNR_ENVIRONMENT', 'value' => 'staging']], $service['spec']['template']['spec']['containers'][0]['env']);
        $this->assertTrue(KnativeNodeService::isProjectWorkload(['metadata' => $service['spec']['template']['metadata']]), 'region capacity still counts it as a project pod');

        $this->assertSame(['runr/component' => 'queue-consumer', 'runr/environment-id' => '01J9ZENVIRONMENT0000000000'], $consumer['spec']['selector']['matchLabels']);
        $this->assertArrayNotHasKey('env', $consumer['spec']['template']['spec']['containers'][0], 'only the handler learns its environment');
        $this->assertSame($project->id, $configMap['metadata']['labels']['runr/project-id'], 'deleting the project sweeps it');

        // The project's own workload is left as it was
        $own = [['kind' => 'ConfigMap', 'metadata' => ['name' => 'shop-v3', 'labels' => ['runr/project-id' => $project->id]]]];
        $this->assertSame($own, EnvironmentManifests::transform($own, $project));
    }

    public function test_a_binding_policy_admits_the_attached_environments_pods(): void
    {
        $binding = (new Binding)->forceFill(['resource_name' => 'orders-db-01j9']);
        $policy = BindingManifests::bindingPolicy($binding, 'runr-data', 'eu-fn', ['p1'], ['labels' => ['app' => 'pg'], 'port' => 5432], ['e2', 'e1']);

        $from = $policy['spec']['ingress'][0]['from'];
        $this->assertCount(2, $from);
        $this->assertSame(['key' => 'runr/project-id', 'operator' => 'In', 'values' => ['p1']], $from[0]['podSelector']['matchExpressions'][0]);
        $this->assertSame(['key' => 'runr/environment-id', 'operator' => 'In', 'values' => ['e1', 'e2']], $from[1]['podSelector']['matchExpressions'][0]);

        $environmentsOnly = BindingManifests::bindingPolicy($binding, 'runr-data', 'eu-fn', [], ['labels' => ['app' => 'pg'], 'port' => 5432], ['e1']);
        $this->assertCount(1, $environmentsOnly['spec']['ingress'][0]['from'], 'a production pod is not admitted to staging\'s data');
    }
}
