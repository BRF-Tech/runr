<?php

namespace Tests\Feature\Sandboxes;

use App\Models\SandboxEvent;
use App\Services\Sandboxes\Tar\TarWriter;
use Tests\Feature\Sandboxes\SandboxExecApiTest as Sse;
use Tests\Support\FakeSandboxExecTransport;

/**
 * Code interpreter (POST /sandboxes/{id}/run): interpreter choice (language map, aliases, the
 * sandbox runtime's language, runtime plugins with an exec command), the structured result with
 * changed files and image outputs of /output, streaming, and unknown languages.
 */
class SandboxRunCodeTest extends SandboxTestCase
{
    /** Answer the run script: output, then the marker line and the changed files. */
    private function answerRun(string $stdout, array $files, int $exit = 0, string $stderr = ''): void
    {
        $this->exec->on(fn ($argv) => str_contains(FakeSandboxExecTransport::script($argv), '/tmp/.runr/code/run-'), function ($argv) use ($stdout, $files, $exit, $stderr) {
            preg_match("/printf \"\\\\n%s\\\\n\" '([^']+)'/", FakeSandboxExecTransport::script($argv), $m);
            $listing = implode('', array_map(fn ($f) => "{$f[1]}|{$f[0]}\n", $files));

            return ['chunks' => [['stdout', $stdout], ['stdout', "\n{$m[1]}\n{$listing}"], ['stderr', $stderr]], 'exit' => $exit, 'duration_ms' => 33];
        });
    }

