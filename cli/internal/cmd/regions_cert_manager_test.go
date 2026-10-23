package cmd

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionCertManagerInstallCARemove(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "cert-manager", "eu-1")
	contains(t, r.out, "not_installed")
	contains(t, r.out, "TLS mode cert-manager is not available: cert-manager is not installed in this region.")
	h.mustRun("regions", "cert-manager", "status", "eu-1", "--no-cluster")
	if q := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/cert-manager")[1].Query; q != "cluster=0" {
		t.Fatalf("status query = %q", q)
	}

	r = h.mustRun("regions", "cert-manager", "install", "eu-1", "--wait")
	contains(t, r.err, "cert-manager installation queued.")
	contains(t, r.err, "cert-manager of eu-1: ready.")
	contains(t, r.out, "runr-ca — the region CA")
	contains(t, r.out, "not reachable from the internet")

	var cm runr.RegionCertManager
	decodeJSON(t, h.mustRun("regions", "cert-manager", "eu-1", "-o", "json").out, &cm)
	if cm.Status != runr.KnativeReady || cm.Issuer.Name != "runr-ca" || cm.Issuer.Kind != "ca" || cm.Managed == nil || !*cm.Managed ||
		!cm.Domains.Available || !cm.Domains.CA || cm.Cluster == nil || !cm.Cluster.CAAvailable {
		t.Fatalf("cert-manager = %+v", cm)
	}

	// The region CA: printed, or written to a file
	r = h.mustRun("regions", "cert-manager", "ca", "eu-1")
	contains(t, r.out, "-----BEGIN CERTIFICATE-----")
	file := filepath.Join(t.TempDir(), "eu-1-ca.crt")
	h.mustRun("regions", "cert-manager", "ca", "eu-1", "-f", file)
	if data, err := os.ReadFile(file); err != nil || len(data) == 0 {
		t.Fatalf("ca file: %v (%d bytes)", err, len(data))
	}
	h.expectCode(ExitUsage, "regions", "cert-manager", "ca", "eu-1", "-o", "json")

	// Removal: confirmation, refused while custom domains have certificates
	h.expectCode(ExitUsage, "regions", "cert-manager", "rm", "eu-1")
	h.srv.SetRegionCertManagerInUse("eu-1", 2)
	r = h.expectCode(ExitValidation, "regions", "cert-manager", "rm", "eu-1", "--yes")
	contains(t, r.err, "Switch those domains to another TLS mode")
	h.srv.SetRegionCertManagerInUse("eu-1", 0)
	decodeJSON(t, h.mustRun("regions", "cert-manager", "rm", "eu-1", "--yes", "--wait", "-o", "json").out, &cm)
	if cm.Status != runr.KnativeNotInstalled {
		t.Fatalf("after rm = %+v", cm)
	}
	h.expectCode(ExitValidation, "regions", "cert-manager", "rm", "eu-1", "--yes")
	h.expectCode(ExitNotFound, "regions", "cert-manager", "ca", "eu-1")
}

func TestRegionCertManagerNeedsServingAndFails(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeNotInstalled)
	r := h.expectCode(ExitValidation, "regions", "cert-manager", "install", "eu-1")
	contains(t, r.err, "Install Knative Serving first")

	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeReady)
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "cert-manager", "install", "eu-1", "--wait")
	contains(t, r.err, "cert-manager of region eu-1 failed")
}

func TestRegionSetCertManagerIssuerSettings(t *testing.T) {
	h := newHarness(t).login()

	h.mustRun("regions", "set", "eu-1", "--set", "cert_manager_issuer_mode=acme", "--set", "cert_manager_acme_email=ops@example.com")
	body := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")[0].Body
	if body["cert_manager_issuer_mode"] != "acme" || body["cert_manager_acme_email"] != "ops@example.com" {
		t.Fatalf("settings body = %v", body)
	}

	// an empty e-mail: the platform's again
	h.mustRun("regions", "set", "eu-1", "--set", "cert_manager_acme_email=")
	if v, ok := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")[1].Body["cert_manager_acme_email"]; !ok || v != nil {
		t.Fatalf("empty e-mail = %v (sent: %v)", v, ok)
	}

	r := h.expectCode(ExitUsage, "regions", "set", "eu-1", "--set", "cert_manager_issuer_mode=letsencrypt")
	contains(t, r.err, "auto, acme or ca")
}

func TestDomainsCA(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	r := h.mustRun("domains", "ca", "--dir", dir)
	contains(t, r.out, "-----BEGIN CERTIFICATE-----")
	if len(h.srv.CallsTo("GET /projects/{project}/domains/ca-certificate")) != 1 {
		t.Fatal("the domains CA was not asked for")
	}
}
