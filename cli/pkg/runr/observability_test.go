package runr_test

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func obsSeedTrace(srv *runrtest.Server, project string, status int, path string) runr.Trace {
	return srv.SeedTrace(project, runr.Trace{
		Start: "2026-10-11T10:00:00.000Z", StartMs: 1791972000000, EndMs: 1791972000120, DurationMs: 120,
		Services: []string{project},
		Spans: []runr.Span{
			{Name: "GET " + path, Kind: "server", Service: project, DurationMs: 120,
				Status:     runr.SpanStatus{Code: map[bool]string{true: "error", false: "ok"}[status >= 500]},
				Attributes: runr.ObsAttrs{"http.response.status_code": float64(status), "url.path": path, "runr.cold_start": true}},
			{Name: "SELECT orders", Kind: "client", Service: project, ParentSpanID: "0000000000000001", Depth: 1,
				StartOffsetMs: 5, DurationMs: 80, Status: runr.SpanStatus{Code: "unset"}, Attributes: runr.ObsAttrs{}},
		},
	}, runr.TraceLogLine{TsMs: 1791972000010, Pod: project + "-00001-deployment-abc", Line: "order 42 failed"})
}

func TestObsAttrsAcceptsEmptyArray(t *testing.T) {
	var v struct {
		A runr.ObsAttrs `json:"a"`
		B runr.ObsAttrs `json:"b"`
		C runr.ObsAttrs `json:"c"`
	}
	if err := json.Unmarshal([]byte(`{"a":[],"b":null,"c":{"x":1,"y":"z"}}`), &v); err != nil {
		t.Fatal(err)
	}
	if len(v.A) != 0 || len(v.B) != 0 || v.C.String("x") != "1" || v.C.String("y") != "z" || v.C.String("none") != "" {
		t.Fatalf("decoded = %+v", v)
	}
}

func TestObsTracesSearchAndDetail(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	bad := obsSeedTrace(srv, "orders", 500, "/orders")
	obsSeedTrace(srv, "orders", 200, "/health")

	from := time.Unix(1791968400, 0)
	res, err := c.SearchTraces(ctx, p.ID, runr.TraceFilter{Status: runr.TraceStatusError, Path: "/ord", MinDurationMs: 50, From: from, Limit: 10})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Traces) != 1 || res.Traces[0].TraceID != bad.TraceID || !res.Traces[0].Error || *res.Traces[0].StatusCode != 500 {
		t.Fatalf("search = %+v", res.Traces)
	}
	q, _ := url.ParseQuery(srv.CallsTo("GET /projects/{project}/traces")[0].Query)
	if q.Get("status") != "error" || q.Get("path") != "/ord" || q.Get("min_duration_ms") != "50" || q.Get("from") != "1791968400" ||
		q.Get("limit") != "10" || q.Has("to") {
		t.Fatalf("query = %v", q)
	}

	tr, err := c.GetTrace(ctx, p.ID, strings.ToUpper(bad.TraceID))
	if err != nil {
		t.Fatal(err)
	}
	if tr.SpanCount != 2 || tr.ErrorCount != 1 || tr.Spans[1].Depth != 1 || tr.Spans[0].Attributes.String("url.path") != "/orders" {
		t.Fatalf("trace = %+v", tr)
	}
	lines, err := c.TraceLogs(ctx, p.ID, bad.TraceID)
	if err != nil || len(lines) != 1 || lines[0].Line != "order 42 failed" {
		t.Fatalf("logs = %+v, %v", lines, err)
	}

	var nf *runr.NotFoundError
	if _, err := c.GetTrace(ctx, p.ID, "not-a-trace"); !errors.As(err, &nf) {
		t.Fatalf("invalid id: %v", err)
	}
	if _, err := c.GetTrace(ctx, p.ID, strings.Repeat("ab", 16)); !errors.As(err, &nf) {
		t.Fatalf("unknown trace: %v", err)
	}
	if n := len(srv.CallsTo("GET /projects/{project}/traces/{traceId}")); n != 2 {
		t.Fatalf("%d trace calls, want 2 (an invalid id is refused locally)", n)
	}
}

