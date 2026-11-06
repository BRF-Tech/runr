<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\KubectlCommand;
use App\Services\Sandboxes\Exec\MarkerFilter;
use App\Services\Sandboxes\Exec\PodTarget;
use App\Services\Sandboxes\Exec\TrailerFilter;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\Sizes;
use Tests\TestCase;

/**
 * The shell wrappers of sandbox commands (env, cwd, PID limit, timeout, quoting), the kubectl
 * invocation, kubectl's exit trailer filter and the size parsers.
 */
class ExecScriptTest extends TestCase
{
    public function test_argv_commands_run_through_the_wrapper_unchanged(): void
    {
        $argv = ExecScript::command(['python3', '-c', 'print("hi")'], ['A' => '1'], '/workspace/src', 30, 256, '01JBEXEC');

        $this->assertSame(['/bin/sh', '-c'], array_slice($argv, 0, 2));
        $this->assertSame('runr', $argv[3]);
        $this->assertSame(['python3', '-c', 'print("hi")'], array_slice($argv, 4));

        $script = $argv[2];
        $this->assertStringContainsString("[ -f '/home/sandbox/.runr/env.sh' ] && . '/home/sandbox/.runr/env.sh'", $script);
        $this->assertStringContainsString("export A='1'", $script);
        $this->assertStringContainsString("cd '/workspace/src' 2>/dev/null ||", $script);
        $this->assertStringContainsString('exit '.ExecScript::EXIT_NO_CWD, $script);
        $this->assertStringContainsString('ulimit -u 256 2>/dev/null || ulimit -p 256', $script);
        $this->assertStringContainsString('echo $$ > /tmp/.runr/exec/01jbexec.pid', $script);
        // The PID file is written first; mkdir only runs when that fails (a pod without the directory)
        $this->assertStringContainsString('{ echo $$ > /tmp/.runr/exec/01jbexec.pid; } 2>/dev/null || { mkdir -p /tmp/.runr/exec && echo $$ > /tmp/.runr/exec/01jbexec.pid; } 2>/dev/null', $script);
        $this->assertLessThan(strpos($script, 'mkdir'), strpos($script, 'echo $$'));
        $this->assertSame(ExecScript::pidFile('01JBEXEC'), '/tmp/.runr/exec/01jbexec.pid');
        $this->assertStringContainsString(ExecScript::pidFile('01JBEXEC'), ExecScript::killExec('01JBEXEC')[2]);
        $this->assertStringContainsString('exec timeout -s KILL 30 "$@"', $script);
        $this->assertStringEndsWith('exec "$@"', $script);
    }

    public function test_input_is_stored_by_an_exec_of_its_own_and_read_from_the_file(): void
    {
        $file = ExecScript::inputFile('01JBEXEC', 1800000000);
        $this->assertSame('/tmp/.runr/exec/1800000000-01jbexec.in', $file);
        $store = ExecScript::storeInput($file, 7);
        $this->assertSame(['runr', $file], array_slice($store, 3));
        $this->assertStringStartsWith("{ [ -d '/tmp/.runr/exec' ] || mkdir -p -- '/tmp/.runr/exec'; } || exit 1\n", $store[2]);
        // Exactly its bytes, and their number checked (ExecScriptShellTest runs it)
        $this->assertStringContainsString("exec 5> \"\$1\"\nhead -c 7 >&5\n", $store[2]);
        $this->assertStringContainsString('[ $runr_pos -eq 7 ] || { echo "runr: the input ended after $(($runr_pos)) of 7 bytes" >&2; rm -f "$1"; exit 1; }', $store[2]);

        $script = ExecScript::command(['cat'], [], null, 30, 0, '01JBEXEC', false, $file)[2];
        $this->assertStringContainsString("[ -f '{$file}' ] || { echo \"runr: the command input is missing\" >&2; exit ".ExecScript::EXIT_NO_INPUT.'; }', $script);
        $this->assertStringContainsString("exec 0< '{$file}'; rm -f '{$file}'", $script);
        // The input is taken after the PID file and before timeout: PID, kill and exit code as before
        $this->assertLessThan(strpos($script, 'exec 0<'), strpos($script, 'echo $$ > /tmp/.runr/exec/01jbexec.pid'));
        $this->assertLessThan(strpos($script, 'exec timeout'), strpos($script, 'exec 0<'));
        $this->assertStringNotContainsString('exec 0<', ExecScript::command(['cat'], [], null, 30, 0, '01JBEXEC')[2]);

        // Inputs older than an hour (their command never started) go with the next command
        $this->assertStringContainsString(ExecScript::removeStaleInputs(time() - ExecScript::STALE_INPUT_SECONDS), $script);
    }

