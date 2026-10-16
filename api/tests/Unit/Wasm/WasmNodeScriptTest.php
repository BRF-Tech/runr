<?php

namespace Tests\Unit\Wasm;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Wasm\WasmNodeScripts;
use PHPUnit\Framework\TestCase;

/**
 * Runs the WebAssembly node scripts (install + cleanup) against a fake node: the node's / is a
 * temp dir; containerd (`config dump` = the file plus what it imports, rejected when it contains
 * "BROKEN"), crictl (the handlers of the configuration last "loaded"), systemctl (a restart loads
 * what is on disk; k3s re-renders its config from the template) and wget (serves local tarballs)
 * are small scripts. The files an init container copies from a shim's image are a temp dir too.
 */
class WasmNodeScriptTest extends TestCase
{
    private const K3S = 'var/lib/rancher/k3s/agent/etc/containerd';

    private const ORIGINAL_V3 = <<<'TOML'
version = 3

[plugins.'io.containerd.cri.v1.images'.registry]
  config_path = "/etc/containerd/certs.d"

[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]
  runtime_type = "io.containerd.runc.v2"
[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc.options]
  SystemdCgroup = true

TOML;

    private const ORIGINAL_V2 = <<<'TOML'
version = 2

[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.runc]
  runtime_type = "io.containerd.runc.v2"

TOML;

    private string $dir;

