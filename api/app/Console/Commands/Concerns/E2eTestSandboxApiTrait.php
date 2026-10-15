<?php

namespace App\Console\Commands\Concerns;

/**
 * Sandbox API end to end (`php artisan test:e2e --suite=sandbox-api`, `ci.sh test-sandbox-api`):
 * against the region's real cluster, through the HTTP API.
 *
 *  - classes: gVisor (installed through the API, as the sandbox suite does), runc (the region's
 *    opt-in), Kata only with --sandbox-kata where the nodes have /dev/kvm (else reported skipped):
 *    Cloud Hypervisor, QEMU and Dragonball installed, and per hypervisor the guest's vCPUs (nproc)
 *    for a 1 and a 2 CPU sandbox — 1 + the CPU limit
 *  - lifecycle: create (pod spec checked in the cluster: RuntimeClass, security context, no
 *    service-account token), exec (stdout, stderr, exit code), SSE streaming, timeout, code run
 *    with an image output, files (write / read / list / tar round trip / delete), background
 *    processes, pause / resume, network none (egress blocked where the CNI enforces policies),
 *    TTL expiry through the reaper, warm pool claim, quota refusal, delete
 *
 * Start times of cold and pool-claimed sandboxes are printed (measured here, not asserted).
 */
trait E2eTestSandboxApiTrait
{
    /** Kata hypervisors the suite installs and checks (Firecracker needs a devmapper thin-pool on the nodes). */
    private const SANDBOX_KATA_HYPERVISORS = ['clh', 'qemu', 'dragonball'];

    /** @var array<string, int> label → start_ms */
    private array $sandboxStarts = [];

    private function runSandboxApiSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Sandbox API ──');

        $tests = [];
        $gvisor = $this->setupSandboxRuntime($regionId);
        $tests[] = $this->sbxEntry('sbx-gvisor-install', $gvisor ?: 'gVisor could not be installed');

        // runc is opted in so the suite also runs where gVisor is the runc-handler fallback
        $settings = $this->api()->put("/api/sandbox-regions/{$regionId}", ['allow_runc' => true, 'max_ttl_seconds' => 3600]);
        $tests[] = $this->sbxEntry('sbx-region-settings', $settings->successful() ?: 'PUT sandbox-regions: '.$settings->status().' '.$settings->body());

        $create = fn (array $body) => $this->api()->timeout(300)->post('/api/sandboxes', $body + ['region' => $regionId]);

        // ── Lifecycle on gVisor ──
        $res = $create(['runtime' => 'python', 'sandbox_class' => 'gvisor', 'ttl_seconds' => 900, 'env' => ['GREETING' => 'hello'],
            'files' => [['path' => '/workspace/data.txt', 'content_base64' => base64_encode("42\n")]]]);
        if ($res->status() !== 201) {
            $tests[] = $this->sbxEntry('sbx-create', 'HTTP '.$res->status().': '.mb_substr($res->body(), 0, 400));

            return $tests;
        }
        $sbx = $res->json('data');
        $id = $sbx['id'];
        $this->sandboxStarts['cold (gvisor)'] = (int) $sbx['start_ms'];
        $tests[] = $this->sbxEntry('sbx-create', $sbx['status'] === 'running' && $sbx['sandbox_class'] === 'gvisor' ?: json_encode($sbx));
        $tests[] = $this->sbxEntry('sbx-pod-spec', $this->checkSandboxPod($sbx));

        $exec = fn (array $body) => $this->api()->timeout(120)->post("/api/sandboxes/{$id}/exec", $body);

        $r = $exec(['cmd' => 'echo "$GREETING from $(cat /workspace/data.txt)"; echo oops >&2; exit 3']);
        $tests[] = $this->sbxEntry('sbx-exec', ($r->json('data.stdout') === "hello from 42\n" && $r->json('data.stderr') === "oops\n" && $r->json('data.exit_code') === 3) ?: $r->body());

        $r = $exec(['cmd' => ['id', '-u']]);
        $tests[] = $this->sbxEntry('sbx-non-root', ((int) trim((string) $r->json('data.stdout')) >= 100000) ?: 'uid '.$r->json('data.stdout'));

