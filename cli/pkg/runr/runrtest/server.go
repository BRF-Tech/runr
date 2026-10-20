// Package runrtest is an in-memory fake of the RunR API for tests of the runr CLI, the
// Terraform provider and anything else built on package runr. It answers in the shapes the
// real API uses (including which answers are wrapped in {"data": …}) and rejects every
// request that runr.Contract does not declare, so tests also prove that the client only
// calls declared endpoints with declared fields.
package runrtest

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// DefaultToken is the API token the fake accepts unless Server.Token is changed.
const DefaultToken = "runr_test_token"

// Call is one request the fake received.
type Call struct {
	Method string
	Path   string
	Query  string
	Body   map[string]any
	// Endpoint is the contract path template (/projects/{project}/deploy).
	Endpoint string
	// Raw is the request body as sent (binary uploads).
	Raw []byte
}

// Server is the fake API. Exported fields may be changed by tests before or between calls
// while holding no lock (tests are sequential); use the helper methods for state.
type Server struct {
	*httptest.Server
	t testing.TB

	// Token is the accepted bearer token.
	Token string
	// User is the authenticated user.
	User runr.User
	// Password is accepted by POST /auth/login for User.Username; TwoFactorCode, when set,
	// is required as well.
	Password      string
	TwoFactorCode string
	// Version is returned by GET /version.
	Version string
	// BuildPolls is how many GET /versions a built version stays "pending".
	BuildPolls int
	// DeployPolls is how many GET /deployments/{id} a deployment stays "pending".
	DeployPolls int
	// FailDeploy makes new deployments end in "failed" with FailMessage.
	FailDeploy  bool
	FailMessage string
	// FailBuild makes builds end in "failed".
	FailBuild bool
	// VerifyDomains makes POST …/verify succeed.
	VerifyDomains bool
	// SecretEnv makes the fake report and store secret variables (config.secret_env_keys,
	// config.secret_env_vars and the /projects/{project}/secrets endpoints). Default on; off,
	// the fake behaves like an API without secret variables.
	SecretEnv bool
	// RBAC enables role-based access control: GET /auth/permissions answers and scoped
	// tokens (POST /tokens with abilities, ScopedToken) get 403 outside their scopes. Off, the
	// fake behaves like an API without RBAC (GET /auth/permissions is 404). Default on.
	RBAC bool
	// Catalog enables the runtime catalog (GET /runtimes, templates); off, the fake answers
	// 404 like an API without runtime plugins. Default on.
	Catalog bool
	// LogLines is returned by the logs endpoint, one batch per call (then empty answers).
	LogLines []string

	mu    sync.Mutex
	valid map[string]bool
	// expires holds the expiry of API tokens created with expires_at (ExpireToken moves it)
	expires   map[string]time.Time
	scopes    map[string][]string
	bearer    string
	seq       int
	calls     []Call
	regions   []*runr.Region
	projects  []*project
	tokens    []*token
	images    []runr.ExecutorImage
	logCalls  int
	idCounter int
	// features holds the state of the feature endpoints (featureState).
	features map[string]any
}

type project struct {
	data        runr.Project
	config      map[string]any
	secrets     map[string]string
	files       map[string]string
	versions    []*version
	deployments []*deployment
	domains     []*runr.Domain
}

type version struct {
	data       runr.Version
	files      map[string]string
	pollsLeft  int
	buildFails bool
}

type deployment struct {
	data      runr.Deployment
	pollsLeft int
	fails     bool
	failMsg   string
}

type token struct {
	data  runr.Token
	plain string
}

// New starts a fake API with one active region "eu-1" and the runtimes python, nodejs, go
// and php. It is closed when the test ends.
func New(t testing.TB) *Server {
	t.Helper()
	s := &Server{
		t:           t,
		Token:       DefaultToken,
		User:        runr.User{ID: "1", Name: "Ada Lovelace", Username: "ada", Email: "ada@example.com"},
		Password:    "secret-password",
		Version:     "v0.5.0",
		BuildPolls:  1,
		DeployPolls: 1,
		FailMessage: "RevisionFailed: container exited with code 1",
		LogLines:    []string{"RunR Python runtime listening on :8080\n"},
		valid:       map[string]bool{},
		expires:     map[string]time.Time{},
		scopes:      map[string][]string{},
		RBAC:        true,
		Catalog:     true,
		SecretEnv:   true,
	}
	s.images = []runr.ExecutorImage{
		{ID: s.newID(), Runtime: "go", Version: "1.26", IsDefault: true, SortOrder: 1},
		{ID: s.newID(), Runtime: "nodejs", Version: "22", SortOrder: 1},
		{ID: s.newID(), Runtime: "nodejs", Version: "24", IsDefault: true, SortOrder: 2},
		{ID: s.newID(), Runtime: "php", Version: "8.4", IsDefault: true, SortOrder: 1},
		{ID: s.newID(), Runtime: "python", Version: "3.12", SortOrder: 1, EOLAt: "2028-10-31"},
		{ID: s.newID(), Runtime: "python", Version: "3.13", IsDefault: true, SortOrder: 2},
		{ID: s.newID(), Runtime: "q", Version: "1.0", IsDefault: true, SortOrder: 1},
	}
	s.AddRegion("eu-1", true)
	s.Server = httptest.NewServer(http.HandlerFunc(s.serve))
	t.Cleanup(s.Close)
	return s
}

// authorized reports whether a bearer token is accepted: Token, a session from POST
// /auth/login or a token from POST /tokens that was not revoked.
func (s *Server) authorized(bearer string) bool {
	if bearer == "" {
		return false
	}
	if bearer == s.Token && !s.revoked(bearer) {
		return true
	}
	if exp, ok := s.expires[bearer]; ok && !time.Now().Before(exp) {
		return false
	}
	return s.valid[bearer]
}

// ExpireToken lets the API token created with the plain value expire (as if its expires_at had
// passed): the API answers it with 401 from then on.
func (s *Server) ExpireToken(plain string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	exp := time.Now().Add(-time.Minute).UTC().Truncate(time.Second)
	s.expires[plain] = exp
	for _, t := range s.tokens {
		if t.plain == plain {
			t.data.ExpiresAt = exp.Format(time.RFC3339)
		}
	}
}

