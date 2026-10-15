package runrtest

import (
	"fmt"
	"sort"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Region administration of the fake: the tracing backend (RegionTracingController) and the
// data services (DataServiceController) of each region. Installs end after
// RegionAdminConfig.InstallPolls reads of the status.

// RegionAdminConfig tunes the fake's region administration.
type RegionAdminConfig struct {
	// InstallPolls is how many status reads an install or uninstall stays in progress. Default 1.
	InstallPolls int
	// FailInstall makes installs end in "error" with FailMessage.
	FailInstall bool
	FailMessage string
	// Disconnected names regions without a cluster connection (data services answer null,
	// tracing installs answer 409).
	Disconnected []string
}

type regionAdminState struct {
	cfg      RegionAdminConfig
	tracing  map[string]*radmTracing
	services map[string]map[string]*radmService
	bindings map[string]map[string]int
}

type radmTracing struct {
	data      runr.RegionTracing
	headers   map[string]string
	pollsLeft int
	fails     bool
}

type radmService struct {
	data       runr.DataService
	options    map[string]any
	secrets    map[string]string
	pollsLeft  int
	fails      bool
	uninstalls bool
}

func (s *Server) regionAdminSt() *regionAdminState {
	return featureState(s, "regions-admin", func() *regionAdminState {
		return &regionAdminState{cfg: RegionAdminConfig{InstallPolls: 1, FailMessage: "helm install failed: timed out waiting for the condition"},
			tracing: map[string]*radmTracing{}, services: map[string]map[string]*radmService{}, bindings: map[string]map[string]int{}}
	})
}

// RegionAdmin returns the region administration settings of the fake.
func (s *Server) RegionAdmin() *RegionAdminConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.regionAdminSt().cfg
}

// SeedDataService marks a data service of a region installed and ready, with bindings
// bindings on it (uninstall is refused while bindings > 0).
func (s *Server) SeedDataService(regionName, service, mode string, bindings int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	st := s.regionAdminSt()
	svc := s.radmServiceOf(reg.ID, service)
	svc.data.Installed, svc.data.Mode, svc.data.Status = true, mode, runr.RegionServiceReady
	svc.data.UpdatedAt = nowRFC3339()
	if st.bindings[reg.ID] == nil {
		st.bindings[reg.ID] = map[string]int{}
	}
	st.bindings[reg.ID][service] = bindings
}

// DataServiceOptions returns the settings and the stored credentials of a region's data
// service as the last install sent them.
func (s *Server) DataServiceOptions(regionName, service string) (map[string]any, map[string]string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		return nil, nil
	}
	svc := s.radmServiceOf(reg.ID, service)
	opts := map[string]any{}
	for k, v := range svc.options {
		opts[k] = v
	}
	secrets := map[string]string{}
	for k, v := range svc.secrets {
		secrets[k] = v
	}
	return opts, secrets
}

// RegionTracingHeaders returns the export headers stored for a region (write-only in the API).
func (s *Server) RegionTracingHeaders(regionName string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range s.radmTracingOf(reg.ID).headers {
		out[k] = v
	}
	return out
}

func (s *Server) radmDisconnected(regionID string) bool {
	reg := s.regionByID(regionID)
	if reg == nil {
		return false
	}
	for _, n := range s.regionAdminSt().cfg.Disconnected {
		if n == reg.Name {
			return true
		}
	}
	return false
}

func (s *Server) radmTracingOf(regionID string) *radmTracing {
	st := s.regionAdminSt()
	t, ok := st.tracing[regionID]
	if !ok {
		rate, hours := 0.1, 72
		t = &radmTracing{data: runr.RegionTracing{RegionID: regionID, Mode: runr.TracingManaged, KnativeSampleRate: &rate,
			RetentionHours: &hours}, headers: map[string]string{}}
		st.tracing[regionID] = t
	}
	return t
}

func (s *Server) radmServiceOf(regionID, service string) *radmService {
	st := s.regionAdminSt()
	if st.services[regionID] == nil {
		st.services[regionID] = map[string]*radmService{}
	}
	svc, ok := st.services[regionID][service]
	if !ok {
		svc = &radmService{data: runr.DataService{Service: service, Status: runr.RegionServiceNotInstalled},
			options: map[string]any{}, secrets: map[string]string{}}
		st.services[regionID][service] = svc
	}
	return svc
}

