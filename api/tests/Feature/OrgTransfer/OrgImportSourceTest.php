<?php

namespace Tests\Feature\OrgTransfer;

use App\Jobs\OrgTransfer\FetchImportPackageJob;
use App\Jobs\OrgTransfer\VerifyImportPackageJob;
use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use App\Models\User;
use App\Services\Bindings\BindingsCluster;
use App\Services\OrgTransfer\OrgExporter;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\OrgTransferProgress;
use App\Services\OrgTransfer\OrgTransferService;
use App\Services\OrgTransfer\Region\BindingDumps;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\TransferTool;
use App\Services\OrgTransfer\Sources\PackageSources;
use App\Services\OrgTransfer\Sources\S3Reader;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesImageCluster;
use Tests\Support\FakeRegionTransfer;
use Tests\TestCase;

/**
 * An import read straight from where an export writes (docs/org-export.md#import): an object
 * binding of the organization (a transfer pod copies the object out of the bucket, RunR downloads
 * it through the API server) or an S3-compatible bucket (HeadObject, ranged GetObject with
 * If-Match). Resumable after the bytes held; credentials only in the encrypted job; the fetched
 * package is verified and planned like an upload.
 */
class OrgImportSourceTest extends TestCase
{
    use ActsWithRoles, FakesImageCluster, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    private FakeRegionTransfer $transfer;

    /** @var list<array{method: string, range: ?string, if_match: ?string, url: string, auth: string}> */
    private array $s3Calls = [];

    /** @var array{bytes: string, etag: string, failAt: ?int, replaceAt: ?int, status: int}|null what the fake bucket answers */
    private ?array $s3 = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.compression' => 'none', 'org_transfer.fetch_chunk_bytes' => 1024 * 1024]);
        Queue::fake();
        $this->owner = $this->userWithRole('owner');
        $this->actingAs($this->owner, 'sanctum');
        $this->region = $this->imageRegion(['name' => 'eu-1']);