    public function test_acknowledged_input_wraps_the_script(): void
    {
        $argv = ExecScript::ackedInput(['/bin/sh', '-c', 'head -c 3 > "$1"', 'runr', '/workspace/x'], 'AB12');

        $this->assertSame(['/bin/sh', '-c', 'head -c 3 > "$1"', 'runr', '/workspace/x'], array_slice($argv, 4));
        $this->assertSame('runr', $argv[3]);
        $this->assertStringContainsString('runr_take "$runr_n" 2>/dev/null || exit 1; printf \'\036runr-ack:%s\n\' ab12 >&2; done; }', $argv[2]);
        $this->assertStringContainsString('runr_rd | "$@"; runr_rc=$?', $argv[2]);
        $this->assertStringEndsWith("printf '\\036runr-ack-end:%s\\n' ab12 >&2\nexit \$runr_rc", $argv[2]);
        // Markers with the input's id: a command's own bytes on stderr are never taken for one
        $this->assertSame("\x1erunr-ack:ab12\n", ExecScript::ackMarker('AB12'));
        $this->assertSame("\x1erunr-ack-end:ab12\n", ExecScript::ackEndMarker('AB12'));
        $this->assertLessThan(65536 - 16, ExecScript::ACKED_FRAME_BYTES);
    }

    public function test_marker_filter_takes_whole_markers_only(): void
    {
        $f = new MarkerFilter(["\x1erunr-ack:ab\n", "\x1erunr-ack-end:ab\n"]);
        // Split anywhere: held back until the next chunk shows a marker
        $out = '';
        $found = [];
        foreach (["x\x06y\x1erunr-a", 'ck:ab', "\nz\x1e", "runr-ack-end:ab\n\x04"] as $chunk) {
            [$data, $markers] = $f->push($chunk);
            $out .= $data;
            $found = array_merge($found, $markers);
        }
        $out .= $f->flush();
        $this->assertSame("x\x06yz\x04", $out);
        $this->assertSame(["\x1erunr-ack:ab\n", "\x1erunr-ack-end:ab\n"], $found);

        // Another id, a cut marker at the end: passed on as they came
        $f = new MarkerFilter(["\x1erunr-ack:ab\n"]);
        [$data, $markers] = $f->push("\x1erunr-ack:cd\n\x1erunr-ack:a");
        $this->assertSame(["\x1erunr-ack:cd\n", []], [$data, $markers]);
        $this->assertSame("\x1erunr-ack:a", $f->flush());
    }

