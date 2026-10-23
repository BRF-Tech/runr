<?php

namespace Tests\Feature\OrgTransfer;

use App\Jobs\OrgTransfer\ApplyImportJob;
use App\Jobs\OrgTransfer\ExportOrganizationJob;
use App\Jobs\OrgTransfer\VerifyImportPackageJob;
use App\Models\AuditEvent;
use App\Models\OrgTransfer;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\OrgTransferService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The org-transfer API (docs/org-export.md): owners only (admins and others 403 owner_only),
 * export start (queued job with the passphrase / S3 credentials in its encrypted payload only),
 * estimate, validation, the running-job limit, the chunked import upload with resume, the
 * checksum check on complete, the download with ranges, cancel, scoping to the organization.
 */
class OrgTransferApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.chunk_bytes' => 4, 'org_transfer.compression' => 'none']);
        Queue::fake();
    }

    private function owner()
    {
        $owner = $this->userWithRole('owner');
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function putChunk(string $id, int $offset, string $bytes): TestResponse
    {
        return $this->call('PUT', "/api/org-transfers/{$id}/chunk?offset={$offset}", [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    public function test_only_owners_may_export_or_import(): void
    {
        $this->owner();
        foreach (['admin', 'developer', 'viewer'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum');
            $response = $this->postJson('/api/org-transfers/export', []);
            $this->assertSame(403, $response->status(), $role);
            if ($role === 'admin') {
                // admins hold org.transfer through "*": the owner check answers
                $response->assertJsonPath('code', 'owner_only');
            }
            $this->getJson('/api/org-transfers')->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    public function test_an_export_is_queued_with_its_secrets_only_in_the_job(): void
    {
        $owner = $this->owner();

        $response = $this->postJson('/api/org-transfers/export', [
            'include_members' => true,
            'include_secrets' => true,
            'passphrase' => 'correct horse battery',
            'destination' => [
                'type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'region' => 'eu-1',
                'access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret', 'prefix' => 'runr',
            ],
        ])->assertStatus(202);

        $transfer = OrgTransfer::query()->findOrFail($response->json('data.id'));
        $this->assertSame('export', $transfer->kind);
        $this->assertSame('queued', $transfer->status);
        $this->assertSame((string) $owner->id, $transfer->created_by);
        $this->assertSame(['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'region' => 'eu-1', 'path_style' => true, 'prefix' => 'runr'], $transfer->destination);
        $this->assertTrue($transfer->options['include_secrets']);
        $this->assertNotNull($transfer->estimate);

        $row = json_encode($transfer->getAttributes());
        $this->assertStringNotContainsString('very-secret', $row);
        $this->assertStringNotContainsString('correct horse battery', $row);
        $this->assertStringNotContainsString('very-secret', $response->getContent());

        Queue::assertPushed(ExportOrganizationJob::class, fn (ExportOrganizationJob $job) => $job->transferId === $transfer->id
            && $job->passphrase === 'correct horse battery'
            && $job->credentials === ['access_key_id' => 'AKIA123', 'secret_access_key' => 'very-secret']);
        $this->assertContains(ShouldBeEncrypted::class, class_implements(ExportOrganizationJob::class), 'encrypted payload');

        $audit = AuditEvent::query()->where('action', 'org.export.start')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('very-secret', json_encode($audit->metadata));
    }

    public function test_secrets_need_a_long_passphrase_and_s3_needs_credentials(): void
    {
        $this->owner();

        $this->postJson('/api/org-transfers/export', ['include_secrets' => true])->assertStatus(422)->assertJsonValidationErrors('passphrase');
        $this->postJson('/api/org-transfers/export', ['include_secrets' => true, 'passphrase' => 'short'])->assertStatus(422)->assertJsonValidationErrors('passphrase');
        $this->postJson('/api/org-transfers/export', ['destination' => ['type' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'b']])
            ->assertStatus(422)->assertJsonValidationErrors(['destination.access_key_id', 'destination.secret_access_key']);
        $this->postJson('/api/org-transfers/export', ['binding_data' => 'some'])->assertStatus(422)->assertJsonValidationErrors('binding_data');
        Queue::assertNothingPushed();
    }

    public function test_one_running_export_per_organization(): void
    {
        $this->owner();
        $this->postJson('/api/org-transfers/export', [])->assertStatus(202);

        $this->postJson('/api/org-transfers/export', [])->assertStatus(429)->assertJsonPath('code', 'too_many_transfers');
        // an import is another kind
        $this->postJson('/api/org-transfers/import', ['filename' => 'a.tar.zst', 'size' => 8])->assertCreated();
    }

    public function test_the_estimate_lists_bindings_and_sizes(): void
    {
        $this->owner();

        $this->postJson('/api/org-transfers/export/estimate', ['include_images' => true])->assertOk()
            ->assertJsonStructure(['data' => ['projects', 'versions', 'code_bytes', 'bindings', 'binding_data_bytes', 'images' => ['count', 'bytes'], 'total_bytes', 'warnings']])
            ->assertJsonPath('data.images.bytes', null);
    }

    public function test_an_import_upload_takes_contiguous_chunks_and_resumes(): void
    {
        $this->owner();
        $content = 'abcdefgh';

        $id = $this->postJson('/api/org-transfers/import', ['filename' => 'pkg.tar.zst', 'size' => 8, 'sha256' => hash('sha256', $content)])
            ->assertCreated()->assertJsonPath('data.status', 'uploading')->assertJsonPath('data.upload.chunk_size', 4)->json('data.id');

        $this->putChunk($id, 0, 'abcd')->assertOk()->assertJsonPath('uploaded_bytes', 4);
        // a gap is refused; the answer says where to go on
        $this->putChunk($id, 8, 'zz')->assertStatus(422)->assertJsonPath('code', 'bad_chunk')->assertJsonPath('uploaded_bytes', 4);
        // larger than a chunk
        $this->putChunk($id, 4, 'efghi')->assertStatus(422);
        // a resent chunk is fine
        $this->putChunk($id, 0, 'abcd')->assertOk()->assertJsonPath('uploaded_bytes', 4);

        $this->postJson("/api/org-transfers/{$id}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_incomplete');

        $this->getJson("/api/org-transfers/{$id}")->assertJsonPath('data.upload.uploaded_bytes', 4);
        $this->putChunk($id, 4, 'efgh')->assertOk()->assertJsonPath('uploaded_bytes', 8);

        $this->postJson("/api/org-transfers/{$id}/complete")->assertStatus(202)->assertJsonPath('data.status', 'verifying');
        $this->assertSame($content, file_get_contents(OrgTransferPaths::package(OrgTransfer::query()->findOrFail($id))));
        Queue::assertPushed(VerifyImportPackageJob::class, fn ($job) => $job->transferId === $id);

        $this->putChunk($id, 0, 'abcd')->assertStatus(409)->assertJsonPath('code', 'not_uploading');
    }

    public function test_a_checksum_mismatch_resets_the_upload(): void
    {
        $this->owner();
        $id = $this->postJson('/api/org-transfers/import', ['filename' => 'pkg.tar.zst', 'size' => 4, 'sha256' => str_repeat('a', 64)])->json('data.id');
        $this->putChunk($id, 0, 'abcd')->assertOk();

        $this->postJson("/api/org-transfers/{$id}/complete")->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch')->assertJsonPath('uploaded_bytes', 0);
        $this->assertSame('uploading', OrgTransfer::query()->findOrFail($id)->status);
        Queue::assertNotPushed(VerifyImportPackageJob::class);
    }

    public function test_a_finished_export_downloads_with_ranges(): void
    {
        $owner = $this->owner();
        $transfer = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'export', 'status' => 'completed', 'progress' => 100,
            'destination' => ['type' => 'download'], 'package_filename' => 'runr-org-acme-20261021-120000.tar.zst',
            'package_size' => 10, 'package_sha256' => hash('sha256', '0123456789'), 'created_by' => (string) $owner->id,
            'expires_at' => now()->addDay(),
        ]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($transfer));
        file_put_contents(OrgTransferPaths::package($transfer), '0123456789');

        $full = $this->get("/api/org-transfers/{$transfer->id}/download");
        $full->assertOk();
        $this->assertSame('0123456789', $full->streamedContent());
        $this->assertStringContainsString('runr-org-acme-20261021-120000.tar.zst', (string) $full->headers->get('Content-Disposition'));

        $part = $this->get("/api/org-transfers/{$transfer->id}/download", ['Range' => 'bytes=4-']);
        $part->assertStatus(206);
        $this->assertSame('456789', $part->streamedContent());
        $this->assertSame('bytes 4-9/10', $part->headers->get('Content-Range'));

        $this->get("/api/org-transfers/{$transfer->id}/download", ['Range' => 'bytes=20-'])->assertStatus(416);

        $transfer->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/org-transfers/{$transfer->id}/download")->assertStatus(409)->assertJsonPath('code', 'not_downloadable');
    }

    public function test_cancel_removes_the_files_and_other_organizations_see_nothing(): void
    {
        $this->owner();
        $id = $this->postJson('/api/org-transfers/import', ['filename' => 'pkg.tar.zst', 'size' => 8])->json('data.id');
        $transfer = OrgTransfer::query()->findOrFail($id);
        $this->assertFileExists(OrgTransferPaths::package($transfer));

        $other = OrgTransfer::query()->create(['owner_key' => 'tenant-b', 'tenant_id' => 'tenant-b', 'kind' => 'export', 'status' => 'completed']);
        $this->getJson("/api/org-transfers/{$other->id}")->assertNotFound();
        $this->assertSame([$id], array_column($this->getJson('/api/org-transfers')->json('data'), 'id'));

        $this->deleteJson("/api/org-transfers/{$id}")->assertNoContent();
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertDirectoryDoesNotExist(OrgTransferPaths::dir($transfer));
    }

    public function test_apply_is_refused_while_the_plan_blocks(): void
    {
        $owner = $this->owner();
        $transfer = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'import', 'status' => 'uploading', 'created_by' => (string) $owner->id,
        ]);

        $this->postJson("/api/org-transfers/{$transfer->id}/plan", [])->assertStatus(409)->assertJsonPath('code', 'not_plannable');
        $this->postJson("/api/org-transfers/{$transfer->id}/apply", [])->assertStatus(409);
        Queue::assertNotPushed(ApplyImportJob::class);
    }

    public function test_expired_uploads_and_old_packages_are_removed(): void
    {
        $this->owner();
        $id = $this->postJson('/api/org-transfers/import', ['filename' => 'pkg.tar.zst', 'size' => 8])->json('data.id');
        $transfer = OrgTransfer::query()->findOrFail($id);
        $transfer->update(['expires_at' => now()->subHour()]);

        $orphan = OrgTransferPaths::root().'/01jabcdefghjkmnpqrstvwxyz0';
        mkdir($orphan, 0777, true);
        file_put_contents($orphan.'/package', 'x');

        app(OrgTransferService::class)->expire();

        $this->assertSame('expired', $transfer->fresh()->status);
        $this->assertDirectoryDoesNotExist(OrgTransferPaths::dir($transfer));
        $this->assertDirectoryDoesNotExist($orphan, 'a directory without its row');
    }
}
