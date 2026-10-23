package cmd

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestOrgExportWaitsAndDownloadsThePackage(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().ExportPolls = 2
	out := filepath.Join(t.TempDir(), "acme.tar.zst")

	r := h.mustRun("org", "export", "--members", "-f", out)

	starts := h.srv.CallsTo("POST /org-transfers/export")
	if len(starts) != 1 {
		t.Fatalf("%d exports started", len(starts))
	}
	body := starts[0].Body
	if body["include_members"] != true || body["passphrase"] != nil {
		t.Fatalf("export body %v", body)
	}
	if d, _ := body["destination"].(map[string]any); d["type"] != "download" {
		t.Fatalf("destination %v", body["destination"])
	}
	data, err := os.ReadFile(out)
	if err != nil || string(data) != string(runrtest.DefaultOrgPackage) {
		t.Fatalf("saved %q (%v)", data, err)
	}
	contains(t, r.err, "Saved "+out)
	contains(t, r.out, "runr-org-acme-20261021-120000.tar.zst")
	contains(t, r.out, "SHA-256")
}

func TestOrgExportResumesAPartialDownload(t *testing.T) {
	h := newHarness(t).login()
	tr := h.srv.SeedOrgTransfer("export", runr.OrgTransferCompleted)
	out := filepath.Join(t.TempDir(), "part.tar.zst")
	write(t, out, string(runrtest.DefaultOrgPackage[:10]))

	c, err := runr.New(runr.Options{APIURL: h.srv.URL, Token: runrtest.DefaultToken, HTTPClient: h.srv.Client()})
	if err != nil {
		t.Fatal(err)
	}
	full, err := c.GetOrgTransfer(t.Context(), tr.ID)
	if err != nil {
		t.Fatal(err)
	}
	app := h.app("", &strings.Builder{}, &strings.Builder{})
	cmd := newOrgExportCmd(app)
	cmd.SetContext(t.Context())
	if _, err := downloadOrgPackage(cmd, app, c, full, out); err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(out)
	if string(data) != string(runrtest.DefaultOrgPackage) {
		t.Fatalf("resumed file %q", data)
	}
}

func TestOrgExportRestartsWhenTheRangeIsIgnored(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().IgnoreRange = true
	tr := h.srv.SeedOrgTransfer("export", runr.OrgTransferCompleted)
	out := filepath.Join(t.TempDir(), "part.tar.zst")
	write(t, out, "garbage!!")

	c, err := runr.New(runr.Options{APIURL: h.srv.URL, Token: runrtest.DefaultToken, HTTPClient: h.srv.Client()})
	if err != nil {
		t.Fatal(err)
	}
	full, _ := c.GetOrgTransfer(t.Context(), tr.ID)
	app := h.app("", &strings.Builder{}, &strings.Builder{})
	cmd := newOrgExportCmd(app)
	cmd.SetContext(t.Context())
	if _, err := downloadOrgPackage(cmd, app, c, full, out); err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(out)
	if string(data) != string(runrtest.DefaultOrgPackage) {
		t.Fatalf("file %q, want the whole package once", data)
	}
}

func TestOrgExportEstimatePrintsSizesAndStartsNothing(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("api", "faas", "python", "eu-1", nil)

	r := h.mustRun("org", "export", "--estimate", "--images")

	if n := len(h.srv.CallsTo("POST /org-transfers/export")); n != 0 {
		t.Fatalf("%d exports started", n)
	}
	est := h.srv.CallsTo("POST /org-transfers/export/estimate")
	if len(est) != 1 || est[0].Body["include_images"] != true {
		t.Fatalf("estimate calls %+v", est)
	}
	contains(t, r.out, "Projects:")
	contains(t, r.out, "size known when pulled")
}

