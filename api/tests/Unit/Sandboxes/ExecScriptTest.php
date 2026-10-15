<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\KubectlCommand;
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
        $this->assertStringContainsString('exec timeout -s KILL 30 "$@"', $script);
        $this->assertStringEndsWith('exec "$@"', $script);
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

    public function test_tar_extraction_creates_the_parent_directories_first(): void
    {
        $argv = ExecScript::extractTarAt('/', ['/workspace/src', '/home/sandbox/.runr', '/workspace/src']);

        $this->assertSame(['/bin/sh', '-c'], array_slice($argv, 0, 2));
        $this->assertSame(1, substr_count($argv[2], "mkdir -p '/workspace/src'"));
        $this->assertStringContainsString("mkdir -p '/home/sandbox/.runr'", $argv[2]);
        $this->assertStringEndsWith("exec tar -x -m -o -f - -C '/'", $argv[2]);
    }

    public function test_file_scripts_take_paths_as_arguments(): void
    {
        $this->assertSame(['runr', '/workspace/a b.txt', '50'], array_slice(ExecScript::readFile('/workspace/a b.txt', 50), 3));
        $this->assertSame(['runr', '/workspace/x'], array_slice(ExecScript::writeFile('/workspace/x', '0755'), 3));
        $this->assertStringContainsString("chmod '0755'", ExecScript::writeFile('/workspace/x', '0755')[2]);
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
        $script = $argv[2];

        $this->assertLessThan(strpos($script, "command -v 'python'"), strpos($script, "command -v 'python3'"));
        $this->assertStringContainsString("timeout -s KILL 30 'python3' '/tmp/.runr/code/run-1.py' < /dev/null", $script);
        $this->assertStringContainsString("cat > '/tmp/.runr/code/run-1.py'", $script);
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
