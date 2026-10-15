<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/** runtimes_list, runtime_get, runtime_register (the runtime catalog routes). */
class RuntimeToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->seedBuiltinVersions();
    }

    public function test_the_catalog_is_listed_for_every_caller(): void
    {
        $viewer = $this->userWithRole('viewer');

        $result = $this->structured($this->callTool('runtimes_list', [], $viewer, ['projects.view']));

        $ids = array_column($result['runtimes'], 'id');
        $this->assertContains('python', $ids);
        $this->assertContains('nodejs', $ids);
        $python = collect($result['runtimes'])->firstWhere('id', 'python');
        $this->assertSame('requirements.txt', $python['dependency_file']);
        $this->assertContains('http', $python['triggers']);
        $this->assertFalse($result['can_register']);
    }

    public function test_a_runtime_is_shown_with_its_manifest_and_starter_files(): void
    {
        $result = $this->structured($this->callTool('runtime_get', ['runtime' => 'python']));

        $this->assertSame('python', $result['runtime']['id']);
        $this->assertStringContainsString('runr.sh/v1', $result['manifest']);
        $this->assertArrayHasKey('main.py', $result['starter_files']);
        $this->assertArrayHasKey('requirements.txt', $result['starter_files']);
    }

    public function test_a_runtime_is_registered_from_a_manifest(): void
    {
        $result = $this->structured($this->callTool('runtime_register', ['manifest' => $this->bashManifest()]));

        $this->assertSame('bash', $result['runtime']['id']);
        $this->assertSame(Runtime::SOURCE_PLATFORM, Runtime::findOrFail('bash')->source);
    }

    public function test_an_invalid_manifest_lists_its_problems(): void
    {
        $error = $this->mcpToolError($this->callTool('runtime_register', ['manifest' => "apiVersion: runr.sh/v1\nkind: Runtime\nid: broken\n"]));

        $this->assertSame(422, $error['status']);
        $this->assertNotEmpty($error['problems'] ?? $error['errors'] ?? []);
        $this->assertSchemaRejects('runtime_register', [], '/manifest');
        $this->assertSchemaRejects('runtime_get', ['runtime' => 'Not An Id'], '/runtime');
        $this->mcpToolError($this->callTool('runtime_get', ['runtime' => 'cobol']));
    }

    public function test_registering_needs_a_runtime_permission(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('runtime_register', ['*'], ['manifest' => 'x'], $viewer);
    }
}