func TestOrgExportSecretsNeedAPassphraseFile(t *testing.T) {
	h := newHarness(t).login()
	t.Setenv(EnvExportPassphrase, "")

	r := h.expectCode(ExitUsage, "org", "export", "--secrets")
	contains(t, r.err, "needs a passphrase")

	short := writeFile(t, "short.txt", "too-short\n")
	r = h.expectCode(ExitUsage, "org", "export", "--secrets", "--passphrase-file", short)
	contains(t, r.err, "at least 12")
	if n := len(h.srv.CallsTo("POST /org-transfers/export")); n != 0 {
		t.Fatalf("%d exports started", n)
	}

	pass := writeFile(t, "pass.txt", "correct horse battery staple\n")
	r = h.mustRun("org", "export", "--secrets", "--passphrase-file", pass, "--no-wait")
	body := h.srv.CallsTo("POST /org-transfers/export")[0].Body
	if body["include_secrets"] != true || body["passphrase"] != "correct horse battery staple" {
		t.Fatalf("export body %v", body)
	}
	notContains(t, r.out+r.err, "correct horse")
}

func TestOrgExportResolvesProjectsBindingsAndDestinations(t *testing.T) {
	h := newHarness(t).login()
	p := h.srv.SeedProject("api", "faas", "python", "eu-1", nil)
	db := h.srv.SeedBinding("db", "postgres", "eu-1", nil, nil)
	store := h.srv.SeedBinding("backups", "object", "eu-1", nil, nil)

	h.mustRun("org", "export", "--project", "api", "--binding-data", "db", "--to", "binding:backups", "--no-wait")
	body := h.srv.CallsTo("POST /org-transfers/export")[0].Body
	if ids, _ := body["projects"].([]any); len(ids) != 1 || ids[0] != p.ID {
		t.Fatalf("projects %v, want [%s]", body["projects"], p.ID)
	}
	if ids, _ := body["binding_data"].([]any); len(ids) != 1 || ids[0] != db.ID {
		t.Fatalf("binding_data %v", body["binding_data"])
	}
	if d, _ := body["destination"].(map[string]any); d["type"] != "binding" || d["binding_id"] != store.ID {
		t.Fatalf("destination %v", body["destination"])
	}

	h.expectCode(ExitUsage, "org", "export", "--to", "binding:db", "--no-wait")
}

func TestOrgExportToS3TakesCredentialsFromTheEnvironment(t *testing.T) {
	h := newHarness(t).login()
	t.Setenv("AWS_ACCESS_KEY_ID", "")
	t.Setenv("AWS_SECRET_ACCESS_KEY", "")

	r := h.expectCode(ExitUsage, "org", "export", "--to", "s3://backups/runr", "--s3-endpoint", "https://s3.example.com")
	contains(t, r.err, "AWS_ACCESS_KEY_ID")

	t.Setenv("AWS_ACCESS_KEY_ID", "AKIDEXAMPLE")
	t.Setenv("AWS_SECRET_ACCESS_KEY", "s3cr3t-value")
	t.Setenv("AWS_REGION", "eu-central-1")
	r = h.mustRun("org", "export", "--to", "s3://backups/runr", "--s3-endpoint", "https://s3.example.com", "--s3-path-style", "--no-wait")
	d, _ := h.srv.CallsTo("POST /org-transfers/export")[0].Body["destination"].(map[string]any)
	if d["type"] != "s3" || d["bucket"] != "backups" || d["prefix"] != "runr" || d["region"] != "eu-central-1" ||
		d["access_key_id"] != "AKIDEXAMPLE" || d["path_style"] != true {
		t.Fatalf("destination %v", d)
	}
	notContains(t, r.out+r.err, "s3cr3t-value")
}

func TestOrgExportFailureExitsWithFailed(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().ExportError = "pg_dump of db failed"

	r := h.expectCode(ExitFailed, "org", "export", "-f", filepath.Join(t.TempDir(), "x.tar.zst"))
	contains(t, r.err, "pg_dump of db failed")
}