// radmRegion resolves the {knativeRegion} segment at index i.
func (s *Server) radmRegion(q *featureReq, i int) (*runr.Region, *apiErr) {
	if len(q.Segs) <= i {
		return nil, errNotFound()
	}
	reg := s.regionByID(q.Segs[i])
	if reg == nil {
		return nil, errNotFound()
	}
	return reg, nil
}

func (s *Server) radmAdvanceTracing(t *radmTracing) {
	if t.data.Status != runr.RegionServiceInstalling {
		return
	}
	if t.pollsLeft > 0 {
		t.pollsLeft--
		return
	}
	if t.fails {
		t.data.Status, t.data.StatusMessage = runr.RegionServiceError, s.regionAdminSt().cfg.FailMessage
		return
	}
	t.data.Status, t.data.InstalledAt = runr.RegionServiceReady, nowRFC3339()
	t.data.Queryable = t.data.Mode == runr.TracingManaged || t.data.Mode == runr.TracingReuse
	t.data.AcceptsSpans = true
}

func (s *Server) radmTracingAPI(t *radmTracing) runr.RegionTracing {
	d := t.data
	d.ExportHeaderNames = []string{}
	for k := range t.headers {
		d.ExportHeaderNames = append(d.ExportHeaderNames, k)
	}
	sort.Strings(d.ExportHeaderNames)
	return d
}

func (s *Server) radmStartTracingInstall(t *radmTracing) {
	cfg := s.regionAdminSt().cfg
	if t.data.Mode == runr.TracingOff {
		t.data.Status = ""
		return
	}
	t.data.Status, t.data.StatusMessage = runr.RegionServiceInstalling, ""
	t.pollsLeft, t.fails = cfg.InstallPolls, cfg.FailInstall
}

func (s *Server) radmAdvanceService(svc *radmService) {
	if svc.data.Status != runr.RegionServiceInstalling && svc.data.Status != runr.RegionServiceUninstalling {
		return
	}
	if svc.pollsLeft > 0 {
		svc.pollsLeft--
		return
	}
	if svc.uninstalls {
		svc.data = runr.DataService{Service: svc.data.Service, Status: runr.RegionServiceNotInstalled}
		svc.uninstalls = false
		return
	}
	if svc.fails {
		svc.data.Status, svc.data.StatusMessage = runr.RegionServiceError, s.regionAdminSt().cfg.FailMessage
		return
	}
	svc.data.Status, svc.data.Installed, svc.data.UpdatedAt = runr.RegionServiceReady, true, nowRFC3339()
}

var radmModes = map[string][]string{
	runr.DataServiceKV:       {"managed", "region"},
	runr.DataServiceObject:   {"managed", "external"},
	runr.DataServicePostgres: {""},
}

var radmSecretOptions = []string{"admin_token", "access_key_id", "secret_access_key"}