    public function test_python_run_with_files_and_image_outputs(): void
    {
        $row = $this->runningSandbox();
        $png = "\x89PNG\r\n\x1a\nfake";
        $this->answerRun("hello\n", [['/workspace/result.csv', 20], ['/output/plot.png', strlen($png)], ['/output/notes.txt', 5]], 0, 'note');
        $this->exec->whenContains('exec tar -c -f - -- "$@"', ['stdout' => (new TarWriter)->addFile('output/plot.png', $png)->bytes()]);

        $data = $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'print("hello")', 'language' => 'python'])->assertOk()->json('data');

        $this->assertSame('python', $data['language']);
        $this->assertSame(0, $data['exit_code']);
        $this->assertSame("hello\n", $data['stdout']);
        $this->assertSame('note', $data['stderr']);
        $this->assertFalse($data['timed_out']);
        $this->assertSame([
            ['path' => '/workspace/result.csv', 'size' => 20],
            ['path' => '/output/plot.png', 'size' => strlen($png)],
            ['path' => '/output/notes.txt', 'size' => 5],
        ], $data['files_changed']);
        $this->assertSame([['path' => '/output/plot.png', 'mime' => 'image/png', 'size' => strlen($png), 'data_base64' => base64_encode($png)]], $data['outputs']);

        // The code travels on stdin; python3 first, python as the fallback
        $run = $this->exec->callsContaining('/tmp/.runr/code/run-')[0];
        $this->assertSame('print("hello")', $run['stdin']);
        $this->assertStringContainsString("command -v 'python3'", $run['argv'][2]);
        $this->assertMatchesRegularExpression("#'/tmp/\\.runr/code/run-[a-z0-9]{26}\\.py'#", $run['argv'][2]);

        // Only the image of /output was fetched
        $tar = $this->exec->callsContaining('exec tar -c -f - -- "$@"')[0];
        $this->assertSame(['runr', 'output/plot.png'], array_slice($tar['argv'], 3));

        $event = SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.run')->sole();
        $this->assertSame('python', $event->data['language']);
        $this->assertSame(3, $event->data['files_changed']);
    }

    public function test_language_aliases_and_the_sandbox_runtime_default(): void
    {
        $row = $this->runningSandbox(['runtime' => 'nodejs', 'runtime_version' => '24.14']);
        $this->answerRun('', []);

        $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'console.log(1)'])->assertOk()->assertJsonPath('data.language', 'javascript');
        $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'console.log(1)', 'language' => 'node'])->assertOk()->assertJsonPath('data.language', 'javascript');
        $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'echo 1', 'language' => 'shell'])->assertOk()->assertJsonPath('data.language', 'bash');

        $scripts = array_map(fn ($c) => $c['argv'][2], $this->exec->callsContaining('/tmp/.runr/code/run-'));
        $this->assertStringContainsString("command -v 'node'", $scripts[0]);
        $this->assertStringContainsString('.js', $scripts[0]);
        $this->assertStringContainsString("command -v 'bash'", $scripts[2]);
    }

    public function test_a_runtime_plugin_with_an_exec_command_is_a_language(): void
    {
        $this->registerRuntime($this->bashManifest(['id' => 'bash5', 'name' => 'Bash 5']));
        $row = $this->runningSandbox();
        $this->answerRun("hi\n", []);

        $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'echo hi', 'language' => 'bash5'])->assertOk()
            ->assertJsonPath('data.language', 'bash5')
            ->assertJsonPath('data.stdout', "hi\n");

        $script = $this->exec->callsContaining('/tmp/.runr/code/run-')[0]['argv'][2];
        $this->assertMatchesRegularExpression("#'bash' '/tmp/\\.runr/code/run-[a-z0-9]{26}\\.sh'#", $script);
    }

    public function test_unknown_languages_are_refused(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'x', 'language' => 'brainfork'])
            ->assertStatus(422)->assertJsonValidationErrors('language');
        $this->postJson("/api/sandboxes/{$row->id}/run", ['language' => 'python'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame([], $this->exec->calls);
    }

    public function test_failed_runs_and_timeouts(): void
    {
        $row = $this->runningSandbox();
        $this->answerRun('', [], 137);
        $this->exec->on(fn ($argv) => str_contains(FakeSandboxExecTransport::script($argv), '/tmp/.runr/code/run-'), function ($argv) {
            preg_match("/printf \"\\\\n%s\\\\n\" '([^']+)'/", FakeSandboxExecTransport::script($argv), $m);

            return ['chunks' => [['stderr', "Traceback\n"], ['stdout', "\n{$m[1]}\n"]], 'exit' => 137, 'duration_ms' => 5000];
        });

        $data = $this->postJson("/api/sandboxes/{$row->id}/run", ['code' => 'while True: pass', 'timeout' => 5])->assertOk()->json('data');

        $this->assertSame(137, $data['exit_code']);
        $this->assertTrue($data['timed_out']);
        $this->assertSame("Traceback\n", $data['stderr']);
        $this->assertSame('', $data['stdout']);
        $this->assertSame([], $data['files_changed']);
        $this->assertSame([], $data['outputs']);
    }

    public function test_streaming_run_holds_back_the_file_listing(): void
    {
        $row = $this->runningSandbox();
        $this->answerRun("line 1\nline 2\n", [['/workspace/out.txt', 3]]);

        $events = Sse::sse($this->post("/api/sandboxes/{$row->id}/run", ['code' => 'print(1)'], ['Accept' => 'text/event-stream'])->streamedContent());

        $names = array_column($events, 'event');
        $this->assertSame('start', $names[0]);
        $this->assertSame(['exit', 'result'], array_slice($names, -2));

        $streamed = implode('', array_map(fn ($e) => $e['data']['data'] ?? '', array_filter($events, fn ($e) => $e['event'] === 'stdout')));
        $this->assertSame("line 1\nline 2\n", $streamed);

        $result = end($events)['data'];
        $this->assertSame([['path' => '/workspace/out.txt', 'size' => 3]], $result['files_changed']);
        $this->assertArrayNotHasKey('stdout', $result);
    }

    public function test_exec_with_code_and_language_uses_the_interpreter(): void
    {
        $row = $this->runningSandbox();
        $this->answerRun("4\n", []);

        $this->postJson("/api/sandboxes/{$row->id}/exec", ['code' => 'print(2+2)', 'language' => 'python'])->assertOk()->assertJsonPath('data.stdout', "4\n");
    }
}
