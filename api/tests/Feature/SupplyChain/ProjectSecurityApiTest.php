<?php

namespace Tests\Feature\SupplyChain;

use App\Jobs\StartSupplyChainJob;
use App\Models\ImageSbomDocument;
use App\Models\ImageSecurityReport;
use App\Models\SecurityPolicyOverride;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Security tab API of a project: summary of versions / deployments / overrides, version report
 * with findings and the gate decision, SBOM downloads, re-scan.
 */
class ProjectSecurityApiTest extends TestCase
{
    use ActsWithRoles, FakesSupplyChainCluster, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Bus::fake([StartSupplyChainJob::class]);
        $this->user = $this->userWithRole('owner', ['username' => 'dev']);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function completedReport(Version $version): ImageSecurityReport
    {
        $report = ImageSecurityReport::create([
            'subject_type' => 'version',
            'subject_id' => $version->id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'knative_region_id' => $version->project->knative_region_id,
            'image_kind' => 'built',
            'image_ref' => $version->build_image,
            'image_digest' => self::SC_DIGEST,
            'status' => 'completed',
            'steps' => ['sbom', 'scan'],
            'sbom_status' => 'ok',
            'scan_status' => 'ok',
            'critical_count' => 1,
            'high_count' => 2,
            'findings_total' => 3,
            'findings' => [['id' => 'CVE-2024-45491', 'severity' => 'CRITICAL', 'package' => 'libexpat1', 'installed' => '2.5.0-1', 'fixed' => '2.5.0-1+deb12u1', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-45491']],
            'sbom_summary' => ['packages' => 42],
            'scanned_at' => now(),
        ]);
        foreach (['spdx-json' => 'sbom.spdx.json', 'cyclonedx-json' => 'sbom.cdx.json'] as $format => $fixture) {
            $doc = (string) file_get_contents(base_path("tests/Fixtures/supply-chain/{$fixture}"));
            $report->sbomDocuments()->create(['format' => $format, 'content' => ImageSbomDocument::encode($doc), 'size_bytes' => strlen($doc), 'sha256' => hash('sha256', $doc)]);
        }

        return $report;
    }

    public function test_summary_lists_versions_deployments_and_overrides(): void
    {
        $region = $this->scRegion(['name' => 'istanbul']);
        $this->scSettings($region, ['gate_mode' => 'enforce', 'data_location' => 'TR']);
        $version = $this->scBuiltVersion($region);
        $mounted = Version::create(['project_id' => $version->project_id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->completedReport($version);
        $version->project->deployments()->create(['version_id' => $version->id, 'status' => 'active']);
        SecurityPolicyOverride::create(['project_id' => $version->project_id, 'version_id' => $version->id, 'username' => 'root', 'reason' => 'accepted', 'violations' => [['rule' => 'critical']]]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/projects/{$version->project_id}/security")->assertOk();

        $res->assertJsonPath('data.region.name', 'istanbul');
        $res->assertJsonPath('data.region.data_location', 'TR');
        $res->assertJsonPath('data.policy.mode', 'enforce');
        $res->assertJsonPath('data.pipeline.sbom', true);
        $res->assertJsonPath('data.versions.0.label', 'v2');
        $res->assertJsonPath('data.versions.0.applicable', false, 'code-mounted');
        $res->assertJsonPath('data.versions.1.image_kind', 'built');
        $res->assertJsonPath('data.versions.1.report.counts.critical', 1);
        $res->assertJsonPath('data.versions.1.report.packages', 42);
        $res->assertJsonPath('data.deployments.0.version_label', 'v1');
        $res->assertJsonPath('data.deployments.0.report.counts.high', 2);
        $res->assertJsonPath('data.overrides.0.reason', 'accepted');
        $this->assertArrayNotHasKey('findings', $res->json('data.versions.1.report'), 'the summary leaves the findings out');
        $this->assertSame($mounted->id, $res->json('data.versions.0.id'));
    }

    public function test_version_report_has_findings_and_the_gate_decision(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_max_critical' => 0]);
        $version = $this->scBuiltVersion($region);
        $this->completedReport($version);

        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/projects/{$version->project_id}/versions/{$version->id}/security")->assertOk();

        $res->assertJsonPath('data.report.findings.0.id', 'CVE-2024-45491');
        $res->assertJsonPath('data.report.findings.0.url', 'https://nvd.nist.gov/vuln/detail/CVE-2024-45491');
        $res->assertJsonPath('data.report.sbom_formats', ['spdx-json', 'cyclonedx-json']);
        $res->assertJsonPath('data.decision.blocked', true);
        $res->assertJsonPath('data.decision.violations.0.rule', 'critical');
    }

    public function test_a_version_of_another_project_is_not_found(): void
    {
        $region = $this->scRegion();
        $a = $this->scBuiltVersion($region);
        $b = $this->scBuiltVersion($region);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/projects/{$a->project_id}/versions/{$b->id}/security")->assertNotFound();
    }

    public function test_sbom_downloads(): void
    {
        $version = $this->scBuiltVersion($this->scRegion());
        $this->completedReport($version);
        $slug = $version->project->slug;

        $spdx = $this->actingAs($this->user, 'sanctum')->get("/api/projects/{$version->project_id}/versions/{$version->id}/sbom?format=spdx-json")->assertOk();
        $this->assertSame('application/spdx+json', $spdx->headers->get('Content-Type'));
        $this->assertStringContainsString("{$slug}-v1.spdx.json", $spdx->headers->get('Content-Disposition'));
        $this->assertSame('SPDX-2.3', json_decode($spdx->getContent(), true)['spdxVersion']);
        $this->assertSame(hash('sha256', $spdx->getContent()), $spdx->headers->get('X-Content-SHA256'));

        $cdx = $this->actingAs($this->user, 'sanctum')->get("/api/projects/{$version->project_id}/versions/{$version->id}/sbom?format=cyclonedx-json")->assertOk();
        $this->assertSame('CycloneDX', json_decode($cdx->getContent(), true)['bomFormat']);
        $this->assertStringContainsString('.cdx.json', $cdx->headers->get('Content-Disposition'));

        $this->actingAs($this->user, 'sanctum')->get("/api/projects/{$version->project_id}/versions/{$version->id}/sbom?format=xml")->assertStatus(422);

        $other = $this->scBuiltVersion($this->scRegion());
        $this->actingAs($this->user, 'sanctum')->get("/api/projects/{$other->project_id}/versions/{$other->id}/sbom")->assertNotFound();
    }

    public function test_rescan(): void
    {
        $version = $this->scBuiltVersion($this->scRegion());

        // No report yet: a full run
        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$version->project_id}/versions/{$version->id}/security/scan")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');
        $this->assertSame(['sbom', 'attach', 'scan'], ImageSecurityReport::forVersion($version)->steps);

        // While it runs
        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$version->project_id}/versions/{$version->id}/security/scan")->assertStatus(409);

        // With an SBOM: only the scan again
        ImageSecurityReport::forVersion($version)->update(['status' => 'completed', 'sbom_status' => 'ok']);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$version->project_id}/versions/{$version->id}/security/scan")->assertStatus(202);
        $this->assertSame(['scan'], ImageSecurityReport::forVersion($version)->steps);
        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 2);
    }

    public function test_rescan_of_a_code_mounted_version(): void
    {
        $version = $this->scBuiltVersion($this->scRegion(), ['build_image' => null]);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$version->project_id}/versions/{$version->id}/security/scan")->assertStatus(422);
    }

    public function test_deployment_security(): void
    {
        $version = $this->scBuiltVersion($this->scRegion());
        $this->completedReport($version);
        $deployment = $version->project->deployments()->create(['version_id' => $version->id, 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/projects/{$version->project_id}/deployments/{$deployment->id}/security")->assertOk()
            ->assertJsonPath('data.deployment_id', $deployment->id)
            ->assertJsonPath('data.version.label', 'v1')
            ->assertJsonPath('data.report.counts.critical', 1);
    }

    public function test_unauthenticated(): void
    {
        $version = $this->scBuiltVersion($this->scRegion());

        $this->getJson("/api/projects/{$version->project_id}/security")->assertUnauthorized();
    }
}
