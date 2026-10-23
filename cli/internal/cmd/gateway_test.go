package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestGatewayLsAndGet(t *testing.T) {
	h := newHarness(t).login()
	g := h.srv.SeedGateway("eu-1", "/orders", "/status")

	r := h.mustRun("gateway", "ls")
	contains(t, r.out, g.ID)
	contains(t, r.out, "eu-1")
	contains(t, r.out, g.Host)

	r = h.mustRun("gateway", "get", "eu-1")
	contains(t, r.out, "/orders")
	contains(t, r.out, "public")

	// a route to an environment of its project names it
	h.srv.SetRouteEnvironment(g.ID, "/status", "staging")
	r = h.mustRun("gateway", "get", "eu-1")
	contains(t, r.out, "status (staging)")

	// fail closed: the gateway says why its key and JWT routes refuse
	h.srv.SetGatewayStatus(g.ID, "pending", "API authentication is starting in the region.")
	r = h.mustRun("gateway", "get", "eu-1")
	contains(t, r.out, "pending")
	contains(t, r.out, "API authentication is starting")
}

func TestGatewayRoutesToProjectsAndEnvironments(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.AddRegion("us-1", true)
	h.srv.SeedEnvironment("orders", "staging", false)
	h.mustRun("environments", "create", "qa", "-p", "orders", "--region", "us-1")

	// The caller's gateway in a region: created once, then answered
	r := h.mustRun("gateway", "create", "eu-1", "-o", "json")
	var g runr.Gateway
	decodeJSON(t, r.out, &g)
	contains(t, h.mustRun("gateway", "create", "eu-1").out, g.ID)
	if n := len(h.srv.CallsTo("POST /gateways")); n != 2 || g.ID == "" {
		t.Fatalf("POST /gateways calls = %d, gateway %+v", n, g)
	}

	// The project's own workload and an environment in the gateway's region
	r = h.mustRun("gateway", "routes", "add", "eu-1", "orders", "-p", "orders", "--method", "get")
	contains(t, r.err, "leads to orders")
	if body := h.srv.CallsTo("POST /gateways/{gateway}/routes")[0].Body; body["environment"] != nil || body["path"] != "/orders" {
		t.Fatalf("route body = %v", body)
	}
	r = h.mustRun("gateway", "routes", "add", "eu-1", "/orders-staging", "-p", "orders", "--env", "staging")
	contains(t, r.err, "orders (staging)")
	if body := h.srv.CallsTo("POST /gateways/{gateway}/routes")[1].Body; body["environment"] != "staging" {
		t.Fatalf("route body = %v", body)
	}
	r = h.mustRun("gateway", "routes", "ls", "eu-1")
	contains(t, r.out, "/orders-staging")
	contains(t, r.out, "orders (staging)")
	contains(t, r.out, "GET")

	// An environment in another region needs a gateway there
	r = h.expectCode(ExitValidation, "gateway", "routes", "add", "eu-1", "/orders-qa", "-p", "orders", "--env", "qa")
	contains(t, r.err, "runs in region us-1")
	h.mustRun("gateway", "create", "us-1")
	h.mustRun("gateway", "routes", "add", "us-1", "/orders-qa", "-p", "orders", "--env", "qa")
	h.expectCode(ExitNotFound, "gateway", "routes", "add", "us-1", "/nope", "-p", "orders", "--env", "nope")
	h.expectCode(ExitUsage, "gateway", "routes", "add", "eu-1", "/x", "-p", "orders", "--method", "TRACE")

	// rm asks, then removes by path
	h.expectCode(ExitUsage, "gateway", "routes", "rm", "eu-1", "/orders-staging")
	h.mustRun("gateway", "routes", "rm", "eu-1", "/orders-staging", "--yes")
	if n := len(h.srv.CallsTo("DELETE /gateways/{gateway}/routes/{route}")); n != 1 {
		t.Fatalf("DELETE calls = %d", n)
	}
	notContains(t, h.mustRun("gateway", "routes", "ls", "eu-1").out, "/orders-staging")
}

