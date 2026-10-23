package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func rsFlex(v float64) *runr.FlexFloat { f := runr.FlexFloat(v); return &f }

func rsInt(v int) *int { return &v }

// oversized is a recommendation of 512 MiB / 500m → 256 MiB / 125m (140 MiB used at most).
func oversized(monthly float64) runr.Recommendation {
	r := runr.Recommendation{
		Direction:   "down",
		Current:     runr.RightsizingSize{CPUM: 500, MemoryMB: 512},
		Recommended: runr.RightsizingSize{CPUM: 125, MemoryMB: 256},
		Reasons:     []string{"memory_over_provisioned", "cpu_over_provisioned"},
		Evidence: runr.RightsizingEvidence{WindowDays: 14, From: "2026-10-01", To: "2026-10-14", Days: 14, Sessions: 560,
			MemoryMaxMB: rsFlex(140), MemoryP95MB: rsFlex(110), CPUMaxM: rsInt(210), CPUP95M: rsInt(80)},
		Freed: runr.RightsizingFreed{CPUM: 375, MemoryMB: 256, Pods: 1},
	}
	if monthly != 0 {
		r.Savings = &runr.RightsizingSavings{Basis: "reserved", Monthly: rsFlex(monthly), Currency: "USD"}
	}
	return r
}

func TestRightsizingList(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedProject("billing", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedProject("quiet", runr.TypeFaaS, "python", "eu-1", nil)

	r := h.mustRun("projects", "rightsizing")
	contains(t, r.out, "No open recommendation")

	h.srv.SeedRecommendation("orders", oversized(4.2))
	h.srv.SeedRecommendation("billing", oversized(12.5))
	dismissed := oversized(30)
	dismissed.Status = "dismissed"
	h.srv.SeedRecommendation("quiet", dismissed)

	r = h.mustRun("projects", "rightsizing")
	contains(t, r.out, "512 MiB / 500m")
	contains(t, r.out, "256 MiB / 125m")
	contains(t, r.out, "memory unused, CPU unused")
	contains(t, r.out, "12.50 USD")
	contains(t, r.out, "about 16.70 USD a month in all")
	notContains(t, r.out, "quiet")
	if i, j := strings.Index(r.out, "billing"), strings.Index(r.out, "orders"); i < 0 || j < 0 || i > j {
		t.Fatalf("largest saving first:\n%s", r.out)
	}

	var list runr.RightsizingList
	decodeJSON(t, h.mustRun("projects", "rightsizing", "-o", "json").out, &list)
	if len(list.Data) != 2 || list.Data[0].ProjectName() != "billing" || list.Summary.Count != 2 {
		t.Fatalf("list = %+v", list)
	}

	h.loginWith(h.srv.ScopedToken("dev", "projects.view"))
	r = h.expectCode(ExitAuth, "projects", "rightsizing")
	contains(t, r.err, "metrics.view")
}

func TestRightsizingProject(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedProject("young", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedRecommendation("orders", oversized(0))

	r := h.mustRun("projects", "rightsizing", "orders")
	contains(t, r.out, "256 MiB / 125m")
	contains(t, r.out, "140 MiB at most")
	contains(t, r.out, "80m 95th percentile")
	contains(t, r.out, "2026-10-01 … 2026-10-14 (14 days with readings, 560 sessions)")
	contains(t, r.out, "+256 MiB +375m")

	h.srv.SetRightsizingState("young", runr.ProjectRightsizing{Status: "collecting",
		Collecting: &runr.RightsizingCollecting{Days: 2, RequiredDays: 3, Sessions: 7, RequiredSessions: 20, WindowDays: 14}})
	r = h.mustRun("projects", "rightsizing", "-p", "young")
	contains(t, r.out, "2 of 3 days")
	contains(t, r.out, "7 of 20")
	contains(t, r.out, "Not enough usage yet")
}

func TestRightsizingApplyAndDismiss(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", map[string]any{"cpu": "500m", "memory_mb": 512})
	h.srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedRecommendation("orders", oversized(4.2))

	// a non-interactive session must confirm
	h.expectCode(ExitUsage, "projects", "rightsizing", "apply", "orders")

	r := h.mustRun("projects", "rightsizing", "apply", "orders", "--yes")
	contains(t, r.err, "Deploy the project to run it")
	contains(t, r.out, "512 MiB / 500m → 256 MiB / 125m")
	p, _ := h.srv.Project("orders")
	if p.Config.CPU != "125m" || p.Config.MemoryMB == nil || *p.Config.MemoryMB != 256 {
		t.Fatalf("config = %+v", p.Config)
	}
	if rec, _ := h.srv.Recommendation("orders"); rec.Status != "applied" || rec.AppliedVersionID == "" {
		t.Fatalf("recommendation = %+v", rec)
	}
	r = h.expectCode(ExitNotFound, "projects", "rightsizing", "apply", "orders", "--yes")
	contains(t, r.err, "open rightsizing recommendation")

	// dismissed for good, or snoozed
	h.srv.SeedRecommendation("shop", oversized(0))
	h.expectCode(ExitUsage, "projects", "rightsizing", "dismiss", "shop", "--snooze", "0")
	r = h.mustRun("projects", "rightsizing", "dismiss", "shop", "--snooze", "30")
	contains(t, r.err, "hidden for 30 days")
	if rec, _ := h.srv.Recommendation("shop"); rec.Status != "snoozed" || rec.SnoozedUntil == "" {
		t.Fatalf("recommendation = %+v", rec)
	}
	call := h.srv.CallsTo("POST /projects/{project}/rightsizing/dismiss")
	if len(call) != 1 {
		t.Fatalf("calls = %+v", call)
	}
	r = h.mustRun("projects", "rightsizing", "dismiss", "shop")
	contains(t, r.err, "dismissed")
	if rec, _ := h.srv.Recommendation("shop"); rec.Status != "dismissed" {
		t.Fatalf("recommendation = %+v", rec)
	}

	// applying needs projects.update
	h.srv.SeedRecommendation("shop", oversized(0))
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view", "metrics.view"))
	r = h.expectCode(ExitAuth, "projects", "rightsizing", "apply", "shop", "--yes")
	contains(t, r.err, "projects.update")
}
