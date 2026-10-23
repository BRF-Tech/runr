package cmd

import (
	"encoding/json"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionOpenBaoInstallDefaultUninstall(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "openbao", "eu-1")
	contains(t, r.out, "not_installed")
	contains(t, r.out, "the project's Kubernetes Secret")

	r = h.mustRun("regions", "openbao", "install", "eu-1", "--wait")
	contains(t, r.err, "OpenBao is being installed.")
	contains(t, r.err, "OpenBao of eu-1: ready.")
	contains(t, r.out, "2.4.1")
	contains(t, r.out, "New values go to")
	contains(t, r.out, "OpenBao")

	// New values back to the Kubernetes Secret, and to OpenBao again
	r = h.mustRun("regions", "openbao", "default", "eu-1", "off")
	contains(t, r.err, "go to their Kubernetes Secrets")
	body := h.srv.CallsTo("PATCH /knative-regions/{knativeRegion}/openbao")[0].Body
	if body["default_for_values"] != false {
		t.Fatalf("PATCH body = %v", body)
	}
	h.mustRun("regions", "openbao", "default", "eu-1", "on")
	h.expectCode(ExitUsage, "regions", "openbao", "default", "eu-1", "maybe")

	var b runr.RegionOpenBao
	if err := json.Unmarshal([]byte(h.mustRun("regions", "openbao", "status", "eu-1", "-o", "json").out), &b); err != nil {
		t.Fatal(err)
	}
	if b.Status != runr.KnativeReady || !b.DefaultForValues {
		t.Fatalf("openbao = %+v", b)
	}

	// Refused while values live in it
	h.srv.SetRegionOpenBao("eu-1", runr.KnativeReady, runr.KnativeReady, 2)
	r = h.expectCode(ExitValidation, "regions", "openbao", "uninstall", "eu-1", "--yes")
	contains(t, r.err, "live in its OpenBao")

	h.srv.SetRegionOpenBao("eu-1", runr.KnativeReady, runr.KnativeReady, 0)
	r = h.mustRun("regions", "openbao", "rm", "eu-1", "--yes", "--wait")
	contains(t, r.err, "OpenBao is being removed.")
	contains(t, r.out, "not_installed")
}

func TestRegionOpenBaoNeedsExternalSecretsOperator(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionOpenBao("eu-1", runr.KnativeNotInstalled, runr.KnativeNotInstalled, 0)

	r := h.expectCode(ExitValidation, "regions", "openbao", "install", "eu-1")
	contains(t, r.err, "needs External Secrets Operator")

	r = h.mustRun("regions", "openbao", "eu-1")
	contains(t, r.out, "OpenBao needs External Secrets Operator")

	h.srv.SetRegionOpenBao("eu-1", runr.KnativeNotInstalled, runr.KnativeReady, 0)
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "openbao", "install", "eu-1", "--wait")
	contains(t, r.err, "OpenBao of region eu-1 failed")
}
