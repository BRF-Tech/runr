package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestImagesGet(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("tr-1", true)
	web := h.srv.SeedImage("web:1.0", "eu-1")
	h.srv.SeedImage("web:1.0", "tr-1")
	h.srv.SeedImage("tools", "eu-1")

	r := h.mustRun("images", "get", "web:1.0", "--region", "eu-1")
	contains(t, r.out, "Image ref:")
	contains(t, r.out, web.ImageRef)
	contains(t, r.out, "Layers:")
	contains(t, r.out, "linux/amd64")
	if calls := h.srv.CallsTo("GET /images/{image}"); len(calls) != 1 || calls[0].Path != "/images/"+web.ID {
		t.Fatalf("calls = %+v", calls)
	}

	var img runr.UploadedImage
	decodeJSON(t, h.mustRun("images", "get", web.ID, "-o", "json").out, &img)
	if img.ID != web.ID || img.Layers != 3 || img.ConfigMediaType == "" {
		t.Fatalf("json = %+v", img)
	}
	// A name without a tag is :latest.
	contains(t, h.mustRun("images", "get", "tools").out, "tools:latest")

	r = h.run("images", "get", "web:1.0")
	if r.code == 0 {
		t.Fatal("a name in two regions without --region")
	}
	contains(t, r.err, "several regions")
	h.expectCode(ExitNotFound, "images", "get", "nope:1")
}

func TestImagesUploads(t *testing.T) {
	h := newHarness(t).login()
	contains(t, h.mustRun("images", "uploads").out, "No unfinished uploads.")

	up := h.srv.SeedUpload("web:2.0", "eu-1", "web.tar", 4096, strings.Repeat("ab", 32), []byte("0123"))
	r := h.mustRun("images", "uploads", "ls")
	contains(t, r.out, up.ID)
	contains(t, r.out, "web:2.0")
	contains(t, r.out, "eu-1")
	contains(t, r.out, "4 B / 4.0 KiB")

	h.expectCode(ExitUsage, "images", "uploads", "rm", up.ID)
	r = h.mustRun("images", "uploads", "rm", up.ID, "--yes", "-o", "json")
	contains(t, r.err, "Aborted upload "+up.ID+".")
	contains(t, r.out, up.ID)
	if n := len(h.srv.CallsTo("DELETE /images/uploads/{upload}")); n != 1 {
		t.Fatalf("%d aborts", n)
	}
	var list []runr.ImageUpload
	decodeJSON(t, h.mustRun("images", "uploads", "-o", "json").out, &list)
	if len(list) != 0 {
		t.Fatalf("uploads after the abort = %+v", list)
	}
	h.expectCode(ExitNotFound, "images", "uploads", "rm", "01jnosuchupload00000000000", "--yes")
}

func TestModelServiceSecurity(t *testing.T) {
	h := modelHarness(t)

	r := h.mustRun("models", "services", "security", "minilm")
	contains(t, r.out, "Report:")
	contains(t, r.out, "none yet")

	h.srv.SeedModelServiceReport("minilm", runr.SeverityCounts{Critical: 1, High: 2, Medium: 3},
		runr.SecurityFinding{ID: "CVE-2026-0001", Severity: "CRITICAL", Package: "openssl", Installed: "3.0.1", Fixed: "3.0.15"})
	r = h.mustRun("models", "services", "security", "minilm")
	contains(t, r.out, "ghcr.io/runrsh/runr/model-")
	contains(t, r.out, "1 critical, 2 high, 3 medium")
	contains(t, r.out, "CVE-2026-0001")

	var sec runr.ModelServiceSecurity
	decodeJSON(t, h.mustRun("models", "services", "security", "minilm", "-o", "json").out, &sec)
	if !sec.Scanned || !sec.Current || sec.Report == nil || sec.Report.Counts.Critical != 1 || len(sec.Report.Findings) != 1 {
		t.Fatalf("json = %+v", sec)
	}

	r = h.expectCode(ExitFailed, "models", "services", "security", "minilm", "--fail-on", "high")
	contains(t, r.err, "1 critical, 2 high")
	// -o json prints the report and still exits 6.
	r = h.expectCode(ExitFailed, "models", "services", "security", "minilm", "--fail-on", "low", "-o", "json")
	contains(t, r.out, "CVE-2026-0001")

	file := filepath.Join(t.TempDir(), "minilm.spdx.json")
	r = h.mustRun("models", "services", "security", "minilm", "--sbom", file)
	contains(t, r.err, "SBOM of minilm")
	if data, err := os.ReadFile(file); err != nil || !strings.Contains(string(data), "SPDX-2.3") {
		t.Fatalf("sbom = %q, %v", data, err)
	}
	r = h.mustRun("models", "services", "security", "minilm", "--sbom", "-", "--sbom-format", runr.SBOMCycloneDX)
	contains(t, r.out, "CycloneDX")

	h.expectCode(ExitUsage, "models", "services", "security", "minilm", "--fail-on", "severe")
	h.expectCode(ExitUsage, "models", "services", "security", "minilm", "--sbom-format", "xml")
	h.expectCode(ExitNotFound, "models", "services", "security", "nope")
}
