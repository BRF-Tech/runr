package runr_test

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestOrgExportStartsWaitsAndDownloads(t *testing.T) {
	srv := runrtest.New(t)
	srv.OrgTransfers().ExportPolls = 2
	c := client(t, srv)
	ctx := t.Context()

	tr, err := c.StartOrgExport(ctx, runr.OrgExportRequest{
		OrgExportOptions: runr.OrgExportOptions{IncludeMembers: true, BindingData: "all"},
		Destination:      &runr.OrgExportDestination{Type: "download"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if tr.Status != runr.OrgTransferQueued || tr.Kind != "export" {
		t.Fatalf("started %+v", tr)
	}
	var polls int
	tr, err = c.WaitOrgTransfer(ctx, tr.ID, []string{runr.OrgTransferCompleted}, runr.OrgWaitOptions{
		Interval: time.Millisecond, Progress: func(*runr.OrgTransfer) { polls++ },
	})
	if err != nil {
		t.Fatal(err)
	}
	if polls < 2 || tr.Package == nil || !tr.Package.Downloadable || tr.Package.SchemaVersion != 1 {
		t.Fatalf("after %d polls: %+v", polls, tr)
	}

	var buf bytes.Buffer
	d, err := c.DownloadOrgExport(ctx, tr.ID, &buf, 0, nil)
	if err != nil {
		t.Fatal(err)
	}
	if buf.String() != string(runrtest.DefaultOrgPackage) || d.Filename != tr.Package.Filename || d.StatusCode != 200 {
		t.Fatalf("downloaded %q as %q (%d)", buf.String(), d.Filename, d.StatusCode)
	}
	sum := sha256.Sum256(buf.Bytes())
	if hex.EncodeToString(sum[:]) != tr.Package.SHA256 {
		t.Fatal("the package does not match its SHA-256")
	}
}

func TestOrgExportDownloadResumesWithARange(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	tr := srv.SeedOrgTransfer("export", runr.OrgTransferCompleted)

	var buf bytes.Buffer
	restarted := false
	d, err := c.DownloadOrgExport(t.Context(), tr.ID, &buf, 10, func() error { restarted = true; return nil })
	if err != nil {
		t.Fatal(err)
	}
	if d.StatusCode != 206 || restarted || buf.String() != string(runrtest.DefaultOrgPackage[10:]) {
		t.Fatalf("status %d, restarted %v, got %q", d.StatusCode, restarted, buf.String())
	}
	if calls := srv.CallsTo("GET /org-transfers/{transfer}/download"); len(calls) != 1 {
		t.Fatalf("%d downloads", len(calls))
	}
}

func TestOrgExportDownloadOfAnUnfinishedExportIsRefused(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	tr := srv.SeedOrgTransfer("export", runr.OrgTransferRunning)

	_, err := c.DownloadOrgExport(t.Context(), tr.ID, &bytes.Buffer{}, 0, nil)
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 || ae.Code != "not_downloadable" {
		t.Fatalf("err %v", err)
	}
}

func TestWaitOrgTransferReportsAFailure(t *testing.T) {
	srv := runrtest.New(t)
	srv.OrgTransfers().ExportError = "the region did not answer"
	c := client(t, srv)
	tr, err := c.StartOrgExport(t.Context(), runr.OrgExportRequest{})
	if err != nil {
		t.Fatal(err)
	}

	_, err = c.WaitOrgTransfer(t.Context(), tr.ID, []string{runr.OrgTransferCompleted}, runr.OrgWaitOptions{Interval: time.Millisecond})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.OrgTransferFailed || fe.Message != "the region did not answer" {
		t.Fatalf("err %v", err)
	}
}

func TestImportPackageUploadsInChunksAndWaitsForThePlan(t *testing.T) {
	srv := runrtest.New(t)
	srv.OrgTransfers().VerifyPolls = 2
	c := client(t, srv)
	file := filepath.Join(t.TempDir(), "acme.tar.zst")
	if err := os.WriteFile(file, []byte("0123456789"), 0o600); err != nil {
		t.Fatal(err)
	}

	var phases []string
	tr, err := c.ImportPackage(t.Context(), file, runr.ImportPackageOptions{
		PollInterval: time.Millisecond,
		Progress: func(phase string, _, _ int64) {
			if len(phases) == 0 || phases[len(phases)-1] != phase {
				phases = append(phases, phase)
			}
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if tr.Status != runr.OrgTransferPlanned || tr.Plan == nil || tr.Plan.Projects[0].SourceID != "src-p1" {
		t.Fatalf("transfer %+v", tr)
	}
	if got := string(srv.ReceivedOrgImport(tr.ID)); got != "0123456789" {
		t.Fatalf("received %q", got)
	}
	if len(srv.CallsTo("PUT /org-transfers/{transfer}/chunk")) != 3 {
		t.Fatal("want 3 chunks of 4 bytes")
	}
	if phases[0] != "uploading" || phases[len(phases)-1] != runr.OrgTransferPlanned {
		t.Fatalf("phases %v", phases)
	}
}

func TestImportPackageResumesBySHA256(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	content := []byte("abcdefghij")
	sum := sha256.Sum256(content)
	open := srv.SeedOrgImportUpload("other-name.tar.zst", 10, hex.EncodeToString(sum[:]), content[:8])
	file := filepath.Join(t.TempDir(), "acme.tar.zst")
	if err := os.WriteFile(file, content, 0o600); err != nil {
		t.Fatal(err)
	}

	tr, err := c.ImportPackage(t.Context(), file, runr.ImportPackageOptions{PollInterval: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	if tr.ID != open.ID || len(srv.CallsTo("POST /org-transfers/import")) != 0 {
		t.Fatalf("a new import was started instead of resuming %s", open.ID)
	}
	chunks := srv.CallsTo("PUT /org-transfers/{transfer}/chunk")
	if len(chunks) != 1 || chunks[0].Query != "offset=8" {
		t.Fatalf("chunks %+v", chunks)
	}
}

func TestApplyOrgImportOfABlockedPlan(t *testing.T) {
	srv := runrtest.New(t)
	srv.OrgTransfers().UnmappedRegion = true
	c := client(t, srv)
	tr := srv.SeedOrgTransfer("import", runr.OrgTransferPlanned)

	_, err := c.ApplyOrgImport(t.Context(), tr.ID, runr.OrgImportApply{RestoreData: true})
	var pbe *runr.PlanBlockedError
	if !errors.As(err, &pbe) {
		t.Fatalf("err %v, want PlanBlockedError", err)
	}

	planned, err := c.PlanOrgImport(t.Context(), tr.ID, runr.OrgImportChoices{RegionMap: map[string]string{"src-r1": srv.AddRegion("fra-1", true).ID}})
	if err != nil {
		t.Fatal(err)
	}
	if planned.Plan.Blocking != 0 || !planned.Plan.CanApply || planned.Plan.Regions[0].TargetName != "fra-1" {
		t.Fatalf("plan %+v", planned.Plan)
	}
	applied, err := c.ApplyOrgImport(t.Context(), tr.ID, runr.OrgImportApply{OrgImportChoices: runr.OrgImportChoices{
		RegionMap: map[string]string{"src-r1": planned.Plan.Regions[0].TargetID},
	}})
	if err != nil || applied.Status != runr.OrgTransferApplying {
		t.Fatalf("apply %+v %v", applied, err)
	}
}

func TestEstimateAndCancelOrgTransfers(t *testing.T) {
	srv := runrtest.New(t)
	srv.SeedProject("api", "faas", "python", "eu-1", nil)
	c := client(t, srv)

	est, err := c.EstimateOrgExport(t.Context(), runr.OrgExportOptions{IncludeImages: true})
	if err != nil {
		t.Fatal(err)
	}
	if est.Projects != 1 || est.Images.Count != 1 || est.Images.Bytes != nil || len(est.Warnings) == 0 {
		t.Fatalf("estimate %+v", est)
	}

	tr := srv.SeedOrgTransfer("export", runr.OrgTransferRunning)
	if err := c.CancelOrgTransfer(t.Context(), tr.ID); err != nil {
		t.Fatal(err)
	}
	list, err := c.ListOrgTransfers(t.Context())
	if err != nil || len(list) != 1 || list[0].Status != runr.OrgTransferCancelled {
		t.Fatalf("list %+v %v", list, err)
	}
	if _, err := c.GetOrgTransfer(t.Context(), "01JNOPE"); !errors.As(err, new(*runr.NotFoundError)) {
		t.Fatalf("err %v", err)
	}
}

func TestImportFromSourceIsReadByTheAPIAndPlanned(t *testing.T) {
	srv := runrtest.New(t)
	srv.OrgTransfers().VerifyPolls = 1
	c := client(t, srv)

	var phases []string
	tr, err := c.ImportFromSource(t.Context(), runr.OrgImportSource{Type: "binding", BindingID: "b1", Key: "dr/acme.tar.zst"}, "", runr.ImportPackageOptions{
		PollInterval: time.Millisecond,
		Progress: func(phase string, _, _ int64) {
			if len(phases) == 0 || phases[len(phases)-1] != phase {
				phases = append(phases, phase)
			}
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if tr.Status != runr.OrgTransferPlanned || tr.Source == nil || tr.Source.Key != "dr/acme.tar.zst" {
		t.Fatalf("transfer %+v", tr)
	}
	if len(phases) == 0 || phases[len(phases)-1] != runr.OrgTransferPlanned {
		t.Fatalf("phases %v", phases)
	}
	if n := len(srv.CallsTo("PUT /org-transfers/{transfer}/chunk")); n != 0 {
		t.Fatalf("%d chunks", n)
	}

	// A read that failed resumes; an S3 source needs its credentials again
	srv.OrgTransfers().FetchError = "network"
	failed, err := c.StartOrgImportFrom(t.Context(), runr.OrgImportSource{Type: "s3", Endpoint: "https://s3.example.com", Bucket: "b", Key: "k.tar.zst", AccessKeyID: "a", SecretAccessKey: "s"}, "")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.WaitOrgTransfer(t.Context(), failed.ID, []string{runr.OrgTransferPlanned}, runr.OrgWaitOptions{Interval: time.Millisecond}); err == nil {
		t.Fatal("the read should fail")
	}
	if _, err := c.ResumeOrgImportFetch(t.Context(), failed.ID, "", ""); err == nil {
		t.Fatal("an S3 resume needs credentials")
	}
	resumed, err := c.ResumeImportFromSource(t.Context(), failed.ID, "a", "s", runr.ImportPackageOptions{PollInterval: time.Millisecond})
	if err != nil || resumed.Status != runr.OrgTransferPlanned {
		t.Fatalf("resumed %+v %v", resumed, err)
	}

	rows, total, err := c.ListOrgImportHistory(t.Context(), resumed.ID, "usage", 1, 50)
	if err != nil || total != 1 || rows[0].SourceTable != "usage_records" {
		t.Fatalf("history %+v %d %v", rows, total, err)
	}
}
