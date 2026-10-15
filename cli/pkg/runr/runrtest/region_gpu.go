package runrtest

import (
	"fmt"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// GPU support and model storage of a region (GpuController) of the fake. Installs, removals and
// reconfigurations end after RegionAdminConfig.InstallPolls reads (in error with FailInstall);
// every region has one node "gpu-1" with two NVIDIA L4 GPUs.

type rgpuRegion struct {
	mode, status, message string
	settings              map[string]any
	pollsLeft             int
	fails                 bool
	target                string // status (and mode) the running operation ends in
	targetMode            string
	refreshed             int
	storage               map[string]any
	storageBound          bool
}

func (s *Server) rgpuOf(regionID string) *rgpuRegion {
	m := *featureState(s, "region-gpu", func() *map[string]*rgpuRegion {
		x := map[string]*rgpuRegion{}
		return &x
	})
	if r, ok := m[regionID]; ok {
		return r
	}
	r := &rgpuRegion{mode: "none", status: "not_installed",
		settings: map[string]any{"time_slicing_replicas": nil, "runtime_class": nil, "runtime_class_choice": "auto",
			"gvisor_nvproxy": false, "operator_driver": true, "operator_toolkit": true, "restrict_to_gpu_nodes": true, "gpu_nodes": nil},
		storage: map[string]any{"storage_class": "", "size_gi": 100, "access_mode": "ReadWriteMany"}}
	m[regionID] = r
	return r
}

// RegionGPUState returns the fake's GPU mode, status and settings of a region.
func (s *Server) RegionGPUState(regionName string) (mode, status string, settings map[string]any) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rgpuOf(reg.ID)
	return r.mode, r.status, r.settings
}

// BindModelStorage marks a region's model storage claim bound (only a larger size applies then).
func (s *Server) BindModelStorage(regionName string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if reg := s.regionByName(regionName); reg != nil {
		s.rgpuOf(reg.ID).storageBound = true
	}
}

func (r *rgpuRegion) advance(failMessage string) {
	if r.status != "installing" && r.status != "uninstalling" {
		return
	}
	if r.pollsLeft > 0 {
		r.pollsLeft--
		return
	}
	if r.fails {
		r.status, r.message = "error", r.message+"ERROR: "+failMessage+"\n"
		return
	}
	r.status, r.mode = r.target, r.targetMode
	r.message += "Done.\n"
}

func (s *Server) rgpuJSON(r *rgpuRegion) map[string]any {
	products, nodes := []any{}, []any{}
	total, allocatable := 0, 0
	if r.mode == "device-plugin" || r.mode == "operator" {
		total, allocatable = 2, 2
		products = append(products, map[string]any{"product": "NVIDIA-L4", "memory_mb": 23034, "count": 2, "allocatable": 2, "nodes": 1})
	}
	nodes = append(nodes, map[string]any{"name": "gpu-1", "ready": true, "product": "NVIDIA-L4", "memory_mb": 23034, "count": 2,
		"capacity": total, "allocatable": allocatable, "allocated": 0, "replicas": nil, "compute_capability": "8.9",
		"driver_version": "550.90", "cuda_version": "12.4", "has_device_plugin": total > 0, "hardware_detected": true, "taints": []any{}})
	var withoutPlugin []any = []any{}
	if total == 0 {
		withoutPlugin = append(withoutPlugin, "gpu-1")
	}
	sandbox := map[string]any{}
	for _, t := range []string{"xaas", "faas", "caas"} {
		sandbox[t] = map[string]any{"runtime_class": nil, "gpu": "ok", "message": nil}
	}
	var msg any
	if r.message != "" {
		msg = r.message
	}
	return map[string]any{
		"mode": r.mode, "status": r.status, "status_message": msg, "settings": r.settings,
		"summary": map[string]any{"gpu_nodes": allocatable / 2, "total": total, "allocatable": allocatable, "allocated": 0,
			"available": allocatable, "products": products, "hardware_without_plugin": withoutPlugin},
		"nodes":      nodes,
		"allocation": map[string]any{"total": 0, "by_node": []any{}},
		"sandbox":    sandbox, "detected_at": nowRFC3339(),
	}
}

func (s *Server) rgpuRegionOf(q *featureReq) (*runr.Region, *rgpuRegion, *apiErr) {
	reg := s.regionByID(q.Segs[1])
	if reg == nil {
		return nil, nil, errNotFound()
	}
	return reg, s.rgpuOf(reg.ID), nil
}