    public function test_relayed_output_runs_the_command_in_the_background(): void
    {
        $script = ExecScript::command(['python3', 'x.py'], [], null, 30, 0, '01JBEXEC', false, null, false, true)[2];

        // The PID file records the background command (timeout), not the shell
        $this->assertStringNotContainsString('echo $$ >', $script);
        $this->assertStringContainsString('{ echo $! > /tmp/.runr/exec/01jbexec.pid; }', $script);
        $this->assertStringContainsString('exec 3</dev/null', $script);
        // Two cats behind anonymous pipes. With pipefail the command's group records its own PID and
        // execs timeout, the pipelines' status is the exit code
        $this->assertStringContainsString('if command -v cat >/dev/null 2>&1; then exec 6>&1 7>&2; if command set -o pipefail 2>/dev/null; then { { { read -r runr_me _ < /proc/self/stat; { echo $runr_me > /tmp/.runr/exec/01jbexec.pid; }', $script);
        $this->assertStringContainsString('if command -v timeout >/dev/null 2>&1; then exec timeout -s KILL 30 "$@" <&3 3<&- 6>&- 7>&-; else exec "$@" <&3 3<&- 6>&- 7>&-; fi; } | cat >&6 6>&- 7>&-; } 2>&1 | cat >&7 6>&- 7>&-; }; runr_rc=$?; else ', $script);
        // Without pipefail: in the background of that group, the exit code over the substitution's pipe (fd 8)
        $this->assertStringContainsString('else runr_rc=$( { { { if command -v timeout >/dev/null 2>&1; then timeout -s KILL 30 "$@" <&3 3<&- 6>&- 7>&- 8>&- & else "$@" <&3 3<&- 6>&- 7>&- 8>&- & fi; { echo $! > /tmp/.runr/exec/01jbexec.pid; }', $script);
        $this->assertStringContainsString('wait $! 2>/dev/null; echo $? >&8; } | cat >&6 6>&- 7>&- 8>&-; } 2>&1 | cat >&7 6>&- 7>&- 8>&-; } 8>&1 ); fi; exec 6>&- 7>&-; else if command -v timeout', $script);
        $this->assertStringNotContainsString('mkfifo', $script);
        $this->assertStringEndsWith("exec 3<&-\nexit \${runr_rc:-1}", $script);

        // The stored input becomes fd 3; a terminal is never relayed
        $this->assertStringContainsString('exec 3<&0', ExecScript::command(['cat'], [], null, 30, 0, '01JBEXEC', false, '/tmp/.runr/exec/1-x.in', false, true)[2]);
        $this->assertStringNotContainsString('mkfifo', ExecScript::command(['bash'], [], null, 30, 0, '01JBEXEC', true, null, false, true)[2]);

        // Code runs too
        $run = ExecScript::runCode('/tmp/.runr/code/run-1.py', [['python3', '{file}']], [], '/workspace', 30, 0, '--mk--', 10, true)[2];
        $this->assertStringContainsString("then run=1; set -- 'python3' '/tmp/.runr/code/run-1.py'\n", $run);
        $this->assertStringContainsString('runr_rc=$( { { { if command -v timeout >/dev/null 2>&1; then timeout -s KILL 30 "$@" < /dev/null 3<&- 6>&- 7>&- 8>&- & else "$@" < /dev/null 3<&- 6>&- 7>&- 8>&- & fi; wait $! 2>/dev/null; echo $? >&8; }', $run);
        $this->assertStringContainsString("\nrc=\${runr_rc:-1}; fi\n", $run);
        $this->assertStringNotContainsString('runr_rc', ExecScript::runCode('/tmp/.runr/code/run-1.py', [['python3', '{file}']], [], '/workspace', 30, 0, '--mk--', 10)[2]);
    }

    public function test_streamed_input_is_framed_and_the_end_is_marked(): void
    {
        $argv = ExecScript::command(['cat'], ['A' => '1'], null, 30, 64, '01JBWS', false, null, true);
        $script = $argv[2];

        $this->assertSame(['runr', 'cat'], array_slice($argv, 3));
        $this->assertStringContainsString("export A='1'", $script);
        $this->assertStringContainsString('runr_rd() { while IFS= read -r runr_n; do [ "$runr_n" = 0 ] && break; runr_take "$runr_n"; done; }', $script);
        // The reader and the command are jobs of their own, joined by a FIFO: wait waits for the command only
        $this->assertStringContainsString('runr_fifo=/tmp/.runr/exec/01jbws.fifo', $script);
        $this->assertStringContainsString('runr_rd <&3 > "$runr_fifo" 3<&- &', $script);
        // The PID file records the background command (timeout leads its process group), not the shell
        $this->assertStringContainsString('then timeout -s KILL 30 "$@" < "$runr_fifo" 3<&- & else "$@" < "$runr_fifo" 3<&- & fi; { echo $! > /tmp/.runr/exec/01jbws.pid; } 2>/dev/null', $script);
        $this->assertStringContainsString('wait $! 2>/dev/null; runr_rc=$?', $script);
        // Every exit of the shell writes the marker, set before the prologue (a missing cwd exits too)
        $this->assertStringStartsWith('trap \'runr_x=$?; printf "\\036runr-end:%s\\n" 01jbws; printf "\\036runr-exit:%s:%d\\n" 01jbws "$runr_x" >&2; exit $runr_x\' EXIT'."\n", $script);
        $this->assertSame("\x1erunr-end:01jbws\n", ExecScript::endMarker('01JBWS'));
        $this->assertStringEndsWith('exit $runr_rc', $script);
        $this->assertSame("\x1erunr-exit:01jbws:", ExecScript::exitMarker('01JBWS'));

        // Acknowledged frames: head takes them even when it reads ahead, an ACK after each; gVisor relays
        $acked = ExecScript::command(['cat'], [], null, 30, 64, '01JBWS', false, null, true, true, true)[2];
        $this->assertStringContainsString('if command -v head >/dev/null 2>&1; then runr_take()', $acked);
        $this->assertStringContainsString('runr_take "$runr_n"; printf \'\036runr-ack:%s\n\' 01jbws >&2; done; }', $acked);
        $this->assertStringContainsString('then timeout -s KILL 30 "$@" < "$runr_fifo" 3<&- 6>&- 7>&- 8>&- & else "$@" < "$runr_fifo" 3<&- 6>&- 7>&- 8>&- & fi; { echo $! > /tmp/.runr/exec/01jbws.pid; }', $acked);
        $this->assertStringNotContainsString('runr-ack', $script);

        // A terminal keeps its stdin as it is
        $this->assertStringNotContainsString('runr_rd', ExecScript::command(['sh'], [], null, 30, 0, '01JBWS', true, null, true)[2]);
    }

