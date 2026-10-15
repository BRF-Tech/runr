<?php

namespace Tests\Unit;

use App\Services\Concerns\SandboxManifestTrait;
use App\Services\Helpers\ContainerRuntimeHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the gVisor node scripts (install + cleanup) against a fake node: the node's / is a temp
 * dir, and containerd (`config dump` = the file, rejected when it contains "BROKEN"), crictl
 * (runsc loaded or not) and systemctl (a restart "loads" the config on disk) are small scripts.
 */
class GvisorNodeScriptTest extends TestCase
{
    private const ORIGINAL_V3 = <<<'TOML'
version = 3

[plugins.'io.containerd.cri.v1.images'.registry]
  config_path = "/etc/containerd/certs.d"

[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]
  runtime_type = "io.containerd.runc.v2"

TOML;

    private const RUNSC_V3 = "\n[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runsc]\n  runtime_type = \"io.containerd.runsc.v1\"\n";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $this->dir = sys_get_temp_dir().'/runr-gvisor-node-'.bin2hex(random_bytes(4));
        foreach (['bin', 'host/etc/containerd', 'host/usr/local/bin/gvisor-bin', 'host/state', 'host/var/lib/runr'] as $d) {
            mkdir("{$this->dir}/{$d}", 0777, true);
        }

