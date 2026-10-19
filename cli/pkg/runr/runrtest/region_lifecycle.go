package runrtest

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The lifecycle of a region in the fake (KnativeRegionController, KnativeInstallController,
// KnativeUninstallController, KnativeNodeController, KnativeRedisController, RegistryController,
// RegistrySetupController, RegionMigrateController, KnativeFunctionTlsController, the Skupper
// routes, RegionCapacityController, RegionDemoController, WasmController). Operations run in the
// background: they end after RegionAdminConfig.InstallPolls reads of a status (the region, the
// component status, the Redis, Wasm or migration status, the registry setup), in error with
// RegionAdminConfig.FailInstall.

// rlcOp is a background operation: apply sets its final state (failed: the error).
type rlcOp struct {
	pollsLeft int
	fails     bool
	apply     func(failed bool, message string)
}

type rlcRegion struct {
	d          runr.RegionDetail
	kubeconfig string
	nodes      []runr.RegionNode
	ops        map[string]*rlcOp
	// redisPassword, tlsKey and migrationKubeconfig are what the last change sent.
	redisPassword       string
	tlsKey              string
	migrationKubeconfig string
	removeTraefik       bool
	// adoptable: a Knative operator / Serving already running on the cluster (detect adopts it).
	adoptOperator, adoptServing bool
	skupper                     bool
	site                        *runr.SkupperSite
	wasmShims                   []string
	wasmNodes                   []string
	wasmMessage                 string
	// domainMigrating: the region moves to a new base domain (SetRegionDomainMigrating)
	domainMigrating bool
}

// SetRegionDomainMigrating marks a region as moving to a new base domain.
func (s *Server) SetRegionDomainMigrating(regionName string, migrating bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if reg := s.regionByName(regionName); reg != nil {
		s.rlcRegionOf(reg).domainMigrating = migrating
	}
}

type rlcSetup struct {
	status, phase, message string
	regionID               string
	in                     map[string]any
	pollsLeft              int
	fails                  bool
}

type rlcState struct {
	regions map[string]*rlcRegion
	setups  map[string]*rlcSetup
	seq     int
}

func (s *Server) rlc() *rlcState {
	return featureState(s, "region-lifecycle", func() *rlcState {
		return &rlcState{regions: map[string]*rlcRegion{}, setups: map[string]*rlcSetup{}}
	})
}

const fakeKubeconfigPrefix = "apiVersion: v1\nkind: Config\n"

// fakeKubeconfig is the kubeconfig the fake keeps for a region it did not create.
func fakeKubeconfig(name string) string {
	return fakeKubeconfigPrefix + "clusters:\n- name: " + name + "\n  cluster: {server: https://" + name + ".k8s.example:6443}\n" +
		"users:\n- name: runr\n  user: {token: kc-secret-" + name + "}\n"
}

func (s *Server) rlcRegionOf(reg *runr.Region) *rlcRegion {
	st := s.rlc()
	if r, ok := st.regions[reg.ID]; ok {
		return r
	}
	ready := reg.KnativeStatus == runr.KnativeReady
	r := &rlcRegion{
		kubeconfig: fakeKubeconfig(reg.Name),
		ops:        map[string]*rlcOp{},
		nodes: []runr.RegionNode{
			{Name: "worker-1", Status: "Ready", CPUm: 4000, MemoryMB: 8192, AvailableCPUm: 3500, AvailableMemoryMB: 7168},
			{Name: "worker-2", Status: "Ready", CPUm: 4000, MemoryMB: 8192, AvailableCPUm: 3500, AvailableMemoryMB: 7168},
		},
		skupper: true,
	}
	d := &r.d
	d.HasKubeconfig = true
	d.ServerURL = "https://" + reg.Name + ".k8s.example:6443"
	d.ClusterStatus = "healthy"
	d.FaaSCPUOvercommit, d.FaaSMemoryOvercommit = 3, 1.5
	d.SelectedWorkers = []string{}
	d.KnativeSettings = map[string]any{"request_timeout": 300}
	d.OperatorStatus, d.ServingStatus = runr.KnativeNotInstalled, runr.KnativeNotInstalled
	d.OperatorNamespace, d.ServingNamespace = "knative-operator", "knative-serving"
	if ready {
		d.OperatorStatus, d.ServingStatus, d.HasServing = runr.KnativeReady, runr.KnativeReady, true
	}
	if reg.HasRedis {
		d.RedisMode, d.RedisStatus, d.RedisAddress = "managed", runr.KnativeReady, "redis.runr-system.svc:6379"
	}
	d.RegistryNamespace = "runr-registry"
	d.WasmStatus = runr.KnativeNotInstalled
	d.ExternalIP = "203.0.113.10"
	st.regions[reg.ID] = r
	return r
}

// regionAnswer is the region resource of the fake.
func (s *Server) rlcResource(reg *runr.Region, r *rlcRegion) runr.RegionDetail {
	d := r.d
	d.Region = *reg
	d.WorkerCount = len(d.SelectedWorkers)
	return d
}

// rlcAdvance moves the region's background operations on by one status read.
func (s *Server) rlcAdvance(r *rlcRegion) {
	keys := make([]string, 0, len(r.ops))
	for k := range r.ops {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		op := r.ops[k]
		if op.pollsLeft > 0 {
			op.pollsLeft--
			continue
		}
		delete(r.ops, k)
		op.apply(op.fails, "["+time.Now().UTC().Format("15:04:05")+"] ERROR: "+s.regionAdminSt().cfg.FailMessage)
	}
}

// rlcStart queues a background operation of kind (one per kind).
func (s *Server) rlcStart(r *rlcRegion, kind string, apply func(failed bool, message string)) {
	cfg := s.regionAdminSt().cfg
	r.ops[kind] = &rlcOp{pollsLeft: cfg.InstallPolls, fails: cfg.FailInstall, apply: apply}
}

// rlcFind resolves the {knativeRegion} segment.
func (s *Server) rlcFind(q *featureReq) (*runr.Region, *rlcRegion, *apiErr) {
	if len(q.Segs) < 2 {
		return nil, nil, errNotFound()
	}
	reg := s.regionByID(q.Segs[1])
	if reg == nil {
		return nil, nil, errNotFound()
	}
	return reg, s.rlcRegionOf(reg), nil
}

func (s *Server) rlcMutable(reg *runr.Region, r *rlcRegion) *apiErr {
	if reg.IsDemo {
		return &apiErr{status: 422, message: "The demo region cannot be changed."}
	}
	if r.kubeconfig == "" {
		return &apiErr{status: 422, message: "The region has no kubeconfig."}
	}
	return nil
}

func rlcCheckKubeconfig(field string, v any) (string, *apiErr) {
	k, _ := v.(string)
	if strings.TrimSpace(k) == "" {
		return "", errValidation(field, "The "+field+" field is required.")
	}
	if !strings.Contains(k, "kind: Config") {
		return "", errValidation(field, "Cannot connect to the cluster: the kubeconfig has no cluster.")
	}
	return k, nil
}

var rlcNameRe = regexp.MustCompile(`^[a-z][a-z0-9-]*[a-z0-9]$`)

func (s *Server) rlcCheckName(v any) (string, *apiErr) {
	name, _ := v.(string)
	if !rlcNameRe.MatchString(name) || len(name) > 63 {
		return "", errValidation("name", "The name field format is invalid.")
	}
	if s.regionByName(name) != nil {
		return "", errValidation("name", "The name has already been taken.")
	}
	return name, nil
}

func strList(v any) []string {
	out := []string{}
	list, _ := v.([]any)
	for _, x := range list {
		if str, ok := x.(string); ok {
			out = append(out, str)
		}
	}
	return out
}

// rlcNewRegion adds a region created through the API.
func (s *Server) rlcNewRegion(name, kubeconfig string, nodes []string, active bool) (*runr.Region, *rlcRegion) {
	status := "active"
	if !active {
		status = "inactive"
	}
	reg := &runr.Region{ID: s.newID(), Name: name, IsActive: active, RegionStatus: status, RegionMode: "managed",
		KnativeStatus: runr.KnativeNotInstalled, ProjectNamespace: name}
	s.regions = append(s.regions, reg)
	r := s.rlcRegionOf(reg)
	r.kubeconfig = kubeconfig
	r.d.SelectedWorkers = append([]string{}, nodes...)
	s.rlcTotals(reg, r)
	return reg, r
}

// rlcTotals sets the capacity of a region from its selected nodes (all nodes when none).
func (s *Server) rlcTotals(reg *runr.Region, r *rlcRegion) {
	cpu, mem := 0, 0
	for i := range r.nodes {
		n := &r.nodes[i]
		n.IsSelected = false
		for _, w := range r.d.SelectedWorkers {
			n.IsSelected = n.IsSelected || w == n.Name
		}
		if len(r.d.SelectedWorkers) == 0 || n.IsSelected {
			cpu += n.AvailableCPUm
			mem += n.AvailableMemoryMB
		}
	}
	reg.TotalCPUm, reg.TotalMemoryMB = cpu, mem
}

// rlcRedis queues the install of the region's Redis.
func (s *Server) rlcRedis(reg *runr.Region, r *rlcRegion, mode, address string) {
	r.d.RedisMode, r.d.RedisStatus, r.d.RedisStatusMessage = mode, runr.KnativeInstalling, ""
	if mode == "managed" {
		address = "redis.runr-system.svc:6379"
	}
	r.d.RedisAddress = address
	reg.HasRedis = false
	s.rlcStart(r, "redis", func(failed bool, message string) {
		if failed {
			r.d.RedisStatus, r.d.RedisStatusMessage = runr.KnativeError, message
			return
		}
		r.d.RedisStatus = runr.KnativeReady
		reg.HasRedis = true
	})
}

// SeedRegionAdoptable makes the cluster of a region run a Knative operator / Serving that
// `detect-components` adopts.
func (s *Server) SeedRegionAdoptable(regionName string, operator, serving bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rlcRegionOf(reg)
	r.adoptOperator, r.adoptServing = operator, serving
}

// SetRegionSkupper sets whether the cluster of a region runs Skupper v2.
func (s *Server) SetRegionSkupper(regionName string, available bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	s.rlcRegionOf(reg).skupper = available
}

// SetRegionKnative sets the install state of a region's Knative operator and Serving.
func (s *Server) SetRegionKnative(regionName, operator, serving string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rlcRegionOf(reg)
	r.d.OperatorStatus, r.d.ServingStatus = operator, serving
	r.d.HasServing = serving == runr.KnativeReady
	reg.KnativeStatus = runr.KnativeNotInstalled
	if operator == runr.KnativeReady && serving == runr.KnativeReady {
		reg.KnativeStatus = runr.KnativeReady
	}
}

// RegionLifecycle returns what the fake holds for a region beyond its resource: the
// kubeconfig, the Redis password, the TLS key and the kubeconfig of a migration, as last sent.
type RegionLifecycle struct {
	Detail              runr.RegionDetail
	Kubeconfig          string
	RedisPassword       string
	TLSKey              string
	MigrationKubeconfig string
	RemoveTraefik       bool
	WasmShims           []string
	WasmNodes           []string
}

// RegionLifecycleOf returns the fake's state of a region (by name); ok is false for none.
func (s *Server) RegionLifecycleOf(regionName string) (RegionLifecycle, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		return RegionLifecycle{}, false
	}
	r := s.rlcRegionOf(reg)
	return RegionLifecycle{Detail: s.rlcResource(reg, r), Kubeconfig: r.kubeconfig, RedisPassword: r.redisPassword,
		TLSKey: r.tlsKey, MigrationKubeconfig: r.migrationKubeconfig, RemoveTraefik: r.removeTraefik,
		WasmShims: append([]string(nil), r.wasmShims...), WasmNodes: append([]string(nil), r.wasmNodes...)}, true
}

