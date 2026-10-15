package cmd

import (
	"net/url"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionGPUInstallSetUninstall(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "gpu", "eu-1", "--refresh")
	contains(t, r.out, "GPU support:")
	contains(t, r.out, "none, not_installed")
	contains(t, r.out, "GPU hardware without the plugin:")
	contains(t, r.out, "gpu-1")
	call := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/gpu")[0]
	if q, _ := url.ParseQuery(call.Query); q.Get("refresh") != "1" {
		t.Fatalf("query = %q", call.Query)
	}

	// The device plugin changes no node runtime: no question.
	r = h.mustRun("regions", "gpu", "install", "eu-1", "--mode", "device-plugin", "--time-slicing", "4", "--gpu-node", "gpu-1", "--wait")
	contains(t, r.err, "installation queued")
	contains(t, r.out, "device-plugin, ready")
	contains(t, r.out, "4 pods per GPU")
	body := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/gpu/install")[0].Body
	if body["mode"] != "device-plugin" || body["time_slicing_replicas"] != float64(4) || len(body["gpu_nodes"].([]any)) != 1 {
		t.Fatalf("install body = %v", body)
	}
	if _, sent := body["operator_driver"]; sent {
		t.Fatalf("device-plugin sent operator flags: %v", body)
	}

	var g runr.RegionGPU
	decodeJSON(t, h.mustRun("regions", "gpu", "status", "eu-1", "-o", "json").out, &g)
	if g.Mode != runr.GPUModeDevicePlugin || g.Summary.Total != 2 || len(g.Nodes) != 1 || g.Settings.TimeSlicingReplicas == nil {
		t.Fatalf("gpu = %+v", g)
	}

	// Settings: a chart change is applied in the background, nvproxy only stored.
	r = h.mustRun("regions", "gpu", "set", "eu-1", "--no-time-slicing", "--auto-gpu-nodes", "--wait")
	contains(t, r.err, "being applied")
	body = h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/gpu/settings")[0].Body
	if v, ok := body["time_slicing_replicas"]; !ok || v != nil {
		t.Fatalf("--no-time-slicing body = %v", body)
	}
	if v, ok := body["gpu_nodes"]; !ok || v != nil {
		t.Fatalf("--auto-gpu-nodes body = %v", body)
	}
	h.mustRun("regions", "gpu", "set", "eu-1", "--gvisor-nvproxy", "--runtime-class", "nvidia")
	_, _, settings := h.srv.RegionGPUState("eu-1")
	if settings["gvisor_nvproxy"] != true || settings["runtime_class_choice"] != "nvidia" {
		t.Fatalf("settings = %v", settings)
	}

	// Removal asks first.
	h.expectCode(ExitUsage, "regions", "gpu", "uninstall", "eu-1")
	h.mustRun("regions", "gpu", "uninstall", "eu-1", "--yes", "--wait")
	if mode, status, _ := h.srv.RegionGPUState("eu-1"); mode != "none" || status != "not_installed" {
		t.Fatalf("after uninstall: %s %s", mode, status)
	}

	// Usage errors.
	h.expectCode(ExitUsage, "regions", "gpu", "install", "eu-1")
	h.expectCode(ExitUsage, "regions", "gpu", "install", "eu-1", "--mode", "device-plugin", "--driver=false")
	h.expectCode(ExitUsage, "regions", "gpu", "install", "eu-1", "--mode", "device-plugin", "--time-slicing", "40")
	h.expectCode(ExitUsage, "regions", "gpu", "install", "eu-1", "--mode", "device-plugin", "--runtime-class", "kata")
	h.expectCode(ExitUsage, "regions", "gpu", "set", "eu-1")
	h.expectCode(ExitUsage, "regions", "gpu", "set", "eu-1", "--time-slicing", "2", "--no-time-slicing")
	h.expectCode(ExitUsage, "regions", "gpu", "set", "eu-1", "--gpu-node", "gpu-1", "--auto-gpu-nodes")
	h.expectCode(ExitValidation, "regions", "gpu", "uninstall", "eu-1", "--yes")
}

func TestRegionGPUOperator(t *testing.T) {
	h := newHarness(t).login()

	// The operator installs the driver and toolkit: it asks first.
	r := h.expectCode(ExitUsage, "regions", "gpu", "install", "eu-1", "--mode", "operator")
	contains(t, r.err, "restarts their container runtime")
	h.terminal = true
	r = h.runStdin("n\n", "regions", "gpu", "install", "eu-1", "--mode", "operator")
	contains(t, r.err, "Aborted.")
	h.terminal = false
	if n := len(h.srv.CallsTo("POST /knative-regions/{knativeRegion}/gpu/install")); n != 0 {
		t.Fatalf("%d installs after no", n)
	}
	// Without the driver and the toolkit nothing on the nodes changes: no question.
	h.mustRun("regions", "gpu", "install", "eu-1", "--mode", "operator", "--driver=false", "--toolkit=false")
	body := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/gpu/install")[0].Body
	if body["operator_driver"] != false || body["operator_toolkit"] != false {
		t.Fatalf("body = %v", body)
	}

	h2 := newHarness(t).login()
	h2.srv.RegionAdmin().FailInstall = true
	r = h2.expectCode(ExitFailed, "regions", "gpu", "install", "eu-1", "--mode", "operator", "--yes", "--wait")
	contains(t, r.err, "helm install failed")
}

func TestRegionModelStorage(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "model-storage", "eu-1")
	contains(t, r.out, "runr-models, absent")
	contains(t, r.out, "100Gi")

	var ms runr.ModelStorage
	decodeJSON(t, h.mustRun("regions", "model-storage", "set", "eu-1", "--storage-class", "longhorn", "--size", "2Ti",
		"--access-mode", "ReadWriteOnce", "-o", "json").out, &ms)
	if ms.StorageClass != "longhorn" || ms.SizeGi != 2048 || ms.AccessMode != "ReadWriteOnce" {
		t.Fatalf("storage = %+v", ms)
	}

	// A bound claim only grows.
	h.srv.BindModelStorage("eu-1")
	h.mustRun("regions", "model-storage", "set", "eu-1", "--size", "3000")
	h.expectCode(ExitValidation, "regions", "model-storage", "set", "eu-1", "--size", "100Gi")
	h.expectCode(ExitValidation, "regions", "model-storage", "set", "eu-1", "--storage-class", "nfs")
	contains(t, h.mustRun("regions", "model-storage", "get", "eu-1").out, "capacity 3000Gi")

	h.expectCode(ExitUsage, "regions", "model-storage", "set", "eu-1")
	h.expectCode(ExitUsage, "regions", "model-storage", "set", "eu-1", "--size", "lots")
	h.expectCode(ExitUsage, "regions", "model-storage", "set", "eu-1", "--access-mode", "ReadOnlyMany")

	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "model-storage", "eu-1")
	h.mustRun("regions", "gpu", "eu-1")
	r = h.expectCode(ExitAuth, "regions", "model-storage", "set", "eu-1", "--size", "4000")
	contains(t, r.err, "regions.gpu.manage")
}
