package runrtest

import (
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// WebAssembly support of a region in the fake (WasmController): installs and removals end after
// RegionAdminConfig.InstallPolls reads of the Wasm status or of the region. The cluster has the
// nodes worker-1 and worker-2 (eligible) and win-1 (not eligible: not Linux).

const fakeWasmWarning = "Installing WebAssembly support restarts the container runtime once on every selected node."

var fakeWasmShims = []runr.WasmShim{
	{ID: "spin", Name: "Spin", Version: "0.21.0", RuntimeClass: "wasm-spin", Handler: "spin", Description: "Spin apps (the wasm-* runtimes)"},
	{ID: "wasmtime", Name: "Wasmtime", Version: "0.6.0", RuntimeClass: "wasm-wasmtime", Handler: "wasmtime", Description: "WASI components"},
}

func (s *Server) rlcWasmNodes(r *rlcRegion) []runr.WasmNode {
	nodes := []runr.WasmNode{
		{Name: "worker-1", Arch: "amd64", Flavor: "containerd", RuntimeVersion: "2.0.4", Eligible: true, Shims: []string{}},
		{Name: "worker-2", Arch: "arm64", Flavor: "containerd", RuntimeVersion: "2.0.4", Eligible: true, Shims: []string{}},
		{Name: "win-1", Arch: "amd64", Eligible: false, Reason: "not_linux", Shims: []string{}},
	}
	if r.d.WasmStatus == runr.KnativeReady {
		for i := range nodes {
			if !nodes[i].Eligible {
				continue
			}
			target := len(r.wasmNodes) == 0
			for _, n := range r.wasmNodes {
				target = target || n == nodes[i].Name
			}
			if target {
				nodes[i].Target = true
				nodes[i].Shims = append([]string{}, r.wasmShims...)
			}
		}
	}
	return nodes
}

func (s *Server) rlcWasmBlocked(reg *runr.Region, r *rlcRegion) *apiErr {
	if err := s.rlcMutable(reg, r); err != nil {
		return err
	}
	if r.d.WasmStatus == runr.KnativeInstalling || r.d.WasmStatus == runr.KnativeUninstalling {
		return &apiErr{status: 422, message: "Another operation is in progress on this region."}
	}
	return nil
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/wasm", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		var nodes any
		if r.wasmNodes != nil {
			nodes = r.wasmNodes
		}
		out := map[string]any{
			"status": r.d.WasmStatus, "message": nullStr(r.wasmMessage), "shims": append([]string{}, r.wasmShims...), "nodes": nodes,
			"available_shims": fakeWasmShims, "spin_runtime": "4.1.0", "warning": fakeWasmWarning,
			"can_manage": s.holds("sandbox.manage"), "cluster": nil, "cluster_error": nil,
		}
		if q.query("cluster") != "0" && r.kubeconfig != "" && !reg.IsDemo {
			features := []string{}
			if r.d.WasmStatus != runr.KnativeReady {
				features = []string{"kubernetes.podspec-runtimeclassname"}
			}
			var installer any
			if r.d.WasmStatus == runr.KnativeReady {
				installer = map[string]any{"desired": 2, "ready": 2, "shims": r.wasmShims}
			}
			out["cluster"] = map[string]any{"nodes": s.rlcWasmNodes(r), "runtime_classes": []string{}, "knative_features_to_enable": features,
				"installer": installer}
		}
		return 200, out, nil
	})
	handle("POST /knative-regions/{knativeRegion}/wasm", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcWasmBlocked(reg, r); err != nil {
			return 0, nil, err
		}
		if !r.d.HasServing {
			return 0, nil, &apiErr{status: 422, message: "Install Knative Serving on the region first."}
		}
		shims := strList(q.Body["shims"])
		if len(shims) == 0 {
			return 0, nil, errValidation("shims", "The shims field is required.")
		}
		seen := map[string]bool{}
		for _, sh := range shims {
			if sh != "spin" && sh != "wasmtime" || seen[sh] {
				return 0, nil, errValidation("shims.0", "Unsupported WebAssembly shim \""+sh+"\". Allowed: spin, wasmtime.")
			}
			seen[sh] = true
		}
		var nodes []string
		if _, ok := q.Body["nodes"]; ok {
			nodes = strList(q.Body["nodes"])
			eligible := map[string]bool{}
			known := map[string]bool{}
			for _, n := range s.rlcWasmNodes(r) {
				known[n.Name], eligible[n.Name] = true, n.Eligible
			}
			for _, n := range nodes {
				switch {
				case !known[n]:
					return 0, nil, errValidation("nodes", "Node "+n+" does not exist.")
				case !eligible[n]:
					return 0, nil, errValidation("nodes", "Node "+n+" cannot run WebAssembly: it is not a Linux node.")
				}
			}
		}
		previous := r.d.WasmStatus
		r.d.WasmStatus, r.wasmMessage = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "wasm", func(failed bool, message string) {
			if failed {
				if previous == runr.KnativeReady {
					r.d.WasmStatus, r.wasmMessage = runr.KnativeReady, message
					return
				}
				r.d.WasmStatus, r.wasmMessage = runr.KnativeError, message
				return
			}
			r.d.WasmStatus, r.wasmMessage = runr.KnativeReady, "WebAssembly support installed."
			r.wasmShims, r.wasmNodes = shims, nodes
		})
		out := s.rlcOperation(reg, r, "WebAssembly support installation queued.")
		out["warning"] = fakeWasmWarning
		return 202, out, nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/wasm", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcWasmBlocked(reg, r); err != nil {
			return 0, nil, err
		}
		if r.d.WasmStatus == runr.KnativeNotInstalled {
			return 0, nil, &apiErr{status: 422, message: "WebAssembly support is not installed."}
		}
		r.d.WasmStatus, r.wasmMessage = runr.KnativeUninstalling, "Removal queued..."
		s.rlcStart(r, "wasm", func(failed bool, message string) {
			if failed {
				r.d.WasmStatus, r.wasmMessage = runr.KnativeError, message
				return
			}
			r.d.WasmStatus, r.wasmMessage, r.wasmShims, r.wasmNodes = runr.KnativeNotInstalled, "", nil, nil
		})
		return 202, s.rlcOperation(reg, r, "WebAssembly support removal queued."), nil
	})
}
