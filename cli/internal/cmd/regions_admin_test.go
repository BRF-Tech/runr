package cmd

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestRegionTracingCommands(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "tracing", "eu-1")
	contains(t, r.out, "Mode:")
	contains(t, r.out, "managed")

	r = h.mustRun("regions", "tracing", "set", "eu-1", "--retention-hours", "168", "--sample-rate", "0.5")
	contains(t, r.out, "168h")
	put := h.srv.CallsTo("PUT /observability/regions/{knativeRegion}/tracing")
	if len(put) != 1 || put[0].Body["mode"] != "managed" || put[0].Body["retention_hours"] != float64(168) ||
		put[0].Body["knative_sample_rate"] != 0.5 {
		t.Fatalf("PUT = %+v", put)
	}
	if _, sent := put[0].Body["install"]; sent {
		t.Fatalf("install sent without --install: %v", put[0].Body)
	}

	// Mode external with headers from a file; --install --wait waits for the install.
	dir := t.TempDir()
	headers := filepath.Join(dir, "headers.txt")
	write(t, headers, "# OTLP authentication\nAuthorization: Bearer abc123\n")
	r = h.mustRun("regions", "tracing", "set", "eu-1", "--mode", "external", "--export-endpoint", "https://otlp.example.com",
		"--export-headers", headers, "--install", "--wait", "-o", "json")
	var tr runr.RegionTracing
	decodeJSON(t, r.out, &tr)
	if tr.Mode != runr.TracingExternal || tr.Status != runr.RegionServiceReady || len(tr.ExportHeaderNames) != 1 {
		t.Fatalf("tracing = %+v", tr)
	}
	if got := h.srv.RegionTracingHeaders("eu-1"); got["Authorization"] != "Bearer abc123" {
		t.Fatalf("headers = %v", got)
	}
	notContains(t, r.out, "abc123")

	// Headers from stdin.
	h.runStdin(`{"X-Api-Key": "k-1"}`, "regions", "tracing", "set", "eu-1", "--export-headers", "-")
	if got := h.srv.RegionTracingHeaders("eu-1"); got["X-Api-Key"] != "k-1" {
		t.Fatalf("headers from stdin = %v", got)
	}

	// Usage errors.
	h.expectCode(ExitUsage, "regions", "tracing", "set", "eu-1", "--mode", "reuse")
	h.expectCode(ExitUsage, "regions", "tracing", "set", "eu-1", "--mode", "sideways")
	h.expectCode(ExitUsage, "regions", "tracing", "set", "eu-1", "--mode", "managed", "--export-endpoint", "https://x.example.com")
	h.expectCode(ExitUsage, "regions", "tracing", "set", "eu-1", "--sample-rate", "2")

	// Removing needs --yes outside a terminal.
	h.expectCode(ExitUsage, "regions", "tracing", "rm", "eu-1")
	h.mustRun("regions", "tracing", "rm", "eu-1", "--yes")
	if n := len(h.srv.CallsTo("DELETE /observability/regions/{knativeRegion}/tracing")); n != 1 {
		t.Fatalf("delete calls = %d", n)
	}
	h.expectCode(ExitNotFound, "regions", "tracing", "mars-1")
}

func TestRegionTracingInstallFailureExitCode(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RegionAdmin().FailInstall = true
	r := h.expectCode(ExitFailed, "regions", "tracing", "install", "eu-1", "--wait")
	contains(t, r.err, "failed")

	h.srv.RegionAdmin().Disconnected = []string{"eu-1"}
	h.expectCode(ExitValidation, "regions", "tracing", "install", "eu-1")
}

