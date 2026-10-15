<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\ImageSecurityReport;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** security_report — the version security route, condensed. */
class SecurityToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    private function report(Version $version, array $findings): ImageSecurityReport
    {
        return ImageSecurityReport::create([
            'subject_type' => ImageSecurityReport::SUBJECT_VERSION,
            'subject_id' => $version->id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'knative_region_id' => $this->region->id,
            'image_kind' => ImageSecurityReport::KIND_BUILT,
            'image_ref' => 'registry.fn/runr-builds/shop:v'.$version->number,
            'image_digest' => 'sha256:'.str_repeat('a', 64),
            'status' => 'completed',
            'steps' => ['sbom', 'scan'],
            'sbom_status' => 'ok',
            'scan_status' => 'ok',
            'critical_count' => 1,
            'high_count' => 2,
            'fixable_critical_count' => 1,
            'fixable_high_count' => 0,
            'findings_total' => count($findings),
            'findings' => $findings,
        ]);
    }

    public function test_the_report_of_the_newest_version_lists_the_worst_findings_first(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $v2 = $this->version($project, 2);
        $this->report($v2, [
            ['id' => 'CVE-2026-0003', 'severity' => 'Low', 'package' => 'zlib'],
            ['id' => 'CVE-2026-0001', 'severity' => 'Critical', 'package' => 'openssl', 'fixed_version' => '3.0.15'],
            ['id' => 'CVE-2026-0002', 'severity' => 'High', 'package' => 'curl'],
        ]);

        $result = $this->structured($this->callTool('security_report', ['project' => 'shop', 'findings' => 2]));

        $this->assertSame(2, $result['version']['number']);
        $this->assertSame(1, $result['report']['counts']['critical']);
        $this->assertSame(['CVE-2026-0001', 'CVE-2026-0002'], array_column($result['findings'], 'id'));
    }

    public function test_a_version_without_a_report_says_so(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);

        $result = $this->callTool('security_report', ['project' => 'shop', 'version' => '1']);

        $this->assertStringContainsString('no security report', $result['content'][0]['text']);
    }

    public function test_security_report_validation_and_permissions(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);

        $this->mcpToolError($this->callTool('security_report', ['project' => 'shop', 'version' => '5']));
        $this->assertSchemaRejects('security_report', ['project' => 'shop', 'findings' => 500], '/findings');
        $this->assertToolHidden('security_report', ['projects.view', 'logs.view']);
    }
}
