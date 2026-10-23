package runrtest

import (
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Global projects in the fake (GlobalProjectController, GlobalDnsController): every region of a
// global project serves its active deployment's version (SetGlobalRegionStatus overrides one);
// a failover moves the primary and fences the old one; a region's global DNS server installs and
// uninstalls after RegionAdminConfig.InstallPolls reads of its status, like the region installs,
// and a ready server has the platform's GeoIP database loaded. The zone is g.runr.test.

const fakeGlobalZone = "g.runr.test"

// fakeGeoIPURL is the platform's GeoIP database URL in the fake.
const fakeGeoIPURL = "https://download.db-ip.com/free/dbip-city-lite-2026-10.mmdb.gz"

type globalState struct {
	projects map[string]*globalProject
	servers  map[string]*runr.GlobalDNSServer
	// delegation status / message of GET /global-dns (SetGlobalDelegation)
	delegation, delegationMessage string
	lastSync                      *time.Time
}

type globalProject struct {
	mode     string
	failover string
	// regions are the region ids in their order, the primary included
	regions   []string
	overrides map[string]runr.GlobalRegion
	warnings  []string
	bindings  []runr.GlobalBindingData
}

// SetGlobalBindings sets the bindings GET /projects/{project}/global reports.
func (s *Server) SetGlobalBindings(projectName string, bindings ...runr.GlobalBindingData) {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, g := s.globalProjectByName(projectName)
	g.bindings = bindings
}

func (s *Server) globalSt() *globalState {
	return featureState(s, "global", func() *globalState {
		return &globalState{projects: map[string]*globalProject{}, servers: map[string]*runr.GlobalDNSServer{}}
	})
}

// SetGlobalDNS sets the status of a region's global DNS server (ready gives it an address and a
// name server name; disabled removes it).
func (s *Server) SetGlobalDNS(regionName, status string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	s.setGlobalServer(reg, status, "")
}

// SetGlobalRegionStatus overrides the status of a global project's copy in one region.
func (s *Server) SetGlobalRegionStatus(projectName, regionName, status, message string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p, g := s.globalProjectByName(projectName)
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	g.overrides[reg.ID] = runr.GlobalRegion{Status: status, StatusMessage: message}
	_ = p
}

// SetGlobalWarnings sets the warnings of a global project.
func (s *Server) SetGlobalWarnings(projectName string, warnings ...string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, g := s.globalProjectByName(projectName)
	g.warnings = warnings
}

// SetGlobalDelegation sets the delegation status GET /global-dns reports (ok, partial, missing,
// unknown) and its message.
func (s *Server) SetGlobalDelegation(status, message string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.globalSt()
	st.delegation, st.delegationMessage = status, message
}

// GlobalRegions returns the region names of a global project in their order (nil: regional).
func (s *Server) GlobalRegions(projectName string) []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	g := s.globalSt().projects[p.data.ID]
	if g == nil {
		return nil
	}
	names := make([]string, 0, len(g.regions))
	for _, id := range g.regions {
		names = append(names, s.regionByID(id).Name)
	}
	return names
}

func (s *Server) globalProjectByName(name string) (*project, *globalProject) {
	p := s.projectByName(name)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", name)
	}
	g := s.globalSt().projects[p.data.ID]
	if g == nil {
		s.t.Fatalf("runrtest: project %q is not a global project", name)
	}
	return p, g
}

