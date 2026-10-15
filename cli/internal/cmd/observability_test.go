package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func obsTraceFixture(h *harness, project string, status int) runr.Trace {
	return h.srv.SeedTrace(project, runr.Trace{
		Start: "2026-10-11T10:00:00.000Z", StartMs: 1791972000000, EndMs: 1791972000120, DurationMs: 120,
		Services: []string{project},
		Spans: []runr.Span{
			{Name: "POST /orders", Kind: "server", Service: project, DurationMs: 120,
				Status:     runr.SpanStatus{Code: map[bool]string{true: "error", false: "ok"}[status >= 500]},
				Attributes: runr.ObsAttrs{"http.response.status_code": float64(status), "url.path": "/orders"}},
			{Name: "SELECT orders", Kind: "client", Service: project, ParentSpanID: "0000000000000001", Depth: 1,
				StartOffsetMs: 4.5, DurationMs: 80, Status: runr.SpanStatus{Code: "unset"}, Attributes: runr.ObsAttrs{}},
		},
	}, runr.TraceLogLine{TsMs: 1791972000010, Pod: project + "-00001-deployment-abc", Line: "order 42 failed"})
}

func TestObsTracesListAndGet(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	bad := obsTraceFixture(h, "orders", 500)
	ok := obsTraceFixture(h, "orders", 200)

	r := h.mustRun("traces", "-p", "orders", "--status", "error", "--since", "2d")
	contains(t, r.out, bad.TraceID)
	notContains(t, r.out, ok.TraceID)
	contains(t, r.out, "500 error")
	r = h.mustRun("traces", "ls", "-p", "orders", "--min-duration", "100ms", "--path", "/orders", "--limit", "5")
	contains(t, r.out, ok.TraceID)

	r = h.mustRun("traces", "get", bad.TraceID, "-p", "orders", "--logs")
	contains(t, r.out, "POST /orders")
	contains(t, r.out, "  SELECT orders")
	contains(t, r.out, "+4.5ms")
	contains(t, r.out, "Logs:")
	contains(t, r.out, "order 42 failed")

	var tr runr.Trace
	decodeJSON(t, h.mustRun("traces", "get", bad.TraceID, "-p", "orders", "-o", "json").out, &tr)
	if tr.TraceID != bad.TraceID || len(tr.Spans) != 2 || tr.ErrorCount != 1 {
		t.Fatalf("trace json = %+v", tr)
	}
	var withLogs struct {
		Trace runr.Trace          `json:"trace"`
		Logs  []runr.TraceLogLine `json:"logs"`
	}
	decodeJSON(t, h.mustRun("traces", "get", bad.TraceID, "-p", "orders", "--logs", "-o", "json").out, &withLogs)
	if len(withLogs.Logs) != 1 || withLogs.Trace.TraceID != bad.TraceID {
		t.Fatalf("trace with logs = %+v", withLogs)
	}

	h.expectCode(ExitUsage, "traces", "get", "nope", "-p", "orders")
	h.expectCode(ExitNotFound, "traces", "get", strings.Repeat("cd", 16), "-p", "orders")
	h.expectCode(ExitUsage, "traces", "-p", "orders", "--status", "slow")
	h.expectCode(ExitUsage, "traces", "-p", "orders", "--since", "yesterday")
	h.expectCode(ExitUsage, "traces", "-p", "orders", "--limit", "500")
}

func TestObsTracesBackendNotQueryable(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SetTracingBackend("off", "ready")
	r := h.expectCode(ExitValidation, "traces", "-p", "orders")
	contains(t, r.err, "cannot be queried")
}

func TestObsTraceLogsNeedLogsView(t *testing.T) {
	h := newHarness(t)
	h.loginWith(h.srv.ScopedToken("traces-only", "projects.view", "traces.view"))
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	tr := obsTraceFixture(h, "orders", 500)
	h.mustRun("traces", "get", tr.TraceID, "-p", "orders")
	r := h.expectCode(ExitAuth, "traces", "get", tr.TraceID, "-p", "orders", "--logs")
	contains(t, r.err, "logs.view")
}

