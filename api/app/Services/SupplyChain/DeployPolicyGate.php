<?php

namespace App\Services\SupplyChain;

use App\Models\AiModelService;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\SecurityPolicyOverride;
use App\Models\Version;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Deploy-time supply-chain checks (POST /api/projects/{project}/deploy), in this order:
 *
 *  1. data residency — RegionPolicy::DEPLOY for the project's region;
 *  2. policy gate — the version's vulnerability counts against the effective policy: the
 *     region's (region_supply_chain_settings.gate_*) combined with the tenant's
 *     (tenant_security_policies.gate_*), the stricter value of each wins. Mode "enforce" blocks,
 *     "warn" lets the deploy through and audits it, "off" checks nothing;
 *  3. signature — with signing and verify_on_deploy on, a version built in the region deploys
 *     only with a signature from one of the region's trusted keys (current or retired). The
 *     admission controller verifies the same signature cryptographically at pod admission.
 *
 * A blocked deploy goes through when the caller sends override_policy + override_reason and
 * holds security.override_policy; the override is stored (SecurityPolicyOverride) and audited.
 */
class DeployPolicyGate
{
    public function __construct(
        private RegionPolicy $residency,
        private SupplyChainPipeline $pipeline,
    ) {}

    /**
     * Residency + gate for a deploy request; returns the decision (overridden or with warnings)
     * or throws (blocked).
     *
     * @throws SupplyChainPolicyException
     * @throws RegionNotAllowedException
     */
    public function authorize(Request $request, Project $project, Version $version): PolicyDecision
    {
        $this->residency->authorize($project->knativeRegion, RegionPolicy::DEPLOY);

        $decision = $this->evaluate($project, $version);
        $audit = ['project_id' => $project->getKey(), 'project' => $project->name, 'version' => $version->number];

        if (! $decision->blocked()) {
            if ($decision->warnings()) {
                SupplyChainAudit::record('deploy.policy_warned', $audit + ['violations' => array_column($decision->warnings(), 'rule')], $project->knative_region_id);
            }

            return $decision;
        }

        $canOverride = Gate::allows('security.override_policy', $project);

        if ($request->boolean('override_policy') && $canOverride && ! $decision->pendingOnly()) {
            $reason = trim((string) $request->input('override_reason', ''));
            if (mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(['override_reason' => [__('supply_chain.gate.override_reason_required')]]);
            }

            $decision->override = SecurityPolicyOverride::create([
                'project_id' => $project->getKey(),
                'version_id' => $version->getKey(),
                'report_id' => $decision->report?->getKey(),
                'user_id' => $request->user()?->getKey(),
                'username' => $request->user()?->username,
                'reason' => mb_substr($reason, 0, 500),
                'violations' => $decision->blocking(),
            ]);

            SupplyChainAudit::record('deploy.policy_overridden', $audit + [
                'override_id' => $decision->override->getKey(),
                'reason' => mb_substr($reason, 0, 500),
                'violations' => array_column($decision->blocking(), 'rule'),
            ], $project->knative_region_id);

            return $decision;
        }

        SupplyChainAudit::record('deploy.policy_blocked', $audit + ['violations' => array_column($decision->blocking(), 'rule')], $project->knative_region_id, AuditEvent::RESULT_DENIED);

        $pending = $decision->pendingOnly();
        $reasons = implode('; ', array_column($decision->blocking(), 'message'));
        $message = $pending
            ? __('supply_chain.gate.pending', ['version' => "v{$version->number}", 'reasons' => $reasons])
            : __('supply_chain.gate.blocked', ['region' => $project->knativeRegion?->name ?? '—', 'reasons' => $reasons])
                .' '.__($canOverride ? 'supply_chain.gate.hint_override' : 'supply_chain.gate.hint_fix');

        throw new SupplyChainPolicyException($message, $decision, $canOverride && ! $pending, $pending ? 'supply_chain_scan_pending' : 'supply_chain_policy');
    }