func (s *Server) setGlobalServer(reg *runr.Region, status, message string) *runr.GlobalDNSServer {
	st := s.globalSt()
	srv := st.servers[reg.ID]
	if srv == nil {
		srv = &runr.GlobalDNSServer{RegionID: reg.ID, RegionName: reg.Name}
		st.servers[reg.ID] = srv
	}
	srv.Status, srv.StatusMessage = status, message
	switch status {
	case runr.GlobalDNSReady:
		yes := true
		now := time.Now().UTC()
		srv.Address, srv.NSName, srv.Reachable = s.globalDNSAddress(reg), "ns-"+reg.Name+"."+fakeGlobalZone, &yes
		srv.SyncStatus, srv.SyncedAt = "in_sync", &now
		srv.TLSStatus = "ready"
		if srv.GeoIP == nil {
			srv.GeoIP = &runr.GlobalDNSGeoIP{Source: runr.GeoIPSourceDefault, EffectiveURL: fakeGeoIPURL, Status: "loaded",
				Message: "GeoIP database loaded from " + fakeGeoIPURL + " (120.5 MiB, 2026-10-01T00:00:00Z).", CheckedAt: &now}
		}
	case runr.GlobalDNSDisabled:
		srv.Address, srv.NSName, srv.Reachable, srv.SyncStatus, srv.SyncedAt, srv.TLSStatus = "", "", nil, "", nil, ""
		srv.GeoIP = nil
	}
	return srv
}

// globalDNSAddress is the LoadBalancer address of a region's DNS server; globalTraefikAddress the
// region's external address.
func (s *Server) globalDNSAddress(reg *runr.Region) string {
	return "198.51.100." + strconv.Itoa(10+s.regionIndex(reg))
}

func (s *Server) globalTraefikAddress(reg *runr.Region) string {
	return "203.0.113." + strconv.Itoa(10+s.regionIndex(reg))
}

func (s *Server) regionIndex(reg *runr.Region) int {
	for i, r := range s.regions {
		if r.ID == reg.ID {
			return i
		}
	}
	return 0
}

func (s *Server) globalDNSReady(regionID string) bool {
	srv := s.globalSt().servers[regionID]
	return srv != nil && srv.Status == runr.GlobalDNSReady
}

// globalRegionIDs validates region_ids of a create or a change: 2 to 8 regions with global DNS.
func (s *Server) globalRegionIDs(v any) ([]string, *apiErr) {
	ids := strList(v)
	if len(ids) < 2 {
		return nil, errValidation("region_ids", "A global project runs in at least 2 regions.")
	}
	if len(ids) > runr.MaxGlobalRegions {
		return nil, errValidation("region_ids", fmt.Sprintf("A global project runs in at most %d regions.", runr.MaxGlobalRegions))
	}
	seen := map[string]bool{}
	for i, id := range ids {
		field := "region_ids." + strconv.Itoa(i)
		if seen[id] {
			return nil, errValidation(field, "The "+field+" field has a duplicate value.")
		}
		seen[id] = true
		reg := s.regionByID(id)
		if reg == nil {
			return nil, errValidation(field, "The selected "+field+" is invalid.")
		}
		if !s.globalDNSReady(id) {
			return nil, errValidation(field, "Region "+reg.Name+" has no global DNS: an operator enables it in the region's settings first.")
		}
	}
	return ids, nil
}

func globalMode(v any, field string) (string, *apiErr) {
	mode, _ := v.(string)
	switch mode {
	case "":
		return runr.GlobalModeHA, nil
	case runr.GlobalModeHA, runr.GlobalModeProximity:
		return mode, nil
	}
	return "", errValidation(field, "The selected "+strings.ReplaceAll(field, "_", " ")+" is invalid.")
}

// validateGlobalCreate checks the global fields of POST /projects (StoreProjectRequest).
func (s *Server) validateGlobalCreate(body map[string]any) *apiErr {
	placement, _ := body["placement"].(string)
	switch placement {
	case "", runr.PlacementRegional:
		return nil
	case runr.PlacementGlobal:
	default:
		return errValidation("placement", "The selected placement is invalid.")
	}
	if _, err := globalMode(body["global_mode"], "global_mode"); err != nil {
		return err
	}
	ids, err := s.globalRegionIDs(body["region_ids"])
	if err != nil {
		return err
	}
	primary, _ := body["knative_region_id"].(string)
	found := false
	for _, id := range ids {
		found = found || id == primary
	}
	if !found {
		return errValidation("knative_region_id", "The primary region must be one of the project's regions.")
	}
	// every trigger works in a global project: the non-HTTP ones run in the primary only
	return nil
}