func TestObsTracingEnableRedeploys(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	before := len(h.srv.Deployments("hello-api"))

	r := h.mustRun("traces", "enable", "--sample-ratio", "0.5", "--dir", dir)
	contains(t, r.err, "Tracing of hello-api is on (sample ratio 0.5)")
	if s := h.srv.ObservabilitySettings("hello-api"); !s.TracingEnabled || s.TracingSampleRatio != 0.5 {
		t.Fatalf("settings = %+v", s)
	}
	if n := len(h.srv.Deployments("hello-api")); n != before+1 {
		t.Fatalf("%d deployments, want %d (enable redeploys the live version)", n, before+1)
	}

	h.mustRun("traces", "disable", "--no-deploy", "--dir", dir)
	if s := h.srv.ObservabilitySettings("hello-api"); s.TracingEnabled {
		t.Fatal("tracing still on")
	}
	if n := len(h.srv.Deployments("hello-api")); n != before+1 {
		t.Fatal("--no-deploy redeployed")
	}
	h.expectCode(ExitUsage, "traces", "enable", "--sample-ratio", "2", "--dir", dir)

	var o runr.ProjectObservability
	decodeJSON(t, h.mustRun("traces", "status", "--dir", dir, "-o", "json").out, &o)
	if o.TracingEnabled || o.RegionTracing == nil || o.RegionTracing.Mode != "managed" {
		t.Fatalf("status json = %+v", o)
	}
	r = h.mustRun("traces", "status", "--dir", dir)
	contains(t, r.out, "Region backend:")
	contains(t, r.out, "managed (ready)")
}

func TestObsTracingEnableWithoutDeployment(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("idle", runr.TypeFaaS, "python", "eu-1", nil)
	r := h.mustRun("traces", "enable", "-p", "idle")
	contains(t, r.out, "Tracing:")
	if len(h.srv.Deployments("idle")) != 0 {
		t.Fatal("a project without a deployment was deployed")
	}
	h.srv.SetTracingBackend("managed", "installing")
	h.expectCode(ExitValidation, "traces", "enable", "-p", "idle")
}

func TestObsReplayCommands(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	c1 := h.srv.SeedReplay("orders", runr.ReplayCapture{Path: "/orders", Query: "id=1", Status: 502,
		Headers: runr.ObsAttrs{"content-type": "application/json"}, Body: `{"id":1}`, ResponseBody: `{"error":"upstream"}`})
	c2 := h.srv.SeedReplay("orders", runr.ReplayCapture{Path: "/health", Status: 500})

	r := h.mustRun("traces", "replay", "ls", "-p", "orders")
	contains(t, r.out, c1.ID)
	contains(t, r.out, "POST /orders?id=1")
	r = h.mustRun("traces", "replay", "ls", "-p", "orders", "--status", "500")
	notContains(t, r.out, c1.ID)
	contains(t, r.out, c2.ID)

	r = h.mustRun("traces", "replay", "get", c1.ID, "-p", "orders")
	contains(t, r.out, "content-type: application/json")
	contains(t, r.out, `{"error":"upstream"}`)

	r = h.mustRun("traces", "replay", "run", c1.ID, "-p", "orders", "-H", "Authorization: Bearer test", "--query", "id=2")
	contains(t, r.out, "502 → 200")
	contains(t, r.out, "Answer changed:")
	body := h.srv.CallsTo("POST /projects/{project}/replays/{capture}/replay")[0].Body
	if hd, _ := body["headers"].(map[string]any); hd["Authorization"] != "Bearer test" || body["query"] != "id=2" {
		t.Fatalf("replay body = %v", body)
	}
	h.expectCode(ExitUsage, "traces", "replay", "run", c1.ID, "-p", "orders", "-H", "no-colon")

	h.expectCode(ExitUsage, "traces", "replay", "rm", c1.ID, "-p", "orders")
	h.expectCode(ExitUsage, "traces", "replay", "rm", "-p", "orders", "--yes")
	h.mustRun("traces", "replay", "rm", c1.ID, "-p", "orders", "--yes")
	h.expectCode(ExitNotFound, "traces", "replay", "get", c1.ID, "-p", "orders")
	r = h.mustRun("traces", "replay", "rm", "--all", "-p", "orders", "--yes")
	contains(t, r.err, "Deleted 1 capture.")
}

