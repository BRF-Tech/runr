<?php

namespace Modules\Tenancy\Console\Concerns;

use App\Models\ProjectSecret;
use App\Models\User;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * Organization export / import between two tenants of one installation (docs/org-export.md):
 * tenant A's project (with a secret) is exported, downloaded, uploaded into tenant B in chunks,
 * planned and applied with the passphrase — B gets the project and its secret, A keeps its own,
 * each side audits its part. Regions are the dummy rows of these tests: no cluster, no data, no
 * deploy (the Kind suite `ci.sh test-org-transfer` covers those).
 */
trait TenancyE2eOrgTransferCasesTrait
{
    private const OT_TENANCY_PASSPHRASE = 'tenancy e2e org transfer';

    protected function runOrgTransferTests(array &$results): void
    {
        $this->info('');
        $this->info('── Organization export / import (tenant A → tenant B) ──');

        $owner = User::on('central')->where('username', 'test')->first();
        $state = [];

        $results[] = $this->runTest('Org transfer: owners of both tenants', function () use ($owner) {
            $this->e2eAssert($owner && $this->tenantAId && $this->tenantBId && $this->managedRegionId, 'Skip: prereq');
            foreach ([$this->tenantAId, $this->tenantBId] as $tenant) {
                TenantUser::query()->updateOrCreate(['tenant_id' => $tenant, 'user_id' => $owner->id], ['role' => 'owner']);
            }
            // B may have lost the managed region in the app cases: the import maps into it
            $this->api()->post("/api/tenants/{$this->tenantBId}/regions", ['region_id' => $this->managedRegionId, 'cpu_quota_m' => 1000, 'memory_quota_mb' => 2048]);

            $r = $this->api($this->userToken, $this->tenantBId)->post('/api/org-transfers/export', []);
            $this->e2eAssert($r->status() === 202, "B owner may export: {$r->status()} — {$r->body()}");
            $this->api($this->userToken, $this->tenantBId)->delete('/api/org-transfers/'.$r->json('data.id'));

            // an administrator of B (not its owner) is refused; the platform administrator who
            // created the tenants is an owner of both (TenantController::store), so the member is
            // B's administrator for this check and its owner again afterwards
            TenantUser::query()->where('tenant_id', $this->tenantBId)->where('user_id', $owner->id)->update(['role' => 'admin']);
            try {
                $admin = $this->api($this->userToken, $this->tenantBId)->post('/api/org-transfers/export', []);
            } finally {
                TenantUser::query()->where('tenant_id', $this->tenantBId)->where('user_id', $owner->id)->update(['role' => 'owner']);
            }
            $this->e2eAssert($admin->status() === 403 && $admin->json('code') === 'owner_only', "an administrator must not export: {$admin->status()} {$admin->body()}");
        });

        $results[] = $this->runTest('Org transfer: export tenant A', function () use (&$state) {
            $a = $this->api($this->userToken, $this->tenantAId);
            $p = $a->post('/api/projects', $this->snippetProject('ot-orders'));
            $this->e2eAssert($p->status() === 201, "project: {$p->status()} — {$p->body()}");
            $state['source'] = $p->json('id') ?? $p->json('data.id');
            // The regions of these tests have no cluster: a value cannot live in the region
            // (pass-through, docs/security.md), the API refuses it. The value is held in A's
            // database as on an install whose values were not moved to the regions yet
            // (secrets:move-to-region), which the export reads like any other.
            $s = $a->put("/api/projects/{$state['source']}/secrets/OT_KEY", ['value' => 'tenant-a-secret']);
            $this->e2eAssert(in_array($s->status(), [422, 503], true), "a region without a cluster takes no value: {$s->status()}");
            Tenant::query()->findOrFail($this->tenantAId)->run(fn () => ProjectSecret::query()->create([
                'project_id' => $state['source'], 'name' => 'OT_KEY', 'value' => 'tenant-a-secret',
                'fingerprint' => SecretFingerprint::of('tenant-a-secret'),
            ]));

            $e = $a->post('/api/org-transfers/export', ['projects' => [$state['source']], 'include_secrets' => true, 'passphrase' => self::OT_TENANCY_PASSPHRASE]);
            $this->e2eAssert($e->status() === 202, "export: {$e->status()} — {$e->body()}");
            $done = $this->otTenancyWait($this->tenantAId, (string) $e->json('data.id'), ['completed', 'failed']);
            $this->e2eAssert(($done['status'] ?? null) === 'completed', 'export: '.json_encode($done));

            $download = $a->timeout(300)->get("/api/org-transfers/{$done['id']}/download");
            $this->e2eAssert($download->status() === 200, "download: {$download->status()}");
            $this->e2eAssert(hash('sha256', $download->body()) === $done['package']['sha256'], 'package checksum');
            $state['package'] = $download->body();
        });

        $results[] = $this->runTest('Org transfer: import into tenant B', function () use (&$state) {
            $this->e2eAssert(isset($state['package']), 'Skip: no package');
            $b = $this->api($this->userToken, $this->tenantBId);
            $bytes = $state['package'];
            $start = $b->post('/api/org-transfers/import', ['filename' => 'tenant-a.tar.zst', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
            $this->e2eAssert($start->status() === 201, "import: {$start->status()} — {$start->body()}");
            $id = (string) $start->json('data.id');
            $chunk = (int) $start->json('data.upload.chunk_size');
            for ($offset = 0; $offset < strlen($bytes); $offset += $chunk) {
                $r = Http::withToken($this->userToken)->accept('application/json')->baseUrl($this->baseUrl)
                    ->withHeaders(['X-Tenant-ID' => $this->tenantBId])
                    ->withBody(substr($bytes, $offset, $chunk), 'application/octet-stream')
                    ->put("/api/org-transfers/{$id}/chunk?offset={$offset}");
                $this->e2eAssert($r->successful(), "chunk {$offset}: {$r->status()} — {$r->body()}");
            }
            $c = $b->post("/api/org-transfers/{$id}/complete");
            $this->e2eAssert($c->status() === 202, "complete: {$c->status()} — {$c->body()}");
            $planned = $this->otTenancyWait($this->tenantBId, $id, ['planned', 'failed']);
            $this->e2eAssert(($planned['status'] ?? null) === 'planned', 'verify: '.json_encode($planned));
            $this->e2eAssert(($planned['plan']['projects'][0]['collision'] ?? true) === false, 'tenant slugs differ: no collision');

            $apply = $b->post("/api/org-transfers/{$id}/apply", ['passphrase' => self::OT_TENANCY_PASSPHRASE]);
            $this->e2eAssert($apply->status() === 202, "apply: {$apply->status()} — {$apply->body()}");
            $done = $this->otTenancyWait($this->tenantBId, $id, ['completed', 'failed']);
            $this->e2eAssert(($done['status'] ?? null) === 'completed', 'apply: '.json_encode($done['report'] ?? $done));
            $state['report'] = $done['report'] ?? [];
        });

        $results[] = $this->runTest('Org transfer: B has the copy, A keeps its own', function () use (&$state) {
            $this->e2eAssert(isset($state['source']), 'Skip: no source');
            $b = $this->api($this->userToken, $this->tenantBId);
            $copy = collect($b->get('/api/projects')->json('data') ?? $b->get('/api/projects')->json())->firstWhere('name', 'ot-orders');
            $this->e2eAssert((bool) $copy, 'no copy in B');
            $this->e2eAssert($copy['id'] !== $state['source'], 'a new project');
            // the passphrase opened the sealed value; B's region (no cluster) takes no values, so
            // the import lists it as a manual step instead of setting it (the Kind suite
            // test-org-transfer restores values into a region)
            $manual = collect($state['report']['manual_steps'] ?? [])->firstWhere('code', 'secrets');
            $this->e2eAssert(in_array('ot-orders: OT_KEY', $manual['items'] ?? [], true), 'the secret is a manual step of B: '.json_encode($state['report']['manual_steps'] ?? null));

            $a = $this->api($this->userToken, $this->tenantAId)->get("/api/projects/{$state['source']}");
            $this->e2eAssert($a->successful(), 'A keeps its project');

            $auditB = $this->api($this->userToken, $this->tenantBId)->get('/api/audit-events', ['action' => 'org.import.completed'])->json('data') ?? [];
            $auditA = $this->api($this->userToken, $this->tenantAId)->get('/api/audit-events', ['action' => 'org.export.completed'])->json('data') ?? [];
            $this->e2eAssert($auditB !== [] && $auditA !== [], 'both sides audited');
        });
    }

    /** @return array<string, mixed> */
    private function otTenancyWait(string $tenantId, string $id, array $statuses, int $timeout = 300): array
    {
        $deadline = time() + $timeout;
        $last = [];
        while (time() < $deadline) {
            $last = $this->api($this->userToken, $tenantId)->get("/api/org-transfers/{$id}")->json('data') ?? [];
            if (in_array($last['status'] ?? null, $statuses, true)) {
                return $last;
            }
            sleep(2);
        }

        return $last;
    }
}
