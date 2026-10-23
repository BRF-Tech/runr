package main

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

const (
	gwA     = "01GATEWAYAAAAAAAAAAAAAAAAA"
	gwB     = "01GATEWAYBBBBBBBBBBBBBBBBB"
	routeK  = "01ROUTEKEYAAAAAAAAAAAAAAAA"
	routeK2 = "01ROUTEKEYBBBBBBBBBBBBBBBB"
	routeJ  = "01ROUTEJWTAAAAAAAAAAAAAAAA"
	routeB  = "01ROUTEOFBAAAAAAAAAAAAAAAA"
)

type staticSnapshots struct{ s *Snapshot }

func (s staticSnapshots) Snapshot() *Snapshot { return s.s }

func hashOf(key string) string {
	sum := sha256.Sum256([]byte(key))
	return hex.EncodeToString(sum[:])
}

// fixture: organization "_" with gateway A (key route, second key route, JWT route) and gateway B;
// tenant "t1" with a key that must never open "_"'s routes.
func fixture(jwksURL string) *Config {
	gwAID := gwA
	planID := "01PLANAAAAAAAAAAAAAAAAAAAA"
	keyPlan := "01PLANKEYAAAAAAAAAAAAAAAAA"
	expired := int64(1_700_000_000)
	return &Config{
		Version: "v1",
		Routes: []RouteConfig{
			{ID: routeK, Scope: "_", GatewayID: gwA, Mode: "key"},
			{ID: routeK2, Scope: "_", GatewayID: gwA, Mode: "key"},
			{ID: routeB, Scope: "_", GatewayID: gwB, Mode: "key"},
			{ID: routeJ, Scope: "_", GatewayID: gwA, Mode: "jwt", JWT: &JWTConfig{JWKSURL: jwksURL, SubjectClaim: "sub"}},
		},
		Consumers: []Consumer{
			{ID: "c-gw", Scope: "_", GatewayID: &gwAID, PlanID: &planID, ExternalID: str("CUST-7"), Subject: str("partner-1")},
			{ID: "c-org", Scope: "_"},
			{ID: "c-susp", Scope: "_", Suspended: true},
			{ID: "c-tenant", Scope: "t1"},
		},
		Keys: []Key{
			{ID: "k-gw", Hash: hashOf("rk_gateway"), ConsumerID: "c-gw"},
			{ID: "k-org", Hash: hashOf("rk_org"), ConsumerID: "c-org"},
			{ID: "k-susp", Hash: hashOf("rk_suspended"), ConsumerID: "c-susp"},
			{ID: "k-tenant", Hash: hashOf("rk_tenant"), ConsumerID: "c-tenant"},
			{ID: "k-expired", Hash: hashOf("rk_expired"), ConsumerID: "c-org", ExpiresAt: &expired},
			{ID: "k-scoped", Hash: hashOf("rk_scoped"), ConsumerID: "c-org", Routes: map[string][]string{routeK: {"GET"}}},
			{ID: "k-own-plan", Hash: hashOf("rk_own_plan"), ConsumerID: "c-org", PlanID: &keyPlan},
		},
		Plans: []Plan{
			{ID: planID, PerMinute: ptr(2)},
			{ID: keyPlan, PerDay: ptr(1)},
		},
	}
}

func newAuthorizer(t *testing.T, cfg *Config) (*Authorizer, *Recorder) {
	t.Helper()
	rec := NewRecorder("http://unused", "t", "pod", http.DefaultClient)
	return &Authorizer{
		Snapshots: staticSnapshots{NewSnapshot(cfg, nil)},
		Limiter:   NewMemoryLimiter(),
		JWKS:      NewJWKSCache(http.DefaultClient),
		Usage:     rec,
		Now:       func() time.Time { return time.Date(2026, 10, 21, 10, 30, 0, 0, time.UTC) },
	}, rec
}

func call(a *Authorizer, route, method string, headers map[string]string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodGet, "/auth/"+route, nil)
	req.Header.Set("X-Forwarded-Method", method)
	req.Header.Set("X-Forwarded-Uri", "/orders/1")
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	w := httptest.NewRecorder()
	a.ServeHTTP(w, req)
	return w
}

func errorCode(t *testing.T, w *httptest.ResponseRecorder) string {
	t.Helper()
	var body map[string]string
	_ = json.Unmarshal(w.Body.Bytes(), &body)
	return body["error"]
}

