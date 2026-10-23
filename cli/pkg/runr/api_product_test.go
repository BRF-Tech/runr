package runr_test

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestAPIProductClient(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	g := srv.SeedGateway("eu-1", "/orders")

	gateways, err := c.ListGateways(ctx)
	if err != nil || len(gateways) != 1 || gateways[0].Routes[0].AuthMode != runr.RouteAuthPublic {
		t.Fatalf("gateways = %+v, %v", gateways, err)
	}

	only := true
	route, err := c.UpdateRouteAuth(ctx, g.ID, g.Routes[0].ID, runr.RouteAuthInput{AuthMode: runr.RouteAuthKey, GatewayOnly: &only})
	if err != nil || route.AuthMode != "key" || !route.GatewayOnly {
		t.Fatalf("route = %+v, %v", route, err)
	}
	if _, err := c.UpdateRouteAuth(ctx, g.ID, g.Routes[0].ID, runr.RouteAuthInput{AuthMode: runr.RouteAuthJWT}); err == nil {
		t.Fatal("jwt without a JWKS URL must be refused")
	}

	ten := int64(10)
	plan, err := c.CreateAPIPlan(ctx, runr.APIPlanInput{GatewayID: g.ID, Name: "Pro", RatePerSecond: &ten})
	if err != nil || plan.String() != "10/s" {
		t.Fatalf("plan = %+v (%s), %v", plan, plan.String(), err)
	}

	consumer, err := c.CreateAPIConsumer(ctx, runr.APIConsumerInput{GatewayID: g.ID, Name: "Acme", PlanID: plan.ID})
	if err != nil || consumer.Plan == nil || consumer.Plan.Name != "Pro" {
		t.Fatalf("consumer = %+v, %v", consumer, err)
	}

	key, err := c.CreateAPIKey(ctx, consumer.ID, runr.APIKeyInput{Name: "live", Scopes: []runr.KeyScope{{RouteID: g.Routes[0].ID, Methods: []string{"GET"}}}})
	if err != nil || !strings.HasPrefix(key.Key, "rk_") || key.Prefix != key.Key[:11] {
		t.Fatalf("key = %+v, %v", key, err)
	}
	keys, _ := c.ListAPIKeys(ctx, consumer.ID)
	if len(keys) != 1 || keys[0].Key != "" {
		t.Fatalf("listed keys carry the secret: %+v", keys)
	}

	zero := 0
	next, err := c.RotateAPIKey(ctx, key.ID, &zero)
	if err != nil || next.RotatedFrom != key.ID || next.Key == key.Key {
		t.Fatalf("rotated = %+v, %v", next, err)
	}
	if _, err := c.RotateAPIKey(ctx, key.ID, nil); err == nil {
		t.Fatal("a revoked key cannot be rotated")
	}

	if err := c.DeleteAPIPlan(ctx, plan.ID); err == nil {
		t.Fatal("a plan in use cannot be deleted")
	}

	if _, err := c.VerifyGatewayDomain(ctx, g.ID); err == nil {
		t.Fatal("nothing pending: not verified")
	}
	if _, err := c.SetGatewayDomain(ctx, g.ID, "api.example.com"); err != nil {
		t.Fatal(err)
	}
	_, err = c.VerifyGatewayDomain(ctx, g.ID)
	var de *runr.GatewayDomainError
	if !errors.As(err, &de) || de.Gateway.CustomDomainPending == nil {
		t.Fatalf("verify before DNS: %v", err)
	}
	srv.VerifyDomains = true
	if gw, err := c.VerifyGatewayDomain(ctx, g.ID); err != nil || gw.CustomDomain != "api.example.com" {
		t.Fatalf("verified = %+v, %v", gw, err)
	}
}

