package runrtest

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"math"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// API product of the gateways (GatewayController, GatewayDomainController, ApiConsumerController,
// ApiKeyController, ApiPlanController, ApiUsageController). The fake keeps gateways, routes,
// consumers, keys and plans in memory and answers like the API: its validation and refusals, the
// burst a plan or rate limit falls back to, a key's status from its revocation and expiry, a
// custom domain waiting in custom_domain_pending until it verifies (VerifyDomains), a route
// created with its API product settings and the route as the gateway shows it (url, jwt) as the
// answer of adding or changing a route — or, without RouteSettings, an API before 0.6.0. Gateways
// can also be seeded (SeedGateway: routes to projects the fake does not hold), usage with SeedUsage.

type apiProductState struct {
	gateways  []*fakeGateway
	consumers []*runr.APIConsumer
	keys      []*runr.APIKey
	plans     []*runr.APIPlan
	usage     []runr.UsageRow
}

// fakeGateway is a gateway as stored: a rate limit without its own burst (the answer shows the
// average), the requested domain with its token.
type fakeGateway struct {
	// data without routes, rate limit, pending domain and page key (gatewayView adds them)
	data        runr.Gateway
	rateAverage int
	rateBurst   int
	pending     string
	token       string
	routes      []*fakeRoute
}

// fakeRoute is a route as stored: its JWT settings stay when it switches to another mode.
type fakeRoute struct {
	// data without url and jwt; Environment / EnvironmentID name the environment it leads to
	data      runr.GatewayRoute
	jwt       map[string]any
	seeded    bool // SeedGateway: its project is not in the fake
	sortOrder int
	createdAt string
}

func (s *Server) apiProductState() *apiProductState {
	return featureState(s, "api-product", func() *apiProductState { return &apiProductState{} })
}

// SeedGateway adds a gateway in region with one route per path (each routed to a new project id).
func (s *Server) SeedGateway(region string, paths ...string) runr.Gateway {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.apiProductState()
	g := &fakeGateway{data: runr.Gateway{
		ID: s.newID(), Subdomain: s.User.Username, Status: "active", RegionID: s.newID(),
		Region: &runr.GatewayRegion{Name: region}, CreatedAt: nowRFC3339(),
	}}
	g.data.Region.ID = g.data.RegionID
	g.data.Host = g.data.Subdomain + "." + region + ".runr.test"
	for i, p := range paths {
		g.routes = append(g.routes, &fakeRoute{seeded: true, sortOrder: i + 1, createdAt: nowRFC3339(), data: runr.GatewayRoute{
			ID: s.newID(), Path: p, ProjectID: s.newID(), AuthMode: runr.RouteAuthPublic,
			Project: &runr.GatewayProject{Name: strings.Trim(p, "/"), Slug: strings.Trim(p, "/")},
		}})
	}
	st.gateways = append(st.gateways, g)
	return s.gatewayView(g)
}

// SetGatewayStatus sets a gateway's status and message (e.g. pending: its key and JWT routes
// answer 503 until gateway-auth is ready).
func (s *Server) SetGatewayStatus(id, status, message string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if g := s.apiProductState().gateway(id); g != nil {
		g.data.Status, g.data.StatusMessage = status, message
	}
}

// SetRouteEnvironment makes a seeded gateway's route lead to an environment of its project.
func (s *Server) SetRouteEnvironment(gatewayID, path, environment string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if g := s.apiProductState().gateway(gatewayID); g != nil {
		for _, r := range g.routes {
			if r.data.Path == path {
				r.data.Environment = environment
			}
		}
	}
}

// Gateways returns the gateways as GET /gateways shows them.
func (s *Server) Gateways() []runr.Gateway {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.pruneRoutes()
	out := []runr.Gateway{}
	for _, g := range s.apiProductState().gateways {
		out = append(out, s.gatewayView(g))
	}
	return out
}

// SeedUsage adds a row GET /api-usage answers for every grouping.
func (s *Server) SeedUsage(row runr.UsageRow) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.apiProductState()
	st.usage = append(st.usage, row)
}

// APIConsumers returns the consumers the fake holds.
func (s *Server) APIConsumers() []runr.APIConsumer {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.apiProductState()
	out := []runr.APIConsumer{}
	for _, c := range st.consumers {
		out = append(out, st.consumerView(c))
	}
	return out
}

// APIKeys returns the keys the fake holds (without the keys themselves), revoked ones included.
func (s *Server) APIKeys() []runr.APIKey {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := []runr.APIKey{}
	for _, k := range s.apiProductState().keys {
		out = append(out, keyView(k))
	}
	return out
}

// ExpireAPIKey lets a key expire (its expires_at an hour ago), as if its time had passed.
func (s *Server) ExpireAPIKey(id string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if k := s.apiProductState().key(id); k != nil {
		k.ExpiresAt = isoTime(time.Now().Add(-time.Hour))
	}
}

// APIPlans returns the plans the fake holds.
func (s *Server) APIPlans() []runr.APIPlan {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := []runr.APIPlan{}
	for _, p := range s.apiProductState().plans {
		out = append(out, planView(p))
	}
	return out
}

func (st *apiProductState) gateway(id string) *fakeGateway {
	for _, g := range st.gateways {
		if g.data.ID == id {
			return g
		}
	}
	return nil
}

func (g *fakeGateway) route(id string) *fakeRoute {
	for _, r := range g.routes {
		if r.data.ID == id {
			return r
		}
	}
	return nil
}

func (st *apiProductState) consumer(id string) *runr.APIConsumer {
	for _, c := range st.consumers {
		if c.ID == id {
			return c
		}
	}
	return nil
}

func (st *apiProductState) key(id string) *runr.APIKey {
	for _, k := range st.keys {
		if k.ID == id {
			return k
		}
	}
	return nil
}

func (st *apiProductState) plan(id string) *runr.APIPlan {
	for _, p := range st.plans {
		if p.ID == id {
			return p
		}
	}
	return nil
}

// pruneRoutes drops the routes whose project or environment is gone (deleting a project or an
// environment removes its routes).
func (s *Server) pruneRoutes() {
	for _, g := range s.apiProductState().gateways {
		kept := g.routes[:0]
		for _, r := range g.routes {
			if r.seeded || s.routeEnvironment(r) != nil {
				kept = append(kept, r)
			}
		}
		g.routes = kept
	}
}

