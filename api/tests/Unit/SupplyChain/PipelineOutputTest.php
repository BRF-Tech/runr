<?php

namespace Tests\Unit\SupplyChain;

use App\Services\SupplyChain\PipelineOutput;
use PHPUnit\Framework\TestCase;

/**
 * The protocol lines runr-supply-chain prints (runtime/supply-chain/runr-supply-chain.sh).
 */
class PipelineOutputTest extends TestCase
{
    public static function file(string $name, string $content): string
    {
        return "RUNR_FILE {$name} ".base64_encode(gzencode($content));
    }

    public function test_steps_digest_files_and_done(): void
    {
        $log = implode("\n", [
            'some tool noise',
            'RUNR_DIGEST sha256:'.str_repeat('ab', 32),
            'RUNR_STEP sbom ok',
            'RUNR_STEP attach failed '.base64_encode("\e[31merror\e[0m: referrers API unsupported"),
            'RUNR_STEP scan ok',
            self::file('trivy', '{"SchemaVersion":2}'),
            self::file('sbom-spdx', '{"spdxVersion":"SPDX-2.3"}'),
            'RUNR_DONE',
        ]);

        $out = PipelineOutput::parse($log);

        $this->assertTrue($out->done);
        $this->assertSame('sha256:'.str_repeat('ab', 32), $out->digest);
        $this->assertTrue($out->ok('sbom'));
        $this->assertFalse($out->ok('attach'));
        $this->assertSame(['status' => 'failed', 'message' => 'error: referrers API unsupported'], $out->step('attach'));
        $this->assertSame('{"SchemaVersion":2}', $out->file('trivy'));
        $this->assertSame('{"spdxVersion":"SPDX-2.3"}', $out->file('sbom-spdx'));
        $this->assertNull($out->file('sbom-cdx'));
    }

    public function test_crlf_and_a_missing_done(): void
    {
        $out = PipelineOutput::parse("RUNR_STEP sbom ok\r\nRUNR_STEP scan failed\r\n");

        $this->assertFalse($out->done);
        $this->assertTrue($out->ok('sbom'));
        $this->assertSame('failed', $out->step('scan')['status']);
        $this->assertNull($out->step('scan')['message']);
    }

    public function test_malformed_lines_are_ignored(): void
    {
        $out = PipelineOutput::parse(implode("\n", [
            'RUNR_DIGEST sha256:short',
            'RUNR_STEP Bad-Name ok',
            'RUNR_STEP scan weird',
            'RUNR_FILE trivy not-base64!!!',
            'RUNR_FILE ../etc/passwd '.base64_encode(gzencode('x')),
            'RUNR_DONE',
        ]));

        $this->assertNull($out->digest);
        $this->assertNull($out->step('Bad-Name'));
        $this->assertSame('failed', $out->step('scan')['status'], 'an unknown status counts as failed');
        $this->assertNull($out->file('trivy'));
        $this->assertSame([], $out->files);
        $this->assertTrue($out->done);
    }

    public function test_no_log(): void
    {
        $out = PipelineOutput::parse(null);

        $this->assertFalse($out->done);
        $this->assertSame([], $out->steps);
    }
}
