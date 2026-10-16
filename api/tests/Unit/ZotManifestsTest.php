<?php

namespace Tests\Unit;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Manifests\ZotManifests;
use PHPUnit\Framework\TestCase;

class ZotManifestsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/runr-zot-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    // ── Helm values (zot chart 0.1.125) ─────────────────────────────────────

    public function test_pvc_values_use_chart_keys(): void
    {
        $values = ZotManifests::pvcValues('ceph-rbd', '50Gi');

        $this->assertTrue($values['persistence']);
        $this->assertSame([
            'create' => true,
            'accessModes' => ['ReadWriteOnce'],
            'storage' => '50Gi',
            'storageClassName' => 'ceph-rbd',
        ], $values['pvc']);

        $default = ZotManifests::pvcValues('', '20Gi');
        $this->assertArrayNotHasKey('storageClassName', $default['pvc']);
    }

    public function test_s3_values_disable_persistence(): void
    {
        $values = ZotManifests::s3Values([
            'endpoint' => 'https://s3', 'region' => 'r', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's',
        ]);

        $this->assertFalse($values['persistence']);
        $this->assertArrayNotHasKey('pvc', $values);
    }

    /**
     * Zot installed before the workers were selected sits on any node; its node-bound volume may
     * be on a node selected as a dedicated worker later, so it tolerates the taint (and prefers
     * the other nodes). With dedicated workers it runs on them.
     */
    public function test_zot_tolerates_the_dedicated_worker_taint_wherever_it_runs(): void
    {
        $toleration = ['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'];
        $s3 = ['endpoint' => 'https://s3', 'region' => 'r', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's'];

        foreach ([ZotManifests::pvcValues('', '20Gi'), ZotManifests::s3Values($s3)] as $values) {
            $this->assertSame([$toleration], $values['tolerations']);
            $this->assertArrayNotHasKey('nodeSelector', $values, 'any node');
            $this->assertSame(
                [['key' => 'runr.dev/dedicated', 'operator' => 'NotIn', 'values' => ['projects']]],
                $values['affinity']['nodeAffinity']['preferredDuringSchedulingIgnoredDuringExecution'][0]['preference']['matchExpressions'],
                'the other nodes preferred, not required',
            );
        }

        $workers = ZotManifests::pvcValues('', '20Gi', true);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $workers['nodeSelector']);
        $this->assertSame([$toleration], $workers['tolerations']);
        $this->assertArrayNotHasKey('affinity', $workers);
    }

    public function test_existing_statefulset_keeps_immutable_claim_template(): void
    {
        $sts = ['spec' => ['volumeClaimTemplates' => [[
            'metadata' => ['name' => 'runr-zot-pvc'],
            'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '8Gi']]],
        ]]]];

        $values = ZotManifests::preserveExistingPvc(ZotManifests::pvcValues('fast', '50Gi'), $sts);

        $this->assertSame('8Gi', $values['pvc']['storage']);
        $this->assertSame('runr-zot-pvc', $values['pvc']['name']);
        $this->assertArrayNotHasKey('storageClassName', $values['pvc']);

        $fresh = ZotManifests::pvcValues('fast', '50Gi');
        $this->assertSame($fresh, ZotManifests::preserveExistingPvc($fresh, null));
    }

    // ── Mirror DaemonSet ────────────────────────────────────────────────────

    public function test_containerd_daemonset_mounts_etc_containerd(): void
    {
        $ds = ZotManifests::mirrorDaemonSet('10.43.0.5:5000', 'runr-zot', 'runr-registry');
        $pod = $ds['spec']['template']['spec'];
        $script = $pod['containers'][0]['command'][2];

        $this->assertSame('/etc/containerd', $pod['volumes'][0]['hostPath']['path']);
        $this->assertSame('/host/etc/containerd', $pod['containers'][0]['volumeMounts'][0]['mountPath']);
        $this->assertStringContainsString('FLAVOR=containerd', $script);
        $this->assertStringContainsString('CERTS_PATH="/etc/containerd/certs.d"', $script);
        $this->assertStringContainsString('ensure_config_path', $script);
        $this->assertStringNotContainsString('__', $script);
    }

    public function test_k3s_daemonset_writes_k3s_certs_dir_only(): void
    {
        $ds = ZotManifests::mirrorDaemonSet('10.43.100.75:5000', 'runr-zot', 'runr-registry', false, ContainerRuntimeHelper::FLAVOR_K3S);
        $pod = $ds['spec']['template']['spec'];
        $script = $pod['containers'][0]['command'][2];

        $this->assertSame(['path' => '/var/lib/rancher/k3s/agent/etc/containerd', 'type' => 'Directory'], $pod['volumes'][0]['hostPath']);
        $this->assertStringContainsString('FLAVOR=k3s', $script);
        $this->assertStringContainsString('CERTS_ROOT="/host/containerd/certs.d"', $script);
        $this->assertStringNotContainsString('/host/etc', $script);
    }

    public function test_mirror_flavor_for_nodes(): void
    {
        $k3s = ['metadata' => ['name' => 'op'], 'status' => ['nodeInfo' => [
            'containerRuntimeVersion' => 'containerd://2.2.7-k3s1', 'kubeletVersion' => 'v1.34.11+k3s1',
        ]]];
        $crio = ['metadata' => ['name' => 'x'], 'status' => ['nodeInfo' => ['containerRuntimeVersion' => 'cri-o://1.31.0']]];

        $this->assertSame('k3s', ZotManifests::mirrorFlavorForNodes([$k3s]));
        $this->assertSame('containerd', ZotManifests::mirrorFlavorForNodes([$crio]));
        $this->assertSame('containerd', ZotManifests::mirrorFlavorForNodes([]));
    }

    // ── config_path rewrite (runs the generated shell/awk) ──────────────────

    private function requireShell(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell with awk');
        }
    }

    /**
     * @return array{0: string, 1: string} [config.toml after, script output]
     */
    private function runEnsureConfigPath(string $toml): array
    {
        $cfg = $this->dir.'/config.toml';
        file_put_contents($cfg, $toml);

        $script = 'CFG='.escapeshellarg($cfg)."\nCERTS_PATH=/etc/containerd/certs.d\n"
            .ZotManifests::containerdConfigPathScript()."\nensure_config_path\n";
        $out = shell_exec('sh -c '.escapeshellarg($script).' 2>&1');

        return [file_get_contents($cfg), (string) $out];
    }

    private function sectionConfigPaths(string $toml, string $header): array
    {
        $lines = explode("\n", $toml);
        $in = false;
        $found = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[/', $line)) {
                $in = trim($line) === $header;

                continue;
            }
            if ($in && preg_match('/^\s*config_path\s*=\s*(.+)$/', $line, $m)) {
                $found[] = trim($m[1]);
            }
        }

        return $found;
    }

    public function test_mirror_restart_hint_suggests_in_place_when_nodes_cannot_be_drained(): void
    {
        $script = fn (bool $inPlace) => ZotManifests::mirrorDaemonSet('10.43.0.5:5000', 'runr-zot', 'runr-registry', false, ContainerRuntimeHelper::FLAVOR_CONTAINERD, $inPlace)['spec']['template']['spec']['containers'][0]['command'][2];

        $this->assertStringContainsString('RESTART_CMD="php artisan registry:rolling-restart <region-id> --in-place"', $script(true));
        $this->assertStringContainsString('RESTART_CMD="php artisan registry:rolling-restart <region-id>"', $script(false));
        $this->assertStringNotContainsString('{regionId}', $script(false));

        // registry:redeploy-mirror knows the region
        $this->assertSame('php artisan registry:rolling-restart 01abc --in-place', ZotManifests::restartCommandHint(true, '01abc'));
        $this->assertSame('php artisan registry:rolling-restart 01abc', ZotManifests::restartCommandHint(false, '01abc'));
    }

    public function test_restart_required_line_prints_the_suggested_command(): void
    {
        $this->requireShell();

        $cfg = $this->dir.'/config.toml';
        file_put_contents($cfg, "version = 2\n\n[plugins]\n  [plugins.\"io.containerd.grpc.v1.cri\".registry]\n    config_path = \"\"\n");
        $script = 'CFG='.escapeshellarg($cfg)."\nCERTS_PATH=/etc/containerd/certs.d\n"
            .'RESTART_CMD='.escapeshellarg(ZotManifests::restartCommandHint(true))."\n"
            .ZotManifests::containerdConfigPathScript()."\nensure_config_path\n";
        $out = (string) shell_exec('sh -c '.escapeshellarg($script).' 2>&1');

        $this->assertStringContainsString('containerd restart required: php artisan registry:rolling-restart <region-id> --in-place', $out);
    }

    public function test_mirror_scripts_are_valid_sh(): void
    {
        $this->requireShell();

        foreach ([ContainerRuntimeHelper::FLAVOR_CONTAINERD, ContainerRuntimeHelper::FLAVOR_K3S] as $flavor) {
            $script = ZotManifests::mirrorDaemonSet('10.43.0.5:5000', 'runr-zot', 'runr-registry', false, $flavor)['spec']['template']['spec']['containers'][0]['command'][2];
            exec('sh -n -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, $flavor.': '.implode("\n", $out));
        }
    }

    public function test_v3_empty_config_path_is_replaced_not_duplicated(): void
    {
        $this->requireShell();

        [$toml, $out] = $this->runEnsureConfigPath(<<<'TOML'
version = 3

[plugins]
  [plugins.'io.containerd.cri.v1.images']
    snapshotter = 'overlayfs'

    [plugins.'io.containerd.cri.v1.images'.registry]
      config_path = ''

  [plugins.'io.containerd.cri.v1.runtime']
    enable_selinux = false
TOML);

        $this->assertSame(['"/etc/containerd/certs.d"'], $this->sectionConfigPaths($toml, "[plugins.'io.containerd.cri.v1.images'.registry]"));
        $this->assertSame(1, substr_count($toml, 'config_path'));
        $this->assertStringContainsString("snapshotter = 'overlayfs'", $toml);
        $this->assertStringContainsString('enable_selinux = false', $toml);
        $this->assertStringContainsString('containerd restart required', $out);
    }

    public function test_duplicates_left_by_old_mirror_are_repaired(): void
    {
        $this->requireShell();

        [$toml] = $this->runEnsureConfigPath(<<<'TOML'
version = 2

[plugins]
  [plugins."io.containerd.grpc.v1.cri"]
    [plugins."io.containerd.grpc.v1.cri".registry]
      config_path = "/etc/containerd/certs.d"
      config_path = ""
      [plugins."io.containerd.grpc.v1.cri".registry.auths]
TOML);

        $this->assertSame(['"/etc/containerd/certs.d"'], $this->sectionConfigPaths($toml, '[plugins."io.containerd.grpc.v1.cri".registry]'));
        $this->assertSame(1, substr_count($toml, 'config_path'));
    }

    public function test_already_configured_is_left_alone(): void
    {
        $this->requireShell();

        $orig = "version = 2\n[plugins.\"io.containerd.grpc.v1.cri\".registry]\n  config_path = \"/etc/containerd/certs.d:/etc/docker/certs.d\"\n";
        [$toml, $out] = $this->runEnsureConfigPath($orig);

        $this->assertSame($orig, $toml);
        $this->assertStringContainsString('already points to', $out);
        $this->assertFileDoesNotExist($this->dir.'/config.toml.runr.bak');
    }

    public function test_existing_custom_path_is_kept_behind_ours(): void
    {
        $this->requireShell();

        [$toml] = $this->runEnsureConfigPath("version = 2\n[plugins.\"io.containerd.grpc.v1.cri\".registry]\n  config_path = \"/etc/docker/certs.d\"\n");

        $this->assertStringContainsString('config_path = "/etc/containerd/certs.d:/etc/docker/certs.d"', $toml);
    }

    public function test_missing_section_is_added(): void
    {
        $this->requireShell();

        [$toml] = $this->runEnsureConfigPath("version = 3\n\n[plugins.'io.containerd.cri.v1.runtime']\n  enable_selinux = false\n");

        $this->assertSame(['"/etc/containerd/certs.d"'], $this->sectionConfigPaths($toml, "[plugins.'io.containerd.cri.v1.images'.registry]"));
    }

    public function test_mirrors_and_unknown_version_are_refused(): void
    {
        $this->requireShell();

        $mirrors = "version = 2\n[plugins.\"io.containerd.grpc.v1.cri\".registry]\n  config_path = \"\"\n"
            ."[plugins.\"io.containerd.grpc.v1.cri\".registry.mirrors.\"docker.io\"]\n  endpoint = [\"https://mirror\"]\n";
        [$toml, $out] = $this->runEnsureConfigPath($mirrors);
        $this->assertSame($mirrors, $toml);
        $this->assertStringContainsString('runr: ERROR', $out);

        $v1 = "[plugins.cri.registry]\n  config_path = \"\"\n";
        [$toml, $out] = $this->runEnsureConfigPath($v1);
        $this->assertSame($v1, $toml);
        $this->assertStringContainsString("unsupported containerd config version '1'", $out);
    }

    public function test_missing_config_file_is_reported_not_as_present(): void
    {
        $this->requireShell();

        $script = 'CFG='.escapeshellarg($this->dir.'/nope.toml')."\nCERTS_PATH=/etc/containerd/certs.d\n"
            .ZotManifests::containerdConfigPathScript()."\nensure_config_path\n";
        $out = (string) shell_exec('sh -c '.escapeshellarg($script).' 2>&1');

        $this->assertStringContainsString('not found', $out);
        $this->assertStringNotContainsString('already', $out);
        $this->assertFileDoesNotExist($this->dir.'/nope.toml');
    }

    public function test_k3s_generated_hosts_are_not_overwritten(): void
    {
        $this->requireShell();

        $certs = $this->dir.'/certs.d';
        mkdir($certs.'/docker.io', 0777, true);
        $k3sFile = "# File generated by k3s. DO NOT EDIT.\nserver = \"https://registry-1.docker.io/v2\"\n";
        file_put_contents($certs.'/docker.io/hosts.toml', $k3sFile);

        $script = "PORT=5000\nCURRENT_IP=10.43.100.75\nFLAVOR=k3s\nCERTS_ROOT=".escapeshellarg($certs)."\n"
            .ZotManifests::hostsTomlScript()."\nwrite_configs 10.43.100.75\n";
        $out = (string) shell_exec('sh -c '.escapeshellarg($script).' 2>&1');

        $this->assertSame($k3sFile, file_get_contents($certs.'/docker.io/hosts.toml'));
        $this->assertStringContainsString('left untouched', $out);
        $this->assertStringContainsString('[host."http://10.43.100.75:5000"]', file_get_contents($certs.'/10.43.100.75:5000/hosts.toml'));
    }

    // ── Sync upstreams (pull-through cache) ─────────────────────────────────

    public function test_mirror_config_syncs_ghcr_into_its_own_namespace_before_docker_hub(): void
    {
        $registries = ZotManifests::mirrorConfig()['extensions']['sync']['registries'];

        $this->assertSame(['https://ghcr.io'], $registries[0]['urls']);
        $this->assertTrue($registries[0]['onDemand']);
        $this->assertSame([['prefix' => '**', 'destination' => '/ghcr.io']], $registries[0]['content']);
        $this->assertSame('1h', $registries[0]['manifestCheckInterval']);
        $this->assertSame(['https://registry-1.docker.io'], $registries[1]['urls']);
        $this->assertSame([['prefix' => '**']], $registries[1]['content']);
        $this->assertArrayNotHasKey('manifestCheckInterval', $registries[1]);

        // Both Helm value variants render it (PVC and S3)
        $this->assertTrue(ZotManifests::hasGhcrUpstream(ZotManifests::pvcValues('', '20Gi')['configFiles']['config.json']));
        $this->assertTrue(ZotManifests::hasGhcrUpstream(ZotManifests::s3Values([
            'endpoint' => 'https://s3', 'region' => 'r', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's',
        ])['configFiles']['config.json']));
    }

    public function test_has_ghcr_upstream_needs_the_ghcr_namespace_mapping(): void
    {
        $config = fn (array $entry) => json_encode(['extensions' => ['sync' => ['registries' => [$entry]]]]);

        $this->assertFalse(ZotManifests::hasGhcrUpstream(null));
        $this->assertFalse(ZotManifests::hasGhcrUpstream('not json'));
        $this->assertFalse(ZotManifests::hasGhcrUpstream(json_encode(['storage' => []])));
        // Docker Hub only (a region installed before this release)
        $this->assertFalse(ZotManifests::hasGhcrUpstream($config(ZotManifests::syncRegistries(true)[1])));
        // An operator's ghcr.io entry for other content is not the cache builds use
        $this->assertFalse(ZotManifests::hasGhcrUpstream($config(['urls' => ['https://ghcr.io'], 'onDemand' => true, 'content' => [['prefix' => 'myorg/**']]])));
        $this->assertFalse(ZotManifests::hasGhcrUpstream($config(['urls' => ['https://ghcr.io'], 'onDemand' => false, 'content' => [['prefix' => '**', 'destination' => '/ghcr.io']]])));
        $this->assertTrue(ZotManifests::hasGhcrUpstream($config(['urls' => ['https://ghcr.io/'], 'onDemand' => true, 'content' => [['prefix' => '**', 'destination' => 'ghcr.io']]])));
    }

    public function test_manifest_check_interval_only_for_zot_that_knows_it(): void
    {
        $this->assertTrue(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot:v2.1.21'));
        $this->assertTrue(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot-linux-amd64:v2.2.0'));
        $this->assertTrue(ZotManifests::supportsManifestCheckInterval('registry.local:5000/zot:2.1.22@sha256:'.str_repeat('a', 64)));
        $this->assertFalse(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot:v2.1.20'));
        $this->assertFalse(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot:v2.1.21-rc1'));
        $this->assertFalse(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot@sha256:'.str_repeat('b', 64)));
        $this->assertFalse(ZotManifests::supportsManifestCheckInterval('ghcr.io/project-zot/zot:latest'));
        $this->assertFalse(ZotManifests::supportsManifestCheckInterval(null));
    }

    public function test_reconcile_touches_only_runr_sync_entries(): void
    {
        $operatorQuay = ['urls' => ['https://quay.io'], 'onDemand' => true, 'content' => [['prefix' => '**', 'destination' => '/quay.io']]];
        $operatorGhcr = ['urls' => ['https://ghcr.io'], 'onDemand' => false, 'pollInterval' => '6h', 'content' => [['prefix' => 'myorg/**']]];
        $installed = [
            'storage' => ['rootDirectory' => '/var/lib/registry', 'storageDriver' => ['name' => 's3', 'secretkey' => 'SK']],
            'http' => ['address' => '0.0.0.0', 'port' => '5000'],
            'log' => ['level' => 'debug'],
            'extensions' => [
                'search' => new \stdClass,
                'sync' => [
                    'credentialsFile' => '/secret/creds.json',
                    'registries' => [
                        $operatorQuay,
                        // RunR's Docker Hub entry with an operator-added key and an outdated one
                        ['urls' => ['https://registry-1.docker.io'], 'onDemand' => true, 'tlsVerify' => false, 'content' => [['prefix' => '**']], 'maxRetries' => 3],
                        $operatorGhcr,
                    ],
                ],
            ],
        ];
        $json = json_encode($installed);

        $new = ZotManifests::reconcileSyncConfig($json, true);
        $this->assertNotNull($new);
        $out = json_decode($new, true);

        [$ghcr, $hub] = ZotManifests::syncRegistries(true);
        $this->assertSame([$operatorQuay, $ghcr, $hub + ['maxRetries' => 3], $operatorGhcr], $out['extensions']['sync']['registries']);
        $this->assertSame('/secret/creds.json', $out['extensions']['sync']['credentialsFile']);
        $this->assertSame($installed['storage'], $out['storage']);
        $this->assertSame('debug', $out['log']['level']);
        // An empty object stays an object (Zot would reject [] for a map)
        $this->assertStringContainsString('"search":{}', $new);

        // Idempotent
        $this->assertNull(ZotManifests::reconcileSyncConfig($new, true));
        // Key order / slash escaping does not count as a change
        $this->assertNull(ZotManifests::reconcileSyncConfig(json_encode(json_decode($new, true), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), true));
    }

    public function test_reconcile_moves_runr_ghcr_in_front_of_docker_hub_and_drops_the_interval_for_old_zot(): void
    {
        [$ghcr, $hub] = ZotManifests::syncRegistries(true);
        $json = json_encode(['extensions' => ['sync' => ['registries' => [$hub, $ghcr + ['maxRetries' => 2]]]]]);

        $out = json_decode(ZotManifests::reconcileSyncConfig($json, false), true);

        $withoutInterval = $ghcr + ['maxRetries' => 2];
        unset($withoutInterval['manifestCheckInterval']);
        $this->assertSame([$withoutInterval, $hub], $out['extensions']['sync']['registries']);
    }

    public function test_reconcile_adds_both_upstreams_to_a_config_without_sync(): void
    {
        $out = json_decode(ZotManifests::reconcileSyncConfig('{"storage":{"rootDirectory":"/var/lib/registry"}}', true), true);

        $this->assertSame(ZotManifests::syncRegistries(true), $out['extensions']['sync']['registries']);
        $this->assertSame(['rootDirectory' => '/var/lib/registry'], $out['storage']);
        $this->assertNull(ZotManifests::reconcileSyncConfig(json_encode(ZotManifests::mirrorConfig()), true));
    }

    public function test_reconcile_rejects_invalid_json(): void
    {
        $this->expectException(\JsonException::class);
        ZotManifests::reconcileSyncConfig('{"storage":', true);
    }
}
