<?php

namespace App\Services\SupplyChain;

use App\Models\ImageSecurityReport;
use App\Models\SecurityPolicyOverride;

/**
 * Outcome of the deploy policy gate for one version (DeployPolicyGate::evaluate).
 *
 * A violation: ['rule' => critical | high | scan_missing | scan_pending | signature |
 * signature_pending, 'message' => string, 'enforced' => bool, ...details]. Enforced violations
 * block the deploy (unless overridden); the others are warnings (gate mode "warn").
 */
final class PolicyDecision
{
    public ?SecurityPolicyOverride $override = null;

    /**
     * @param  list<array{rule: string, message: string, enforced: bool}>  $violations
     * @param  array<string, mixed>  $policy
     */
    public function __construct(
        public readonly bool $applicable,
        public readonly array $violations,
        public readonly array $policy,
        public readonly ?ImageSecurityReport $report,
    ) {}

    public static function notApplicable(array $policy = []): self
    {
        return new self(false, [], $policy, null);
    }

    /** @return list<array> */
    public function blocking(): array
    {
        return array_values(array_filter($this->violations, fn ($v) => $v['enforced']));
    }

    /** @return list<array> */
    public function warnings(): array
    {
        return array_values(array_filter($this->violations, fn ($v) => ! $v['enforced']));
    }

    public function blocked(): bool
    {
        return $this->blocking() !== [] && $this->override === null;
    }

    /** Only "the scan / signature is still running" keeps the deploy back. */
    public function pendingOnly(): bool
    {
        $blocking = $this->blocking();

        return $blocking !== [] && array_diff(array_column($blocking, 'rule'), ['scan_pending', 'signature_pending']) === [];
    }

    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'blocked' => $this->blocked(),
            'overridden' => $this->override !== null,
            'violations' => $this->violations,
            'policy' => $this->policy,
            'report' => $this->report?->summary(),
        ];
    }
}
