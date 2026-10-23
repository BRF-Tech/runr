<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\InstallCertManagerJob;
use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * cert_manager_status and cert_manager_install (GET / POST /knative-regions/{id}/cert-manager):
 * the status from RunR's records with the reason TLS mode cert-manager is not offered, the queued
 * install followed until ready, and the permissions (regions.view, regions.manage).
 */
class CertManagerToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private KnativeRegion $eu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $this->eu = KnativeRegion::create([
            'name' => 'eu-1',
            'project_namespace' => 'eu-1',
            'is_active' => true,
            // never contacted: the tools read RunR's records (cluster: false), the install is queued
            'kubeconfig' => "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: c\n  cluster:\n    server: https://eu-1.cluster.invalid:6443\nusers:\n- name: u\n  user:\n    token: t\ncontexts:\n- name: c\n  context:\n    cluster: c\n    user: u\n",
            'knative_serving_status' => 'ready',
            'knative_operator_status' => 'ready',
        ]);
    }

    public function test_status_says_whether_custom_domains_can_use_it(): void
    {
        $result = $this->structured($this->callTool('cert_manager_status', ['region' => 'eu-1', 'cluster' => false]));

        $this->assertSame('not_installed', $result['status']);
        $this->assertFalse($result['custom_domains']['cert_manager_mode_available']);
        $this->assertSame(__('api.cert_manager.domains_not_installed'), $result['custom_domains']['reason']);
        $this->assertSame('ca', explode(':', $result['default_issuer_plan'])[0], 'no external address: the region CA');
        $this->assertTrue($result['can_manage']);

        $this->eu->update(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-ca', 'cert_manager_managed' => true, 'cert_manager_version' => 'v1.21.2']);
        $result = $this->structured($this->callTool('cert_manager_status', ['region' => $this->eu->id, 'cluster' => false]));

        $this->assertSame(['ready', 'runr-ca', 'ca', true, 'v1.21.2'], [$result['status'], $result['issuer'], $result['issuer_kind'], $result['installed_by_runr'], $result['version']]);
        $this->assertTrue($result['custom_domains']['cert_manager_mode_available']);
        $this->assertTrue($result['custom_domains']['ca_download']);
        $this->assertArrayNotHasKey('cluster', $result);
    }

    public function test_install_is_queued_and_followed_until_ready(): void
    {
        Queue::fake();
        $this->onPoll = fn () => $this->eu->forceFill(['cert_manager_status' => 'ready', 'cert_manager_issuer' => 'runr-ca'])->save();

        $result = $this->structured($this->callTool('cert_manager_install', ['region' => 'eu-1']));

        $this->assertSame('ready', $result['status']);
        $this->assertSame('runr-ca', $result['issuer']);
        Queue::assertPushed(InstallCertManagerJob::class, fn (InstallCertManagerJob $job) => $job->action === InstallCertManagerJob::INSTALL && $job->region->is($this->eu));
    }

    public function test_install_without_waiting_and_a_region_without_serving(): void
    {
        Queue::fake();

        $result = $this->structured($this->callTool('cert_manager_install', ['region' => 'eu-1', 'wait' => false]));
        $this->assertSame('installing', $result['status']);

        $this->eu->update(['knative_serving_status' => 'not_installed', 'cert_manager_status' => 'not_installed']);
        $error = $this->mcpToolError($this->callTool('cert_manager_install', ['region' => 'eu-1']));
        $this->assertStringContainsString(__('api.cert_manager.serving_required'), json_encode($error));
    }

    public function test_permissions(): void
    {
        $this->customRole('builder', ['projects.view', 'tokens.manage']);
        $builder = $this->userWithRole('builder');
        $this->assertToolHidden('cert_manager_status', ['*'], ['region' => 'eu-1'], $builder);
        $this->assertToolHidden('cert_manager_install', ['*'], ['region' => 'eu-1'], $builder);

        // regions.view reads, only regions.manage installs
        $viewer = $this->userWithRole('viewer');
        $this->assertToolHidden('cert_manager_install', ['*'], ['region' => 'eu-1'], $viewer);
        $this->structured($this->callTool('cert_manager_status', ['region' => 'eu-1', 'cluster' => false], $viewer));

        $this->assertSchemaRejects('cert_manager_status', [], '/region');
    }
}