    public function test_terminal_commands_get_a_term_and_a_foreground_timeout(): void
    {
        $script = ExecScript::command(['top'], ['TERM' => 'screen'], null, 45, 0, null, true)[2];

        // The request's TERM is exported before the default, which keeps it
        $this->assertLessThan(strpos($script, 'export TERM="${TERM:-xterm-256color}"'), strpos($script, "export TERM='screen'"));
        // GNU timeout with --foreground; busybox timeout (no such option) or none fall through
        $this->assertStringContainsString('if timeout --foreground 1 true >/dev/null 2>&1; then exec timeout --foreground -s KILL 45 "$@"; fi', $script);
        $this->assertLessThan(strpos($script, 'then exec timeout -s KILL 45'), strpos($script, '--foreground -s KILL 45'));
        $this->assertStringEndsWith('exec "$@"', $script);

        $plain = ExecScript::command(['top'], [], null, 45)[2];
        $this->assertStringNotContainsString('--foreground', $plain);
        $this->assertStringNotContainsString('xterm', $plain);
    }

    public function test_string_commands_run_through_sh(): void
    {
        $argv = ExecScript::command('ls -la | wc -l');

        $this->assertSame(['/bin/sh', '-c', 'ls -la | wc -l'], array_slice($argv, 4));
        $this->assertStringContainsString("cd '/workspace'", $argv[2]);
    }

    public function test_values_are_single_quoted_safely(): void
    {
        $this->assertSame("'it'\\''s \$HOME `x`'", ExecScript::quote("it's \$HOME `x`"));

        $script = ExecScript::command(['true'], ['TOKEN' => "a'b\$c"])[2];
        $this->assertStringContainsString("export TOKEN='a'\\''b\$c'", $script);
    }

    public function test_invalid_env_names_are_refused(): void
    {
        $this->expectException(SandboxException::class);
        ExecScript::command(['true'], ['BAD-NAME' => 'x']);
    }

    public function test_empty_commands_are_refused(): void
    {
        $this->expectException(SandboxException::class);
        ExecScript::command([]);
    }

    public function test_env_file(): void
    {
        $this->assertSame("# RunR sandbox environment\nexport A='1'\nexport B='two words'\n", ExecScript::envFile(['A' => '1', 'B' => 'two words']));
    }

    public function test_tar_extraction_creates_the_missing_parent_directories_with_one_mkdir(): void
    {
        $argv = ExecScript::extractTarAt('/', 3072, ['/workspace/src', '/home/sandbox/.runr', '/workspace/src']);

        $this->assertSame(['/bin/sh', '-c'], array_slice($argv, 0, 2));
        $words = "'/workspace/src' '/home/sandbox/.runr' '/'";
        // One mkdir for all of them, only when one is missing (ExecScriptShellTest runs it)
        $this->assertStringContainsString('for d in '.$words.'; do [ -d "$d" ] || { mkdir -p -- '.$words.'; break; }; done', $argv[2]);
        // Exactly the archive's bytes, written first and their number checked: tar takes a cut archive
        $this->assertMatchesRegularExpression("#\nrunr_t='/tmp/\\.runr/exec/\\d+-[0-9a-f]{16}\\.in'\nexec 5> \"\\\$runr_t\"\nhead -c 3072 >&5\n#", $argv[2]);
        $this->assertStringContainsString("tar -x -m -o -f \"\$runr_t\" -C '/' || { runr_rc=\$?; rm -f \"\$runr_t\"; exit \$runr_rc; }", $argv[2]);
        $this->assertStringEndsWith('rm -f "$runr_t"', $argv[2]);
    }

