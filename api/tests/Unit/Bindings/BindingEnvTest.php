<?php

namespace Tests\Unit\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Project;
use App\Services\Bindings\BindingEnv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Env vars of bindings and the container hook (envFrom on <project>-bindings).
 */
class BindingEnvTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_per_type(): void
    {
        $this->assertSame(['REDIS_URL', 'KV_PREFIX'], BindingEnv::keysFor(new Binding(['type' => 'kv'])));
        $this->assertSame(
            ['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY', 'S3_REGION', 'S3_FORCE_PATH_STYLE'],
            BindingEnv::keysFor(new Binding(['type' => 'object'])),
        );
        $this->assertSame(
            ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
            BindingEnv::keysFor(new Binding(['type' => 'postgres'])),
        );
    }

    public function test_external_keys_are_the_binding_values(): void
    {
        $binding = new Binding(['type' => 'external']);
        $binding->credentials = ['values' => ['API_URL' => 'https://api.example.com', 'API_TOKEN' => 't']];

        $this->assertSame(['API_URL', 'API_TOKEN'], BindingEnv::keysFor($binding));
        $this->assertSame([], BindingEnv::keysFor(new Binding(['type' => 'external'])));
    }

    public function test_secret_name_and_attachment_env_names(): void
    {
        $project = Project::create(['name' => 'orders-api', 'service_type' => 'caas']);
        $this->assertSame('orders-api-bindings', BindingEnv::secretName($project));

        $binding = new Binding(['type' => 'postgres']);
        $attachment = new BindingAttachment(['env_prefix' => 'ORDERS_']);
        $attachment->setRelation('binding', $binding);

        $this->assertContains('ORDERS_DATABASE_URL', $attachment->envNames());
        $this->assertContains('ORDERS_PGPASSWORD', $attachment->envNames());
    }

    public function test_apply_adds_env_from_only_for_projects_with_attachments(): void
    {
        $project = Project::create(['name' => 'orders-api', 'service_type' => 'caas']);
        $container = ['name' => 'user-container', 'envFrom' => [['configMapRef' => ['name' => 'other']]]];

        $this->assertSame($container, BindingEnv::apply($container, $project), 'nothing attached: unchanged');

        $binding = new Binding(['name' => 'db', 'resource_name' => 'db-12345678', 'type' => 'external', 'plan' => 'external', 'status' => 'ready']);
        $binding->credentials = ['values' => ['X' => 'y']];
        $binding->save();
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id, 'env_prefix' => '']);

        $applied = BindingEnv::apply($container, $project);
        $this->assertSame([
            ['configMapRef' => ['name' => 'other']],
            ['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]],
        ], $applied['envFrom']);

        // Applying twice keeps one entry
        $this->assertCount(2, BindingEnv::apply($applied, $project)['envFrom']);
    }

    public function test_with_env_from_removes_the_entry_and_an_empty_list(): void
    {
        $container = ['envFrom' => [['secretRef' => ['name' => 'x-bindings', 'optional' => true]]]];

        $this->assertArrayNotHasKey('envFrom', BindingEnv::withEnvFrom($container, 'x-bindings', false));
        $this->assertSame(
            [['secretRef' => ['name' => 'keep']]],
            BindingEnv::withEnvFrom(['envFrom' => [['secretRef' => ['name' => 'keep']], ['secretRef' => ['name' => 'x-bindings']]]], 'x-bindings', false)['envFrom'],
        );
    }

    public function test_hash_is_stable_and_order_independent(): void
    {
        $a = BindingEnv::hash(['B' => '2', 'A' => '1']);

        $this->assertSame($a, BindingEnv::hash(['A' => '1', 'B' => '2']));
        $this->assertSame(16, strlen($a));
        $this->assertNotSame($a, BindingEnv::hash(['A' => '1', 'B' => '3']));
    }

    public function test_project_env_prefixes_values_of_active_bindings_with_credentials(): void
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);

        $active = new Binding(['name' => 'stripe', 'resource_name' => 'stripe-11111111', 'type' => 'external', 'plan' => 'external', 'status' => 'ready']);
        $active->credentials = ['values' => ['API_KEY' => 'sk_live']];
        $active->save();

        $deleting = new Binding(['name' => 'old', 'resource_name' => 'old-22222222', 'type' => 'external', 'plan' => 'external', 'status' => Binding::STATUS_DELETING]);
        $deleting->credentials = ['values' => ['OLD_KEY' => 'x']];
        $deleting->save();

        BindingAttachment::create(['binding_id' => $active->id, 'project_id' => $project->id, 'env_prefix' => 'STRIPE_']);
        BindingAttachment::create(['binding_id' => $deleting->id, 'project_id' => $project->id, 'env_prefix' => '']);

        $this->assertSame(['STRIPE_API_KEY' => 'sk_live'], BindingEnv::projectEnv($project));
    }
}
