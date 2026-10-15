package cmd

import (
	"net/url"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func TestMetricsSummary(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	r := h.mustRun("metrics", "-p", "orders")
	contains(t, r.out, "the last 7 days (0 samples)")
	contains(t, r.out, "No metrics were recorded")

	now := time.Now().UTC()
	h.srv.SeedMetricSnapshot("orders", runrtest.MetricSnapshot{At: now.Add(-time.Hour), CPUCores: 0.25, MemoryMB: 128, RPS: 2, ColdStarts: 1})
	h.srv.SeedMetricSnapshot("orders", runrtest.MetricSnapshot{At: now.Add(-2 * time.Hour), CPUCores: 0.75, MemoryMB: 256, RPS: 6, ColdStarts: 2})
	h.srv.SeedMetricSnapshot("orders", runrtest.MetricSnapshot{At: now.AddDate(0, 0, -20), CPUCores: 4, MemoryMB: 4096, RPS: 100})
	h.srv.SetSessionStats("orders", runrtest.SessionStats{Total: 40, ColdStarts: 3, DurationMS: 120000, CPUMS: 30000, AvgDuration: 3000})

	r = h.mustRun("metrics", "-p", "orders")
	contains(t, r.out, "the last 7 days (2 samples)")
	contains(t, r.out, "0.500 cores on average, 0.750 at most")
	contains(t, r.out, "192 MiB on average, 256 MiB at most")
	contains(t, r.out, "4.00/s on average, 6.00/s at most")
	contains(t, r.out, "40 (3 cold)")
	notContains(t, r.out, "No metrics")

	var sum runr.MetricsSummary
	decodeJSON(t, h.mustRun("metrics", "--period", "30d", "-p", "orders", "-o", "json").out, &sum)
	if sum.Metrics.PeriodDays != 30 || sum.Metrics.SampleCount != 3 || sum.Metrics.MaxCPU == nil || *sum.Metrics.MaxCPU != 4 {
		t.Fatalf("summary = %+v", sum.Metrics)
	}
	call := h.srv.CallsTo("GET /projects/{project}/metrics/summary")
	if q, _ := url.ParseQuery(call[len(call)-1].Query); q.Get("period") != "30d" {
		t.Fatalf("query = %q", call[len(call)-1].Query)
	}
	h.expectCode(ExitUsage, "metrics", "--period", "1y", "-p", "orders")
}

func TestMetricsHistory(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	now := time.Now().UTC()
	h.srv.SeedMetricSnapshot("orders", runrtest.MetricSnapshot{At: now.Add(-30 * time.Minute), CPUCores: 0.125, MemoryMB: 96.5,
		RPS: 1.5, Concurrency: 0.4, Pods: 1, ColdStarts: 1})
	h.srv.SeedMetricSnapshot("orders", runrtest.MetricSnapshot{At: now.Add(-3 * 24 * time.Hour), Granularity: "1h", CPUCores: 2, Pods: 3})

	r := h.mustRun("metrics", "history", "-p", "orders")
	contains(t, r.out, "0.125")
	contains(t, r.out, "1.50")
	notContains(t, r.out, "2.000")
	call := h.srv.CallsTo("GET /projects/{project}/metrics/history")[0]
	q, _ := url.ParseQuery(call.Query)
	if q.Get("granularity") != "5m" || q.Get("from") == "" || q.Get("to") == "" {
		t.Fatalf("query = %q", call.Query)
	}

	// A week picks the hourly series; the decimals the API sends as strings are read as numbers.
	var hist runr.MetricsHistory
	decodeJSON(t, h.mustRun("metrics", "history", "--since", "168h", "-p", "orders", "-o", "json").out, &hist)
	if hist.Granularity != "1h" || len(hist.Data) != 1 || hist.Data[0].CPUCores == nil || *hist.Data[0].CPUCores != 2 ||
		hist.Data[0].PodCount == nil || *hist.Data[0].PodCount != 3 {
		t.Fatalf("history = %+v", hist)
	}

	r = h.mustRun("metrics", "history", "--from", now.AddDate(0, 0, -1).Format("2006-01-02"), "--granularity", "1d", "-p", "orders")
	contains(t, r.out, "No 1d snapshots")

	h.expectCode(ExitUsage, "metrics", "history", "--since", "soon", "-p", "orders")
	h.expectCode(ExitUsage, "metrics", "history", "--since", "1h", "--from", "2026-10-01", "-p", "orders")
	h.expectCode(ExitUsage, "metrics", "history", "--to", "2026-10-01", "-p", "orders")
	h.expectCode(ExitUsage, "metrics", "history", "--granularity", "10m", "-p", "orders")
	h.expectCode(ExitUsage, "metrics", "history", "--from", "2026-10-02", "--to", "2026-10-01", "-p", "orders")

	h.loginWith(h.srv.ScopedToken("dev", "projects.view"))
	r = h.expectCode(ExitAuth, "metrics", "-p", "orders")
	contains(t, r.err, "metrics.view")
}
