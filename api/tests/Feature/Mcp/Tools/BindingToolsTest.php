<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/** bindings_list, binding_create, binding_attach — never a credential value in an answer. */
class BindingToolsTest extends TestCase
{
    use FakesBindingOps, McpToolFixtures, RefreshDatabase;

    private KnativeRegion $dataRegion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->setUpBindingFakes();

        $this->dataRegion = $this->bindingRegion();
    }

    public function test_bindings_are_listed_without_credentials(): void
    {
        $this->makeBinding('kv', $this->dataRegion, ['name' => 'cache']);
        $this->makeBinding('external', null, ['name' => 'stripe']);

        $result = $this->callTool('bindings_list');
        $data = $this->structured($result);

        $this->assertEqualsCanonicalizing(['cache', 'stripe'], array_column($data['bindings'], 'name'));
        $this->assertStringNotContainsString('kv-pass-1', json_encode($result));
        $this->assertStringNotContainsString('tok-1', json_encode($result));

        $kv = $this->structured($this->callTool('bindings_list', ['type' => 'kv']));
        $this->assertSame(['cache'], array_column($kv['bindings'], 'name'));
    }

    public function test_a_kv_binding_is_created_in_a_region(): void
    {
        $this->readyService($this->dataRegion, 'kv');

        $result = $this->structured($this->callTool('binding_create', [
            'name' => 'sessions', 'type' => 'kv', 'region' => 'fn', 'settings' => ['memory_mb' => 64],
        ]));

        $this->assertSame('sessions', $result['binding']['name']);
        $this->assertSame(['REDIS_URL', 'KV_PREFIX'], $result['binding']['env_keys']);
        $this->assertSame(64, Binding::where('name', 'sessions')->sole()->settings['memory_mb']);
    }

    public function test_an_external_binding_takes_its_values(): void
    {
        $result = $this->callTool('binding_create', ['name' => 'stripe', 'type' => 'external', 'values' => ['STRIPE_KEY' => 'sk-do-not-leak']]);

        $this->structured($result);
        $this->assertStringNotContainsString('sk-do-not-leak', json_encode($result));
        $this->assertSame('sk-do-not-leak', Binding::where('name', 'stripe')->sole()->credential('values')['STRIPE_KEY'] ?? null);
    }

    public function test_a_binding_is_attached_by_name_with_a_prefix(): void
    {
        // the attachment's env (REDIS_URL) comes from the region's kv data service
        $this->readyService($this->dataRegion, 'kv');
        $this->makeBinding('kv', $this->dataRegion, ['name' => 'cache']);
        $project = $this->bindingProject($this->dataRegion);

        $result = $this->structured($this->callTool('binding_attach', ['project' => $project->name, 'binding' => 'cache', 'env_prefix' => 'CACHE_']));

        $this->assertSame('CACHE_', $result['attachment']['env_prefix']);
        $this->assertContains('CACHE_REDIS_URL', $result['attachment']['env_names']);
        $this->assertSame(1, BindingAttachment::count());
    }

    public function test_binding_validation(): void
    {
        $this->assertSchemaRejects('binding_create', ['name' => 'Bad_Name', 'type' => 'kv'], '/name');
        $this->assertSchemaRejects('binding_create', ['name' => 'x', 'type' => 'mongo'], '/type');
        $this->assertSchemaRejects('binding_attach', ['project' => 'orders-api', 'binding' => 'cache', 'env_prefix' => 'cache'], '/env_prefix');

        // The region's KV service is not set up: the route's 422
        $error = $this->mcpToolError($this->callTool('binding_create', ['name' => 'cache', 'type' => 'kv', 'region' => 'fn']));
        $this->assertSame(422, $error['status']);

        $this->bindingProject($this->dataRegion);
        $this->mcpToolError($this->callTool('binding_attach', ['project' => 'orders-api', 'binding' => 'ghost']));
    }

    public function test_binding_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('bindings_list', [], $viewer));
        $this->assertToolHidden('binding_create', ['*'], ['name' => 'x', 'type' => 'kv'], $viewer);
        // attaching needs bindings.manage AND env.edit
        $this->assertToolHidden('binding_attach', ['bindings.manage'], ['project' => 'orders-api', 'binding' => 'cache']);
        $this->assertToolHidden('bindings_list', ['projects.view']);
    }
}