func TestObsTracesNotQueryable(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetTracingBackend("external", "ready")
	_, err := c.SearchTraces(context.Background(), p.ID, runr.TraceFilter{})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("err = %v, want 409", err)
	}
}

func TestObsTraceLogsNeedLogsView(t *testing.T) {
	srv := runrtest.New(t)
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	tr := obsSeedTrace(srv, "orders", 500, "/orders")
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: srv.ScopedToken("traces", "traces.view"), HTTPClient: srv.Client(), Retries: -1})
	ctx := context.Background()
	if _, err := c.GetTrace(ctx, p.ID, tr.TraceID); err != nil {
		t.Fatalf("traces.view reads the trace: %v", err)
	}
	_, err := c.TraceLogs(ctx, p.ID, tr.TraceID)
	if perms := runr.MissingPermissions(err); len(perms) != 1 || perms[0] != "logs.view" {
		t.Fatalf("logs without logs.view: %v (lacking %v)", err, perms)
	}
}

func TestObsTracingSwitches(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	ratio := 0.25
	o, err := c.SetTracing(ctx, p.ID, true, &ratio)
	if err != nil || !o.TracingEnabled || o.TracingSampleRatio != 0.25 || o.RequiresRedeploy {
		t.Fatalf("tracing = %+v, %v", o, err)
	}
	if body := srv.CallsTo("PUT /projects/{project}/observability/tracing")[0].Body; body["enabled"] != true || body["sample_ratio"] != 0.25 {
		t.Fatalf("body = %v", body)
	}
	maxBody := 1024
	o, err = c.SetReplay(ctx, p.ID, runr.ReplaySettings{Enabled: true, MaxBodyBytes: &maxBody, RedactHeaders: &[]string{"X-Tenant"}})
	if err != nil || !o.ReplayEnabled || o.ReplayMaxBodyBytes != 1024 || o.ReplayRedactHeaders[0] != "x-tenant" {
		t.Fatalf("replay = %+v, %v", o, err)
	}
	got, err := c.ProjectObservability(ctx, p.ID)
	if err != nil || !got.TracingEnabled || got.RegionTracing == nil || !got.RegionTracing.Queryable {
		t.Fatalf("settings = %+v, %v", got, err)
	}

	srv.SetTracingBackend("managed", "installing")
	if _, err := c.SetTracing(ctx, p.ID, true, nil); obsStatusOf(err) != 409 {
		t.Fatalf("tracing on a region that is not ready: %v", err)
	}
	web := srv.SeedProject("web", runr.TypeCaaS, "", "eu-1", map[string]any{"image": "nginx"})
	if _, err := c.SetReplay(ctx, web.ID, runr.ReplaySettings{Enabled: true}); !runr.IsValidation(err) {
		t.Fatalf("replay of a container: %v", err)
	}
}

func obsStatusOf(err error) int {
	var ae *runr.APIError
	if errors.As(err, &ae) {
		return ae.StatusCode
	}
	return 0
}

