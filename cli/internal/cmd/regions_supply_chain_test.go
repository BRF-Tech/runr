package cmd

import (
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionSupplyChainGetSet(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "supply-chain", "eu-1")
	contains(t, r.out, "Policy gate:")
	contains(t, r.out, "off: max critical 0, max high no limit")
	contains(t, r.out, "Admission policy:")

	key := filepath.Join(t.TempDir(), "acme.pub")
	write(t, key, "-----BEGIN PUBLIC KEY-----\nMFkw…\n-----END PUBLIC KEY-----\n")
	r = h.mustRun("regions", "supply-chain", "set", "eu-1", "--set", "gate_mode=enforce", "--set", "gate-max-critical=0",
		"--set", "gate_max_high=", "--set", "gate_require_scan=yes", "--set", "rescan_interval_hours=12",
		"--set", "caas_policy=signed", "--set", "caas_allowed_registries=ghcr.io/acme, registry.example.com",
		"--trusted-key", key, "--set", "data_location=TR")
	contains(t, r.err, "Updated the supply chain of eu-1.")
	contains(t, r.out, "enforce: max critical 0, max high no limit")
	body := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/supply-chain")[0].Body
	if body["gate_mode"] != "enforce" || body["gate_max_critical"] != float64(0) || body["gate_require_scan"] != true ||
		body["rescan_interval_hours"] != float64(12) || len(body["caas_allowed_registries"].([]any)) != 2 ||
		len(body["caas_trusted_keys"].([]any)) != 1 || body["data_location"] != "TR" {
		t.Fatalf("body = %v", body)
	}
	if v, ok := body["gate_max_high"]; !ok || v != nil {
		t.Fatalf("gate_max_high = %v (sent %v)", v, ok)
	}
	st := h.srv.RegionSupplyChainState("eu-1")
	if st.CaaSPolicy != "signed" || st.DataLocation != "TR" || st.GateMaxHigh != nil {
		t.Fatalf("state = %+v", st)
	}

	var sc runr.RegionSupplyChain
	decodeJSON(t, h.mustRun("regions", "supply-chain", "get", "eu-1", "-o", "json").out, &sc)
	if sc.GateMode != "enforce" || len(sc.CaaSTrustedKeys) != 1 || !sc.CanManage || sc.Region.Name != "eu-1" {
		t.Fatalf("json = %+v", sc)
	}

	// The API's consistency checks and the CLI's own.
	h.expectCode(ExitValidation, "regions", "supply-chain", "set", "eu-1", "--set", "caas_trusted_keys=")
	h.expectCode(ExitValidation, "regions", "supply-chain", "set", "eu-1", "--set", "gate_mode=strict")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1", "--set", "colour=blue")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1", "--set", "sbom_enabled=maybe")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1", "--set", "caas_trusted_keys=abc")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1", "--trusted-key", key, "--set", "caas_trusted_keys=")
	notKey := filepath.Join(t.TempDir(), "x.txt")
	write(t, notKey, "hello")
	h.expectCode(ExitUsage, "regions", "supply-chain", "set", "eu-1", "--trusted-key", notKey)
}

func TestRegionSupplyChainSigningKey(t *testing.T) {
	h := newHarness(t).login()

	// No key yet: public-key is 404, generating asks nothing.
	h.expectCode(ExitNotFound, "regions", "supply-chain", "public-key", "eu-1")
	r := h.mustRun("regions", "supply-chain", "signing-key", "rotate", "eu-1", "--wait")
	contains(t, r.err, "Generating a signing key for eu-1.")
	contains(t, r.out, "key ready")
	first := h.srv.RegionSupplyChainState("eu-1").SigningKeyFingerprint
	if first == "" {
		t.Fatal("no key after the generation")
	}
	contains(t, h.mustRun("regions", "supply-chain", "public-key", "eu-1").out, "BEGIN PUBLIC KEY")
	file := filepath.Join(t.TempDir(), "cosign.pub")
	h.mustRun("regions", "supply-chain", "public-key", "eu-1", "--file", file)
	if data, err := os.ReadFile(file); err != nil || !strings.Contains(string(data), "fake-key-1") {
		t.Fatalf("public key file = %q, %v", data, err)
	}
	h.expectCode(ExitUsage, "regions", "supply-chain", "public-key", "eu-1", "-o", "json")

	// Rotating an existing key asks first; the old key is retired.
	r = h.expectCode(ExitUsage, "regions", "supply-chain", "signing-key", "rotate", "eu-1")
	contains(t, r.err, "Rotate the signing key of eu-1")
	var sc runr.RegionSupplyChain
	decodeJSON(t, h.mustRun("regions", "supply-chain", "signing-key", "rotate", "eu-1", "--yes", "--wait", "-o", "json").out, &sc)
	if sc.SigningKeyFingerprint == first || len(sc.SigningRetiredKeys) != 1 || sc.SigningRetiredKeys[0].Fingerprint != first {
		t.Fatalf("after rotation = %+v", sc)
	}

	// Removing retired keys asks too.
	r = h.expectCode(ExitUsage, "regions", "supply-chain", "signing-key", "forget-retired", "eu-1")
	contains(t, r.err, "1 retired signing key")
	h.mustRun("regions", "supply-chain", "signing-key", "forget-retired", "eu-1", "--yes")
	if n := len(h.srv.RegionSupplyChainState("eu-1").SigningRetiredKeys); n != 0 {
		t.Fatalf("%d retired keys left", n)
	}
	r = h.mustRun("regions", "supply-chain", "signing-key", "forget-retired", "eu-1")
	contains(t, r.err, "no retired signing keys")
	if n := len(h.srv.CallsTo("DELETE /knative-regions/{knativeRegion}/supply-chain/signing-key/retired")); n != 1 {
		t.Fatalf("%d removals", n)
	}

	// A failed generation ends with exit code 6.
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "supply-chain", "signing-key", "rotate", "eu-1", "--yes", "--wait")
	contains(t, r.err, "helm install failed")
}

