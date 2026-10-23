<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\OrgTransfer\ExportOrganizationJob;
use App\Jobs\OrgTransfer\FetchImportPackageJob;
use App\Models\Binding;
use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * MCP tools of the organization export / import (docs/org-export.md, docs/mcp.md):
 * org_export_start / org_export_status / org_import_plan / org_import_apply — owners only (the
 * API answers), hidden from tokens without org.transfer, apply only with confirm.
 */
class OrgTransferToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        Queue::fake();
    }

    public function test_an_owner_starts_an_export_and_follows_it(): void
    {
        $project = $this->faasProject('orders');

        $out = $this->structured($this->callTool('org_export_start', ['projects' => ['orders'], 'include_members' => true]));

        $this->assertSame('queued', $out['transfer']['status']);
        $transfer = OrgTransfer::query()->findOrFail($out['transfer']['id']);
        $this->assertSame([$project->id], $transfer->options['projects'], 'project names resolve to ids');
        Queue::assertPushed(ExportOrganizationJob::class);

        $status = $this->structured($this->callTool('org_export_status', ['transfer' => $transfer->id]));
        $this->assertSame('export', $status['transfer']['kind']);

        $list = $this->structured($this->callTool('org_export_status'));
        $this->assertSame([$transfer->id], array_column($list['transfers'], 'id'));
    }

    public function test_secrets_need_a_passphrase(): void
    {
        $error = $this->mcpToolError($this->callTool('org_export_start', ['include_secrets' => true]));
        $this->assertStringContainsString('passphrase', json_encode($error));
        Queue::assertNothingPushed();

        $this->assertSchemaRejects('org_export_start', ['include_secrets' => true, 'passphrase' => 'short'], '/passphrase');
    }

    public function test_admins_are_refused_by_the_api(): void
    {
        $admin = $this->userWithRole('admin');

        $error = $this->mcpToolError($this->callTool('org_export_start', [], $admin));
        $this->assertStringContainsString('owner', strtolower(json_encode($error)));
        Queue::assertNothingPushed();
    }

    public function test_tokens_without_org_transfer_do_not_see_the_tools(): void
    {
        $this->assertToolHidden('org_export_start', ['projects.view']);
        $this->assertToolHidden('org_import_apply', ['projects.view'], ['transfer' => '01jabcdefghjkmnpqrstvwxyz0', 'confirm' => true]);
    }

    public function test_an_import_starts_from_an_object_binding_and_a_failed_read_resumes(): void
    {
        $region = KnativeRegion::query()->create(['name' => 'eu-1', 'project_namespace' => 'fn', 'is_active' => true]);
        $bucket = Binding::query()->create([
            'name' => 'backups', 'resource_name' => 'backups-abcdefgh', 'type' => 'object', 'plan' => 'shared',
            'knative_region_id' => $region->id, 'status' => Binding::STATUS_READY,
        ]);

        $error = $this->mcpToolError($this->callTool('org_import_start', ['binding' => $bucket->id]));
        $this->assertStringContainsString('key', json_encode($error));

        $out = $this->structured($this->callTool('org_import_start', ['binding' => $bucket->id, 'key' => 'runr-exports/p.tar.zst']));
        $this->assertSame('fetching', $out['transfer']['status']);
        $this->assertSame('binding', $out['transfer']['source']['type']);
        $this->assertSame('runr-exports/p.tar.zst', $out['transfer']['source']['key']);
        Queue::assertPushed(FetchImportPackageJob::class, fn ($job) => $job->credentials === null);

        OrgTransfer::query()->whereKey($out['transfer']['id'])->update(['status' => 'failed', 'phase' => 'fetch']);
        $resumed = $this->structured($this->callTool('org_import_start', ['resume' => $out['transfer']['id']]));
        $this->assertSame('fetching', $resumed['transfer']['status']);
        Queue::assertPushed(FetchImportPackageJob::class, 2);
    }

    public function test_apply_needs_confirm_and_a_planned_import(): void
    {
        $this->assertSchemaRejects('org_import_apply', ['transfer' => '01jabcdefghjkmnpqrstvwxyz0']);

        $import = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'import', 'status' => 'uploading', 'created_by' => (string) $this->owner->id]);
        $error = $this->mcpToolError($this->callTool('org_import_apply', ['transfer' => $import->id, 'confirm' => true]));
        $this->assertSame(409, $error['status'] ?? null, json_encode($error));

        $error = $this->mcpToolError($this->callTool('org_import_plan', ['transfer' => $import->id, 'region_map' => ['r1' => 'r2']]));
        $this->assertSame(409, $error['status'] ?? null, json_encode($error));
    }
}