// routeEnvironment is the project of a route and the environment it leads to (nil environment:
// the project's own workload); nil when either is gone.
func (s *Server) routeEnvironment(r *fakeRoute) *routeTarget {
	p := s.projectByID(r.data.ProjectID)
	if p == nil {
		return nil
	}
	t := &routeTarget{project: p}
	if r.data.EnvironmentID == "" {
		return t
	}
	for _, e := range s.environmentsState().byProject[p.data.ID] {
		if e.data.ID == r.data.EnvironmentID {
			t.environment = e
			return t
		}
	}
	return nil
}

type routeTarget struct {
	project     *project
	environment *fakeEnvironment
}

// gatewayView is GatewayResource.
func (s *Server) gatewayView(g *fakeGateway) runr.Gateway {
	out := g.data
	out.Routes = []runr.GatewayRoute{}
	out.RateLimit, out.CustomDomainPending, out.DeveloperPageKey = nil, nil, ""
	if g.rateAverage > 0 {
		burst := g.rateBurst
		if burst == 0 {
			burst = g.rateAverage
		}
		out.RateLimit = &runr.GatewayLimit{Average: g.rateAverage, Burst: burst}
	}
	if g.pending != "" {
		p := &runr.PendingDomain{Domain: g.pending}
		p.Verification.Type, p.Verification.Name, p.Verification.Value = "TXT", "_runr-challenge."+g.pending, "runr-verify="+g.token
		p.DNSTargets = append(p.DNSTargets, struct {
			Type  string `json:"type"`
			Name  string `json:"name"`
			Value string `json:"value"`
		}{"CNAME", g.pending, g.data.Host})
		out.CustomDomainPending = p
	}
	if out.DeveloperPage {
		out.DeveloperPageKey = "_." + g.data.ID
	}
	for _, r := range g.routes {
		out.Routes = append(out.Routes, s.routeView(g, r))
	}
	return out
}

// routeView is GatewayRouteResource: a route as its gateway shows it, with its URL (on the
// custom domain when the gateway has one) and its JWT settings.
func (s *Server) routeView(g *fakeGateway, r *fakeRoute) runr.GatewayRoute {
	host := g.data.Host
	if g.data.CustomDomain != "" {
		host = g.data.CustomDomain
	}
	v := r.data
	v.URL = "https://" + host + r.data.Path
	if v.AuthMode == "" {
		v.AuthMode = runr.RouteAuthPublic
	}
	if v.AuthMode == runr.RouteAuthJWT {
		v.JWT = routeJWT(r.jwt)
	}
	if t := s.routeEnvironment(r); t != nil {
		v.Project = &runr.GatewayProject{ID: t.project.data.ID, Name: t.project.data.Name, Slug: t.project.data.Slug}
		if t.environment != nil {
			v.Environment = t.environment.data.Name
		}
	}
	return v
}

// routeSettingsIn are the API product settings a route request names; GatewayController
// validates them alike on creation and change.
type routeSettingsIn struct {
	mode   *string
	jwt    map[string]any
	jwtSet bool
	only   *bool
}

func routeSettingsOf(body map[string]any, errs fieldErrors) routeSettingsIn {
	var in routeSettingsIn
	mode, modeSet := optStr(body, "auth_mode", 16, errs)
	if modeSet && (mode == nil || (*mode != "public" && *mode != "key" && *mode != "jwt")) {
		errs.add("auth_mode", "The selected auth mode is invalid.")
	}
	in.mode = mode
	rawJWT, jwtSet := body["jwt"]
	in.jwtSet = jwtSet
	if jwtSet && rawJWT != nil {
		jwt, ok := rawJWT.(map[string]any)
		if !ok {
			errs.add("jwt", "The jwt field must be an array.")
		} else {
			u := str(jwt["jwks_url"])
			switch {
			case u == "":
				errs.add("jwt.jwks_url", "The jwt.jwks url field is required when jwt is present.")
			case len(u) > 2048 || !(strings.HasPrefix(u, "https://") || strings.HasPrefix(u, "http://")):
				errs.add("jwt.jwks_url", "The jwt.jwks url field must be a valid URL.")
			}
			optStr(jwt, "issuer", 255, errs)
			optStr(jwt, "audience", 255, errs)
			if v, _ := optStr(jwt, "subject_claim", 64, errs); v != nil && !subjectName.MatchString(*v) {
				errs.add("jwt.subject_claim", "The jwt.subject claim field format is invalid.")
			}
			if v, ok := jwt["require_consumer"]; ok && v != nil {
				if _, isBool := v.(bool); !isBool {
					errs.add("jwt.require_consumer", "The jwt.require consumer field must be true or false.")
				}
			}
			in.jwt = jwt
		}
	}
	in.only, _ = optBool(body, "gateway_only", errs)
	return in
}

// jwksRequired: a JWT route verifies tokens with its issuer's JWKS.
func jwksRequired(mode string, jwt map[string]any) *apiErr {
	if mode == runr.RouteAuthJWT && str(jwt["jwks_url"]) == "" {
		return errValidation("jwt.jwks_url", "JWT authentication needs the JWKS URL of the token issuer.")
	}
	return nil
}

// gatewayOnlyRefusal: "only through the gateway" is refused for a workload with custom domains
// and for a global project's own workload.
func (s *Server) gatewayOnlyRefusal(r *fakeRoute) *apiErr {
	t := s.routeEnvironment(r)
	if t == nil {
		return nil
	}
	envID := ""
	if t.environment != nil {
		envID = t.environment.data.ID
	}
	for _, d := range t.project.domains {
		if t.project.domainEnv[d.ID] == envID {
			return errValidation("gateway_only", "Remove the project's custom domains first: they would get around the gateway.")
		}
	}
	if t.environment == nil && s.globalSt().projects[t.project.data.ID] != nil {
		return errValidation("gateway_only", "A global project is served on its global host name: \"only through the gateway\" would close it.")
	}
	return nil
}

// routeJWT is GatewayAuthConfig::jwt(): the JWT settings with their defaults.
func routeJWT(cfg map[string]any) *runr.RouteJWT {
	j := &runr.RouteJWT{JWKSURL: str(cfg["jwks_url"]), Issuer: str(cfg["issuer"]), Audience: str(cfg["audience"]),
		SubjectClaim: str(cfg["subject_claim"])}
	if j.SubjectClaim == "" {
		j.SubjectClaim = "sub"
	}
	j.RequireConsumer, _ = cfg["require_consumer"].(bool)
	return j
}

