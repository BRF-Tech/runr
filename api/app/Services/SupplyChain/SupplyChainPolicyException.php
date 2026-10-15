<?php

namespace App\Services\SupplyChain;

use Illuminate\Http\JsonResponse;

/**
 * A deploy the supply-chain policy gate refused: HTTP 422 with the violated rules, whether the
 * caller may override (security.override_policy) and the version's report summary.
 *
 * code: supply_chain_policy (findings / signature) or supply_chain_scan_pending (the scan or the
 * signature of the version is still running — deploy again when it finished).
 */
class SupplyChainPolicyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly PolicyDecision $decision,
        public readonly bool $canOverride,
        public readonly string $errorCode = 'supply_chain_policy',
    ) {
        parent::__construct($message);
    }

    /** An expected refusal, audited by DeployPolicyGate — not an application error */
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'violations' => $this->decision->blocking(),
            'warnings' => $this->decision->warnings(),
            'can_override' => $this->canOverride,
            'policy' => $this->decision->policy,
            'report' => $this->decision->report?->summary(),
        ], 422);
    }
}