func (s *Server) revoked(bearer string) bool {
	v, ok := s.valid[bearer]
	return ok && !v
}

// URL of the console (the client appends /api).
func (s *Server) ConsoleURL() string { return s.Server.URL }

func (s *Server) newID() string {
	s.idCounter++
	// 26 characters, Crockford base32 alphabet: shaped like a ULID.
	return fmt.Sprintf("01J%023d", s.idCounter)
}

// AddRegion adds a region and returns it.
func (s *Server) AddRegion(name string, active bool) *runr.Region {
	s.mu.Lock()
	defer s.mu.Unlock()
	r := &runr.Region{ID: s.newID(), Name: name, IsActive: active, RegionStatus: map[bool]string{true: "active", false: "inactive"}[active],
		RegionMode: "managed", KnativeStatus: "ready", HasRedis: true, TotalCPUm: 8000, TotalMemoryMB: 16384, ProjectNamespace: name}
	s.regions = append(s.regions, r)
	return r
}

// SeedProject creates a project directly in the fake's state (no request is recorded).
func (s *Server) SeedProject(name, serviceType, runtime, regionName string, config map[string]any) runr.Project {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = "" // helpers see everything
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	p := s.createProject(map[string]any{"name": name, "service_type": serviceType, "runtime": runtime,
		"knative_region_id": reg.ID, "config": config})
	return s.projectResource(p)
}

// SeedFiles saves files as a new version of a faas project and returns the version.
func (s *Server) SeedFiles(projectName string, files map[string]string) runr.Version {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	v := s.saveVersion(p, files, "seed", false)
	v.data.BuildStatus = runr.BuildReady
	v.pollsLeft = 0
	return v.data
}

// SeedDeployment marks a version deployed and active.
func (s *Server) SeedDeployment(projectName string, versionNumber int) runr.Deployment {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	var v *version
	for _, x := range p.versions {
		if x.data.Number == versionNumber {
			v = x
		}
	}
	if v == nil {
		s.t.Fatalf("runrtest: version %d of %q does not exist", versionNumber, projectName)
	}
	d := s.startDeployment(p, v)
	d.pollsLeft = 0
	d.fails = false
	s.finishDeployment(p, d)
	return d.data
}

// SeedDomain adds a custom domain to a project.
func (s *Server) SeedDomain(projectName, host string) runr.Domain {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	return *s.addDomain(p, host)
}

// SeedStop stops a project as an administrator would (POST /projects/{project}/stop): level
// runr.StopLevelPlatform or runr.StopLevelOrganization; by names who stopped it (empty: nobody
// named, as a member sees a platform stop). Deploys of the project are refused until the stop is
// lifted (by == "" and reason == "" lifts it).
func (s *Server) SeedStop(projectName, reason, level, by string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if reason == "" && by == "" {
		p.data.Stopped = nil
		return
	}
	now := time.Now().UTC().Truncate(time.Second)
	p.data.Stopped = &runr.ProjectStop{At: &now, Reason: reason, Level: level}
	if by != "" {
		p.data.Stopped.By = &runr.Ref{ID: s.newID(), Name: by}
	}
}

// Project returns the current resource of a project by name.
func (s *Server) Project(name string) (runr.Project, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = "" // helpers see everything
	p := s.projectByName(name)
	if p == nil {
		return runr.Project{}, false
	}
	return s.projectResource(p), true
}

// Files returns a project's latest files.
func (s *Server) Files(name string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(name)
	if p == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range p.files {
		out[k] = v
	}
	return out
}

// Secrets returns the secret variables the fake stored for a project.
func (s *Server) Secrets(name string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(name)
	if p == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range p.secrets {
		out[k] = v
	}
	return out
}

// Deployments returns a project's deployments, newest first.
func (s *Server) Deployments(name string) []runr.Deployment {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(name)
	if p == nil {
		return nil
	}
	out := make([]runr.Deployment, 0, len(p.deployments))
	for i := len(p.deployments) - 1; i >= 0; i-- {
		out = append(out, p.deployments[i].data)
	}
	return out
}

// Calls returns the recorded requests.
func (s *Server) Calls() []Call {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Call(nil), s.calls...)
}

// CallsTo returns the recorded requests to a contract endpoint ("POST /projects/{project}/deploy").
func (s *Server) CallsTo(endpoint string) []Call {
	method, path, _ := strings.Cut(endpoint, " ")
	var out []Call
	for _, c := range s.Calls() {
		if c.Method == method && c.Endpoint == path {
			out = append(out, c)
		}
	}
	return out
}

// ResetCalls forgets the recorded requests.
func (s *Server) ResetCalls() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.calls = nil
}

// ---------------------------------------------------------------------------------------
// HTTP handling

type apiErr struct {
	status      int
	message     string
	errors      map[string][]string
	permissions []string
	// code and extra are the refusal's code and further fields ({message, code, reason, level})
	code  string
	extra map[string]any
}

func (e *apiErr) Error() string { return e.message }

func errValidation(field, msg string) *apiErr {
	return &apiErr{status: 422, message: msg, errors: map[string][]string{field: {msg}}}
}

func errNotFound() *apiErr { return &apiErr{status: 404, message: "Not found."} }