func TestOrgImportUploadsPlansAndApplies(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().VerifyPolls = 1
	h.srv.OrgTransfers().ApplyPolls = 1
	file := writeFile(t, "acme.tar.zst", "0123456789")

	r := h.mustRun("org", "import", file, "--rename", "api=api-copy", "--deploy", "--yes")

	start := h.srv.CallsTo("POST /org-transfers/import")
	sum := sha256.Sum256([]byte("0123456789"))
	if len(start) != 1 || start[0].Body["sha256"] != hex.EncodeToString(sum[:]) || start[0].Body["size"] != float64(10) {
		t.Fatalf("start %+v", start)
	}
	var offsets []string
	for _, c := range h.srv.CallsTo("PUT /org-transfers/{transfer}/chunk") {
		offsets = append(offsets, strings.TrimPrefix(c.Query, "offset="))
	}
	if strings.Join(offsets, ",") != "0,4,8" {
		t.Fatalf("chunk offsets %v", offsets)
	}
	plans := h.srv.CallsTo("POST /org-transfers/{transfer}/plan")
	if len(plans) != 1 {
		t.Fatalf("%d plans", len(plans))
	}
	if rn, _ := plans[0].Body["renames"].(map[string]any); rn["src-p1"] != "api-copy" {
		t.Fatalf("renames %v", plans[0].Body["renames"])
	}
	apply := h.srv.CallsTo("POST /org-transfers/{transfer}/apply")
	if len(apply) != 1 || apply[0].Body["deploy"] != true || apply[0].Body["restore_data"] != true || apply[0].Body["images"] != true {
		t.Fatalf("apply %+v", apply)
	}
	contains(t, r.out, "api-copy")
	contains(t, r.out, "Result: 5 created")
	contains(t, r.out, "TO DO: Point these domains")
}

func TestOrgImportFromABindingIsReadByTheAPIAndApplied(t *testing.T) {
	h := newHarness(t).login()
	store := h.srv.SeedBinding("backups", "object", "eu-1", nil, nil)

	r := h.mustRun("org", "import", "--from", "binding:backups", "--key", "dr/acme.tar.zst", "--no-history", "--skip-data", "cache", "--yes")

	start := h.srv.CallsTo("POST /org-transfers/import")
	src, _ := start[0].Body["source"].(map[string]any)
	if len(start) != 1 || src["type"] != "binding" || src["binding_id"] != store.ID || src["key"] != "dr/acme.tar.zst" {
		t.Fatalf("start %+v", start)
	}
	if n := len(h.srv.CallsTo("PUT /org-transfers/{transfer}/chunk")); n != 0 {
		t.Fatalf("%d chunks uploaded, want none", n)
	}
	plan := h.srv.CallsTo("POST /org-transfers/{transfer}/plan")
	if len(plan) != 1 || plan[0].Body["history"] != false {
		t.Fatalf("plan %+v", plan)
	}
	if ids, _ := plan[0].Body["skip_binding_data"].([]any); len(ids) != 1 || ids[0] != "src-b1" {
		t.Fatalf("skip_binding_data %v", plan[0].Body["skip_binding_data"])
	}
	contains(t, r.out, "Result: 5 created")

	h.expectCode(ExitUsage, "org", "import", "--from", "binding:backups")
	h.expectCode(ExitUsage, "org", "import", "--from", "binding:backups", "--key", "x", writeFile(t, "x.tar.zst", "x"))
	h.expectCode(ExitUsage, "org", "import", "--from", "ftp://x/y")
}