func TestObsReplayEnable(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("traces", "replay", "enable", "-p", "orders", "--max-body-bytes", "4096", "--retention-days", "3",
		"--redact-header", "X-Tenant", "--redact-query", "session")
	s := h.srv.ObservabilitySettings("orders")
	if !s.ReplayEnabled || s.ReplayMaxBodyBytes != 4096 || s.ReplayRetentionDays != 3 || s.ReplayRedactHeaders[0] != "x-tenant" ||
		s.ReplayRedactQuery[0] != "session" {
		t.Fatalf("settings = %+v", s)
	}
	h.expectCode(ExitUsage, "traces", "replay", "enable", "-p", "orders", "--retention-days", "90")
	h.mustRun("traces", "replay", "disable", "-p", "orders")
	if h.srv.ObservabilitySettings("orders").ReplayEnabled {
		t.Fatal("replay still on")
	}
	h.srv.SeedProject("web", runr.TypeCaaS, "", "eu-1", map[string]any{"image": "nginx"})
	h.expectCode(ExitValidation, "traces", "replay", "enable", "-p", "web")
}

func TestObsUptimeCommands(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	r := h.mustRun("uptime", "create", "health", "-p", "orders", "--path", "/health", "--interval", "30s", "--expect", "200",
		"--header", "X-Probe: abc", "--failure-threshold", "2")
	contains(t, r.err, "Created uptime check health (/health every 30s)")
	checks := h.srv.UptimeChecks("orders")
	if len(checks) != 1 || checks[0].IntervalSeconds != 30 || checks[0].ExpectedStatus != "200" || checks[0].FailureThreshold != 2 ||
		checks[0].HeaderNames[0] != "X-Probe" {
		t.Fatalf("checks = %+v", checks)
	}
	body := h.srv.CallsTo("POST /projects/{project}/uptime-checks")[0].Body
	if _, sent := body["timeout_seconds"]; sent {
		t.Fatalf("an unset flag was sent: %v", body)
	}
	h.expectCode(ExitUsage, "uptime", "create", "fast", "-p", "orders", "--interval", "10s")
	h.expectCode(ExitUsage, "uptime", "create", "both", "-p", "orders", "--url", "https://example.com", "--path", "/x")
	h.expectCode(ExitUsage, "uptime", "create", "verb", "-p", "orders", "--method", "DELETE")

	h.mustRun("uptime", "update", "health", "-p", "orders", "--interval", "1m", "--enabled=false")
	checks = h.srv.UptimeChecks("orders")
	if checks[0].IntervalSeconds != 60 || checks[0].IsEnabled {
		t.Fatalf("updated = %+v", checks[0])
	}
	h.expectCode(ExitUsage, "uptime", "update", "health", "-p", "orders")
	h.expectCode(ExitNotFound, "uptime", "update", "ghost", "-p", "orders", "--interval", "1m")

	h.mustRun("uptime", "run", "health", "-p", "orders")
	r = h.mustRun("uptime", "ls", "-p", "orders")
	contains(t, r.out, "health")
	contains(t, r.out, "up (paused)")
	contains(t, r.out, "100%")

	var rep runr.UptimeReport
	decodeJSON(t, h.mustRun("uptime", "results", "health", "-p", "orders", "--hours", "72", "-o", "json").out, &rep)
	if len(rep.Results) != 1 || !rep.Results[0].OK {
		t.Fatalf("results = %+v", rep)
	}
	h.expectCode(ExitUsage, "uptime", "results", "health", "-p", "orders", "--hours", "0")

	in := h.srv.SeedUptimeIncident("orders", checks[0].ID, runr.UptimeIncident{Title: "Health down"})
	h.srv.SeedUptimeIncident("orders", checks[0].ID, runr.UptimeIncident{Title: "Earlier", ResolvedAt: "2026-10-01T10:00:00Z"})
	r = h.mustRun("uptime", "incidents", "-p", "orders", "--open")
	contains(t, r.out, "Health down")
	notContains(t, r.out, "Earlier")
	contains(t, r.out, "health")
	msgFile := filepath.Join(t.TempDir(), "msg.txt")
	write(t, msgFile, "We are on it.")
	h.mustRun("uptime", "incidents", "update", in.ID, "-p", "orders", "--title", "Checkout degraded", "--message", "@"+msgFile)
	patch := h.srv.CallsTo("PATCH /projects/{project}/uptime-incidents/{incident}")[0].Body
	if patch["title"] != "Checkout degraded" || patch["message"] != "We are on it." {
		t.Fatalf("incident patch = %v", patch)
	}
	h.expectCode(ExitUsage, "uptime", "incidents", "update", in.ID, "-p", "orders")

	h.expectCode(ExitUsage, "uptime", "rm", "health", "-p", "orders")
	h.mustRun("uptime", "rm", "health", "-p", "orders", "--yes")
	if len(h.srv.UptimeChecks("orders")) != 0 {
		t.Fatal("check not deleted")
	}
}

