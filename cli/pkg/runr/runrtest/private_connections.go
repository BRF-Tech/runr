package runrtest

import (
	"fmt"
	"regexp"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Private connections (PrivateDatabaseBindingController): of a project's own workload or of one
// of its environments, in the region that workload runs in. The fake keeps them in memory; their
// Skupper status is ready.

type privateConnectionsState struct {
	list []*runr.PrivateConnection
	// states is what …/status reports by connection id (default ready)
	states map[string]string
}

// SetPrivateConnectionState sets what the region reports about a connection of a project (ready,
// pending or error).
func (s *Server) SetPrivateConnectionState(projectName, name, state string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	st := s.privateConnectionsState()
	for _, c := range st.list {
		if p != nil && c.ProjectID == p.data.ID && c.Name == name {
			if st.states == nil {
				st.states = map[string]string{}
			}
			st.states[c.ID] = state
		}
	}
}

func (s *Server) privateConnectionsState() *privateConnectionsState {
	return featureState(s, "private-connections", func() *privateConnectionsState { return &privateConnectionsState{} })
}

// PrivateConnections returns the connections of a project (every environment).
func (s *Server) PrivateConnections(projectName string) []runr.PrivateConnection {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	var out []runr.PrivateConnection
	for _, c := range s.privateConnectionsState().list {
		if p != nil && c.ProjectID == p.data.ID {
			out = append(out, *c)
		}
	}
	return out
}

var (
	privateConnectionNameRe    = regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`)
	privateConnectionPrefixRe  = regexp.MustCompile(`^[A-Z][A-Z0-9_]*_$`)
	privateConnectionRoutingRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:/-]*$`)
	privateConnectionKindRe    = regexp.MustCompile(`(?m)^\s*kind:\s*["']?(AccessToken|Link)["']?\s*$`)
)

// privateConnectionSSLModes are the sslmodes the API accepts.
var privateConnectionSSLModes = map[string]bool{"disable": true, "allow": true, "prefer": true, "require": true, "verify-ca": true, "verify-full": true}

// privateConnectionLinkKind reads the kind of a pasted Skupper v2 link (SkupperLinkParser): an
// AccessToken (redeemed into a Link) or a Link; "" with the refusal for anything else.
func privateConnectionLinkKind(token string) (string, string) {
	if !strings.Contains(token, "skupper.io/v2") {
		return "", "No Skupper v2 AccessToken or Link found. Paste the YAML you received for the Managed PostgreSQL database."
	}
	m := privateConnectionKindRe.FindAllStringSubmatch(token, -1)
	for _, kind := range m {
		if kind[1] == "AccessToken" {
			return "access_token", ""
		}
	}
	if len(m) > 0 {
		return "link", ""
	}
	return "", "No Skupper v2 AccessToken or Link found. Paste the YAML you received for the Managed PostgreSQL database."
}

// privateConnectionScope resolves ?environment= / environment: its id ("" the project's own
// workload), its name and the region the workload runs in.
func (s *Server) privateConnectionScope(p *project, ref string) (string, string, string, *apiErr) {
	if ref == "" || !s.Environments {
		return "", "", p.data.RegionID, nil
	}
	e, err := s.environmentRef(p, ref)
	if err != nil {
		return "", "", "", err
	}
	if e.data.IsPrimary {
		return "", "", p.data.RegionID, nil
	}
	region := e.data.RegionID
	if region == "" {
		region = p.data.RegionID
	}
	return e.data.ID, e.data.Name, region, nil
}

