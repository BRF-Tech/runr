<?php

namespace Tests\Feature\Sandboxes;

use App\Models\SandboxProcess;
use Tests\Feature\Sandboxes\SandboxExecApiTest as Sse;
use Tests\Support\FakeSandboxExecTransport;

/**
 * Background processes: start (detached, pid), state refresh from /proc (running, exited with
 * code, killed), logs (tail split at the marker), follow over SSE, signals, termination of the
 * sandbox marking them killed.
 */
class SandboxProcessesApiTest extends SandboxTestCase
{
    public function test_start_list_logs_and_kill(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('nohup', ['stdout' => "4242\n"]);

        $process = $this->postJson("/api/sandboxes/{$row->id}/processes", ['cmd' => ['python3', '-m', 'http.server', '8000'], 'env' => ['X' => '1']])
            ->assertCreated()->json('data');

        $this->assertSame(4242, $process['pid']);
        $this->assertSame('running', $process['status']);
        $this->assertSame('python3 -m http.server 8000', $process['command']);
        $this->assertSame('/workspace', $process['cwd']);
        $start = $this->exec->callsContaining('nohup')[0];
        $this->assertSame(['python3', '-m', 'http.server', '8000'], FakeSandboxExecTransport::userArgv($start['argv']));
        $this->assertStringContainsString('/tmp/.runr/proc/'.strtolower($process['id']), $start['argv'][2]);

        // Still running
        $this->exec->whenContains('/proc/$pid/stat', fn ($argv) => ['stdout' => "{$process['id']}|running|\n"]);
        $this->getJson("/api/sandboxes/{$row->id}/processes")->assertOk()->assertJsonPath('data.0.status', 'running');

        // Logs: stdout and stderr around the marker
        $this->exec->whenContains('tail -c 1024', function ($argv) {
            preg_match("/printf \"\\\\n%s\\\\n\" '([^']+)'/", $argv[2], $m);

            return ['stdout' => "Serving HTTP\n\n{$m[1]}\n127.0.0.1 GET /\n"];
        });
        $this->getJson("/api/sandboxes/{$row->id}/processes/{$process['id']}/logs?tail_bytes=1024")->assertOk()
            ->assertJsonPath('data.stdout', "Serving HTTP\n")
            ->assertJsonPath('data.stderr', "127.0.0.1 GET /\n");

        // Kill: the signal goes to the process group, then the state is read again
        $killed = false;
        $this->exec->whenContains('kill -KILL -4242', function () use (&$killed) {
            $killed = true;

            return ['exit' => 0];
        });
        $this->exec->whenContains('/proc/$pid/stat', function () use (&$killed, $process) {
            return ['stdout' => "{$process['id']}|".($killed ? 'ended' : 'running')."|\n"];
        });
        $this->deleteJson("/api/sandboxes/{$row->id}/processes/{$process['id']}?signal=KILL")->assertOk()
            ->assertJsonPath('data.status', 'killed')
            ->assertJsonPath('data.exit_code', null);
        $this->assertCount(1, $this->exec->callsContaining('kill -KILL -4242'));
    }

    public function test_exit_codes_are_read_from_the_sandbox(): void
    {
        $row = $this->runningSandbox();
        $proc = SandboxProcess::create(['sandbox_id' => $row->id, 'pid' => 77, 'command' => 'make', 'status' => 'running', 'started_at' => now()]);
        $this->exec->whenContains('/proc/$pid/stat', ['stdout' => "{$proc->id}|ended|2\n"]);

        $this->getJson("/api/sandboxes/{$row->id}/processes/{$proc->id}")->assertOk()
            ->assertJsonPath('data.status', 'exited')
            ->assertJsonPath('data.exit_code', 2);

        // An ended process is not asked for again
        $before = count($this->exec->calls);
        $this->getJson("/api/sandboxes/{$row->id}/processes")->assertOk();
        $this->assertCount($before, $this->exec->calls);
    }

    public function test_follow_streams_the_output_until_the_process_ends(): void
    {
        $row = $this->runningSandbox();
        $proc = SandboxProcess::create(['sandbox_id' => $row->id, 'pid' => 9, 'command' => 'train.py', 'status' => 'running', 'started_at' => now()]);
        $this->exec->whenContains('/proc/$pid/stat', ['stdout' => "{$proc->id}|ended|0\n"]);
        // registered last, so it answers first: the follow script reads /proc/$pid/stat too
        $this->exec->whenContains('tail -c +1 -f', ['chunks' => [['stdout', "epoch 1\n"], ['stderr', "lr=0.1\n"], ['stdout', "epoch 2\n"]], 'exit' => 0]);

        $events = Sse::sse($this->get("/api/sandboxes/{$row->id}/processes/{$proc->id}/logs?follow=1")->streamedContent());

        $this->assertSame(['start', 'stdout', 'stderr', 'stdout', 'exit'], array_column($events, 'event'));
        $this->assertSame(['exit_code' => 0, 'status' => 'exited'], end($events)['data']);
    }

    public function test_validation_and_unknown_processes(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/processes", [])->assertStatus(422);
        $this->postJson("/api/sandboxes/{$row->id}/processes", ['cmd' => ''])->assertStatus(422);
        $this->getJson("/api/sandboxes/{$row->id}/processes/01JB0000000000000000000000")->assertNotFound();

        $proc = SandboxProcess::create(['sandbox_id' => $row->id, 'pid' => 5, 'command' => 'x', 'status' => 'running', 'started_at' => now()]);
        $this->exec->whenContains('/proc/$pid/stat', ['stdout' => "{$proc->id}|running|\n"]);
        $this->deleteJson("/api/sandboxes/{$row->id}/processes/{$proc->id}?signal=NUKE")->assertStatus(422);

        // A process of another sandbox is not reachable through this one
        $other = $this->runningSandbox();
        $this->getJson("/api/sandboxes/{$other->id}/processes/{$proc->id}")->assertNotFound();
    }

    public function test_a_failed_start_is_reported(): void
    {
        $row = $this->runningSandbox();
        $this->exec->whenContains('nohup', ['stderr' => 'mkdir: read-only file system', 'exit' => 1]);

        $this->postJson("/api/sandboxes/{$row->id}/processes", ['cmd' => 'sleep 100'])->assertStatus(422)->assertJsonPath('reason', 'process_failed');
        $this->assertSame(0, SandboxProcess::count());
    }

    public function test_terminating_the_sandbox_marks_its_processes_killed(): void
    {
        $row = $this->runningSandbox();
        $proc = SandboxProcess::create(['sandbox_id' => $row->id, 'pid' => 5, 'command' => 'x', 'status' => 'running', 'started_at' => now()]);

        $this->deleteJson("/api/sandboxes/{$row->id}")->assertOk();

        $this->assertSame('killed', $proc->fresh()->status);
        $this->assertNotNull($proc->fresh()->ended_at);
    }
}
