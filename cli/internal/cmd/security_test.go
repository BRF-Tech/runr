package cmd

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func securityFindings() []runr.SecurityFinding {
	return []runr.SecurityFinding{
		{ID: "CVE-2026-0001", Severity: "CRITICAL", Package: "openssl", Installed: "3.0.1", Fixed: "3.0.9"},
		{ID: "CVE-2026-0002", Severity: "HIGH", Package: "zlib", Installed: "1.2.11"},
	}
}

func TestSecurityOverview(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecurityReport("hello-api", 1, runr.SeverityCounts{High: 2, Low: 5})

	r := h.mustRun("security", "--dir", dir)
	contains(t, r.out, "Deploy policy:")
	contains(t, r.out, "VERSION")
	contains(t, r.out, "v1")
	contains(t, r.out, "signed")

	var ov runr.SecurityOverview
	decodeJSON(t, h.mustRun("security", "--dir", dir, "-o", "json").out, &ov)
	if len(ov.Versions) != 1 || ov.Versions[0].Report == nil || ov.Versions[0].Report.Counts.High != 2 || ov.Region == nil || ov.Region.Name != "eu-1" {
		t.Fatalf("overview = %+v", ov)
	}
	if len(ov.Deployments) != 1 || ov.Deployments[0].VersionLabel != "v1" {
		t.Fatalf("deployments = %+v", ov.Deployments)
	}

	// The positional project replaces runr.yaml.
	r = h.mustRun("security", "hello-api")
	contains(t, r.out, "hello-api")
}

func TestSecurityVersionAndFailOn(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecurityReport("hello-api", 1, runr.SeverityCounts{Critical: 1, High: 1}, securityFindings()...)

	r := h.mustRun("security", "--version", "v1", "--dir", dir)
	contains(t, r.out, "CVE-2026-0001")
	contains(t, r.out, "openssl")
	contains(t, r.out, "1 critical, 1 high")
	contains(t, r.out, "allowed with 1 warning")

	var sec runr.VersionSecurity
	decodeJSON(t, h.mustRun("security", "--version", "latest", "--dir", dir, "-o", "json").out, &sec)
	if sec.Report == nil || len(sec.Report.Findings) != 2 || sec.Decision == nil || sec.Decision.Blocked {
		t.Fatalf("version security = %+v", sec)
	}

	r = h.expectCode(ExitFailed, "security", "--version", "v1", "--fail-on", "critical", "--dir", dir)
	contains(t, r.err, "1 critical")
	h.expectCode(ExitFailed, "security", "--live", "--fail-on", "high", "--dir", dir)
	h.mustRun("security", "--version", "v1", "--fail-on", "policy", "--dir", dir) // warn mode

	h.srv.SetSecurityGate("enforce")
	r = h.expectCode(ExitFailed, "security", "--version", "v1", "--fail-on", "policy", "--dir", dir)
	contains(t, r.err, "blocks this version")
	contains(t, r.out, "blocked")
}

func TestSecurityLiveAndDeployment(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecurityReport("hello-api", 1, runr.SeverityCounts{Medium: 3})
	r := h.mustRun("security", "--live", "--dir", dir)
	contains(t, r.out, "3 medium")
	if len(h.srv.CallsTo("GET /projects/{project}/deployments/{deployment}/security")) != 1 {
		t.Fatal("--live did not read the deployment's report")
	}
	d := h.srv.Deployments("hello-api")[0]
	h.mustRun("security", "--deployment", d.ID, "--dir", dir)
	h.expectCode(ExitUsage, "security", "--live", "--version", "v1", "--dir", dir)
}

func TestSecurityScanWait(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("security", "--version", "v1", "--dir", dir)
	contains(t, r.out, "queue a scan")

	r = h.mustRun("security", "--version", "v1", "--scan", "--dir", dir)
	contains(t, r.err, "Scan of v1 queued")
	if len(h.srv.CallsTo("POST /projects/{project}/versions/{version}/security/scan")) != 1 {
		t.Fatal("no scan requested")
	}
	// A second scan while one runs: 409, exit 5.
	h.expectCode(ExitValidation, "security", "--version", "v1", "--scan", "--dir", dir)

	h2 := newHarness(t).login()
	dir2 := h2.deployHello()
	r = h2.mustRun("security", "--version", "v1", "--scan", "--wait", "--dir", dir2, "-o", "json")
	var sec runr.VersionSecurity
	decodeJSON(t, r.out, &sec)
	if sec.Report == nil || sec.Report.Status != "ok" {
		t.Fatalf("after --wait: %+v", sec.Report)
	}
	h2.expectCode(ExitUsage, "security", "--wait", "--dir", dir2)
}

func TestSecuritySBOM(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecurityReport("hello-api", 1, runr.SeverityCounts{})

	file := filepath.Join(t.TempDir(), "sbom.spdx.json")
	r := h.mustRun("security", "--version", "v1", "--sbom", file, "--dir", dir)
	contains(t, r.err, "written to "+file)
	data, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	contains(t, string(data), "SPDX-2.3")

	r = h.mustRun("security", "--version", "v1", "--sbom", "-", "--sbom-format", "cyclonedx-json", "--dir", dir)
	contains(t, r.out, "CycloneDX")
	notContains(t, r.out, "Vulnerabilities") // stdout holds the document only
	if q := h.srv.CallsTo("GET /projects/{project}/versions/{version}/sbom")[1].Query; q != "format=cyclonedx-json" {
		t.Fatalf("sbom query = %q", q)
	}
	h.expectCode(ExitUsage, "security", "--version", "v1", "--sbom", "-", "--sbom-format", "xml", "--dir", dir)

	h.expectCode(ExitNotFound, "security", "--version", "v9", "--sbom", file, "--dir", dir)
}

func TestSecurityUsageAndPermissions(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.expectCode(ExitUsage, "security", "--fail-on", "severe", "--dir", dir)
	r := h.expectCode(ExitFailed, "security", "--version", "v1", "--fail-on", "high", "--dir", dir)
	contains(t, r.err, "no supply-chain report")

	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	r = h.expectCode(ExitAuth, "security", "--dir", dir)
	contains(t, r.err, "security.view")
}

func TestResidency(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", true)
	r := h.mustRun("residency")
	contains(t, r.out, "every region")
	contains(t, r.out, "us-1")

	h.srv.RestrictResidency("eu-1")
	var res runr.Residency
	decodeJSON(t, h.mustRun("residency", "-o", "json").out, &res)
	if !res.Restricted || len(res.AllowedRegionIDs) != 1 || len(res.Regions) != 2 {
		t.Fatalf("residency = %+v", res)
	}
	for _, reg := range res.Regions {
		if reg.Allowed != (reg.Name == "eu-1") {
			t.Fatalf("region %s allowed = %v", reg.Name, reg.Allowed)
		}
	}
	r = h.mustRun("residency")
	contains(t, r.out, "restricted to the allowed regions")
}