    /** @var array<string, string> shim id → script line (WasmShim::scriptLine() shape, local tarballs) */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $this->dir = sys_get_temp_dir().'/runr-wasm-node-'.bin2hex(random_bytes(4));
        foreach (['bin', 'dl', 'pkg', 'shim-files', 'host/etc/containerd', 'host/usr/local/bin', 'host/state', 'host/var/lib/runr'] as $d) {
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
  for d in "$FAKEROOT"/var/lib/rancher/k3s/agent/etc/containerd/config-v3.toml.d/*.toml "$FAKEROOT"/etc/containerd/conf.d/*.toml; do
    if [ -f "$d" ]; then
      if grep -q BROKEN "$d"; then echo "failed to load TOML from $d" >&2; exit 1; fi
      cat "$d"
    fi
  done
fi
exit 0
SH);
        $this->fake('crictl', <<<'SH'
#!/bin/sh
R='"runc":{}'
for h in spin-runr wasmtime-runr; do
  if grep -q "runtimes.$h\]" "$FAKEROOT/state/loaded" 2>/dev/null; then R="$R,\"$h\":{}"; fi
done
echo "{\"config\":{\"containerd\":{\"runtimes\":{$R}}}}"
SH);
        $this->fake('systemctl', <<<'SH'
#!/bin/sh
case "$1" in
  is-active) [ "$3" = "$ACTIVE_UNIT" ] ;;
  restart)
    echo "$2" >> "$FAKEROOT/state/restarts"
    K="$FAKEROOT/var/lib/rancher/k3s/agent/etc/containerd"
    # k3s renders config.toml from its base and config-v3.toml.tmpl at start
    if [ -f "$K/config.toml.base" ]; then
      if [ -f "$K/config-v3.toml.tmpl" ]; then
        { cat "$K/config.toml.base"; grep -v '^{{ template' "$K/config-v3.toml.tmpl"; } > "$K/config.toml"
      else
        cp "$K/config.toml.base" "$K/config.toml"
      fi
    fi
    cat "$FAKEROOT/etc/containerd/config.toml" "$FAKEROOT"/etc/containerd/conf.d/*.toml "$K/config.toml" "$K"/config-v3.toml.d/*.toml > "$FAKEROOT/state/loaded" 2>/dev/null
    exit 0 ;;
  *) exit 1 ;;
esac
SH);
        $this->fake('wget', <<<'SH'
#!/bin/sh
# wget -q -O <file> <url>: the tarballs of this test
out=""; url=""
while [ $# -gt 0 ]; do
  case "$1" in
    -O) out="$2"; shift 2 ;;
    -q) shift ;;
    *) url="$1"; shift ;;
  esac
done
f="$DL_DIR/$(basename "$url")"
[ -f "$f" ] || exit 8
echo "$url" >> "$FAKEROOT/state/downloads"
cp "$f" "$out"
SH);

        $this->lines = [
            'spin' => $this->tarball('spin', 'containerd-shim-spin-v2', 'spin-runr', 'io.containerd.spin.v2'),
            'wasmtime' => $this->tarball('wasmtime', 'containerd-shim-wasmtime-v1', 'wasmtime-runr', 'io.containerd.wasmtime.v1'),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && str_starts_with($this->dir, sys_get_temp_dir().'/runr-wasm-node-')) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private function fake(string $name, string $script): void
    {
        file_put_contents("{$this->dir}/bin/{$name}", $script);
        chmod("{$this->dir}/bin/{$name}", 0755);
    }

    /** A release-like tarball with the shim binary at its root; the script line that installs it. */
    private function tarball(string $id, string $binary, string $handler, string $type, string $content = "#!/bin/sh\necho shim\n"): string
    {
        $pkg = "{$this->dir}/pkg/{$id}";
        @mkdir($pkg, 0777, true);
        file_put_contents("{$pkg}/{$binary}", $content);
        chmod("{$pkg}/{$binary}", 0755);
        exec('tar -czf '.escapeshellarg("{$this->dir}/dl/{$id}.tar.gz").' -C '.escapeshellarg($pkg).' '.escapeshellarg($binary));
        $sha = hash_file('sha256', "{$this->dir}/dl/{$id}.tar.gz");
        $url = "https://dl.example.com/{$id}.tar.gz";

        return implode(' ', [$id, $binary, $handler, $type, $url, $sha, $url, $sha]);
    }

    /**
     * What the init container of an image shim leaves in the installer pod (the image's /shim:
     * binary, SHA256SUMS, VERSION); the script line that installs it.
     */
    private function imageShim(string $id, string $binary, string $handler, string $type, string $content, string $version): string
    {
        $files = "{$this->dir}/shim-files/{$id}";
        @mkdir($files, 0777, true);
        file_put_contents("{$files}/{$binary}", $content);
        chmod("{$files}/{$binary}", 0755);
        file_put_contents("{$files}/SHA256SUMS", hash('sha256', $content)."  {$binary}\n");
        file_put_contents("{$files}/VERSION", "{$version}\n");
        $image = "ghcr.io/brf-tech/runr/containerd-shim-{$id}:{$version}";

        return implode(' ', [$id, $binary, $handler, $type, 'image', $image, 'image', $image]);
    }

    /**
     * @param  list<string>  $shims
     * @return array{0: int, 1: string}
     */
    private function runOnNode(string $script, string $flavor = 'containerd', array $shims = ['spin']): array
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
            'WASM_SHIMS' => implode("\n", array_map(fn ($s) => $this->lines[$s], $shims)),
            'WASM_ALL_SHIMS' => implode("\n", $this->lines),
            'FAKEROOT' => "{$this->dir}/host",
            'DL_DIR' => "{$this->dir}/dl",
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
            ['HOST=/host', WasmNodeScripts::READY_MARKER, WasmNodeScripts::CLEAN_MARKER, '/tmp/runr-wasm-shims', "SHIM_FILES='".WasmNodeScripts::SHIM_FILES."'", "# -- end of helpers --\n"],
            ["HOST={$this->dir}/host", "{$this->dir}/done", "{$this->dir}/done", "{$this->dir}/shims", "SHIM_FILES='{$this->dir}/shim-files'", "# -- end of helpers --\n".$overrides],
            $script,
        );

        if (is_file("{$this->dir}/done")) {
            unlink("{$this->dir}/done");
        }
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

    private function read(string $rel): string
    {
        return (string) file_get_contents($this->path($rel));
    }

    /** @return list<string> */
    private function restarts(): array
    {
        $f = $this->path('state/restarts');

        return is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
    }