func TestKeyRoutes(t *testing.T) {
	a, _ := newAuthorizer(t, fixture(""))

	cases := []struct {
		name    string
		route   string
		method  string
		headers map[string]string
		status  int
		code    string
	}{
		{"no key", routeK, "GET", nil, 401, "missing_key"},
		{"unknown key", routeK, "GET", map[string]string{"X-Api-Key": "rk_nope"}, 401, "invalid_key"},
		{"another organization's key", routeK, "GET", map[string]string{"X-Api-Key": "rk_tenant"}, 401, "invalid_key"},
		{"expired key", routeK, "GET", map[string]string{"X-Api-Key": "rk_expired"}, 401, "key_expired"},
		{"gateway consumer on another gateway", routeB, "GET", map[string]string{"X-Api-Key": "rk_gateway"}, 403, "wrong_gateway"},
		{"suspended consumer", routeK, "GET", map[string]string{"X-Api-Key": "rk_suspended"}, 403, "consumer_suspended"},
		{"route outside the key's scopes", routeK2, "GET", map[string]string{"X-Api-Key": "rk_scoped"}, 403, "route_not_allowed"},
		{"method outside the key's scopes", routeK, "POST", map[string]string{"X-Api-Key": "rk_scoped"}, 403, "method_not_allowed"},
		{"scoped key, allowed method", routeK, "get", map[string]string{"X-Api-Key": "rk_scoped"}, 200, ""},
		{"organization consumer on any gateway", routeB, "GET", map[string]string{"X-Api-Key": "rk_org"}, 200, ""},
		{"key as bearer token", routeK, "GET", map[string]string{"Authorization": "Bearer rk_org"}, 200, ""},
	}
	for _, c := range cases {
		w := call(a, c.route, c.method, c.headers)
		if w.Code != c.status {
			t.Errorf("%s: status %d, want %d (%s)", c.name, w.Code, c.status, w.Body.String())
			continue
		}
		if c.code != "" && errorCode(t, w) != c.code {
			t.Errorf("%s: error %q, want %q", c.name, errorCode(t, w), c.code)
		}
		if c.status == 401 && w.Header().Get("WWW-Authenticate") == "" {
			t.Errorf("%s: 401 without WWW-Authenticate", c.name)
		}
		for _, v := range c.headers {
			if strings.Contains(w.Body.String(), strings.TrimPrefix(v, "Bearer ")) {
				t.Errorf("%s: the answer echoes the key", c.name)
			}
		}
	}
}

func TestAllowedCallCarriesTheConsumerUpstream(t *testing.T) {
	a, rec := newAuthorizer(t, fixture(""))
	w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_gateway"})
	if w.Code != 200 {
		t.Fatalf("status %d: %s", w.Code, w.Body.String())
	}
	if w.Header().Get("X-Runr-Consumer") != "c-gw" || w.Header().Get("X-Runr-Key") != "k-gw" || w.Header().Get("X-Runr-Consumer-External") != "CUST-7" {
		t.Errorf("upstream headers %v", w.Header())
	}
	if rec.Pending() != 1 {
		t.Errorf("usage rows %d, want 1", rec.Pending())
	}
}

func TestPlanLimitAnswers429WithRateLimitHeaders(t *testing.T) {
	a, _ := newAuthorizer(t, fixture(""))
	headers := map[string]string{"X-Api-Key": "rk_gateway"} // consumer plan: 2 a minute
	for i := range 2 {
		if w := call(a, routeK, "GET", headers); w.Code != 200 {
			t.Fatalf("call %d: %d", i+1, w.Code)
		}
	}
	w := call(a, routeK, "GET", headers)
	if w.Code != 429 || errorCode(t, w) != ReasonRateLimited {
		t.Fatalf("third call: %d %s", w.Code, w.Body.String())
	}
	for header, want := range map[string]string{"RateLimit-Limit": "2", "RateLimit-Remaining": "0", "RateLimit-Policy": "2;w=60", "Retry-After": "60", "RateLimit-Reset": "60"} {
		if got := w.Header().Get(header); got != want {
			t.Errorf("%s: %q, want %q", header, got, want)
		}
	}
}

func TestKeyWithItsOwnPlanIsCountedAlone(t *testing.T) {
	a, _ := newAuthorizer(t, fixture(""))
	if w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_own_plan"}); w.Code != 200 {
		t.Fatalf("first: %d", w.Code)
	}
	w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_own_plan"})
	if w.Code != 429 || errorCode(t, w) != ReasonQuotaExceeded {
		t.Errorf("the key's daily quota of 1: %d %s", w.Code, w.Body.String())
	}
	// the consumer's other keys (no plan) are not limited by it
	if w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"}); w.Code != 200 {
		t.Errorf("another key of the consumer: %d", w.Code)
	}
}

