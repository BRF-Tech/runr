package cmd

import (
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestAuditSettings(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("audit", "settings")
	contains(t, r.out, "365 days")
	contains(t, r.out, "3650 days")

	r = h.mustRun("audit", "settings", "set", "--retention-days", "730")
	contains(t, r.err, "Audit events are kept 730 days.")
	if got := h.srv.AuditRetentionDays(); got != 730 {
		t.Fatalf("retention = %d", got)
	}
	var s runr.AuditSettings
	decodeJSON(t, h.mustRun("audit", "settings", "set", "--retention-days", "0", "-o", "json").out, &s)
	if s.RetentionDays != 0 || s.MaxDays != 3650 || !s.CanUpdate {
		t.Fatalf("settings = %+v", s)
	}
	contains(t, h.mustRun("audit", "settings").out, "forever")

	h.expectCode(ExitUsage, "audit", "settings", "set")
	h.expectCode(ExitUsage, "audit", "settings", "set", "--retention-days", "-5")
	h.expectCode(ExitValidation, "audit", "settings", "set", "--retention-days", "5000")

	// A tenant's user may read but not change it; a token without settings.manage neither.
	h.srv.SetAuditSettingsReadOnly()
	r = h.expectCode(ExitAuth, "audit", "settings", "set", "--retention-days", "30")
	contains(t, r.err, "platform administrators")
	h.loginWith(h.srv.ScopedToken("auditor", "audit.view"))
	h.mustRun("audit", "settings")
	r = h.expectCode(ExitAuth, "audit", "settings", "set", "--retention-days", "30")
	contains(t, r.err, "settings.manage")
}