func TestObsReplays(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	cap1 := srv.SeedReplay("orders", runr.ReplayCapture{Path: "/orders", Status: 502, Headers: runr.ObsAttrs{"content-type": "application/json"},
		ResponseBody: `{"error":"upstream"}`})
	srv.SeedReplay("orders", runr.ReplayCapture{Path: "/health", Status: 500})

	list, pg, err := c.ListReplays(ctx, p.ID, 502, "", 0)
	if err != nil || len(list) != 1 || list[0].ID != cap1.ID || pg.Total != 1 || list[0].Headers != nil {
		t.Fatalf("list = %+v %+v, %v", list, pg, err)
	}
	detail, err := c.GetReplay(ctx, p.ID, cap1.ID)
	if err != nil || detail.Headers.String("content-type") != "application/json" || detail.ResponseBody == "" {
		t.Fatalf("detail = %+v, %v", detail, err)
	}
	q := "page=2"
	res, err := c.Replay(ctx, p.ID, cap1.ID, map[string]string{"Authorization": "Bearer test"}, &q)
	if err != nil || res.Diff.Status.Before != 502 || res.Diff.Status.After != 200 || !res.Diff.Changed || res.Capture.ReplayCount != 1 {
		t.Fatalf("replay = %+v, %v", res, err)
	}
	if body := srv.CallsTo("POST /projects/{project}/replays/{capture}/replay")[0].Body; body["query"] != "page=2" {
		t.Fatalf("replay body = %v", body)
	}
	if err := c.DeleteReplay(ctx, p.ID, cap1.ID); err != nil {
		t.Fatal(err)
	}
	var nf *runr.NotFoundError
	if _, err := c.GetReplay(ctx, p.ID, cap1.ID); !errors.As(err, &nf) {
		t.Fatalf("deleted capture: %v", err)
	}
	if n, err := c.DeleteAllReplays(ctx, p.ID); err != nil || n != 1 {
		t.Fatalf("delete all = %d, %v", n, err)
	}
}

func TestObsUptimeChecks(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	name, path, interval := "health", "/health", 30
	u, err := c.CreateUptimeCheck(ctx, p.ID, runr.UptimeCheckInput{Name: &name, Path: &path, IntervalSeconds: &interval,
		Headers: &map[string]string{"X-Probe": "secret"}})
	if err != nil || u.Path != "/health" || u.IntervalSeconds != 30 || u.Status != runr.UptimeUnknown || u.HeaderNames[0] != "X-Probe" {
		t.Fatalf("create = %+v, %v", u, err)
	}
	bad := 5
	if _, err := c.CreateUptimeCheck(ctx, p.ID, runr.UptimeCheckInput{Name: &name, IntervalSeconds: &bad}); !runr.IsValidation(err) {
		t.Fatalf("interval 5s: %v", err)
	}
	expect := "2xx"
	if u, err = c.UpdateUptimeCheck(ctx, p.ID, u.ID, runr.UptimeCheckInput{ExpectedStatus: &expect}); err != nil || u.ExpectedStatus != "2xx" {
		t.Fatalf("update = %+v, %v", u, err)
	}
	found, err := c.FindUptimeCheck(ctx, p.ID, "HEALTH")
	if err != nil || found.ID != u.ID {
		t.Fatalf("find = %+v, %v", found, err)
	}
	var nf *runr.NotFoundError
	if _, err := c.FindUptimeCheck(ctx, p.ID, "ghost"); !errors.As(err, &nf) {
		t.Fatalf("ghost: %v", err)
	}

	run, err := c.RunUptimeCheck(ctx, p.ID, u.ID)
	if err != nil || !run.OK || run.Check.Status != runr.UptimeUp {
		t.Fatalf("run = %+v, %v", run, err)
	}
	srv.SeedUptimeIncident("orders", u.ID, runr.UptimeIncident{Title: "Health down"})
	rep, err := c.UptimeResults(ctx, p.ID, u.ID, 48, "control-plane")
	if err != nil || len(rep.Results) != 1 || rep.Uptime.H24 == nil || *rep.Uptime.H24 != 100 || len(rep.Incidents) != 1 {
		t.Fatalf("results = %+v, %v", rep, err)
	}
	if q := srv.CallsTo("GET /projects/{project}/uptime-checks/{check}/results")[0].Query; q != "hours=48&location=control-plane" {
		t.Fatalf("results query = %q", q)
	}
	list, err := c.ListUptimeChecks(ctx, p.ID)
	if err != nil || len(list) != 1 || list[0].Uptime24h == nil {
		t.Fatalf("list = %+v, %v", list, err)
	}

	incidents, err := c.ListUptimeIncidents(ctx, p.ID)
	if err != nil || len(incidents) != 1 || !incidents[0].Open() {
		t.Fatalf("incidents = %+v, %v", incidents, err)
	}
	title := "Checkout degraded"
	in, err := c.UpdateUptimeIncident(ctx, p.ID, incidents[0].ID, &title, nil)
	if err != nil || in.Title != title {
		t.Fatalf("incident = %+v, %v", in, err)
	}
	if err := c.DeleteUptimeCheck(ctx, p.ID, u.ID); err != nil || len(srv.UptimeChecks("orders")) != 0 {
		t.Fatalf("delete: %v", err)
	}
}