func (s *Server) serve(w http.ResponseWriter, r *http.Request) {
	if h, ok := rawRoutes[r.URL.Path]; ok {
		// Not part of the API (the web shell's WebSocket): the handler locks s.mu itself.
		h(s, w, r)
		return
	}
	escaped := r.URL.EscapedPath()
	path := strings.TrimPrefix(escaped, "/api")
	if path == escaped {
		s.write(w, 404, map[string]any{"message": "The API lives under /api."})
		return
	}
	ep := runr.MatchEndpoint(r.Method, path)
	var body map[string]any
	var raw []byte
	if r.Body != nil {
		raw, _ = io.ReadAll(r.Body)
		ct := r.Header.Get("Content-Type")
		jsonBody := ct == "" || strings.Contains(ct, "json")
		if jsonBody && len(strings.TrimSpace(string(raw))) > 0 {
			if err := json.Unmarshal(raw, &body); err != nil {
				s.write(w, 400, map[string]any{"message": "body is not a JSON object"})
				return
			}
		}
	}
	if ep == nil {
		s.t.Errorf("runrtest: %s %s is not declared in runr.Contract", r.Method, path)
		s.write(w, 501, map[string]any{"message": "endpoint not in runr.Contract"})
		return
	}
	if ep.RawBody && body != nil {
		s.t.Errorf("runrtest: %s %s expects a binary body, got JSON", ep.Method, ep.Path)
	}
	s.checkFields(ep, r, body)
	segs := strings.Split(strings.Trim(path, "/"), "/")
	for i, sg := range segs {
		if u, err := url.PathUnescape(sg); err == nil {
			segs[i] = u
		}
	}
	plainPath := "/" + strings.Join(segs, "/")

	s.mu.Lock()
	s.calls = append(s.calls, Call{Method: r.Method, Path: plainPath, Query: r.URL.RawQuery, Body: body, Endpoint: ep.Path, Raw: raw})
	s.mu.Unlock()

	s.mu.Lock()
	bearer := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if ep.Path != "/version" && ep.Path != "/auth/login" && !s.authorized(bearer) {
		s.mu.Unlock()
		s.write(w, 401, map[string]any{"message": "Unauthenticated."})
		return
	}
	s.bearer = bearer
	var status int
	var out any
	err := s.forbidden(ep, bearer, body)
	if err == nil {
		if h, ok := featureRoutes[r.Method+" "+ep.Path]; ok {
			status, out, err = h(s, &featureReq{R: r, Segs: segs, Body: body, Raw: raw})
		} else {
			status, out, err = s.route(ep.Path, r, segs, body)
		}
	}
	s.mu.Unlock()
	if err != nil {
		payload := map[string]any{"message": err.message}
		if err.errors != nil {
			payload["errors"] = err.errors
		}
		if err.permissions != nil {
			payload["permissions"] = err.permissions
		}
		if err.code != "" {
			payload["code"] = err.code
		}
		for k, v := range err.extra {
			payload[k] = v
		}
		s.write(w, err.status, payload)
		return
	}
	if status == 204 {
		w.WriteHeader(204)
		return
	}
	if ra, ok := out.(rawAnswer); ok {
		w.Header().Set("Content-Type", ra.contentType)
		if ra.filename != "" {
			w.Header().Set("Content-Disposition", `attachment; filename="`+ra.filename+`"`)
		}
		w.WriteHeader(status)
		_, _ = w.Write(ra.data)
		return
	}
	s.write(w, status, out)
}

// ---------------------------------------------------------------------------------------
// Feature endpoints
//
// The endpoints of a feature (workflows, bindings, models, …) live in their own file of this
// package: it registers a handler per contract endpoint in init (handle) and keeps its state
// in a struct that featureState returns. Handlers run with s.mu held; Seed helpers of a feature lock
// s.mu themselves.

// featureReq is one request handed to a feature handler.
type featureReq struct {
	R *http.Request
	// Segs are the unescaped path segments (projects, 01J…, workflows).
	Segs []string
	// Body is the JSON body (nil for none or a binary body); Raw the body as sent.
	Body map[string]any
	Raw  []byte
}

// str returns a string field of the body ("" when absent).
func (q *featureReq) str(key string) string {
	v, _ := q.Body[key].(string)
	return v
}

// query returns a query parameter.
func (q *featureReq) query(key string) string { return q.R.URL.Query().Get(key) }

// featureHandler answers one endpoint: status, JSON answer (or a rawAnswer), error.
type featureHandler func(s *Server, q *featureReq) (int, any, *apiErr)

var featureRoutes = map[string]featureHandler{}

// rawRoutes are paths outside /api the fake serves itself (exact path → handler), registered in
// init: the web shell's WebSocket (/ws/shell).
var rawRoutes = map[string]func(s *Server, w http.ResponseWriter, r *http.Request){}

// handle registers the handler of a contract endpoint ("GET /projects/{project}/secrets").
// Call it from init.
func handle(endpoint string, h featureHandler) {
	if _, dup := featureRoutes[endpoint]; dup {
		panic("runrtest: two handlers for " + endpoint)
	}
	featureRoutes[endpoint] = h
}

// rawAnswer is an answer that is not JSON (SBOM documents, exports).
type rawAnswer struct {
	contentType string
	filename    string
	data        []byte
}

// featureState returns the feature state stored under key, created with init on first use.
func featureState[T any](s *Server, key string, init func() *T) *T {
	if s.features == nil {
		s.features = map[string]any{}
	}
	if v, ok := s.features[key]; ok {
		return v.(*T)
	}
	v := init()
	s.features[key] = v
	return v
}

// projectOf resolves the {project} segment of a /projects/{project}/… request.
func (s *Server) projectOf(q *featureReq) (*project, *apiErr) {
	if len(q.Segs) < 2 || q.Segs[0] != "projects" {
		return nil, errNotFound()
	}
	p := s.projectByID(q.Segs[1])
	if p == nil {
		return nil, errNotFound()
	}
	return p, nil
}

// nowRFC3339 returns the current time as the API formats it (RFC 3339, UTC).
func nowRFC3339() string { return time.Now().UTC().Format(time.RFC3339) }

// checkFields fails the test when the client sends a body field or query parameter the
// contract entry does not declare.
func (s *Server) checkFields(ep *runr.Endpoint, r *http.Request, body map[string]any) {
	declared := map[string]bool{}
	for _, f := range append(append([]string{}, ep.Request...), ep.Capabilities...) {
		declared[f] = true
		declared[strings.TrimSuffix(strings.SplitN(f, ".", 2)[0], "[]")] = true
	}
	freeForm := map[string]bool{}
	for _, f := range ep.FreeForm {
		freeForm[f] = true
	}
	var walk func(prefix string, v map[string]any)
	walk = func(prefix string, v map[string]any) {
		for k, val := range v {
			name := prefix + k
			if !declared[name] && !declared[strings.TrimSuffix(name, "[]")] {
				// Free-form maps: env_vars / secret_env_vars / deploy config keys.
				if strings.HasSuffix(prefix, "env_vars.") {
					continue
				}
				s.t.Errorf("runrtest: %s %s sends undeclared field %q", ep.Method, ep.Path, name)
				continue
			}
			if freeForm[name] {
				continue
			}
			switch t := val.(type) {
			case map[string]any:
				if k == "env_vars" || k == "secret_env_vars" {
					continue
				}
				walk(name+".", t)
			case []any:
				for _, item := range t {
					if m, ok := item.(map[string]any); ok {
						walk(name+"[].", m)
					}
				}
			}
		}
	}
	walk("", body)
	for q := range r.URL.Query() {
		ok := false
		for _, d := range ep.Query {
			ok = ok || d == q
		}
		if !ok {
			s.t.Errorf("runrtest: %s %s sends undeclared query parameter %q", ep.Method, ep.Path, q)
		}
	}
}