    public function test_file_scripts_take_paths_as_arguments(): void
    {
        $this->assertSame(['runr', '/workspace/a b.txt', '50'], array_slice(ExecScript::readFile('/workspace/a b.txt', 50), 3));
        $this->assertSame(['runr', '/workspace/x'], array_slice(ExecScript::writeFile('/workspace/x', 12, '0755'), 3));
        $this->assertStringContainsString("chmod '0755'", ExecScript::writeFile('/workspace/x', 12, '0755')[2]);
        $this->assertStringContainsString('head -c 12 >&5', ExecScript::writeFile('/workspace/x', 12)[2]);
        $this->assertStringContainsString('rm -rf --', ExecScript::remove('/workspace/d', true)[2]);
        $this->assertStringContainsString('rmdir --', ExecScript::remove('/workspace/d', false)[2]);
        $this->assertSame(['runr', '/workspace/d'], array_slice(ExecScript::createTar('/workspace/d'), 3));
        $this->assertSame(['runr', '/workspace', '10001'], array_slice(ExecScript::listDir('/workspace', 10000), 3));
    }

    public function test_background_process_start_and_state(): void
    {
        $argv = ExecScript::startProcess(['python3', '-m', 'http.server'], '/tmp/.runr/proc/p1', ['PORT' => '8000'], '/workspace', 128);

        $this->assertSame(['python3', '-m', 'http.server'], array_slice($argv, 4));
        $this->assertStringContainsString("d='/tmp/.runr/proc/p1'", $argv[2]);
        $this->assertStringContainsString('nohup setsid /bin/sh -c "$inner" runr-proc "$@" > "$d/out" 2> "$d/err" < /dev/null &', $argv[2]);
        $this->assertStringContainsString("export PORT='8000'", $argv[2]);
        $this->assertStringEndsWith('echo $!', $argv[2]);

        $states = ExecScript::processStates([['id' => 'p1', 'pid' => 42, 'dir' => '/tmp/.runr/proc/p1']]);
        $this->assertSame(['runr', 'p1:42:/tmp/.runr/proc/p1'], array_slice($states, 3));
        $this->assertStringContainsString('"Z"', $states[2]);
    }

    public function test_signals_are_sanitized(): void
    {
        $this->assertSame(['/bin/sh', '-c', 'kill -STOP -1 2>/dev/null; exit 0'], ExecScript::signalAll('STOP'));
        $this->assertStringStartsWith('kill -TERM -42 ', ExecScript::killProcess(42, 'TERM')[2]);
        // Anything but letters and digits is dropped from a signal name
        $this->assertStringStartsWith('kill -KILLRMRF -42 ', ExecScript::killProcess(42, 'KILL; rm -rf /')[2]);
    }

    public function test_code_run_script_lists_the_candidates_in_order(): void
    {
        $argv = ExecScript::runCode('/tmp/.runr/code/run-1.py', [['python3', '{file}'], ['python', '{file}']], [], '/workspace', 30, 256, '--marker--', 1000);
        $this->assertCount(3, $argv, 'no stdin: the code file was stored by an exec of its own');
        $script = $argv[2];

        $this->assertLessThan(strpos($script, "command -v 'python'"), strpos($script, "command -v 'python3'"));
        $this->assertStringContainsString("timeout -s KILL 30 'python3' '/tmp/.runr/code/run-1.py' < /dev/null", $script);
        $this->assertStringContainsString("[ -f '/tmp/.runr/code/run-1.py' ] || { echo \"runr: the code file is missing\" >&2; exit 1; }", $script);
        $this->assertStringNotContainsString('cat >', $script);
        // The code directory exists from the pod's start: mkdir only when it does not
        $this->assertStringContainsString("{ [ -d '/tmp/.runr/code' ] || mkdir -p -- '/tmp/.runr/code'; } || exit 1", $script);
        $this->assertSame(1, substr_count($script, 'mkdir'));
        $this->assertStringContainsString("printf \"\\n%s\\n\" '--marker--'", $script);
        $this->assertStringContainsString("find '/workspace' '/output' -type f -newer", $script);
        $this->assertStringContainsString('head -n 1000', $script);
    }