func TestUpdateGatewayRateLimitAndPage(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	region := srv.AddRegion("eu-2", true)
	g, err := c.EnsureGateway(ctx, region.ID)
	if err != nil {
		t.Fatal(err)
	}

	// Without a burst the API takes the average
	gw, err := c.UpdateGateway(ctx, g.ID, map[string]any{"rate_limit": &runr.GatewayLimit{Average: 50}})
	if err != nil || gw.RateLimit == nil || gw.RateLimit.Average != 50 || gw.RateLimit.Burst != 50 {
		t.Fatalf("rate limit = %+v, %v", gw.RateLimit, err)
	}
	if limit := srv.CallsTo("PATCH /gateways/{gateway}")[0].Body["rate_limit"].(map[string]any); len(limit) != 1 {
		t.Fatalf("a burst of 0 was sent: %v", limit)
	}
	gw, err = c.UpdateGateway(ctx, g.ID, map[string]any{"rate_limit": &runr.GatewayLimit{Average: 50, Burst: 80}, "description": "Orders"})
	if err != nil || gw.RateLimit.Burst != 80 || gw.Description != "Orders" {
		t.Fatalf("gateway = %+v, %v", gw, err)
	}

	// nil: no rate limit; a nil description clears it
	var none *runr.GatewayLimit
	gw, err = c.UpdateGateway(ctx, g.ID, map[string]any{"rate_limit": none, "description": nil})
	if err != nil || gw.RateLimit != nil || gw.Description != "" {
		t.Fatalf("gateway = %+v, %v", gw, err)
	}
	if body := srv.CallsTo("PATCH /gateways/{gateway}")[2].Body; body["rate_limit"] != nil || body["description"] != nil {
		t.Fatalf("body %v", body)
	}

	_, err = c.UpdateGateway(ctx, g.ID, map[string]any{"rate_limit": &runr.GatewayLimit{Average: 0}})
	if !runr.IsValidation(err) {
		t.Fatalf("an average of 0: %v", err)
	}
	if _, err := c.UpdateGatewayPage(ctx, g.ID, true, nil, nil); err != nil {
		t.Fatal(err)
	}
	if gw, _ := c.GetGateway(ctx, g.ID); gw.DeveloperPageKey == "" || gw.RateLimit != nil {
		t.Fatalf("page = %+v", gw)
	}
}

func TestUpdateGatewayRoute(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	p := srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	g, err := c.EnsureGateway(ctx, p.RegionID)
	if err != nil {
		t.Fatal(err)
	}
	route, err := c.AddGatewayRoute(ctx, g.ID, runr.GatewayRouteInput{ProjectID: p.ID, Path: "/orders", Methods: []string{"GET"}})
	if err != nil {
		t.Fatal(err)
	}

	jwt := &runr.RouteJWT{JWKSURL: "https://id.example.com/jwks", Audience: "orders"}
	updated, err := c.UpdateGatewayRoute(ctx, g.ID, route.ID, map[string]any{"path": "/v2/orders", "methods": nil, "auth_mode": "jwt", "jwt": jwt})
	if err != nil || updated.Path != "/v2/orders" || updated.Methods != nil || updated.AuthMode != "jwt" {
		t.Fatalf("route = %+v, %v", updated, err)
	}
	// The answer is the stored route; the gateway shows its URL and JWT settings with their defaults
	gw, _ := c.GetGateway(ctx, g.ID)
	r := gw.Routes[0]
	if r.URL != "https://"+gw.Host+"/v2/orders" || r.JWT == nil || r.JWT.SubjectClaim != "sub" || r.JWT.Audience != "orders" {
		t.Fatalf("route in the gateway = %+v (%+v)", r, r.JWT)
	}

	// Leaving jwt mode: the settings can be cleared; jwt without a JWKS URL is refused
	if _, err := c.UpdateGatewayRoute(ctx, g.ID, route.ID, map[string]any{"auth_mode": "key", "jwt": nil}); err != nil {
		t.Fatal(err)
	}
	if _, err := c.UpdateGatewayRoute(ctx, g.ID, route.ID, map[string]any{"auth_mode": "jwt"}); !runr.IsValidation(err) {
		t.Fatalf("jwt without its settings: %v", err)
	}
	if _, err := c.UpdateGatewayRoute(ctx, g.ID, "01J00000000000000000000000", map[string]any{"auth_mode": "key"}); !runr.IsNotFound(err) {
		t.Fatalf("a route that is gone: %v", err)
	}
}

