<?php

namespace Tests\Unit\SupplyChain;

use App\Services\SupplyChain\SbomSummarizer;
use Tests\TestCase;

/**
 * Summary of syft's CycloneDX / SPDX output: package count per ecosystem, top licenses, generator.
 */
class SbomSummarizerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/supply-chain/{$name}"));
    }

    public function test_cyclonedx_is_the_source_of_the_package_counts(): void
    {
        $summary = (new SbomSummarizer)->summarize($this->fixture('sbom.spdx.json'), $this->fixture('sbom.cdx.json'));

        $this->assertSame(6, $summary['packages'], 'every component, files included');
        $this->assertSame(['deb' => 3, 'pypi' => 2, 'file' => 1], $summary['by_type']);
        $this->assertEquals(['Apache-2.0' => 2, 'MIT' => 2, 'Zlib' => 1], $summary['licenses']);
        $this->assertSame('syft-1.18.1', $summary['generator'], 'SPDX creator wins');
        $this->assertSame('SPDX-2.3', $summary['spdx_version']);
        $this->assertSame('1.6', $summary['cyclonedx_spec']);
        $this->assertSame(['spdx-json', 'cyclonedx-json'], $summary['formats']);
    }

    public function test_spdx_alone_skips_the_image_root_package(): void
    {
        $summary = (new SbomSummarizer)->summarize($this->fixture('sbom.spdx.json'), null);

        $this->assertSame(2, $summary['packages']);
        $this->assertSame(['deb' => 1, 'pypi' => 1], $summary['by_type']);
        $this->assertEquals(['MIT' => 1, 'Apache-2.0' => 1], $summary['licenses'], 'licenseDeclared when licenseConcluded is NOASSERTION');
        $this->assertSame(['spdx-json'], $summary['formats']);
    }

    public function test_cyclonedx_generator_from_metadata_tools(): void
    {
        $summary = (new SbomSummarizer)->summarize(null, $this->fixture('sbom.cdx.json'));

        $this->assertSame('syft-1.18.1', $summary['generator']);
        $this->assertNull($summary['spdx_version']);
    }

    public function test_nothing_readable(): void
    {
        $summary = (new SbomSummarizer)->summarize('{', null);

        $this->assertSame(0, $summary['packages']);
        $this->assertSame([], $summary['formats']);
    }
}
