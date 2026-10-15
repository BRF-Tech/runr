<?php

namespace Tests\Feature\SupplyChain;

use App\Events\DeploymentCreated;
use App\Events\SupplyChainAuditEvent;
use App\Jobs\StartSupplyChainJob;
use App\Models\AuditEvent;
use App\Models\ImageSecurityReport;
use App\Models\RegionSupplyChainSetting;
use App\Models\SecurityPolicyOverride;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Models\Version;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\DeployPolicyGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * POST /api/projects/{project}/deploy through the supply-chain policy gate: thresholds per
 * severity, fixable-only counting, required scan, signature verification, warn mode, the
 * tenant's stricter policy and the audited override.
 */
class DeployPolicyGateTest extends TestCase
{
    use ActsWithRoles, FakesSupplyChainCluster, RefreshDatabase;

    /** First user of the install: Owner (every permission) */
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([DeploymentCreated::class, SupplyChainAuditEvent::class]);
        Bus::fake([StartSupplyChainJob::class]);
        $this->owner = $this->userWithRole('owner', ['username' => 'owner']);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function report(Version $version, array $attributes = []): ImageSecurityReport
    {
        return ImageSecurityReport::create($attributes + [
            'subject_type' => ImageSecurityReport::SUBJECT_VERSION,
            'subject_id' => $version->id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'knative_region_id' => $version->project->knative_region_id,
            'image_kind' => ImageSecurityReport::KIND_BUILT,
            'image_ref' => $version->build_image,
            'image_digest' => self::SC_DIGEST,
            'status' => 'completed',
            'steps' => ['sbom', 'scan'],
            'sbom_status' => 'ok',
            'scan_status' => 'ok',
            'critical_count' => 2,
            'fixable_critical_count' => 1,
            'high_count' => 4,
            'fixable_high_count' => 1,
        ]);
    }