func init() {
	handle("GET /projects/{project}/private-connections", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		envID, _, _, err := s.privateConnectionScope(p, q.query("environment"))
		if err != nil {
			return 0, nil, err
		}
		out := []runr.PrivateConnection{}
		for _, c := range s.privateConnectionsState().list {
			if c.ProjectID == p.data.ID && c.EnvironmentID == envID {
				out = append(out, *c)
			}
		}
		return 200, map[string]any{"data": out}, nil
	})
	handle("POST /projects/{project}/private-connections", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		envID, envName, regionID, err := s.privateConnectionScope(p, q.str("environment"))
		if err != nil {
			return 0, nil, err
		}
		name, prefix := q.str("name"), q.str("env_prefix")
		if !privateConnectionNameRe.MatchString(name) || len(name) > 30 {
			return 0, nil, errValidation("name", "The name field format is invalid.")
		}
		if prefix != "" && !privateConnectionPrefixRe.MatchString(prefix) {
			return 0, nil, errValidation("env_prefix", "The env prefix field format is invalid.")
		}
		for _, f := range []string{"routing_key", "link_token", "database", "username", "password"} {
			if q.str(f) == "" {
				return 0, nil, errValidation(f, "The "+strings.ReplaceAll(f, "_", " ")+" field is required.")
			}
		}
		if rk := q.str("routing_key"); len(rk) > 253 || !privateConnectionRoutingRe.MatchString(rk) {
			return 0, nil, errValidation("routing_key", "The routing key field format is invalid.")
		}
		if raw, ok := q.Body["port"]; ok && raw != nil {
			if _, valid := intIn(raw, 1, 65535); !valid {
				return 0, nil, errValidation("port", "The port field must be between 1 and 65535.")
			}
		}
		if mode := q.str("sslmode"); mode != "" && !privateConnectionSSLModes[mode] {
			return 0, nil, errValidation("sslmode", "The selected sslmode is invalid.")
		}
		for f, max := range map[string]int{"database": 63, "username": 63, "password": 1024} {
			if len(q.str(f)) > max {
				return 0, nil, errValidation(f, fmt.Sprintf("The %s field must not be greater than %d characters.", f, max))
			}
		}
		// names and prefixes are unique per workload (the project's own, or one environment's)
		st := s.privateConnectionsState()
		for _, c := range st.list {
			if c.ProjectID != p.data.ID || c.EnvironmentID != envID {
				continue
			}
			if c.Name == name {
				return 0, nil, errValidation("name", "The name has already been taken.")
			}
			if c.EnvPrefix == prefix {
				return 0, nil, errValidation("env_prefix", "Another private connection of this project uses the same environment variable prefix.")
			}
		}
		// the region the workload runs in must have a cluster; a global project is refused (its
		// copies in the other regions would have no link to the database)
		field := "project"
		if envID != "" {
			field = "environment"
		}
		if reg := s.regionByID(regionID); regionID == "" || reg == nil || reg.IsDemo {
			return 0, nil, errValidation(field, "The project needs a region with a kubeconfig.")
		}
		if s.globalSt().projects[p.data.ID] != nil {
			return 0, nil, errValidation("project", "Private database connections are refused for global projects: the connection's Skupper link is made once, with a one-time token, in one region's namespace — the copies in the other regions have no link to the database. Reach the database with an external binding (its address and credentials), which every region uses as it is.")
		}
		kind, refusal := privateConnectionLinkKind(q.str("link_token"))
		if refusal != "" {
			return 0, nil, errValidation("link_token", refusal)
		}
		if reg := s.regionByID(regionID); !s.rlcRegionOf(reg).skupper {
			return 0, nil, errValidation("project", "Skupper v2 (skupper.io/v2alpha1) is not installed on region "+reg.Name+".")
		}
		id := strings.ToLower(s.newID())
		port := 5432
		if v, ok := q.Body["port"].(float64); ok {
			port = int(v)
		}
		sslmode := q.str("sslmode")
		if sslmode == "" {
			sslmode = "require"
		}
		resource := name + "-" + id[len(id)-6:]
		c := &runr.PrivateConnection{
			ID: id, ProjectID: p.data.ID, Name: name, ResourceName: resource, EnvPrefix: prefix,
			RoutingKey: q.str("routing_key"), Port: port, SSLMode: sslmode, LinkKind: kind, Host: resource,
			SecretName: resource + "-pgconn", EnvironmentID: envID, Environment: envName, CreatedAt: nowRFC3339(),
		}
		for _, k := range []string{"DATABASE_URL", "PGHOST", "PGPORT", "PGDATABASE", "PGUSER", "PGPASSWORD", "PGSSLMODE"} {
			c.EnvNames = append(c.EnvNames, prefix+k)
		}
		st.list = append(st.list, c)
		return 201, map[string]any{"data": c, "env_synced": true}, nil
	})
	handle("GET /projects/{project}/private-connections/{privateConnection}/status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.privateConnectionsState()
		for _, c := range st.list {
			if c.ProjectID == p.data.ID && c.ID == q.Segs[3] {
				state, link := "ready", "Ready"
				switch st.states[c.ID] {
				case "pending":
					state, link = "pending", "Pending"
				case "error":
					state, link = "error", "Error"
				}
				status := map[string]any{
					"state":    state,
					"link":     map[string]any{"status": link, "message": nil},
					"listener": map[string]any{"status": "Ready", "message": nil, "has_matching_connector": state == "ready"},
				}
				if c.LinkKind == "access_token" {
					status["access_token"] = map[string]any{"status": "Ready", "message": nil, "redeemed": state != "pending"}
				}
				return 200, map[string]any{"data": c, "status": status}, nil
			}
		}
		return 0, nil, errNotFound()
	})
	handle("DELETE /projects/{project}/private-connections/{privateConnection}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.privateConnectionsState()
		for i, c := range st.list {
			if c.ProjectID == p.data.ID && c.ID == q.Segs[3] {
				st.list = append(st.list[:i], st.list[i+1:]...)
				return 200, map[string]any{"message": "The private connection was removed.", "env_synced": true}, nil
			}
		}
		return 0, nil, errNotFound()
	})
}
