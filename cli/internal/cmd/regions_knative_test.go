package cmd

import (
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionKnativeInstallUninstall(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeNotInstalled, runr.KnativeNotInstalled)

	r := h.mustRun("regions", "knative", "eu-1")
	contains(t, r.out, "Operator:")
	contains(t, r.out, "not_installed in knative-operator")

	// Serving needs the operator first (the API refuses it).
	h.expectCode(ExitValidation, "regions", "knative", "install", "eu-1", "serving")

	// Operator, then Serving with a version, each waited for.
	r = h.mustRun("regions", "knative", "install", "eu-1", "operator", "--namespace", "kn-op", "--wait")
	contains(t, r.err, "Operator installation started.")
	contains(t, r.err, "The Knative operator on eu-1: ready.")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/install-operator")[0].Body; b["namespace"] != "kn-op" {
		t.Fatalf("operator body = %v", b)
	}
	var d runr.RegionDetail
	decodeJSON(t, h.mustRun("regions", "knative", "install", "eu-1", "serving", "--version", "1.18", "--wait", "-o", "json").out, &d)
	if d.ServingStatus != runr.KnativeReady || !d.HasServing || d.OperatorNamespace != "kn-op" {
		t.Fatalf("after serving = %+v", d)
	}
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/install-serving")[1].Body; b["version"] != "1.18" {
		t.Fatalf("serving body = %v", b)
	}
	// Installed already: the API says so.
	r = h.mustRun("regions", "knative", "install", "eu-1", "operator")
	contains(t, r.err, "Operator is already installed.")

	// The operator goes after Serving; uninstalls ask first.
	h.expectCode(ExitUsage, "regions", "knative", "uninstall", "eu-1", "serving")
	h.expectCode(ExitValidation, "regions", "knative", "uninstall", "eu-1", "operator", "--yes")
	h.mustRun("regions", "knative", "uninstall", "eu-1", "serving", "--remove-traefik", "--yes", "--wait")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); !lc.RemoveTraefik || lc.Detail.ServingStatus != runr.KnativeNotInstalled {
		t.Fatalf("after uninstall = %+v", lc)
	}
	h.mustRun("regions", "knative", "uninstall", "eu-1", "operator", "--yes", "--wait")

	// all: one install of both, one removal
	r = h.mustRun("regions", "knative", "install", "eu-1", "--wait")
	contains(t, r.err, "Installation started.")
	contains(t, r.err, "Knative on eu-1: ready.")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); lc.Detail.ServingStatus != runr.KnativeReady || lc.Detail.KnativeStatus != runr.KnativeReady {
		t.Fatalf("after install all = %+v", lc.Detail)
	}
	h.mustRun("regions", "knative", "uninstall", "eu-1", "all", "--yes", "--wait")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/uninstall-knative")[0].Body; b != nil {
		t.Fatalf("uninstall-knative body = %v", b)
	}

	// Usage errors.
	h.expectCode(ExitUsage, "regions", "knative", "install", "eu-1", "eventing")
	h.expectCode(ExitUsage, "regions", "knative", "install", "eu-1", "--version", "1.18")
	h.expectCode(ExitUsage, "regions", "knative", "install", "eu-1", "operator", "--version", "1.18")
	h.expectCode(ExitUsage, "regions", "knative", "uninstall", "eu-1", "operator", "--remove-traefik", "--yes")
}

func TestRegionKnativeFailedInstall(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeNotInstalled, runr.KnativeNotInstalled)
	h.srv.RegionAdmin().FailInstall = true
	r := h.expectCode(ExitFailed, "regions", "knative", "install", "eu-1", "--wait")
	contains(t, r.err, "helm install failed")
	contains(t, r.err, "Knative of region eu-1 failed")
}

func TestRegionKnativeDetectVersionsNamespace(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeNotInstalled, runr.KnativeNotInstalled)
	h.srv.SeedRegionAdoptable("eu-1", true, true)

	r := h.mustRun("regions", "knative", "detect", "eu-1")
	contains(t, r.out, "adopted in knative-operator")
	contains(t, r.out, "adopted in knative-serving")
	var det runr.KnativeDetection
	decodeJSON(t, h.mustRun("regions", "knative", "detect", "eu-1", "-o", "json").out, &det)
	if det.Operator != nil || det.Region == nil || det.Region.KnativeStatus != runr.KnativeReady {
		t.Fatalf("second detect = %+v", det)
	}

	r = h.mustRun("regions", "knative", "versions", "eu-1")
	contains(t, r.out, "1.19")
	var versions []string
	decodeJSON(t, h.mustRun("regions", "knative", "versions", "-o", "json").out, &versions)
	if len(versions) != 3 {
		t.Fatalf("versions = %v", versions)
	}

	r = h.mustRun("regions", "knative", "namespace", "eu-1", "knative-serving")
	contains(t, r.out, "exists")
	r = h.mustRun("regions", "knative", "namespace", "eu-1", "kn-new", "-o", "json")
	contains(t, r.out, `"exists": false`)
	if q := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/check-namespace")[1].Query; q != "name=kn-new" {
		t.Fatalf("query = %q", q)
	}
}

func TestRegionKnativeAdoptOnInstall(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeNotInstalled, runr.KnativeNotInstalled)
	h.srv.SeedRegionAdoptable("eu-1", true, false)
	var res runr.RegionOperation
	decodeJSON(t, h.mustRun("regions", "knative", "install", "eu-1", "operator", "--wait", "-o", "json").out, &res)
	if !res.Adopted || res.Region == nil || res.Region.OperatorStatus != runr.KnativeReady {
		t.Fatalf("adoption = %+v", res)
	}
}