func TestOrgImportFromS3TakesCredentialsFromTheEnvironment(t *testing.T) {
	h := newHarness(t).login()
	t.Setenv("AWS_ACCESS_KEY_ID", "")
	t.Setenv("AWS_SECRET_ACCESS_KEY", "")
	r := h.expectCode(ExitUsage, "org", "import", "--from", "s3://backups/dr/acme.tar.zst", "--s3-endpoint", "https://s3.example.com")
	contains(t, r.err, "AWS_ACCESS_KEY_ID")

	t.Setenv("AWS_ACCESS_KEY_ID", "AKIDEXAMPLE")
	t.Setenv("AWS_SECRET_ACCESS_KEY", "s3cr3t-value")
	r = h.mustRun("org", "import", "--from", "s3://backups/dr", "--key", "acme.tar.zst", "--s3-endpoint", "https://s3.example.com", "--s3-path-style", "--plan-only")
	src, _ := h.srv.CallsTo("POST /org-transfers/import")[0].Body["source"].(map[string]any)
	if src["type"] != "s3" || src["bucket"] != "backups" || src["key"] != "dr/acme.tar.zst" || src["access_key_id"] != "AKIDEXAMPLE" || src["path_style"] != true || src["region"] != "us-east-1" {
		t.Fatalf("source %v", src)
	}
	notContains(t, r.out+r.err, "s3cr3t-value")
	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/apply")); n != 0 {
		t.Fatalf("plan-only applied %d times", n)
	}
}

func TestOrgImportResumesAFailedRead(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedBinding("backups", "object", "eu-1", nil, nil)
	h.srv.OrgTransfers().FetchError = "the object could not be read from the bucket"

	r := h.expectCode(ExitFailed, "org", "import", "--from", "binding:backups", "--key", "acme.tar.zst", "--plan-only")
	contains(t, r.err, "could not be read")
	last, ok := h.srv.LastOrgTransfer()
	if !ok || last.Status != runr.OrgTransferFailed {
		t.Fatalf("last transfer %+v", last)
	}

	h.mustRun("org", "import", "--resume", last.ID, "--plan-only")
	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/fetch")); n != 1 {
		t.Fatalf("%d resumes", n)
	}
}

func TestOrgTransferHistoryListsTheKeptRecords(t *testing.T) {
	h := newHarness(t).login()
	tr := h.srv.SeedOrgTransfer("import", runr.OrgTransferCompleted)

	r := h.mustRun("org", "transfer", "history", tr.ID, "--kind", "bill")
	contains(t, r.out, "billing_bills")
	notContains(t, r.out, "usage_records")
	contains(t, r.out, "1 of 1 record(s)")
	h.expectCode(ExitUsage, "org", "transfer", "history", tr.ID, "--kind", "money")
}

func TestOrgExportHistoryOption(t *testing.T) {
	h := newHarness(t).login()
	h.mustRun("org", "export", "--history", "--no-wait")
	if b := h.srv.CallsTo("POST /org-transfers/export")[0].Body; b["include_history"] != true {
		t.Fatalf("export body %v", b)
	}
}

func TestOrgImportResumesAnOpenUpload(t *testing.T) {
	h := newHarness(t).login()
	content := "0123456789"
	sum := sha256.Sum256([]byte(content))
	open := h.srv.SeedOrgImportUpload("acme.tar.zst", 10, hex.EncodeToString(sum[:]), []byte("0123"))
	file := writeFile(t, "acme.tar.zst", content)

	h.mustRun("org", "import", file, "--plan-only")

	if n := len(h.srv.CallsTo("POST /org-transfers/import")); n != 0 {
		t.Fatalf("%d new imports, want the resume", n)
	}
	chunks := h.srv.CallsTo("PUT /org-transfers/{transfer}/chunk")
	if len(chunks) != 2 || chunks[0].Query != "offset=4" {
		t.Fatalf("chunks %+v", chunks)
	}
	if got := string(h.srv.ReceivedOrgImport(open.ID)); got != content {
		t.Fatalf("received %q", got)
	}
}

