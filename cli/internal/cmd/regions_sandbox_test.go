package cmd

import (
	"net/url"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionSandboxInstallAndMap(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "sandbox", "eu-1")
	contains(t, r.out, "Kata Containers:")
	contains(t, r.out, "not_installed")
	contains(t, r.out, "faas projects run under:")

	// gVisor: no confirmation, --wait follows the install.
	r = h.mustRun("regions", "sandbox", "install", "eu-1", "gvisor", "--wait")
	contains(t, r.err, "installation queued")
	var st runr.RegionSandboxStatus
	decodeJSON(t, h.mustRun("regions", "sandbox", "status", "eu-1", "-o", "json").out, &st)
	if st.GVisor.Status != runr.SandboxRuntimeReady || st.Kata.Status != runr.SandboxRuntimeNotInstalled {
		t.Fatalf("status = %+v", st)
	}
	if body := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/install-sandbox")[0].Body; body["runtime"] != "gvisor" || len(body) != 1 {
		t.Fatalf("gvisor install body = %v", body)
	}

	// Kata restarts the container runtime: --yes outside a terminal, the warning is shown.
	r = h.expectCode(ExitUsage, "regions", "sandbox", "install", "eu-1", "kata")
	contains(t, r.err, "restarts the container runtime")
	r = h.mustRun("regions", "sandbox", "install", "eu-1", "kata", "--hypervisor", "clh", "--hypervisor", "kata-fc",
		"--devmapper-pool-gb", "50", "--yes", "--wait", "-o", "json")
	contains(t, r.err, "Thin-pool data size: 50 GiB")
	decodeJSON(t, r.out, &st)
	if st.Kata.Status != runr.SandboxRuntimeReady || strings.Join(st.Kata.Hypervisors, ",") != "clh,fc" {
		t.Fatalf("kata = %+v", st.Kata)
	}
	body := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/install-sandbox")[1].Body
	if body["devmapper_pool_gb"] != float64(50) || len(body["hypervisors"].([]any)) != 2 {
		t.Fatalf("kata install body = %v", body)
	}
	// A terminal answers the question.
	h.terminal = true
	r = h.runStdin("n\n", "regions", "sandbox", "install", "eu-1", "kata")
	contains(t, r.err, "Aborted.")
	h.terminal = false

	// Mapping: types left out keep their class; all four are sent.
	r = h.mustRun("regions", "sandbox", "map", "eu-1", "--faas", "gvisor", "--build", "kata-clh")
	contains(t, r.err, "Updated the sandbox mapping of eu-1.")
	if m := h.srv.SandboxMapping("eu-1"); m["faas"] != "gvisor" || m["build"] != "kata-clh" || m["xaas"] != "runc" || m["caas"] != "runc" {
		t.Fatalf("mapping = %v", m)
	}
	put := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/sandbox-mapping")[0].Body
	if len(put) != 4 || put["sandbox_caas_runtime"] != "runc" {
		t.Fatalf("mapping body = %v", put)
	}
	h.expectCode(ExitValidation, "regions", "sandbox", "map", "eu-1", "--caas", "kata-qemu")
	h.expectCode(ExitUsage, "regions", "sandbox", "map", "eu-1")

	// Removal asks too.
	h.expectCode(ExitUsage, "regions", "sandbox", "uninstall", "eu-1", "gvisor")
	h.mustRun("regions", "sandbox", "uninstall", "eu-1", "gvisor", "--yes", "--wait")
	decodeJSON(t, h.mustRun("regions", "sandbox", "eu-1", "-o", "json").out, &st)
	if st.GVisor.Status != runr.SandboxRuntimeNotInstalled {
		t.Fatalf("after uninstall = %+v", st.GVisor)
	}

	// Usage errors.
	h.expectCode(ExitUsage, "regions", "sandbox", "install", "eu-1", "runc")
	h.expectCode(ExitUsage, "regions", "sandbox", "install", "eu-1", "gvisor", "--hypervisor", "clh")
	h.expectCode(ExitUsage, "regions", "sandbox", "install", "eu-1", "kata", "--hypervisor", "vbox", "--yes")
	h.expectCode(ExitUsage, "regions", "sandbox", "install", "eu-1", "kata", "--devmapper-pool-gb", "5", "--yes")
	h.expectCode(ExitNotFound, "regions", "sandbox", "mars-1")
}

func TestRegionSandboxFailedInstall(t *testing.T) {
	h := newHarness(t).login()
	cfg := h.srv.RegionAdmin()
	cfg.FailInstall = true
	r := h.expectCode(ExitFailed, "regions", "sandbox", "install", "eu-1", "gvisor", "--wait")
	contains(t, r.err, "helm install failed")

	// Without hardware virtualization Kata is refused up front.
	no := false
	h.srv.SetRegionKVM("eu-1", map[string]*bool{"worker-1": &no}, false)
	r = h.expectCode(ExitValidation, "regions", "sandbox", "install", "eu-1", "kata", "--yes")
	contains(t, r.err, "/dev/kvm")
}

func TestRegionSandboxShims(t *testing.T) {
	h := newHarness(t).login()
	yes := true
	h.srv.SetRegionKVM("eu-1", map[string]*bool{"worker-1": &yes, "worker-2": nil}, false)

	r := h.mustRun("regions", "sandbox", "shims", "eu-1")
	contains(t, r.out, "/dev/kvm:")
	contains(t, r.out, "mixed")
	contains(t, r.out, "worker-1 yes")
	contains(t, r.out, "worker-2 not checked")
	contains(t, r.out, "devmapper snapshotter")
	contains(t, r.out, "kata-clh")

	var sh runr.RegionKataShims
	decodeJSON(t, h.mustRun("regions", "sandbox", "shims", "eu-1", "--recheck", "-o", "json").out, &sh)
	if !sh.KVM.Probing || sh.Shims["fc"].Available || !sh.Shims["clh"].Available || sh.KVM.Nodes["worker-2"] != nil {
		t.Fatalf("shims = %+v", sh)
	}
	calls := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/kata-shims")
	if q, _ := url.ParseQuery(calls[1].Query); q.Get("refresh") != "1" || calls[0].Query != "" {
		t.Fatalf("queries = %q, %q", calls[0].Query, calls[1].Query)
	}

	// No node checked yet: the API sends the maps as [].
	h.srv.SetRegionKVM("eu-1", map[string]*bool{}, true)
	decodeJSON(t, h.mustRun("regions", "sandbox", "shims", "eu-1", "-o", "json").out, &sh)
	if sh.KVM.State != "unknown" || len(sh.KVM.Nodes) != 0 {
		t.Fatalf("empty kvm = %+v", sh.KVM)
	}
}

func TestRegionSandboxPermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("ops", "regions.view"))
	h.mustRun("regions", "sandbox", "eu-1")
	h.mustRun("regions", "sandbox", "shims", "eu-1")
	r := h.expectCode(ExitAuth, "regions", "sandbox", "install", "eu-1", "gvisor")
	contains(t, r.err, "sandbox.manage")
	h.expectCode(ExitAuth, "regions", "sandbox", "map", "eu-1", "--faas", "runc")
}
