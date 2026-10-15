package cmd

import (
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func auditSeed(h *harness) {
	h.srv.SeedAuditEvent(runr.AuditEvent{Action: "project.create", Target: &runr.AuditTarget{Type: "project", ID: "p1", Name: "orders"}})
	h.srv.SeedAuditEvent(runr.AuditEvent{Action: "project.secret.set", Target: &runr.AuditTarget{Type: "project", ID: "p1", Name: "orders"},
		Metadata: []byte(`{"name":"API_KEY"}`)})
	h.srv.SeedAuditEvent(runr.AuditEvent{Action: "auth.login", Result: "denied",
		Actor: runr.AuditActor{Type: "anonymous", Name: "mallory"}})
}

func auditQuery(t *testing.T, h *harness) url.Values {
	t.Helper()
	calls := h.srv.CallsTo("GET /audit-events")
	if len(calls) == 0 {
		t.Fatal("no audit list call")
	}
	q, err := url.ParseQuery(calls[len(calls)-1].Query)
	if err != nil {
		t.Fatal(err)
	}
	return q
}

func TestAuditLs(t *testing.T) {
	h := newHarness(t).login()
	auditSeed(h)

	r := h.mustRun("audit", "ls")
	contains(t, r.out, "auth.login")
	contains(t, r.out, "mallory (anonymous)")
	contains(t, r.out, "project orders")
	if i, j := strings.Index(r.out, "auth.login"), strings.Index(r.out, "project.create"); i < 0 || j < 0 || i > j {
		t.Fatalf("not newest first:\n%s", r.out)
	}

	var page runr.AuditPage
	decodeJSON(t, h.mustRun("audit", "ls", "--action", "project.*", "-o", "json").out, &page)
	if len(page.Events) != 2 || page.Page.Total != 2 {
		t.Fatalf("filtered page = %+v", page)
	}
	if q := auditQuery(t, h); q.Get("action") != "project.*" || q.Get("per_page") != "25" {
		t.Fatalf("query = %v", q)
	}

	h.mustRun("audit", "ls", "--result", "denied", "--since", "7d", "--actor", "mallory")
	q := auditQuery(t, h)
	if q.Get("result") != "denied" || q.Get("actor") != "mallory" || q.Get("from") == "" {
		t.Fatalf("query = %v", q)
	}

	r = h.mustRun("audit", "ls", "--limit", "1")
	contains(t, r.out, "1 of 3 events")
}

func TestAuditLsThisProject(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	p, _ := h.srv.Project("hello-api")
	h.srv.SeedAuditEvent(runr.AuditEvent{Action: "project.update", Target: &runr.AuditTarget{Type: "project", ID: runr.FlexID(p.ID), Name: p.Name}})
	r := h.mustRun("audit", "ls", "--this-project", "--dir", dir)
	contains(t, r.out, "project.update")
	if q := auditQuery(t, h); q.Get("target_type") != "project" || q.Get("target_id") != p.ID {
		t.Fatalf("query = %v", q)
	}
	h.expectCode(ExitUsage, "audit", "ls", "--this-project", "--target-type", "binding", "--dir", dir)
}

func TestAuditUsageErrors(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "audit", "ls", "--limit", "0")
	h.expectCode(ExitUsage, "audit", "ls", "--limit", "500")
	h.expectCode(ExitUsage, "audit", "ls", "--result", "maybe")
	h.expectCode(ExitUsage, "audit", "ls", "--since", "yesterday")
	h.expectCode(ExitUsage, "audit", "export", "--format", "xml")
	if len(h.srv.CallsTo("GET /audit-events")) != 0 {
		t.Fatal("an invalid invocation reached the API")
	}
}

func TestAuditGet(t *testing.T) {
	h := newHarness(t).login()
	auditSeed(h)
	var page runr.AuditPage
	decodeJSON(t, h.mustRun("audit", "ls", "--action", "project.secret.set", "-o", "json").out, &page)
	id := page.Events[0].ID
	r := h.mustRun("audit", "get", id)
	contains(t, r.out, "project.secret.set")
	contains(t, r.out, `{"name":"API_KEY"}`)
	h.expectCode(ExitNotFound, "audit", "get", "01JNOSUCHEVENT0000000000000")
}

func TestAuditExport(t *testing.T) {
	h := newHarness(t).login()
	auditSeed(h)

	file := filepath.Join(t.TempDir(), "audit.csv")
	r := h.mustRun("audit", "export", "--file", file, "--action", "project.*")
	contains(t, r.err, "Exported")
	data, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	csv := string(data)
	contains(t, csv, "occurred_at")
	contains(t, csv, "project.secret.set")
	notContains(t, csv, "auth.login")

	r = h.mustRun("audit", "export", "--format", "json")
	var events []runr.AuditEvent
	decodeJSON(t, r.out, &events)
	if len(events) != 3 {
		t.Fatalf("%d exported events", len(events))
	}
	call := h.srv.CallsTo("GET /audit-events/export")[1]
	if q, _ := url.ParseQuery(call.Query); q.Get("format") != "json" {
		t.Fatalf("export query = %q", call.Query)
	}
}

func TestAuditPermissions(t *testing.T) {
	h := newHarness(t).login()
	auditSeed(h)
	h.loginWith(h.srv.ScopedToken("auditor", "audit.view"))
	h.mustRun("audit", "ls")
	r := h.expectCode(ExitAuth, "audit", "export")
	contains(t, r.err, "audit.export")

	h.loginWith(h.srv.ScopedToken("dev", "projects.view"))
	r = h.expectCode(ExitAuth, "audit", "ls")
	contains(t, r.err, "audit.view")
}
