package runrtest

import (
	"fmt"
	"sort"
	"strings"
)

// The sandbox runtimes of a region (SandboxController) of the fake: Kata and gVisor installs
// that end after RegionAdminConfig.InstallPolls reads of the sandbox status (in error with
// RegionAdminConfig.FailInstall), the nodes' /dev/kvm and the project type mapping.

var fakeKataHypervisors = []string{"clh", "qemu", "dragonball", "fc"}

type rsbxRuntime struct {
	status, message string
	hypervisors     []string
	pollsLeft       int
	fails           bool
	// target is the status the running operation ends in (ready or not_installed).
	target string
}

type rsbxRegion struct {
	kata, gvisor rsbxRuntime
	mapping      map[string]string
	// kvm maps a node to its /dev/kvm (nil: not checked yet).
	kvm map[string]*bool
	// devmapper reports whether fc can run (containerd's devmapper snapshotter).
	devmapper bool
	rechecks  int
}

type rsbxState struct {
	regions map[string]*rsbxRegion
}

func (s *Server) rsbx() *rsbxState {
	return featureState(s, "region-sandbox", func() *rsbxState { return &rsbxState{regions: map[string]*rsbxRegion{}} })
}

func (s *Server) rsbxRegionOf(regionID string) *rsbxRegion {
	st := s.rsbx()
	if r, ok := st.regions[regionID]; ok {
		return r
	}
	yes := true
	r := &rsbxRegion{
		kata:      rsbxRuntime{status: "not_installed"},
		gvisor:    rsbxRuntime{status: "not_installed"},
		mapping:   map[string]string{"xaas": "runc", "faas": "runc", "caas": "runc", "build": "runc"},
		kvm:       map[string]*bool{"worker-1": &yes, "worker-2": &yes},
		devmapper: true,
	}
	st.regions[regionID] = r
	return r
}

// SetRegionKVM sets the /dev/kvm of a region's nodes (nil: not checked yet) and whether fc's
// devmapper snapshotter is available.
func (s *Server) SetRegionKVM(regionName string, nodes map[string]*bool, devmapper bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rsbxRegionOf(reg.ID)
	r.kvm, r.devmapper = nodes, devmapper
}

// SeedSandboxRuntime marks a sandbox runtime of a region installed and ready (Kata with
// hypervisors).
func (s *Server) SeedSandboxRuntime(regionName, runtime string, hypervisors ...string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rsbxRegionOf(reg.ID)
	rt := r.runtime(runtime)
	rt.status, rt.message = "ready", runtime+" installed successfully."
	if runtime == "kata" {
		rt.hypervisors = append([]string(nil), hypervisors...)
	}
}

// SandboxMapping returns the project type mapping of a region.
func (s *Server) SandboxMapping(regionName string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range s.rsbxRegionOf(reg.ID).mapping {
		out[k] = v
	}
	return out
}

func (r *rsbxRegion) runtime(name string) *rsbxRuntime {
	if name == "kata" {
		return &r.kata
	}
	return &r.gvisor
}

// advance moves a running install or uninstall one status read on.
func (rt *rsbxRuntime) advance(failMessage string) {
	if rt.status != "installing" && rt.status != "uninstalling" {
		return
	}
	if rt.pollsLeft > 0 {
		rt.pollsLeft--
		return
	}
	if rt.fails {
		rt.status = "error"
		rt.message += "\nERROR: " + failMessage
		return
	}
	rt.status = rt.target
	if rt.target == "ready" {
		rt.message += "\nInstalled successfully."
	} else {
		rt.message += "\nRemoved."
		rt.hypervisors = nil
	}
}

func (r *rsbxRegion) kvmState() (string, []string) {
	yes, no := 0, 0
	var kvmNodes []string
	for n, v := range r.kvm {
		switch {
		case v == nil:
		case *v:
			yes++
			kvmNodes = append(kvmNodes, n)
		default:
			no++
		}
	}
	sort.Strings(kvmNodes)
	switch {
	case len(r.kvm) == 0:
		return "unknown", kvmNodes
	case yes == len(r.kvm):
		return "yes", kvmNodes
	case yes > 0:
		return "mixed", kvmNodes
	case no == len(r.kvm):
		return "no", kvmNodes
	}
	return "unknown", kvmNodes
}

