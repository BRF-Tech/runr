package cmd

import (
	"net/url"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
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

func TestMetricsLive(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	// No live deployment yet.
	h.expectCode(ExitNotFound, "metrics", "live", "-p", "orders")

	h.srv.SeedFiles("orders", map[string]string{"main.py": "def handler(event, context):\n    return {}\n"})
	d := h.srv.SeedDeployment("orders", 1)

	// Scaled to zero: no readings.
	r := h.mustRun("metrics", "live", "-p", "orders")
	contains(t, r.out, d.ID)
	contains(t, r.out, "no reading")

	f := func(v float64) *float64 { return &v }
	h.srv.SetLiveMetrics("orders", runrtest.LiveMetrics{CPU: f(0.1234), MemoryMB: f(87.5), RPS: f(3.25), CPUAvg1h: f(0.05), MemoryAvg1hMB: f(80)})
	r = h.mustRun("metrics", "live", "-p", "orders")
	contains(t, r.out, "0.123 cores")
	contains(t, r.out, "88 MiB")
	contains(t, r.out, "3.25/s")
	contains(t, r.out, "0.050 cores on average")
	notContains(t, r.out, "no reading")
	p, _ := h.srv.Project("orders")
	calls := h.srv.CallsTo("GET /projects/{project}/deployments/{deployment}/metrics")
	if last := calls[len(calls)-1]; last.Query != "" || last.Path != "/projects/"+p.ID+"/deployments/"+d.ID+"/metrics" {
		t.Fatalf("call = %+v", last)
	}

	// --quick: the current values only; -o json carries the API's fields.
	var m runr.DeploymentMetrics
	decodeJSON(t, h.mustRun("metrics", "live", "-p", "orders", "--quick", "--deployment", d.ID, "-o", "json").out, &m)
	if m.CPU == nil || *m.CPU != 0.1234 || m.RPS == nil || *m.RPS != 3.25 || m.CPUAvg1h != nil {
		t.Fatalf("json = %+v", m)
	}
	calls = h.srv.CallsTo("GET /projects/{project}/deployments/{deployment}/metrics")
	if q, _ := url.ParseQuery(calls[len(calls)-1].Query); q.Get("quick") != "1" {
		t.Fatalf("query = %q", calls[len(calls)-1].Query)
	}
	r = h.mustRun("metrics", "live", "-p", "orders", "--quick")
	notContains(t, r.out, "last hour")

	h.expectCode(ExitNotFound, "metrics", "live", "-p", "orders", "--deployment", "01J00000000000000000000099")
	h.expectCode(ExitUsage, "metrics", "live", "-p", "orders", "--watch", "100ms")

	h.loginWith(h.srv.ScopedToken("dev", "projects.view"))
	r = h.expectCode(ExitAuth, "metrics", "live", "-p", "orders")
	contains(t, r.err, "metrics.view")
}