// routeData is the answer of adding or changing a route: the route as the gateway shows it
// (routeView). Without RouteSettings, an API before 0.6.0: the stored route with its project and
// environment, without url and jwt; a new route has no auth_mode / gateway_only yet (the model
// was created without them).
func (s *Server) routeData(g *fakeGateway, r *fakeRoute, created bool) any {
	if s.RouteSettings {
		return s.routeView(g, r)
	}
	var methods any
	if r.data.Methods != nil {
		methods = r.data.Methods
	}
	m := map[string]any{
		"id": r.data.ID, "gateway_id": g.data.ID, "project_id": r.data.ProjectID, "path": r.data.Path,
		"methods": methods, "sort_order": r.sortOrder, "created_at": r.createdAt, "updated_at": nowRFC3339(),
		"environment_id": nil, "environment": nil,
	}
	if t := s.routeEnvironment(r); t != nil {
		m["project"] = map[string]any{"id": t.project.data.ID, "name": t.project.data.Name, "slug": t.project.data.Slug}
		if t.environment != nil {
			m["environment_id"], m["environment"] = t.environment.data.ID, t.environment.data.Name
		}
	}
	if !created {
		var jwt any
		if r.jwt != nil {
			jwt = r.jwt
		}
		m["auth_mode"], m["jwt_config"], m["gateway_only"], m["strip_prefix"] = r.data.AuthMode, jwt, r.data.GatewayOnly, true
	}
	return m
}

func (st *apiProductState) consumerView(c *runr.APIConsumer) runr.APIConsumer {
	out := *c
	total, active := 0, 0
	for _, k := range st.keys {
		if k.ConsumerID == c.ID {
			total++
			if keyStatus(k) == "active" {
				active++
			}
		}
	}
	out.Keys = &struct {
		Total  int `json:"total"`
		Active int `json:"active"`
	}{total, active}
	out.Plan = nil
	if p := st.plan(c.PlanID); p != nil {
		out.Plan = &runr.APIPlanRef{ID: p.ID, Name: p.Name}
	}
	if out.Metadata == nil {
		out.Metadata = map[string]any{}
	}
	return out
}

// keyStatus is ApiKey::status(): revoked, expired (expires_at passed) or active.
func keyStatus(k *runr.APIKey) string {
	if k.RevokedAt != "" {
		return "revoked"
	}
	if t, err := time.Parse(time.RFC3339Nano, k.ExpiresAt); err == nil && !t.After(time.Now()) {
		return "expired"
	}
	return "active"
}

func keyView(k *runr.APIKey) runr.APIKey {
	out := *k
	out.Status, out.Key = keyStatus(k), ""
	return out
}

// planView is ApiPlanResource: the burst is the per-second rate unless the plan has its own.
func planView(p *runr.APIPlan) runr.APIPlan {
	out := *p
	out.Burst = nil
	if p.RatePerSecond != nil {
		b := *p.RatePerSecond
		if p.Burst != nil {
			b = *p.Burst
		}
		out.Burst = &b
	}
	return out
}

// isoTime formats a time as Carbon's toISOString() does.
func isoTime(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05.000000Z") }

func plainKey() string {
	b := make([]byte, 22)
	_, _ = rand.Read(b)
	return "rk_" + hex.EncodeToString(b)[:43]
}

func randomToken() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func str(v any) string {
	s, _ := v.(string)
	return s
}

// ---------------------------------------------------------------------------------------
// Validation as Laravel answers it: 422 with the first message and the field errors.

type fieldErrors map[string][]string

func (f fieldErrors) add(field, msg string) {
	if len(f[field]) == 0 {
		f[field] = append(f[field], msg)
	}
}

func (f fieldErrors) err() *apiErr {
	if len(f) == 0 {
		return nil
	}
	fields := make([]string, 0, len(f))
	for k := range f {
		fields = append(fields, k)
	}
	sort.Strings(fields)
	return &apiErr{status: 422, message: f[fields[0]][0], errors: f}
}

func attribute(field string) string { return strings.ReplaceAll(field, "_", " ") }

// optInt checks a nullable integer field: absent (present false), null (nil) or a whole number
// in [min, max].
func optInt(body map[string]any, field string, min, max float64, errs fieldErrors) (v *int64, present bool) {
	raw, ok := body[field]
	if !ok {
		return nil, false
	}
	if raw == nil {
		return nil, true
	}
	f, isNum := raw.(float64)
	switch {
	case !isNum || f != math.Trunc(f):
		errs.add(field, fmt.Sprintf("The %s field must be an integer.", attribute(field)))
	case f < min:
		errs.add(field, fmt.Sprintf("The %s field must be at least %.0f.", attribute(field), min))
	case f > max:
		errs.add(field, fmt.Sprintf("The %s field must not be greater than %.0f.", attribute(field), max))
	default:
		n := int64(f)
		return &n, true
	}
	return nil, true
}

// optStr checks a nullable string field of at most max characters; "" is null
// (ConvertEmptyStringsToNull).
func optStr(body map[string]any, field string, max int, errs fieldErrors) (v *string, present bool) {
	raw, ok := body[field]
	if !ok {
		return nil, false
	}
	if raw == nil || raw == "" {
		return nil, true
	}
	s, isStr := raw.(string)
	switch {
	case !isStr:
		errs.add(field, fmt.Sprintf("The %s field must be a string.", attribute(field)))
	case len(s) > max:
		errs.add(field, fmt.Sprintf("The %s field must not be greater than %d characters.", attribute(field), max))
	default:
		return &s, true
	}
	return nil, true
}

// optBool checks a boolean field.
func optBool(body map[string]any, field string, errs fieldErrors) (v *bool, present bool) {
	raw, ok := body[field]
	if !ok {
		return nil, false
	}
	b, isBool := raw.(bool)
	if !isBool {
		errs.add(field, fmt.Sprintf("The %s field must be true or false.", attribute(field)))
		return nil, true
	}
	return &b, true
}

// optID checks a nullable 26-character id field.
func optID(body map[string]any, field string, errs fieldErrors) (v string, present bool) {
	s, present := optStr(body, field, 1<<10, errs)
	if s == nil {
		return "", present
	}
	if len(*s) != 26 {
		errs.add(field, fmt.Sprintf("The %s field must be 26 characters.", attribute(field)))
		return "", true
	}
	return *s, true
}

func ptrStr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

var (
	routePath   = regexp.MustCompile(`^/[a-zA-Z0-9/_-]*$`)
	subjectName = regexp.MustCompile(`^[A-Za-z0-9_.:-]+$`)
	hostLabel   = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	emailShape  = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)
)