func rsbxListed(v string, list []string) bool {
	for _, l := range list {
		if l == v {
			return true
		}
	}
	return false
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/sandbox-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.Segs[1])
		if reg == nil {
			return 0, nil, errNotFound()
		}
		r := s.rsbxRegionOf(reg.ID)
		fail := s.regionAdminSt().cfg.FailMessage
		r.kata.advance(fail)
		r.gvisor.advance(fail)
		var hv any
		if r.kata.hypervisors != nil {
			hv = r.kata.hypervisors
		}
		msg := func(m string) any {
			if m == "" {
				return nil
			}
			return m
		}
		return 200, map[string]any{
			"kata":    map[string]any{"status": r.kata.status, "message": msg(r.kata.message), "hypervisor": hv},
			"gvisor":  map[string]any{"status": r.gvisor.status, "message": msg(r.gvisor.message)},
			"mapping": r.mapping,
		}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/kata-shims", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.Segs[1])
		if reg == nil {
			return 0, nil, errNotFound()
		}
		r := s.rsbxRegionOf(reg.ID)
		state, kvmNodes := r.kvmState()
		shims := map[string]any{}
		for _, hv := range fakeKataHypervisors {
			available, reason := true, any(nil)
			switch {
			case state == "no":
				available, reason = false, "no_kvm"
			case hv == "fc" && !r.devmapper:
				available, reason = false, "no_devmapper"
			}
			shims[hv] = map[string]any{"label": strings.ToUpper(hv[:1]) + hv[1:], "runtime": "kata-" + hv,
				"category": "standard", "arches": []string{"amd64"}, "available": available, "reason": reason,
				"recommended": hv == "clh"}
		}
		// PHP sends an empty map as [].
		var nodes, checked any = []any{}, []any{}
		if len(r.kvm) > 0 {
			n, c := map[string]any{}, map[string]any{}
			for name, v := range r.kvm {
				if v == nil {
					n[name] = nil
					continue
				}
				n[name] = *v
				c[name] = "2026-10-14T09:00:00Z"
			}
			nodes = n
			if len(c) > 0 {
				checked = c
			}
		}
		probing := false
		if q.query("refresh") == "1" {
			r.rechecks++
			probing = true
		}
		if kvmNodes == nil {
			kvmNodes = []string{}
		}
		return 200, map[string]any{
			"shims": shims,
			"kvm":   map[string]any{"state": state, "nodes": nodes, "kvm_nodes": kvmNodes, "checked": checked, "probing": probing},
			"warning": "Installing Kata Containers restarts the container runtime on every target node (containerd, or the " +
				"k3s / RKE2 service that runs it).",
			"fc_warning": "Firecracker (fc) also sets up containerd's devmapper snapshotter on those nodes.",
			"devmapper":  map[string]any{"default_pool_gb": 20, "min_pool_gb": 10, "max_pool_gb": 1024},
		}, nil
	})

	handle("POST /knative-regions/{knativeRegion}/install-sandbox", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.Segs[1])
		if reg == nil {
			return 0, nil, errNotFound()
		}
		runtime := q.str("runtime")
		if runtime != "kata" && runtime != "gvisor" {
			return 0, nil, errValidation("runtime", "The selected runtime is invalid.")
		}
		r := s.rsbxRegionOf(reg.ID)
		rt := r.runtime(runtime)
		if rt.status == "installing" || rt.status == "uninstalling" {
			return 0, nil, &apiErr{status: 422, message: "An operation is already in progress for this region."}
		}
		hypervisors := []string{"clh"}
		if raw, ok := q.Body["hypervisors"].([]any); ok && len(raw) > 0 {
			hypervisors = nil
			for _, h := range raw {
				name, _ := h.(string)
				if !rsbxListed(name, fakeKataHypervisors) || rsbxListed(name, hypervisors) {
					return 0, nil, errValidation("hypervisors", fmt.Sprintf("The selected hypervisor %v is invalid.", h))
				}
				hypervisors = append(hypervisors, name)
			}
		}
		pool := 0
		if v, ok := q.Body["devmapper_pool_gb"].(float64); ok {
			if v < 10 || v > 1024 {
				return 0, nil, errValidation("devmapper_pool_gb", "The devmapper pool gb field must be between 10 and 1024.")
			}
			pool = int(v)
		}
		out := map[string]any{"message": fmt.Sprintf("Sandbox runtime '%s' installation queued.", runtime), "region": map[string]any{"id": reg.ID}}
		if runtime == "kata" {
			if state, _ := r.kvmState(); state == "no" {
				msg := "Kata Containers needs hardware virtualization (/dev/kvm), which none of the nodes has. Use gVisor or runc on this region."
				return 0, nil, &apiErr{status: 422, message: msg, errors: map[string][]string{"runtime": {msg}}}
			}
			if rsbxListed("fc", hypervisors) && !r.devmapper {
				msg := "Kata hypervisor 'fc' cannot run on this cluster: it needs containerd's devmapper snapshotter."
				return 0, nil, &apiErr{status: 422, message: msg, errors: map[string][]string{"hypervisors": {msg}}}
			}
			warning := "Installing Kata Containers restarts the container runtime on every target node."
			if rsbxListed("fc", hypervisors) {
				if pool == 0 {
					pool = 20
				}
				warning += fmt.Sprintf(" Thin-pool data size: %d GiB (sparse).", pool)
			}
			out["warning"] = warning
			rt.hypervisors = hypervisors
		}
		cfg := s.regionAdminSt().cfg
		rt.status, rt.message, rt.target = "installing", "Installation queued...", "ready"
		rt.pollsLeft, rt.fails = cfg.InstallPolls, cfg.FailInstall
		return 202, out, nil
	})

	handle("POST /knative-regions/{knativeRegion}/uninstall-sandbox", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.Segs[1])
		if reg == nil {
			return 0, nil, errNotFound()
		}
		runtime := q.str("runtime")
		if runtime != "kata" && runtime != "gvisor" {
			return 0, nil, errValidation("runtime", "The selected runtime is invalid.")
		}
		rt := s.rsbxRegionOf(reg.ID).runtime(runtime)
		if rt.status == "installing" || rt.status == "uninstalling" {
			return 0, nil, &apiErr{status: 422, message: "An operation is already in progress for this region."}
		}
		rt.status, rt.message, rt.target = "uninstalling", "Removal queued...", "not_installed"
		rt.pollsLeft, rt.fails = s.regionAdminSt().cfg.InstallPolls, false
		return 200, map[string]any{"message": fmt.Sprintf("Sandbox runtime '%s' removal queued.", runtime), "region": map[string]any{"id": reg.ID}}, nil
	})

	handle("PUT /knative-regions/{knativeRegion}/sandbox-mapping", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.Segs[1])
		if reg == nil {
			return 0, nil, errNotFound()
		}
		r := s.rsbxRegionOf(reg.ID)
		allowed := []string{"runc"}
		if r.kata.status == "ready" {
			for _, hv := range r.kata.hypervisors {
				allowed = append(allowed, "kata-"+hv)
			}
		}
		if r.gvisor.status == "ready" {
			allowed = append(allowed, "gvisor")
		}
		next := map[string]string{}
		for _, typ := range []string{"xaas", "faas", "caas", "build"} {
			key := "sandbox_" + typ + "_runtime"
			v := q.str(key)
			if v == "" {
				return 0, nil, errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field is required.")
			}
			if !rsbxListed(v, allowed) {
				return 0, nil, errValidation(key, "The selected "+strings.ReplaceAll(key, "_", " ")+" is invalid.")
			}
			next[typ] = v
		}
		r.mapping = next
		return 200, map[string]any{"message": "Sandbox mapping updated.", "region": map[string]any{"id": reg.ID}}, nil
	})
}
