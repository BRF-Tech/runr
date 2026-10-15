package runr_test

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestSecurityClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	srv.SeedSecurityReport("svc", 1, runr.SeverityCounts{Critical: 1},
		runr.SecurityFinding{ID: "CVE-2026-1", Severity: "CRITICAL", Package: "openssl", Fixed: "3.0.9"})

	ov, err := c.ProjectSecurity(ctx, p.ID)
	if err != nil || len(ov.Versions) != 1 || ov.Versions[0].Report.Counts.Critical != 1 {
		t.Fatalf("overview = %+v, %v", ov, err)
	}
	sec, err := c.VersionSecurityReport(ctx, p.ID, v.ID)
	if err != nil || len(sec.Report.Findings) != 1 || sec.Report.Fixable.Critical != 1 || sec.Decision == nil {
		t.Fatalf("version = %+v, %v", sec, err)
	}

	var buf bytes.Buffer
	d := &runr.Download{W: &buf}
	if err := c.DownloadSBOM(ctx, p.ID, v.ID, runr.SBOMCycloneDX, d); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(buf.String(), "CycloneDX") || d.ContentType != "application/vnd.cyclonedx+json" ||
		d.Filename != "svc-v1.cdx.json" || d.Bytes != int64(buf.Len()) {
		t.Fatalf("download = %+v %q", d, buf.String())
	}
	if err := c.DownloadSBOM(ctx, p.ID, v.ID, "xml", &runr.Download{W: &buf}); !runr.IsValidation(err) {
		t.Fatalf("unknown format: %v", err)
	}

	r, err := c.ScanVersion(ctx, p.ID, v.ID)
	if err != nil || !runr.ReportRunning(r) {
		t.Fatalf("scan = %+v, %v", r, err)
	}
	if _, err := c.ScanVersion(ctx, p.ID, v.ID); securityStatusOf(err) != 409 {
		t.Fatalf("second scan: %v", err)
	}
	done, err := c.WaitSecurityReport(ctx, p.ID, &v, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil || done.Report.Status != "ok" {
		t.Fatalf("wait = %+v, %v", done, err)
	}
}

func securityStatusOf(err error) int {
	var ae *runr.APIError
	if errors.As(err, &ae) {
		return ae.StatusCode
	}
	return 0
}

func TestResidencyClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	res, err := c.GetResidency(context.Background())
	if err != nil || res.Restricted || res.AllowedRegionIDs != nil || len(res.Regions) != 1 || !res.Regions[0].Allowed {
		t.Fatalf("residency = %+v, %v", res, err)
	}
}

func TestAuditClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	for _, a := range []string{"project.create", "project.update", "auth.login"} {
		srv.SeedAuditEvent(runr.AuditEvent{Action: a})
	}
	page, err := c.ListAuditEvents(ctx, runr.AuditFilter{Action: "project.*", PerPage: 1})
	if err != nil || len(page.Events) != 1 || page.Events[0].Action != "project.update" || page.Page.Total != 2 ||
		page.Page.LastPage != 2 || !page.CanExport {
		t.Fatalf("page = %+v, %v", page, err)
	}
	e, err := c.GetAuditEvent(ctx, page.Events[0].ID)
	if err != nil || e.Actor.Name != "Ada Lovelace" {
		t.Fatalf("event = %+v, %v", e, err)
	}
	var buf bytes.Buffer
	d := &runr.Download{W: &buf}
	if err := c.ExportAuditEvents(ctx, runr.AuditFilter{Result: "success"}, "csv", d); err != nil {
		t.Fatal(err)
	}
	if lines := strings.Count(buf.String(), "\n"); lines != 4 || !strings.HasPrefix(d.ContentType, "text/csv") {
		t.Fatalf("export: %d lines, %s\n%s", lines, d.ContentType, buf.String())
	}
	if _, err := c.ListAuditEvents(ctx, runr.AuditFilter{Result: "maybe"}); !runr.IsValidation(err) {
		t.Fatalf("bad result filter: %v", err)
	}
}