// createGlobal makes a created project global (POST /projects with placement global).
func (s *Server) createGlobal(p *project, body map[string]any) {
	if placement, _ := body["placement"].(string); placement != runr.PlacementGlobal {
		return
	}
	mode, _ := globalMode(body["global_mode"], "global_mode")
	s.globalSt().projects[p.data.ID] = &globalProject{mode: mode, failover: runr.GlobalFailoverAutomatic, regions: strList(body["region_ids"]),
		overrides: map[string]runr.GlobalRegion{}}
}

// withGlobal adds placement, global and the global URL to a project resource.
func (s *Server) withGlobal(p *project, out *runr.Project) {
	g := s.globalSt().projects[p.data.ID]
	if g == nil {
		out.Placement = runr.PlacementRegional
		return
	}
	out.Placement = runr.PlacementGlobal
	res := s.globalResource(p, g)
	out.Global = &res
	if out.Config.Trigger() == runr.TriggerHTTP {
		out.EndpointURL = "https://" + res.Hostname
	}
}

// servedVersion is the version number of the project's active deployment (0: none).
func (s *Server) servedVersion(p *project) int {
	for i := len(p.deployments) - 1; i >= 0; i-- {
		d := p.deployments[i]
		if d.data.Status != runr.DeploymentActive || d.data.Version == nil {
			continue
		}
		for _, v := range p.versions {
			if v.data.ID == d.data.Version.ID {
				return v.data.Number
			}
		}
	}
	return 0
}

func (s *Server) globalResource(p *project, g *globalProject) runr.ProjectGlobal {
	out := runr.ProjectGlobal{Mode: g.mode, Failover: g.failover, FailoverAfterSeconds: 180, Hostname: p.data.Slug + "." + fakeGlobalZone,
		PrimaryRegionID: p.data.RegionID, Regions: []runr.GlobalRegion{}, Bindings: append([]runr.GlobalBindingData{}, g.bindings...),
		Warnings: append([]string{}, g.warnings...)}
	served := s.servedVersion(p)
	for i, id := range g.regions {
		reg := s.regionByID(id)
		r := runr.GlobalRegion{RegionID: id, RegionName: reg.Name, Role: runr.GlobalRoleSecondary, Position: i,
			Status: runr.GlobalRegionPending, Address: s.globalTraefikAddress(reg)}
		if id == p.data.RegionID {
			r.Role, r.TriggersActive = runr.GlobalRolePrimary, true
		}
		if served > 0 {
			v := served
			yes := true
			now := time.Now().UTC()
			r.Status, r.Version, r.Healthy, r.HealthCheckedAt, r.InDNS = runr.GlobalRegionReady, &v, &yes, &now, true
		}
		if o, ok := g.overrides[id]; ok {
			r.Status, r.StatusMessage, r.FencedAt = o.Status, o.StatusMessage, o.FencedAt
			switch o.Status {
			case runr.GlobalRegionReady:
			case runr.GlobalRegionBuilding, runr.GlobalRegionDeploying:
				// the region keeps serving the version it has while the next one rolls out
				r.InDNS = r.Version != nil
				if served > 0 {
					next := served + 1
					r.TargetVersion = &next
				}
			default:
				no := false
				r.InDNS, r.Healthy = false, &no
			}
		}
		out.Regions = append(out.Regions, r)
	}
	return out
}

// globalOf resolves the {project} of a /projects/{project}/global request (404: regional).
func (s *Server) globalOf(q *featureReq) (*project, *globalProject, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, nil, err
	}
	g := s.globalSt().projects[p.data.ID]
	if g == nil {
		return nil, nil, &apiErr{status: 404, message: "Project " + p.data.Name + " is not a global project."}
	}
	return p, g, nil
}