    private function resetRestarts(): void
    {
        if (is_file($this->path('state/restarts'))) {
            unlink($this->path('state/restarts'));
        }
    }

    // ── tests ───────────────────────────────────────────────────────────────

    public function test_scripts_are_valid_sh(): void
    {
        foreach (['install' => WasmNodeScripts::install(), 'cleanup' => WasmNodeScripts::cleanup()] as $name => $script) {
            exec('sh -n -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "{$name}: ".implode("\n", $out));
        }
    }

    public function test_strip_block_is_the_inverse_of_the_marked_block(): void
    {
        foreach (['3' => self::ORIGINAL_V3, '2' => self::ORIGINAL_V2] as $version => $original) {
            $script = WasmNodeScripts::helpers()
                ."marked_block {$version} true > \"\$HOST/blk\"\n"
                ."cat \"\$HOST/etc/containerd/case.toml\" \"\$HOST/blk\" > \"\$HOST/both\"\n"
                ."strip_block \"\$HOST/both\"\n";
            $this->put('etc/containerd/case.toml', $original);
            [$code, $out] = $this->runOnNode($script);

            $this->assertSame(0, $code, $out);
            $this->assertSame(rtrim($original, "\n"), rtrim($out, "\n"), "config version {$version}");
            // PHP turns the numeric string keys into ints
            $this->assertStringContainsString((string) $version === '3'
                ? "[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.spin-runr]"
                : '[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.spin-runr]', $this->read('blk'));
        }
    }

    public function test_install_on_plain_containerd_appends_the_validated_block_and_restarts_once(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $config = $this->read('etc/containerd/config.toml');
        $this->assertStringStartsWith(self::ORIGINAL_V3, $config);
        $this->assertStringContainsString(WasmNodeScripts::BEGIN_MARK, $config);
        $this->assertStringContainsString("[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.spin-runr]\n  runtime_type = \"io.containerd.spin.v2\"", $config);
        // runc uses the systemd cgroup driver on this node: so do the shim's containers
        $this->assertStringContainsString("[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.spin-runr.options]\n  SystemdCgroup = true", $config);
        $this->assertStringNotContainsString('wasmtime-runr', $config);
        $this->assertSame(self::ORIGINAL_V3, $this->read('etc/containerd/config.toml.runr-pre-wasm'));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-new'));

        $this->assertFileExists($this->path('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertTrue(is_executable($this->path('usr/local/bin/containerd-shim-spin-v2')));
        $this->assertSame(['containerd'], $this->restarts());
        $this->assertStringContainsString('runr: wasm ready: spin-runr', $out);
        $this->assertFileExists("{$this->dir}/done");
        $this->assertFileDoesNotExist($this->path('var/lib/runr/wasm/runtime-restarted'));
    }

    public function test_a_second_install_changes_and_restarts_nothing(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());
        $this->assertSame(0, $code, $out);
        $config = $this->read('etc/containerd/config.toml');
        $this->resetRestarts();
        @unlink($this->path('state/downloads'));

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $this->assertSame($config, $this->read('etc/containerd/config.toml'));
        $this->assertSame(1, substr_count($config, WasmNodeScripts::BEGIN_MARK));
        $this->assertSame([], $this->restarts());
        $this->assertFileDoesNotExist($this->path('state/downloads'), 'the installed shim is not downloaded again');
        $this->assertStringContainsString('no restart needed', $out);
    }

    public function test_install_without_the_systemd_cgroup_driver_sets_no_options(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V2);
        $this->put('state/loaded', self::ORIGINAL_V2);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $config = $this->read('etc/containerd/config.toml');
        $this->assertStringContainsString('[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.spin-runr]', $config);
        $this->assertStringNotContainsString('SystemdCgroup', substr($config, strlen(self::ORIGINAL_V2)));
    }

    public function test_install_on_containerd_with_an_imports_directory_writes_a_drop_in(): void
    {
        $config = "version = 3\nimports = [\"/etc/containerd/conf.d/*.toml\"]\n\n".substr(self::ORIGINAL_V3, strlen("version = 3\n"));
        $this->put('etc/containerd/config.toml', $config);
        $this->put('state/loaded', $config);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $this->assertSame($config, $this->read('etc/containerd/config.toml'), 'config.toml stays as it is');
        $dropIn = $this->read('etc/containerd/conf.d/runr-wasm.toml');
        $this->assertStringStartsWith("version = 3\n".WasmNodeScripts::BEGIN_MARK, $dropIn);
        $this->assertStringContainsString('runtimes.spin-runr]', $dropIn);
        $this->assertStringContainsString('containerd accepts /etc/containerd/config.toml', $out);
        $this->assertSame(['containerd'], $this->restarts());

        // cleanup: the drop-in goes, config.toml is untouched
        $this->resetRestarts();
        [$code, $out] = $this->runOnNode(WasmNodeScripts::cleanup());
        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->path('etc/containerd/conf.d/runr-wasm.toml'));
        $this->assertSame($config, $this->read('etc/containerd/config.toml'));
        $this->assertSame(['containerd'], $this->restarts());
    }

