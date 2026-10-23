package cmd

import (
	"path/filepath"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestLoadTestRunWaitsForTheResults(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	r := h.mustRun("loadtest", "run", "/api/orders?page=2", "-p", "orders", "--users", "50", "--duration", "1m")
	contains(t, r.err, "metered")
	contains(t, r.out, "p95 48.7ms")
	contains(t, r.out, "Pods at peak")
	body := h.srv.CallsTo("POST /projects/{project}/load-tests")[0].Body
	if body["path"] != "/api/orders?page=2" || body["users"] != float64(50) || body["duration_seconds"] != float64(60) || body["method"] != "GET" {
		t.Fatalf("body = %v", body)
	}
	if _, sent := body["body"]; sent {
		t.Fatalf("a GET sent a body: %v", body)
	}
	tests := h.srv.LoadTests("orders")
	if len(tests) != 1 || tests[0].Status != runr.LoadTestCompleted {
		t.Fatalf("tests = %+v", tests)
	}

	var lt runr.LoadTest
	decodeJSON(t, h.mustRun("loadtest", "get", tests[0].ID, "-p", "orders", "-o", "json").out, &lt)
	if lt.Results == nil || lt.Results.Requests != 15000 || *lt.Results.PodsPeak != 5 {
		t.Fatalf("get = %+v", lt)
	}
}

func TestLoadTestRunWithABodyAndNoWait(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	bodyFile := filepath.Join(t.TempDir(), "order.json")
	write(t, bodyFile, `{"item":42}`)

	r := h.mustRun("loadtest", "run", "/orders", "-p", "orders", "--method", "post", "--body-file", bodyFile, "--no-wait")
	contains(t, r.out, "pending")
	body := h.srv.CallsTo("POST /projects/{project}/load-tests")[0].Body
	if body["method"] != "POST" || body["body"] != `{"item":42}` || body["content_type"] != "application/json" ||
		body["users"] != float64(10) || body["duration_seconds"] != float64(30) {
		t.Fatalf("body = %v", body)
	}

	// one test per project at a time
	r = h.run("loadtest", "run", "-p", "orders")
	if r.code == 0 {
		t.Fatal("a second test while one runs was accepted")
	}
	contains(t, r.err, "running")

	h.mustRun("loadtest", "cancel", h.srv.LoadTests("orders")[0].ID, "-p", "orders")
	if got := h.srv.LoadTests("orders")[0].Status; got != runr.LoadTestCancelled {
		t.Fatalf("status = %s", got)
	}
}

func TestLoadTestRefusals(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	h.expectCode(ExitUsage, "loadtest", "run", "api", "-p", "orders")
	h.expectCode(ExitUsage, "loadtest", "run", "-p", "orders", "--method", "DELETE")
	h.expectCode(ExitUsage, "loadtest", "run", "-p", "orders", "--body", "{}")
	h.expectCode(ExitUsage, "loadtest", "run", "-p", "orders", "--duration", "1500ms")
	h.expectCode(ExitValidation, "loadtest", "run", "-p", "orders", "--users", "20")
	h.expectCode(ExitValidation, "loadtest", "run", "-p", "orders", "--host", "example.com")
	if n := len(h.srv.LoadTests("orders")); n != 0 {
		t.Fatalf("%d tests started", n)
	}

	h.srv.SetLoadTestOutcome("k6 ended without results: dial tcp 10.96.0.42:443: i/o timeout", 0)
	r := h.expectCode(ExitFailed, "loadtest", "run", "-p", "orders")
	contains(t, r.err, "i/o timeout")

	h.srv.SetLoadTestOutcome("", 1)
	r = h.run("loadtest", "run", "-p", "orders")
	if r.code == 0 {
		t.Fatal("a test past the daily limit was accepted")
	}
	contains(t, r.err, "daily limit")
}

func TestLoadTestComparesTheLastRuns(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	r := h.mustRun("loadtest", "-p", "orders")
	contains(t, r.out, "No completed load test yet")

	p95 := func(v float64) runr.LoadTestResults {
		return runr.LoadTestResults{Requests: 3000, RPS: 99.5, LatencyMs: runr.LoadTestLatency{P95: &v}}
	}
	for _, v := range []float64{31, 32, 33} {
		res := p95(v)
		h.srv.SeedLoadTest("orders", runr.LoadTest{Users: 10, DurationSeconds: 30, Results: &res})
	}
	h.srv.SeedLoadTest("orders", runr.LoadTest{Status: runr.LoadTestFailed, Error: "boom", Users: 10, DurationSeconds: 30})

	r = h.mustRun("loadtest", "ls", "-p", "orders", "--limit", "3")
	contains(t, r.out, "p95")
	contains(t, r.out, "33ms")
	contains(t, r.out, "32ms")
	notContains(t, r.out, "31ms")
	contains(t, r.out, "Today")

	var out struct {
		Tests   []runr.LoadTest      `json:"tests"`
		Options runr.LoadTestOptions `json:"options"`
	}
	decodeJSON(t, h.mustRun("loadtest", "ls", "-p", "orders", "-o", "json").out, &out)
	if len(out.Tests) != 4 || out.Options.DailyLimit != 20 || len(out.Options.Targets) != 1 {
		t.Fatalf("ls = %+v", out)
	}
	h.expectCode(ExitUsage, "loadtest", "ls", "-p", "orders", "--limit", "0")
}
