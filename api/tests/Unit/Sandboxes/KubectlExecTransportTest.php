<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\KubectlExecTransport;
use App\Services\Sandboxes\Exec\PodTarget;
use Tests\TestCase;

/**
 * KubectlExecTransport with a kubectl stand-in that runs the command locally: acknowledged input
 * (Kata's Rust runtime) goes frame by frame, and the acknowledgements stay out of stderr.
 */
class KubectlExecTransportTest extends TestCase
{
    private ?string $kubectl = null;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs /bin/sh');
        }
        // kubectl stand-in: drops kubectl's own arguments and becomes the command after "--"
        $this->kubectl = tempnam(sys_get_temp_dir(), 'fake-kubectl-');
        file_put_contents($this->kubectl, "#!/bin/sh\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($this->kubectl, 0755);
        config(['sandbox.exec.kubectl' => $this->kubectl]);
        $this->dir = sys_get_temp_dir().'/runr-transport-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->kubectl !== null) {
            @unlink($this->kubectl);
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private function target(): PodTarget
    {
        return new PodTarget("apiVersion: v1\nkind: Config\n", 'runr-sbx-default', 'sbx-1');
    }

    public function test_acknowledged_input_arrives_whole(): void
    {
        $data = random_bytes(3 * ExecScript::ACKED_FRAME_BYTES + 1234);
        $file = $this->dir.'/up/x.bin';

        $result = (new KubectlExecTransport)->exec($this->target(), ExecScript::ackedInput(ExecScript::writeFile($file, strlen($data)), 'ab12'), $data, 30, null, 1048576, null, 'ab12');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stderr, 'the acknowledgements are taken out');
        $this->assertSame($data, file_get_contents($file));
        $this->assertLessThan(10_000, $result->durationMs);
    }

    public function test_acknowledged_input_of_a_script_that_fails_early(): void
    {
        $result = (new KubectlExecTransport)->exec($this->target(), ExecScript::ackedInput(['/bin/sh', '-c', 'echo nope >&2; exit 3'], 'ab12'), random_bytes(200000), 30, null, 1048576, null, 'ab12');

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("nope\n", $result->stderr);
        $this->assertLessThan(10_000, $result->durationMs);
    }

    public function test_empty_acknowledged_input(): void
    {
        $file = $this->dir.'/empty.bin';
        $result = (new KubectlExecTransport)->exec($this->target(), ExecScript::ackedInput(ExecScript::writeFile($file, 0), 'ab12'), '', 30, null, 1048576, null, 'ab12');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('', file_get_contents($file));
    }

    public function test_a_scripts_own_stderr_passes_byte_for_byte(): void
    {
        // 0x06, 0x04, a marker of another id, a marker's start at the very end: none of them is an ack
        $script = ['/bin/sh', '-c', 'cat > /dev/null; printf \'a\006b\004c\036runr-ack:other\nd\036runr-ack:ab\' >&2; exit 4'];
        $result = (new KubectlExecTransport)->exec($this->target(), ExecScript::ackedInput($script, 'ab12'), str_repeat('x', 3 * ExecScript::ACKED_FRAME_BYTES), 30, null, 1048576, null, 'ab12');

        $this->assertSame(4, $result->exitCode);
        $this->assertSame("a\x06b\x04c\x1erunr-ack:other\nd\x1erunr-ack:ab", $result->stderr);
    }
}
