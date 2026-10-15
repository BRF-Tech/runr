<?php

namespace Tests\Feature\SupplyChain;

use App\Casts\Encrypted;
use App\Events\SupplyChainAuditEvent;
use App\Jobs\GenerateSigningKeyJob;
use App\Jobs\InstallAdmissionPolicyJob;
use App\Jobs\RefreshScannerDbJob;
use App\Models\AuditEvent;
use App\Models\ImageSbomDocument;
use App\Models\ImageSecurityReport;
use App\Models\RegionSupplyChainSetting;
use App\Models\RuntimeBuild;
use App\Models\User;
use App\Support\Secrets\SecretColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Region → Supply chain API: settings (never the private key), validation, abilities, key
 * generation / rotation / retired keys, public key download, scanner DB refresh, admission
 * install / removal / preview.
 */
class RegionSupplyChainApiTest extends TestCase
{
    use ActsWithRoles, FakesSupplyChainCluster, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([SupplyChainAuditEvent::class]);
        Bus::fake([GenerateSigningKeyJob::class, InstallAdmissionPolicyJob::class, RefreshScannerDbJob::class]);

        // Owner of the (single-tenant) organization: every permission
        $this->admin = $this->userWithRole('owner', ['username' => 'root']);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function url($region, string $path = ''): string
    {
        return "/api/knative-regions/{$region->id}/supply-chain{$path}";
    }

    public function test_defaults_of_a_region_without_settings(): void
    {
        $region = $this->scRegion();

        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url($region))->assertOk();