    private function deploy(Version $version, array $extra = [], ?User $user = null)
    {
        $user ??= $this->owner;

        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$version->project_id}/deploy", ['version_id' => $version->id] + $extra);
    }

    public function test_gate_off_deploys_whatever_the_findings(): void
    {
        $version = $this->scBuiltVersion($this->scRegion());
        $this->report($version);

        $this->deploy($version)->assertStatus(202);
        Event::assertDispatched(DeploymentCreated::class);
    }

    public function test_enforce_blocks_above_the_critical_limit_with_a_clear_message(): void
    {
        $region = $this->scRegion(['name' => 'eu-west']);
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_max_critical' => 0]);
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        $res = $this->deploy($version)->assertStatus(422);

        $res->assertJsonPath('code', 'supply_chain_policy');
        $res->assertJsonPath('violations.0.rule', 'critical');
        $res->assertJsonPath('violations.0.count', 2);
        $res->assertJsonPath('violations.0.limit', 0);
        $res->assertJsonPath('report.counts.critical', 2);
        $this->assertStringContainsString('eu-west', $res->json('message'));
        $this->assertStringContainsString('2 CRITICAL vulnerabilities (allowed: 0)', $res->json('message'));
        Event::assertNotDispatched(DeploymentCreated::class);
        $this->assertSame(0, $version->project->deployments()->count());
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'deploy.policy_blocked');

        // Audit log: the decision (explicit event) next to the refused request (route catalog)
        $blocked = AuditEvent::query()->where('action', 'deploy.policy_blocked')->sole();
        $this->assertSame('denied', $blocked->result);
        $this->assertSame('project', $blocked->target_type);
        $this->assertSame($version->project_id, $blocked->target_id);
        $this->assertSame(['critical'], $blocked->metadata['violations']);
        $this->assertSame('failure', AuditEvent::query()->where('action', 'deployment.create')->sole()->result);
    }

    public function test_high_limit_and_fixable_only_counting(): void
    {
        $region = $this->scRegion();
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_max_critical' => 5, 'gate_max_high' => 3]);
        $this->deploy($version)->assertStatus(422)->assertJsonPath('violations.0.rule', 'high');

        // 1 fixable CRITICAL, 1 fixable HIGH
        $this->scSettings($region, ['gate_max_critical' => 1, 'gate_max_high' => 1, 'gate_only_fixable' => true]);
        $this->deploy($version)->assertStatus(202);
    }

    public function test_warn_mode_lets_the_deploy_through_and_audits_it(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'warn', 'gate_max_critical' => 0]);
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        $this->deploy($version)->assertStatus(202);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'deploy.policy_warned' && $e->details['violations'] === ['critical']);
    }

    public function test_a_required_scan_that_is_missing_or_running(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_require_scan' => true]);
        $version = $this->scBuiltVersion($region);

        $this->deploy($version)->assertStatus(422)
            ->assertJsonPath('code', 'supply_chain_policy')
            ->assertJsonPath('violations.0.rule', 'scan_missing');

        $this->report($version, ['status' => 'running', 'scan_status' => 'running', 'critical_count' => 0, 'high_count' => 0]);
        $res = $this->deploy($version, ['override_policy' => true, 'override_reason' => 'hurry'])->assertStatus(422);
        $res->assertJsonPath('code', 'supply_chain_scan_pending');
        $res->assertJsonPath('can_override', false, 'a running scan is waited for, not overridden');
        $this->assertSame(0, SecurityPolicyOverride::count());
    }

    public function test_a_required_scan_does_not_block_caas_when_the_region_scans_no_external_images(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_require_scan' => true]);

        $this->deploy($this->scCaasVersion($region))->assertStatus(202);
    }

    public function test_an_unscanned_caas_image_is_scanned_on_deploy(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_require_scan' => true, 'scan_external_images' => true]);
        $version = $this->scCaasVersion($region, 'ghcr.io/acme/web:2');

        $this->deploy($version)->assertStatus(422)->assertJsonPath('code', 'supply_chain_scan_pending');

        Bus::assertDispatched(StartSupplyChainJob::class);
        $this->assertSame('ghcr.io/acme/web:2', ImageSecurityReport::forVersion($version)->image_ref);
    }

    public function test_signature_is_verified_on_deploy(): void
    {
        $region = $this->scRegion();
        $settings = $this->scSigningReady($region);
        $version = $this->scBuiltVersion($region);
        $report = $this->report($version, ['critical_count' => 0, 'high_count' => 0]);

        $this->deploy($version)->assertStatus(422)->assertJsonPath('violations.0.rule', 'signature');

        $report->update(['signature_status' => 'ok', 'signature_key_fingerprint' => 'someone-else']);
        $res = $this->deploy($version)->assertStatus(422);
        $this->assertStringContainsString('not from a key the region trusts', $res->json('message'));

        $report->update(['signature_key_fingerprint' => $settings->signing_key_fingerprint]);
        $this->deploy($version)->assertStatus(202);
    }

    public function test_a_signed_spin_app_deploys_under_an_enforced_gate_that_requires_a_scan(): void
    {
        $region = $this->scRegion();
        $settings = $this->scSigningReady($region, ['gate_mode' => 'enforce', 'gate_require_scan' => true, 'gate_max_critical' => 0, 'gate_max_high' => 0]);
        $version = $this->scWasmVersion($region);

        // No scan is asked of a WebAssembly artifact; its signature still is
        $res = $this->deploy($version)->assertStatus(422);
        $this->assertSame(['signature'], array_column($res->json('violations'), 'rule'));

        $this->report($version, [
            'image_kind' => ImageSecurityReport::KIND_WASM,
            'steps' => ['sign'],
            'sbom_status' => 'skipped',
            'scan_status' => 'skipped',
            'critical_count' => 0,
            'fixable_critical_count' => 0,
            'high_count' => 0,
            'fixable_high_count' => 0,
            'signature_status' => 'ok',
            'signature_key_fingerprint' => $settings->signing_key_fingerprint,
        ]);

        $this->deploy($version)->assertStatus(202);
        Event::assertDispatched(DeploymentCreated::class);
        $this->assertSame(0, SecurityPolicyOverride::count(), 'no override needed');
    }

    public function test_a_signature_of_a_retired_key_still_deploys(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['signing_retired_keys' => [['public_key' => self::scPublicKey(), 'fingerprint' => 'old-fp', 'retired_at' => now()->toISOString()]]]);
        $version = $this->scBuiltVersion($region);
        $this->report($version, ['signature_status' => 'ok', 'signature_key_fingerprint' => 'old-fp', 'critical_count' => 0, 'high_count' => 0]);

        $this->deploy($version)->assertStatus(202);
    }

    public function test_verify_on_deploy_can_be_switched_off(): void
    {
        $region = $this->scRegion();
        $this->scSigningReady($region, ['verify_on_deploy' => false]);

        $this->deploy($this->scBuiltVersion($region))->assertStatus(202);
    }

    public function test_an_override_needs_the_permission_and_a_reason_and_is_recorded(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_max_critical' => 0]);
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        // Without the permission: a Developer deploys, but does not override
        $member = $this->userWithRole('developer', ['username' => 'member']);
        $this->deploy($version, ['override_policy' => true, 'override_reason' => 'accepted risk'], $member)
            ->assertStatus(422)
            ->assertJsonPath('can_override', false);

        // Without a reason
        $admin = $this->owner;
        $this->deploy($version, ['override_policy' => true, 'override_reason' => ''], $admin)
            ->assertStatus(422)
            ->assertJsonValidationErrors('override_reason');

        // Blocked response tells the admin an override is possible
        $this->deploy($version, [], $admin)->assertStatus(422)->assertJsonPath('can_override', true);

        // Override
        $res = $this->deploy($version, ['override_policy' => true, 'override_reason' => 'CVE fixed upstream, rollout tonight'], $admin)->assertStatus(202);

        $override = SecurityPolicyOverride::sole();
        $this->assertSame('owner', $override->username);
        $this->assertSame('CVE fixed upstream, rollout tonight', $override->reason);
        $this->assertSame('critical', $override->violations[0]['rule']);
        $this->assertSame($res->json('id'), $override->deployment_id);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'deploy.policy_overridden' && $e->details['override_id'] === $override->id);
    }

    public function test_the_tenant_policy_is_stricter_than_the_region(): void
    {
        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::create(['tenant_id' => 'acme', 'residency_enforced' => false, 'gate_mode' => 'enforce', 'gate_max_critical' => 1, 'gate_max_high' => 2]);

        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'warn', 'gate_max_critical' => 10, 'gate_only_fixable' => true]);
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        $policy = app(DeployPolicyGate::class)->effectivePolicy(RegionSupplyChainSetting::forRegion($region));
        $this->assertSame('enforce', $policy['mode']);
        $this->assertSame(1, $policy['max_critical']);
        $this->assertSame(2, $policy['max_high']);
        $this->assertFalse($policy['only_fixable'], 'the tenant counts every finding');
        $this->assertTrue($policy['tenant']);

        $this->deploy($version)->assertStatus(422)->assertJsonCount(2, 'violations');
    }

    public function test_code_mounted_versions_and_demo_regions_are_not_gated(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_require_scan' => true]);
        $this->deploy($this->scBuiltVersion($region, ['build_image' => null]))->assertStatus(202);

        $demo = $this->scRegion(['is_demo' => true, 'kubeconfig' => null]);
        $this->scSettings($demo, ['gate_mode' => 'enforce', 'gate_require_scan' => true]);
        $this->deploy($this->scBuiltVersion($demo))->assertStatus(202);
    }

    public function test_evaluate_reports_the_decision_without_deploying(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['gate_mode' => 'enforce', 'gate_max_critical' => 0]);
        $version = $this->scBuiltVersion($region);
        $this->report($version);

        $decision = app(DeployPolicyGate::class)->evaluate($version->project, $version)->toArray();

        $this->assertTrue($decision['applicable']);
        $this->assertTrue($decision['blocked']);
        $this->assertSame('critical', $decision['violations'][0]['rule']);
    }
}