func TestUpdateAPIKeyAndPlan(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	g := srv.SeedGateway("eu-1", "/orders", "/status")

	ten, twenty := int64(10), int64(20)
	plan, err := c.CreateAPIPlan(ctx, runr.APIPlanInput{GatewayID: g.ID, Name: "Pro", RatePerSecond: &ten})
	if err != nil || *plan.Burst != 10 {
		t.Fatalf("plan = %+v, %v", plan, err)
	}
	// The burst follows the rate unless the plan has its own; no rate: no burst
	plan, err = c.UpdateAPIPlan(ctx, plan.ID, map[string]any{"rate_per_second": twenty, "description": "Partners"})
	if err != nil || *plan.Burst != 20 || plan.Description != "Partners" {
		t.Fatalf("plan = %+v, %v", plan, err)
	}
	if _, err := c.UpdateAPIPlan(ctx, plan.ID, map[string]any{"burst": 5}); !runr.IsValidation(err) {
		t.Fatalf("a burst below the rate: %v", err)
	}
	plan, err = c.UpdateAPIPlan(ctx, plan.ID, map[string]any{"rate_per_second": nil, "quota_per_day": 1000})
	if err != nil || plan.RatePerSecond != nil || plan.Burst != nil || *plan.QuotaPerDay != 1000 {
		t.Fatalf("plan = %+v, %v", plan, err)
	}

	consumer, err := c.CreateAPIConsumer(ctx, runr.APIConsumerInput{GatewayID: g.ID, Name: "Acme"})
	if err != nil {
		t.Fatal(err)
	}
	key, err := c.CreateAPIKey(ctx, consumer.ID, runr.APIKeyInput{Name: "live"})
	if err != nil {
		t.Fatal(err)
	}
	updated, err := c.UpdateAPIKey(ctx, key.ID, map[string]any{
		"name": "production", "plan_id": plan.ID, "expires_at": "2099-01-01T00:00:00Z",
		"scopes": []runr.KeyScope{{RouteID: g.Routes[1].ID, Methods: []string{"get", "GET"}}},
	})
	if err != nil || updated.Name != "production" || updated.PlanID != plan.ID || updated.Key != "" || updated.Prefix != key.Prefix {
		t.Fatalf("key = %+v, %v", updated, err)
	}
	if len(updated.Scopes) != 1 || len(updated.Scopes[0].Methods) != 1 || updated.Scopes[0].Methods[0] != "GET" {
		t.Fatalf("scopes = %+v", updated.Scopes)
	}
	if !strings.HasPrefix(updated.ExpiresAt, "2099-01-01T00:00:00") {
		t.Fatalf("expires_at = %s", updated.ExpiresAt)
	}
	updated, err = c.UpdateAPIKey(ctx, key.ID, map[string]any{"scopes": nil, "plan_id": nil, "expires_at": nil})
	if err != nil || updated.Scopes != nil || updated.PlanID != "" || updated.ExpiresAt != "" {
		t.Fatalf("key = %+v, %v", updated, err)
	}
	if _, err := c.UpdateAPIKey(ctx, key.ID, map[string]any{"expires_at": "2020-01-01T00:00:00Z"}); !runr.IsValidation(err) {
		t.Fatalf("an expiry in the past: %v", err)
	}
	other := srv.SeedGateway("eu-1", "/elsewhere")
	if _, err := c.UpdateAPIKey(ctx, key.ID, map[string]any{"scopes": []runr.KeyScope{{RouteID: other.Routes[0].ID}}}); !runr.IsValidation(err) {
		t.Fatalf("a route of another gateway: %v", err)
	}

	// A key or plan that is gone: ErrUnsupported for the CLI, still a 404 for IsNotFound
	_, err = c.UpdateAPIKey(ctx, "01J00000000000000000000000", map[string]any{"name": "x"})
	if !errors.Is(err, runr.ErrUnsupported) || !runr.IsNotFound(err) {
		t.Fatalf("missing key: %v", err)
	}
	if _, err := c.GetAPIConsumer(ctx, "01J00000000000000000000000"); !runr.IsNotFound(err) {
		t.Fatalf("missing consumer: %v", err)
	}
}

func TestAPIUsageFilterAndCounts(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	srv.SeedUsage(runr.UsageRow{UsageCounts: runr.UsageCounts{Requests: 5, Forbidden: 1, QuotaExceeded: 2}, Period: "2026-10-20"})

	u, err := c.APIUsage(context.Background(), runr.APIUsageFilter{GroupBy: "day", From: "2026-10-01T00:00:00Z"})
	if err != nil || u.Totals.Requests != 5 || u.Totals.Refused() != 3 || u.Data[0].Period != "2026-10-20" {
		t.Fatalf("usage = %+v, %v", u, err)
	}
	if q := srv.CallsTo("GET /api-usage")[0].Query; !strings.Contains(q, "group_by=day") || !strings.Contains(q, "from=") {
		t.Fatalf("query %s", q)
	}
}