func (s *Server) write(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func (s *Server) route(ep string, r *http.Request, segs []string, body map[string]any) (int, any, *apiErr) {
	switch r.Method + " " + ep {
	case "GET /version":
		return 200, map[string]any{"version": s.Version}, nil
	case "POST /auth/login":
		return s.login(body)
	case "POST /auth/logout":
		s.valid[s.bearer] = false
		return 200, map[string]any{"message": "Logged out."}, nil
	case "GET /auth/me":
		return 200, s.userJSON(), nil
	case "GET /auth/permissions":
		if !s.RBAC {
			return 0, nil, errNotFound()
		}
		return 200, s.permissionsJSON(), nil
	case "GET /tokens":
		list := []any{}
		for _, t := range s.tokens {
			list = append(list, t.data)
		}
		return 200, map[string]any{"tokens": list, "available_abilities": Catalog}, nil
	case "POST /tokens":
		return s.createToken(body)
	case "DELETE /tokens/{token}":
		for i, t := range s.tokens {
			if t.data.ID.String() == segs[1] {
				s.valid[t.plain] = false
				s.tokens = append(s.tokens[:i], s.tokens[i+1:]...)
				return 200, map[string]any{"message": "Token deleted."}, nil
			}
		}
		return 0, nil, &apiErr{status: 404, message: "Token not found."}
	// GET /runtimes…: runtimes.go
	case "GET /settings":
		return 200, map[string]any{"base_domain": "runr.test", "registration_enabled": true,
			"executor_images": s.images, "regions": s.regions}, nil
	case "GET /knative-regions":
		return 200, map[string]any{"data": s.regions}, nil
	case "GET /knative-regions/{region}":
		for _, reg := range s.regions {
			if reg.ID == segs[1] {
				return 200, map[string]any{"data": reg}, nil
			}
		}
		return 0, nil, errNotFound()
	case "GET /projects":
		list := []runr.Project{}
		for i := len(s.projects) - 1; i >= 0; i-- {
			list = append(list, s.projectResource(s.projects[i]))
		}
		return 200, map[string]any{"data": list}, nil
	case "POST /projects":
		if err := s.validateCreate(body); err != nil {
			return 0, nil, err
		}
		p := s.createProject(body)
		return 201, s.projectResource(p), nil
	}

	// Everything below is /projects/{project}/…
	if len(segs) < 2 || segs[0] != "projects" {
		return 0, nil, errNotFound()
	}
	p := s.projectByID(segs[1])
	if p == nil {
		return 0, nil, errNotFound()
	}
	switch r.Method + " " + ep {
	case "GET /projects/{project}":
		return 200, map[string]any{"data": s.projectResource(p)}, nil
	case "PUT /projects/{project}":
		if err := s.updateProject(p, body); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.projectResource(p)}, nil
	case "DELETE /projects/{project}":
		for i, x := range s.projects {
			if x == p {
				s.projects = append(s.projects[:i], s.projects[i+1:]...)
			}
		}
		return 204, nil, nil
	case "GET /projects/{project}/files", "GET /projects/{project}/code":
		return s.getCode(p, r.URL.Query().Get("version_id"), strings.HasSuffix(ep, "/code"))
	case "POST /projects/{project}/files":
		return s.saveFiles(p, body)
	case "POST /projects/{project}/code":
		return s.saveCode(p, body)
	case "GET /projects/{project}/versions":
		list := []runr.Version{}
		for i := len(p.versions) - 1; i >= 0; i-- {
			v := p.versions[i]
			s.advanceBuild(v)
			list = append(list, v.data)
		}
		return 200, map[string]any{"data": list}, nil
	case "GET /projects/{project}/versions/{version}/files":
		for _, v := range p.versions {
			if v.data.ID == segs[3] {
				return 200, map[string]any{"version": v.data, "files": fileMap(v.files)}, nil
			}
		}
		return 0, nil, errNotFound()
	case "GET /projects/{project}/deployments":
		list := []runr.Deployment{}
		for i := len(p.deployments) - 1; i >= 0; i-- {
			list = append(list, p.deployments[i].data)
		}
		return 200, map[string]any{"data": list}, nil
	case "POST /projects/{project}/deploy":
		return s.deploy(p, body)
	case "GET /projects/{project}/deployments/{deployment}":
		d := s.deploymentByID(p, segs[3])
		if d == nil {
			return 0, nil, errNotFound()
		}
		if d.pollsLeft > 0 {
			d.pollsLeft--
		} else if d.data.Status == runr.DeploymentPending {
			s.finishDeployment(p, d)
		}
		return 200, map[string]any{"data": d.data}, nil
	case "DELETE /projects/{project}/deployments/{deployment}":
		for i, d := range p.deployments {
			if d.data.ID == segs[3] {
				p.deployments = append(p.deployments[:i], p.deployments[i+1:]...)
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	case "GET /projects/{project}/deployments/{deployment}/logs":
		if s.deploymentByID(p, segs[3]) == nil {
			return 0, nil, errNotFound()
		}
		// Each call returns the next batch of LogLines (like Loki answering `since`); once
		// they are used up the answer is empty.
		idx := s.logCalls
		s.logCalls++
		logs := ""
		if idx < len(s.LogLines) {
			logs = s.LogLines[idx]
		}
		return 200, map[string]any{
			"pods":       []map[string]any{{"name": p.data.Slug + "-00001-deployment-abc", "status": "Running", "ready": true}},
			"logs":       logs,
			"last_ts_ms": 1700000000000 + int64(s.logCalls),
			"source":     "loki",
		}, nil
	case "GET /projects/{project}/domains":
		list := []runr.Domain{}
		for _, d := range p.domains {
			list = append(list, *d)
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{
			"eligible": true, "reason": nil, "cert_manager_issuer": "letsencrypt",
			"default_host": p.data.Slug + "." + s.regionName(p) + ".runr.test",
			"dns_targets":  []map[string]any{{"type": "CNAME", "value": p.data.Slug + "." + s.regionName(p) + ".runr.test"}},
		}}, nil
	case "POST /projects/{project}/domains":
		host, _ := body["domain"].(string)
		host = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(host), "."))
		if host == "" || !strings.Contains(host, ".") {
			return 0, nil, errValidation("domain", "The domain must be a valid host name.")
		}
		for _, x := range s.projects {
			for _, d := range x.domains {
				if d.Domain == host {
					return 0, nil, errValidation("domain", "This domain is already used.")
				}
			}
		}
		return 201, map[string]any{"data": s.addDomain(p, host)}, nil
	case "POST /projects/{project}/domains/{domain}/verify":
		d := s.domainByID(p, segs[3])
		if d == nil {
			return 0, nil, errNotFound()
		}
		if !s.VerifyDomains {
			d.StatusMessage = "TXT record " + d.Verification.Name + " not found"
			return 0, nil, &apiErr{status: 422, message: d.StatusMessage}
		}
		d.Status = "active"
		d.StatusMessage = ""
		return 200, map[string]any{"message": "Domain is active.", "data": d}, nil
	case "PUT /projects/{project}/domains/{domain}/tls":
		d := s.domainByID(p, segs[3])
		if d == nil {
			return 0, nil, errNotFound()
		}
		mode, _ := body["mode"].(string)
		switch mode {
		case runr.TLSNone, runr.TLSCertManager:
		case runr.TLSCustom:
			if c, _ := body["certificate"].(string); !strings.Contains(c, "BEGIN CERTIFICATE") {
				return 0, nil, errValidation("certificate", "The certificate must be a PEM CERTIFICATE.")
			}
			if k, _ := body["privateKey"].(string); !strings.Contains(k, "PRIVATE KEY") {
				return 0, nil, errValidation("privateKey", "The private key must be a PEM PRIVATE KEY.")
			}
		default:
			return 0, nil, errValidation("mode", "The selected mode is invalid.")
		}
		d.TLSMode = mode
		d.HTTPSCertificate = map[string]string{runr.TLSNone: "region-default", runr.TLSCustom: "custom", runr.TLSCertManager: "cert-manager"}[mode]
		d.URL = map[bool]string{true: "http://", false: "https://"}[mode == runr.TLSNone] + d.Domain
		return 200, map[string]any{"message": "TLS updated.", "data": d}, nil
	case "DELETE /projects/{project}/domains/{domain}":
		for i, d := range p.domains {
			if d.ID == segs[3] {
				p.domains = append(p.domains[:i], p.domains[i+1:]...)
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	}
	return 0, nil, &apiErr{status: 405, message: "Method not allowed."}
}

// ---------------------------------------------------------------------------------------
// Behaviour

func (s *Server) userJSON() map[string]any {
	id, _ := strconv.Atoi(s.User.ID.String())
	return map[string]any{"id": id, "name": s.User.Name, "username": s.User.Username, "email": s.User.Email}
}

func (s *Server) login(body map[string]any) (int, any, *apiErr) {
	login, _ := body["login"].(string)
	pw, _ := body["password"].(string)
	if (login != s.User.Username && login != s.User.Email) || pw != s.Password {
		return 0, nil, &apiErr{status: 401, message: "These credentials do not match our records."}
	}
	if s.TwoFactorCode != "" {
		code, _ := body["two_factor_code"].(string)
		if code == "" {
			return 202, map[string]any{"requires_2fa": true}, nil
		}
		if code != s.TwoFactorCode {
			return 0, nil, &apiErr{status: 422, message: "Invalid two-factor code."}
		}
	}
	s.seq++
	session := fmt.Sprintf("session_%d", s.seq)
	s.valid[session] = true
	return 200, map[string]any{"user": s.userJSON(), "token": session}, nil
}

func (s *Server) createToken(body map[string]any) (int, any, *apiErr) {
	name, _ := body["name"].(string)
	if name == "" || name == "api" {
		return 0, nil, errValidation("name", "The name field is required.")
	}
	abilities := []string{"*"}
	if raw, ok := body["abilities"].([]any); ok && len(raw) > 0 {
		abilities = abilities[:0]
		for _, a := range raw {
			abilities = append(abilities, fmt.Sprint(a))
		}
	}
	s.seq++
	plain := fmt.Sprintf("%d|plain_token_%d", s.seq, s.seq)
	t := &token{plain: plain, data: runr.Token{ID: runr.FlexID(strconv.Itoa(s.seq)), Name: name, Abilities: abilities,
		CreatedAt: time.Now().UTC().Format(time.RFC3339)}}
	if e, ok := body["expires_at"].(string); ok {
		t.data.ExpiresAt = e
		if exp, err := time.Parse(time.RFC3339, e); err == nil {
			s.expires[plain] = exp
		}
	}
	s.tokens = append(s.tokens, t)
	s.valid[plain] = true
	if !(len(abilities) == 1 && abilities[0] == "*") {
		s.scopes[plain] = expand(abilities)
	}
	return 201, map[string]any{"plain_token": plain, "token": t.data}, nil
}

var validTypes = map[string]bool{runr.TypeFaaS: true, runr.TypeXaaS: true, runr.TypeCaaS: true}

func (s *Server) validateCreate(body map[string]any) *apiErr {
	name, _ := body["name"].(string)
	if name == "" {
		return errValidation("name", "The name field is required.")
	}
	for _, p := range s.projects {
		if p.data.Name == name {
			return errValidation("name", "The name has already been taken.")
		}
	}
	st, _ := body["service_type"].(string)
	if !validTypes[st] {
		return errValidation("service_type", "The selected service type is invalid.")
	}
	rid, _ := body["knative_region_id"].(string)
	if s.regionByID(rid) == nil {
		return errValidation("knative_region_id", "The selected knative region id is invalid.")
	}
	cfg, ok := body["config"].(map[string]any)
	if !ok {
		return errValidation("config", "The config field is required.")
	}
	if st == runr.TypeCaaS {
		if img, _ := cfg["image"].(string); img == "" {
			return errValidation("config.image", "The config.image field is required when service type is caas.")
		}
	}
	if rt, _ := body["runtime"].(string); rt != "" {
		found := false
		for _, img := range s.images {
			found = found || img.Runtime == rt
		}
		if !found {
			return errValidation("runtime", "Runtime "+rt+" is not available.")
		}
	}
	if _, secrets, ok := splitSecretEnv(cfg); ok && s.SecretEnv {
		if err := s.secretEnvErrors(nil, cfg, secrets); err != nil {
			return err
		}
	}
	return s.validateConfig(cfg)
}

func (s *Server) validateConfig(cfg map[string]any) *apiErr {
	if env, ok := cfg["env_vars"].(map[string]any); ok {
		for k := range env {
			if err := runr.ValidateEnvName(k); err != nil {
				return errValidation("config.env_vars", err.Error())
			}
		}
	}
	if tt, _ := cfg["trigger_type"].(string); tt != "" {
		switch tt {
		case runr.TriggerHTTP, runr.TriggerQueue, runr.TriggerSchedule:
		default:
			return errValidation("config.trigger_type", "The selected config.trigger type is invalid.")
		}
		if tt == runr.TriggerSchedule {
			if sch, _ := cfg["schedule"].(string); sch == "" {
				return errValidation("config.schedule", "The config.schedule field is required when config.trigger type is schedule.")
			}
		}
		if tt == runr.TriggerQueue {
			if q, _ := cfg["queue_stream"].(string); q == "" {
				return errValidation("config.queue_stream", "The config.queue stream field is required when config.trigger type is queue.")
			}
		}
	}
	if m, ok := cfg["memory_mb"].(float64); ok && m < 64 {
		return errValidation("config.memory_mb", "The config.memory mb field must be at least 64.")
	}
	return nil
}

func (s *Server) createProject(body map[string]any) *project {
	name, _ := body["name"].(string)
	st, _ := body["service_type"].(string)
	rt, _ := body["runtime"].(string)
	rv, _ := body["runtime_version"].(string)
	desc, _ := body["description"].(string)
	rid, _ := body["knative_region_id"].(string)
	if rt != "" && rv == "" {
		for _, img := range s.images {
			if img.Runtime == rt && img.IsDefault {
				rv = img.Version
			}
		}
	}
	cfg := map[string]any{}
	var secrets map[string]any
	if c, ok := body["config"].(map[string]any); ok {
		c, secrets, _ = splitSecretEnv(c)
		for k, v := range c {
			cfg[k] = v
		}
	}
	defaults := map[string]any{"memory_mb": float64(128), "cpu": "100m", "timeout_seconds": float64(30),
		"trigger_type": "http", "scale_to_zero": true, "min_scale": float64(0), "max_scale": float64(5)}
	switch st {
	case runr.TypeFaaS:
		defaults["handler"] = "main.handler"
	case runr.TypeXaaS:
		defaults["handler"] = "handler"
		defaults["filename"] = "main." + ext(rt)
	case runr.TypeCaaS:
		defaults["memory_mb"] = float64(256)
		defaults["image_pull_policy"] = "IfNotPresent"
		defaults["ports"] = []any{}
	}
	for k, v := range defaults {
		if _, ok := cfg[k]; !ok {
			cfg[k] = v
		}
	}
	if _, ok := cfg["env_vars"]; !ok {
		cfg["env_vars"] = map[string]any{}
	}
	p := &project{
		data: runr.Project{ID: s.newID(), Name: name, Slug: slugify(name), ServiceType: st, Runtime: rt,
			RuntimeVersion: rv, Description: desc, RegionID: rid, EndpointType: "subdomain"},
		config:  cfg,
		secrets: map[string]string{},
		files:   map[string]string{},
	}
	now := time.Now().UTC()
	p.data.CreatedAt = &now
	s.projects = append(s.projects, p)
	if len(secrets) > 0 && s.SecretEnv {
		s.applySecretEnv(p, secrets)
	}
	if st == runr.TypeCaaS {
		s.saveVersion(p, nil, "Initial config", true)
	}
	return p
}

func (s *Server) updateProject(p *project, body map[string]any) *apiErr {
	if d, ok := body["description"].(string); ok {
		p.data.Description = d
	}
	if rid, ok := body["knative_region_id"].(string); ok {
		if s.regionByID(rid) == nil {
			return errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		p.data.RegionID = rid
	}
	cfg, ok := body["config"].(map[string]any)
	if !ok || len(cfg) == 0 {
		return nil
	}
	if err := s.validateConfig(cfg); err != nil {
		return err
	}
	// Secret variables go to the project's secrets, never into the config or a version
	// (ProjectController::takeSecretEnv); an API without them ignores the field.
	cfg, secrets, hasSecrets := splitSecretEnv(cfg)
	if s.SecretEnv {
		if err := s.secretEnvErrors(p, cfg, secrets); err != nil {
			return err
		}
	}
	for k, v := range cfg {
		p.config[k] = v
	}
	if hasSecrets && s.SecretEnv {
		s.applySecretEnv(p, secrets)
	}
	if len(cfg) == 0 {
		return nil // only secret variables changed: no new version
	}
	msg, _ := body["config_message"].(string)
	if msg == "" {
		msg = "Config updated"
	}
	files := map[string]string{}
	for k, v := range p.files {
		files[k] = v
	}
	s.saveVersion(p, files, msg, true)
	return nil
}

func (s *Server) getCode(p *project, versionID string, single bool) (int, any, *apiErr) {
	var v *version
	if versionID != "" {
		for _, x := range p.versions {
			if x.data.ID == versionID {
				v = x
			}
		}
		if v == nil {
			return 0, nil, errNotFound()
		}
	} else if len(p.versions) > 0 {
		v = p.versions[len(p.versions)-1]
	}
	files := map[string]string{}
	isDefault := v == nil
	if v != nil {
		files = v.files
	} else {
		files[s.mainFile(p)] = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"Hello, World!\"}"
	}
	out := map[string]any{"version": nil}
	if v != nil {
		out["version"] = v.data
	}
	if isDefault {
		out["is_default"] = true
	}
	if single {
		out["code"] = files[s.mainFile(p)]
	} else {
		out["files"] = fileMap(files)
	}
	return 200, out, nil
}

func (s *Server) saveFiles(p *project, body map[string]any) (int, any, *apiErr) {
	if p.data.ServiceType != runr.TypeFaaS {
		return 0, nil, errNotFound()
	}
	raw, _ := body["files"].([]any)
	if len(raw) == 0 {
		return 0, nil, errValidation("files", "The files field is required.")
	}
	files := map[string]string{}
	size := 0
	for i, item := range raw {
		m, _ := item.(map[string]any)
		name, _ := m["name"].(string)
		content, ok := m["content"].(string)
		if name == "" || strings.Contains(name, "/") || strings.HasPrefix(name, "..") {
			return 0, nil, errValidation(fmt.Sprintf("files.%d.name", i), "The file name "+name+" is invalid.")
		}
		if !ok || content == "" {
			return 0, nil, errValidation(fmt.Sprintf("files.%d.content", i), "The content field is required.")
		}
		files[name] = content
		size += len(name) + len(content)
	}
	if size > runr.MaxCodeBytes {
		return 0, nil, errValidation("files", "The code is too large.")
	}
	main := s.mainFile(p)
	if _, ok := files[main]; !ok {
		return 0, nil, errValidation("files", "The main file "+main+" cannot be deleted.")
	}
	msg, _ := body["message"].(string)
	if msg == "" {
		names := make([]string, 0, len(files))
		for n := range files {
			names = append(names, n)
		}
		sort.Strings(names)
		msg = "update: " + strings.Join(names, ", ")
	}
	v := s.saveVersion(p, files, msg, false)
	return 201, map[string]any{"message": "Files saved.", "version": v.data}, nil
}

func (s *Server) saveCode(p *project, body map[string]any) (int, any, *apiErr) {
	if p.data.ServiceType != runr.TypeXaaS {
		return 0, nil, errNotFound()
	}
	code, _ := body["code"].(string)
	if code == "" {
		return 0, nil, errValidation("code", "The code field is required.")
	}
	msg, _ := body["message"].(string)
	if msg == "" {
		msg = "update: " + s.mainFile(p)
	}
	v := s.saveVersion(p, map[string]string{s.mainFile(p): code}, msg, false)
	return 201, map[string]any{"message": "Code saved.", "version": v.data}, nil
}

// saveVersion records a version; unchanged code returns the latest version (GitService).
func (s *Server) saveVersion(p *project, files map[string]string, message string, configChange bool) *version {
	if !configChange && len(p.versions) > 0 && equalFiles(p.files, files) {
		return p.versions[len(p.versions)-1]
	}
	if files != nil {
		p.files = files
	}
	n := len(p.versions) + 1
	now := time.Now().UTC()
	v := &version{files: map[string]string{}}
	for k, val := range p.files {
		v.files[k] = val
	}
	snap := map[string]any{"name": p.data.Name, "runtime": p.data.Runtime}
	for k, val := range p.config {
		snap[k] = val
	}
	v.data = runr.Version{ID: s.newID(), Number: n, Label: "v" + strconv.Itoa(n), CommitID: fmt.Sprintf("%040d", n),
		CommitMessage: message, BuildStatus: runr.BuildReady, ConfigSnapshot: snap, CreatedAt: &now,
		CreatedBy: &runr.Ref{ID: s.User.ID.String(), Name: s.User.Name}}
	for k := range v.files {
		v.data.ChangedFiles = append(v.data.ChangedFiles, k)
	}
	sort.Strings(v.data.ChangedFiles)
	if !configChange && s.needsBuild(p, v.files) {
		v.data.BuildStatus = runr.BuildPending
		v.pollsLeft = s.BuildPolls
		v.buildFails = s.FailBuild
	}
	p.versions = append(p.versions, v)
	return v
}

func (s *Server) advanceBuild(v *version) {
	if v.data.BuildStatus != runr.BuildPending && v.data.BuildStatus != runr.BuildBuilding {
		return
	}
	if v.pollsLeft > 0 {
		v.pollsLeft--
		v.data.BuildStatus = runr.BuildBuilding
		return
	}
	if v.buildFails {
		v.data.BuildStatus = runr.BuildFailed
		v.data.BuildError = "pip install failed: No matching distribution found"
		return
	}
	v.data.BuildStatus = runr.BuildReady
}

func (s *Server) needsBuild(p *project, files map[string]string) bool {
	if p.data.Runtime == "go" {
		return true
	}
	dep := map[string]string{"python": "requirements.txt", "nodejs": "package.json", "php": "composer.json"}[p.data.Runtime]
	_, ok := files[dep]
	return dep != "" && ok
}

func (s *Server) deploy(p *project, body map[string]any) (int, any, *apiErr) {
	if st := p.data.Stopped; st != nil {
		// ProjectAdmission::assertDeployable
		return 0, nil, &apiErr{status: 409, code: "project_stopped",
			message: "This project is stopped and cannot be deployed until it is resumed. Reason: " + st.Reason,
			extra:   map[string]any{"reason": st.Reason, "level": st.Level}}
	}
	vid, _ := body["version_id"].(string)
	var v *version
	for _, x := range p.versions {
		if x.data.ID == vid {
			v = x
		}
	}
	if v == nil {
		return 0, nil, errValidation("version_id", "The selected version id is invalid.")
	}
	if v.data.BuildStatus != runr.BuildReady {
		return 0, nil, &apiErr{status: 422, message: "Version is not ready (status: " + v.data.BuildStatus + ")."}
	}
	// Built into a registry the region no longer pulls from: built again, deployed when ready
	if s.queuedSt().rebuild[v.data.ID] {
		return s.queueRebuild(p, v, body)
	}
	d := s.startDeployment(p, v)
	if dc, ok := body["deploy_config"].(map[string]any); ok {
		d.data.DeployConfig = dc
	}
	return 202, d.data, nil
}

func (s *Server) startDeployment(p *project, v *version) *deployment {
	for _, d := range p.deployments {
		if d.data.Status == runr.DeploymentActive {
			d.data.Status = runr.DeploymentShuttingDown
		}
	}
	now := time.Now().UTC()
	d := &deployment{pollsLeft: s.DeployPolls, fails: s.FailDeploy, failMsg: s.FailMessage}
	d.data = runr.Deployment{ID: s.newID(), Status: runr.DeploymentPending, K8sJobName: "run-" + p.data.Slug + "-" + v.data.Label,
		DeployedBy: s.User.Username, Version: &runr.Ref{ID: v.data.ID, Name: v.data.Label}, VersionLabel: v.data.Label, CreatedAt: &now}
	p.deployments = append(p.deployments, d)
	return d
}

func (s *Server) finishDeployment(p *project, d *deployment) {
	if d.fails {
		f := false
		d.data.Status = runr.DeploymentFailed
		d.data.KnativeReady = &f
		d.data.KnativeMessage = d.failMsg
		for _, x := range p.deployments {
			if x.data.Status == runr.DeploymentShuttingDown {
				x.data.Status = runr.DeploymentActive
			}
		}
		return
	}
	t := true
	now := time.Now().UTC()
	d.data.Status = runr.DeploymentActive
	d.data.KnativeReady = &t
	d.data.ReadyAt = &now
	for _, x := range p.deployments {
		if x != d && (x.data.Status == runr.DeploymentShuttingDown || x.data.Status == runr.DeploymentActive) {
			x.data.Status = runr.DeploymentShutDown
		}
	}
}

func (s *Server) addDomain(p *project, host string) *runr.Domain {
	now := time.Now().UTC().Format(time.RFC3339)
	d := &runr.Domain{ID: s.newID(), ProjectID: p.data.ID, Domain: host, Status: "pending", TLSMode: runr.TLSNone,
		HTTPSCertificate: "region-default", URL: "http://" + host,
		Verification: runr.DNSRecord{Type: "TXT", Name: "_runr-challenge." + host, Value: "runr-verify=" + p.data.ID},
		DNSTargets:   []runr.DNSRecord{{Type: "CNAME", Name: host, Value: p.data.Slug + "." + s.regionName(p) + ".runr.test"}},
		CreatedAt:    now, UpdatedAt: now}
	p.domains = append(p.domains, d)
	return d
}

// ---------------------------------------------------------------------------------------
// Resources and lookups

func (s *Server) projectResource(p *project) runr.Project {
	out := p.data
	cfgJSON, _ := json.Marshal(p.config)
	var cfg runr.ProjectConfig
	if s.SecretEnv {
		m := map[string]any{}
		_ = json.Unmarshal(cfgJSON, &m)
		keys := make([]string, 0, len(p.secrets))
		for k := range p.secrets {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		if s.holds("env.view") {
			m["secret_env_keys"] = keys
		} else {
			m["secret_env_keys"] = nil // ProjectResource: names only for env.view
		}
		cfgJSON, _ = json.Marshal(m)
	}
	if !s.holds("env.view") {
		// EnvVisibility::mask
		m := map[string]any{}
		_ = json.Unmarshal(cfgJSON, &m)
		m["env_vars"] = nil
		m["env_vars_hidden"] = true
		cfgJSON, _ = json.Marshal(m)
	}
	_ = json.Unmarshal(cfgJSON, &cfg)
	out.Config = cfg
	if reg := s.regionByID(p.data.RegionID); reg != nil {
		out.Region = &runr.Ref{ID: reg.ID, Name: reg.Name}
	}
	if cfg.Trigger() == runr.TriggerHTTP {
		out.EndpointURL = "https://" + p.data.Slug + "." + s.regionName(p) + ".runr.test"
	} else {
		out.EndpointURL = ""
	}
	if n := len(p.versions); n > 0 {
		v := p.versions[n-1].data
		out.LatestVersion = &v
	} else {
		out.LatestVersion = nil
	}
	if n := len(p.deployments); n > 0 {
		d := p.deployments[n-1]
		ds := &runr.DeploymentStatus{Status: d.data.Status}
		for _, v := range p.versions {
			if d.data.Version != nil && v.data.ID == d.data.Version.ID {
				vv := v.data
				ds.Version = &vv
			}
		}
		out.LatestDeployment = ds
	} else {
		out.LatestDeployment = nil
	}
	return out
}

func (s *Server) projectByID(id string) *project {
	for _, p := range s.projects {
		if p.data.ID == id {
			return p
		}
	}
	return nil
}

func (s *Server) projectByName(name string) *project {
	for _, p := range s.projects {
		if p.data.Name == name {
			return p
		}
	}
	return nil
}

func (s *Server) deploymentByID(p *project, id string) *deployment {
	for _, d := range p.deployments {
		if d.data.ID == id {
			return d
		}
	}
	return nil
}

func (s *Server) domainByID(p *project, id string) *runr.Domain {
	for _, d := range p.domains {
		if d.ID == id {
			return d
		}
	}
	return nil
}

func (s *Server) regionByID(id string) *runr.Region {
	for _, r := range s.regions {
		if r.ID == id {
			return r
		}
	}
	return nil
}

func (s *Server) regionByName(name string) *runr.Region {
	for _, r := range s.regions {
		if r.Name == name {
			return r
		}
	}
	return nil
}

func (s *Server) regionName(p *project) string {
	if r := s.regionByID(p.data.RegionID); r != nil {
		return r.Name
	}
	return "region"
}

func (s *Server) mainFile(p *project) string {
	if p.data.ServiceType == runr.TypeXaaS {
		if f, _ := p.config["filename"].(string); f != "" {
			return f
		}
		return "main." + ext(p.data.Runtime)
	}
	h, _ := p.config["handler"].(string)
	if h == "" {
		h = "main.handler"
	}
	base, _, _ := strings.Cut(h, ".")
	return base + "." + ext(p.data.Runtime)
}

func ext(runtime string) string {
	switch runtime {
	case "nodejs":
		return "js"
	case "go":
		return "go"
	case "php":
		return "php"
	case "q":
		return "q"
	}
	return "py"
}

func slugify(name string) string {
	return strings.ToLower(strings.ReplaceAll(name, "_", "-"))
}

func equalFiles(a, b map[string]string) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if b[k] != v {
			return false
		}
	}
	return true
}

// fileMap encodes files the way PHP does: {} as [] when empty.
func fileMap(files map[string]string) any {
	if len(files) == 0 {
		return []any{}
	}
	return files
}