    public function test_kubectl_argv(): void
    {
        config(['sandbox.exec.kubectl' => 'kubectl']);
        $target = new PodTarget('kubeconfig', 'runr-sbx-default', 'sbx-1');

        $this->assertSame(
            ['kubectl', 'exec', '-i', 'sbx-1', '-n', 'runr-sbx-default', '-c', 'sandbox', '--kubeconfig=/tmp/k', '--request-timeout=0', '--', 'echo', 'hi'],
            KubectlCommand::argv($target, '/tmp/k', ['echo', 'hi'], true),
        );
        $this->assertNotContains('-i', KubectlCommand::argv($target, '/tmp/k', ['true'], false));
        $this->assertNotContains('-t', KubectlCommand::argv($target, '/tmp/k', ['true'], true));
        $this->assertSame(['kubectl', 'exec', '-i', '-t', 'sbx-1'], array_slice(KubectlCommand::argv($target, '/tmp/k', ['sh'], true, true), 0, 5));
    }

    public function test_kubectl_errors_versus_remote_exit_codes(): void
    {
        $this->assertTrue(KubectlCommand::isKubectlError("Error from server (NotFound): pods \"sbx-1\" not found\n", 1));
        $this->assertTrue(KubectlCommand::isKubectlError("Unable to connect to the server: dial tcp: i/o timeout\n", 1));
        $this->assertFalse(KubectlCommand::isKubectlError("Traceback ...\ncommand terminated with exit code 1\n", 1));
        $this->assertFalse(KubectlCommand::isKubectlError('anything', 0));
        $this->assertSame('boom', KubectlCommand::stripTrailer("boom\ncommand terminated with exit code 2\n"));
    }

    public function test_trailer_filter_drops_only_the_final_trailer(): void
    {
        $f = new TrailerFilter;
        $out = $f->push("line 1\n").$f->push("command terminated with exit code 3\n").$f->finish();
        $this->assertSame("line 1\n", $out);

        // A trailer-looking line followed by more output is the program's own
        $f = new TrailerFilter;
        $out = $f->push("command terminated with exit code 3\n").$f->push("more\n").$f->finish();
        $this->assertSame("command terminated with exit code 3\nmore\n", $out);

        // Partial lines stream unless they may become the trailer
        $f = new TrailerFilter;
        $this->assertSame('progress 10%', $f->push('progress 10%'));
        $this->assertSame("\n", $f->push("\ncomm"));
        $this->assertSame('', $f->push("and terminated with exit code 1\n"));
        $this->assertSame('', $f->finish());

        // stderr without a final newline: kubectl's trailer ends its last line, in one chunk or split
        $f = new TrailerFilter;
        $out = $f->push("partial\0\0command terminated with exit code 4\n").$f->finish();
        $this->assertSame("partial\0\0", $out);
        $f = new TrailerFilter;
        $out = $f->push("partial\0\0com").$f->push('mand terminated with exit co').$f->push("de 4\n").$f->finish();
        $this->assertSame("partial\0\0", $out);
        // ... and a line that only ends like it is released when more follows
        $f = new TrailerFilter;
        $out = $f->push("x command terminated with exit code 4\n").$f->push("y\n").$f->finish();
        $this->assertSame("x command terminated with exit code 4\ny\n", $out);
    }

    public function test_sizes(): void
    {
        $this->assertSame(500, Sizes::cpuMillicores('500m'));
        $this->assertSame(500, Sizes::cpuMillicores(0.5));
        $this->assertSame(1000, Sizes::cpuMillicores('1'));
        $this->assertSame(2000, Sizes::cpuMillicores(2));
        $this->assertNull(Sizes::cpuMillicores('fast'));
        $this->assertNull(Sizes::cpuMillicores('0'));

        $this->assertSame(512, Sizes::mebibytes(512));
        $this->assertSame(512, Sizes::mebibytes('512Mi'));
        $this->assertSame(1024, Sizes::mebibytes('1Gi'));
        $this->assertSame(954, Sizes::mebibytes('1G'));
        $this->assertSame(1, Sizes::mebibytes('512Ki'));
        $this->assertNull(Sizes::mebibytes('lots'));
        $this->assertNull(Sizes::mebibytes(0));
    }
}
