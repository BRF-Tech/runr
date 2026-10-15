<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Services\Sandboxes\Tar\TarReader;
use App\Services\Sandboxes\Tar\TarWriter;
use Tests\Feature\Sandboxes\SandboxTestCase;
use Tests\Support\FakeSandboxExecTransport;

/**
 * sandbox_archive_download / sandbox_archive_upload: a directory tree in and out of a sandbox as a
 * base64 tar, through GET / PUT /api/sandboxes/{id}/files/archive (fake cluster and exec transport
 * of the Sandbox API tests), within the archive size limit.
 */
class SandboxArchiveToolsTest extends SandboxTestCase
{
    use McpToolFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $sandboxRegion = $this->region;
        $this->setUpMcpTools();
        $this->region = $sandboxRegion;
    }

    private function archive(): string
    {
        return (new TarWriter)->addDirectory('src')->addFile('src/main.py', "print('x')\n")->addFile('README.md', '# hi')->bytes();
    }

    public function test_a_directory_is_downloaded_as_base64_tar(): void
    {
        $row = $this->runningSandbox();
        $archive = $this->archive();
        $this->exec->whenContains('exec tar -c -f - .', ['stdout' => $archive]);

        $result = $this->structured($this->callTool('sandbox_archive_download', ['sandbox' => $row->id, 'path' => '/workspace']));

        $this->assertSame('tar', $result['format']);
        $this->assertSame('/workspace', $result['path']);
        $this->assertSame(strlen($archive), $result['bytes']);
        $this->assertSame(hash('sha256', $archive), $result['sha256']);
        $this->assertSame($archive, base64_decode($result['archive_base64']));
        $this->assertTrue(AuditEvent::where('action', 'sandbox.file.download')->exists(), 'audited by the route');
    }

    public function test_an_archive_above_the_tool_limit_is_refused(): void
    {
        config(['mcp.tools.max_archive_bytes' => 1024]);
        $row = $this->runningSandbox();
        // a real tar archive above the limit: a 4 KiB file
        $this->exec->whenContains('exec tar -c -f - .', ['stdout' => (new TarWriter)->addFile('big.bin', str_repeat('x', 4096))->bytes()]);

        $error = $this->mcpToolError($this->callTool('sandbox_archive_download', ['sandbox' => $row->id]));

        $this->assertSame(1024, $error['max_bytes']);
    }

    public function test_an_archive_is_uploaded_and_extracted(): void
    {
        $row = $this->runningSandbox();
        $archive = $this->archive();

        $result = $this->structured($this->callTool('sandbox_archive_upload', [
            'sandbox' => $row->id, 'path' => '/workspace/copy', 'archive_base64' => base64_encode($archive),
        ]));

        $this->assertSame('/workspace/copy', $result['path']);
        $this->assertSame(strlen($archive), $result['bytes']);

        $upload = collect($this->exec->calls)->last();
        $this->assertStringContainsString("exec tar -x -m -o -f - -C '/workspace/copy'", FakeSandboxExecTransport::script($upload['argv']));
        $this->assertSame(['src/', 'src/main.py', 'README.md'], array_column(TarReader::entries($upload['stdin']), 'name'));
        $this->assertTrue(AuditEvent::where('action', 'sandbox.file.upload')->exists());
    }

    public function test_uploads_must_be_a_tar_within_the_limit(): void
    {
        $row = $this->runningSandbox();

        $this->assertStringContainsString('base64', $this->mcpToolError($this->callTool('sandbox_archive_upload', ['sandbox' => $row->id, 'archive_base64' => '!!not base64!!']))['message']);
        $this->assertStringContainsString('tar', $this->mcpToolError($this->callTool('sandbox_archive_upload', ['sandbox' => $row->id, 'archive_base64' => base64_encode(gzencode('x'))]))['message']);

        config(['mcp.tools.max_archive_bytes' => 1024]);
        // above the limit once decoded, within the schema's maxLength (base64 of the limit + 1 KiB slack)
        $this->assertSame(1024, $this->mcpToolError($this->callTool('sandbox_archive_upload', ['sandbox' => $row->id, 'archive_base64' => base64_encode(str_repeat('a', 1500))]))['max_bytes']);

        $this->assertSchemaRejects('sandbox_archive_upload', ['sandbox' => $row->id, 'archive_base64' => base64_encode($this->archive()), 'path' => 'relative'], '/path');
        $this->assertSame([], array_filter($this->exec->calls, fn ($c) => str_contains(FakeSandboxExecTransport::script($c['argv']), 'tar -x')), 'nothing reached the sandbox');
    }

    public function test_archives_need_sandboxes_exec(): void
    {
        $row = $this->runningSandbox();

        $this->assertToolHidden('sandbox_archive_download', ['sandboxes.view'], ['sandbox' => $row->id]);
        $this->assertToolHidden('sandbox_archive_upload', ['sandboxes.view'], ['sandbox' => $row->id, 'archive_base64' => base64_encode($this->archive())]);
    }
}