// regionGlobalDNS is the answer of /knative-regions/{knativeRegion}/global-dns.
func (s *Server) regionGlobalDNS(reg *runr.Region, r *rlcRegion) map[string]any {
	srv := s.globalSt().servers[reg.ID]
	if srv == nil {
		srv = &runr.GlobalDNSServer{RegionID: reg.ID, RegionName: reg.Name, Status: runr.GlobalDNSDisabled}
	}
	reason := s.globalDNSIneligible(reg, r)
	return map[string]any{
		"region_id": srv.RegionID, "region_name": srv.RegionName, "status": srv.Status, "status_message": nullStr(srv.StatusMessage),
		"address": nullStr(srv.Address), "ns_name": nullStr(srv.NSName), "reachable": srv.Reachable,
		"sync_status": nullStr(srv.SyncStatus), "sync_message": nullStr(srv.SyncMessage), "synced_at": srv.SyncedAt,
		"tls_status": nullStr(srv.TLSStatus), "tls_message": nullStr(srv.TLSMessage), "tls_expires_at": srv.TLSExpiresAt,
		"geoip": srv.GeoIP, "eligible": reason == "", "reason": nullStr(reason),
	}
}

func (s *Server) globalDNSIneligible(reg *runr.Region, r *rlcRegion) string {
	switch {
	case reg.IsDemo:
		return "The demo region cannot serve DNS."
	case r.kubeconfig == "":
		return "The region has no kubeconfig."
	case !r.d.HasServing:
		return "Install Knative Serving on the region first."
	}
	return ""
}

// globalProjectsIn lists the global projects that run in a region.
func (s *Server) globalProjectsIn(regionID string) []string {
	var names []string
	for id, g := range s.globalSt().projects {
		for _, rid := range g.regions {
			if rid == regionID {
				if p := s.projectByID(id); p != nil {
					names = append(names, p.data.Name)
				}
			}
		}
	}
	sort.Strings(names)
	return names
}

