<?php

namespace Tests\Feature\Sandboxes;

use App\Models\SandboxEvent;
use App\Services\Sandboxes\Tar\TarReader;
use App\Services\Sandboxes\Tar\TarWriter;
use Tests\Support\FakeSandboxExecTransport;

/**
 * Files of a sandbox: listing (stat output parsed), read (raw and JSON), write (raw body and JSON
 * base64, mode), delete, mkdir, directory tar download and upload (round trip through the exec
 * stdin), size limits, paths and errors.
 */
class SandboxFilesApiTest extends SandboxTestCase
{
    public function test_list_parses_the_directory(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('find "$p" -mindepth 1 -maxdepth 1', ['stdout' => implode("\n", [
            'dir',
            'regular file|12|1700000000|644|/workspace/b.txt',
            'directory|4096|1700000100|755|/workspace/src',
            'symbolic link|9|1700000200|777|/workspace/link',
            'regular empty file|0|1700000300|600|/workspace/a.txt',
        ])."\n"]);

        $data = $this->getJson("/api/sandboxes/{$row->id}/files?path=/workspace/")->assertOk()->json('data');

        $this->assertSame('/workspace', $data['path']);
        $this->assertTrue($data['is_dir']);
        $this->assertFalse($data['truncated']);
        $this->assertSame(['src', 'a.txt', 'b.txt', 'link'], array_column($data['entries'], 'name'));
        $this->assertSame(['dir', 'file', 'file', 'symlink'], array_column($data['entries'], 'type'));
        $this->assertSame(['name' => 'b.txt', 'path' => '/workspace/b.txt', 'type' => 'file', 'size' => 12, 'mode' => '644', 'modified_at' => '2023-11-14T22:13:20Z'], $data['entries'][2]);
        $this->assertSame(['runr', '/workspace', '10001'], array_slice($this->exec->calls[0]['argv'], 3));
    }

    public function test_missing_paths_answer_404(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('no such file or directory', ['stderr' => "runr: no such file or directory: /nope\n", 'exit' => 2]);

        $this->getJson("/api/sandboxes/{$row->id}/files?path=/nope")->assertNotFound()->assertJsonPath('reason', 'not_found');
    }

