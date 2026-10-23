<?php

namespace Tests\Feature\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\DynamicDbCredentials;
use App\Services\Secrets\OpenBao\OpenBaoInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\Concerns\FakesOpenBao;
use Tests\TestCase;

/**
 * Dynamic Postgres credentials of bindings (docs/bindings.md, "Dynamic credentials"): a project
 * attached with credentials "dynamic" gets a Postgres user of its own from the region's OpenBao —
 * the binding's connection there (a role OpenBao alone knows the password of), a role per
 * attachment, the project's policy — and the region renews it through External Secrets Operator;
 * the binding's own user and password never reach the project.
 */
class DynamicDbCredentialsTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, FakesOpenBao, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();
        $this->fakeOpenBao();

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->bindingRegion();
        $this->readyService($this->region, 'postgres');
    }

    private function attachDynamic(Project $project, Binding $binding, string $prefix = 'ORDERS_')
    {
        return $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", [
            'binding_id' => $binding->id, 'env_prefix' => $prefix, 'credentials' => 'dynamic',
        ]);
    }

    private function role(BindingAttachment $attachment): string
    {
        return 'runr-'.strtolower($attachment->binding_id).'-'.strtolower($attachment->project_id);
    }

    public function test_dynamic_credentials_need_the_regions_openbao(): void
    {
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);

        $this->attachDynamic($project, $binding)->assertUnprocessable()->assertJsonValidationErrors('binding');
        $this->assertSame(0, BindingAttachment::count());

        // Postgres only
        $this->openBaoReady($this->region);
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $this->attachDynamic($project, $kv, 'CACHE_')->assertUnprocessable();
    }

    public function test_a_dynamic_attachment_gets_a_postgres_user_of_its_own(): void
    {
        $this->openBaoReady($this->region);
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $owner = (string) $binding->credential('username');

        $this->attachDynamic($project, $binding)->assertCreated()->assertJsonPath('data.credential_mode', 'dynamic');
        $attachment = BindingAttachment::sole();
        $bao = $this->openBao($this->region);
        $connection = 'runr-'.strtolower($binding->id);

        // The binding's connection: a role of its own for OpenBao, its password rotated at once
        $config = $bao->paths["database/config/{$connection}"];
        $this->assertSame('postgresql-database-plugin', $config['plugin_name']);
        $this->assertStringStartsWith('postgresql://{{username}}:{{password}}@runr-pg-shared-rw.', $config['connection_url']);
        $this->assertStringStartsWith('runr_bao_', $config['username']);
        $this->assertSame(["{$connection}-*"], $config['allowed_roles']);
        $written = $bao->written();
        $this->assertGreaterThan(array_search("database/config/{$connection}", $written, true), array_search("database/rotate-root/{$connection}", $written, true));
        $sql = (string) $this->opsTask('postgres-openbao')->files['script.sql'];
        $this->assertStringContainsString('CREATE ROLE "'.$config['username'].'" LOGIN CREATEROLE', $sql);
        $this->assertStringContainsString('GRANT "'.$owner.'" TO "'.$config['username'].'" WITH ADMIN OPTION', $sql);

        // The attachment's role: members of the binding's role, dropped on revocation
        $role = $bao->paths['database/roles/'.$this->role($attachment)];
        $this->assertSame($connection, $role['db_name']);
        $this->assertStringContainsString('IN ROLE "'.$owner.'"', $role['creation_statements'][0]);
        $this->assertStringContainsString('DROP ROLE IF EXISTS "{{name}}"', implode("\n", $role['revocation_statements']));
        $this->assertSame(config('secrets.openbao.db_default_ttl'), $role['default_ttl']);

        // The project may read its credentials, nothing else of the engine
        $policy = $bao->policies['runr-project-'.strtolower($project->id)];
        $this->assertStringContainsString('path "database/creds/'.$this->role($attachment).'"', $policy);

        // The static Secret has no user, password or URL; the region renews them from OpenBao
        $static = $this->assertApplied('/api/v1/namespaces/fn/secrets/orders-api-bindings');
        $keys = array_keys($static['data'] ?? $static['stringData'] ?? []);
        $this->assertContains('ORDERS_PGHOST', $keys);
        foreach (['ORDERS_PGUSER', 'ORDERS_PGPASSWORD', 'ORDERS_DATABASE_URL'] as $key) {
            $this->assertNotContains($key, $keys);
        }

        $generator = $this->assertApplied('/apis/generators.external-secrets.io/v1alpha1/namespaces/fn/vaultdynamicsecrets/'.DynamicDbCredentials::generatorName($attachment));
        $this->assertSame('/database/creds/'.$this->role($attachment), $generator['spec']['path']);
        $this->assertSame('runr-project-'.strtolower($project->id), $generator['spec']['provider']['auth']['kubernetes']['role']);

        $secret = DynamicDbCredentials::secretName($attachment, $project->fresh());
        $external = $this->assertApplied("/apis/external-secrets.io/v1/namespaces/fn/externalsecrets/{$secret}");
        $this->assertSame(['ORDERS_PGUSER', 'ORDERS_PGPASSWORD', 'ORDERS_DATABASE_URL'], array_keys($external['spec']['target']['template']['data']));
        $this->assertSame(config('secrets.openbao.db_refresh'), $external['spec']['refreshInterval']);

        // The binding's own password reaches neither the project nor OpenBao
        $this->assertStringNotContainsString('pg-pass-1', json_encode([$static, $generator, $external, $bao->paths]));

        // The workload reads the dynamic Secret after the static one
        $container = BindingEnv::apply(['name' => 'user-container'], $project->fresh());
        $this->assertSame(['orders-api-bindings', $secret], array_column(array_column($container['envFrom'], 'secretRef'), 'name'));

        // OpenBao stays while it issues them
        $this->assertSame(1, app(OpenBaoInstallService::class)->valuesOn($this->region));
    }

    public function test_detaching_revokes_the_users_and_the_policy_loses_them(): void
    {
        $this->openBaoReady($this->region);
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $this->attachDynamic($project, $binding)->assertCreated();
        $attachment = BindingAttachment::sole();
        $role = $this->role($attachment);

        $this->signedIn($this->owner)->deleteJson("/api/projects/{$project->id}/bindings/{$attachment->id}")->assertOk();

        $bao = $this->openBao($this->region);
        $this->assertContains(['write', "sys/leases/revoke-prefix/database/creds/{$role}"], $bao->log);
        $this->assertContains(['delete', "database/roles/{$role}"], $bao->log);
        $this->assertStringNotContainsString($role, $bao->policies['runr-project-'.strtolower($project->id)]);
    }

    public function test_an_attachment_switches_between_static_and_dynamic(): void
    {
        $this->openBaoReady($this->region);
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id, 'env_prefix' => 'ORDERS_'])
            ->assertCreated()->assertJsonPath('data.credential_mode', 'static');
        $attachment = BindingAttachment::sole();

        $this->signedIn($this->owner)->patchJson("/api/projects/{$project->id}/bindings/{$attachment->id}", ['credentials' => 'dynamic'])
            ->assertOk()->assertJsonPath('data.credential_mode', 'dynamic')->assertJsonPath('data.env_prefix', 'ORDERS_');
        $this->assertArrayHasKey('database/roles/'.$this->role($attachment), $this->openBao($this->region)->paths);

        $this->signedIn($this->owner)->patchJson("/api/projects/{$project->id}/bindings/{$attachment->id}", ['credentials' => 'static'])
            ->assertOk()->assertJsonPath('data.credential_mode', 'static');
        $this->assertArrayNotHasKey('database/roles/'.$this->role($attachment), $this->openBao($this->region)->paths);
        $this->assertSame(['ORDERS_DATABASE_URL', 'ORDERS_PGPASSWORD', 'ORDERS_PGUSER'], array_values(array_intersect(
            ['ORDERS_DATABASE_URL', 'ORDERS_PGPASSWORD', 'ORDERS_PGUSER'],
            array_keys(BindingEnv::projectEnv($project->fresh())),
        )));
    }

    public function test_a_renewal_rolls_the_workload_onto_the_new_user(): void
    {
        $this->openBaoReady($this->region);
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $this->attachDynamic($project, $binding)->assertCreated();
        $attachment = BindingAttachment::sole();
        $secret = DynamicDbCredentials::secretName($attachment, $project->fresh());

        $this->k8sResources['/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api'] = ['spec' => ['template' => ['metadata' => ['annotations' => []], 'spec' => ['containers' => [['name' => 'user-container']]]]]];
        $this->k8sResources['/apis/external-secrets.io/v1/namespaces/fn/externalsecrets'] = ['items' => [[
            'metadata' => ['name' => $secret, 'labels' => ['runr/secret-source' => 'pgdyn', 'runr/project-id' => $project->id]],
            'status' => ['syncedResourceVersion' => '1-abc'],
        ]]];

        $this->artisan('secrets:sync-status')->assertSuccessful();

        $patch = $this->k8sSent('PATCH', '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api');
        $this->assertNotNull($patch, 'the Knative Service gets a new revision');
        $this->assertArrayHasKey(DynamicDbCredentials::RENEWAL_ANNOTATION, json_decode($patch['body'], true)['spec']['template']['metadata']['annotations']);
    }
}