func (s *Server) rlcOperation(reg *runr.Region, r *rlcRegion, message string) map[string]any {
	return map[string]any{"message": message, "region": s.rlcResource(reg, r)}
}

func init() {
	// The resource, create, change, delete
	handle("GET /knative-regions/{region}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, map[string]any{"data": s.rlcResource(reg, r)}, nil
	})
	handle("POST /knative-regions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		name, err := s.rlcCheckName(q.Body["name"])
		if err != nil {
			return 0, nil, err
		}
		kc, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"])
		if err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["nodes"].([]any); !ok {
			return 0, nil, errValidation("nodes", "The nodes field must be present.")
		}
		active := true
		if v, ok := q.Body["is_active"].(bool); ok {
			active = v
		}
		mode := q.str("redis_mode")
		if mode == "" {
			mode = "managed"
		}
		if (mode == "in_cluster" || mode == "external") && q.str("redis_address") == "" {
			return 0, nil, errValidation("redis_address", "The redis address field is required when redis mode is "+mode+".")
		}
		reg, r := s.rlcNewRegion(name, kc, strList(q.Body["nodes"]), active)
		if mode != "none" {
			s.rlcRedis(reg, r, mode, q.str("redis_address"))
			r.redisPassword = q.str("redis_password")
		}
		return 201, s.rlcResource(reg, r), nil
	})
	handle("PUT /knative-regions/{region}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if v, ok := q.Body["kubeconfig"]; ok {
			kc, err := rlcCheckKubeconfig("kubeconfig", v)
			if err != nil {
				return 0, nil, err
			}
			r.kubeconfig = kc
			r.d.HasKubeconfig = true
		}
		if v, ok := q.Body["region_status"]; ok {
			st, _ := v.(string)
			switch st {
			case "active", "inactive", "maintenance":
			default:
				return 0, nil, errValidation("region_status", "The selected region status is invalid.")
			}
			reg.RegionStatus, reg.IsActive = st, st == "active"
		}
		return 200, s.rlcResource(reg, r), nil
	})
	handle("DELETE /knative-regions/{region}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, _, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		for i, x := range s.regions {
			if x == reg {
				s.regions = append(s.regions[:i], s.regions[i+1:]...)
				break
			}
		}
		delete(s.rlc().regions, reg.ID)
		return 204, nil, nil
	})
	handle("GET /knative-regions/{knativeRegion}/kubeconfig", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.kubeconfig == "" {
			return 0, nil, &apiErr{status: 404, message: "The region has no kubeconfig."}
		}
		return 200, rawAnswer{contentType: "application/x-yaml", filename: reg.Name + "-kubeconfig.yaml", data: []byte(r.kubeconfig)}, nil
	})
	handle("PUT /knative-regions/{knativeRegion}/settings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if v, ok := q.Body["request_timeout"]; ok {
			n, isNum := v.(float64)
			if !isNum || n != float64(int(n)) || n < 1 || n > 3600 {
				return 0, nil, errValidation("request_timeout", "The request timeout field must be between 1 and 3600.")
			}
		}
		if v, ok := q.Body["agent_ws_url"].(string); ok && v != "" && !regexp.MustCompile(`(?i)^(wss?|https?)://\S+$`).MatchString(v) {
			return 0, nil, errValidation("agent_ws_url", "The agent ws url field format is invalid.")
		}
		if v, ok := q.Body["ingress_trusted_ips"]; ok && v != nil {
			if _, isList := v.([]any); !isList {
				return 0, nil, errValidation("ingress_trusted_ips", "The ingress trusted ips field must be an array.")
			}
		}
		for k, v := range q.Body {
			r.d.KnativeSettings[k] = v
		}
		return 200, map[string]any{"message": "Settings updated.", "settings": r.d.KnativeSettings, "region": s.rlcResource(reg, r)}, nil
	})

	// Checks of a cluster before it becomes a region
	handle("POST /knative-regions/validate-kubeconfig", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"valid": true, "version": "1.31", "kubeconfig": nil}, nil
	})
	handle("POST /knative-regions/preview-nodes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"nodes": []runr.RegionNode{
			{Name: "node-a", Status: "Ready", CPUm: 8000, MemoryMB: 16384, AvailableCPUm: 7500, AvailableMemoryMB: 15360},
			{Name: "node-b", Status: "NotReady", CPUm: 8000, MemoryMB: 16384, AvailableCPUm: 7500, AvailableMemoryMB: 15360},
		}}, nil
	})
	handle("POST /knative-regions/preview-storage-classes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"storageClasses": []map[string]any{{"name": "local-path", "is_default": true}, {"name": "fast-ssd", "is_default": false}}}, nil
	})
	handle("POST /knative-regions/check-namespace-preview", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		name := q.str("name")
		exists := name == "default" || name == "kube-system" || s.regionByName(name) != nil
		return 200, map[string]any{"exists": exists}, nil
	})

	// Cluster status and the Knative components
	handle("GET /knative-regions/{knativeRegion}/knative-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		if r.kubeconfig == "" {
			return 0, nil, &apiErr{status: 422, message: "The region has no kubeconfig."}
		}
		status, msg := reg.KnativeStatus, "Knative is not installed."
		switch status {
		case runr.KnativeReady:
			msg = "Knative Serving and Traefik are ready."
		case runr.KnativeInstalling, runr.KnativeUninstalling, runr.KnativeError:
			msg = r.d.KnativeStatusMessage
		}
		return 200, map[string]any{"status": status, "message": msg, "details": map[string]any{"serving_ready": status == runr.KnativeReady},
			"region": s.rlcResource(reg, r)}, nil
	})
	handle("GET /knative-regions/{knativeRegion}/component-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, map[string]any{
			"operator": map[string]any{"status": r.d.OperatorStatus, "message": r.d.OperatorStatusMessage, "namespace": r.d.OperatorNamespace},
			"serving": map[string]any{"status": r.d.ServingStatus, "message": r.d.ServingStatusMessage, "namespace": r.d.ServingNamespace,
				"ingress_class": "traefik", "version": nil},
			"region": s.rlcResource(reg, r),
		}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/install-knative", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if reg.KnativeStatus == runr.KnativeInstalling {
			return 0, nil, &apiErr{status: 409, message: "An installation is already in progress."}
		}
		reg.KnativeStatus, r.d.KnativeStatusMessage = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "knative", func(failed bool, message string) {
			if failed {
				reg.KnativeStatus, r.d.KnativeStatusMessage = runr.KnativeError, r.d.KnativeStatusMessage+"\n"+message
				return
			}
			reg.KnativeStatus, r.d.KnativeStatusMessage = runr.KnativeReady, "Done — Knative Serving and Traefik are ready."
			r.d.OperatorStatus, r.d.ServingStatus, r.d.HasServing = runr.KnativeReady, runr.KnativeReady, true
		})
		return 200, s.rlcOperation(reg, r, "Installation started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/install-operator", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if r.d.OperatorStatus == runr.KnativeInstalling {
			return 0, nil, &apiErr{status: 409, message: "An installation is already in progress."}
		}
		if r.d.OperatorStatus == runr.KnativeReady {
			return 200, s.rlcOperation(reg, r, "Operator is already installed."), nil
		}
		if ns := q.str("namespace"); ns != "" {
			r.d.OperatorNamespace = ns
		}
		if r.adoptOperator {
			r.d.OperatorStatus, r.d.OperatorStatusMessage = runr.KnativeReady, "Adopted from existing cluster installation"
			out := s.rlcOperation(reg, r, "Existing operator adopted.")
			out["adopted"] = true
			return 200, out, nil
		}
		r.d.OperatorStatus, r.d.OperatorStatusMessage = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "operator", func(failed bool, message string) {
			if failed {
				r.d.OperatorStatus, r.d.OperatorStatusMessage = runr.KnativeError, message
				return
			}
			r.d.OperatorStatus, r.d.OperatorStatusMessage = runr.KnativeReady, "Operator installed."
		})
		return 200, s.rlcOperation(reg, r, "Operator installation started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/install-serving", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if r.d.OperatorStatus != runr.KnativeReady {
			return 0, nil, &apiErr{status: 422, message: "Install the Knative operator first."}
		}
		if r.d.ServingStatus == runr.KnativeInstalling {
			return 0, nil, &apiErr{status: 409, message: "An installation is already in progress."}
		}
		if r.d.ServingStatus == runr.KnativeReady {
			return 200, s.rlcOperation(reg, r, "Serving is already installed."), nil
		}
		if ns := q.str("namespace"); ns != "" {
			r.d.ServingNamespace = ns
		}
		r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "serving", func(failed bool, message string) {
			if failed {
				r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeError, message
				return
			}
			r.d.ServingStatus, r.d.ServingStatusMessage, r.d.HasServing = runr.KnativeReady, "Serving installed.", true
			reg.KnativeStatus = runr.KnativeReady
		})
		return 200, s.rlcOperation(reg, r, "Serving installation started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/upgrade-serving", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		version := q.str("version")
		if version == "" {
			return 0, nil, errValidation("version", "The version field is required.")
		}
		if r.d.ServingStatus == runr.KnativeInstalling {
			return 0, nil, &apiErr{status: 409, message: "An installation is already in progress."}
		}
		if r.d.ServingStatus != runr.KnativeReady {
			return 0, nil, &apiErr{status: 422, message: "Knative Serving has to be installed and ready to be upgraded."}
		}
		r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeInstalling, "Upgrade to "+version+" queued..."
		s.rlcStart(r, "serving", func(failed bool, message string) {
			if failed {
				r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeError, message
				return
			}
			r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeReady, "Knative Serving upgraded to "+version+"."
		})
		return 200, s.rlcOperation(reg, r, "Knative Serving upgrade to "+version+" started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/uninstall-knative", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if reg.KnativeStatus == runr.KnativeUninstalling {
			return 0, nil, &apiErr{status: 409, message: "An uninstall is already in progress."}
		}
		r.removeTraefik, _ = q.Body["remove_traefik"].(bool)
		reg.KnativeStatus, r.d.KnativeStatusMessage = runr.KnativeUninstalling, "Uninstall queued..."
		s.rlcStart(r, "knative", func(failed bool, message string) {
			if failed {
				reg.KnativeStatus, r.d.KnativeStatusMessage = runr.KnativeError, message
				return
			}
			reg.KnativeStatus = runr.KnativeNotInstalled
			r.d.OperatorStatus, r.d.ServingStatus, r.d.HasServing = runr.KnativeNotInstalled, runr.KnativeNotInstalled, false
		})
		return 200, s.rlcOperation(reg, r, "Uninstall started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/uninstall-operator", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if r.d.ServingStatus != runr.KnativeNotInstalled && r.d.ServingStatus != runr.KnativeError {
			return 0, nil, &apiErr{status: 422, message: "Uninstall Knative Serving first."}
		}
		r.d.OperatorStatus = runr.KnativeUninstalling
		s.rlcStart(r, "operator", func(failed bool, message string) {
			if failed {
				r.d.OperatorStatus, r.d.OperatorStatusMessage = runr.KnativeError, message
				return
			}
			r.d.OperatorStatus = runr.KnativeNotInstalled
			reg.KnativeStatus = runr.KnativeNotInstalled
		})
		return 200, s.rlcOperation(reg, r, "Operator uninstall started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/uninstall-serving", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		r.removeTraefik, _ = q.Body["remove_traefik"].(bool)
		r.d.ServingStatus = runr.KnativeUninstalling
		s.rlcStart(r, "serving", func(failed bool, message string) {
			if failed {
				r.d.ServingStatus, r.d.ServingStatusMessage = runr.KnativeError, message
				return
			}
			r.d.ServingStatus, r.d.HasServing = runr.KnativeNotInstalled, false
			reg.KnativeStatus = runr.KnativeNotInstalled
		})
		return 200, s.rlcOperation(reg, r, "Serving uninstall started."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/detect-components", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.kubeconfig == "" {
			return 0, nil, &apiErr{status: 422, message: "The region has no kubeconfig."}
		}
		out := map[string]any{"operator": nil, "serving": nil}
		if r.adoptOperator && r.d.OperatorStatus != runr.KnativeReady {
			r.d.OperatorStatus = runr.KnativeReady
			out["operator"] = map[string]any{"adopted": true, "namespace": r.d.OperatorNamespace}
		}
		if r.adoptServing && r.d.ServingStatus != runr.KnativeReady {
			r.d.ServingStatus, r.d.HasServing = runr.KnativeReady, true
			out["serving"] = map[string]any{"adopted": true, "namespace": r.d.ServingNamespace}
		}
		if r.d.OperatorStatus == runr.KnativeReady && r.d.ServingStatus == runr.KnativeReady {
			reg.KnativeStatus = runr.KnativeReady
		}
		out["region"] = s.rlcResource(reg, r)
		return 200, out, nil
	})
	handle("GET /knative-regions/{knativeRegion}/check-namespace", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, _, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		name := q.query("name")
		if name == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		exists := name == "default" || name == "kube-system" || name == reg.ProjectNamespace || name == "knative-serving"
		return 200, map[string]any{"exists": exists}, nil
	})
	handle("GET /knative-regions/{knativeRegion}/compatible-versions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, _, err := s.rlcFind(q); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"versions": []string{"1.19", "1.18", "1.17"}}, nil
	})

	// Worker nodes
	handle("GET /knative-regions/{knativeRegion}/nodes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.kubeconfig == "" {
			return 0, nil, &apiErr{status: 422, message: "The region has no kubeconfig."}
		}
		out := make([]map[string]any, 0, len(r.nodes))
		for _, n := range r.nodes {
			out = append(out, map[string]any{"name": n.Name, "status": n.Status, "cpu_m": n.CPUm, "memory_mb": n.MemoryMB,
				"available_cpu_m": n.AvailableCPUm, "available_memory_mb": n.AvailableMemoryMB, "is_selected": n.IsSelected,
				"labels": []any{}})
		}
		return 200, map[string]any{"nodes": out}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/workers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["nodes"].([]any); !ok {
			return 0, nil, errValidation("nodes", "The nodes field is required.")
		}
		r.d.SelectedWorkers = strList(q.Body["nodes"])
		s.rlcTotals(reg, r)
		out := s.rlcOperation(reg, r, "Worker nodes updated.")
		out["total_cpu_m"], out["total_memory_mb"] = reg.TotalCPUm, reg.TotalMemoryMB
		return 200, out, nil
	})

	// Queue Redis
	handle("GET /knative-regions/{knativeRegion}/redis-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, map[string]any{"redis_mode": nullStr(r.d.RedisMode), "knative_redis_status": nullStr(r.d.RedisStatus),
			"redis_address": nullStr(r.d.RedisAddress), "knative_redis_status_message": nullStr(r.d.RedisStatusMessage),
			"has_redis": reg.HasRedis}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/test-redis", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.kubeconfig == "" {
			return 0, nil, &apiErr{status: 422, message: "The region has no kubeconfig."}
		}
		addr := q.str("address")
		if addr == "" {
			return 0, nil, errValidation("address", "The address field is required.")
		}
		if strings.Contains(addr, "unreachable") {
			return 200, map[string]any{"reachable": false, "message": "Redis connection failed (pod phase: Failed)"}, nil
		}
		return 200, map[string]any{"reachable": true, "message": "PONG"}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/redis", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		mode := q.str("mode")
		switch mode {
		case "managed":
		case "in_cluster", "external":
			if q.str("address") == "" {
				return 0, nil, errValidation("address", "The address field is required unless mode is managed.")
			}
		default:
			return 0, nil, errValidation("mode", "The selected mode is invalid.")
		}
		s.rlcRedis(reg, r, mode, q.str("address"))
		r.redisPassword = q.str("password")
		return 202, s.rlcOperation(reg, r, "Redis installation queued."), nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/redis", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if reg.IsDemo {
			return 0, nil, &apiErr{status: 422, message: "The demo region cannot be changed."}
		}
		r.d.RedisStatus = runr.KnativeUninstalling
		reg.HasRedis = false
		s.rlcStart(r, "redis", func(failed bool, message string) {
			if failed {
				r.d.RedisStatus, r.d.RedisStatusMessage = runr.KnativeError, message
				return
			}
			r.d.RedisMode, r.d.RedisStatus, r.d.RedisAddress, r.d.RedisStatusMessage = "", "", "", ""
			r.redisPassword = ""
		})
		return 200, s.rlcOperation(reg, r, "Redis removal queued."), nil
	})

	// Registry
	handle("GET /knative-regions/{knativeRegion}/registry-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"registry_mode": nullStr(r.d.RegistryMode), "registry_address": nullStr(r.d.RegistryAddress),
			"registry_status": nullStr(r.d.RegistryStatus), "registry_status_message": nullStr(r.d.RegistryStatusMessage),
			"has_registry": r.d.HasRegistry}, nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/registry", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if reg.IsDemo {
			return 0, nil, &apiErr{status: 403, message: "The demo region's registry cannot be changed."}
		}
		r.d.RegistryMode, r.d.RegistryAddress, r.d.RegistryStorageType, r.d.RegistryStatus, r.d.RegistryStatusMessage = "", "", "", "", ""
		r.d.HasRegistry = false
		return 200, s.rlcResource(reg, r), nil
	})
	handle("POST /knative-regions/{knativeRegion}/registry", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if r.d.RegistryMode == "internal" && r.d.RegistryStatus == runr.KnativeReady {
			return 0, nil, &apiErr{status: 409, message: "The region runs an internal registry: remove it first."}
		}
		mode, address := q.str("registry_mode"), "10.0.0.5:5000"
		switch mode {
		case "internal":
			if q.str("storage_type") != "pvc" && q.str("storage_type") != "s3" {
				return 0, nil, errValidation("storage_type", "The storage type field is required when registry mode is internal.")
			}
		case "external":
			if address = q.str("external_address"); address == "" {
				return 0, nil, errValidation("external_address", "The external address field is required when registry mode is external.")
			}
		default:
			return 0, nil, errValidation("registry_mode", "The selected registry mode is invalid.")
		}
		r.d.RegistryStatus, r.d.RegistryStatusMessage = runr.KnativeInstalling, "Registry setup queued..."
		s.rlcStart(r, "registry", func(failed bool, message string) {
			if failed {
				r.d.RegistryStatus, r.d.RegistryStatusMessage = runr.KnativeError, message
				return
			}
			r.d.RegistryMode, r.d.RegistryAddress, r.d.RegistryStatus, r.d.RegistryStatusMessage = mode, address, runr.KnativeReady, ""
			r.d.HasRegistry = true
		})
		return 202, s.rlcOperation(reg, r, "Registry setup started."), nil
	})
	setup := func(s *Server, q *featureReq, token string) (int, any, *apiErr) {
		if _, err := s.rlcCheckName(q.Body["name"]); err != nil {
			return 0, nil, err
		}
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["nodes"].([]any); !ok {
			return 0, nil, errValidation("nodes", "The nodes field must be present.")
		}
		switch q.str("registry_mode") {
		case "internal":
			switch q.str("storage_type") {
			case "pvc":
				pvc, _ := q.Body["pvc_config"].(map[string]any)
				if pvc["storageClass"] == nil || pvc["size"] == nil {
					return 0, nil, errValidation("pvc_config.storageClass", "The pvc config.storage class field is required when storage type is pvc.")
				}
			case "s3":
				s3, _ := q.Body["s3_config"].(map[string]any)
				for _, k := range []string{"endpoint", "bucket", "region", "access_key", "secret_key"} {
					if s3[k] == nil {
						return 0, nil, errValidation("s3_config."+k, "The s3 config."+k+" field is required when storage type is s3.")
					}
				}
			default:
				return 0, nil, errValidation("storage_type", "The storage type field is required when registry mode is internal.")
			}
		case "external":
			if q.str("external_address") == "" {
				return 0, nil, errValidation("external_address", "The external address field is required when registry mode is external.")
			}
		default:
			return 0, nil, errValidation("registry_mode", "The selected registry mode is invalid.")
		}
		st := s.rlc()
		if token == "" {
			st.seq++
			token = fmt.Sprintf("8c0f6a1e-0000-4000-8000-%012d", st.seq)
		}
		cfg := s.regionAdminSt().cfg
		st.setups[token] = &rlcSetup{status: runr.RegistrySetupPending, phase: "queued", in: q.Body, pollsLeft: cfg.InstallPolls,
			fails: cfg.FailInstall}
		return 202, map[string]any{"setup_token": token, "setup_id": fmt.Sprintf("%032x", st.seq)}, nil
	}
	handle("POST /registry-setup/start", func(s *Server, q *featureReq) (int, any, *apiErr) { return setup(s, q, "") })
	handle("POST /registry-setup/{token}/retry", func(s *Server, q *featureReq) (int, any, *apiErr) {
		cur := s.rlc().setups[q.Segs[1]]
		if cur == nil || cur.status != runr.RegistrySetupError {
			return 0, nil, &apiErr{status: 422, message: "Only a failed setup can be retried."}
		}
		return setup(s, q, q.Segs[1])
	})
	handle("GET /registry-setup/{token}/status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		cur := s.rlc().setups[q.Segs[1]]
		if cur == nil {
			return 0, nil, &apiErr{status: 404, message: "The setup token has expired."}
		}
		if cur.status == runr.RegistrySetupPending {
			if cur.pollsLeft > 0 {
				cur.pollsLeft--
				cur.phase = "installing_registry"
			} else if cur.fails {
				cur.status, cur.phase, cur.message = runr.RegistrySetupError, "installing_registry", s.regionAdminSt().cfg.FailMessage
			} else {
				in := cur.in
				reg, r := s.rlcNewRegion(in["name"].(string), in["kubeconfig"].(string), strList(in["nodes"]), true)
				mode, _ := in["registry_mode"].(string)
				r.d.RegistryMode, r.d.RegistryStatus, r.d.HasRegistry = mode, runr.KnativeReady, true
				if mode == "external" {
					r.d.RegistryAddress, _ = in["external_address"].(string)
				} else {
					r.d.RegistryAddress = "zot.runr-registry.svc:5000"
					r.d.RegistryStorageType, _ = in["storage_type"].(string)
				}
				redis, _ := in["redis_mode"].(string)
				if redis == "" {
					redis = "managed"
				}
				if redis != "none" {
					addr, _ := in["redis_address"].(string)
					s.rlcRedis(reg, r, redis, addr)
				}
				cur.status, cur.phase, cur.message, cur.regionID = runr.RegistrySetupReady, "done", "Registry and region created successfully.", reg.ID
			}
		}
		out := map[string]any{"status": cur.status, "phase": cur.phase, "message": cur.message, "region_id": nil, "address": nil}
		if cur.regionID != "" {
			out["region_id"] = cur.regionID
		}
		return 200, out, nil
	})
	handle("POST /registry-setup/test-pvc", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		if q.str("storageClass") == "missing" {
			return 200, map[string]any{"success": false, "message": "PVC provisioning failed: StorageClass 'missing' did not provision a volume within 60s"}, nil
		}
		return 200, map[string]any{"success": true, "message": "PVC provisioning successful"}, nil
	})
	handle("POST /registry-setup/test-s3", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		s3, _ := q.Body["s3_config"].(map[string]any)
		for _, k := range []string{"endpoint", "bucket", "region", "access_key", "secret_key"} {
			if v, _ := s3[k].(string); v == "" {
				return 0, nil, errValidation("s3_config."+k, "The s3 config."+k+" field is required.")
			}
		}
		return 200, map[string]any{"success": true, "message": "S3 connection successful"}, nil
	})
	handle("POST /registry-setup/test-external", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"]); err != nil {
			return 0, nil, err
		}
		if q.str("url") == "" {
			return 0, nil, errValidation("url", "The url field is required.")
		}
		return 200, map[string]any{"success": true, "message": "Registry connection successful"}, nil
	})

	// Migration
	handle("POST /knative-regions/{knativeRegion}/migrate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		kc, err := rlcCheckKubeconfig("kubeconfig", q.Body["kubeconfig"])
		if err != nil {
			return 0, nil, err
		}
		if r.d.IsMigrating {
			return 0, nil, &apiErr{status: 422, message: "A migration is already in progress."}
		}
		r.migrationKubeconfig = kc
		r.d.MigrationStatus, r.d.MigrationStartedAt, r.d.IsMigrating = runr.MigrationPending, nowRFC3339(), true
		s.rlcStart(r, "migration", func(failed bool, message string) {
			if failed {
				r.d.MigrationStatus, r.d.IsMigrating = runr.MigrationFailed, false
				return
			}
			r.d.MigrationStatus, r.d.MigrationOldIP, r.d.ExternalIP = runr.MigrationDNSCheck, r.d.ExternalIP, "198.51.100.20"
		})
		return 200, map[string]any{"message": "Migration started.", "status": "pending"}, nil
	})
	handle("GET /knative-regions/{knativeRegion}/migration-status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, map[string]any{"migration_status": nullStr(r.d.MigrationStatus), "migration_started_at": nullStr(r.d.MigrationStartedAt),
			"migration_old_ip": nullStr(r.d.MigrationOldIP), "current_ip": nullStr(r.d.ExternalIP), "is_migrating": r.d.IsMigrating}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/complete-migration", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.d.MigrationStatus != runr.MigrationDNSCheck {
			return 0, nil, &apiErr{status: 422, message: "The migration is not waiting for DNS."}
		}
		r.d.MigrationStatus, r.d.IsMigrating, r.d.MigrationOldIP = runr.MigrationCompleted, false, ""
		r.kubeconfig = r.migrationKubeconfig
		return 200, map[string]any{"message": "Migration completed."}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/rollback-migration", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.d.MigrationStatus != runr.MigrationDNSCheck && r.d.MigrationStatus != runr.MigrationFailed {
			return 0, nil, &apiErr{status: 422, message: "This migration cannot be rolled back."}
		}
		if r.d.MigrationOldIP != "" {
			r.d.ExternalIP = r.d.MigrationOldIP
		}
		r.d.MigrationStatus, r.d.IsMigrating, r.d.MigrationOldIP, r.d.MigrationStartedAt = "", false, "", ""
		return 200, map[string]any{"message": "Migration rolled back."}, nil
	})

	// Function-domain TLS, Skupper, capacity, demo projects
	handle("PUT /knative-regions/{knativeRegion}/function-tls", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		switch q.str("mode") {
		case runr.FunctionTLSConsole:
			r.tlsKey = ""
		case runr.FunctionTLSCustom:
			if !strings.Contains(q.str("certificate"), "BEGIN CERTIFICATE") {
				return 0, nil, errValidation("certificate", "The certificate must be a PEM CERTIFICATE.")
			}
			if !strings.Contains(q.str("privateKey"), "PRIVATE KEY") {
				return 0, nil, errValidation("privateKey", "The private key must be a PEM PRIVATE KEY.")
			}
			r.tlsKey = q.str("privateKey")
		default:
			return 0, nil, errValidation("mode", "The selected mode is invalid.")
		}
		r.d.FunctionTLSMode, r.d.FunctionTLSExpiresAt = q.str("mode"), "2027-01-14T00:00:00.000000Z"
		return 200, s.rlcOperation(reg, r, "Function domain TLS updated."), nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/function-tls", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		r.d.FunctionTLSMode, r.d.FunctionTLSExpiresAt, r.tlsKey = "", "", ""
		return 200, s.rlcOperation(reg, r, "Function-domain TLS removed."), nil
	})
	handle("PUT /knative-regions/{knativeRegion}/monitoring", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		for _, k := range []string{"prometheus_url", "loki_url"} {
			if v, ok := q.Body[k].(string); ok && !regexp.MustCompile(`^https?://[a-z0-9-]+\.[a-z0-9-]+\.svc(\.cluster\.local)?:\d+(/\S*)?$`).MatchString(v) {
				return 0, nil, errValidation(k, "Must be an in-cluster Service URL of the region cluster.")
			}
		}
		return 200, s.rlcOperation(reg, r, "Monitoring updated."), nil
	})
	handle("POST /knative-regions/{knativeRegion}/complete-domain-migration", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if !r.domainMigrating {
			return 0, nil, &apiErr{status: 422, message: "The region is not moving to another base domain."}
		}
		r.domainMigrating = false
		return 200, s.rlcOperation(reg, r, "Domain migration completed: the region answers on its new function domain."), nil
	})
	handle("GET /knative-regions/{knativeRegion}/skupper", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if !r.skupper {
			return 200, map[string]any{"data": map[string]any{"available": false, "api_version": "skupper.io/v2alpha1",
				"namespace": reg.ProjectNamespace, "site": nil}}, nil
		}
		return 200, map[string]any{"data": map[string]any{"available": true, "api_version": "skupper.io/v2alpha1",
			"namespace": reg.ProjectNamespace, "site": r.site}}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/skupper/site", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if !r.skupper {
			return 0, nil, errValidation("region", "Skupper v2 is not installed on region "+reg.Name+".")
		}
		if r.site == nil {
			r.site = &runr.SkupperSite{Name: "runr", Status: "Pending"}
		} else {
			r.site.Status = "Ready"
		}
		return 200, map[string]any{"data": r.site}, nil
	})
	capacity := func(reg *runr.Region, r *rlcRegion) map[string]any {
		budget := func(capacity, used int, factor float64) map[string]any {
			b := int(float64(capacity) * factor)
			var util any
			if capacity > 0 {
				util = float64(int(float64(used)/float64(capacity)*1000)) / 10
			}
			return map[string]any{"capacity": capacity, "always_on_used": used, "always_on_available": capacity - used,
				"faas_overcommit": factor, "faas_budget": b - used, "faas_used": 0, "faas_available": b - used, "utilization": util}
		}
		return map[string]any{"region_id": reg.ID, "faas_cpu_overcommit": r.d.FaaSCPUOvercommit,
			"faas_memory_overcommit": r.d.FaaSMemoryOvercommit,
			"cpu_m":                  budget(reg.TotalCPUm, reg.UsedCPUm, r.d.FaaSCPUOvercommit), "memory_mb": budget(reg.TotalMemoryMB, reg.UsedMemoryMB, r.d.FaaSMemoryOvercommit)}
	}
	handle("GET /knative-regions/{knativeRegion}/capacity", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": capacity(reg, r)}, nil
	})
	handle("PUT /knative-regions/{knativeRegion}/capacity", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if len(q.Body) == 0 {
			return 0, nil, errValidation("faas_cpu_overcommit", "The faas cpu overcommit field is required when faas memory overcommit is not present.")
		}
		for _, k := range []string{"faas_cpu_overcommit", "faas_memory_overcommit"} {
			v, ok := q.Body[k]
			if !ok {
				continue
			}
			f, isNum := v.(float64)
			if !isNum || f < 1 || f > 10 || float64(int(f*100)) != f*100 {
				return 0, nil, errValidation(k, "The "+strings.ReplaceAll(k, "_", " ")+" field must be between 1 and 10 with at most 2 decimals.")
			}
		}
		if f, ok := q.Body["faas_cpu_overcommit"].(float64); ok {
			r.d.FaaSCPUOvercommit = f
		}
		if f, ok := q.Body["faas_memory_overcommit"].(float64); ok {
			r.d.FaaSMemoryOvercommit = f
		}
		return 200, map[string]any{"message": "Capacity settings updated.", "data": capacity(reg, r)}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/demo-projects", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if reg.IsDemo {
			return 0, nil, &apiErr{status: 422, message: "The demo region cannot get demo projects."}
		}
		if r.d.DemoStatus == runr.DemoSeeding || r.d.DemoStatus == runr.DemoReady {
			return 0, nil, &apiErr{status: 422, message: "The region already has demo projects."}
		}
		if !r.d.HasServing {
			return 0, nil, &apiErr{status: 422, message: "Install Knative Serving first."}
		}
		r.d.DemoStatus = runr.DemoSeeding
		s.rlcStart(r, "demo", func(failed bool, message string) {
			if failed {
				r.d.DemoStatus = runr.DemoError
				return
			}
			r.d.DemoStatus = runr.DemoReady
		})
		return 200, map[string]any{"region_demo_status": runr.DemoSeeding}, nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/demo-projects", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if r.d.DemoStatus == runr.DemoRemoving {
			return 0, nil, &apiErr{status: 422, message: "The demo projects are already being removed."}
		}
		r.d.DemoStatus = runr.DemoRemoving
		s.rlcStart(r, "demo", func(failed bool, message string) {
			if failed {
				r.d.DemoStatus = runr.DemoError
				return
			}
			r.d.DemoStatus = ""
		})
		return 200, map[string]any{"region_demo_status": runr.DemoRemoving}, nil
	})
}

// nullStr encodes "" as null, as the API does for unset columns.
func nullStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}