func TestOrgImportPlanOnlyAppliesNothing(t *testing.T) {
	h := newHarness(t).login()
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	r := h.mustRun("org", "import", file, "--plan-only", "-o", "json")

	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/apply")); n != 0 {
		t.Fatalf("%d applies", n)
	}
	var tr runr.OrgTransfer
	if err := json.Unmarshal([]byte(r.out), &tr); err != nil {
		t.Fatalf("output %q: %v", r.out, err)
	}
	if tr.Status != runr.OrgTransferPlanned || tr.Plan == nil || len(tr.Plan.Projects) != 1 {
		t.Fatalf("transfer %+v", tr)
	}
}

func TestOrgImportWithABlockingPlanExitsWithValidation(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().UnmappedRegion = true
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	r := h.expectCode(ExitValidation, "org", "import", file, "--yes")
	contains(t, r.out, "is not mapped to a region here")
	contains(t, r.err, "blocking")
	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/apply")); n != 0 {
		t.Fatalf("%d applies of a blocked plan", n)
	}
}

func TestOrgImportRegionMapUnblocksThePlan(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().UnmappedRegion = true
	fra := h.srv.AddRegion("fra-1", true)
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	h.mustRun("org", "import", file, "--region-map", "eu-1=fra-1", "--yes")

	plan := h.srv.CallsTo("POST /org-transfers/{transfer}/plan")[0].Body
	if m, _ := plan["region_map"].(map[string]any); m["src-r1"] != fra.ID {
		t.Fatalf("region_map %v", plan["region_map"])
	}
	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/apply")); n != 1 {
		t.Fatalf("%d applies", n)
	}

	r := h.expectCode(ExitUsage, "org", "import", file, "--region-map", "mars=fra-1", "--yes")
	contains(t, r.err, "no region \"mars\"")
}

func TestOrgImportPassphraseComesFromTheEnvironment(t *testing.T) {
	h := newHarness(t).login()
	t.Setenv(EnvImportPassphrase, "correct horse battery staple")
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	r := h.mustRun("org", "import", file, "--plan-only")

	plan := h.srv.CallsTo("POST /org-transfers/{transfer}/plan")
	if len(plan) != 1 || plan[0].Body["passphrase"] != "correct horse battery staple" {
		t.Fatalf("plan calls %+v", plan)
	}
	notContains(t, r.out+r.err, "correct horse")
}

func TestOrgImportFailureExitsWithFailed(t *testing.T) {
	h := newHarness(t).login()
	h.srv.OrgTransfers().ApplyError = "push of project api failed"
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	r := h.expectCode(ExitFailed, "org", "import", file, "--yes")
	contains(t, r.err, "push of project api failed")
}

func TestOrgImportNeedsConfirmation(t *testing.T) {
	h := newHarness(t).login()
	file := writeFile(t, "acme.tar.zst", "abcdefgh")

	h.expectCode(ExitUsage, "org", "import", file)
	if n := len(h.srv.CallsTo("POST /org-transfers/{transfer}/apply")); n != 0 {
		t.Fatalf("%d applies without confirmation", n)
	}
}

func TestOrgTransfersListShowAndCancel(t *testing.T) {
	h := newHarness(t).login()
	done := h.srv.SeedOrgTransfer("export", runr.OrgTransferCompleted)
	running := h.srv.SeedOrgTransfer("export", runr.OrgTransferRunning)

	r := h.mustRun("org", "transfers")
	contains(t, r.out, done.ID)
	contains(t, r.out, running.ID)

	r = h.mustRun("org", "transfer", done.ID)
	contains(t, r.out, "runr-org-acme-20261021-120000.tar.zst")

	h.expectCode(ExitNotFound, "org", "transfer", "01JUNKNOWN")

	h.expectCode(ExitUsage, "org", "transfer", "cancel", running.ID)
	h.mustRun("org", "transfer", "cancel", running.ID, "--yes")
	if tr, _ := h.srv.OrgTransfer(running.ID); tr.Status != runr.OrgTransferCancelled {
		t.Fatalf("status %s", tr.Status)
	}
}
