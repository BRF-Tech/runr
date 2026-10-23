package runrtest

import (
	"net"
	"net/url"
	"sort"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// A project's network (ProjectNetworkController, InternalNetworkService): access mode, its IP
// ranges, and the allow list of internal callers by internal name <project>.<organization>.
// The fake's organization is "platform".

type networkState struct {
	// byProject: project id → network
	byProject map[string]*runr.Network
	// CrossTenantOff refuses callers of another organization, like the platform switch.
	crossTenantOff bool
}

func (s *Server) networkState() *networkState {
	return featureState(s, "network", func() *networkState {
		return &networkState{byProject: map[string]*runr.Network{}}
	})
}

// CrossTenantGrantsOff makes the fake refuse callers of other organizations.
func (s *Server) CrossTenantGrantsOff() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.networkState().crossTenantOff = true
}

// Network returns the fake's network of a project (nil before it was read or set).
func (s *Server) Network(projectName string) *runr.Network {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	return s.networkState().byProject[p.data.ID]
}

func (s *Server) networkOf(p *project) *runr.Network {
	st := s.networkState()
	if n, ok := st.byProject[p.data.ID]; ok {
		n.CrossTenantGrants = !st.crossTenantOff
		return n
	}
	name := p.data.Slug + ".platform"
	n := &runr.Network{
		AccessMode: runr.AccessPublic, AllowedCIDRs: []string{}, InternalName: name,
		InternalHost: name + ".runr.internal", InternalURL: "http://" + name + ".runr.internal",
		Organization: "platform", TokenHeader: "X-Runr-Internal-Token", TokenEnv: "RUNR_INTERNAL_TOKEN",
		CrossTenantGrants: !st.crossTenantOff, Callers: []runr.NetworkCaller{},
	}
	st.byProject[p.data.ID] = n
	return n
}

func init() {
	handle("GET /projects/{project}/network", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.networkOf(p)}, nil
	})

	handle("PUT /projects/{project}/network", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		mode, _ := q.Body["access_mode"].(string)
		if mode != runr.AccessPublic && mode != runr.AccessAllowlist && mode != runr.AccessInternal {
			return 0, nil, errValidation("access_mode", "The selected access mode is invalid.")
		}
		cidrs := []string{}
		if raw, ok := q.Body["allowed_cidrs"].([]any); ok {
			for _, c := range raw {
				str, _ := c.(string)
				if net.ParseIP(str) == nil {
					if _, _, perr := net.ParseCIDR(str); perr != nil {
						return 0, nil, errValidation("allowed_cidrs.0", "The allowed_cidrs.0 field must be an IP address or a CIDR range.")
					}
				}
				cidrs = append(cidrs, str)
			}
		}
		if mode == runr.AccessAllowlist && len(cidrs) == 0 {
			return 0, nil, errValidation("allowed_cidrs", "Name at least one IP address or range for the allow list.")
		}
		n := s.networkOf(p)
		n.AccessMode = mode
		n.AllowedCIDRs = []string{}
		if mode == runr.AccessAllowlist {
			n.AllowedCIDRs = cidrs
		}
		return 200, map[string]any{"message": "Network settings saved.", "data": n}, nil
	})

	handle("POST /projects/{project}/network/callers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		caller, _ := q.Body["caller"].(string)
		caller = strings.ToLower(strings.TrimSpace(caller))
		if !strings.Contains(caller, ".") {
			other := s.projectByName(caller)
			if other == nil {
				other = s.projectByID(caller)
			}
			if other == nil {
				return 0, nil, errValidation("caller", "No project \""+caller+"\" in this organization.")
			}
			caller = other.data.Slug + ".platform"
		}
		n := s.networkOf(p)
		if caller == n.InternalName {
			return 0, nil, errValidation("caller", "A project can always call itself; it needs no entry on its allow list.")
		}
		org := caller[strings.IndexByte(caller, '.')+1:]
		if org != "platform" && s.networkState().crossTenantOff {
			return 0, nil, errValidation("caller", "The platform does not allow grants to projects of other organizations.")
		}
		row := runr.NetworkCaller{Caller: caller, Organization: org, CrossTenant: org != "platform", CreatedAt: nowRFC3339()}
		for _, c := range n.Callers {
			if c.Caller == caller {
				return 201, map[string]any{"data": c}, nil
			}
		}
		n.Callers = append(n.Callers, row)
		sort.Slice(n.Callers, func(i, j int) bool { return n.Callers[i].Caller < n.Callers[j].Caller })
		return 201, map[string]any{"data": row}, nil
	})

	handle("DELETE /projects/{project}/network/callers/{caller}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		caller, _ := url.PathUnescape(q.Segs[4])
		n := s.networkOf(p)
		for i, c := range n.Callers {
			if c.Caller == caller {
				n.Callers = append(n.Callers[:i], n.Callers[i+1:]...)
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	})
}
