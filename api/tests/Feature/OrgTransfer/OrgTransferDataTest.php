<?php

namespace Tests\Feature\OrgTransfer;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\DataServices\KvService;
use App\Services\OrgTransfer\Destinations\PackageDestinations;
use App\Services\OrgTransfer\Destinations\S3Uploader;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgExporter;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Region\BindingDumps;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\TransferTool;
use App\Services\OrgTransfer\Sections\BindingDataSection;
use App\Services\OrgTransfer\Sections\ImagesSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesImageCluster;
use Tests\Support\FakeRegionTransfer;
use Tests\TestCase;

/**
 * What moves through the region (docs/org-export.md#binding-data): binding dumps made by a
 * transfer pod and restored into the new binding once it is provisioned, prebuilt images pulled
 * and pushed into the target registry (the version then deployable without a build), the
 * package written to an object binding or an external S3 bucket (SigV4, multipart).
 */
class OrgTransferDataTest extends TestCase
{
    use ActsWithRoles, FakesImageCluster, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    private FakeRegionTransfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.compression' => 'none']);
        Queue::fake();
        $this->owner = $this->userWithRole('owner');
        $this->actingAs($this->owner, 'sanctum');
        $this->region = $this->imageRegion(['name' => 'eu-1']);

        $this->transfer = new FakeRegionTransfer;
        $this->app->instance(RegionTransfer::class, $this->transfer);
        $this->mock(BindingsCluster::class, fn ($mock) => $mock->shouldReceive('ensureDataNamespace'));
        // Dump / restore scripts are BindingDumps' own test: plain tools here
        $this->app->instance(BindingDumps::class, new class extends BindingDumps
        {
            public function __construct() {}

            public function supports(Binding $binding): bool
            {
                return true;
            }

            public function dumpTool(Binding $binding): TransferTool
            {
                return new TransferTool('pg', 'dump');
            }

            public function restoreTool(Binding $binding): TransferTool
            {
                return new TransferTool('pg', 'restore');
            }

            public function bucketUploadTool(Binding $binding, string $file, string $key): TransferTool
            {
                return new TransferTool('rclone', "copy {$key}");
            }
        });
    }

    private function postgres(string $name = 'db'): Binding
    {
        return Binding::query()->create([
            'name' => $name, 'resource_name' => "{$name}-abcdefgh", 'type' => 'postgres', 'plan' => 'shared',
            'knative_region_id' => $this->region->id, 'status' => Binding::STATUS_READY, 'usage_bytes' => 2048,
            'credentials' => ['host' => 'pg-shared-rw', 'database' => "b_{$name}", 'username' => "b_{$name}", 'password' => 'pw'],
        ]);
    }

    private function export(array $options, array $destination = ['type' => 'download'], ?array $credentials = null): OrgTransfer
    {
        $transfer = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'export', 'status' => 'queued', 'options' => $options,
            'destination' => $destination, 'created_by' => (string) $this->owner->id,
        ]);
        app(OrgExporter::class)->run($transfer, null, $credentials);

        return $transfer->fresh();
    }

    private function import(OrgTransfer $export, array $choices): OrgTransfer
    {
        $import = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'import', 'status' => 'verifying', 'upload_size' => $export->package_size,
            'uploaded_bytes' => $export->package_size, 'created_by' => (string) $this->owner->id,
        ]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($import));
        copy(OrgTransferPaths::package($export), OrgTransferPaths::package($import));
        app(OrgImporter::class)->verify($import, ImportChoices::from($choices));
        $import->refresh()->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, ImportChoices::from($choices), $this->owner);

        return $import->fresh();
    }

    public function test_binding_data_is_dumped_in_its_region_and_restored_into_the_new_binding(): void
    {
        $source = $this->postgres();
        $this->transfer->produce = ['dump-' => 'PGDMP-custom-format'];

        $export = $this->export(['binding_data' => [$source->id]]);
        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertSame('send', $this->transfer->started[0]['mode']);
        $this->assertSame('runr-data', $this->transfer->started[0]['namespace']);
        $this->assertSame(1, $export->report['contents']['binding_data']);
        $this->assertGreaterThanOrEqual(1, $this->transfer->stopped, 'the pod is removed');

        // Restore into a fresh organization: the source binding is gone
        $source->delete();
        BindingRegionService::query()->create(['knative_region_id' => $this->region->id, 'service' => 'postgres', 'mode' => 'managed', 'status' => 'ready']);
        $this->partialMock(BindingManager::class, function ($mock) {
            // provisioning runs in its job; here the binding is ready at once
            $mock->shouldReceive('create')->andReturnUsing(fn (array $data) => Binding::query()->create([
                'name' => $data['name'], 'resource_name' => $data['name'].'-newnewne', 'type' => $data['type'], 'plan' => $data['plan'],
                'knative_region_id' => $data['knative_region_id'], 'status' => Binding::STATUS_READY, 'settings' => $data['settings'],
                'credentials' => ['host' => 'pg-shared-rw', 'database' => 'b_new', 'username' => 'b_new', 'password' => 'pw2'],
            ]));
        });

        $import = $this->import($export, ['restore_data' => true]);

        $this->assertSame('completed', $import->status, json_encode($import->report));
        $restore = collect($this->transfer->started)->firstWhere('mode', 'receive');
        $this->assertSame(['data.dump'], array_keys($restore['files']));
        $this->assertSame(hash('sha256', 'PGDMP-custom-format'), $restore['files']['data.dump']['sha256']);
        $this->assertSame('PGDMP-custom-format', $this->transfer->received[$restore['purpose'].'/data.dump']);
        $this->assertSame('created', collect($import->report['items'])->firstWhere('section', 'binding_data')['status']);
    }

    private function kv(string $name = 'cache'): Binding
    {
        return Binding::query()->create([
            'name' => $name, 'resource_name' => "{$name}-abcdefgh", 'type' => 'kv', 'plan' => 'shared',
            'knative_region_id' => $this->region->id, 'status' => Binding::STATUS_READY, 'usage_bytes' => 512,
            'credentials' => ['username' => "{$name}-abcdefgh", 'password' => 'pw'],
        ]);
    }

    /** The region's KV service answers with $version (null: it does not answer). */
    private function targetRedis(?string $version): void
    {
        BindingRegionService::query()->updateOrCreate(['knative_region_id' => $this->region->id, 'service' => 'kv'], ['mode' => 'managed', 'status' => 'ready']);
        $this->mock(KvService::class, fn ($mock) => $mock->shouldReceive('serverVersion')->andReturn($version));
    }

    /** A verified import of $export with $choices (its plan). */
    private function planned(OrgTransfer $export, array $choices = []): OrgTransfer
    {
        $import = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'import', 'status' => 'verifying', 'upload_size' => $export->package_size,
            'uploaded_bytes' => $export->package_size, 'created_by' => (string) $this->owner->id,
        ]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($import));
        copy(OrgTransferPaths::package($export), OrgTransferPaths::package($import));
        app(OrgImporter::class)->verify($import, ImportChoices::from($choices));

        return $import->fresh();
    }

    public function test_a_kv_dump_records_the_redis_version_it_was_made_with(): void
    {
        $this->kv();
        $this->transfer->produce = ['dump-' => "6b 1 -1 0011\n"];
        $this->transfer->results = ['dump-' => ['redis_version' => '7.4.1']];

        $export = $this->export(['binding_data' => 'all']);

        $this->assertSame('completed', $export->status, (string) $export->message);
        $dir = OrgTransferPaths::dir($export).'/check';
        app(PackageReader::class)->extract(OrgTransferPaths::package($export), $dir);
        $index = json_decode((string) file_get_contents($dir.'/'.BindingDataSection::INDEX), true);
        $this->assertSame('kv_dump_v1', $index[0]['format']);
        $this->assertSame('7.4.1', $index[0]['redis_version']);
    }

    public function test_the_plan_refuses_kv_data_for_an_older_target_redis_and_names_both_versions(): void
    {
        $source = $this->kv();
        $this->transfer->results = ['dump-' => ['redis_version' => '7.4.1']];
        $export = $this->export(['binding_data' => 'all']);
        $source->delete();
        $this->targetRedis('7.0.15');

        $import = $this->planned($export, ['restore_data' => true]);

        $this->assertSame('planned', $import->status, (string) $import->message);
        $row = collect($import->plan['bindings'])->firstWhere('name', 'cache');
        $issue = collect($row['issues'])->firstWhere('code', 'kv_version_older');
        $this->assertNotNull($issue, json_encode($row));
        $this->assertSame('error', $issue['level']);
        $this->assertStringContainsString('7.4.1', $issue['message']);
        $this->assertStringContainsString('7.0.15', $issue['message']);
        $this->assertStringContainsString('eu-1', $issue['message']);
        $this->assertSame('7.4.1', $row['data_redis_version']);
        $this->assertSame('7.0.15', $row['target_redis_version']);
        $this->assertFalse($import->plan['can_apply']);

        // Leaving that binding's data out (or all data) unblocks it
        $plan = app(OrgImporter::class)->plan($import, ImportChoices::from(['skip_binding_data' => [(string) $source->id]]));
        $row = collect($plan['bindings'])->firstWhere('name', 'cache');
        $this->assertFalse($row['data_restore']);
        $this->assertNull(collect($row['issues'])->firstWhere('code', 'kv_version_older'));
        $this->assertTrue($plan['can_apply'], json_encode($plan['bindings']));
    }

    public function test_the_same_or_a_newer_target_redis_takes_the_kv_data(): void
    {
        $source = $this->kv();
        $this->transfer->results = ['dump-' => ['redis_version' => '7.2.4']];
        $export = $this->export(['binding_data' => 'all']);
        $source->delete();

        foreach (['7.2.4', '7.4.1', '8.0.0'] as $version) {
            $this->targetRedis($version);
            $plan = app(OrgImporter::class)->plan($this->planned($export), ImportChoices::from([]));
            $row = collect($plan['bindings'])->firstWhere('name', 'cache');
            $this->assertSame([], array_values(array_filter($row['issues'], fn ($i) => str_starts_with($i['code'], 'kv_version'))), $version);
        }
    }

    public function test_an_unknown_target_version_blocks_and_an_unrecorded_source_version_warns(): void
    {
        $source = $this->kv();
        $this->transfer->results = ['dump-' => ['redis_version' => '7.4.1']];
        $export = $this->export(['binding_data' => 'all']);
        $this->transfer->results = [];
        $unrecorded = $this->export(['binding_data' => 'all']);
        $source->delete();

        $this->targetRedis(null);
        $row = collect($this->planned($export)->plan['bindings'])->firstWhere('name', 'cache');
        $this->assertSame('error', collect($row['issues'])->firstWhere('code', 'kv_version_unknown')['level'] ?? null, json_encode($row));

        $this->targetRedis('6.2.0');
        $row = collect($this->planned($unrecorded)->plan['bindings'])->firstWhere('name', 'cache');
        $this->assertNull($row['data_redis_version']);
        $this->assertSame('warning', collect($row['issues'])->firstWhere('code', 'kv_version_unrecorded')['level'] ?? null, json_encode($row));
    }

    public function test_the_restore_refuses_an_older_target_redis_and_a_left_out_binding_is_skipped(): void
    {
        $source = $this->kv();
        $this->transfer->results = ['dump-' => ['redis_version' => '7.4.1']];
        $export = $this->export(['binding_data' => 'all']);
        $source->delete();
        $this->partialMock(BindingManager::class, function ($mock) {
            $mock->shouldReceive('create')->andReturnUsing(fn (array $data) => Binding::query()->create([
                'name' => $data['name'], 'resource_name' => $data['name'].'-newnewne', 'type' => $data['type'], 'plan' => $data['plan'],
                'knative_region_id' => $data['knative_region_id'], 'status' => Binding::STATUS_READY, 'settings' => $data['settings'],
                'credentials' => ['username' => $data['name'].'-newnewne', 'password' => 'pw2'],
            ]));
        });

        // The plan saw 7.4.1; the region's Redis went back to 7.0 before the apply
        $this->targetRedis('7.4.1');
        $import = $this->planned($export);
        $this->targetRedis('7.0.15');
        $import->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, ImportChoices::from([]), $this->owner);
        $item = collect($import->fresh()->report['items'])->firstWhere('section', 'binding_data');
        $this->assertSame('failed', $item['status']);
        $this->assertStringContainsString('7.0.15', (string) $item['message']);
        $this->assertNull(collect($this->transfer->started)->firstWhere('mode', 'receive'), 'nothing was sent to the region');

        // Applied again without that binding's data: skipped, the import completes
        $import->refresh()->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, ImportChoices::from(['skip_binding_data' => [(string) $source->id]]), $this->owner);
        $import->refresh();
        $this->assertSame('completed', $import->status, json_encode($import->report));
        $this->assertSame('skipped', collect($import->report['items'])->firstWhere('section', 'binding_data')['status']);
    }

    public function test_a_failed_dump_is_skipped_and_reported_not_fatal(): void
    {
        $this->postgres();
        $this->transfer->failPurpose = 'dump-';

        $export = $this->export(['binding_data' => 'all']);

        $this->assertSame('completed', $export->status);
        $this->assertSame('binding_data', $export->report['skipped'][0]['section']);
        $this->assertStringContainsString('tool failed', $export->report['skipped'][0]['message']);
    }

    public function test_images_travel_and_the_version_points_at_the_pushed_image(): void
    {
        $project = Project::create(['name' => 'web', 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30]);
        $version = Version::create(['project_id' => $project->id, 'number' => 3, 'commit_message' => 'v3', 'created_by' => 'dev', 'build_status' => 'ready',
            'build_image' => self::IMG_ZOT.'/runr-builds/web:v3']);
        $this->assertCount(1, ImagesSection::versionsFor($project));
        $this->transfer->produce = ['image-' => 'oci-layout-tar'];

        $export = $this->export(['include_images' => true]);
        $pull = $this->transfer->started[0];
        $this->assertSame(self::IMG_ZOT.'/runr-builds/web:v3', $pull['pull']);
        $this->assertNull($pull['tool'], 'the agent pulls itself');
        $this->assertSame(1, $export->report['contents']['images']);

        $project->delete();
        $import = $this->import($export, ['images' => true]);

        $copy = Project::query()->where('name', 'web')->firstOrFail();
        $copied = Version::query()->where('project_id', $copy->id)->where('number', 3)->firstOrFail();
        $this->assertSame(self::IMG_ZOT.'/runr-builds/web:v3', $copied->build_image, 'pushed under the name its builds use');
        $this->assertSame('ready', $copied->build_status);
        $push = collect($this->transfer->started)->firstWhere('mode', 'receive');
        $this->assertSame(['/runr-image-upload', 'push-when-ready', 'image.tar'], $push['tool']->command);
        $this->assertSame('oci-layout-tar', $this->transfer->received[$push['purpose'].'/image.tar']);
        $this->assertSame('completed', $import->status, json_encode($import->report));
    }

    public function test_the_package_goes_to_an_object_binding(): void
    {
        $bucket = Binding::query()->create([
            'name' => 'backups', 'resource_name' => 'backups-abcdefgh', 'type' => 'object', 'plan' => 'shared',
            'knative_region_id' => $this->region->id, 'status' => Binding::STATUS_READY,
            'credentials' => ['bucket' => 'backups-abcdefgh', 'access_key_id' => 'k', 'secret_access_key' => 's'],
        ]);

        $export = $this->export([], ['type' => 'binding', 'binding_id' => $bucket->id, 'prefix' => 'dr/nightly']);

        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertMatchesRegularExpression('#^dr/nightly/runr-org-.+\.tar$#', $export->destination['key']);
        $this->assertFileDoesNotExist(OrgTransferPaths::package($export), 'not kept on the API volume');
        $this->assertFalse($export->isDownloadable());
        $receive = $this->transfer->started[0];
        $this->assertSame('receive', $receive['mode']);
        $this->assertSame('copy '.$export->destination['key'], $receive['tool']->script);
        $this->assertSame($export->package_size, strlen($this->transfer->received[$receive['purpose'].'/package.tar.zst']));
    }

    public function test_the_package_goes_to_external_s3_with_sigv4(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;

            return Http::response('', 200, ['ETag' => '"etag-1"']);
        });

        $export = $this->export([], ['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'dr', 'region' => 'eu-central-1', 'path_style' => true, 'prefix' => 'runr'],
            ['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 'wJalrXUtnFEMI']);

        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertCount(1, $requests, 'one PUT for a small package');
        $put = $requests[0];
        $this->assertSame('PUT', $put->method());
        $this->assertStringStartsWith('https://s3.example.com/dr/runr/runr-org-', $put->url());
        $auth = $put->header('authorization')[0];
        $this->assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/\d{8}/eu-central-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $auth);
        $this->assertSame(hash('sha256', $put->body()), $put->header('x-amz-content-sha256')[0]);
        $this->assertStringNotContainsString('wJalrXUtnFEMI', json_encode($export->getAttributes()));
    }

    public function test_large_packages_use_a_multipart_upload(): void
    {
        $file = $this->testStorage.'/big';
        $handle = fopen($file, 'wb');
        fwrite($handle, str_repeat('a', S3Uploader::PART_BYTES + 10));
        fclose($handle);

        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_QUERY);
            if (str_contains($request->url(), 'uploads=')) {
                return Http::response('<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>');
            }

            return Http::response('<CompleteMultipartUploadResult/>', 200, ['ETag' => '"e"']);
        });

        (new S3Uploader)->upload($file, ['endpoint' => 'https://s3.example.com', 'bucket' => 'dr', 'access_key_id' => 'k', 'secret_access_key' => 's'], 'x/pkg.tar.zst');

        $this->assertSame(['POST uploads=', 'PUT partNumber=1&uploadId=up-1', 'PUT partNumber=2&uploadId=up-1', 'POST uploadId=up-1'], $calls);
    }

    public function test_destination_keys_stay_under_the_prefix(): void
    {
        $this->assertSame('a/b/f.tar.zst', PackageDestinations::key('/a/../b/', 'f.tar.zst'));
        $this->assertSame('f.tar.zst', PackageDestinations::key('', 'f.tar.zst'));
        $this->assertSame('dr/x-y/f.tar.zst', PackageDestinations::key('dr/x y', 'f.tar.zst'));
        $this->assertStringNotContainsString('..', PackageDestinations::key('../../etc', 'f.tar.zst'));
    }

    public function test_bindings_without_data_support_are_not_selected(): void
    {
        $this->postgres('db');
        Binding::query()->create(['name' => 'ext', 'resource_name' => 'ext-abcdefgh', 'type' => 'external', 'plan' => 'external', 'status' => Binding::STATUS_READY]);

        $this->assertSame(['db'], BindingDataSection::selected(['binding_data' => 'all'])->pluck('name')->all());
        $this->assertSame([], BindingDataSection::selected(['binding_data' => []])->all());
    }
}