// rgpuSettings applies the settings fields of a body (install or settings).
func rgpuSettings(r *rgpuRegion, body map[string]any, keys ...string) *apiErr {
	for _, k := range keys {
		v, ok := body[k]
		if !ok {
			continue
		}
		switch k {
		case "time_slicing_replicas":
			if v != nil {
				n, isNum := v.(float64)
				if !isNum || n < 1 || n > 16 || n != float64(int(n)) {
					return errValidation(k, "The time slicing replicas field must be between 1 and 16.")
				}
				if n <= 1 {
					v = nil
				}
			}
			r.settings[k] = v
		case "runtime_class":
			choice := "auto"
			if v != nil {
				choice, _ = v.(string)
				if !rsbxListed(choice, []string{"auto", "nvidia", "none"}) {
					return errValidation(k, "The selected runtime class is invalid.")
				}
			}
			r.settings["runtime_class_choice"] = choice
		case "gpu_nodes":
			if v == nil {
				r.settings[k] = nil
				continue
			}
			list, isList := v.([]any)
			if !isList {
				return errValidation(k, "The gpu nodes field must be an array.")
			}
			if len(list) == 0 {
				r.settings[k] = nil
			} else {
				r.settings[k] = list
			}
		default:
			if v == nil {
				continue
			}
			b, isBool := v.(bool)
			if !isBool {
				return errValidation(k, "The "+strings.ReplaceAll(k, "_", " ")+" field must be true or false.")
			}
			r.settings[k] = b
		}
	}
	return nil
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/gpu", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if q.query("refresh") == "1" {
			r.refreshed++
		}
		r.advance(s.regionAdminSt().cfg.FailMessage)
		return 200, s.rgpuJSON(r), nil
	})

	handle("POST /knative-regions/{knativeRegion}/gpu/install", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		mode := q.str("mode")
		if mode != "device-plugin" && mode != "operator" {
			return 0, nil, errValidation("mode", "The selected mode is invalid.")
		}
		if r.status == "installing" || r.status == "uninstalling" {
			return 0, nil, &apiErr{status: 422, message: "An operation is already in progress for this region."}
		}
		if err := rgpuSettings(r, q.Body, "time_slicing_replicas", "runtime_class", "operator_driver", "operator_toolkit",
			"restrict_to_gpu_nodes", "gpu_nodes"); err != nil {
			return 0, nil, err
		}
		cfg := s.regionAdminSt().cfg
		r.status, r.message, r.target, r.targetMode = "installing", "Installation queued...\n", "ready", mode
		r.pollsLeft, r.fails = cfg.InstallPolls, cfg.FailInstall
		return 202, map[string]any{"message": fmt.Sprintf("GPU support (%s) installation queued.", mode), "gpu": s.rgpuJSON(r)}, nil
	})

	handle("POST /knative-regions/{knativeRegion}/gpu/uninstall", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if r.mode != "device-plugin" && r.mode != "operator" {
			return 0, nil, &apiErr{status: 422, message: "RunR did not install GPU support on this region."}
		}
		r.status, r.message, r.target, r.targetMode = "uninstalling", "Removal queued...\n", "not_installed", "none"
		r.pollsLeft, r.fails = s.regionAdminSt().cfg.InstallPolls, false
		return 202, map[string]any{"message": "GPU support removal queued.", "gpu": s.rgpuJSON(r)}, nil
	})

	handle("PUT /knative-regions/{knativeRegion}/gpu/settings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if err := rgpuSettings(r, q.Body, "time_slicing_replicas", "runtime_class", "gvisor_nvproxy", "restrict_to_gpu_nodes",
			"gpu_nodes"); err != nil {
			return 0, nil, err
		}
		chart := false
		for _, k := range []string{"time_slicing_replicas", "runtime_class", "restrict_to_gpu_nodes", "gpu_nodes"} {
			_, set := q.Body[k]
			chart = chart || set
		}
		if chart && (r.mode == "device-plugin" || r.mode == "operator") {
			r.status, r.message, r.target, r.targetMode = "installing", "Applying GPU settings...\n", "ready", r.mode
			r.pollsLeft, r.fails = s.regionAdminSt().cfg.InstallPolls, false
			return 202, map[string]any{"message": "GPU settings are being applied.", "gpu": s.rgpuJSON(r)}, nil
		}
		return 200, map[string]any{"message": "GPU settings saved.", "gpu": s.rgpuJSON(r)}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/model-storage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, s.modelStorageJSON(r), nil
	})

	handle("PUT /knative-regions/{knativeRegion}/model-storage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rgpuRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		next := map[string]any{}
		for k, v := range r.storage {
			next[k] = v
		}
		if v, ok := q.Body["storage_class"]; ok {
			str, _ := v.(string)
			if r.storageBound && str != r.storage["storage_class"] {
				return 0, nil, errValidation("storage_class", "The storage class of an existing claim cannot change.")
			}
			next["storage_class"] = str
		}
		if v, ok := q.Body["access_mode"]; ok {
			str, _ := v.(string)
			if str != "ReadWriteMany" && str != "ReadWriteOnce" {
				return 0, nil, errValidation("access_mode", "The selected access mode is invalid.")
			}
			if r.storageBound && str != r.storage["access_mode"] {
				return 0, nil, errValidation("access_mode", "The access mode of an existing claim cannot change.")
			}
			next["access_mode"] = str
		}
		if v, ok := q.Body["size_gi"]; ok {
			n, isNum := v.(float64)
			if !isNum || n < 1 || n > 65536 || n != float64(int(n)) {
				return 0, nil, errValidation("size_gi", "The size gi field must be between 1 and 65536.")
			}
			if r.storageBound && int(n) < r.storage["size_gi"].(int) {
				return 0, nil, errValidation("size_gi", "The claim cannot shrink.")
			}
			next["size_gi"] = int(n)
		}
		r.storage = next
		return 200, s.modelStorageJSON(r), nil
	})
}

func (s *Server) modelStorageJSON(r *rgpuRegion) map[string]any {
	out := map[string]any{"pvc_name": "runr-models", "status": "absent", "message": nil, "capacity": nil}
	for k, v := range r.storage {
		out[k] = v
	}
	if r.storageBound {
		out["status"], out["capacity"] = "bound", fmt.Sprintf("%dGi", r.storage["size_gi"])
	}
	return out
}