        $this->fake('containerd', <<<'SH'
#!/bin/sh
# containerd --config <node path> config dump
[ "$1" = --config ] && [ "$3" = config ] && [ "$4" = dump ] || { echo "unexpected: $*" >&2; exit 2; }
f="$FAKEROOT$2"
[ -f "$f" ] || { echo "open $2: no such file or directory" >&2; exit 1; }
if grep -q BROKEN "$f"; then echo "failed to load TOML from $2: toml: line 3: expected '='" >&2; exit 1; fi
cat "$f"
if grep -q '^imports' "$f"; then
  for d in "$FAKEROOT"/var/lib/rancher/k3s/agent/etc/containerd/config-v3.toml.d/*.toml; do
    if [ -f "$d" ]; then cat "$d"; fi
  done
fi
exit 0
SH);
        $this->fake('crictl', <<<'SH'
#!/bin/sh
if grep -q runsc "$FAKEROOT/state/loaded" 2>/dev/null; then
  echo '{"config":{"containerd":{"runtimes":{"runc":{},"runsc":{}}}}}'
else
  echo '{"config":{"containerd":{"runtimes":{"runc":{}}}}}'
fi
SH);
        $this->fake('systemctl', <<<'SH'
#!/bin/sh
case "$1" in
  is-active) [ "$3" = "$ACTIVE_UNIT" ] ;;
  restart)
    echo "$2" >> "$FAKEROOT/state/restarts"
    K="$FAKEROOT/var/lib/rancher/k3s/agent/etc/containerd"
    cat "$FAKEROOT/etc/containerd/config.toml" "$K/config.toml" "$K"/config-v3.toml.d/*.toml > "$FAKEROOT/state/loaded" 2>/dev/null
    exit 0 ;;
  *) exit 1 ;;
esac
SH);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && str_starts_with($this->dir, sys_get_temp_dir().'/runr-gvisor-node-')) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private function fake(string $name, string $script): void
    {
        file_put_contents("{$this->dir}/bin/{$name}", $script);
        chmod("{$this->dir}/bin/{$name}", 0755);
    }

    private function scripts(): object
    {
        return new class
        {
            use SandboxManifestTrait;

            public function install(): string
            {
                return $this->gvisorInstallScript();
            }

            public function cleanup(): string
            {
                return $this->gvisorCleanupScript();
            }

            public function helpers(): string
            {
                return $this->gvisorHelpersScript();
            }
        };
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runOnNode(string $script, string $flavor = 'containerd'): array
    {
        $k3s = $flavor === 'k3s';
        $env = [
            'NODE_NAME' => 'node-1',
            'RUNTIME_FLAVOR' => $flavor,
            'CONTAINERD_MAJOR' => '2',
            'CONTAINERD_CONFIG_DIR' => ContainerRuntimeHelper::configDir($flavor),
            'CONTAINERD_SOCKET' => ContainerRuntimeHelper::socketPath($flavor),
            'RESTART_UNITS' => implode(' ', ContainerRuntimeHelper::restartUnits($flavor)),
            'ACTIVE_UNIT' => $k3s ? 'k3s' : 'containerd',
            'FAKEROOT' => "{$this->dir}/host",
            'PATH' => "{$this->dir}/bin:".getenv('PATH'),
        ];
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= "export {$k}=".escapeshellarg($v)."\n";
        }

        $overrides = <<<'SH'
fail() { echo "runr: ERROR: $* (node $NODE)"; exit 1; }
hostctl() { "$@"; }
sleep() { :; }

SH;
        $script = str_replace(
            ['HOST=/host', 'chroot "$HOST"', '/tmp/runr-gvisor-ready', '/tmp/runr-gvisor-clean', "# -- end of helpers --\n"],
            ["HOST={$this->dir}/host", 'true', "{$this->dir}/done", "{$this->dir}/done", "# -- end of helpers --\n".$overrides],
            $script,
        );

        exec('sh -c '.escapeshellarg($prefix.$script).' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    private function path(string $rel): string
    {
        return "{$this->dir}/host/{$rel}";
    }

    private function put(string $rel, string $content): void
    {
        if (! is_dir(dirname($this->path($rel)))) {
            mkdir(dirname($this->path($rel)), 0777, true);
        }
        file_put_contents($this->path($rel), $content);
    }

    private function restarts(): array
    {
        $f = $this->path('state/restarts');

        return is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
    }

    private function binaries(): void
    {
        foreach (['usr/local/bin/runsc', 'usr/local/bin/containerd-shim-runsc-v1', 'usr/local/bin/gvisor-bin/gvisor_sentry'] as $b) {
            $this->put($b, "#!/bin/sh\n");
            chmod($this->path($b), 0755);
        }
    }

    public function test_strip_runsc_is_the_inverse_of_the_appended_block(): void
    {
        $cases = [
            'v3' => [self::ORIGINAL_V3, self::RUNSC_V3],
            'v2' => ["version = 2\n\n[plugins.\"io.containerd.grpc.v1.cri\"]\n  sandbox_image = \"pause:3.10\"\n",
                "\n[plugins.\"io.containerd.grpc.v1.cri\".containerd.runtimes.runsc]\n  runtime_type = \"io.containerd.runsc.v1\"\n"],
            'no final newline' => ["version = 3\n[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]\n  runtime_type = \"io.containerd.runc.v2\"", self::RUNSC_V3],
            'options table' => [self::ORIGINAL_V3, self::RUNSC_V3."\n[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runsc.options]\n  TypeUrl = \"io.containerd.runsc.v1.options\"\n"],
        ];

        foreach ($cases as $name => [$original, $block]) {
            $this->put('etc/containerd/case.toml', $original.$block);
            $script = $this->scripts()->helpers().'strip_runsc "$HOST/etc/containerd/case.toml"'."\n";
            [$code, $out] = $this->runOnNode($script);
            $this->assertSame(0, $code, $out);
            $expected = str_ends_with($original, "\n") ? $original : $original."\n";
            $this->assertSame(rtrim($expected, "\n"), rtrim($out, "\n"), "case {$name}");
            $this->assertStringNotContainsString('runsc', $out, "case {$name}");
        }
    }

    public function test_cleanup_removes_the_runsc_block_restarts_containerd_and_removes_the_binaries(): void
    {
        // The MVP workers after the failed 0.3.4 test: block appended, runsc loaded, binaries, a backup
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3.self::RUNSC_V3);
        $this->put('etc/containerd/config.toml.runr-pre-gvisor-034', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3.self::RUNSC_V3);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->cleanup());

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3, file_get_contents($this->path('etc/containerd/config.toml')), $out);
        $this->assertSame(['containerd'], $this->restarts());
        $this->assertStringNotContainsString('runsc', file_get_contents($this->path('state/loaded')));
        $this->assertFileDoesNotExist($this->path('usr/local/bin/runsc'));
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-runsc-v1'));
        $this->assertDirectoryDoesNotExist($this->path('usr/local/bin/gvisor-bin'));
        $this->assertSame([], glob($this->path('etc/containerd/config.toml.*')), 'no backups / temp files left');
        $this->assertFileExists("{$this->dir}/done");
        $this->assertStringContainsString('containerd accepts /etc/containerd/config.toml.runr-new (runsc registered: no)', $out);
    }

    public function test_cleanup_leaves_the_config_alone_when_containerd_rejects_the_result(): void
    {
        $config = "# BROKEN\n".self::ORIGINAL_V3.self::RUNSC_V3;
        $this->put('etc/containerd/config.toml', $config);
        $this->put('state/loaded', $config);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->cleanup());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('runr: ERROR: containerd rejects /etc/containerd/config.toml.runr-new', $out);
        $this->assertStringContainsString('config.toml left unchanged, nothing restarted', $out);
        $this->assertSame($config, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertSame([], $this->restarts());
        $this->assertFileExists($this->path('usr/local/bin/runsc'));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-new'));
    }

    public function test_cleanup_of_a_clean_node_changes_nothing(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);

        [$code, $out] = $this->runOnNode($this->scripts()->cleanup());

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertSame([], $this->restarts());
        $this->assertStringContainsString('no restart needed', $out);
    }

    public function test_cleanup_on_k3s_removes_the_drop_in_and_restarts_k3s(): void
    {
        $k = 'var/lib/rancher/k3s/agent/etc/containerd';
        $generated = "# File generated by k3s. DO NOT EDIT. Use config-v3.toml.tmpl instead.\nversion = 3\n"
            ."imports = [\"/{$k}/config-v3.toml.d/*.toml\"]\n";
        $this->put("{$k}/config.toml", $generated);
        $this->put("{$k}/config-v3.toml.d/runr-runsc.toml", self::RUNSC_V3);
        $this->put('state/loaded', $generated.self::RUNSC_V3);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->cleanup(), 'k3s');

        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->path("{$k}/config-v3.toml.d/runr-runsc.toml"));
        $this->assertSame($generated, file_get_contents($this->path("{$k}/config.toml")));
        $this->assertSame(['k3s'], $this->restarts());
        $this->assertFileDoesNotExist($this->path('usr/local/bin/runsc'));
        $this->assertDirectoryDoesNotExist($this->path('usr/local/bin/gvisor-bin'));
    }

    public function test_install_on_a_node_that_still_has_the_block_adds_nothing_and_does_not_restart(): void
    {
        $config = self::ORIGINAL_V3.self::RUNSC_V3;
        $this->put('etc/containerd/config.toml', $config);
        $this->put('state/loaded', $config);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->install());

        $this->assertSame(0, $code, $out);
        $this->assertSame($config, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertSame(1, substr_count(file_get_contents($this->path('etc/containerd/config.toml')), 'runtimes.runsc]'));
        $this->assertSame([], $this->restarts());
        $this->assertStringContainsString('runsc already configured', $out);
        $this->assertFileExists("{$this->dir}/done");
    }

    public function test_install_adds_the_block_after_validation_and_restarts_once(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->install());

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3.self::RUNSC_V3, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertSame(self::ORIGINAL_V3, file_get_contents($this->path('etc/containerd/config.toml.runr-pre-gvisor')));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-new'));
        $this->assertSame(['containerd'], $this->restarts());
        $this->assertStringContainsString('runsc', file_get_contents($this->path('state/loaded')));
        $this->assertFileDoesNotExist($this->path('var/lib/runr/gvisor-runtime-restarted'));

        // ... and the cleanup restores the original file exactly
        [$code, $out] = $this->runOnNode($this->scripts()->cleanup());
        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertSame(['containerd', 'containerd'], $this->restarts());
    }

    public function test_install_does_not_restart_with_a_config_containerd_rejects(): void
    {
        $config = "# BROKEN\n".self::ORIGINAL_V3;
        $this->put('etc/containerd/config.toml', $config);
        $this->put('state/loaded', self::ORIGINAL_V3);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->install());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('config.toml left unchanged, nothing restarted', $out);
        $this->assertSame($config, file_get_contents($this->path('etc/containerd/config.toml')));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-new'));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-pre-gvisor'));
        $this->assertSame([], $this->restarts());
    }

    public function test_install_on_k3s_writes_a_validated_drop_in(): void
    {
        $k = 'var/lib/rancher/k3s/agent/etc/containerd';
        $generated = "version = 3\nimports = [\"/{$k}/config-v3.toml.d/*.toml\"]\n";
        $this->put("{$k}/config.toml", $generated);
        $this->put('state/loaded', $generated);
        $this->binaries();

        [$code, $out] = $this->runOnNode($this->scripts()->install(), 'k3s');

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::RUNSC_V3, file_get_contents($this->path("{$k}/config-v3.toml.d/runr-runsc.toml")));
        $this->assertSame($generated, file_get_contents($this->path("{$k}/config.toml")));
        $this->assertStringContainsString("containerd accepts /{$k}/config.toml (runsc registered: yes)", $out);
        $this->assertSame(['k3s'], $this->restarts());
    }

    public function test_scripts_are_valid_sh(): void
    {
        foreach (['install' => $this->scripts()->install(), 'cleanup' => $this->scripts()->cleanup()] as $name => $script) {
            exec('sh -n -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "{$name}: ".implode("\n", $out));
        }
    }
}