var routeMethods = map[string]bool{"GET": true, "POST": true, "PUT": true, "PATCH": true, "DELETE": true}

var keyMethods = map[string]bool{"GET": true, "POST": true, "PUT": true, "PATCH": true, "DELETE": true, "HEAD": true, "OPTIONS": true}

// methodsOf checks a nullable list of methods (allowed: the accepted ones, as sent).
func methodsOf(raw any, field string, allowed map[string]bool, minOne bool, errs fieldErrors) ([]string, bool) {
	if raw == nil {
		return nil, true
	}
	list, ok := raw.([]any)
	if !ok {
		errs.add(field, fmt.Sprintf("The %s field must be an array.", field))
		return nil, false
	}
	if minOne && len(list) == 0 {
		errs.add(field, fmt.Sprintf("The %s field must have at least 1 items.", field))
		return nil, false
	}
	out := []string{}
	for i, m := range list {
		ms, _ := m.(string)
		if !allowed[ms] {
			errs.add(fmt.Sprintf("%s.%d", field, i), fmt.Sprintf("The selected %s.%d is invalid.", field, i))
			return nil, false
		}
		out = append(out, ms)
	}
	return out, true
}

// validHost is DomainName::isValid(): a lower-case FQDN, no IP address.
func validHost(d string) bool {
	if len(d) > 253 || !strings.Contains(d, ".") {
		return false
	}
	labels := strings.Split(d, ".")
	for _, l := range labels {
		if !hostLabel.MatchString(l) {
			return false
		}
	}
	last := labels[len(labels)-1]
	return strings.Trim(last, "0123456789") != ""
}

// futureTime checks a nullable date-time field in the future; the answer's format.
func futureTime(body map[string]any, field string, errs fieldErrors) (v string, present bool) {
	raw, ok := body[field]
	if !ok {
		return "", false
	}
	if raw == nil || raw == "" {
		return "", true
	}
	s, _ := raw.(string)
	t, err := time.Parse(time.RFC3339Nano, s)
	if err != nil {
		if t, err = time.Parse("2006-01-02", s); err != nil {
			errs.add(field, fmt.Sprintf("The %s field must be a valid date.", attribute(field)))
			return "", true
		}
	}
	if !t.After(time.Now()) {
		errs.add(field, fmt.Sprintf("The %s field must be a date after now.", attribute(field)))
		return "", true
	}
	return isoTime(t), true
}

// ---------------------------------------------------------------------------------------
// Rules shared by consumers, keys and plans (ApiProductAccess, ApiKeys, ApiPlanController)

// assertPlanUsable: a plan of the gateway or of the organization (an organization consumer: only
// the organization's).
func (st *apiProductState) assertPlanUsable(planID, gatewayID, field string) *apiErr {
	if planID == "" {
		return nil
	}
	p := st.plan(planID)
	if p == nil || (p.GatewayID != "" && p.GatewayID != gatewayID) {
		return errValidation(field, "This plan belongs to another gateway.")
	}
	return nil
}

// assertSubjectFree: a JWT subject names one consumer among those a gateway accepts.
func (st *apiProductState) assertSubjectFree(subject, gatewayID, exceptID string) *apiErr {
	if subject == "" {
		return nil
	}
	for _, c := range st.consumers {
		if c.JWTSubject != subject || c.ID == exceptID {
			continue
		}
		if gatewayID == "" || c.GatewayID == gatewayID || c.GatewayID == "" {
			return errValidation("jwt_subject", "Another consumer of this gateway has this JWT subject.")
		}
	}
	return nil
}

// keyScopes validates and normalizes scopes (ApiKeys::normalizeScopes): known routes of the
// consumer's gateway (any gateway for an organization consumer), upper-case methods without
// duplicates; null: every route.
func (s *Server) keyScopes(c *runr.APIConsumer, raw any, errs fieldErrors) ([]runr.KeyScope, bool) {
	if raw == nil {
		return nil, true
	}
	list, ok := raw.([]any)
	if !ok {
		errs.add("scopes", "The scopes field must be an array.")
		return nil, false
	}
	if len(list) < 1 || len(list) > 200 {
		errs.add("scopes", "The scopes field must have between 1 and 200 items.")
		return nil, false
	}
	out := []runr.KeyScope{}
	for i, item := range list {
		m, _ := item.(map[string]any)
		routeID := str(m["route_id"])
		if len(routeID) != 26 {
			errs.add(fmt.Sprintf("scopes.%d.route_id", i), fmt.Sprintf("The scopes.%d.route_id field must be 26 characters.", i))
			return nil, false
		}
		var upper []any
		if ms, ok := m["methods"].([]any); ok {
			for _, x := range ms {
				upper = append(upper, strings.ToUpper(str(x)))
			}
			if upper == nil {
				upper = []any{}
			}
		}
		var methods []string
		if upper != nil {
			list, ok := methodsOf(upper, fmt.Sprintf("scopes.%d.methods", i), keyMethods, true, errs)
			if !ok {
				return nil, false
			}
			seen := map[string]bool{}
			for _, x := range list {
				if !seen[x] {
					seen[x] = true
					methods = append(methods, x)
				}
			}
		}
		known := false
		for _, g := range s.apiProductState().gateways {
			if c.GatewayID != "" && g.data.ID != c.GatewayID {
				continue
			}
			known = known || g.route(routeID) != nil
		}
		if !known {
			errs.add(fmt.Sprintf("scopes.%d.route_id", i), "This route is not a route of the consumer's gateway.")
			return nil, false
		}
		out = append(out, runr.KeyScope{RouteID: routeID, Methods: methods})
	}
	return out, true
}

// planInUse: a consumer or an active (not revoked) key uses it.
func (st *apiProductState) planInUse(id string) bool {
	for _, c := range st.consumers {
		if c.PlanID == id {
			return true
		}
	}
	for _, k := range st.keys {
		if k.PlanID == id && k.RevokedAt == "" {
			return true
		}
	}
	return false
}

func (st *apiProductState) planNameTaken(name, gatewayID, exceptID string) bool {
	for _, p := range st.plans {
		if p.Name == name && p.GatewayID == gatewayID && p.ID != exceptID {
			return true
		}
	}
	return false
}

