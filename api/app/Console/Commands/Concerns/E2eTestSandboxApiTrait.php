<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\InstallSandboxJob;
use App\Services\SandboxInstallService;

/**
 * Sandbox API end to end (`php artisan test:e2e --suite=sandbox-api`, `ci.sh test-sandbox-api`):
 * against the region's real cluster, through the HTTP API.
 *
 *  - classes: gVisor (installed through the API, as the sandbox suite does), runc (the region's
 *    opt-in), Kata only with --sandbox-kata where the nodes have /dev/kvm (else reported skipped):
 *    Cloud Hypervisor, QEMU and Dragonball installed (one the install reports unavailable on the
 *    nodes is skipped with its reason), and per hypervisor the guest's vCPUs (nproc) for a 1 and a
 *    2 CPU sandbox — 1 + the CPU limit; a sandbox that does not start prints its pod and events
 *  - lifecycle: create (pod spec checked in the cluster: RuntimeClass, security context, no
 *    service-account token), exec (stdout, stderr, exit code), SSE streaming, the exec WebSocket
 *    with a terminal (size, resize, typed input, exit code), timeout, code run
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

        $kataOnly = self::sbxKataOnly((string) ($this->option('sandbox-cases') ?: (getenv('RUNR_E2E_SANDBOX_CASES') ?: '')));
        if ($kataOnly) {
            $this->line('  cases: Kata only (--sandbox-cases / RUNR_E2E_SANDBOX_CASES=kata): the Kata install and cases, then the quota case');
        }

        $tests = [];
        if (! $kataOnly) {
            $gvisor = $this->setupSandboxRuntime($regionId);
            $tests[] = $this->sbxEntry('sbx-gvisor-install', $gvisor ?: 'gVisor could not be installed');
        }

        // runc is opted in so the suite also runs where gVisor is the runc-handler fallback
        $settings = $this->api()->put("/api/sandbox-regions/{$regionId}", ['allow_runc' => true, 'max_ttl_seconds' => 3600]);
        $tests[] = $this->sbxEntry('sbx-region-settings', $settings->successful() ?: 'PUT sandbox-regions: '.$settings->status().' '.$settings->body());

        $create = fn (array $body) => $this->api()->timeout(300)->post('/api/sandboxes', $body + ['region' => $regionId]);

        if ($kataOnly) {
            $tests = array_merge($tests, $this->option('sandbox-kata')
                ? $this->runSandboxKata($regionId, $create)
                : [$this->sbxEntry('sbx-kata', 'Kata cases selected, but the host has no /dev/kvm (--sandbox-kata not given)')]);
            $tests[] = $this->sbxEntry('sbx-quota', $this->checkSandboxQuota($create, 'kata'));
            $this->printSandboxStarts();

            return $tests;
        }

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
        $tests[] = $this->sbxEntry('sbx-exec-tty', $this->checkSandboxTtyExec($id));

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
        $tests[] = $this->sbxEntry('sbx-quota', $this->checkSandboxQuota($create, 'gvisor'));

        $this->printSandboxStarts();

        return $tests;
    }

    /** --sandbox-cases / RUNR_E2E_SANDBOX_CASES: "kata" = only the Kata cases (with their setup and the quota case). */
    public static function sbxKataOnly(string $cases): bool
    {
        $names = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($cases)) ?: [])));

        return $names !== [] && array_diff($names, ['kata']) === [];
    }

    private function printSandboxStarts(): void
    {
        $this->info('');
        $this->info('── Sandbox start times (create request → running, start_ms) ──');
        foreach ($this->sandboxStarts as $label => $ms) {
            $this->line(sprintf('  %-22s %d ms', $label, $ms));
        }
    }

    /**
     * The organization's concurrency quota: with max_concurrent 1 the first sandbox starts and the
     * second is refused (422 quota_concurrent). Sandboxes earlier cases left alive (one whose start
     * failed, one not deleted) are deleted first, so the case counts only its own.
     */
    private function checkSandboxQuota(callable $create, string $class): bool|string
    {
        $left = $this->sbxDeleteLiveSandboxes();
        if ($left !== []) {
            $this->line('  sbx-quota: deleted '.count($left).' sandbox(es) earlier cases left alive: '.implode(', ', $left));
        }

        $this->api()->put('/api/sandbox-quotas/default', ['max_concurrent' => 1]);
        try {
            $first = $create(['runtime' => 'python', 'sandbox_class' => $class]);
            $second = $create(['runtime' => 'python', 'sandbox_class' => $class]);
            foreach ([$first, $second] as $r) {
                if (is_string($r->json('data.id'))) {
                    $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));
                }
            }
        } finally {
            $this->api()->delete('/api/sandbox-quotas/default');
        }

        return ($first->status() === 201 && $second->status() === 422 && $second->json('reason') === 'quota_concurrent')
            ?: "first {$first->status()} ".mb_substr($first->body(), 0, 300).", second {$second->status()} {$second->body()}";
    }

    /**
     * Deletes the organization's live sandboxes (pending, running, paused): what earlier cases left
     * behind.
     *
     * @return list<string> each one's id, status and class before the delete
     */
    private function sbxDeleteLiveSandboxes(): array
    {
        $deleted = [];
        foreach ((array) $this->api()->get('/api/sandboxes', ['status' => 'active', 'per_page' => 200])->json('data') as $sbx) {
            if (! is_array($sbx) || ! is_string($sbx['id'] ?? null)) {
                continue;
            }
            $this->api()->delete('/api/sandboxes/'.$sbx['id']);
            $deleted[] = $sbx['id'].' ('.($sbx['status'] ?? '?').', '.($sbx['sandbox_class'] ?? '?').')';
        }

        return $deleted;
    }

    /**
     * What a sandbox's pod is doing (phase, conditions, containers, the kubelet's last events with
     * their whole message: a Kata runtime's error is in FailedCreatePodSandBox) and the sandbox's
     * own status and error.
     */
    private function sbxDiagnosis(string $id): string
    {
        $sbx = (array) $this->api()->get("/api/sandboxes/{$id}")->json('data');
        $state = 'sandbox '.($sbx['status'] ?? '?').(! empty($sbx['error']) ? ' ('.mb_substr((string) $sbx['error'], 0, 600).')' : '');

        return $state.' — pod: '.$this->podDiagnosisOf($this->e2eK8s, 'runr-sbx-default', 'sbx-'.strtolower($id), 1500);
    }

    private function runSandboxKata(string $regionId, callable $create): array
    {
        $hypervisors = self::SANDBOX_KATA_HYPERVISORS;
        $install = $this->api()->timeout(120)->post("/api/knative-regions/{$regionId}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => $hypervisors]);
        $unavailable = $install->status() === 422 ? (array) $install->json('unavailable') : [];
        if ($unavailable !== [] && array_diff($hypervisors, array_keys($unavailable)) !== []) {
            // The region cannot run some of them (the install's node check): those are skipped
            // with the install's reason, the others installed and checked
            foreach ($unavailable as $hv => $reason) {
                $this->line("  <fg=yellow>─</> sbx-kata-{$hv}-vcpus-*: skipped (the install reports kata-{$hv} unavailable: ".mb_substr((string) $reason, 0, 400).')');
            }
            $hypervisors = array_values(array_diff($hypervisors, array_keys($unavailable)));
            $install = $this->api()->timeout(120)->post("/api/knative-regions/{$regionId}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => $hypervisors]);
        }
        if ($install->status() === 422) {
            $this->line('  <fg=yellow>─</> sbx-kata: skipped ('.mb_substr((string) $install->json('message'), 0, 400).')');

            return [];
        }

        // As long as the install job may run (InstallSandboxJob::$timeout: kata-deploy on the nodes,
        // SandboxInstallService::KATA_WAIT_SECONDS, and its checks) plus its time in the queue: the
        // job ends ready or error (its own deadline, or the job's timeout through failed()) before
        // this gives up. On a slow host the kata-deploy image pull alone took more than 15 minutes.
        $jobTimeout = (int) ((new \ReflectionClass(InstallSandboxJob::class))->getDefaultProperties()['timeout'] ?? 1500);
        $deadline = time() + $jobTimeout + 300;
        $kata = [];
        while (time() < $deadline) {
            $kata = (array) $this->api()->get("/api/knative-regions/{$regionId}/sandbox-status")->json('kata');
            if (in_array($kata['status'] ?? null, ['ready', 'error', 'failed'], true)) {
                break;
            }
            sleep(10);
        }
        if (($kata['status'] ?? null) !== 'ready') {
            // the install's own log (its last steps, kata-deploy's pending nodes) and the installer
            // pods in the cluster: what the region and its nodes were doing
            $log = implode(' | ', array_slice(array_filter(explode("\n", (string) ($kata['message'] ?? ''))), -4));

            return [$this->sbxEntry('sbx-kata-install', 'Kata install: '.($kata['status'] ?? 'no status').' — '.$log.' — '.$this->sbxKataInstallerPods())];
        }

        $r = $create(['runtime' => 'python', 'sandbox_class' => 'kata']);
        $entries = [$this->sbxEntry('sbx-kata-create', ($r->status() === 201 && str_starts_with((string) $r->json('data.sandbox_class'), 'kata-')) ?: $r->body())];
        if ($r->status() === 201) {
            $this->sandboxStarts['cold (kata)'] = (int) $r->json('data.start_ms');
            $e = $this->api()->timeout(120)->post('/api/sandboxes/'.$r->json('data.id').'/exec', ['cmd' => 'uname -r']);
            $entries[] = $this->sbxEntry('sbx-kata-exec', ($e->json('data.exit_code') === 0) ?: $e->body());
            $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));
        }

        return array_merge($entries, $this->checkKataGuestVcpus($create, $hypervisors));
    }

    /**
     * The Kata installer's pods (runr-sandbox): node, phase, each container's waiting / terminated
     * reason and restarts, and kata-deploy's last log lines — whether the install waited on the
     * nodes (an image pull, a runtime restart) or RunR.
     */
    private function sbxKataInstallerPods(): string
    {
        try {
            $ns = SandboxInstallService::SANDBOX_NAMESPACE;
            $lines = [];
            foreach ($this->e2eK8s->listResources('v1', 'Pod', $ns)['items'] ?? [] as $pod) {
                $name = (string) ($pod['metadata']['name'] ?? '?');
                $containers = [];
                foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $c) {
                    $state = (array) ($c['state'] ?? []);
                    $why = $state['waiting']['reason'] ?? $state['terminated']['reason'] ?? (isset($state['running']) ? 'running' : '?');
                    $containers[] = ($c['name'] ?? '?').' '.$why.' restarts '.(int) ($c['restartCount'] ?? 0);
                }
                $line = $name.' on '.($pod['spec']['nodeName'] ?? '-').' '.($pod['status']['phase'] ?? '?').' ['.implode('; ', $containers).']';
                if (str_starts_with($name, 'kata')) {
                    $tail = array_slice(array_filter(explode("\n", (string) $this->e2eK8s->getPodLogs($name, $ns, 'kata-deploy', 5))), -2);
                    $line .= $tail ? ' log: '.implode(' / ', $tail) : '';
                }
                $lines[] = $line;
            }

            return $lines ? 'pods: '.mb_substr(implode(' || ', $lines), 0, 1200) : 'no installer pods';
        } catch (\Throwable $e) {
            return 'pods not read: '.$e->getMessage();
        }
    }

    /**
     * Guest size per hypervisor: a statically sized Kata guest has 1 vCPU + the pod's CPU limits
     * (KataRuntime::guestVcpus — the go runtime for clh / qemu, RunR's pod annotations for
     * Dragonball on runtime-rs). nproc inside the guest for two CPU limits; memory printed.
     *
     * @param  list<string>  $hypervisors  the ones installed
     */
    private function checkKataGuestVcpus(callable $create, array $hypervisors): array
    {
        $entries = [];
        $rows = [];
        foreach ($hypervisors as $hv) {
            foreach ([1000, 2000] as $cpuM) {
                $name = "sbx-kata-{$hv}-vcpus-{$cpuM}m";
                $r = $create(self::kataSizeRequest($hv, $cpuM));
                if ($r->status() !== 201) {
                    $why = 'create: HTTP '.$r->status().' '.mb_substr($r->body(), 0, 300);
                    $failedId = $r->json('data.id');
                    if (is_string($failedId)) {
                        // the pod's state and the kubelet's events (a Kata runtime's error), then the
                        // sandbox goes: a start that does not end must not hold the quota
                        $why .= ' — '.$this->sbxDiagnosis($failedId);
                        $this->api()->delete("/api/sandboxes/{$failedId}");
                    }
                    $this->line("  {$name}: {$why}");
                    $entries[] = $this->sbxEntry($name, $why);

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

    /**
     * Create request of a guest-size check: the size in the API's fields (`cpu` as millicores,
     * `memory` in MiB, docs/sandbox-api.md), not the response's cpu_m / memory_mb.
     */
    private static function kataSizeRequest(string $hypervisor, int $cpuM): array
    {
        return ['runtime' => 'python', 'sandbox_class' => "kata-{$hypervisor}", 'cpu' => "{$cpuM}m", 'memory' => 512];
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
        $claimedAt = microtime(true);
        $ok = $r->status() === 201 && $r->json('data.from_pool') === true;
        if ($ok) {
            $this->sandboxStarts['warm pool (gvisor)'] = (int) $r->json('data.start_ms');
            $exec = $this->api()->post('/api/sandboxes/'.$r->json('data.id').'/exec', ['cmd' => 'echo $RUNR_SANDBOX_ID']);
            $ok = trim((string) $exec->json('data.stdout')) === strtolower($r->json('data.id'));
            $this->api()->delete('/api/sandboxes/'.$r->json('data.id'));

            // The claim's refill (queue) starts a replacement, warm as soon as its pod runs:
            // claim → warm again is printed with the start times (not asserted)
            do {
                $pools = collect($this->api()->get('/api/sandbox-pools')->json('data'));
                if ((int) ($pools->firstWhere('id', $poolId)['warm'] ?? 0) > 0) {
                    $this->sandboxStarts['pool warm again'] = (int) round((microtime(true) - $claimedAt) * 1000);
                    break;
                }
                usleep(250_000);
            } while (microtime(true) - $claimedAt < 90);
        }

        $this->api()->delete("/api/sandbox-pools/{$poolId}");

        return $ok ?: 'claim: '.$r->body();
    }

    /** @return list<array{event: string, data: mixed}> */
    /**
     * The exec WebSocket with a terminal: a TTY of the requested size, a resize frame that reaches
     * the command, typed input (Enter is \r on a terminal) and the command's exit code.
     */
    private function checkSandboxTtyExec(string $id): bool|string
    {
        $script = '[ -t 0 ] && [ -t 1 ] && echo tty-yes; stty size; read a; stty size; read b; echo "got:$b"; exit 7';
        $r = $this->api()->timeout(60)->post("/api/sandboxes/{$id}/exec/ws", [
            'cmd' => ['/bin/sh', '-c', $script], 'tty' => true, 'cols' => 100, 'rows' => 30, 'timeout' => 60,
        ]);
        if (! $r->successful() || $r->json('data.tty') !== true) {
            return 'POST exec/ws: '.$r->status().' '.mb_substr($r->body(), 0, 300);
        }
        $ws = $this->wsUpgrade((string) $r->json('data.token'), '/sandbox-exec');
        if (! $ws) {
            return 'the exec WebSocket did not open';
        }

        $out = '';
        $start = $exit = null;
        $step = 0;
        try {
            $deadline = time() + 60;
            while ($exit === null && time() < $deadline) {
                $frame = $this->wsReadFrame($ws, 10);
                if ($frame === null) {
                    if (feof($ws)) {
                        break;
                    }

                    continue;
                }
                $msg = (array) json_decode($frame, true);
                match ($msg['type'] ?? null) {
                    'start' => $start = $msg,
                    'stdout', 'stderr' => $out .= base64_decode((string) ($msg['data'] ?? '')),
                    'exit' => $exit = $msg,
                    'error' => $exit = $msg,
                    default => null,
                };
                if ($step === 0 && str_contains($out, '30 100')) {
                    // A new window size, then Enter: the command prints the size it sees now
                    $step = 1;
                    $this->wsSendText($ws, (string) json_encode(['type' => 'resize', 'cols' => 120, 'rows' => 40]));
                    usleep(500_000);
                    $this->wsSendText($ws, (string) json_encode(['type' => 'stdin', 'data' => base64_encode("next\r")]));
                } elseif ($step === 1 && str_contains($out, '40 120')) {
                    $step = 2;
                    $this->wsSendText($ws, (string) json_encode(['type' => 'stdin', 'data' => base64_encode("hello\r")]));
                }
            }
        } finally {
            @fclose($ws);
        }

        $ok = ($start['tty'] ?? null) === true && str_contains($out, 'tty-yes') && $step === 2
            && str_contains($out, 'got:hello') && ($exit['exit_code'] ?? null) === 7;

        return $ok ?: 'start '.json_encode($start).', exit '.json_encode($exit).', output '.json_encode(mb_substr($out, 0, 400));
    }

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
