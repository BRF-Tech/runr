<?php

namespace Tests\Unit\Wasm;

use App\Services\Wasm\WagiSpinManifest;
use PHPUnit\Framework\TestCase;

/** The spin.toml RunR generates for WAGI functions (docs/wasm.md#wasm-exec). */
class WagiSpinManifestTest extends TestCase
{
    public function test_a_wagi_component_with_mapped_files_and_the_contract_environment(): void
    {
        $toml = WagiSpinManifest::render(
            'Q Hello',
            ['argv' => 'q -cgi /{file}', 'files' => true, 'environment' => ['GREETING' => 'hi {handler}']],
            ['main.q', 'spin.toml', 'wasm.upload', 'lib/util.q', 'main.wasm'],
            ['file' => 'main.q', 'handler' => 'handler', 'version' => '1.0'],
            ['RUNR_RUNTIME' => 'q-wasm', 'RUNR_FILENAME' => 'main.q'],
        );

        $this->assertStringContainsString("spin_manifest_version = 2\n", $toml);
        $this->assertStringContainsString("name = \"q-hello\"\n", $toml);
        $this->assertStringContainsString("route = \"/...\"\n", $toml);
        $this->assertStringContainsString('executor = { type = "wagi", argv = "q -cgi /main.q" }', $toml);
        $this->assertStringContainsString("source = \"main.wasm\"\n", $toml);
        // RunR's own files and the module are never mapped
        $this->assertStringContainsString("files = [\"lib/util.q\", \"main.q\"]\n", $toml);
        $this->assertStringContainsString('environment = { GREETING = "hi handler", RUNR_FILENAME = "main.q", RUNR_RUNTIME = "q-wasm" }', $toml);
    }

    public function test_defaults_and_escaping(): void
    {
        $toml = WagiSpinManifest::render('', ['files' => false], ['main.c'], [], []);

        $this->assertStringContainsString('executor = { type = "wagi", argv = "${SCRIPT_NAME} ${ARGS}" }', $toml);
        $this->assertStringContainsString("files = []\n", $toml);
        $this->assertStringNotContainsString('environment', $toml);
        $this->assertStringContainsString('name = "runr-function"', $toml);

        $this->assertSame('"a \\"b\\" \\\\ c\\nd\\u0001"', WagiSpinManifest::str("a \"b\" \\ c\nd\x01"));
    }
}