func TestRegionSupplyChainScannerDBAndAdmission(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "supply-chain", "scanner-db", "refresh", "eu-1", "--wait", "-o", "json")
	var sc runr.RegionSupplyChain
	decodeJSON(t, r.out, &sc)
	if sc.ScannerDBStatus != "ready" || sc.ScannerDBUpdatedAt == "" {
		t.Fatalf("scanner db = %+v", sc)
	}

	r = h.mustRun("regions", "supply-chain", "admission", "preview", "eu-1")
	contains(t, r.out, "policy-controller")
	contains(t, r.out, "runr-signed-builds")
	var p runr.AdmissionPreview
	decodeJSON(t, h.mustRun("regions", "supply-chain", "admission", "preview", "eu-1", "-o", "json").out, &p)
	if p.Chart == "" || len(p.Policies) != 1 {
		t.Fatalf("preview = %+v", p)
	}

	// Without a mode the API refuses; --mode sets it.
	h.expectCode(ExitValidation, "regions", "supply-chain", "admission", "install", "eu-1")
	h.expectCode(ExitUsage, "regions", "supply-chain", "admission", "install", "eu-1", "--mode", "strict")
	r = h.mustRun("regions", "supply-chain", "admission", "install", "eu-1", "--mode", "warn", "--wait")
	contains(t, r.out, "warn, ready")
	calls := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/supply-chain/admission")
	if calls[len(calls)-1].Body["mode"] != "warn" {
		t.Fatalf("install body = %v", calls[len(calls)-1].Body)
	}

	h.expectCode(ExitUsage, "regions", "supply-chain", "admission", "uninstall", "eu-1")
	h.mustRun("regions", "supply-chain", "admission", "uninstall", "eu-1", "--yes", "--wait")
	if st := h.srv.RegionSupplyChainState("eu-1"); st.AdmissionStatus != "not_installed" {
		t.Fatalf("admission after uninstall = %s", st.AdmissionStatus)
	}
}

func TestRegionSupplyChainRuntimeImages(t *testing.T) {
	h := newHarness(t).login()
	contains(t, h.mustRun("regions", "supply-chain", "runtime-images", "eu-1").out, "No runtime images")

	rep := h.srv.SeedRuntimeImageReport("eu-1", "q", "1.0", runr.SeverityCounts{High: 2})
	r := h.mustRun("regions", "supply-chain", "runtime-images", "eu-1")
	contains(t, r.out, rep.Report.ID)
	contains(t, r.out, "signed")

	r = h.mustRun("regions", "supply-chain", "runtime-images", "sbom", "eu-1", rep.Report.ID)
	contains(t, r.out, "SPDX-2.3")
	file := filepath.Join(t.TempDir(), "q.cdx.json")
	h.mustRun("regions", "supply-chain", "runtime-images", "sbom", "eu-1", rep.Report.ID, "--format", runr.SBOMCycloneDX, "--file", file)
	if data, err := os.ReadFile(file); err != nil || !strings.Contains(string(data), "CycloneDX") {
		t.Fatalf("sbom file = %q, %v", data, err)
	}
	call := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom")[1]
	if q, _ := url.ParseQuery(call.Query); q.Get("format") != runr.SBOMCycloneDX {
		t.Fatalf("query = %q", call.Query)
	}
	h.expectCode(ExitNotFound, "regions", "supply-chain", "runtime-images", "sbom", "eu-1", "01jnoreport000000000000000")
	h.expectCode(ExitUsage, "regions", "supply-chain", "runtime-images", "sbom", "eu-1", rep.Report.ID, "--format", "xml")
}

func TestRegionSupplyChainPermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "supply-chain", "eu-1")
	r := h.expectCode(ExitAuth, "regions", "supply-chain", "set", "eu-1", "--set", "gate_mode=warn")
	contains(t, r.err, "regions.supply_chain.manage")
	h.expectCode(ExitAuth, "regions", "supply-chain", "admission", "preview", "eu-1")
}
