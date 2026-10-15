<?php

namespace Tests\Unit\SupplyChain;

use App\Services\SupplyChain\TrivyReportParser;
use Tests\TestCase;

/**
 * Trivy JSON (SchemaVersion 2) → counts per severity, fixable counts, deduplicated findings
 * (most severe and fixable first), digest and OS — fixture tests/Fixtures/supply-chain/trivy-report.json.
 */
class TrivyReportParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/supply-chain/{$name}"));
    }

    public function test_counts_every_severity_once_per_package(): void
    {
        $parsed = (new TrivyReportParser)->parse($this->fixture('trivy-report.json'), 100);

        $this->assertSame(['critical' => 2, 'high' => 2, 'medium' => 2, 'low' => 1, 'unknown' => 1], $parsed['counts']);
        $this->assertSame(['critical' => 1, 'high' => 2, 'medium' => 1, 'low' => 0, 'unknown' => 0], $parsed['fixable']);
        // CVE-2024-2511 appears twice for libssl3 (two layers): counted once
        $this->assertSame(8, $parsed['total']);
        $this->assertCount(8, $parsed['findings']);
    }

    public function test_findings_are_sorted_by_severity_then_fixable_then_package(): void
    {
        $findings = (new TrivyReportParser)->parse($this->fixture('trivy-report.json'), 100)['findings'];

        $this->assertSame(
            ['CVE-2024-45491', 'CVE-2023-45853', 'CVE-2024-2511', 'CVE-2024-6345', 'GHSA-9wx4-h78v-vm56', 'CVE-2023-50495', 'CVE-2011-3374', 'TEMP-0841856-B18BAF'],
            array_column($findings, 'id'),
        );

        $first = $findings[0];
        $this->assertSame('CRITICAL', $first['severity']);
        $this->assertSame('libexpat1', $first['package']);
        $this->assertSame('2.5.0-1', $first['installed']);
        $this->assertSame('2.5.0-1+deb12u1', $first['fixed']);
        $this->assertSame('https://avd.aquasec.com/nvd/cve-2024-45491', $first['url']);
        $this->assertSame('debian', $first['type']);
        $this->assertStringContainsString('debian 12.7', $first['target']);

        $this->assertNull($findings[1]['fixed'], 'zlib1g will_not_fix has no fixed version');
        $this->assertSame('UNKNOWN', $findings[7]['severity'], 'NEGLIGIBLE maps to UNKNOWN');
    }

    public function test_advisory_links_fall_back_to_nvd_github_and_aqua(): void
    {
        $byId = collect((new TrivyReportParser)->parse($this->fixture('trivy-report.json'), 100)['findings'])->keyBy('id');

        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2023-45853', $byId['CVE-2023-45853']['url']);
        $this->assertSame('https://github.com/advisories/GHSA-9wx4-h78v-vm56', $byId['GHSA-9wx4-h78v-vm56']['url']);
        $this->assertSame('https://avd.aquasec.com/nvd/temp-0841856-b18baf', $byId['TEMP-0841856-B18BAF']['url']);
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2024-6345', $byId['CVE-2024-6345']['url']);
    }

    public function test_metadata_digest_and_os(): void
    {
        $parsed = (new TrivyReportParser)->parse($this->fixture('trivy-report.json'));

        $this->assertSame('sha256:4f2a0c3ad1b6f6c1d9a1f6b1f3f6ab0a1e0f5c3f2a1d9e8c7b6a5f4e3d2c1b0a', $parsed['digest']);
        $this->assertSame('debian 12.7', $parsed['os']);
        $this->assertStringContainsString('runr-builds/orders-api', $parsed['artifact']);
    }

    public function test_findings_are_capped_but_counts_are_not(): void
    {
        $parsed = (new TrivyReportParser)->parse($this->fixture('trivy-report.json'), 3);

        $this->assertCount(3, $parsed['findings']);
        $this->assertSame(8, $parsed['total']);
        $this->assertSame(2, $parsed['counts']['critical']);
    }

    public function test_cap_defaults_to_the_config(): void
    {
        config(['supply_chain.scan.max_findings' => 2]);

        $this->assertCount(2, (new TrivyReportParser)->parse($this->fixture('trivy-report.json'))['findings']);
    }

    public function test_a_report_without_vulnerabilities(): void
    {
        $parsed = (new TrivyReportParser)->parse(['SchemaVersion' => 2, 'Results' => [['Target' => 'x', 'Class' => 'os-pkgs']]]);

        $this->assertSame(0, $parsed['total']);
        $this->assertSame([], $parsed['findings']);
        $this->assertNull($parsed['digest']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TrivyReportParser)->parse('not json');
    }

    public function test_version_output(): void
    {
        $meta = (new TrivyReportParser)->parseVersion($this->fixture('trivy-version.json'));

        $this->assertSame('0.58.1', $meta['version']);
        $this->assertSame(2, $meta['db_version']);
        $this->assertSame('2026-10-10T06:00:00Z', $meta['db_updated_at']);
        $this->assertSame('2026-10-10T12:00:00Z', $meta['db_next_update']);
    }
}