    public function test_install_on_k3s_writes_a_drop_in_and_restarts_k3s(): void
    {
        $generated = "version = 3\nimports = [\"/".self::K3S."/config-v3.toml.d/*.toml\"]\n";
        $this->put(self::K3S.'/config.toml', $generated);
        $this->put('state/loaded', $generated);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'k3s');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('runtimes.spin-runr]', $this->read(self::K3S.'/config-v3.toml.d/runr-wasm.toml'));
        $this->assertSame($generated, $this->read(self::K3S.'/config.toml'));
        $this->assertSame(['k3s'], $this->restarts());
        $this->assertStringContainsString('restarting k3s in', $out);
    }

    public function test_install_on_k3s_without_imports_uses_the_template_and_cleanup_removes_it(): void
    {
        $base = "# generated by k3s\nversion = 3\n\n[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]\n  runtime_type = \"io.containerd.runc.v2\"\n";
        $this->put(self::K3S.'/config.toml.base', $base);
        $this->put(self::K3S.'/config.toml', $base);
        $this->put('state/loaded', $base);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'k3s');

        $this->assertSame(0, $code, $out);
        $template = $this->read(self::K3S.'/config-v3.toml.tmpl');
        $this->assertStringStartsWith('{{ template "base" . }}', $template);
        $this->assertStringContainsString('runtimes.spin-runr]', $template);
        $this->assertStringContainsString('runtimes.spin-runr]', $this->read(self::K3S.'/config.toml'), 'k3s rendered it at the restart');
        $this->assertSame(['k3s'], $this->restarts());

        $this->resetRestarts();
        [$code, $out] = $this->runOnNode(WasmNodeScripts::cleanup(), 'k3s');

        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->path(self::K3S.'/config-v3.toml.tmpl'), 'only RunR\'s block was in it');
        $this->assertSame($base, $this->read(self::K3S.'/config.toml'));
        $this->assertSame(['k3s'], $this->restarts());
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertStringContainsString('runr: wasm removed', $out);
    }

    public function test_a_template_of_the_operator_keeps_its_own_content(): void
    {
        $base = "version = 3\n";
        $own = "{{ template \"base\" . }}\n\n[plugins.'io.containerd.cri.v1.images'.registry]\n  config_path = \"/etc/rancher/certs.d\"\n";
        $this->put(self::K3S.'/config.toml.base', $base);
        $this->put(self::K3S.'/config.toml', $base);
        $this->put(self::K3S.'/config-v3.toml.tmpl', $own);
        $this->put('state/loaded', $base);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'k3s');
        $this->assertSame(0, $code, $out);
        $this->assertStringStartsWith($own, $this->read(self::K3S.'/config-v3.toml.tmpl'));

        [$code, $out] = $this->runOnNode(WasmNodeScripts::cleanup(), 'k3s');
        $this->assertSame(0, $code, $out);
        $this->assertSame(rtrim($own, "\n"), rtrim($this->read(self::K3S.'/config-v3.toml.tmpl'), "\n"));
    }

    public function test_deselecting_a_shim_removes_its_handler_and_binary(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'containerd', ['spin', 'wasmtime']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('runtimes.wasmtime-runr]', $this->read('etc/containerd/config.toml'));
        $this->assertFileExists($this->path('usr/local/bin/containerd-shim-wasmtime-v1'));
        $this->assertStringContainsString('runr: wasm ready: spin-runr wasmtime-runr', $out);

        $this->resetRestarts();
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'containerd', ['spin']);

        $this->assertSame(0, $code, $out);
        $config = $this->read('etc/containerd/config.toml');
        $this->assertStringContainsString('runtimes.spin-runr]', $config);
        $this->assertStringNotContainsString('wasmtime-runr', $config);
        $this->assertSame(1, substr_count($config, WasmNodeScripts::BEGIN_MARK));
        $this->assertSame(['containerd'], $this->restarts(), 'the runtime drops the handler');
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-wasmtime-v1'));
        $this->assertFileExists($this->path('usr/local/bin/containerd-shim-spin-v2'));
    }

    public function test_an_image_shim_is_installed_from_the_files_of_its_image(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        $binary = "#!/bin/sh\necho runr build\n";
        $this->lines['spin'] = $this->imageShim('spin', 'containerd-shim-spin-v2', 'spin-runr', 'io.containerd.spin.v2', $binary, 'v0.26.0-runr.1');

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $this->assertSame($binary, $this->read('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertTrue(is_executable($this->path('usr/local/bin/containerd-shim-spin-v2')));
        $this->assertSame(hash('sha256', $binary), trim($this->read('var/lib/runr/wasm/containerd-shim-spin-v2.sha256')));
        $this->assertStringContainsString('from image ghcr.io/brf-tech/runr/containerd-shim-spin:v0.26.0-runr.1 (v0.26.0-runr.1)', $out);
        $this->assertFileDoesNotExist($this->path('state/downloads'), 'nothing downloaded');
        $this->assertStringContainsString("runtimes.spin-runr]\n  runtime_type = \"io.containerd.spin.v2\"", $this->read('etc/containerd/config.toml'));
        $this->assertSame(['containerd'], $this->restarts());

        // again: present, nothing changes
        $this->resetRestarts();
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('containerd-shim-spin-v2 present (spin shim)', $out);
        $this->assertSame([], $this->restarts());
    }

    public function test_an_upgrade_replaces_the_upstream_shim_with_the_image_build_without_a_restart(): void
    {
        // a node an earlier RunR release prepared: the binary of the upstream release tarball
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());
        $this->assertSame(0, $code, $out);
        $this->assertSame("#!/bin/sh\necho shim\n", $this->read('usr/local/bin/containerd-shim-spin-v2'));
        $config = $this->read('etc/containerd/config.toml');
        $this->resetRestarts();

        // this release's installer: the same shim from RunR's image
        $binary = "#!/bin/sh\necho runr build\n";
        $this->lines['spin'] = $this->imageShim('spin', 'containerd-shim-spin-v2', 'spin-runr', 'io.containerd.spin.v2', $binary, 'v0.26.0-runr.1');
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertSame(0, $code, $out);
        $this->assertSame($binary, $this->read('usr/local/bin/containerd-shim-spin-v2'), 'binary replaced');
        $this->assertSame(hash('sha256', $binary), trim($this->read('var/lib/runr/wasm/containerd-shim-spin-v2.sha256')));
        $this->assertStringContainsString('Replacing the spin shim', $out);
        $this->assertSame($config, $this->read('etc/containerd/config.toml'), 'same runtime handler: configuration unchanged');
        $this->assertSame([], $this->restarts(), 'a binary-only change restarts nothing');
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-spin-v2.runr-tmp'));
        $this->assertStringContainsString('runr: wasm ready: spin-runr', $out);
    }

    public function test_image_files_that_do_not_match_their_sha256sums_are_not_installed(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        $this->lines['spin'] = $this->imageShim('spin', 'containerd-shim-spin-v2', 'spin-runr', 'io.containerd.spin.v2', "#!/bin/sh\necho runr build\n", 'v0.26.0-runr.1');
        file_put_contents("{$this->dir}/shim-files/spin/containerd-shim-spin-v2", 'truncated');

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString("runr: ERROR: containerd-shim-spin-v2 of image ghcr.io/brf-tech/runr/containerd-shim-spin:v0.26.0-runr.1 does not match the image's SHA256SUMS", $out);
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertSame(self::ORIGINAL_V3, $this->read('etc/containerd/config.toml'));
        $this->assertSame([], $this->restarts());

        // no files at all (the init container did not run)
        exec('rm -rf '.escapeshellarg("{$this->dir}/shim-files/spin"));
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('runr: ERROR: containerd-shim-spin-v2 or SHA256SUMS missing in', $out);
        $this->assertStringContainsString('(init container shim-spin, image ghcr.io/brf-tech/runr/containerd-shim-spin:v0.26.0-runr.1)', $out);
    }

    public function test_a_tarball_that_does_not_match_its_sha256_is_not_installed(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        // the published digest no longer matches the served file
        file_put_contents("{$this->dir}/dl/spin.tar.gz", 'tampered');

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('runr: ERROR: spin.tar.gz does not match its sha256', $out);
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertSame(self::ORIGINAL_V3, $this->read('etc/containerd/config.toml'));
        $this->assertSame([], $this->restarts());
    }

    public function test_a_config_containerd_rejects_is_left_unchanged_and_nothing_restarts(): void
    {
        $config = "# BROKEN\n".self::ORIGINAL_V3;
        $this->put('etc/containerd/config.toml', $config);
        $this->put('state/loaded', self::ORIGINAL_V3);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::install());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('runr: ERROR: containerd rejects /etc/containerd/config.toml.runr-new', $out);
        $this->assertStringContainsString('config.toml left unchanged, nothing restarted', $out);
        $this->assertSame($config, $this->read('etc/containerd/config.toml'));
        $this->assertFileDoesNotExist($this->path('etc/containerd/config.toml.runr-new'));
        $this->assertSame([], $this->restarts());
    }

    public function test_cleanup_restores_the_original_config_and_removes_the_binaries(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);
        [$code, $out] = $this->runOnNode(WasmNodeScripts::install(), 'containerd', ['spin', 'wasmtime']);
        $this->assertSame(0, $code, $out);
        $this->resetRestarts();

        [$code, $out] = $this->runOnNode(WasmNodeScripts::cleanup());

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3, $this->read('etc/containerd/config.toml'));
        $this->assertSame(['containerd'], $this->restarts());
        $this->assertStringNotContainsString('-runr', $this->read('state/loaded'));
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-spin-v2'));
        $this->assertFileDoesNotExist($this->path('usr/local/bin/containerd-shim-wasmtime-v1'));
        $this->assertDirectoryDoesNotExist($this->path('var/lib/runr/wasm'));
        $this->assertSame([], glob($this->path('etc/containerd/config.toml.*')), 'no backups / temp files left');
        $this->assertFileExists("{$this->dir}/done");
    }

    public function test_cleanup_of_a_clean_node_changes_nothing(): void
    {
        $this->put('etc/containerd/config.toml', self::ORIGINAL_V3);
        $this->put('state/loaded', self::ORIGINAL_V3);

        [$code, $out] = $this->runOnNode(WasmNodeScripts::cleanup());

        $this->assertSame(0, $code, $out);
        $this->assertSame(self::ORIGINAL_V3, $this->read('etc/containerd/config.toml'));
        $this->assertSame([], $this->restarts());
        $this->assertStringContainsString('no WebAssembly runtime loaded — no restart needed', $out);
    }
}