func init() {
	handle("GET /projects/{project}/global", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, g, err := s.globalOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, s.globalResource(p, g), nil
	})
	handle("PUT /projects/{project}/global", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, g, err := s.globalOf(q)
		if err != nil {
			return 0, nil, err
		}
		mode := g.mode
		if v, ok := q.Body["mode"]; ok {
			if mode, err = globalMode(v, "mode"); err != nil {
				return 0, nil, err
			}
		}
		failover := g.failover
		if v, ok := q.Body["failover"]; ok {
			failover, _ = v.(string)
			if failover != runr.GlobalFailoverAutomatic && failover != runr.GlobalFailoverManual {
				return 0, nil, errValidation("failover", "The selected failover is invalid.")
			}
		}
		regions := g.regions
		if v, ok := q.Body["region_ids"]; ok {
			if regions, err = s.globalRegionIDs(v); err != nil {
				return 0, nil, err
			}
		}
		primary := p.data.RegionID
		if v, ok := q.Body["primary_region_id"].(string); ok && v != "" {
			primary = v
		}
		found := false
		for _, id := range regions {
			found = found || id == primary
		}
		if !found {
			return 0, nil, errValidation("primary_region_id", "The primary region must be one of the project's regions.")
		}
		if primary != p.data.RegionID {
			current := s.globalResource(p, g)
			ready := false
			for _, r := range current.Regions {
				ready = ready || (r.RegionID == primary && r.Status == runr.GlobalRegionReady)
			}
			if !ready {
				return 0, nil, errValidation("primary_region_id", "The new primary region must serve the project first (status ready).")
			}
		}
		g.mode, g.failover, g.regions = mode, failover, regions
		p.data.RegionID = primary
		for id := range g.overrides {
			keep := false
			for _, rid := range regions {
				keep = keep || rid == id
			}
			if !keep {
				delete(g.overrides, id)
			}
		}
		return 200, s.globalResource(p, g), nil
	})
	handle("POST /projects/{project}/global/failover", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, g, err := s.globalOf(q)
		if err != nil {
			return 0, nil, err
		}
		current := s.globalResource(p, g)
		target, _ := q.Body["region_id"].(string)
		if target == "" {
			// the first ready secondary in HA order
			for _, r := range current.Regions {
				if r.RegionID != p.data.RegionID && r.Status == runr.GlobalRegionReady {
					target = r.RegionID
					break
				}
			}
			if target == "" {
				return 0, nil, errValidation("region_id", "No other region serves the active version and answers: there is nothing to fail over to.")
			}
		}
		if target == p.data.RegionID {
			return 0, nil, errValidation("region_id", "That region is the primary already.")
		}
		ready := false
		for _, r := range current.Regions {
			ready = ready || (r.RegionID == target && r.Status == runr.GlobalRegionReady)
		}
		if !ready {
			return 0, nil, errValidation("region_id", "Only a region whose copy serves the active version can become the primary.")
		}
		old := p.data.RegionID
		now := time.Now().UTC()
		to := s.regionByID(target)
		g.overrides[old] = runr.GlobalRegion{Status: runr.GlobalRegionFenced, FencedAt: &now,
			StatusMessage: "Failed over to " + to.Name + ": this region is out of the DNS answers until it answers again."}
		p.data.RegionID = target
		return 202, s.globalResource(p, g), nil
	})
	handle("POST /projects/{project}/global/sync", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, g, err := s.globalOf(q)
		if err != nil {
			return 0, nil, err
		}
		// what is not ready is rolled out again
		for id, o := range g.overrides {
			if o.Status == runr.GlobalRegionFailed {
				delete(g.overrides, id)
			}
		}
		return 202, map[string]any{"queued": true}, nil
	})

	handle("GET /global-dns", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.globalSt()
		servers := []runr.GlobalDNSServer{}
		records := []runr.DNSRecord{}
		update := ""
		for _, reg := range s.regions {
			srv := st.servers[reg.ID]
			if srv == nil {
				continue
			}
			s.rlcAdvance(s.rlcRegionOf(reg))
			servers = append(servers, *srv)
			if srv.Status == runr.GlobalDNSReady {
				if update == "" {
					update = reg.ID
				}
				records = append(records, runr.DNSRecord{Name: fakeGlobalZone, Type: "NS", Value: srv.NSName})
			}
		}
		for _, srv := range servers {
			if srv.Status == runr.GlobalDNSReady {
				records = append(records, runr.DNSRecord{Name: srv.NSName, Type: "A", Value: srv.Address})
			}
		}
		status, message := st.delegation, st.delegationMessage
		if status == "" {
			status = "ok"
			if len(records) == 0 {
				status, message = "unknown", "No region serves the zone yet."
			}
		}
		return 200, map[string]any{
			"zone": fakeGlobalZone, "base_domain": "runr.test", "tls": "acme", "update_region_id": nullStr(update),
			"delegation": map[string]any{"records": records, "status": status, "message": nullStr(message)},
			"servers":    servers, "last_sync_at": st.lastSync,
		}, nil
	})
	handle("POST /global-dns/sync", func(s *Server, q *featureReq) (int, any, *apiErr) {
		now := time.Now().UTC()
		s.globalSt().lastSync = &now
		return 202, map[string]any{"queued": true}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/global-dns", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, s.regionGlobalDNS(reg, r), nil
	})
	handle("POST /knative-regions/{knativeRegion}/global-dns", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if reason := s.globalDNSIneligible(reg, r); reason != "" {
			return 0, nil, &apiErr{status: 422, message: reason, extra: map[string]any{"reason": reason}}
		}
		if srv := s.globalSt().servers[reg.ID]; srv != nil && (srv.Status == runr.GlobalDNSInstalling || srv.Status == runr.GlobalDNSUninstalling) {
			return 0, nil, &apiErr{status: 422, message: "Another operation is in progress on this region."}
		}
		previous := runr.GlobalDNSDisabled
		if srv := s.globalSt().servers[reg.ID]; srv != nil {
			previous = srv.Status
		}
		s.setGlobalServer(reg, runr.GlobalDNSInstalling, "Installation queued...")
		s.rlcStart(r, "global-dns", func(failed bool, message string) {
			if failed {
				if previous == runr.GlobalDNSReady {
					s.setGlobalServer(reg, runr.GlobalDNSReady, message)
					return
				}
				s.setGlobalServer(reg, runr.GlobalDNSError, message)
				return
			}
			s.setGlobalServer(reg, runr.GlobalDNSReady, "")
		})
		return 202, s.regionGlobalDNS(reg, r), nil
	})
	handle("PUT /knative-regions/{knativeRegion}/global-dns/geoip", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		srv := s.globalSt().servers[reg.ID]
		if srv == nil || srv.Status == runr.GlobalDNSDisabled {
			return 0, nil, &apiErr{status: 404, message: "The region does not run a global DNS server."}
		}
		source, _ := q.Body["source"].(string)
		url, _ := q.Body["url"].(string)
		switch source {
		case runr.GeoIPSourceDefault, runr.GeoIPSourceFile, runr.GeoIPSourceNone:
			url = ""
		case runr.GeoIPSourceURL:
			if !strings.HasPrefix(url, "http://") && !strings.HasPrefix(url, "https://") {
				return 0, nil, errValidation("url", "The url field is required when source is url.")
			}
		default:
			return 0, nil, errValidation("source", "The selected source is invalid.")
		}
		effective := ""
		switch source {
		case runr.GeoIPSourceDefault:
			effective = fakeGeoIPURL
		case runr.GeoIPSourceURL:
			effective = url
		}
		srv.GeoIP = &runr.GlobalDNSGeoIP{Source: source, URL: url, EffectiveURL: effective, Status: "unknown"}
		return 200, s.regionGlobalDNS(reg, r), nil
	})
	handle("POST /knative-regions/{knativeRegion}/global-dns/geoip/reload", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		srv := s.globalSt().servers[reg.ID]
		if srv == nil || srv.Status == runr.GlobalDNSDisabled || srv.GeoIP == nil {
			return 0, nil, &apiErr{status: 404, message: "The region does not run a global DNS server."}
		}
		srv.GeoIP.Status, srv.GeoIP.Message = "unknown", "The server reads its GeoIP source again."
		return 202, s.regionGlobalDNS(reg, r), nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/global-dns", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		srv := s.globalSt().servers[reg.ID]
		if srv == nil || srv.Status == runr.GlobalDNSDisabled {
			return 0, nil, &apiErr{status: 422, message: "Global DNS is not enabled on this region."}
		}
		if srv.Status == runr.GlobalDNSInstalling || srv.Status == runr.GlobalDNSUninstalling {
			return 0, nil, &apiErr{status: 422, message: "Another operation is in progress on this region."}
		}
		if used := s.globalProjectsIn(reg.ID); len(used) > 0 {
			return 0, nil, &apiErr{status: 422, message: "Region " + reg.Name + " serves global projects (" + strings.Join(used, ", ") +
				"): remove the region from them first."}
		}
		s.setGlobalServer(reg, runr.GlobalDNSUninstalling, "Removal queued...")
		s.rlcStart(r, "global-dns", func(failed bool, message string) {
			if failed {
				s.setGlobalServer(reg, runr.GlobalDNSError, message)
				return
			}
			s.setGlobalServer(reg, runr.GlobalDNSDisabled, "")
		})
		return 202, s.regionGlobalDNS(reg, r), nil
	})
}