    /**
     * Model service deploy (create with deploy, deploy, redeploy after a change): residency plus
     * the gate on the service's image, an external image like a CaaS one (no signature rule).
     * A refused service is left stopped with the reason as its status message.
     *
     * @throws SupplyChainPolicyException
     * @throws RegionNotAllowedException
     */
    public function guardModelService(Request $request, AiModelService $service): void
    {
        try {
            $this->authorizeModelService($request, $service);
        } catch (SupplyChainPolicyException|RegionNotAllowedException $e) {
            $service->update(['status' => AiModelService::STATUS_STOPPED, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    public function authorizeModelService(Request $request, AiModelService $service): void
    {
        $region = $service->region;
        $this->residency->authorize($region, RegionPolicy::MODEL_SERVICE);
        if (! $region || $region->is_demo) {
            return;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $policy = $this->effectivePolicy($settings);
        if ($policy['mode'] === 'off') {
            return;
        }

        $image = $this->pipeline->modelServiceImage($service);
        $report = ImageSecurityReport::forModelService($service);
        $scanned = $settings->scan_enabled && $settings->scan_external_images;
        if ($scanned && (! $report || $report->image_ref !== $image)) {
            $report = $this->pipeline->queueForModelService($service);
        }

        $decision = new PolicyDecision(true, $this->scanViolations($report, ['require_scan' => $policy['require_scan'] && $scanned] + $policy, $policy['mode'] === 'enforce'), $policy, $report);
        $audit = ['model_service_id' => $service->getKey(), 'model_service' => $service->name, 'image' => $image];

        if (! $decision->blocked()) {
            if ($decision->warnings()) {
                SupplyChainAudit::record('deploy.policy_warned', $audit + ['violations' => array_column($decision->warnings(), 'rule')], $region->getKey());
            }

            return;
        }

        $canOverride = Gate::allows('security.override_policy');
        if ($request->boolean('override_policy') && $canOverride && ! $decision->pendingOnly()) {
            $reason = trim((string) $request->input('override_reason', ''));
            if (mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(['override_reason' => [__('supply_chain.gate.override_reason_required')]]);
            }
            SupplyChainAudit::record('deploy.policy_overridden', $audit + [
                'reason' => mb_substr($reason, 0, 500),
                'violations' => array_column($decision->blocking(), 'rule'),
            ], $region->getKey());

            return;
        }

        SupplyChainAudit::record('deploy.policy_blocked', $audit + ['violations' => array_column($decision->blocking(), 'rule')], $region->getKey(), AuditEvent::RESULT_DENIED);

        $pending = $decision->pendingOnly();
        $reasons = implode('; ', array_column($decision->blocking(), 'message'));
        $message = $pending
            ? __('supply_chain.gate.pending', ['version' => $service->name, 'reasons' => $reasons])
            : __('supply_chain.gate.blocked', ['region' => $region->name, 'reasons' => $reasons])
                .' '.__($canOverride ? 'supply_chain.gate.hint_override' : 'supply_chain.gate.hint_fix');

        throw new SupplyChainPolicyException($message, $decision, $canOverride && ! $pending, $pending ? 'supply_chain_scan_pending' : 'supply_chain_policy');
    }

    /** Links an override to the deployment it let through. */
    public function recordFor(PolicyDecision $decision, Deployment $deployment): void
    {
        $decision->override?->update(['deployment_id' => $deployment->getKey()]);
    }

    public function evaluate(Project $project, Version $version): PolicyDecision
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo) {
            return PolicyDecision::notApplicable();
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $policy = $this->effectivePolicy($settings);
        $image = $this->pipeline->imageForVersion($project, $version);

        // Code-mounted versions run the runtime's own (platform) image: nothing was built to check
        if (! $image) {
            return PolicyDecision::notApplicable($policy);
        }
        [$kind] = $image;

        $report = ImageSecurityReport::forVersion($version);
        if (! $report && $settings->scan_enabled && (
            ($kind === ImageSecurityReport::KIND_EXTERNAL && $settings->scan_external_images)
            || $kind === ImageSecurityReport::KIND_UPLOADED
        )) {
            // CaaS image not scanned yet (an external one, or an uploaded image): start it now, the gate sees it as running
            $report = $this->pipeline->queueForVersion($version);
        }

        $violations = [];
        $gateOn = $policy['mode'] !== 'off';
        $enforced = $policy['mode'] === 'enforce';

        // "Require a scan" only where the region scans this kind of image at all; a Spin app
        // (WebAssembly OCI artifact) has no container filesystem to scan — its signature is checked
        $scanned = $settings->scan_enabled
            && $kind !== ImageSecurityReport::KIND_WASM
            && ($kind !== ImageSecurityReport::KIND_EXTERNAL || $settings->scan_external_images);

        if ($gateOn) {
            $violations = array_merge($violations, $this->scanViolations($report, ['require_scan' => $policy['require_scan'] && $scanned] + $policy, $enforced));
        }

        // Images in RunR's own registry: what it built (Spin apps included), and uploaded images (signed after their scan)
        if ($policy['require_signature'] && in_array($kind, [ImageSecurityReport::KIND_BUILT, ImageSecurityReport::KIND_WASM, ImageSecurityReport::KIND_UPLOADED], true)) {
            if ($v = $this->signatureViolation($report, $settings)) {
                $violations[] = $v;
            }
        }

        return new PolicyDecision($gateOn || $policy['require_signature'], $violations, $policy, $report);
    }

    /**
     * Region policy + the tenant's, the stricter value of each field.
     *
     * @return array{mode: string, max_critical: ?int, max_high: ?int, only_fixable: bool, require_scan: bool, require_signature: bool, trusted_fingerprints: list<string>, tenant: bool}
     */
    public function effectivePolicy(RegionSupplyChainSetting $settings, ?string $tenantId = null): array
    {
        $tenant = $this->residency->policy($tenantId);
        $rank = array_flip(RegionSupplyChainSetting::GATE_MODES);

        $mode = $settings->gate_mode;
        if ($tenant?->gate_mode && ($rank[$tenant->gate_mode] ?? 0) > ($rank[$mode] ?? 0)) {
            $mode = $tenant->gate_mode;
        }

        $min = fn (?int $a, ?int $b) => $a === null ? $b : ($b === null ? $a : min($a, $b));

        // A tenant gate that is on uses its own thresholds even where the region's gate is off
        $regionOn = $settings->gate_mode !== 'off';

        return [
            'mode' => $mode,
            'max_critical' => $min($regionOn ? $settings->gate_max_critical : null, $tenant?->gate_mode && $tenant->gate_mode !== 'off' ? $tenant->gate_max_critical : null),
            'max_high' => $min($regionOn ? $settings->gate_max_high : null, $tenant?->gate_mode && $tenant->gate_mode !== 'off' ? $tenant->gate_max_high : null),
            'only_fixable' => $settings->gate_only_fixable && ! ($tenant?->gate_mode && $tenant->gate_mode !== 'off'),
            'require_scan' => ($regionOn && $settings->gate_require_scan) || (bool) ($tenant?->gate_require_scan && $tenant->gate_mode !== 'off'),
            'require_signature' => $settings->signing_enabled && $settings->verify_on_deploy && $settings->hasSigningKey(),
            'trusted_fingerprints' => $settings->trustedFingerprints(),
            'tenant' => (bool) ($tenant?->gate_mode && $tenant->gate_mode !== 'off'),
        ];
    }

    /** @return list<array> */
    private function scanViolations(?ImageSecurityReport $report, array $policy, bool $enforced): array
    {
        if (! $report || ! $report->hasScan()) {
            if (! $policy['require_scan']) {
                return [];
            }
            $running = $report && $report->isRunning();

            return [[
                'rule' => $running ? 'scan_pending' : 'scan_missing',
                'message' => __($running ? 'supply_chain.gate.scan_pending' : 'supply_chain.gate.scan_missing'),
                'enforced' => $enforced,
            ]];
        }

        $counts = $report->counts($policy['only_fixable']);
        $out = [];
        foreach (['critical' => 'max_critical', 'high' => 'max_high'] as $severity => $limit) {
            if ($policy[$limit] !== null && $counts[$severity] > $policy[$limit]) {
                $out[] = [
                    'rule' => $severity,
                    'message' => __("supply_chain.gate.{$severity}", ['count' => $counts[$severity], 'limit' => $policy[$limit]])
                        .($policy['only_fixable'] ? ' '.__('supply_chain.gate.fixable_only') : ''),
                    'enforced' => $enforced,
                    'count' => $counts[$severity],
                    'limit' => $policy[$limit],
                ];
            }
        }

        return $out;
    }

    private function signatureViolation(?ImageSecurityReport $report, RegionSupplyChainSetting $settings): ?array
    {
        if ($report && $report->isSigned() && in_array($report->signature_key_fingerprint, $settings->trustedFingerprints(), true)) {
            return null;
        }

        $pending = $report && $report->isRunning() && in_array('sign', $report->steps ?? [], true);

        return [
            'rule' => $pending ? 'signature_pending' : 'signature',
            'message' => __($pending ? 'supply_chain.gate.signature_pending' : ($report && $report->isSigned() ? 'supply_chain.gate.signature_untrusted' : 'supply_chain.gate.signature_missing')),
            'enforced' => true,
        ];
    }

    /** Region the gate of a project applies to (for summaries). */
    public function policyFor(KnativeRegion $region): array
    {
        return $this->effectivePolicy(RegionSupplyChainSetting::forRegion($region));
    }
}