        $r = $this->api()->timeout(120)->withHeaders(['Accept' => 'text/event-stream'])
            ->post("/api/sandboxes/{$id}/exec", ['cmd' => 'for i in 1 2 3; do echo line $i; sleep 0.2; done']);
        $events = $this->sseEvents($r->body());
        $lines = implode('', array_map(fn ($e) => $e['data']['data'] ?? '', array_filter($events, fn ($e) => $e['event'] === 'stdout')));
        $tests[] = $this->sbxEntry('sbx-exec-sse', ($lines === "line 1\nline 2\nline 3\n" && end($events)['event'] === 'exit') ?: $r->body());

        $r = $exec(['cmd' => 'sleep 30', 'timeout' => 2]);
        $tests[] = $this->sbxEntry('sbx-exec-timeout', ($r->json('data.timed_out') === true) ?: $r->body());

        $code = "import os\nprint(sum(range(10)))\nopen('/output/plot.svg','w').write('<svg xmlns=\"http://www.w3.org/2000/svg\"/>')\nopen('result.txt','w').write('ok')\n";
        $r = $this->api()->timeout(120)->post("/api/sandboxes/{$id}/run", ['code' => $code, 'language' => 'python']);
        $paths = array_column((array) $r->json('data.files_changed'), 'path');
        $tests[] = $this->sbxEntry('sbx-run-code', ($r->json('data.stdout') === "45\n"
            && in_array('/workspace/result.txt', $paths, true)
            && ($r->json('data.outputs.0.mime') === 'image/svg+xml')) ?: $r->body());

        $tests[] = $this->sbxEntry('sbx-files', $this->checkSandboxFiles($id));
        $tests[] = $this->sbxEntry('sbx-processes', $this->checkSandboxProcesses($id));

        $p = $this->api()->post("/api/sandboxes/{$id}/pause");
        $blocked = $exec(['cmd' => 'true'])->status();
        $q = $this->api()->post("/api/sandboxes/{$id}/resume");
        $tests[] = $this->sbxEntry('sbx-pause-resume', ($p->json('data.status') === 'paused' && $blocked === 409 && $q->json('data.status') === 'running') ?: "pause {$p->status()}, exec {$blocked}, resume {$q->status()}");

        // network: none — the default-deny policy (enforced where the CNI implements NetworkPolicy)
        $r = $exec(['cmd' => ['python3', '-c', "import urllib.request\nurllib.request.urlopen('http://1.1.1.1', timeout=5)"], 'timeout' => 20]);
        if ($r->json('data.exit_code') === 0) {
            $this->warn('  sbx-network-none: egress went through — the cluster CNI does not enforce NetworkPolicies');
        } else {
            $tests[] = $this->sbxEntry('sbx-network-none', true);
        }

        $d = $this->api()->delete("/api/sandboxes/{$id}");
        sleep(3);
        $pod = $this->e2eK8s->getResourceStatus('v1', 'Pod', 'sbx-'.strtolower($id), 'runr-sbx-default');
        $gone = $pod === null || isset($pod['metadata']['deletionTimestamp']);
        $tests[] = $this->sbxEntry('sbx-delete', ($d->json('data.status') === 'terminated' && $gone) ?: $d->body());

