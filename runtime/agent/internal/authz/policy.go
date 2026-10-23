package authz

import (
	"strings"
	"sync"
)

// Annotations and labels of a Knative Service RunR manages on the internal network.
const (
	LabelManaged      = "runr.dev/internal-network"
	AnnotationName    = "runr.dev/internal-name"
	AnnotationOrg     = "runr.dev/internal-org"
	AnnotationCallers = "runr.dev/internal-callers"
	AnnotationKind    = "runr.dev/internal-kind"

	ModeEnforce = "enforce"
	ModeAudit   = "audit"

	// InternalDomain is the zone of the <project>.<organization> hosts.
	InternalDomain = "runr.internal"
)

// Service is the policy of one Knative Service.
type Service struct {
	// Name is the internal name <project>.<organization>; Org its organization.
	Name      string
	Org       string
	Namespace string
	Service   string
	// Kind is project, model or preview (a preview environment has no runr.internal host)
	Kind    string
	Callers map[string]bool
	// ProjectID is the project's id (label runr/project-id); empty for a model service or preview
	ProjectID string
}

// Policy is everything the authz decides with: the services by host, the regions' keys, the
// mode of each project namespace and the platform's cross-organization switch.
type Policy struct {
	Services    map[string]*Service
	Keys        map[string][]byte
	Modes       map[string]string
	CrossTenant bool
	// ProjectNames names a project's pods by "<namespace>/<project id>" (pods deployed before
	// they carried their internal name)
	ProjectNames map[string]string
}

// NewService builds a service's policy from its metadata; ok is false for a Knative Service
// RunR does not manage.
func NewService(namespace, name string, annotations map[string]string) (*Service, bool) {
	internal := annotations[AnnotationName]
	if internal == "" {
		return nil, false
	}
	s := &Service{
		Name:      internal,
		Org:       annotations[AnnotationOrg],
		Namespace: namespace,
		Service:   name,
		Kind:      annotations[AnnotationKind],
		Callers:   map[string]bool{},
	}
	if s.Org == "" {
		s.Org = orgOf(internal)
	}
	for _, c := range strings.Split(annotations[AnnotationCallers], ",") {
		if c = strings.ToLower(strings.TrimSpace(c)); c != "" {
			s.Callers[c] = true
		}
	}
	return s, true
}

// Hosts a service answers on the internal network: Knative's cluster-local hosts and, for a
// project, <project>.<organization>.runr.internal.
func (s *Service) Hosts() []string {
	hosts := []string{
		s.Service + "." + s.Namespace,
		s.Service + "." + s.Namespace + ".svc",
		s.Service + "." + s.Namespace + ".svc.cluster.local",
	}
	if s.Kind == "project" && strings.Contains(s.Name, ".") {
		hosts = append(hosts, s.Name+"."+InternalDomain)
	}
	return hosts
}

// Store holds the current policy for concurrent readers.
type Store struct {
	mu     sync.RWMutex
	policy *Policy
}

// Set replaces the policy.
func (st *Store) Set(p *Policy) {
	st.mu.Lock()
	st.policy = p
	st.mu.Unlock()
}

// Get returns the current policy (nil before the first load).
func (st *Store) Get() *Policy {
	st.mu.RLock()
	defer st.mu.RUnlock()
	return st.policy
}

// Build indexes services by every host they answer on.
func Build(services []*Service, keys map[string][]byte, modes map[string]string, crossTenant bool) *Policy {
	p := &Policy{Services: map[string]*Service{}, Keys: keys, Modes: modes, CrossTenant: crossTenant, ProjectNames: map[string]string{}}
	for _, s := range services {
		for _, h := range s.Hosts() {
			p.Services[h] = s
		}
		if s.Kind == "project" && s.ProjectID != "" {
			p.ProjectNames[s.Namespace+"/"+s.ProjectID] = s.Name
		}
	}
	return p
}

// NameOf is the internal name of the project a pod belongs to.
func (p *Policy) NameOf(id PodIdentity) (string, bool) {
	if id.Name != "" {
		return id.Name, true
	}
	name, ok := p.ProjectNames[id.Namespace+"/"+id.ProjectID]
	return name, ok
}

// tagged finds the service of a Knative traffic-tag host, <tag>-<service>.<namespace>[.svc[.cluster.local]]:
// the same service behind another name, under the same policy.
func (p *Policy) tagged(host string) (*Service, bool) {
	label, rest, found := strings.Cut(host, ".")
	if !found {
		return nil, false
	}
	for h, s := range p.Services {
		first, hostRest, _ := strings.Cut(h, ".")
		if hostRest == rest && first == s.Service && strings.HasSuffix(label, "-"+s.Service) {
			return s, true
		}
	}
	return nil, false
}

// Decision of a call.
type Decision struct {
	// Allowed by the policy; Admit is what the authz answers (audit mode admits denials).
	Allowed bool
	Admit   bool
	// Status is the HTTP status of a refusal (401 without a valid token, 403 not allowed).
	Status int
	Caller string
	// Via is how the caller was identified: token, or address (a pod of a project, no token)
	Via    string
	Reason string
	// Managed is false for a host RunR does not manage (admitted as it is).
	Managed bool
}

// Decide checks a call to host. A caller is its token's subject or, when it sends no token, the
// project whose pod has its address (identify; nil: tokens only).
func (p *Policy) Decide(host, token string, verify func(string) (*Claims, error), identify func() (string, bool)) Decision {
	host = strings.ToLower(host)
	if i := strings.IndexByte(host, ':'); i >= 0 {
		host = host[:i]
	}
	s, ok := p.Services[host]
	if !ok {
		s, ok = p.tagged(host)
	}
	if !ok {
		return Decision{Allowed: true, Admit: true, Reason: "not managed"}
	}
	// enforce unless the region says audit (an operator who wants to look first)
	mode := p.Modes[s.Namespace]
	if mode != ModeAudit {
		mode = ModeEnforce
	}
	deny := func(status int, caller, reason string) Decision {
		return Decision{Status: status, Caller: caller, Reason: reason, Managed: true, Admit: mode == ModeAudit}
	}
	refuse := func(status int, caller, via, reason string) Decision {
		d := deny(status, caller, reason)
		d.Via = via
		return d
	}

	var caller, via string
	switch {
	case token != "":
		claims, err := verify(token)
		if err != nil {
			return deny(401, "", err.Error())
		}
		caller, via = strings.ToLower(claims.Subject), "token"
	case identify != nil:
		name, ok := identify()
		if !ok {
			return deny(401, "", "no token, and the caller's address is no pod of a project")
		}
		caller, via = strings.ToLower(name), "address"
	default:
		return deny(401, "", errMissing.Error())
	}
	allow := Decision{Allowed: true, Admit: true, Caller: caller, Via: via, Managed: true}
	switch {
	case caller == SystemSubject:
		allow.Reason = "runr"
		return allow
	case caller == s.Name:
		allow.Reason = "self"
		return allow
	case !s.Callers[caller]:
		return refuse(403, caller, via, caller+" is not on the allow list of "+s.Name)
	case orgOf(caller) != s.Org && !p.CrossTenant:
		return refuse(403, caller, via, "cross-organization grants are switched off")
	}
	allow.Reason = "allow list"
	return allow
}