// A key or JWT route is created with its authentication in one call (never public for a
// moment), and add prints the route's URL from the answer.
func TestGatewayRouteAddWithItsAuthentication(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedProject("partners", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("gateway", "create", "eu-1")

	r := h.mustRun("gateway", "routes", "add", "eu-1", "/orders", "-p", "orders", "--auth", "key", "--gateway-only")
	contains(t, r.out, "key, gateway only")
	contains(t, r.out, "https://ada.eu-1.runr.test/orders")
	posts := h.srv.CallsTo("POST /gateways/{gateway}/routes")
	if len(posts) != 1 || posts[0].Body["auth_mode"] != "key" || posts[0].Body["gateway_only"] != true {
		t.Fatalf("POST calls = %+v", posts)
	}
	if n := len(h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")); n != 0 {
		t.Fatalf("PATCH calls = %d, want one call that creates the route with its authentication", n)
	}

	r = h.mustRun("gateway", "routes", "add", "eu-1", "/partners", "-p", "partners", "--auth", "jwt",
		"--jwks-url", "https://id.example.com/jwks", "--audience", "partners", "-o", "json")
	var route runr.GatewayRoute
	decodeJSON(t, r.out, &route)
	if route.AuthMode != runr.RouteAuthJWT || route.JWT == nil || route.JWT.Audience != "partners" || route.URL == "" {
		t.Fatalf("route = %+v", route)
	}
	if n := len(h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")); n != 0 {
		t.Fatalf("PATCH calls = %d", n)
	}

	// a path in use, refused by the API; a jwt route without its JWKS URL, refused before it
	h.srv.SeedProject("billing", runr.TypeFaaS, "python", "eu-1", nil)
	r = h.expectCode(ExitValidation, "gateway", "routes", "add", "eu-1", "/orders", "-p", "billing", "--auth", "key")
	contains(t, r.err, "already has a route on /orders")
	h.expectCode(ExitUsage, "gateway", "routes", "add", "eu-1", "/x", "-p", "billing", "--auth", "jwt")
	h.expectCode(ExitUsage, "gateway", "routes", "add", "eu-1", "/x", "-p", "billing", "--auth", "basic")
	if n := len(h.srv.CallsTo("POST /gateways/{gateway}/routes")); n != 3 {
		t.Fatalf("POST calls = %d", n)
	}
}

// An API before 0.6.0 creates every route public and answers without the route's URL: the CLI
// sets the authentication right after and reads the URL from the gateway.
func TestGatewayRouteAddOnAnEarlierAPI(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RouteSettings = false
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("gateway", "create", "eu-1")

	r := h.mustRun("gateway", "routes", "add", "eu-1", "/orders", "-p", "orders", "--auth", "key")
	contains(t, r.out, "https://ada.eu-1.runr.test/orders")
	contains(t, r.out, "key")
	patches := h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")
	if len(patches) != 1 || patches[0].Body["auth_mode"] != "key" {
		t.Fatalf("PATCH calls = %+v", patches)
	}
	if g := h.srv.Gateways()[0]; g.Routes[0].AuthMode != runr.RouteAuthKey {
		t.Fatalf("route = %+v", g.Routes[0])
	}

	// without settings nothing follows
	h.srv.SeedProject("status", runr.TypeFaaS, "python", "eu-1", nil)
	h.mustRun("gateway", "routes", "add", "eu-1", "/status", "-p", "status")
	if n := len(h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")); n != 1 {
		t.Fatalf("PATCH calls = %d", n)
	}
}

func TestGatewayRouteAuth(t *testing.T) {
	h := newHarness(t).login()
	g := h.srv.SeedGateway("eu-1", "/orders", "/partners")

	h.mustRun("gateway", "routes", "set", "eu-1", "/orders", "--auth", "key", "--gateway-only")
	calls := h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")
	if len(calls) != 1 || calls[0].Body["auth_mode"] != "key" || calls[0].Body["gateway_only"] != true {
		t.Fatalf("calls = %+v", calls)
	}
	if !strings.HasSuffix(calls[0].Path, "/routes/"+g.Routes[0].ID) {
		t.Fatalf("path %s, want the route's id", calls[0].Path)
	}

	r := h.mustRun("gateway", "routes", "set", g.ID, "partners", "--auth", "jwt", "--jwks-url", "https://id.example.com/jwks", "--audience", "partners")
	contains(t, r.out, "jwt")
	body := h.srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")[1].Body
	if jwt, _ := body["jwt"].(map[string]any); jwt["jwks_url"] != "https://id.example.com/jwks" || jwt["audience"] != "partners" {
		t.Fatalf("jwt body %+v", body)
	}

	h.expectCode(2, "gateway", "routes", "set", "eu-1", "/orders", "--auth", "basic")
	h.expectCode(2, "gateway", "routes", "set", "eu-1", "/orders", "--issuer", "x")
	h.expectCode(2, "gateway", "routes", "set", "eu-1", "/orders")
	h.expectCode(2, "gateway", "routes", "set", "eu-1", "/missing", "--auth", "key")
}

func TestGatewayConsumersKeysAndPlans(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGateway("eu-1", "/orders")

	var plan runr.APIPlan
	decodeJSON(t, h.mustRun("gateway", "plans", "create", "Pro", "--gateway", "eu-1", "--per-second", "10", "--per-month", "100000", "-o", "json").out, &plan)
	if plan.ID == "" || *plan.RatePerSecond != 10 || *plan.Burst != 10 {
		t.Fatalf("plan = %+v", plan)
	}
	contains(t, h.mustRun("gateway", "plans", "ls", "--gateway", "eu-1").out, "10/s, 100000/month")
	h.expectCode(2, "gateway", "plans", "create", "Bad", "--burst", "5")

	var consumer runr.APIConsumer
	decodeJSON(t, h.mustRun("gateway", "consumers", "create", "Acme Ltd", "--gateway", "eu-1", "--external-id", "CUST-1", "--plan", plan.ID, "-o", "json").out, &consumer)
	if consumer.Scope != "gateway" || consumer.ExternalID != "CUST-1" {
		t.Fatalf("consumer = %+v", consumer)
	}
	contains(t, h.mustRun("gateway", "consumers").out, "Acme Ltd")

	// the key is printed once, then only its prefix
	r := h.mustRun("gateway", "keys", "create", consumer.ID, "--name", "production", "--gateway", "eu-1", "--route", "/orders:get,post", "--expires", "90d")
	if !strings.Contains(r.out, "rk_") || !strings.Contains(r.out, "shown this once") {
		t.Fatalf("create output:\n%s", r.out)
	}
	body := h.srv.CallsTo("POST /api-consumers/{apiConsumer}/keys")[0].Body
	scopes, _ := body["scopes"].([]any)
	scope, _ := scopes[0].(map[string]any)
	if methods, _ := scope["methods"].([]any); len(methods) != 2 || methods[0] != "GET" || body["expires_at"] == "" {
		t.Fatalf("key body %+v", body)
	}
	plain := strings.Fields(r.out)[0]
	ls := h.mustRun("gateway", "keys", "ls", consumer.ID)
	notContains(t, ls.out, plain)
	contains(t, ls.out, plain[:11])

	var keys []runr.APIKey
	decodeJSON(t, h.mustRun("gateway", "keys", "ls", consumer.ID, "-o", "json").out, &keys)
	r = h.mustRun("gateway", "keys", "rotate", keys[0].ID, "--overlap", "0")
	contains(t, r.out, "replaces "+keys[0].ID)
	if b := h.srv.CallsTo("POST /api-keys/{apiKey}/rotate")[0].Body; b["overlap_minutes"] != float64(0) {
		t.Fatalf("rotate body %+v", b)
	}

	h.expectCode(2, "gateway", "keys", "revoke", keys[0].ID)
	h.mustRun("gateway", "keys", "revoke", keys[0].ID, "--yes")

	h.mustRun("gateway", "consumers", "update", consumer.ID, "--status", "suspended", "--plan", "")
	if b := h.srv.CallsTo("PATCH /api-consumers/{apiConsumer}")[0].Body; b["status"] != "suspended" || b["plan_id"] != nil {
		t.Fatalf("update body %+v", b)
	}
	h.expectCode(2, "gateway", "consumers", "update", consumer.ID, "--status", "gone")

	h.mustRun("gateway", "consumers", "rm", consumer.ID, "--yes")
	if len(h.srv.APIConsumers()) != 0 {
		t.Fatal("consumer not deleted")
	}
}

func TestGatewayUsage(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGateway("eu-1", "/orders")
	h.srv.SeedUsage(runr.UsageRow{UsageCounts: runr.UsageCounts{Requests: 120, RateLimited: 3, Unauthorized: 2}, ConsumerID: "c1",
		Consumer: &struct {
			ID         string `json:"id"`
			Name       string `json:"name"`
			ExternalID string `json:"external_id,omitempty"`
		}{ID: "c1", Name: "Acme", ExternalID: "CUST-1"}})

	r := h.mustRun("gateway", "usage", "--gateway", "eu-1", "--by", "consumer", "--since", "30d")
	contains(t, r.out, "Acme (CUST-1)")
	contains(t, r.out, "120 let through, 5 refused")
	// no measured answers: no latency
	contains(t, r.out, "P99")
	if strings.Contains(r.out, "The functions answered") {
		t.Errorf("a note on answers without any: %s", r.out)
	}
	q := h.srv.CallsTo("GET /api-usage")[0].Query
	if !strings.Contains(q, "group_by=consumer") || !strings.Contains(q, "gateway_id=") || !strings.Contains(q, "from=") {
		t.Fatalf("query %s", q)
	}

	h.expectCode(2, "gateway", "usage", "--by", "week")
}

func TestGatewayUsageShowsWhatTheFunctionsAnswered(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGateway("eu-1", "/orders")
	p50, p95, p99 := 24.5, 180.0, 950.25
	h.srv.SeedUsage(runr.UsageRow{UsageCounts: runr.UsageCounts{Requests: 100, Responses: 100, Status2xx: 97, Status5xx: 2, UpstreamErrors: 1, Errors: 3,
		LatencyP50MS: &p50, LatencyP95MS: &p95, LatencyP99MS: &p99}, RouteID: "r1", Path: "/orders"})

	r := h.mustRun("gateway", "usage", "--gateway", "eu-1", "--by", "route")
	for _, want := range []string{"/orders", "ERRORS", "24.5", "180", "950.25", "The functions answered 100: 3 error(s), p50 24.5 ms, p95 180 ms, p99 950.25 ms."} {
		contains(t, r.out, want)
	}

	// -o json: the API's answer with the status classes
	r = h.mustRun("gateway", "usage", "--gateway", "eu-1", "--by", "route", "-o", "json")
	contains(t, r.out, `"status_5xx": 2`)
	contains(t, r.out, `"upstream_errors": 1`)
}

func TestGatewayPageMetrics(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGateway("eu-1", "/orders")

	r := h.mustRun("gateway", "page", "eu-1", "--metrics")
	contains(t, r.out, "success rate and latency")
	if body := h.srv.CallsTo("PATCH /gateways/{gateway}")[0].Body; body["developer_page_metrics"] != true {
		t.Fatalf("body %v", body)
	}

	h.mustRun("gateway", "page", "eu-1", "--description", "Orders")
	if body := h.srv.CallsTo("PATCH /gateways/{gateway}")[1].Body; body["developer_page_metrics"] != nil {
		t.Fatalf("metrics sent without --metrics: %v", body)
	}
}

func TestGatewayDomainAndPage(t *testing.T) {
	h := newHarness(t).login()
	g := h.srv.SeedGateway("eu-1", "/orders")

	r := h.mustRun("gateway", "domain", "set", "eu-1", "api.example.com")
	contains(t, r.out, "_runr-challenge.api.example.com")

	h.expectCode(ExitValidation, "gateway", "domain", "verify", "eu-1")
	h.srv.VerifyDomains = true
	contains(t, h.mustRun("gateway", "domain", "verify", "eu-1").out, "api.example.com is verified")

	contains(t, h.mustRun("gateway", "page", "eu-1", "--description", "Orders API").out, "/developers/_."+g.ID)
	contains(t, h.mustRun("gateway", "page", "eu-1", "--off").out, "withdrawn")
}