// planLimits validates the limits of a plan body into p (absent fields stay).
func planLimits(body map[string]any, p *runr.APIPlan, errs fieldErrors) {
	set := func(dst **int64, field string, max float64) {
		if v, ok := optInt(body, field, 1, max, errs); ok {
			*dst = v
		}
	}
	if v, ok := optStr(body, "description", 500, errs); ok {
		p.Description = ptrStr(v)
	}
	set(&p.RatePerSecond, "rate_per_second", 100000)
	set(&p.Burst, "burst", 1000000)
	set(&p.RatePerMinute, "rate_per_minute", 10000000)
	set(&p.QuotaPerDay, "quota_per_day", 1000000000000)
	set(&p.QuotaPerMonth, "quota_per_month", 10000000000000)
}

// assertBurst: a burst needs a per-second rate and is at least that rate.
func assertBurst(p *runr.APIPlan) *apiErr {
	if p.Burst == nil {
		return nil
	}
	if p.RatePerSecond == nil {
		return errValidation("burst", "A burst needs a per-second rate.")
	}
	if *p.Burst < *p.RatePerSecond {
		return errValidation("burst", "The burst cannot be lower than the per-second rate.")
	}
	return nil
}

// consumerFields validates the optional fields of a consumer body into c (absent fields stay).
func consumerFields(body map[string]any, c *runr.APIConsumer, errs fieldErrors) {
	if v, ok := optStr(body, "email", 255, errs); ok {
		if v != nil && !emailShape.MatchString(*v) {
			errs.add("email", "The email field must be a valid email address.")
		}
		c.Email = ptrStr(v)
	}
	if v, ok := optStr(body, "external_id", 191, errs); ok {
		c.ExternalID = ptrStr(v)
	}
	if v, ok := optStr(body, "jwt_subject", 191, errs); ok {
		c.JWTSubject = ptrStr(v)
	}
	if v, ok := optID(body, "plan_id", errs); ok {
		c.PlanID = v
	}
	if v, ok := optStr(body, "status", 16, errs); ok && v != nil {
		if *v != "active" && *v != "suspended" {
			errs.add("status", "The selected status is invalid.")
		}
		c.Status = *v
	}
	if raw, ok := body["metadata"]; ok {
		switch m := raw.(type) {
		case nil:
			c.Metadata = nil
		case map[string]any:
			if len(m) > 50 {
				errs.add("metadata", "The metadata field must not have more than 50 items.")
			}
			c.Metadata = m
			if len(m) == 0 {
				c.Metadata = nil
			}
		default:
			errs.add("metadata", "The metadata field must be an array.")
		}
	}
}