        $res->assertJsonPath('data.sbom_enabled', true);
        $res->assertJsonPath('data.scan_enabled', true);
        $res->assertJsonPath('data.gate_mode', 'off');
        $res->assertJsonPath('data.signing_status', 'none');
        $res->assertJsonPath('data.admission_status', 'not_installed');
        $res->assertJsonPath('data.scanner_db_repository', self::SC_ZOT.'/runr-supply-chain/trivy-db:2');
        $res->assertJsonPath('data.region.project_namespace', 'fn');
        $res->assertJsonPath('data.can_manage', true);
        $this->assertSame(0, RegionSupplyChainSetting::count(), 'reading creates nothing');
    }

    public function test_the_private_key_is_a_registered_secret_column(): void
    {
        $this->assertSame(['signing_private_key', 'signing_key_password'], SecretColumns::forTable('region_supply_chain_settings'));
        $casts = (new RegionSupplyChainSetting)->getCasts();
        $this->assertSame(Encrypted::class, $casts['signing_private_key']);
        $this->assertSame(Encrypted::class, $casts['signing_key_password']);
    }

    public function test_the_private_key_and_its_password_never_leave_the_api(): void
    {
        $region = $this->scRegion();
        $settings = $this->scSigningReady($region);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url($region))->assertOk();
        $body = $res->getContent();

        $this->assertStringNotContainsString('ENCRYPTED SIGSTORE PRIVATE KEY', $body);
        $this->assertArrayNotHasKey('signing_private_key', $res->json('data'));
        $this->assertArrayNotHasKey('signing_key_password', $res->json('data'));
        $res->assertJsonPath('data.signing_key_fingerprint', $settings->signing_key_fingerprint);
        $res->assertJsonPath('data.signing_public_key', $settings->signing_public_key);

        // Stored encrypted
        $raw = DB::table('region_supply_chain_settings')->where('knative_region_id', $region->id)->first();
        $this->assertStringNotContainsString('ENCRYPTED SIGSTORE PRIVATE KEY', $raw->signing_private_key);
        $this->assertNotSame('pw', $raw->signing_key_password);
        $this->assertStringContainsString('ENCRYPTED SIGSTORE PRIVATE KEY', $settings->fresh()->signing_private_key);
    }

    public function test_update_settings_and_audit_without_secrets(): void
    {
        $region = $this->scRegion();

        $res = $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), [
            'gate_mode' => 'enforce',
            'gate_max_critical' => 0,
            'gate_max_high' => 10,
            'scan_external_images' => true,
            'caas_policy' => 'allowlist',
            'caas_allowed_registries' => ['ghcr.io/acme', ' docker.io/library ', 'ghcr.io/acme'],
            'admission_exempt_images' => ['registry.example.com/tools/**'],
            'data_location' => ' TR ',
            'rescan_interval_hours' => 12,
        ])->assertOk();

        $res->assertJsonPath('data.gate_mode', 'enforce');
        $res->assertJsonPath('data.caas_allowed_registries', ['ghcr.io/acme', 'docker.io/library']);
        $res->assertJsonPath('data.data_location', 'TR');
        $res->assertJsonPath('data.updated_by', 'root');

        // Audit log (route catalog): request fields and the model change, never a secret
        $event = AuditEvent::query()->where('action', 'region.supply_chain.update')->sole();
        $this->assertSame('success', $event->result);
        $this->assertSame('region', $event->target_type);
        $this->assertSame($region->id, $event->target_id);
        $this->assertSame('enforce', $event->metadata['gate_mode']);
        $this->assertContains('caas_allowed_registries', $event->metadata['fields']);
        $change = collect($event->metadata['changes'] ?? [])->firstWhere('model', 'region_supply_chain');
        $this->assertNotNull($change, 'the settings change is part of the event');
    }

    public function test_validation(): void
    {
        $region = $this->scRegion();
        $put = fn (array $data) => $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), $data);

        $put(['gate_mode' => 'block'])->assertStatus(422)->assertJsonValidationErrors('gate_mode');
        $put(['scanner_db_mode' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors('scanner_db_mode');
        $put(['caas_policy' => 'allowlist'])->assertStatus(422)->assertJsonValidationErrors('caas_allowed_registries');
        $put(['caas_policy' => 'signed', 'caas_allowed_registries' => ['ghcr.io/acme']])->assertStatus(422)->assertJsonValidationErrors('caas_trusted_keys');
        $put(['caas_trusted_keys' => ['not a key']])->assertStatus(422)->assertJsonValidationErrors('caas_trusted_keys.0');
        $put(['caas_trusted_keys' => [self::scPublicKey()], 'caas_policy' => 'signed', 'caas_allowed_registries' => ['ghcr.io/acme']])->assertOk();
        $put(['admission_exempt_images' => ['bad image; rm -rf']])->assertStatus(422);
        $put(['rescan_interval_hours' => -1])->assertStatus(422);
        $put(['data_location' => '../etc'])->assertStatus(422);
        $put(['signing_private_key' => 'x'])->assertOk(); // not accepted: ignored
        $this->assertNull(RegionSupplyChainSetting::forRegion($region)->signing_private_key);

        $noRegistry = $this->scRegion(['registry_mode' => null, 'registry_status' => null, 'registry_address' => null]);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url($noRegistry), ['scanner_db_mode' => 'mirror'])
            ->assertStatus(422)->assertJsonValidationErrors('scanner_db_mode');
    }

    public function test_regions_view_reads_and_regions_supply_chain_manage_changes(): void
    {
        $region = $this->scRegion();
        $member = $this->userWithRole('developer', ['username' => 'member']); // regions.view, no supply-chain management

        $this->actingAs($member, 'sanctum')->getJson($this->url($region))->assertOk()->assertJsonPath('data.can_manage', false);
        $this->actingAs($member, 'sanctum')->putJson($this->url($region), ['gate_mode' => 'off'])->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson($this->url($region, '/signing-key'))->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson($this->url($region, '/admission'))->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson($this->url($region, '/scanner-db/refresh'))->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson($this->url($region, '/admission/preview'))->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), ['gate_mode' => 'warn'])->assertOk();
    }

    public function test_generate_then_rotate_the_signing_key(): void
    {
        $region = $this->scRegion();

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/signing-key'))->assertStatus(202)
            ->assertJsonPath('data.signing_status', 'generating');
        Bus::assertDispatched(GenerateSigningKeyJob::class, fn ($job) => $job->rotate === false);

        // A second request while generating
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/signing-key'))->assertStatus(409);

        // Rotation of a ready key keeps the old public key as retired
        $old = $this->scSigningReady($region);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/signing-key'))->assertStatus(202);
        Bus::assertDispatched(GenerateSigningKeyJob::class, fn ($job) => $job->rotate === true);
        $retired = RegionSupplyChainSetting::forRegion($region)->signing_retired_keys;
        $this->assertSame($old->signing_key_fingerprint, $retired[0]['fingerprint']);

        // Forget retired keys (and update an installed admission policy)
        RegionSupplyChainSetting::forRegion($region)->forceFill(['signing_status' => 'ready', 'admission_status' => 'ready'])->save();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url($region, '/signing-key/retired'))->assertOk()
            ->assertJsonPath('data.signing_retired_keys', []);
        Bus::assertDispatched(InstallAdmissionPolicyJob::class, fn ($job) => $job->action === 'sync');
        $this->assertTrue(AuditEvent::query()->where('action', 'region.signing_key.retired_remove')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'region.signing_key.request')->exists());
    }

    public function test_key_generation_needs_a_cluster(): void
    {
        $demo = $this->scRegion(['is_demo' => true]);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($demo, '/signing-key'))->assertStatus(422);
        Bus::assertNotDispatched(GenerateSigningKeyJob::class);
    }

    public function test_public_key_download(): void
    {
        $region = $this->scRegion(['name' => 'eu west']);
        $this->actingAs($this->admin, 'sanctum')->get($this->url($region, '/public-key'))->assertNotFound();

        $settings = $this->scSigningReady($region);
        $res = $this->actingAs($this->admin, 'sanctum')->get($this->url($region, '/public-key'))->assertOk();

        $this->assertSame($settings->signing_public_key, $res->getContent());
        $this->assertStringContainsString('runr-eu-west-cosign.pub', $res->headers->get('Content-Disposition'));
    }

    public function test_scanner_db_refresh(): void
    {
        $region = $this->scRegion();

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/scanner-db/refresh'))->assertStatus(202)
            ->assertJsonPath('data.scanner_db_status', 'refreshing');
        Bus::assertDispatched(RefreshScannerDbJob::class);

        $noRegistry = $this->scRegion(['registry_mode' => null, 'registry_status' => null, 'registry_address' => null]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($noRegistry, '/scanner-db/refresh'))->assertStatus(422);
    }

    public function test_admission_install_needs_the_region_key(): void
    {
        $region = $this->scRegion();

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/admission'))->assertStatus(422);
        Bus::assertNotDispatched(InstallAdmissionPolicyJob::class);

        $this->scSigningReady($region);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($region, '/admission'), ['mode' => 'warn'])->assertStatus(202)
            ->assertJsonPath('data.admission_status', 'installing')
            ->assertJsonPath('data.admission_mode', 'warn');
        Bus::assertDispatched(InstallAdmissionPolicyJob::class, fn ($job) => $job->action === 'install');

        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url($region, '/admission'))->assertStatus(202)
            ->assertJsonPath('data.admission_status', 'removing');
        Bus::assertDispatched(InstallAdmissionPolicyJob::class, fn ($job) => $job->action === 'uninstall');
    }

    public function test_changed_admission_settings_update_an_installed_policy(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_status' => 'ready', 'admission_mode' => 'enforce']);

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), ['gate_mode' => 'warn'])->assertOk();
        Bus::assertNotDispatched(InstallAdmissionPolicyJob::class);

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), ['admission_exempt_images' => ['registry.example.com/x/**']])->assertOk();
        Bus::assertDispatched(InstallAdmissionPolicyJob::class, fn ($job) => $job->action === 'sync');

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url($region), ['admission_mode' => 'off'])->assertOk();
        Bus::assertDispatched(InstallAdmissionPolicyJob::class, fn ($job) => $job->action === 'uninstall');
    }

    public function test_admission_preview_lists_the_policies(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['admission_mode' => 'enforce']);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url($region, '/admission/preview'))->assertOk();

        $res->assertJsonPath('data.namespace', 'cosign-system');
        $res->assertJsonPath('data.namespace_label', 'policy.sigstore.dev/include');
        $res->assertJsonPath('data.no_match_policy', 'allow');
        $this->assertSame(['runr-signed-images', 'runr-system-images'], array_column(array_column($res->json('data.policies'), 'metadata'), 'name'));
    }

    public function test_runtime_image_reports_of_the_region(): void
    {
        $region = $this->scRegion();
        $build = RuntimeBuild::create([
            'runtime_id' => 'q', 'version' => '1.0', 'knative_region_id' => $region->id,
            'status' => 'ready', 'image' => self::SC_ZOT.'/runr-runtimes/q:1.0-x', 'digest' => str_repeat('c', 64),
        ]);
        $report = ImageSecurityReport::create([
            'subject_type' => 'runtime_build', 'subject_id' => $build->id, 'knative_region_id' => $region->id,
            'image_kind' => 'runtime', 'image_ref' => $build->image, 'status' => 'completed', 'scan_status' => 'ok', 'high_count' => 3,
        ]);
        $report->sbomDocuments()->create(['format' => 'cyclonedx-json', 'content' => ImageSbomDocument::encode('{"bomFormat":"CycloneDX"}'), 'size_bytes' => 24, 'sha256' => 'x']);

        $this->actingAs($this->admin, 'sanctum')->getJson($this->url($region, '/runtime-images'))->assertOk()
            ->assertJsonPath('data.0.runtime', 'q')
            ->assertJsonPath('data.0.report.counts.high', 3);

        $sbom = $this->actingAs($this->admin, 'sanctum')->get($this->url($region, "/runtime-images/{$report->id}/sbom?format=cyclonedx-json"))->assertOk();
        $this->assertSame('{"bomFormat":"CycloneDX"}', $sbom->getContent());
    }
}