    public function test_read_raw_and_json(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('exec cat -- "$p"', ['stdout' => "\x89PNG\x00data"]);

        $raw = $this->get("/api/sandboxes/{$row->id}/files/content?path=/output/plot.png")->assertOk();
        $this->assertSame("\x89PNG\x00data", $raw->getContent());
        $this->assertSame('application/octet-stream', $raw->headers->get('Content-Type'));
        $this->assertSame('9', $raw->headers->get('X-RunR-File-Size'));
        $this->assertStringContainsString('plot.png', $raw->headers->get('Content-Disposition'));

        $this->getJson("/api/sandboxes/{$row->id}/files/content?path=/output/plot.png&format=json")->assertOk()
            ->assertExactJson(['data' => ['path' => '/output/plot.png', 'size' => 9, 'content_base64' => base64_encode("\x89PNG\x00data")]]);

        $this->assertTrue(SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.file_download')->exists());
    }

    public function test_read_refuses_files_above_the_download_limit(): void
    {
        config(['sandbox.files.max_download_bytes' => 100]);
        $row = $this->runningSandbox();
        $this->exec->whenContains('exec cat -- "$p"', ['stderr' => "runr: file too large: 500 bytes\n", 'exit' => 3]);

        $this->getJson("/api/sandboxes/{$row->id}/files/content?path=/workspace/big.bin")->assertStatus(413)->assertJsonPath('reason', 'file_too_large');
        $this->assertSame('100', $this->exec->calls[0]['argv'][5]);
    }

    public function test_write_raw_body_and_json(): void
    {
        $row = $this->runningSandbox();

        $this->call('PUT', "/api/sandboxes/{$row->id}/files/content?path=/workspace/new/dir/x.bin&mode=0755", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], "\x00\x01raw")
            ->assertOk()->assertJsonPath('data.size', 5)->assertJsonPath('data.path', '/workspace/new/dir/x.bin');
        $this->assertSame("\x00\x01raw", $this->exec->calls[0]['stdin']);
        $this->assertSame(['runr', '/workspace/new/dir/x.bin'], array_slice($this->exec->calls[0]['argv'], 3));
        $this->assertStringContainsString('mkdir -p -- "$d"', $this->exec->calls[0]['argv'][2]);
        $this->assertStringContainsString("chmod '0755'", $this->exec->calls[0]['argv'][2]);

        $this->putJson("/api/sandboxes/{$row->id}/files/content?path=/workspace/a.txt", ['content_base64' => base64_encode('hello')])
            ->assertOk()->assertJsonPath('data.size', 5);
        $this->assertSame('hello', $this->exec->calls[1]['stdin']);

        $this->putJson("/api/sandboxes/{$row->id}/files/content?path=/workspace/a.txt", ['content_base64' => '***'])->assertStatus(422);
        $this->putJson("/api/sandboxes/{$row->id}/files/content?path=/workspace/a.txt", ['content_base64' => '', 'mode' => '9999'])->assertStatus(422);
        $this->assertTrue(SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.file_upload')->exists());
    }

    public function test_uploads_above_the_limit_are_refused(): void
    {
        config(['sandbox.files.max_upload_bytes' => 4]);
        $row = $this->runningSandbox();

        $this->putJson("/api/sandboxes/{$row->id}/files/content?path=/workspace/a.txt", ['content_base64' => base64_encode('too long')])
            ->assertStatus(413)->assertJsonPath('reason', 'file_too_large');
        $this->assertSame([], $this->exec->calls);
    }

    public function test_delete_and_mkdir(): void
    {
        $row = $this->runningSandbox();

        $this->deleteJson("/api/sandboxes/{$row->id}/files?path=/workspace/build&recursive=1")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertStringContainsString('rm -rf --', $this->exec->calls[0]['argv'][2]);

        $this->deleteJson("/api/sandboxes/{$row->id}/files?path=/workspace/x.txt")->assertOk();
        $this->assertStringContainsString('rmdir --', $this->exec->calls[1]['argv'][2]);

        $this->deleteJson("/api/sandboxes/{$row->id}/files?path=/")->assertStatus(422);
        $this->deleteJson("/api/sandboxes/{$row->id}/files?path=/workspace/../..")->assertStatus(422);

        $this->postJson("/api/sandboxes/{$row->id}/files/mkdir", ['path' => '/workspace/a/b'])->assertOk()->assertJsonPath('data.path', '/workspace/a/b');
        $this->assertSame(['/bin/sh', '-c', 'exec mkdir -p -- "$1"', 'runr', '/workspace/a/b'], end($this->exec->calls)['argv']);
        $this->assertTrue(SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.file_delete')->exists());
    }

    public function test_paths_must_be_absolute(): void
    {
        $row = $this->runningSandbox();

        $this->getJson("/api/sandboxes/{$row->id}/files?path=workspace")->assertStatus(422);
        $this->getJson("/api/sandboxes/{$row->id}/files/content?path=")->assertStatus(422);
        $this->assertSame([], $this->exec->calls);
    }

    public function test_directory_archive_round_trip(): void
    {
        $row = $this->runningSandbox();
        $archive = (new TarWriter)->addDirectory('src')->addFile('src/main.py', "print('x')\n")->addFile('README.md', '# hi')->bytes();
        $this->exec->whenContains('exec tar -c -f - .', ['stdout' => $archive]);

        $download = $this->get("/api/sandboxes/{$row->id}/files/archive?path=/workspace")->assertOk();
        $this->assertSame('application/x-tar', $download->headers->get('Content-Type'));
        $this->assertSame(['runr', '/workspace'], array_slice($this->exec->calls[0]['argv'], 3));

        // Upload the same archive into another directory: the bytes reach tar -x unchanged
        $this->call('PUT', "/api/sandboxes/{$row->id}/files/archive?path=/workspace/copy", [], [], [], ['CONTENT_TYPE' => 'application/x-tar', 'HTTP_ACCEPT' => 'application/json'], $download->getContent())
            ->assertOk()->assertJsonPath('data.path', '/workspace/copy')->assertJsonPath('data.bytes', strlen($archive));

        $upload = $this->exec->calls[1];
        $this->assertStringContainsString("exec tar -x -m -o -f - -C '/workspace/copy'", FakeSandboxExecTransport::script($upload['argv']));
        $this->assertSame(['src/', 'src/main.py', 'README.md'], array_column(TarReader::entries($upload['stdin']), 'name'));
        $this->assertSame("print('x')\n", TarReader::entries($upload['stdin'])[1]['content']);
    }

    public function test_archives_above_the_download_limit_are_refused(): void
    {
        config(['sandbox.files.max_download_bytes' => 1024]);
        $row = $this->runningSandbox();
        $this->exec->whenContains('exec tar -c -f - .', ['stdout' => str_repeat("\0", 4096)]);

        $this->getJson("/api/sandboxes/{$row->id}/files/archive?path=/workspace")->assertStatus(413);
    }

    public function test_files_need_a_running_sandbox(): void
    {
        $row = $this->runningSandbox(['status' => 'paused']);

        $this->getJson("/api/sandboxes/{$row->id}/files?path=/workspace")->assertStatus(409);
    }
}