func init() {
	handle("GET /gateways", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		out := []runr.Gateway{}
		for _, g := range s.apiProductState().gateways {
			out = append(out, s.gatewayView(g))
		}
		return 200, map[string]any{"data": out}, nil
	})
	handle("GET /gateways/{gateway}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.gatewayView(g)}, nil
	})
	// Rate limit (null: none) and developer page
	handle("PATCH /gateways/{gateway}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		any4 := false
		for _, k := range []string{"rate_limit", "developer_page", "description", "developer_page_metrics"} {
			_, ok := q.Body[k]
			any4 = any4 || ok
		}
		if !any4 {
			return 0, nil, errValidation("rate_limit", "The rate limit field must be present.")
		}
		errs := fieldErrors{}
		page, _ := optBool(q.Body, "developer_page", errs)
		metrics, _ := optBool(q.Body, "developer_page_metrics", errs)
		desc, descSet := optStr(q.Body, "description", 5000, errs)
		raw, limitSet := q.Body["rate_limit"]
		var average, burst *int64
		if limitSet && raw != nil {
			limit, ok := raw.(map[string]any)
			if !ok {
				errs.add("rate_limit", "The rate limit field must be an array.")
			} else {
				sub := fieldErrors{}
				if average, _ = optInt(limit, "average", 1, 100000, sub); average == nil && len(sub) == 0 {
					errs.add("rate_limit.average", "The rate limit.average field is required when rate limit is present.")
				}
				burst, _ = optInt(limit, "burst", 1, 100000, sub)
				for k, v := range sub {
					errs["rate_limit."+k] = v
				}
			}
		}
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if page != nil {
			g.data.DeveloperPage = *page
		}
		if metrics != nil {
			g.data.DeveloperPageMetrics = *metrics
		}
		if descSet {
			g.data.Description = ptrStr(desc)
		}
		if limitSet {
			g.rateAverage, g.rateBurst = 0, 0
			if average != nil {
				g.rateAverage = int(*average)
				if burst != nil {
					g.rateBurst = int(*burst)
				}
			}
		}
		return 200, map[string]any{"data": s.gatewayView(g)}, nil
	})
	// The caller's gateway in a region, created when there is none
	handle("POST /gateways", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg := s.regionByID(q.str("knative_region_id"))
		if reg == nil {
			return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		if reg.IsDemo {
			return 0, nil, errValidation("knative_region_id", "Region "+reg.Name+" has no cluster a gateway could run on.")
		}
		st := s.apiProductState()
		for _, g := range st.gateways {
			if g.data.RegionID == reg.ID {
				return 200, map[string]any{"data": s.gatewayView(g)}, nil
			}
		}
		g := &fakeGateway{data: runr.Gateway{
			ID: s.newID(), Subdomain: s.User.Username, Status: "active", RegionID: reg.ID,
			Region: &runr.GatewayRegion{ID: reg.ID, Name: reg.Name}, CreatedAt: nowRFC3339(),
		}}
		g.data.Host = g.data.Subdomain + "." + reg.Name + ".runr.test"
		st.gateways = append(st.gateways, g)
		return 201, map[string]any{"data": s.gatewayView(g)}, nil
	})
	// A route to a project's own workload or to one of its environments, in the gateway's region
	handle("POST /gateways/{gateway}/routes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		projectID := q.str("project_id")
		switch {
		case projectID == "":
			errs.add("project_id", "The project id field is required.")
		case !runr.LooksLikeULID(projectID):
			errs.add("project_id", "The project id field must be a valid ULID.")
		case s.projectByID(projectID) == nil:
			errs.add("project_id", "The selected project id is invalid.")
		}
		path := q.str("path")
		switch {
		case path == "":
			errs.add("path", "The path field is required.")
		case len(path) > 255:
			errs.add("path", "The path field must not be greater than 255 characters.")
		case !routePath.MatchString(path):
			errs.add("path", "The path field format is invalid.")
		}
		envRef, _ := optStr(q.Body, "environment", 64, errs)
		methods, _ := methodsOf(q.Body["methods"], "methods", routeMethods, false, errs)
		// the API product settings come with the route (an API before 0.6.0 ignores them)
		var settings routeSettingsIn
		if s.RouteSettings {
			settings = routeSettingsOf(q.Body, errs)
		}
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		mode := runr.RouteAuthPublic
		if settings.mode != nil {
			mode = *settings.mode
		}
		if err := jwksRequired(mode, settings.jwt); err != nil {
			return 0, nil, err
		}
		p := s.projectByID(projectID)
		var env *fakeEnvironment
		if envRef != nil && s.Environments {
			e, err := s.environmentRef(p, *envRef)
			if err != nil {
				return 0, nil, err
			}
			if !e.data.IsPrimary {
				env = e
			}
		}
		path = "/" + strings.TrimLeft(path, "/")
		for _, r := range g.routes {
			if r.data.Path == path {
				return 0, nil, errValidation("path", "The gateway already has a route on "+path+".")
			}
		}
		name, field, envID, regionID := runr.PrimaryEnvironment, "project_id", "", p.data.RegionID
		if env != nil {
			name, field, envID = env.data.Name, "environment", env.data.ID
			if env.data.RegionID != "" {
				regionID = env.data.RegionID
			}
		}
		for _, x := range s.apiProductState().gateways {
			for _, r := range x.routes {
				if !r.seeded && r.data.ProjectID == p.data.ID && r.data.EnvironmentID == envID {
					return 0, nil, errValidation(field, "The "+name+" environment of the project already has a gateway route: change or remove that one.")
				}
			}
		}
		if global := s.globalSt().projects[p.data.ID]; env == nil && global != nil {
			in := false
			for _, id := range global.regions {
				in = in || id == g.data.RegionID
			}
			if !in {
				return 0, nil, errValidation("project_id", "The gateway is in region "+g.data.Region.Name+", where this global project does not run: use a gateway of one of its regions.")
			}
		} else if regionID != g.data.RegionID {
			region := regionID
			if reg := s.regionByID(regionID); reg != nil {
				region = reg.Name
			}
			return 0, nil, errValidation(field, "The "+name+" environment runs in region "+region+": a route to it belongs to a gateway in that region.")
		}
		order := 0
		for _, r := range g.routes {
			if r.sortOrder > order {
				order = r.sortOrder
			}
		}
		r := &fakeRoute{sortOrder: order + 1, createdAt: nowRFC3339(), jwt: settings.jwt, data: runr.GatewayRoute{
			ID: s.newID(), Path: path, ProjectID: p.data.ID, EnvironmentID: envID, Methods: methods, AuthMode: mode,
		}}
		if settings.only != nil && *settings.only {
			if err := s.gatewayOnlyRefusal(r); err != nil {
				return 0, nil, err
			}
			r.data.GatewayOnly = true
		}
		g.routes = append(g.routes, r)
		if env == nil {
			p.data.EndpointType = "gateway"
		}
		return 201, map[string]any{"data": s.routeData(g, r, true)}, nil
	})
	handle("DELETE /gateways/{gateway}/routes/{route}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		for i, r := range g.routes {
			if r.data.ID == q.Segs[3] {
				g.routes = append(g.routes[:i], g.routes[i+1:]...)
				if p := s.projectByID(r.data.ProjectID); p != nil && r.data.EnvironmentID == "" {
					p.data.EndpointType = "subdomain"
				}
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	})
	// Path, methods, authentication and "only through the gateway" of a route
	handle("PATCH /gateways/{gateway}/routes/{route}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		s.pruneRoutes()
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		r := g.route(q.Segs[3])
		if r == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		path, pathSet := q.Body["path"].(string)
		if _, ok := q.Body["path"]; ok && (!pathSet || len(path) > 255 || !routePath.MatchString(path)) {
			errs.add("path", "The path field format is invalid.")
		}
		_, methodsSet := q.Body["methods"]
		methods, _ := methodsOf(q.Body["methods"], "methods", routeMethods, false, errs)
		settings := routeSettingsOf(q.Body, errs)
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		newMode, newJWT := r.data.AuthMode, r.jwt
		if settings.mode != nil {
			newMode = *settings.mode
		}
		if settings.jwtSet {
			newJWT = settings.jwt
		}
		if err := jwksRequired(newMode, newJWT); err != nil {
			return 0, nil, err
		}
		if settings.only != nil && *settings.only && !r.data.GatewayOnly {
			if err := s.gatewayOnlyRefusal(r); err != nil {
				return 0, nil, err
			}
		}
		if pathSet {
			// a path in use: 422, as the API answers it (also when its unique index refuses it)
			path = "/" + strings.TrimLeft(path, "/")
			for _, x := range g.routes {
				if x != r && x.data.Path == path {
					return 0, nil, errValidation("path", "The gateway already has a route on "+path+".")
				}
			}
			r.data.Path = path
		}
		if methodsSet {
			r.data.Methods = methods
		}
		r.data.AuthMode, r.jwt = newMode, newJWT
		if settings.only != nil {
			r.data.GatewayOnly = *settings.only
		}
		return 200, map[string]any{"data": s.routeData(g, r, false)}, nil
	})
	// The gateway's own domain: requested (pending with a TXT token), verified, removed
	handle("PUT /gateways/{gateway}/custom-domain", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		g := st.gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		domain := strings.TrimSuffix(strings.ToLower(strings.TrimSpace(q.str("domain"))), ".")
		switch {
		case domain == "":
			return 0, nil, errValidation("domain", "The domain field is required.")
		case strings.Contains(domain, "*"):
			return 0, nil, errValidation("domain", "Wildcard domains are not supported; add each host name separately.")
		case !validHost(domain):
			return 0, nil, errValidation("domain", "Enter a fully qualified host name such as api.example.com (lowercase letters, digits and dashes; internationalized names in their xn-- form).")
		case strings.HasSuffix(domain, ".runr.test"):
			return 0, nil, errValidation("domain", "This domain belongs to the platform (a region function domain or the console domain) and cannot be attached.")
		}
		taken := false
		for _, x := range st.gateways {
			taken = taken || (x != g && (x.data.CustomDomain == domain || x.pending == domain))
		}
		for _, p := range s.projects {
			for _, d := range p.domains {
				taken = taken || d.Domain == domain
			}
		}
		if taken {
			return 0, nil, errValidation("domain", "This domain is already used by a gateway or a project.")
		}
		if g.data.CustomDomain != domain {
			g.pending, g.token = domain, randomToken()
		}
		return 200, map[string]any{"data": s.gatewayView(g)}, nil
	})
	handle("POST /gateways/{gateway}/custom-domain/verify", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		if g.pending == "" {
			return 0, nil, &apiErr{status: 422, message: "No domain is waiting for verification.", extra: map[string]any{"data": s.gatewayView(g)}}
		}
		if !s.VerifyDomains {
			msg := fmt.Sprintf("TXT record _runr-challenge.%s with the value \"runr-verify=%s\" was not found.", g.pending, g.token)
			return 0, nil, &apiErr{status: 422, message: msg, extra: map[string]any{"data": s.gatewayView(g)}}
		}
		g.data.CustomDomain, g.data.CustomDomainVerifiedAt = g.pending, isoTime(time.Now())
		g.pending, g.token = "", ""
		return 200, map[string]any{"message": "The domain is verified and routed to the gateway.", "data": s.gatewayView(g)}, nil
	})
	handle("DELETE /gateways/{gateway}/custom-domain", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.apiProductState().gateway(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		g.data.CustomDomain, g.data.CustomDomainVerifiedAt, g.pending, g.token = "", "", "", ""
		return 200, map[string]any{"data": s.gatewayView(g)}, nil
	})

	handle("GET /api-consumers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		gw := q.query("gateway_id")
		if gw != "" && st.gateway(gw) == nil {
			return 0, nil, errNotFound()
		}
		out := []runr.APIConsumer{}
		for _, c := range st.consumers {
			if gw == "" || c.GatewayID == gw || c.GatewayID == "" {
				out = append(out, st.consumerView(c))
			}
		}
		sort.SliceStable(out, func(i, j int) bool { return out[i].Name < out[j].Name })
		return 200, map[string]any{"data": out}, nil
	})
	handle("POST /api-consumers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		errs := fieldErrors{}
		gw, _ := optID(q.Body, "gateway_id", errs)
		name, _ := optStr(q.Body, "name", 120, errs)
		if name == nil {
			errs.add("name", "The name field is required.")
		}
		c := &runr.APIConsumer{GatewayID: gw, Status: "active", CanManage: true}
		consumerFields(q.Body, c, errs)
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if gw != "" && st.gateway(gw) == nil {
			return 0, nil, errNotFound()
		}
		if err := st.assertPlanUsable(c.PlanID, gw, "plan_id"); err != nil {
			return 0, nil, err
		}
		if err := st.assertSubjectFree(c.JWTSubject, gw, ""); err != nil {
			return 0, nil, err
		}
		c.ID, c.Name, c.Scope, c.CreatedAt = s.newID(), *name, "gateway", isoTime(time.Now())
		if gw == "" {
			c.Scope = "organization"
		}
		st.consumers = append(st.consumers, c)
		return 201, map[string]any{"data": st.consumerView(c)}, nil
	})
	handle("GET /api-consumers/{apiConsumer}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		c := st.consumer(q.Segs[1])
		if c == nil {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": st.consumerView(c)}, nil
	})
	handle("PATCH /api-consumers/{apiConsumer}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		c := st.consumer(q.Segs[1])
		if c == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		next := *c
		if v, ok := optStr(q.Body, "name", 120, errs); ok {
			if v == nil {
				errs.add("name", "The name field must be a string.")
			} else {
				next.Name = *v
			}
		}
		consumerFields(q.Body, &next, errs) // status null: unchanged
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["plan_id"]; ok {
			if err := st.assertPlanUsable(next.PlanID, c.GatewayID, "plan_id"); err != nil {
				return 0, nil, err
			}
		}
		if _, ok := q.Body["jwt_subject"]; ok {
			if err := st.assertSubjectFree(next.JWTSubject, c.GatewayID, c.ID); err != nil {
				return 0, nil, err
			}
		}
		*c = next
		return 200, map[string]any{"data": st.consumerView(c)}, nil
	})
	// The consumer and its keys go
	handle("DELETE /api-consumers/{apiConsumer}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		for i, c := range st.consumers {
			if c.ID == q.Segs[1] {
				st.consumers = append(st.consumers[:i], st.consumers[i+1:]...)
				kept := st.keys[:0]
				for _, k := range st.keys {
					if k.ConsumerID != c.ID {
						kept = append(kept, k)
					}
				}
				st.keys = kept
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	})
	// Newest first, without the keys themselves
	handle("GET /api-consumers/{apiConsumer}/keys", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		if st.consumer(q.Segs[1]) == nil {
			return 0, nil, errNotFound()
		}
		out := []runr.APIKey{}
		for i := len(st.keys) - 1; i >= 0; i-- {
			if k := st.keys[i]; k.ConsumerID == q.Segs[1] {
				out = append(out, keyView(k))
			}
		}
		return 200, map[string]any{"data": out}, nil
	})
	handle("POST /api-consumers/{apiConsumer}/keys", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		c := st.consumer(q.Segs[1])
		if c == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		name, _ := optStr(q.Body, "name", 120, errs)
		if name == nil {
			errs.add("name", "The name field is required.")
		}
		k := &runr.APIKey{ConsumerID: c.ID}
		if !s.keyFields(q.Body, c, k, errs) {
			return 0, nil, errs.err()
		}
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if err := st.assertPlanUsable(k.PlanID, c.GatewayID, "plan_id"); err != nil {
			return 0, nil, err
		}
		k.Name = *name
		return 201, map[string]any{"data": s.issueKey(st, k)}, nil
	})
	handle("PATCH /api-keys/{apiKey}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		k := st.key(q.Segs[1])
		if k == nil {
			return 0, nil, errNotFound()
		}
		c := st.consumer(k.ConsumerID)
		errs := fieldErrors{}
		next := *k
		if v, ok := optStr(q.Body, "name", 120, errs); ok {
			if v == nil {
				errs.add("name", "The name field must be a string.")
			} else {
				next.Name = *v
			}
		}
		if !s.keyFields(q.Body, c, &next, errs) {
			return 0, nil, errs.err()
		}
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["plan_id"]; ok {
			if err := st.assertPlanUsable(next.PlanID, c.GatewayID, "plan_id"); err != nil {
				return 0, nil, err
			}
		}
		*k = next
		return 200, map[string]any{"data": keyView(k)}, nil
	})
	handle("POST /api-keys/{apiKey}/rotate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		k := st.key(q.Segs[1])
		if k == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		overlap, _ := optInt(q.Body, "overlap_minutes", 0, 43200, errs)
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if keyStatus(k) != "active" {
			return 0, nil, &apiErr{status: 422, message: "Only an active key can be rotated; this one is revoked or expired."}
		}
		minutes := int64(1440)
		if overlap != nil {
			minutes = *overlap
		}
		next := s.issueKey(st, &runr.APIKey{ConsumerID: k.ConsumerID, Name: k.Name, Scopes: k.Scopes, PlanID: k.PlanID,
			ExpiresAt: k.ExpiresAt, RotatedFrom: k.ID})
		if minutes <= 0 {
			k.RevokedAt = isoTime(time.Now())
		} else {
			until := time.Now().Add(time.Duration(minutes) * time.Minute)
			if t, err := time.Parse(time.RFC3339Nano, k.ExpiresAt); err != nil || t.After(until) {
				k.ExpiresAt = isoTime(until)
			}
		}
		return 201, map[string]any{"data": next}, nil
	})
	handle("POST /api-keys/{apiKey}/revoke", func(s *Server, q *featureReq) (int, any, *apiErr) {
		k := s.apiProductState().key(q.Segs[1])
		if k == nil {
			return 0, nil, errNotFound()
		}
		if k.RevokedAt == "" {
			k.RevokedAt = isoTime(time.Now())
		}
		return 200, map[string]any{"data": keyView(k)}, nil
	})

	handle("GET /api-plans", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		gw := q.query("gateway_id")
		if gw != "" && st.gateway(gw) == nil {
			return 0, nil, errNotFound()
		}
		out := []runr.APIPlan{}
		for _, p := range st.plans {
			if gw == "" || p.GatewayID == gw || p.GatewayID == "" {
				out = append(out, planView(p))
			}
		}
		sort.SliceStable(out, func(i, j int) bool { return out[i].Name < out[j].Name })
		return 200, map[string]any{"data": out}, nil
	})
	handle("POST /api-plans", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		errs := fieldErrors{}
		gw, _ := optID(q.Body, "gateway_id", errs)
		name, _ := optStr(q.Body, "name", 100, errs)
		if name == nil {
			errs.add("name", "The name field is required.")
		}
		p := &runr.APIPlan{GatewayID: gw, Scope: "gateway"}
		planLimits(q.Body, p, errs)
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if gw != "" && st.gateway(gw) == nil {
			return 0, nil, errNotFound()
		}
		if st.planNameTaken(*name, gw, "") {
			return 0, nil, errValidation("name", "A plan with this name already exists here.")
		}
		if err := assertBurst(p); err != nil {
			return 0, nil, err
		}
		p.ID, p.Name = s.newID(), *name
		if gw == "" {
			p.Scope = "organization"
		}
		st.plans = append(st.plans, p)
		return 201, map[string]any{"data": planView(p)}, nil
	})
	handle("PATCH /api-plans/{apiPlan}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		p := st.plan(q.Segs[1])
		if p == nil {
			return 0, nil, errNotFound()
		}
		errs := fieldErrors{}
		next := *p
		if v, ok := optStr(q.Body, "name", 100, errs); ok {
			if v == nil {
				errs.add("name", "The name field must be a string.")
			} else {
				next.Name = *v
			}
		}
		planLimits(q.Body, &next, errs)
		if err := errs.err(); err != nil {
			return 0, nil, err
		}
		if next.Name != p.Name && st.planNameTaken(next.Name, p.GatewayID, p.ID) {
			return 0, nil, errValidation("name", "A plan with this name already exists here.")
		}
		// no per-second rate any more: no burst either
		if v, ok := q.Body["rate_per_second"]; ok && v == nil {
			next.Burst = nil
		}
		if err := assertBurst(&next); err != nil {
			return 0, nil, err
		}
		*p = next
		return 200, map[string]any{"data": planView(p)}, nil
	})
	handle("DELETE /api-plans/{apiPlan}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.apiProductState()
		for i, p := range st.plans {
			if p.ID == q.Segs[1] {
				if st.planInUse(p.ID) {
					return 0, nil, &apiErr{status: 422, message: "Consumers or active keys use this plan. Move them to another plan first."}
				}
				st.plans = append(st.plans[:i], st.plans[i+1:]...)
				for _, k := range st.keys {
					if k.PlanID == p.ID {
						k.PlanID = "" // nullOnDelete
					}
				}
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	})

	handle("GET /api-usage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		groupBy := q.query("group_by")
		if groupBy == "" {
			groupBy = "day"
		}
		switch groupBy {
		case "hour", "day", "month", "consumer", "key", "route":
		default:
			return 0, nil, errValidation("group_by", "The selected group by is invalid.")
		}
		var totals runr.UsageCounts
		rows := []runr.UsageRow{}
		for _, r := range s.apiProductState().usage {
			if c := q.query("consumer_id"); c != "" && r.ConsumerID != c {
				continue
			}
			totals.Requests += r.Requests
			totals.Unauthorized += r.Unauthorized
			totals.Forbidden += r.Forbidden
			totals.RateLimited += r.RateLimited
			totals.QuotaExceeded += r.QuotaExceeded
			totals.Responses += r.Responses
			totals.Errors += r.Errors
			// a single row's latencies stand for the totals (the fake keeps no histogram)
			totals.LatencyP50MS, totals.LatencyP95MS, totals.LatencyP99MS = r.LatencyP50MS, r.LatencyP95MS, r.LatencyP99MS
			rows = append(rows, r)
		}
		from, to := q.query("from"), q.query("to")
		if from == "" {
			from = "2026-10-14T00:00:00Z"
		}
		if to == "" {
			to = "2026-10-21T00:00:00Z"
		}
		return 200, map[string]any{"from": from, "to": to, "group_by": groupBy, "totals": totals, "data": rows}, nil
	})
}

// keyFields validates scopes, plan_id and expires_at of a key body into k (absent fields stay);
// false when a scope is refused (errs holds why).
func (s *Server) keyFields(body map[string]any, c *runr.APIConsumer, k *runr.APIKey, errs fieldErrors) bool {
	if v, ok := optID(body, "plan_id", errs); ok {
		k.PlanID = v
	}
	if v, ok := futureTime(body, "expires_at", errs); ok {
		k.ExpiresAt = v
	}
	if raw, ok := body["scopes"]; ok {
		scopes, valid := s.keyScopes(c, raw, errs)
		if !valid {
			return false
		}
		k.Scopes = scopes
	}
	return true
}

// issueKey stores a new key (ApiKeys::issue) and returns it with the key itself.
func (s *Server) issueKey(st *apiProductState, k *runr.APIKey) *runr.APIKey {
	plain := plainKey()
	k.ID, k.Prefix, k.CreatedAt = s.newID(), plain[:11], isoTime(time.Now())
	st.keys = append(st.keys, k)
	view := keyView(k)
	view.Key = plain
	return &view
}