func TestObsUptimeRegions(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	us := h.srv.AddRegion("us-1", true)
	h.mustRun("uptime", "create", "global", "-p", "orders", "--url", "https://orders.example.com/health", "--region", "us-1")
	body := h.srv.CallsTo("POST /projects/{project}/uptime-checks")[0].Body
	ids, _ := body["region_ids"].([]any)
	if body["locations"] != "regions" || len(ids) != 1 || ids[0] != us.ID {
		t.Fatalf("body = %v", body)
	}
	h.expectCode(ExitUsage, "uptime", "create", "mixed", "-p", "orders", "--locations", "control_plane", "--region", "us-1")
}

func TestObsAlertChannels(t *testing.T) {
	h := newHarness(t).login()
	urlFile := filepath.Join(t.TempDir(), "slack-url.txt")
	write(t, urlFile, "https://hooks.slack.example/services/T000/B000/SECRETPART\n")

	r := h.mustRun("alerts", "channels", "create", "oncall", "--type", "slack", "--webhook-url", "@"+urlFile)
	notContains(t, r.out, "SECRETPART")
	if got := h.srv.AlertChannelSecrets("oncall"); got["webhook_url"] != "https://hooks.slack.example/services/T000/B000/SECRETPART" {
		t.Fatalf("stored config = %v", got)
	}
	r = h.runStdin("0123456789abcdef-s3cr3t\n", "alerts", "channels", "create", "hook", "--type", "webhook",
		"--url", "https://hooks.example.com/runr", "--secret", "-")
	if r.code != 0 {
		t.Fatalf("create webhook: exit %d\n%s", r.code, r.err)
	}
	if got := h.srv.AlertChannelSecrets("hook"); got["secret"] != "0123456789abcdef-s3cr3t" {
		t.Fatalf("stored webhook = %v", got)
	}
	h.mustRun("alerts", "channels", "create", "ops", "--type", "email", "--email", "ops@example.com", "--email", "sre@example.com")

	r = h.mustRun("alerts", "channels")
	contains(t, r.out, "hooks.slack.example")
	contains(t, r.out, "hooks.example.com (signed)")
	contains(t, r.out, "ops@example.com, sre@example.com")
	notContains(t, r.out, "SECRETPART")

	h.mustRun("alerts", "channels", "update", "hook", "--url", "https://hooks.example.com/v2", "--enabled=false")
	if got := h.srv.AlertChannelSecrets("hook"); got["secret"] != "0123456789abcdef-s3cr3t" || got["url"] != "https://hooks.example.com/v2" {
		t.Fatalf("updated webhook = %v", got)
	}
	r = h.mustRun("alerts", "channels", "test", "oncall")
	contains(t, r.out, "Delivered:")

	h.expectCode(ExitUsage, "alerts", "channels", "create", "x", "--type", "pager")
	h.expectCode(ExitUsage, "alerts", "channels", "create", "x", "--type", "slack")
	h.expectCode(ExitValidation, "alerts", "channels", "create", "x", "--type", "slack", "--webhook-url", "http://insecure.example.com")
	h.expectCode(ExitUsage, "alerts", "channels", "update", "hook")
	h.expectCode(ExitUsage, "alerts", "channels", "rm", "ops")
	h.mustRun("alerts", "channels", "rm", "ops", "--yes")
	h.expectCode(ExitNotFound, "alerts", "channels", "test", "ops")
}

func TestObsAlertRules(t *testing.T) {
	h := newHarness(t).login()
	p := h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("alerts", "channels", "create", "ops", "--type", "email", "--email", "ops@example.com")

	h.mustRun("alerts", "rules", "create", "api-errors", "--type", "error_rate", "-p", "orders",
		"--param", "threshold=5", "--param", "window_minutes=10", "--channel", "ops", "--cooldown", "30m")
	rules := h.srv.AlertRules()
	if len(rules) != 1 || rules[0].ProjectID != p.ID || rules[0].Params["threshold"] != 5.0 || rules[0].CooldownMinutes != 30 ||
		len(rules[0].ChannelIDs) != 1 {
		t.Fatalf("rules = %+v", rules)
	}
	r := h.mustRun("alerts", "rules")
	contains(t, r.out, "api-errors")
	contains(t, r.out, "threshold=5")
	contains(t, r.out, "ops")

	h.mustRun("alerts", "rules", "update", "api-errors", "--severity", "critical", "--all-projects")
	rules = h.srv.AlertRules()
	if rules[0].Severity != "critical" || rules[0].ProjectID != "" {
		t.Fatalf("updated = %+v", rules[0])
	}
	if _, has := h.srv.CallsTo("PUT /observability/alert-rules/{rule}")[0].Body["type"]; has {
		t.Fatal("an update sends the type")
	}
	h.expectCode(ExitValidation, "alerts", "rules", "create", "slow", "--type", "p95_latency")
	h.expectCode(ExitUsage, "alerts", "rules", "create", "x", "--type", "nope")
	h.expectCode(ExitUsage, "alerts", "rules", "create", "x", "--type", "uptime_down", "--severity", "panic")
	h.expectCode(ExitUsage, "alerts", "rules", "update", "api-errors")
	h.expectCode(ExitNotFound, "alerts", "rules", "create", "y", "--type", "uptime_down", "--channel", "ghost")
	h.mustRun("alerts", "rules", "rm", "api-errors", "--yes")
	if len(h.srv.AlertRules()) != 0 {
		t.Fatal("rule not deleted")
	}
}

