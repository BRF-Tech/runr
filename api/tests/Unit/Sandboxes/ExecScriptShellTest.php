<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\MarkerFilter;
use App\Services\Sandboxes\SandboxManifests;
use App\Services\Sandboxes\Tar\TarWriter;
use PHPUnit\Framework\TestCase;

/**
 * The directory parts of the sandbox scripts, run under the host's /bin/sh (busybox ash in the API
 * image; dash or bash elsewhere, as in the sandbox images): what they create, and that their fast
 * paths start no mkdir process. A `mkdir` first on PATH records every call before it runs the real
 * one. On a Kata or gVisor sandbox one process start costs up to ~25 ms.
 */
class ExecScriptShellTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs /bin/sh');
        }
        $this->dir = sys_get_temp_dir().'/runr-execscript-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin', 0700, true);
        $real = trim((string) shell_exec('command -v mkdir'));
        file_put_contents($this->dir.'/bin/mkdir', "#!/bin/sh\necho \"\$*\" >> '{$this->dir}/mkdir.log'\nexec {$real} \"\$@\"\n");
        chmod($this->dir.'/bin/mkdir', 0700);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function test_the_pid_file_needs_mkdir_only_when_its_directory_is_missing(): void
    {
        $file = $this->dir.'/exec/01jbexec.pid';

        // A pod started by 0.6.6 or earlier: no directory yet, the fallback creates it
        [$code, $out, $err] = $this->sh(ExecScript::writePid($file).'; echo next');
        $this->assertSame([0, "next\n", ''], [$code, $out, $err]);
        $this->assertMatchesRegularExpression('/^\d+\n$/', (string) file_get_contents($file));
        $this->assertSame(1, $this->mkdirCalls());

        // A pod whose KEEPALIVE created it (or after the first command): no mkdir
        [$code, $out, $err] = $this->sh(ExecScript::writePid($file).'; echo next');
        $this->assertSame([0, "next\n", ''], [$code, $out, $err]);
        $this->assertSame(1, $this->mkdirCalls());

        // A directory that cannot be created: silent, and the command still runs
        [$code, $out, $err] = $this->sh(ExecScript::writePid('/proc/runr-none/x.pid').'; echo next');
        $this->assertSame([0, "next\n", ''], [$code, $out, $err]);
    }

    public function test_ensure_dir_needs_mkdir_only_when_the_directory_is_missing(): void
    {
        $code = $this->dir.'/code';

        $this->assertSame(0, $this->sh(ExecScript::ensureDir($code).' || exit 1')[0]);
        $this->assertDirectoryExists($code);
        $this->assertSame(1, $this->mkdirCalls());

        $this->assertSame(0, $this->sh(ExecScript::ensureDir($code).' || exit 1')[0]);
        $this->assertSame(1, $this->mkdirCalls());

        $this->assertNotSame(0, $this->sh(ExecScript::ensureDir('/proc/runr-none').' || exit 1')[0]);
    }

    public function test_a_stored_input_becomes_the_command_stdin_and_is_removed(): void
    {
        $file = $this->dir.'/42-x.in';
        // storeInput: the stdin to the file, the directory created when missing
        $store = ExecScript::storeInput($this->dir.'/sub/42-y.in', 7);
        $this->assertSame(0, $this->runArgv($store, "stored\n")[0]);
        $this->assertSame("stored\n", file_get_contents($this->dir.'/sub/42-y.in'));

        // Input that arrives short fails, and what came is removed
        [$code, , $err] = $this->runArgv(ExecScript::storeInput($this->dir.'/sub/42-z.in', 100), "short\n");
        $this->assertSame(1, $code);
        $this->assertSame("runr: the input ended after 6 of 100 bytes\n", $err);
        $this->assertFileDoesNotExist($this->dir.'/sub/42-z.in');

        file_put_contents($file, "line 1\nline 2\n");
        $argv = ExecScript::command(['/bin/sh', '-c', 'cat; echo after-eof'], [], $this->dir, 10, 0, null, false, $file);
        [$code, $out, $err] = $this->runArgv($argv);
        $this->assertSame([0, "line 1\nline 2\nafter-eof\n", ''], [$code, $out, $err]);
        $this->assertFileDoesNotExist($file);

        // Gone (a store that failed): the wrapper says so
        [$code, , $err] = $this->runArgv($argv);
        $this->assertSame(ExecScript::EXIT_NO_INPUT, $code);
        $this->assertSame("runr: the command input is missing\n", $err);
    }

    public function test_stale_inputs_are_removed_by_a_later_command(): void
    {
        $exec = $this->dir.'/exec';
        mkdir($exec);
        $now = time();
        foreach ([($now - 7200).'-old.in', ($now - 60).'-new.in', 'odd.in', ($now - 7200).'-x.pid'] as $name) {
            touch("{$exec}/{$name}");
        }
        $line = str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::removeStaleInputs($now - 3600));

        $this->assertSame(0, $this->sh($line.'; true')[0]);
        $left = array_map('basename', glob("{$exec}/*"));
        sort($left);
        $this->assertSame([($now - 7200).'-x.pid', ($now - 60).'-new.in', 'odd.in'], $left);
        // An empty directory: the glob stays a pattern, nothing runs
        exec('rm -f '.escapeshellarg($exec).'/*');
        $this->assertSame([0, '', ''], $this->sh($line.'; true'));
    }

    public function test_framed_input_keeps_the_exec_stdin_open_and_marks_the_end(): void
    {
        $exec = $this->dir.'/exec';
        $script = str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::command(['/bin/sh', '-c', 'cat; echo after-eof; exit 3'], [], $this->dir, 10, 0, '01JBWS', false, null, true)[2]);

        [$code, $out, $err] = $this->interactive(['/bin/sh', '-c', $script, 'runr', '/bin/sh', '-c', 'cat; echo after-eof; exit 3'], ["3\nabc", "4\ndefg", "0\n"]);

        $this->assertSame("abcdefgafter-eof\n".ExecScript::endMarker('01JBWS'), $out);
        $this->assertSame("\x1erunr-exit:01jbws:3\n", $err);
        $this->assertSame(3, $code);
        $this->assertMatchesRegularExpression('/^\d+\n$/', (string) file_get_contents("{$exec}/01jbws.pid"));
    }

    public function test_a_kill_through_the_pid_file_reaches_the_framed_command(): void
    {
        $exec = $this->dir.'/exec';
        $script = str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::command(['sleep', '30'], [], $this->dir, 60, 0, '01JBWS', false, null, true)[2]);
        $kill = str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::killExec('01JBWS')[2]);

        $started = microtime(true);
        [$code, , $err] = $this->interactive(['/bin/sh', '-c', $script, 'runr', 'sleep', '30'], [], function () use ($exec, $kill) {
            if (is_file("{$exec}/01jbws.pid")) {
                exec('/bin/sh -c '.escapeshellarg($kill));

                return true;
            }

            return false;
        });

        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertSame("\x1erunr-exit:01jbws:137\n", $err);
        $this->assertSame(137, $code);
    }

    public function test_a_framed_command_that_cannot_start_still_marks_its_end(): void
    {
        // kubectl's stdin stays open: without the marker the session would wait for its timeout
        $script = ExecScript::command(['cat'], [], $this->dir.'/missing', 10, 0, '01JBWS', false, null, true)[2];

        $started = microtime(true);
        [$code, $out, $err] = $this->interactive(['/bin/sh', '-c', $script, 'runr', 'cat'], []);

        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame(ExecScript::EXIT_NO_CWD, $code);
        $this->assertSame("\x1erunr-end:01jbws\n", $out);
        $this->assertStringEndsWith("\x1erunr-exit:01jbws:".ExecScript::EXIT_NO_CWD."\n", $err);
        $this->assertStringContainsString('does not exist', $err);
    }

    public function test_uploads_without_head_read_to_the_end_of_stdin(): void
    {
        // A custom image needs /bin/sh and tar only: no head on PATH
        $bin = $this->dir.'/nohead';
        mkdir($bin);
        foreach (['cat', 'dirname', 'mkdir', 'tar', 'chmod', 'rm'] as $tool) {
            symlink(trim((string) shell_exec('command -v '.$tool)), "{$bin}/{$tool}");
        }
        $run = function (array $argv, string $stdin) use ($bin): int {
            $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, ['PATH' => $bin]);
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $this->assertSame('', stream_get_contents($pipes[2]));

            return proc_close($proc);
        };

        $this->assertSame(0, $run(ExecScript::writeFile($this->dir.'/up/x.bin', 5, '0600'), 'hello'));
        $this->assertSame('hello', file_get_contents($this->dir.'/up/x.bin'));

        $tar = (new TarWriter)->addFile('a.txt', "one\n", 0644)->bytes();
        $this->assertSame(0, $run(ExecScript::extractTarAt($this->dir.'/t', strlen($tar)), $tar));
        $this->assertSame("one\n", file_get_contents($this->dir.'/t/a.txt'));
    }

    public function test_short_uploads_fail_with_a_message(): void
    {
        [$code, , $err] = $this->runArgv(ExecScript::writeFile($this->dir.'/w/x.bin', 1000, '0600'), str_repeat('a', 400));
        $this->assertSame(1, $code);
        $this->assertSame("runr: the upload ended after 400 of 1000 bytes\n", $err);
        $this->assertFileDoesNotExist($this->dir.'/w/x.bin');

        // An archive cut at a member's end: tar would take it, the size check does not
        $tar = (new TarWriter)->addFile('a.txt', str_repeat('a', 512), 0644)->addFile('b.txt', "two\n", 0644)->bytes();
        $script = str_replace(SandboxManifests::EXEC_DIR, $this->dir.'/exec', ExecScript::extractTarAt($this->dir.'/t', strlen($tar))[2]);
        [$code, , $err] = $this->sh($script, substr($tar, 0, 1024));
        $this->assertSame(1, $code);
        $this->assertSame('runr: the archive ended after 1024 of '.strlen($tar)." bytes\n", $err);
        $this->assertFileDoesNotExist($this->dir.'/t/a.txt');
        $this->assertSame([], glob($this->dir.'/exec/*.in'));

        // Whole: extracted, the staged archive removed
        [$code, , $err] = $this->sh($script, $tar);
        $this->assertSame(0, $code, $err);
        $this->assertSame("two\n", file_get_contents($this->dir.'/t/b.txt'));
        $this->assertSame([], glob($this->dir.'/exec/*.in'));
    }

    public function test_relayed_output_keeps_streams_exit_code_kill_and_timeout(): void
    {
        $exec = $this->dir.'/exec';
        mkdir($exec);
        $wrap = fn (array $cmd, int $timeout = 10, ?string $id = '01JBRL') => array_merge(['/bin/sh', '-c', str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::command($cmd, [], $this->dir, $timeout, 0, $id, false, null, false, true)[2]), 'runr'], $cmd);

        // Both streams whole and apart, the exit code the command's, the FIFOs gone
        [$code, $out, $err] = $this->runArgv($wrap(['/bin/sh', '-c', 'head -c 300000 /dev/zero; echo err >&2; exit 3']));
        $this->assertSame([3, 300000, "err\n"], [$code, strlen($out), $err]);
        $this->assertMatchesRegularExpression('/^\d+\n$/', (string) file_get_contents($exec.'/01jbrl.pid'));

        // A stored input is the command's stdin
        file_put_contents($this->dir.'/1-x.in', "line\n");
        $argv = ['/bin/sh', '-c', str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::command(['cat'], [], $this->dir, 10, 0, null, false, $this->dir.'/1-x.in', false, true)[2]), 'runr', 'cat'];
        $this->assertSame([0, "line\n", ''], $this->runArgv($argv));

        // Timeout: 137 from the command, not from the relays
        $started = microtime(true);
        [$code] = $this->runArgv($wrap(['sleep', '30'], 1));
        $this->assertSame(137, $code);
        $this->assertLessThan(10, microtime(true) - $started);

        // Kill through the PID file: the command goes, the wrapper ends with 137
        $kill = str_replace(SandboxManifests::EXEC_DIR, $exec, ExecScript::killExec('01JBKL')[2]);
        if (is_file($exec.'/01jbkl.pid')) {
            unlink($exec.'/01jbkl.pid');
        }
        $proc = proc_open($wrap(['sleep', '30'], 60, '01JBKL'), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        $until = microtime(true) + 10;
        while (! is_file($exec.'/01jbkl.pid') && microtime(true) < $until) {
            usleep(20_000);
        }
        usleep(100_000);
        exec('/bin/sh -c '.escapeshellarg($kill));
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $this->assertSame(137, proc_close($proc));
        $this->assertLessThan(10, microtime(true) - $until + 10);
    }

    public function test_acknowledged_input_reaches_the_script_whole(): void
    {
        $data = random_bytes(150000);
        $argv = ExecScript::ackedInput(ExecScript::writeFile($this->dir.'/acked/x.bin', strlen($data)), 'ab12');

        [$code, , $err, $acks] = $this->acked($argv, 'ab12', $data, 4096);
        $this->assertSame(0, $code, $err);
        $this->assertSame($data, file_get_contents($this->dir.'/acked/x.bin'));
        $this->assertSame((int) ceil(strlen($data) / 4096), $acks);
        $this->assertSame('', $err);

        // The script fails early: the reader stops, the exit code is the script's
        [$code, , , $acks] = $this->acked(ExecScript::ackedInput(['/bin/sh', '-c', 'exit 3'], 'ab12'), 'ab12', $data, 4096);
        $this->assertSame(3, $code);
        $this->assertLessThan(strlen($data) / 4096, $acks);

        // The script's own stderr, 0x06, 0x04 and a marker of another id included, passes byte for byte
        $own = "a\x06b\x04c\x1erunr-ack:other\nd\x1e";
        $script = ['/bin/sh', '-c', 'cat > /dev/null; printf \'a\006b\004c\036runr-ack:other\nd\036\' >&2'];
        [$code, , $err, $acks] = $this->acked(ExecScript::ackedInput($script, 'ab12'), 'ab12', str_repeat('x', 10000), 4096);
        $this->assertSame([0, $own, 3], [$code, $err, $acks]);
    }

    public function test_tar_extraction_starts_one_mkdir_for_missing_directories_and_none_otherwise(): void
    {
        $root = $this->dir.'/root';
        mkdir($root);
        mkdir($this->dir.'/exec');
        $tar = (new TarWriter)->addFile('a/b/x.txt', "one\n", 0644)->addFile('c/y.txt', "two\n", 0600)->bytes();

        $at = fn (string $dir, array $mkdirs = []) => str_replace(SandboxManifests::EXEC_DIR, $this->dir.'/exec', ExecScript::extractTarAt($dir, strlen($tar), $mkdirs)[2]);
        [$code, , $err] = $this->sh($at($root, [$root.'/a/b', $root.'/c', $root.'/a/b']), $tar);
        $this->assertSame(0, $code, $err);
        $this->assertSame("one\n", file_get_contents($root.'/a/b/x.txt'));
        $this->assertSame("two\n", file_get_contents($root.'/c/y.txt'));
        $this->assertSame(1, $this->mkdirCalls());

        // Everything there already (a claimed warm pod's env directory): tar alone
        [$code, , $err] = $this->sh($at($root, [$root.'/a/b', $root.'/c']), $tar);
        $this->assertSame(0, $code, $err);
        $this->assertSame(1, $this->mkdirCalls());

        // A directory that cannot be created stops the script before tar (set -e)
        $this->assertNotSame(0, $this->sh($at($root, ['/proc/runr-none']), $tar)[0]);

        // Exactly the archive's bytes: what follows them on stdin is not read
        [$code, , $err] = $this->sh($at($root.'/d'), $tar.'trailing bytes');
        $this->assertSame(0, $code, $err);
        $this->assertSame("one\n", file_get_contents($root.'/d/a/b/x.txt'));
    }

    /**
     * Runs $argv (ExecScript::ackedInput with $id) the way the transport feeds it: a frame of $data,
     * the next once its acknowledgement came, then "0\n"; stdin closes at the end marker.
     *
     * @return array{0: int, 1: string, 2: string, 3: int} exit code, stdout, stderr without the acks, acks
     */
    private function acked(array $argv, string $id, string $data, int $frame): array
    {
        $filter = new MarkerFilter([ExecScript::ackMarker($id), ExecScript::ackEndMarker($id)]);
        $env = ['PATH' => $this->dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $offset = 0;
        $acks = 0;
        $out = $err = '';
        $open = true;
        $send = function () use (&$offset, &$open, $data, $frame, $pipes) {
            if (! $open) {
                return;
            }
            if ($offset < strlen($data)) {
                $piece = substr($data, $offset, $frame);
                $offset += strlen($piece);
                @fwrite($pipes[0], strlen($piece)."\n".$piece);
            } else {
                @fwrite($pipes[0], "0\n");
                fclose($pipes[0]);
                $open = false;
            }
        };
        $send();
        $until = microtime(true) + 20;
        while (microtime(true) < $until) {
            $out .= (string) stream_get_contents($pipes[1]);
            [$chunk, $found] = $filter->push((string) stream_get_contents($pipes[2]));
            $err .= $chunk;
            $ended = false;
            foreach ($found as $marker) {
                if ($marker === ExecScript::ackMarker($id)) {
                    $acks++;
                    $send();
                } else {
                    $ended = true;
                }
            }
            if ($ended) {
                if ($open) {
                    fclose($pipes[0]);
                    $open = false;
                }
                break;
            }
            usleep(2_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= $filter->push((string) stream_get_contents($pipes[2]))[0].$filter->flush();
        if ($open) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err, $acks];
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function runArgv(array $argv, string $stdin = ''): array
    {
        $env = ['PATH' => $this->dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    /**
     * Runs $argv with its stdin kept open (as kubectl's is in a framed session): writes $frames,
     * calls $step every 20 ms until it returns true, closes stdin only once the exit marker came on
     * stderr — or after 15 s.
     *
     * @param  list<string>  $frames
     * @return array{0: int, 1: string, 2: string}
     */
    private function interactive(array $argv, array $frames, ?callable $step = null): array
    {
        $env = ['PATH' => $this->dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        foreach ($frames as $frame) {
            fwrite($pipes[0], $frame);
            usleep(50_000);
        }
        $out = $err = '';
        $stepped = $step === null;
        $until = microtime(true) + 15;
        while (microtime(true) < $until && ! str_contains($err, "\x1erunr-exit:")) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            if (! $stepped) {
                $stepped = $step();
            }
            usleep(20_000);
        }
        fclose($pipes[0]);   // what ending kubectl does once the marker came
        // Until the wrapper ended (a process it left behind may keep the pipes open)
        $code = -1;
        $until = microtime(true) + 10;
        while (microtime(true) < $until) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (! $status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            usleep(20_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [$code, $out, $err];
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function sh(string $script, string $stdin = ''): array
    {
        $env = ['PATH' => $this->dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')];
        $proc = proc_open(['/bin/sh', '-c', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function mkdirCalls(): int
    {
        $log = $this->dir.'/mkdir.log';

        return is_file($log) ? count(file($log) ?: []) : 0;
    }
}
