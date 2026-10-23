<?php

namespace Tests\Feature\OrgTransfer;

use App\Models\KnativeRegion;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use App\Services\OrgTransfer\Region\TransferTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesImageCluster;
use Tests\TestCase;

/**
 * The transfer pods of binding data and images (docs/org-export.md#data-and-images): a Job with
 * the transfer agent and a tool container sharing an emptyDir, hardened (non-root, no service
 * account token, capabilities dropped), credentials only in a Secret, a random token on every
 * request through the API server's service proxy; downloads in ranges, uploads from the agent's
 * offset, failures and timeouts surface, stop() removes everything.
 */
class RegionTransferTest extends TestCase
{
    use FakesImageCluster, RefreshDatabase;

    private KnativeRegion $region;

    /** @var array<string, mixed> what the fake agent reports on GET status */
    private array $agentStatus = ['state' => 'working', 'files' => []];

    private string $served = '';

    private int $received = 0;

    /** @var list<array{method: string, path: string, query: string, headers: array, body: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.transfer_chunk_bytes' => 4]);
        $this->region = $this->imageRegion();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'query' => $query, 'headers' => $request->headers(), 'body' => $request->body()];

            if (! preg_match('#/services/http:(runr-xfer-[a-z0-9-]+):8080/proxy/(.+)$#', $path, $m)) {
                return $request->method() === 'GET' ? Http::response(['items' => []]) : Http::response(['kind' => 'Status'], 200);
            }
            if (($request->header(RegionTransfer::TOKEN_HEADER)[0] ?? '') === '') {
                return Http::response(['error' => 'token'], 401);
            }
            parse_str($query, $q);

            return match (true) {
                $m[2] === 'status' => Http::response($this->agentStatus),
                str_starts_with($m[2], 'files/') && str_ends_with($m[2], '/complete') => Http::response(['complete' => true]),
                str_starts_with($m[2], 'files/') && $request->method() === 'GET' => $this->range($request),
                str_starts_with($m[2], 'files/') && $request->method() === 'PUT' => $this->chunk((int) ($q['offset'] ?? 0), $request->body()),
                $m[2] === 'stop' => Http::response(['stopping' => true]),
                default => Http::response(['error' => 'unknown'], 404),
            };
        });
    }

    private function range(Request $request)
    {
        preg_match('/bytes=(\d+)-(\d+)/', (string) ($request->header('Range')[0] ?? ''), $r);
        $start = (int) $r[1];
        $end = min((int) $r[2], strlen($this->served) - 1);

        return Http::response(substr($this->served, $start, $end - $start + 1), 206, ['Content-Range' => "bytes {$start}-{$end}/".strlen($this->served)]);
    }

    private function chunk(int $offset, string $bytes)
    {
        if ($offset <= $this->received) {
            $this->received = max($this->received, $offset + strlen($bytes));
        }

        return Http::response(['received' => $this->received, 'size' => 0]);
    }

    private function transfer(): RegionTransfer
    {
        return new class extends RegionTransfer
        {
            protected function sleep(int $seconds): void {}
        };
    }

    private function tool(): TransferTool
    {
        return new TransferTool('ghcr.io/cloudnative-pg/postgresql:17.5', TransferTool::wrap('pg_dump -f /work/out/data.dump'), ['pg.env' => "PGPASSWORD='s3cr3t'\n"]);
    }

    public function test_the_pod_is_hardened_and_keeps_credentials_in_its_secret(): void
    {
        $m = $this->transfer()->manifests($this->region, 'runr-data', 'runr-xfer-dump-db-abc123', 'tok', RegionTransfer::MODE_RECEIVE, $this->tool(),
            ['data.dump' => ['size' => 10, 'sha256' => str_repeat('a', 64)]], null);

        $pod = $m['job']['spec']['template']['spec'];
        $this->assertFalse($pod['automountServiceAccountToken']);
        $this->assertTrue($pod['securityContext']['runAsNonRoot']);
        $this->assertSame(65532, $pod['securityContext']['fsGroup']);
        $this->assertSame(0, $m['job']['spec']['backoffLimit']);

        [$agent, $tool] = $pod['containers'];
        $this->assertSame(['transfer'], $agent['args']);
        $this->assertTrue($agent['securityContext']['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $tool['securityContext']['capabilities']['drop']);
        $env = collect($agent['env'])->keyBy('name');
        $this->assertSame('receive', $env['RUNR_TRANSFER_MODE']['value']);
        $this->assertSame([['name' => 'data.dump', 'size' => 10, 'sha256' => str_repeat('a', 64)]], json_decode($env['RUNR_TRANSFER_FILES']['value'], true));
        $this->assertArrayNotHasKey('value', $env['RUNR_TRANSFER_TOKEN'], 'the token comes from the Secret');
        $this->assertSame(['sh', '/ops/run.sh'], $tool['command']);

        $manifestJson = json_encode([$m['job'], $m['service'], $m['policy']]);
        $this->assertStringNotContainsString('s3cr3t', $manifestJson, 'no credential in the pod spec');
        $this->assertSame(base64_encode("PGPASSWORD='s3cr3t'\n"), $m['secret']['data']['ops-pg.env']);
        $this->assertSame(base64_encode('tok'), $m['secret']['data']['token']);

        $this->assertSame(['runr.sh/org-transfer' => 'runr-xfer-dump-db-abc123'], $m['policy']['spec']['podSelector']['matchLabels']);
        $this->assertSame(8080, $m['policy']['spec']['ingress'][0]['ports'][0]['port']);
        // a sender whose tool has ended is not Ready, its agent still serves the files
        $this->assertTrue($m['service']['spec']['publishNotReadyAddresses']);
        $this->assertSame('bindings-ops', $m['job']['metadata']['labels']['runr.dev/purpose'], 'the data namespace lets ops pods reach its services');
    }

    public function test_a_pulling_sender_needs_no_tool(): void
    {
        $m = $this->transfer()->manifests($this->region, 'fn', 'runr-xfer-image-api-x', 'tok', RegionTransfer::MODE_SEND, null, [], self::IMG_ZOT.'/runr-builds/api:v3');

        $containers = $m['job']['spec']['template']['spec']['containers'];
        $this->assertCount(1, $containers);
        $env = collect($containers[0]['env'])->keyBy('name');
        $this->assertSame(self::IMG_ZOT.'/runr-builds/api:v3', $env['RUNR_TRANSFER_PULL']['value']);
        $this->assertSame('true', $env['RUNR_REGISTRY_PLAIN_HTTP']['value']);
    }

    public function test_a_sender_is_downloaded_in_ranges(): void
    {
        $transfer = $this->transfer();
        $handle = $transfer->startSender($this->region, 'runr-data', 'dump db', $this->tool());

        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/namespaces/runr-data/jobs')), 'the Job is created');

        $this->served = 'pg-dump-bytes';
        $this->agentStatus = ['state' => 'done', 'files' => [['name' => 'data.dump', 'size' => 13, 'sha256' => hash('sha256', 'pg-dump-bytes')]]];
        $status = $transfer->waitFor($handle, ['done']);
        $this->assertSame(13, $status['files'][0]['size']);

        $target = $this->testStorage.'/data.dump';
        $progress = [];
        $transfer->download($handle, 'data.dump', 13, $target, function ($done, $total) use (&$progress) {
            $progress[] = $done;
        });
        $this->assertSame('pg-dump-bytes', file_get_contents($target));
        $this->assertSame([4, 8, 12, 13], $progress);

        $ranges = array_values(array_filter($this->calls, fn ($c) => str_contains($c['path'], '/proxy/files/data.dump') && $c['method'] === 'GET'));
        $this->assertSame('bytes=0-3', $ranges[0]['headers']['Range'][0]);
        $this->assertSame($handle->token, $ranges[0]['headers'][RegionTransfer::TOKEN_HEADER][0]);

        $transfer->stop($handle);
        $deletes = array_map(fn ($c) => $c['path'], array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE'));
        $this->assertNotEmpty(array_filter($deletes, fn ($p) => str_contains($p, '/jobs/'.$handle->name)));
        $this->assertNotEmpty(array_filter($deletes, fn ($p) => str_contains($p, '/secrets/'.$handle->name)));
        $this->assertNotEmpty(array_filter($deletes, fn ($p) => str_contains($p, '/networkpolicies/'.$handle->name)));
    }

    public function test_a_download_resumes_after_what_is_on_disk(): void
    {
        $transfer = $this->transfer();
        $handle = $transfer->startSender($this->region, 'runr-data', 'dump db', $this->tool());
        $this->served = 'abcdefghij';
        $target = $this->testStorage.'/part';
        file_put_contents($target, 'abcdef');

        $transfer->download($handle, 'data.dump', 10, $target);

        $this->assertSame('abcdefghij', file_get_contents($target));
        $first = array_values(array_filter($this->calls, fn ($c) => str_contains($c['path'], '/proxy/files/') && $c['method'] === 'GET'))[0];
        $this->assertSame('bytes=6-9', $first['headers']['Range'][0]);
    }

    public function test_an_upload_continues_from_the_agents_offset_and_completes(): void
    {
        $transfer = $this->transfer();
        $handle = $transfer->startReceiver($this->region, 'runr-data', 'restore db', $this->tool(), ['data.dump' => ['size' => 10, 'sha256' => hash('sha256', '0123456789')]]);
        $this->received = 4;
        $this->agentStatus = ['state' => 'receiving', 'files' => [['name' => 'data.dump', 'size' => 10, 'received' => 4]]];
        $source = $this->testStorage.'/data.dump';
        file_put_contents($source, '0123456789');

        $transfer->upload($handle, 'data.dump', $source);

        $puts = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'PUT' && str_contains($c['path'], '/proxy/files/data.dump')));
        $this->assertSame(['offset=4', 'offset=8'], array_column($puts, 'query'));
        $this->assertSame('4567', $puts[0]['body']);
        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/proxy/files/data.dump/complete')));
    }

    public function test_a_failed_transfer_reports_the_tools_reason(): void
    {
        $transfer = $this->transfer();
        $handle = $transfer->startSender($this->region, 'runr-data', 'dump db', $this->tool());
        $this->agentStatus = ['state' => 'failed', 'message' => 'pg_dump failed: permission denied'];

        $this->expectException(RegionTransferException::class);
        $this->expectExceptionMessage('pg_dump failed: permission denied');
        $transfer->waitFor($handle, ['done']);
    }

    public function test_a_transfer_that_does_not_finish_times_out(): void
    {
        $transfer = $this->transfer();
        $handle = $transfer->startSender($this->region, 'runr-data', 'dump db', $this->tool());

        $this->expectException(RegionTransferException::class);
        $transfer->waitFor($handle, ['done'], null, 0);
    }
}
