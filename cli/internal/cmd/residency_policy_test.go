package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestResidencyPolicy(t *testing.T) {
	h := newHarness(t).login()

	// Single-tenant: there is no organization to name.
	r := h.expectCode(ExitUsage, "residency", "policy")
	contains(t, r.err, "--tenant")
	r = h.expectCode(ExitNotFound, "residency", "policy", "--tenant", bqTenant)
	contains(t, r.err, "single-tenant")

	h.srv.RBACMultiTenant()
	h.srv.AddRegion("ist-1", true)
	r = h.mustRun("residency", "policy")
	contains(t, r.out, "every region")
	contains(t, r.out, "the regions' gate")

	r = h.mustRun("residency", "set", "--allow-region", "eu-1", "--allow-region", "ist-1", "--allow-location", "TR",
		"--default-region", "ist-1", "--enforce")
	contains(t, r.err, "Updated the security policy of "+bqTenant+".")
	contains(t, r.out, "eu-1, ist-1")
	ids, locs, _ := h.srv.TenantSecurityPolicyState()
	if len(ids) != 2 || strings.Join(locs, ",") != "TR" {
		t.Fatalf("policy = %v %v", ids, locs)
	}
	calls := h.srv.CallsTo("PUT /tenants/{tenant}/security-policy")
	body := calls[len(calls)-1].Body
	if body["residency_enforced"] != true || len(body["allowed_region_ids"].([]any)) != 2 || body["default_region_id"] == nil {
		t.Fatalf("body = %v", body)
	}

	var p runr.TenantSecurityPolicy
	decodeJSON(t, h.mustRun("residency", "set", "--gate-mode", "enforce", "--gate-max-critical", "0", "--gate-require-scan",
		"-o", "json").out, &p)
	if p.GateMode != "enforce" || p.GateMaxCritical == nil || *p.GateMaxCritical != 0 || p.GateRequireScan == nil || !*p.GateRequireScan {
		t.Fatalf("gate = %+v", p)
	}
	h.mustRun("residency", "set", "--unset", "gate-mode", "--all-locations", "--tenant", bqTenant)
	calls = h.srv.CallsTo("PUT /tenants/{tenant}/security-policy")
	body = calls[len(calls)-1].Body
	if v, ok := body["gate_mode"]; !ok || v != nil || len(body["allowed_locations"].([]any)) != 0 {
		t.Fatalf("--unset body = %v", body)
	}
	if _, _, mode := h.srv.TenantSecurityPolicyState(); mode != "" {
		t.Fatalf("gate mode after --unset = %q", mode)
	}

	// The default region must be allowed; unknown regions and wrong flags are refused here.
	h.expectCode(ExitValidation, "residency", "set", "--allow-region", "eu-1", "--default-region", "ist-1")
	h.expectCode(ExitNotFound, "residency", "set", "--allow-region", "mars-1")
	h.expectCode(ExitUsage, "residency", "set")
	h.expectCode(ExitUsage, "residency", "set", "--gate-mode", "strict")
	h.expectCode(ExitUsage, "residency", "set", "--allow-region", "eu-1", "--all-regions")
	h.expectCode(ExitUsage, "residency", "set", "--unset", "gate-mode", "--gate-mode", "warn")
	h.expectCode(ExitUsage, "residency", "set", "--unset", "residency")

	// A token that manages residency but not the gate.
	h.loginWith(h.srv.ScopedToken("residency", "tenants.residency.manage"))
	h.mustRun("residency", "set", "--all-regions", "--no-default-region")
	r = h.expectCode(ExitAuth, "residency", "set", "--gate-mode", "warn")
	contains(t, r.err, "regions.supply_chain.manage")
}