func TestRegionDataServicesCommands(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "data-services", "eu-1")
	contains(t, r.out, "SERVICE")
	contains(t, r.out, "not_installed")

	r = h.mustRun("regions", "data-services", "install", "eu-1", "redis", "--maxmemory-mb", "512", "--wait")
	contains(t, r.out, "ready")
	post := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/data-services/{service}")
	if len(post) != 1 || post[0].Path[len(post[0].Path)-3:] != "/kv" || post[0].Body["maxmemory_mb"] != float64(512) {
		t.Fatalf("install = %+v", post)
	}

	h.mustRun("regions", "data-services", "install", "eu-1", "postgres", "--instances", "3", "--storage", "50Gi", "--backups")
	post = h.srv.CallsTo("POST /knative-regions/{knativeRegion}/data-services/{service}")
	b := post[len(post)-1].Body
	if b["instances"] != float64(3) || b["storage"] != "50Gi" || b["backups"] != true || len(b) != 3 {
		t.Fatalf("postgres body = %v", b)
	}

	r = h.mustRun("regions", "data-services", "ls", "eu-1", "--live", "-o", "json")
	var all runr.RegionDataServices
	decodeJSON(t, r.out, &all)
	if kv := all.Service(runr.DataServiceKV); kv == nil || kv.Status != runr.RegionServiceReady || kv.Health == nil {
		t.Fatalf("kv = %+v", kv)
	}

	// Settings of another service and malformed values are refused.
	h.expectCode(ExitUsage, "regions", "data-services", "install", "eu-1", "kv", "--instances", "2")
	h.expectCode(ExitUsage, "regions", "data-services", "install", "eu-1", "postgres", "--credentials", "-")
	h.expectCode(ExitUsage, "regions", "data-services", "install", "eu-1", "kv", "--storage", "lots")
	h.expectCode(ExitUsage, "regions", "data-services", "install", "eu-1", "mongo")

	// Uninstall needs --yes; a service that bindings use is refused.
	h.expectCode(ExitUsage, "regions", "data-services", "uninstall", "eu-1", "kv")
	h.mustRun("regions", "data-services", "uninstall", "eu-1", "kv", "--yes", "--wait")
	h.srv.SeedDataService("eu-1", runr.DataServiceObject, "managed", 1)
	h.expectCode(ExitValidation, "regions", "data-services", "uninstall", "eu-1", "object", "--yes")
}

func TestRegionDataServiceExternalCredentials(t *testing.T) {
	h := newHarness(t).login()
	creds := filepath.Join(t.TempDir(), "s3.env")
	write(t, creds, "ACCESS_KEY_ID=AKIAEXAMPLE\nSECRET_ACCESS_KEY=\"s3-secret\"\n")
	r := h.mustRun("regions", "data-services", "install", "eu-1", "s3", "--mode", "external", "--provider", "s3",
		"--endpoint", "https://s3.example.com", "--s3-region", "eu-west-1", "--path-style", "--credentials", creds, "--wait")
	notContains(t, r.out, "s3-secret")
	opts, secrets := h.srv.DataServiceOptions("eu-1", runr.DataServiceObject)
	if secrets["access_key_id"] != "AKIAEXAMPLE" || secrets["secret_access_key"] != "s3-secret" {
		t.Fatalf("secrets = %v", secrets)
	}
	if opts["region"] != "eu-west-1" || opts["path_style"] != true || opts["provider"] != "s3" {
		t.Fatalf("options = %v", opts)
	}

	// From stdin, and an unknown key is refused.
	h.runStdin("ADMIN_TOKEN=garage-admin\n", "regions", "data-services", "install", "eu-1", "object", "--mode", "external",
		"--provider", "garage", "--endpoint", "https://s3.garage.example.com", "--admin-endpoint", "https://admin.garage.example.com",
		"--credentials", "-")
	if _, s := h.srv.DataServiceOptions("eu-1", runr.DataServiceObject); s["admin_token"] != "garage-admin" {
		t.Fatalf("admin token = %v", s)
	}
	bad := filepath.Join(t.TempDir(), "bad.env")
	if err := os.WriteFile(bad, []byte("PASSWORD=x\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	h.expectCode(ExitUsage, "regions", "data-services", "install", "eu-1", "object", "--credentials", bad)
}

func TestRegionDataServicesDisconnectedRegion(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RegionAdmin().Disconnected = []string{"eu-1"}
	r := h.mustRun("regions", "data-services", "eu-1")
	contains(t, r.out, "no cluster connection")
	h.expectCode(ExitValidation, "regions", "data-services", "install", "eu-1", "kv")
}
