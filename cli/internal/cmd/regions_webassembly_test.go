package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionWasmInstallUninstall(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "wasm", "eu-1")
	contains(t, r.out, "not_installed")
	contains(t, r.out, "spin 0.21.0 (wasm-spin)")
	contains(t, r.out, "win-1")
	contains(t, r.out, "no: not a Linux node")
	contains(t, r.out, "kubernetes.podspec-runtimeclassname")
	h.mustRun("regions", "wasm", "status", "eu-1", "--no-cluster")
	if q := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/wasm")[1].Query; q != "cluster=0" {
		t.Fatalf("status query = %q", q)
	}

	// The container runtime restarts: confirmation first.
	r = h.expectCode(ExitUsage, "regions", "wasm", "install", "eu-1")
	contains(t, r.err, "restarts the container runtime")
	h.terminal = true
	r = h.runStdin("n\n", "regions", "wasm", "install", "eu-1")
	contains(t, r.err, "Aborted.")
	h.terminal = false

	r = h.mustRun("regions", "wasm", "install", "eu-1", "--shim", "spin", "--shim", "wasmtime", "--shim", "spin",
		"--node", "worker-1", "--yes", "--wait")
	contains(t, r.err, "WebAssembly support installation queued.")
	contains(t, r.err, "WebAssembly support of eu-1: ready.")
	body := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/wasm")[0].Body
	if len(body["shims"].([]any)) != 2 || len(body["nodes"].([]any)) != 1 {
		t.Fatalf("install body = %v", body)
	}
	var w runr.RegionWasm
	decodeJSON(t, h.mustRun("regions", "wasm", "eu-1", "-o", "json").out, &w)
	if w.Status != runr.KnativeReady || strings.Join(w.Shims, ",") != "spin,wasmtime" || strings.Join(w.Nodes, ",") != "worker-1" ||
		w.Cluster == nil || w.Cluster.Installer == nil || !w.Cluster.Nodes[0].Target || w.Cluster.Nodes[1].Target {
		t.Fatalf("wasm = %+v", w)
	}

	// Default shim spin on every eligible node: nodes is left out.
	h.mustRun("regions", "wasm", "install", "eu-1", "--yes", "--wait")
	body = h.srv.CallsTo("POST /knative-regions/{knativeRegion}/wasm")[1].Body
	if _, ok := body["nodes"]; ok || len(body["shims"].([]any)) != 1 {
		t.Fatalf("default install body = %v", body)
	}

	// The API checks the nodes.
	r = h.expectCode(ExitValidation, "regions", "wasm", "install", "eu-1", "--node", "win-1", "--yes")
	contains(t, r.err, "not a Linux node")
	h.expectCode(ExitUsage, "regions", "wasm", "install", "eu-1", "--shim", "wasmer", "--yes")

	h.expectCode(ExitUsage, "regions", "wasm", "uninstall", "eu-1")
	decodeJSON(t, h.mustRun("regions", "wasm", "uninstall", "eu-1", "--yes", "--wait", "-o", "json").out, &w)
	if w.Status != runr.KnativeNotInstalled || len(w.Shims) != 0 {
		t.Fatalf("after uninstall = %+v", w)
	}
	h.expectCode(ExitValidation, "regions", "wasm", "uninstall", "eu-1", "--yes")
}

func TestRegionWasmNeedsServingAndFails(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeNotInstalled)
	r := h.expectCode(ExitValidation, "regions", "wasm", "install", "eu-1", "--yes")
	contains(t, r.err, "Install Knative Serving")

	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeReady)
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "wasm", "install", "eu-1", "--yes", "--wait")
	contains(t, r.err, "WebAssembly support of region eu-1 failed")
	contains(t, r.err, "helm install failed")
}

func TestRegionWasmPermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	var w runr.RegionWasm
	decodeJSON(t, h.mustRun("regions", "wasm", "eu-1", "-o", "json").out, &w)
	if w.CanManage {
		t.Fatal("a regions.view token can manage wasm")
	}
	r := h.expectCode(ExitAuth, "regions", "wasm", "install", "eu-1", "--yes")
	contains(t, r.err, "sandbox.manage")
	h.expectCode(ExitAuth, "regions", "wasm", "uninstall", "eu-1", "--yes")
}