func TestObsAlertEventsAndDeliveries(t *testing.T) {
	h := newHarness(t).login()
	h.mustRun("alerts", "channels", "create", "ops", "--type", "email", "--email", "ops@example.com")
	h.srv.SeedAlertEvent(runr.AlertEvent{Type: "error_rate", Severity: "critical", Title: "orders: 12% errors"})
	h.srv.SeedAlertEvent(runr.AlertEvent{Type: "uptime_down", Status: "resolved", Title: "health back"})
	r := h.mustRun("alerts", "events", "--firing")
	contains(t, r.out, "orders: 12% errors")
	notContains(t, r.out, "health back")
	h.expectCode(ExitUsage, "alerts", "events", "--status", "open")

	var channels []runr.AlertChannel
	decodeJSON(t, h.mustRun("alerts", "channels", "ls", "-o", "json").out, &channels)
	failed := h.srv.SeedAlertDelivery(runr.AlertDelivery{AlertChannelID: channels[0].ID, Status: "failed", Attempts: 4, LastError: "550 mailbox full"})
	sent := h.srv.SeedAlertDelivery(runr.AlertDelivery{AlertChannelID: channels[0].ID, Status: "sent", Attempts: 1})
	r = h.mustRun("alerts", "deliveries", "--failed", "--channel", "ops")
	contains(t, r.out, failed.ID)
	contains(t, r.out, "550 mailbox full")
	notContains(t, r.out, sent.ID)
	if q := h.srv.CallsTo("GET /observability/alert-deliveries")[0].Query; !strings.Contains(q, "status=failed") || !strings.Contains(q, "channel_id="+channels[0].ID) {
		t.Fatalf("deliveries query = %q", q)
	}
	r = h.mustRun("alerts", "deliveries", "retry", failed.ID)
	contains(t, r.err, "queued again")
	h.expectCode(ExitValidation, "alerts", "deliveries", "retry", sent.ID)
	h.expectCode(ExitNotFound, "alerts", "deliveries", "retry", "01JXXXXXXXXXXXXXXXXXXXXXXX")
}

func TestObsStatusPageCommands(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("uptime", "create", "health", "-p", "orders", "--path", "/health")

	r := h.mustRun("status-page")
	contains(t, r.out, "not set up")
	h.expectCode(ExitUsage, "status-page", "set", "--title", "Acme status")
	h.expectCode(ExitNotFound, "status-page", "set", "--slug", "acme", "--title", "Acme", "--show", "ghost")

	var sp runr.StatusPageSettings
	decodeJSON(t, h.mustRun("status-page", "set", "--slug", "acme", "--title", "Acme status", "--public", "--show", "health", "-o", "json").out, &sp)
	if !sp.Page.Exists || !sp.Page.IsPublic || sp.Page.Slug != "acme" || len(sp.Components) != 1 || !sp.Components[0].ShowOnStatusPage {
		t.Fatalf("status page = %+v", sp)
	}
	r = h.mustRun("status-page", "get")
	contains(t, r.out, "/status/acme")
	contains(t, r.out, "Acme status")

	h.mustRun("status-page", "set", "--public=false", "--history-days", "30")
	body := h.srv.CallsTo("PUT /observability/status-page")[1].Body
	if body["slug"] != "acme" || body["is_public"] != false || body["history_days"] != 30.0 || body["title"] != "Acme status" {
		t.Fatalf("second save = %v", body)
	}
	h.expectCode(ExitUsage, "status-page", "set", "--history-days", "365")
}