        $this->transfer = new FakeRegionTransfer;
        $this->app->instance(RegionTransfer::class, $this->transfer);
        $this->mock(BindingsCluster::class, fn ($mock) => $mock->shouldReceive('ensureDataNamespace'));
        $this->app->instance(BindingDumps::class, new class extends BindingDumps
        {
            public function __construct() {}

            public function bucketDownloadTool(Binding $binding, string $key, string $file): TransferTool
            {
                return new TransferTool('rclone', "copy {$key} {$file}");
            }
        });
        // No pauses between retried ranges
        $this->app->bind(PackageSources::class, fn ($app) => new class($app->make(RegionTransfer::class), $app->make(BindingDumps::class), $app->make(S3Reader::class), $app->make(OrgTransferProgress::class)) extends PackageSources
        {
            protected function pause(int $seconds): void {}
        });
    }

    private function bucket(): Binding
    {
        return Binding::query()->create([
            'name' => 'backups', 'resource_name' => 'backups-abcdefgh', 'type' => 'object', 'plan' => 'shared',
            'knative_region_id' => $this->region->id, 'status' => Binding::STATUS_READY,
            'credentials' => ['bucket' => 'backups-abcdefgh', 'access_key_id' => 'k', 'secret_access_key' => 's'],
        ]);
    }

    private function s3Source(array $extra = []): array
    {
        return array_merge(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'region' => 'eu-1',
            'key' => 'dr/runr-org-acme.tar.zst', 'access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret'], $extra);
    }

    /**
     * A bucket holding $bytes under the source's key (ETag $etag); $failAt: answer 503 to the
     * GET starting there once; $replaceAt: answer 412 there once (the object was replaced).
     */
    private function fakeS3(string $bytes, string $etag = '"etag-1"', ?int $failAt = null, ?int $replaceAt = null, int $status = 200): void
    {
        $this->s3Calls = [];
        $registered = $this->s3 !== null;
        $this->s3 = ['bytes' => $bytes, 'etag' => $etag, 'failAt' => $failAt, 'replaceAt' => $replaceAt, 'status' => $status];
        if ($registered) {
            return; // one fake answers from $this->s3 (Http::fake() adds callbacks, the first one answers)
        }

        Http::fake(function (Request $request) {
            $psr = $request->toPsrRequest();
            $range = $psr->getHeaderLine('Range') ?: null;
            $this->s3Calls[] = ['method' => $request->method(), 'range' => $range, 'if_match' => $psr->getHeaderLine('If-Match') ?: null, 'url' => $request->url(), 'auth' => $psr->getHeaderLine('Authorization')];
            $bucket = &$this->s3;
            if ($bucket['status'] !== 200) {
                return Http::response('<Error><Code>NoSuchKey</Code></Error>', $bucket['status']);
            }
            if ($request->method() === 'HEAD') {
                return Http::response('', 200, ['Content-Length' => (string) strlen($bucket['bytes']), 'ETag' => $bucket['etag']]);
            }
            preg_match('/bytes=(\d+)-(\d+)/', (string) $range, $m);
            $from = (int) $m[1];
            if ($bucket['failAt'] !== null && $from === $bucket['failAt']) {
                $bucket['failAt'] = null;

                return Http::response('slow down', 503);
            }
            if ($bucket['replaceAt'] !== null && $from === $bucket['replaceAt']) {
                $bucket['replaceAt'] = null;

                return Http::response('', 412);
            }

            return Http::response(substr($bucket['bytes'], $from, (int) $m[2] - $from + 1), 206);
        });
    }

    private function fetch(OrgTransfer $transfer, ?array $credentials): OrgTransfer
    {
        (new FetchImportPackageJob($transfer->id, $credentials))->handle(app(PackageSources::class), app(OrgTransferService::class));

        return $transfer->fresh();
    }

    public function test_an_import_from_s3_is_queued_with_the_credentials_only_in_the_job(): void
    {
        $response = $this->postJson('/api/org-transfers/import', ['source' => $this->s3Source()])->assertStatus(202);

        $transfer = OrgTransfer::query()->findOrFail($response->json('data.id'));
        $this->assertSame('fetching', $transfer->status);
        $this->assertSame('runr-org-acme.tar.zst', $transfer->package_filename);
        $this->assertSame(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'region' => 'eu-1', 'path_style' => true, 'key' => 'dr/runr-org-acme.tar.zst'], $transfer->import_source);
        $response->assertJsonPath('data.source.type', 's3');
        $this->assertStringNotContainsString('very-secret', json_encode($transfer->getAttributes()));
        $this->assertStringNotContainsString('very-secret', $response->getContent());
        Queue::assertPushed(FetchImportPackageJob::class, fn (FetchImportPackageJob $job) => $job->transferId === $transfer->id
            && $job->credentials === ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret']);
        $this->assertContains(ShouldBeEncrypted::class, class_implements(FetchImportPackageJob::class), 'encrypted payload');

        $audit = AuditEvent::query()->where('action', 'org.import.start')->firstOrFail();
        $this->assertSame('s3', $audit->metadata['source']);
        $this->assertStringNotContainsString('very-secret', json_encode($audit->metadata));
    }

    public function test_sources_are_validated(): void
    {
        $this->postJson('/api/org-transfers/import', ['source' => $this->s3Source(['secret_access_key' => null])])->assertStatus(422)->assertJsonValidationErrors('source.secret_access_key');
        $this->postJson('/api/org-transfers/import', ['source' => $this->s3Source(['key' => '../../etc/passwd'])])->assertStatus(422)->assertJsonValidationErrors('source.key');
        $db = Binding::query()->create(['name' => 'db', 'resource_name' => 'db-abcdefgh', 'type' => 'postgres', 'plan' => 'shared', 'knative_region_id' => $this->region->id, 'status' => Binding::STATUS_READY]);
        $this->postJson('/api/org-transfers/import', ['source' => ['type' => 'binding', 'binding_id' => $db->id, 'key' => 'x.tar.zst']])->assertStatus(422)->assertJsonValidationErrors('source.binding_id');
        $this->postJson('/api/org-transfers/import', ['source' => ['type' => 'ftp', 'key' => 'x']])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_the_s3_read_goes_in_ranges_with_if_match_then_verifies_like_an_upload(): void
    {
        $bytes = random_bytes(2 * 1024 * 1024 + 100);
        $this->fakeS3($bytes);
        $transfer = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar.zst'],
            ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret'], hash('sha256', $bytes), $this->owner);

        $transfer = $this->fetch($transfer, ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret']);

        $this->assertSame('verifying', $transfer->status, (string) $transfer->message);
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($transfer)));
        $this->assertSame(strlen($bytes), $transfer->upload_size);
        $this->assertSame(strlen($bytes), $transfer->uploaded_bytes);
        $this->assertSame('"etag-1"', $transfer->import_source['etag']);
        $this->assertSame(['HEAD', 'GET', 'GET', 'GET'], array_column($this->s3Calls, 'method'));
        $this->assertSame(['bytes=0-1048575', 'bytes=1048576-2097151', 'bytes=2097152-'.(strlen($bytes) - 1)], array_values(array_filter(array_column($this->s3Calls, 'range'))));
        $this->assertSame(['"etag-1"'], array_values(array_unique(array_filter(array_column($this->s3Calls, 'if_match')))));
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIA123/', $this->s3Calls[1]['auth']);
        Queue::assertPushed(VerifyImportPackageJob::class, fn ($job) => $job->transferId === $transfer->id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'org.import.fetched')->count());
    }

    public function test_a_resumed_s3_read_continues_after_the_bytes_held_and_starts_over_for_another_object(): void
    {
        $bytes = random_bytes(3 * 1024 * 1024);
        $this->fakeS3($bytes);
        $transfer = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar.zst'], null, null, $this->owner);
        // An earlier attempt got the first MiB of the same object
        file_put_contents(OrgTransferPaths::package($transfer), substr($bytes, 0, 1024 * 1024));
        $transfer->update(['import_source' => $transfer->import_source + ['size' => strlen($bytes), 'etag' => '"etag-1"'], 'upload_size' => strlen($bytes), 'uploaded_bytes' => 1024 * 1024]);

        $transfer = $this->fetch($transfer, ['access_key_id' => 'a', 'secret_access_key' => 'b']);
        $this->assertSame('verifying', $transfer->status, (string) $transfer->message);
        $this->assertSame('bytes=1048576-2097151', $this->s3Calls[1]['range'], 'resumed after the bytes held');
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($transfer)));

        // The bucket holds another object under the key now: the bytes held are not its
        $other = random_bytes(1024 * 1024 + 5);
        $this->fakeS3($other, '"etag-2"');
        $transfer->update(['status' => 'fetching']);
        file_put_contents(OrgTransferPaths::package($transfer), substr($bytes, 0, 1024 * 1024));
        $transfer = $this->fetch($transfer, ['access_key_id' => 'a', 'secret_access_key' => 'b']);
        $this->assertSame('bytes=0-1048575', $this->s3Calls[1]['range']);
        $this->assertSame($other, file_get_contents(OrgTransferPaths::package($transfer)));
    }

    public function test_a_failing_range_is_tried_again_and_a_replaced_object_is_read_from_the_start(): void
    {
        $bytes = random_bytes(2 * 1024 * 1024 + 7);
        $this->fakeS3($bytes, '"etag-1"', failAt: 1024 * 1024);
        $transfer = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar.zst'], null, null, $this->owner);
        $transfer = $this->fetch($transfer, ['access_key_id' => 'a', 'secret_access_key' => 'b']);
        $this->assertSame('verifying', $transfer->status, (string) $transfer->message);
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($transfer)));
        $this->assertSame(2, count(array_filter($this->s3Calls, fn ($c) => $c['range'] === 'bytes=1048576-2097151')), 'the failed range was asked again');

        $transfer->update(['status' => 'completed']);
        $this->fakeS3($bytes, '"etag-1"', replaceAt: 1024 * 1024);
        $again = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar.zst'], null, null, $this->owner);
        $again = $this->fetch($again, ['access_key_id' => 'a', 'secret_access_key' => 'b']);
        $this->assertSame('verifying', $again->status, (string) $again->message);
        $this->assertSame(2, count(array_filter($this->s3Calls, fn ($c) => $c['method'] === 'HEAD')), 'started over after the 412');
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($again)));
    }

    public function test_a_missing_object_fails_the_import_and_a_resume_needs_the_credentials_again(): void
    {
        $this->fakeS3('', status: 404);
        $response = $this->postJson('/api/org-transfers/import', ['source' => $this->s3Source()])->assertStatus(202);
        $transfer = OrgTransfer::query()->findOrFail($response->json('data.id'));

        $transfer = $this->fetch($transfer, ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret']);

        $this->assertSame('failed', $transfer->status);
        $this->assertSame('fetch', $transfer->phase);
        $this->assertStringContainsString('dr/runr-org-acme.tar.zst', (string) $transfer->message);
        $this->assertSame(1, AuditEvent::query()->where('action', 'org.import.fetch_failed')->count());

        $this->postJson("/api/org-transfers/{$transfer->id}/fetch")->assertStatus(422)->assertJsonPath('code', 's3_credentials');
        $this->postJson("/api/org-transfers/{$transfer->id}/fetch", ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret'])
            ->assertStatus(202)->assertJsonPath('data.status', 'fetching');
        Queue::assertPushed(FetchImportPackageJob::class, 2);

        // An upload is not resumed this way
        $transfer->update(['status' => 'cancelled']);
        $upload = $this->postJson('/api/org-transfers/import', ['filename' => 'p.tar.zst', 'size' => 10])->assertCreated();
        $this->postJson('/api/org-transfers/'.$upload->json('data.id').'/fetch')->assertStatus(409)->assertJsonPath('code', 'not_resumable');
    }

    public function test_an_import_from_an_object_binding_is_copied_in_its_region_and_downloaded(): void
    {
        $bucket = $this->bucket();
        $bytes = random_bytes(4096);
        $this->transfer->produce = ['import-' => $bytes];

        $response = $this->postJson('/api/org-transfers/import', ['source' => ['type' => 'binding', 'binding_id' => $bucket->id, 'key' => 'dr/nightly/p.tar.zst']])->assertStatus(202);
        $transfer = OrgTransfer::query()->findOrFail($response->json('data.id'));
        $this->assertSame('backups', $transfer->import_source['binding_name']);
        Queue::assertPushed(FetchImportPackageJob::class, fn ($job) => $job->credentials === null);

        $transfer = $this->fetch($transfer, null);

        $this->assertSame('verifying', $transfer->status, (string) $transfer->message);
        $send = $this->transfer->started[0];
        $this->assertSame('send', $send['mode']);
        $this->assertSame('runr-data', $send['namespace']);
        $this->assertSame('copy dr/nightly/p.tar.zst package.tar.zst', $send['tool']->script);
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($transfer)));
        $this->assertSame(hash('sha256', $bytes), $transfer->import_source['sha256']);
        $this->assertGreaterThanOrEqual(1, $this->transfer->stopped, 'the pod is removed');
    }

    public function test_a_resumed_binding_read_keeps_the_bytes_of_the_same_object_only(): void
    {
        $bucket = $this->bucket();
        $bytes = random_bytes(4096);
        $this->transfer->produce = ['import-' => $bytes];
        $transfer = app(OrgTransferService::class)->startFetch(app(OrgTransferService::class)->source(['type' => 'binding', 'binding_id' => $bucket->id, 'key' => 'p.tar.zst'])[0], null, null, $this->owner);

        file_put_contents(OrgTransferPaths::package($transfer), substr($bytes, 0, 1000));
        $transfer->update(['import_source' => $transfer->import_source + ['size' => 4096, 'sha256' => hash('sha256', $bytes)]]);
        $transfer = $this->fetch($transfer, null);
        $this->assertSame(1000, $this->transfer->downloads[0]['held'], 'continued after the bytes held');
        $this->assertSame($bytes, file_get_contents(OrgTransferPaths::package($transfer)));

        // Another object under the key: started over
        $transfer->update(['status' => 'fetching']);
        file_put_contents(OrgTransferPaths::package($transfer), str_repeat('x', 1000));
        $this->transfer->produce = ['import-' => $other = random_bytes(3000)];
        $transfer = $this->fetch($transfer, null);
        $this->assertSame(0, $this->transfer->downloads[1]['held']);
        $this->assertSame($other, file_get_contents(OrgTransferPaths::package($transfer)));
    }

    public function test_a_declared_checksum_that_does_not_match_fails_the_fetch(): void
    {
        $bucket = $this->bucket();
        $this->transfer->produce = ['import-' => 'not the declared package'];
        $transfer = app(OrgTransferService::class)->startFetch(app(OrgTransferService::class)->source(['type' => 'binding', 'binding_id' => $bucket->id, 'key' => 'p.tar.zst'])[0], null, str_repeat('a', 64), $this->owner);

        $transfer = $this->fetch($transfer, null);

        $this->assertSame('failed', $transfer->status);
        $this->assertSame(__('org_transfer.upload.checksum_mismatch'), $transfer->message);
        $this->assertSame(0, filesize(OrgTransferPaths::package($transfer)), 'the bytes are dropped');
    }

    public function test_cancelling_stops_the_fetch_and_a_stalled_fetch_is_marked_failed(): void
    {
        $bucket = $this->bucket();
        $transfer = app(OrgTransferService::class)->startFetch(app(OrgTransferService::class)->source(['type' => 'binding', 'binding_id' => $bucket->id, 'key' => 'p.tar.zst'])[0], null, null, $this->owner);
        $this->deleteJson("/api/org-transfers/{$transfer->id}")->assertNoContent();
        $this->assertSame('cancelled', $this->fetch($transfer, null)->status);
        $this->assertSame([], $this->transfer->started, 'a cancelled fetch does not start');

        // Cancelled while the bytes come: the job stops between two ranges
        $this->fakeS3(random_bytes(3 * 1024 * 1024));
        $running = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar.zst'], null, null, $this->owner);
        Http::fake(function (Request $request) use ($running) {
            if ($request->method() === 'GET') {
                OrgTransfer::query()->whereKey($running->id)->update(['status' => 'cancelled']);
            }

            return null; // the bucket fake answers
        });
        $this->assertSame('cancelled', $this->fetch($running, ['access_key_id' => 'a', 'secret_access_key' => 'b'])->status);
        $this->assertSame(1, count(array_filter($this->s3Calls, fn ($c) => $c['method'] === 'GET')), 'no range after the cancel');

        $stalled = app(OrgTransferService::class)->startFetch(app(OrgTransferService::class)->source(['type' => 'binding', 'binding_id' => $bucket->id, 'key' => 'q.tar.zst'])[0], null, null, $this->owner);
        OrgTransfer::query()->whereKey($stalled->id)->update(['updated_at' => now()->subHour()]);
        app(OrgTransferService::class)->expire();
        $stalled->refresh();
        $this->assertSame('failed', $stalled->status);
        $this->assertSame('fetch', $stalled->phase);
        $this->postJson("/api/org-transfers/{$stalled->id}/fetch")->assertStatus(202);
    }

    public function test_a_package_read_from_s3_plans_like_an_uploaded_one(): void
    {
        $export = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'export', 'status' => 'queued', 'options' => [], 'destination' => ['type' => 'download'], 'created_by' => (string) $this->owner->id]);
        app(OrgExporter::class)->run($export, null);
        $package = (string) file_get_contents(OrgTransferPaths::package($export->fresh()));
        $this->fakeS3($package);

        $transfer = app(OrgTransferService::class)->startFetch(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'key' => 'p.tar'], null, null, $this->owner);
        $transfer = $this->fetch($transfer, ['access_key_id' => 'a', 'secret_access_key' => 'b']);
        (new VerifyImportPackageJob($transfer->id))->handle(app(OrgImporter::class));

        $transfer->refresh();
        $this->assertSame('planned', $transfer->status, (string) $transfer->message);
        $this->assertSame(strlen($package), $transfer->package_size);
        $this->assertTrue($transfer->plan['can_apply']);
    }
}