func init() {
	handle("GET /observability/regions/{knativeRegion}/tracing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 2)
		if err != nil {
			return 0, nil, err
		}
		t := s.radmTracingOf(reg.ID)
		s.radmAdvanceTracing(t)
		return 200, map[string]any{"data": s.radmTracingAPI(t)}, nil
	})
	handle("PUT /observability/regions/{knativeRegion}/tracing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 2)
		if err != nil {
			return 0, nil, err
		}
		mode := q.str("mode")
		switch mode {
		case runr.TracingManaged, runr.TracingReuse, runr.TracingExternal, runr.TracingOff:
		case "":
			return 0, nil, errValidation("mode", "The mode field is required.")
		default:
			return 0, nil, errValidation("mode", "The selected mode is invalid.")
		}
		if mode == runr.TracingReuse && (q.str("tempo_url") == "" || q.str("tempo_otlp_endpoint") == "") {
			return 0, nil, errValidation("tempo_url", "The tempo url field is required when mode is reuse.")
		}
		if mode == runr.TracingExternal && q.str("export_endpoint") == "" {
			return 0, nil, errValidation("export_endpoint", "The export endpoint field is required when mode is external.")
		}
		if r, ok := q.Body["knative_sample_rate"].(float64); ok && (r < 0 || r > 1) {
			return 0, nil, errValidation("knative_sample_rate", "The knative sample rate field must be between 0 and 1.")
		}
		t := s.radmTracingOf(reg.ID)
		t.data.Mode = mode
		t.data.TempoURL, t.data.TempoOTLPEndpoint, t.data.TempoOrgID, t.data.ExportEndpoint = "", "", "", ""
		switch mode {
		case runr.TracingReuse:
			t.data.TempoURL, t.data.TempoOTLPEndpoint, t.data.TempoOrgID = q.str("tempo_url"), q.str("tempo_otlp_endpoint"), q.str("tempo_org_id")
		case runr.TracingExternal:
			t.data.ExportEndpoint = q.str("export_endpoint")
		}
		if h, ok := q.Body["export_headers"].(map[string]any); ok {
			t.headers = map[string]string{}
			for k, v := range h {
				t.headers[k] = fmt.Sprint(v)
			}
		}
		if mode != runr.TracingExternal {
			t.headers = map[string]string{}
		}
		if r, ok := q.Body["knative_sample_rate"].(float64); ok {
			t.data.KnativeSampleRate = &r
		}
		if h, ok := q.Body["retention_hours"].(float64); ok {
			n := int(h)
			t.data.RetentionHours = &n
		}
		if b, _ := q.Body["install"].(bool); b {
			if s.radmDisconnected(reg.ID) {
				return 0, nil, &apiErr{status: 409, message: "The region has no cluster connection."}
			}
			s.radmStartTracingInstall(t)
			return 202, map[string]any{"data": s.radmTracingAPI(t), "message": "Tracing install started."}, nil
		}
		return 200, map[string]any{"data": s.radmTracingAPI(t)}, nil
	})
	handle("POST /observability/regions/{knativeRegion}/tracing/install", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 2)
		if err != nil {
			return 0, nil, err
		}
		if s.radmDisconnected(reg.ID) {
			return 0, nil, &apiErr{status: 409, message: "The region has no cluster connection."}
		}
		t := s.radmTracingOf(reg.ID)
		s.radmStartTracingInstall(t)
		return 202, map[string]any{"data": s.radmTracingAPI(t), "message": "Tracing install started."}, nil
	})
	handle("DELETE /observability/regions/{knativeRegion}/tracing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 2)
		if err != nil {
			return 0, nil, err
		}
		if s.radmDisconnected(reg.ID) {
			return 0, nil, &apiErr{status: 409, message: "The region has no cluster connection."}
		}
		t := s.radmTracingOf(reg.ID)
		t.data.Status, t.data.InstalledAt, t.data.Queryable, t.data.AcceptsSpans = "", "", false, false
		return 202, map[string]any{"message": "Tracing removal started."}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/data-services", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 1)
		if err != nil {
			return 0, nil, err
		}
		manage := s.holds("regions.data_services.manage")
		if s.radmDisconnected(reg.ID) {
			return 200, map[string]any{"data": nil, "abilities": map[string]any{"manage": false}}, nil
		}
		st := s.regionAdminSt()
		services := map[string]any{}
		for _, name := range runr.DataServiceNames {
			svc := s.radmServiceOf(reg.ID, name)
			s.radmAdvanceService(svc)
			d := svc.data
			d.BindingsCount = st.bindings[reg.ID][name]
			entry := map[string]any{"service": name, "installed": d.Installed, "mode": nilIfEmpty(d.Mode), "status": d.Status,
				"status_message": nilIfEmpty(d.StatusMessage), "config": nil, "bindings_count": d.BindingsCount,
				"updated_at": nilIfEmpty(d.UpdatedAt)}
			if d.Installed {
				cfg := map[string]any{}
				for k, v := range svc.options {
					cfg[k] = v
				}
				entry["config"] = cfg
			}
			if q.query("live") == "1" && d.Status == runr.RegionServiceReady {
				entry["health"] = map[string]any{"state": "ok", "message": nil, "details": map[string]any{}}
			}
			services[name] = entry
		}
		return 200, map[string]any{
			"data": map[string]any{"region_id": reg.ID, "namespace": "runr-data", "services": services,
				"region_redis": map[string]any{"mode": "managed", "status": "ready", "has_password": true}},
			"abilities": map[string]any{"manage": manage},
		}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/data-services/{service}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 1)
		if err != nil {
			return 0, nil, err
		}
		name := q.Segs[3]
		modes, ok := radmModes[name]
		if !ok {
			return 0, nil, errNotFound()
		}
		if s.radmDisconnected(reg.ID) || reg.IsDemo {
			return 0, nil, errValidation("region", "Data services cannot be installed in region "+reg.Name+".")
		}
		if m := q.str("mode"); m != "" {
			valid := false
			for _, x := range modes {
				valid = valid || x == m
			}
			if !valid {
				return 0, nil, errValidation("mode", "The selected mode is invalid.")
			}
		}
		if name == runr.DataServiceObject && q.str("mode") == "external" && (q.str("provider") == "" || q.str("endpoint") == "") {
			return 0, nil, errValidation("endpoint", "The endpoint field is required.")
		}
		if v, ok := q.Body["maxmemory_mb"].(float64); ok && (v < 64 || v > 65536) {
			return 0, nil, errValidation("maxmemory_mb", "The maxmemory mb field must be between 64 and 65536.")
		}
		if v, ok := q.Body["instances"].(float64); ok && (v < 1 || v > 5) {
			return 0, nil, errValidation("instances", "The instances field must be between 1 and 5.")
		}
		svc := s.radmServiceOf(reg.ID, name)
		if svc.data.Status == runr.RegionServiceInstalling || svc.data.Status == runr.RegionServiceUninstalling {
			return 0, nil, errValidation("service", "The "+name+" data service is busy.")
		}
		for k, v := range q.Body {
			secret := false
			for _, so := range radmSecretOptions {
				secret = secret || so == k
			}
			if secret {
				if str := fmt.Sprint(v); str != "" {
					svc.secrets[k] = str
				}
				continue
			}
			svc.options[k] = v
		}
		if !svc.data.Installed && svc.data.Mode == "" {
			svc.data.Mode = q.str("mode")
			if svc.data.Mode == "" && name != runr.DataServicePostgres {
				svc.data.Mode = "managed"
			}
		}
		cfg := s.regionAdminSt().cfg
		svc.data.Status, svc.data.StatusMessage = runr.RegionServiceInstalling, ""
		svc.pollsLeft, svc.fails, svc.uninstalls = cfg.InstallPolls, cfg.FailInstall, false
		return 202, map[string]any{"message": "The " + name + " install is queued.",
			"data": map[string]any{"service": name, "status": svc.data.Status, "mode": svc.data.Mode}}, nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/data-services/{service}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, err := s.radmRegion(q, 1)
		if err != nil {
			return 0, nil, err
		}
		name := q.Segs[3]
		if _, ok := radmModes[name]; !ok {
			return 0, nil, errNotFound()
		}
		svc := s.radmServiceOf(reg.ID, name)
		if !svc.data.Installed && svc.data.Status == runr.RegionServiceNotInstalled {
			return 0, nil, errValidation("service", "The "+name+" data service is not installed.")
		}
		if n := s.regionAdminSt().bindings[reg.ID][name]; n > 0 {
			return 0, nil, errValidation("service", fmt.Sprintf("%d bindings use the %s data service.", n, name))
		}
		svc.data.Status, svc.data.StatusMessage = runr.RegionServiceUninstalling, ""
		svc.pollsLeft, svc.uninstalls = s.regionAdminSt().cfg.InstallPolls, true
		return 202, map[string]any{"message": "The " + name + " uninstall is queued.",
			"data": map[string]any{"service": name, "status": svc.data.Status}}, nil
	})
}

// nilIfEmpty encodes an empty string as null, like the API's nullable columns.
func nilIfEmpty(v string) any {
	if v == "" {
		return nil
	}
	return v
}
