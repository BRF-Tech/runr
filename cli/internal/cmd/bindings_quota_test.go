package cmd

import (
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

const bqTenant = "01JTENANT0000000000000000A"

func TestBindingsQuotaOfATenant(t *testing.T) {
	h := newHarness(t).login()

	// Single-tenant installations have no tenants.
	r := h.expectCode(ExitNotFound, "bindings", "quota", "--tenant", bqTenant)
	contains(t, r.err, "single-tenant")

	h.srv.RBACMultiTenant()
	r = h.mustRun("bindings", "quota", "--tenant", bqTenant)
	contains(t, r.out, "LIMIT THAT APPLIES")
	contains(t, r.out, "max_bindings")
	contains(t, r.out, "1024")

	r = h.mustRun("bindings", "quota", "set", "--tenant", bqTenant, "--max-bindings", "50", "--object-gb", "0", "-o", "json")
	var q runr.TenantBindingQuota
	decodeJSON(t, r.out, &q)
	if q.Limits["max_bindings"] != 50 || q.Overrides["object_gb"] == nil || *q.Overrides["object_gb"] != 0 || q.Overrides["kv_mb"] != nil {
		t.Fatalf("quota = %+v", q)
	}
	body := h.srv.CallsTo("PUT /tenants/{tenant}/binding-quotas")[0].Body
	if len(body) != 2 || body["max_bindings"] != float64(50) || body["object_gb"] != float64(0) {
		t.Fatalf("body = %v", body)
	}
	contains(t, h.mustRun("bindings", "quota", "--tenant", bqTenant).out, "unlimited")

	h.mustRun("bindings", "quota", "set", "--tenant", bqTenant, "--unset", "max-bindings")
	body = h.srv.CallsTo("PUT /tenants/{tenant}/binding-quotas")[1].Body
	if v, ok := body["max_bindings"]; !ok || v != nil || len(body) != 1 {
		t.Fatalf("--unset body = %v", body)
	}
	if ov := h.srv.TenantBindingQuotaOverrides(); ov["max_bindings"] != nil || ov["object_gb"] == nil {
		t.Fatalf("overrides = %v", ov)
	}

	h.expectCode(ExitUsage, "bindings", "quota", "set", "--max-bindings", "5")
	h.expectCode(ExitUsage, "bindings", "quota", "set", "--tenant", bqTenant)
	h.expectCode(ExitUsage, "bindings", "quota", "set", "--tenant", bqTenant, "--kv-mb", "-1")
	h.expectCode(ExitUsage, "bindings", "quota", "set", "--tenant", bqTenant, "--unset", "gpus")
	h.expectCode(ExitUsage, "bindings", "quota", "set", "--tenant", bqTenant, "--kv-mb", "5", "--unset", "kv-mb")

	// The organization's own view is unchanged.
	if r := h.mustRun("bindings", "quota"); !strings.Contains(r.out, "LIMIT") || strings.Contains(r.out, "OVERRIDE") {
		t.Fatalf("own quota = %s", r.out)
	}

	// Platform administration: a scoped token is refused.
	h.loginWith(h.srv.ScopedToken("ops", "bindings.view", "bindings.manage"))
	h.expectCode(ExitAuth, "bindings", "quota", "--tenant", bqTenant)
}