func TestObsAlertChannelsKeepSecrets(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})

	name, typ := "oncall", "webhook"
	ch, err := c.CreateAlertChannel(ctx, runr.AlertChannelInput{Name: &name, Type: &typ,
		Config: map[string]any{"url": "https://hooks.example.com/runr?key=k1", "secret": "0123456789abcdef-signing"}})
	if err != nil {
		t.Fatal(err)
	}
	if ch.Config.String("url_host") != "hooks.example.com" || ch.Config.String("secret_set") != "true" || ch.Config.String("url") != "" {
		t.Fatalf("masked config = %v", ch.Config)
	}
	if strings.Contains(trace.String(), "0123456789abcdef-signing") || strings.Contains(trace.String(), "key=k1") {
		t.Fatalf("the trace shows a channel secret:\n%s", trace.String())
	}

	// An update without the secret keeps it.
	newURL := map[string]any{"url": "https://hooks.example.com/v2"}
	if _, err := c.UpdateAlertChannel(ctx, ch.ID, runr.AlertChannelInput{Config: newURL, Type: &typ}); err != nil {
		t.Fatal(err)
	}
	if got := srv.AlertChannelSecrets("oncall"); got["secret"] != "0123456789abcdef-signing" || got["url"] != "https://hooks.example.com/v2" {
		t.Fatalf("stored config = %v", got)
	}
	if _, has := srv.CallsTo("PUT /observability/alert-channels/{channel}")[0].Body["type"]; has {
		t.Fatal("an update sends the type")
	}
	res, err := c.TestAlertChannel(ctx, ch.ID)
	if err != nil || !res.OK || res.Channel.LastTestOK == nil || !*res.Channel.LastTestOK {
		t.Fatalf("test = %+v, %v", res, err)
	}
	if found, err := c.FindAlertChannel(ctx, "ONCALL"); err != nil || found.ID != ch.ID {
		t.Fatalf("find = %+v, %v", found, err)
	}
	bad := "slack"
	if _, err := c.CreateAlertChannel(ctx, runr.AlertChannelInput{Name: &name, Type: &bad,
		Config: map[string]any{"webhook_url": "http://insecure.example.com"}}); !runr.IsValidation(err) {
		t.Fatalf("slack over http: %v", err)
	}
}

