package runrtest

import "github.com/brf-tech/runr/cli/pkg/runr"

// Without makes endpoints answer 404 like an API that does not have them (an older release, a
// module that is off): "GET /projects/{project}/rightsizing", as the contract names them.
func (s *Server) Without(endpoints ...string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	m := s.withoutState()
	for _, e := range endpoints {
		if ep := findEndpoint(e); ep == nil {
			s.t.Fatalf("runrtest: Without(%q): no such contract endpoint", e)
		}
		m[e] = true
	}
}

func (s *Server) withoutState() map[string]bool {
	return *featureState(s, "without", func() *map[string]bool { m := map[string]bool{}; return &m })
}

// absent reports whether Without took the endpoint away (called with s.mu held).
func (s *Server) absent(ep *runr.Endpoint) bool {
	return s.withoutState()[ep.Method+" "+ep.Path]
}

func findEndpoint(e string) *runr.Endpoint {
	for i := range runr.Contract {
		if runr.Contract[i].Method+" "+runr.Contract[i].Path == e {
			return &runr.Contract[i]
		}
	}
	return nil
}