func TestUnknownRouteAndNoSnapshotAnswer503(t *testing.T) {
	a, _ := newAuthorizer(t, fixture(""))
	if w := call(a, "01UNKNOWNAAAAAAAAAAAAAAAAA", "GET", nil); w.Code != 503 || w.Header().Get("Retry-After") == "" {
		t.Errorf("unknown route: %d", w.Code)
	}
	a.Snapshots = staticSnapshots{nil}
	if w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"}); w.Code != 503 {
		t.Errorf("no snapshot: %d", w.Code)
	}
}

func TestUsageIsCountedPerOutcome(t *testing.T) {
	a, rec := newAuthorizer(t, fixture(""))
	call(a, routeK, "GET", nil)                                          // unauthorized, no consumer
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_nope"})    // unauthorized, no consumer
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"})     // request
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"})     // request
	call(a, routeK2, "GET", map[string]string{"X-Api-Key": "rk_scoped"}) // forbidden

	rec.mu.Lock()
	defer rec.mu.Unlock()
	hour := "2026-10-21T10:00:00Z"
	anon := rec.pending[UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, Hour: hour}]
	org := rec.pending[UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, ConsumerID: "c-org", KeyID: "k-org", Hour: hour}]
	scoped := rec.pending[UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK2, ConsumerID: "c-org", KeyID: "k-scoped", Hour: hour}]
	if anon == nil || anon.Unauthorized != 2 {
		t.Errorf("anonymous: %+v", anon)
	}
	if org == nil || org.Requests != 2 {
		t.Errorf("org key: %+v", org)
	}
	if scoped == nil || scoped.Forbidden != 1 {
		t.Errorf("scoped key: %+v", scoped)
	}
}

func TestJWTRoute(t *testing.T) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	var keys atomic.Value
	keys.Store([]map[string]any{rsaJWK("k", &key.PublicKey)})
	srv, _ := jwksServer(t, &keys)

	a, rec := newAuthorizer(t, fixture(srv.URL))
	a.JWKS = NewJWKSCache(srv.Client())
	exp := time.Date(2026, 10, 21, 11, 0, 0, 0, time.UTC).Unix()

	// a consumer's subject: the consumer and its plan
	w := call(a, routeJ, "GET", map[string]string{"Authorization": "Bearer " + signRS256(t, key, "k", map[string]any{"sub": "partner-1", "exp": exp})})
	if w.Code != 200 || w.Header().Get("X-Runr-Consumer") != "c-gw" || w.Header().Get("X-Runr-Subject") != "partner-1" {
		t.Fatalf("consumer subject: %d %v", w.Code, w.Header())
	}

	// another subject: passes, counted under the subject
	w = call(a, routeJ, "GET", map[string]string{"Authorization": "Bearer " + signRS256(t, key, "k", map[string]any{"sub": "someone", "exp": exp})})
	if w.Code != 200 || w.Header().Get("X-Runr-Consumer") != "" || w.Header().Get("X-Runr-Subject") != "someone" {
		t.Fatalf("other subject: %d %v", w.Code, w.Header())
	}
	rec.mu.Lock()
	row := rec.pending[UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeJ, Subject: "someone", Hour: "2026-10-21T10:00:00Z"}]
	rec.mu.Unlock()
	if row == nil || row.Requests != 1 {
		t.Errorf("subject usage %+v", row)
	}

	// require_consumer: the unknown subject is refused
	a.Snapshots.(staticSnapshots).s.Route(routeJ).JWT.RequireConsumer = true
	w = call(a, routeJ, "GET", map[string]string{"Authorization": "Bearer " + signRS256(t, key, "k", map[string]any{"sub": "someone", "exp": exp})})
	if w.Code != 403 || errorCode(t, w) != "unknown_subject" {
		t.Errorf("require_consumer: %d %s", w.Code, w.Body.String())
	}

	// no token, bad token
	if w := call(a, routeJ, "GET", nil); w.Code != 401 || errorCode(t, w) != "missing_token" {
		t.Errorf("no token: %d", w.Code)
	}
	if w := call(a, routeJ, "GET", map[string]string{"Authorization": "Bearer a.b.c"}); w.Code != 401 || errorCode(t, w) != "invalid_token" {
		t.Errorf("bad token: %d", w.Code)
	}
}

func TestSanitizeKeepsHeaderValuesSafe(t *testing.T) {
	if got := sanitize("a\r\nX-Evil: 1"); got != "aX-Evil: 1" {
		t.Errorf("sanitize: %q", got)
	}
	if got := sanitize(strings.Repeat("x", 500)); len(got) != 191 {
		t.Errorf("length %d", len(got))
	}
}