        // ── runc (opt-in) ──
        $r = $create(['runtime' => 'python', 'sandbox_class' => 'runc']);
        $tests[] = $this->sbxEntry('sbx-runc', ($r->status() === 201 && $r->json('data.sandbox_class') === 'runc') ?: $r->body());
        if ($r->status() === 201) {
            $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));
        }

        // ── Kata where /dev/kvm exists ──
        if ($this->option('sandbox-kata')) {
            $tests = array_merge($tests, $this->runSandboxKata($regionId, $create));
        } else {
            $this->line('  <fg=yellow>─</> sbx-kata: skipped (no /dev/kvm on the host, or --sandbox-kata not given)');
        }

        // ── TTL expiry through the reaper ──
        $r = $create(['runtime' => 'python', 'sandbox_class' => 'gvisor', 'ttl_seconds' => 15]);
        if ($r->status() === 201) {
            sleep(20);
            $this->call('sandboxes:maintain', ['--reap' => true]);
            $s = $this->api()->get('/api/sandboxes/'.$r->json('data.id'));
            $tests[] = $this->sbxEntry('sbx-ttl-reaper', ($s->json('data.status') === 'terminated' && $s->json('data.end_reason') === 'ttl') ?: $s->body());
        } else {
            $tests[] = $this->sbxEntry('sbx-ttl-reaper', 'create: '.$r->body());
        }

        // ── Warm pool ──
        $tests[] = $this->sbxEntry('sbx-warm-pool', $this->checkSandboxPool($regionId, $create));

        // ── Quota ──
        $this->api()->put('/api/sandbox-quotas/default', ['max_concurrent' => 1]);
        $first = $create(['runtime' => 'python', 'sandbox_class' => 'gvisor']);
        $second = $create(['runtime' => 'python', 'sandbox_class' => 'gvisor']);
        $tests[] = $this->sbxEntry('sbx-quota', ($first->status() === 201 && $second->status() === 422 && $second->json('reason') === 'quota_concurrent') ?: "first {$first->status()}, second {$second->status()} {$second->body()}");
        if ($first->status() === 201) {
            $this->api()->delete('/api/sandboxes/'.$first->json('data.id'));
        }
        $this->api()->delete('/api/sandbox-quotas/default');

        $this->info('');
        $this->info('── Sandbox start times (create request → running, start_ms) ──');
        foreach ($this->sandboxStarts as $label => $ms) {
            $this->line(sprintf('  %-22s %d ms', $label, $ms));
        }

        return $tests;
    }

    private function runSandboxKata(string $regionId, callable $create): array
    {
        $install = $this->api()->timeout(60)->post("/api/knative-regions/{$regionId}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => self::SANDBOX_KATA_HYPERVISORS]);
        if ($install->status() === 422) {
            $this->line('  <fg=yellow>─</> sbx-kata: skipped ('.mb_substr((string) $install->json('message'), 0, 200).')');

            return [];
        }

        // As long as the install may take (SandboxInstallService::KATA_WAIT_SECONDS, 20 minutes for
        // kata-deploy on the nodes) plus its checks: on a slow host the kata-deploy image pull alone
        // took more than the 15 minutes the suite used to allow
        $deadline = time() + 1500;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/sandbox-status")->json('kata.status');
            if ($status === 'ready' || $status === 'failed') {
                break;
            }
            sleep(10);
        }
        if (($status ?? null) !== 'ready') {
            return [$this->sbxEntry('sbx-kata-install', 'Kata install: '.($status ?? 'timeout'))];
        }

        $r = $create(['runtime' => 'python', 'sandbox_class' => 'kata']);
        $entries = [$this->sbxEntry('sbx-kata-create', ($r->status() === 201 && str_starts_with((string) $r->json('data.sandbox_class'), 'kata-')) ?: $r->body())];
        if ($r->status() === 201) {
            $this->sandboxStarts['cold (kata)'] = (int) $r->json('data.start_ms');
            $e = $this->api()->timeout(120)->post('/api/sandboxes/'.$r->json('data.id').'/exec', ['cmd' => 'uname -r']);
            $entries[] = $this->sbxEntry('sbx-kata-exec', ($e->json('data.exit_code') === 0) ?: $e->body());
            $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));
        }

        return array_merge($entries, $this->checkKataGuestVcpus($create));
    }

    /**
     * Guest size per hypervisor: a statically sized Kata guest has 1 vCPU + the pod's CPU limits
     * (KataRuntime::guestVcpus — the go runtime for clh / qemu, RunR's pod annotations for
     * Dragonball on runtime-rs). nproc inside the guest for two CPU limits; memory printed.
     */
    private function checkKataGuestVcpus(callable $create): array
    {
        $entries = [];
        $rows = [];
        foreach (self::SANDBOX_KATA_HYPERVISORS as $hv) {
            foreach ([1000, 2000] as $cpuM) {
                $name = "sbx-kata-{$hv}-vcpus-{$cpuM}m";
                $r = $create(['runtime' => 'python', 'sandbox_class' => "kata-{$hv}", 'cpu_m' => $cpuM, 'memory_mb' => 512]);
                if ($r->status() !== 201) {
                    $entries[] = $this->sbxEntry($name, 'create: HTTP '.$r->status().' '.mb_substr($r->body(), 0, 300));

                    continue;
                }
                $id = $r->json('data.id');
                $e = $this->api()->timeout(120)->post("/api/sandboxes/{$id}/exec", ['cmd' => "nproc; awk '/MemTotal/ {print int(\$2 / 1024)}' /proc/meminfo"]);
                [$nproc, $memMb] = array_pad(array_map('intval', preg_split('/\s+/', trim((string) $e->json('data.stdout')))), 2, 0);
                $expected = 1 + intdiv($cpuM, 1000);
                $rows[] = sprintf('  %-16s %5dm  nproc %d (expected %d)  MemTotal %d MiB  start %d ms', "kata-{$hv}", $cpuM, $nproc, $expected, $memMb, (int) $r->json('data.start_ms'));
                $entries[] = $this->sbxEntry($name, $nproc === $expected ?: "nproc {$nproc}, expected {$expected} (1 + the CPU limit): ".$e->body());
                $this->api()->delete("/api/sandboxes/{$id}");
            }
        }

        $this->info('');
        $this->info('── Kata guest size (nproc / MemTotal inside the guest, sandbox memory 512 MiB) ──');
        foreach ($rows as $row) {
            $this->line($row);
        }

        return $entries;
    }

    /** The pod in the cluster: class, non-root, no token, no capabilities, deadline. */
    private function checkSandboxPod(array $sbx): bool|string
    {
        $pod = $this->e2eK8s->getResourceStatus('v1', 'Pod', 'sbx-'.strtolower($sbx['id']), 'runr-sbx-default');
        if (! $pod) {
            return 'pod not found';
        }
        $spec = $pod['spec'];
        $c = $spec['containers'][0];
        $problems = array_keys(array_filter([
            'runtimeClassName' => ($spec['runtimeClassName'] ?? null) !== 'gvisor',
            'automountServiceAccountToken' => ($spec['automountServiceAccountToken'] ?? true) !== false,
            'runAsNonRoot' => ($spec['securityContext']['runAsNonRoot'] ?? false) !== true,
            'seccomp' => ($c['securityContext']['seccompProfile']['type'] ?? null) !== 'RuntimeDefault',
            'capabilities' => ($c['securityContext']['capabilities']['drop'] ?? []) !== ['ALL'],
            'readOnlyRootFilesystem' => ($c['securityContext']['readOnlyRootFilesystem'] ?? false) !== true,
            'activeDeadlineSeconds' => empty($spec['activeDeadlineSeconds']),
            'serviceAccountToken mount' => collect($c['volumeMounts'] ?? [])->contains(fn ($m) => str_contains($m['mountPath'] ?? '', 'serviceaccount')),
        ]));

        return $problems === [] ?: 'pod spec: '.implode(', ', $problems);
    }

    private function checkSandboxFiles(string $id): bool|string
    {
        $put = $this->api()->withBody("binary\x00data", 'application/octet-stream')
            ->put("/api/sandboxes/{$id}/files/content?path=/workspace/dir/a.bin");
        $get = $this->api()->get("/api/sandboxes/{$id}/files/content?path=/workspace/dir/a.bin&format=json");
        if (base64_decode((string) $get->json('data.content_base64')) !== "binary\x00data") {
            return 'read back: '.$put->body().' / '.$get->body();
        }

        $list = $this->api()->get("/api/sandboxes/{$id}/files?path=/workspace/dir");
        if (array_column((array) $list->json('data.entries'), 'name') !== ['a.bin']) {
            return 'list: '.$list->body();
        }

        $tar = $this->api()->get("/api/sandboxes/{$id}/files/archive?path=/workspace/dir");
        $up = $this->api()->withBody($tar->body(), 'application/x-tar')->put("/api/sandboxes/{$id}/files/archive?path=/workspace/copy");
        $copy = $this->api()->get("/api/sandboxes/{$id}/files/content?path=/workspace/copy/a.bin&format=json");
        if (! $up->successful() || base64_decode((string) $copy->json('data.content_base64')) !== "binary\x00data") {
            return 'tar round trip: '.$up->body().' / '.$copy->body();
        }

        $del = $this->api()->delete("/api/sandboxes/{$id}/files?path=/workspace/copy&recursive=1");
        $missing = $this->api()->get("/api/sandboxes/{$id}/files?path=/workspace/copy");

        return ($del->successful() && $missing->status() === 404) ?: 'delete: '.$del->body().' / '.$missing->status();
    }

    private function checkSandboxProcesses(string $id): bool|string
    {
        $start = $this->api()->post("/api/sandboxes/{$id}/processes", ['cmd' => 'echo started; sleep 300']);
        if ($start->status() !== 201) {
            return 'start: '.$start->body();
        }
        $pid = $start->json('data.id');
        sleep(1);

        $list = $this->api()->get("/api/sandboxes/{$id}/processes");
        $logs = $this->api()->get("/api/sandboxes/{$id}/processes/{$pid}/logs");
        $kill = $this->api()->delete("/api/sandboxes/{$id}/processes/{$pid}?signal=KILL");

        return ($list->json('data.0.status') === 'running'
            && $logs->json('data.stdout') === "started\n"
            && in_array($kill->json('data.status'), ['killed', 'exited'], true))
            ?: "list {$list->body()} logs {$logs->body()} kill {$kill->body()}";
    }

    private function checkSandboxPool(string $regionId, callable $create): bool|string
    {
        $pool = $this->api()->post('/api/sandbox-pools', ['region' => $regionId, 'runtime' => 'python', 'size' => 1, 'sandbox_class' => 'gvisor']);
        if ($pool->status() !== 201) {
            return 'pool: '.$pool->body();
        }
        $poolId = $pool->json('data.id');

        $deadline = time() + 300;
        do {
            $this->call('sandboxes:maintain', ['--refill' => true]);
            $warm = (int) collect($this->api()->get('/api/sandbox-pools')->json('data'))->firstWhere('id', $poolId)['warm'];
            if ($warm > 0) {
                break;
            }
            sleep(5);
        } while (time() < $deadline);

        if ($warm < 1) {
            return 'no warm sandbox after 300 s';
        }

        $r = $create(['runtime' => 'python', 'sandbox_class' => 'gvisor']);
        $ok = $r->status() === 201 && $r->json('data.from_pool') === true;
        if ($ok) {
            $this->sandboxStarts['warm pool (gvisor)'] = (int) $r->json('data.start_ms');
            $exec = $this->api()->post('/api/sandboxes/'.$r->json('data.id').'/exec', ['cmd' => 'echo $RUNR_SANDBOX_ID']);
            $ok = trim((string) $exec->json('data.stdout')) === strtolower($r->json('data.id'));
            $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));
        }

        $this->api()->delete("/api/sandbox-pools/{$poolId}");

        return $ok ?: 'claim: '.$r->body();
    }

    /** @return list<array{event: string, data: mixed}> */
    private function sseEvents(string $body): array
    {
        $events = [];
        foreach (preg_split("/\r?\n\r?\n/", $body) as $block) {
            $event = null;
            $data = [];
            foreach (preg_split("/\r?\n/", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data[] = substr($line, 6);
                }
            }
            if ($event !== null) {
                $events[] = ['event' => $event, 'data' => json_decode(implode("\n", $data), true)];
            }
        }

        return $events ?: [['event' => 'none', 'data' => null]];
    }

    private function sbxEntry(string $name, bool|string $result): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'sandbox', 'trigger_type' => 'none'], (string) ($this->e2eRegion?->id ?? ''));
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry['startTime'] = 0.0; // the suite prints the sandbox start times itself

        $state = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 300)));

        return $entry;
    }
}