func TestObsAlertRulesEventsDeliveries(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	name, typ := "ops", "email"
	ch, err := c.CreateAlertChannel(ctx, runr.AlertChannelInput{Name: &name, Type: &typ, Config: map[string]any{"emails": []string{"Ops@Example.com"}}})
	if err != nil {
		t.Fatal(err)
	}

	rname, rtype := "errors", "error_rate"
	if _, err := c.CreateAlertRule(ctx, runr.AlertRuleInput{Name: &rname, Type: &rtype}); !runr.IsValidation(err) {
		t.Fatalf("error_rate without threshold: %v", err)
	}
	r, err := c.CreateAlertRule(ctx, runr.AlertRuleInput{Name: &rname, Type: &rtype, ProjectID: &p.ID,
		Params: map[string]any{"threshold": 5.0, "window_minutes": 10.0, "unknown": "x"}, ChannelIDs: &[]string{ch.ID}})
	if err != nil {
		t.Fatal(err)
	}
	if r.Severity != "warning" || r.CooldownMinutes != 15 || r.Params.String("threshold") != "5" || r.Params.String("unknown") != "" || r.ChannelIDs[0] != ch.ID {
		t.Fatalf("rule = %+v", r)
	}
	sev := "critical"
	if r, err = c.UpdateAlertRule(ctx, r.ID, runr.AlertRuleInput{Severity: &sev, Type: &rtype}); err != nil || r.Severity != "critical" {
		t.Fatalf("update = %+v, %v", r, err)
	}
	if found, err := c.FindAlertRule(ctx, "errors"); err != nil || found.ID != r.ID {
		t.Fatalf("find = %+v, %v", found, err)
	}

	srv.SeedAlertEvent(runr.AlertEvent{AlertRuleID: r.ID, Type: "error_rate", Severity: "critical", Title: "orders: 12% errors"})
	srv.SeedAlertEvent(runr.AlertEvent{Type: "uptime_down", Status: "resolved", Title: "health"})
	firing, err := c.ListAlertEvents(ctx, "firing")
	if err != nil || len(firing) != 1 || firing[0].Title != "orders: 12% errors" {
		t.Fatalf("firing = %+v, %v", firing, err)
	}
	failed := srv.SeedAlertDelivery(runr.AlertDelivery{AlertChannelID: ch.ID, Status: "failed", Attempts: 4, LastError: "550 mailbox"})
	sent := srv.SeedAlertDelivery(runr.AlertDelivery{AlertChannelID: ch.ID, Status: "sent", Attempts: 1})
	list, pg, err := c.ListAlertDeliveries(ctx, ch.ID, "failed")
	if err != nil || len(list) != 1 || list[0].ID != failed.ID || list[0].ChannelName != "ops" || pg.Total != 1 {
		t.Fatalf("deliveries = %+v %+v, %v", list, pg, err)
	}
	d, err := c.RetryAlertDelivery(ctx, failed.ID)
	if err != nil || d.Status != "pending" {
		t.Fatalf("retry = %+v, %v", d, err)
	}
	if _, err := c.RetryAlertDelivery(ctx, sent.ID); obsStatusOf(err) != 409 {
		t.Fatalf("retry of a sent delivery: %v", err)
	}
	var nf *runr.NotFoundError
	if _, err := c.RetryAlertDelivery(ctx, "01JXXXXXXXXXXXXXXXXXXXXXXX"); !errors.As(err, &nf) {
		t.Fatalf("unknown delivery: %v", err)
	}

	if err := c.DeleteAlertChannel(ctx, ch.ID); err != nil {
		t.Fatal(err)
	}
	if rules := srv.AlertRules(); len(rules[0].ChannelIDs) != 0 {
		t.Fatalf("a deleted channel stays on the rule: %+v", rules[0])
	}
	if err := c.DeleteAlertRule(ctx, r.ID); err != nil || len(srv.AlertRules()) != 0 {
		t.Fatalf("delete rule: %v", err)
	}
}

func TestObsStatusPage(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	name := "health"
	u, err := c.CreateUptimeCheck(ctx, p.ID, runr.UptimeCheckInput{Name: &name})
	if err != nil {
		t.Fatal(err)
	}
	sp, err := c.StatusPage(ctx)
	if err != nil || sp.Page.Exists || len(sp.Components) != 1 {
		t.Fatalf("status page = %+v, %v", sp, err)
	}
	if _, err := c.SetStatusPage(ctx, runr.StatusPageInput{Slug: "A", Title: "x"}); !runr.IsValidation(err) {
		t.Fatalf("bad slug: %v", err)
	}
	shown := "Orders API"
	sp, err = c.SetStatusPage(ctx, runr.StatusPageInput{Slug: "acme", Title: "Acme status", IsPublic: true,
		Components: []runr.StatusPageComponentInput{{ID: u.ID, ShowOnStatusPage: true, StatusPageName: &shown}}})
	if err != nil || !sp.Page.Exists || !sp.Page.IsPublic || sp.PublicPath != "/status/acme" || !sp.Components[0].ShowOnStatusPage ||
		sp.Components[0].StatusPageName != shown {
		t.Fatalf("saved = %+v, %v", sp, err)
	}
}
