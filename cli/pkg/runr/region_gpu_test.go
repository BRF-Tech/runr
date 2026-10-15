package runr

import (
	"encoding/json"
	"testing"
)

// PHP sends empty maps as []: a region without a GPU inventory yet.
func TestRegionGPUDecodesEmptyPHPArrays(t *testing.T) {
	var g RegionGPU
	raw := `{"mode":"none","status":"not_installed","status_message":null,"settings":{"time_slicing_replicas":null,
		"runtime_class":null,"runtime_class_choice":"auto","gvisor_nvproxy":false,"operator_driver":true,
		"operator_toolkit":true,"restrict_to_gpu_nodes":true,"gpu_nodes":null},"summary":[],"nodes":[],
		"allocation":{"total":0,"by_node":[]},"sandbox":[],"detected_at":null}`
	if err := json.Unmarshal([]byte(raw), &g); err != nil {
		t.Fatal(err)
	}
	if g.Mode != GPUModeNone || g.Summary.Total != 0 || len(g.Allocation.ByNode) != 0 || len(g.Sandbox) != 0 ||
		g.Settings.RuntimeClassChoice != "auto" {
		t.Fatalf("gpu = %+v", g)
	}

	raw = `{"mode":"operator","status":"ready","summary":{"total":2,"available":1,"products":[{"product":"NVIDIA-L4",
		"memory_mb":23034,"count":2,"allocatable":2,"nodes":1}]},"allocation":{"total":1,"by_node":{"gpu-1":1}},
		"sandbox":{"faas":{"runtime_class":"gvisor","gpu":"unsupported","message":"needs nvproxy"}}}`
	if err := json.Unmarshal([]byte(raw), &g); err != nil {
		t.Fatal(err)
	}
	if g.Summary.Total != 2 || g.Summary.Available != 1 || len(g.Summary.Products) != 1 || g.Allocation.ByNode["gpu-1"] != 1 ||
		g.Sandbox["faas"].GPU != "unsupported" {
		t.Fatalf("gpu = %+v", g)
	}
}
